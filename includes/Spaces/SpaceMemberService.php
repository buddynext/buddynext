<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Space membership service.
 *
 * Manages join, leave, invite, ban, and role changes for space members.
 * Member counts are kept in sync with the denormalized bn_spaces.member_count
 * column. The owner cannot leave — they must delete or transfer ownership.
 *
 * Membership lifecycle by space type:
 *   Open    — user calls join() → status='active' immediately.
 *   Private — user calls request_join() → status='pending';
 *             owner/mod calls approve_request() → status='active'.
 *   Secret  — owner/mod calls invite() → status='invited';
 *             user calls join() → status converted to 'active'.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use WP_Error;

/**
 * Handles space membership operations.
 */
class SpaceMemberService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_space_members';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 600;

	/**
	 * Valid member roles.
	 */
	private const ALLOWED_ROLES = array( 'owner', 'moderator', 'member' );

	// ── Public membership API ───────────────────────────────────────────────

	/**
	 * Join a space directly (open spaces or accepting an invitation).
	 *
	 * For open spaces the status is set to 'active' immediately. If the user
	 * was previously invited (status='invited') their row is promoted to
	 * 'active'. A banned user cannot rejoin.
	 *
	 * @param int $space_id Space to join.
	 * @param int $user_id  User joining.
	 * @return true|WP_Error
	 */
	public function join( int $space_id, int $user_id ): true|WP_Error {
		// Pre-load space row so listeners on buddynext_can_join_space (including
		// Pro's gated-space gate) receive the actual required_ability + type.
		$space = $this->load_space_row( $space_id );

		// An archived space is read-only — it accepts no new members or requests.
		if ( ! empty( $space['is_archived'] ) ) {
			return new WP_Error(
				'space_archived',
				__( 'This space is archived and is not accepting new members.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filter whether the user is permitted to join a space.
		 *
		 * Pro can return false to block access for non-members of a gated tier.
		 * When false is returned the method short-circuits with a WP_Error before
		 * any DB work is performed.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $can      Whether the user may proceed. Default true.
		 * @param array  $space    Space row from bn_spaces (empty array when row missing).
		 * @param int    $user_id  User attempting to join.
		 * @param string $action   Action being performed — always 'join' from this method.
		 */
		$can = (bool) apply_filters( 'buddynext_can_join_space', true, $space, $user_id, 'join' );
		if ( ! $can ) {
			return $this->denied_join_error( $space_id, $user_id, $space, 'join' );
		}

		// Check hard ban (bn_space_bans) as well as soft ban (member status).
		if ( $this->is_hard_banned( $space_id, $user_id ) ) {
			return new WP_Error(
				'user_banned',
				__( 'You are banned from this space.', 'buddynext' )
			);
		}

		$status = $this->get_status( $space_id, $user_id );

		if ( 'banned' === $status ) {
			return new WP_Error(
				'user_banned',
				__( 'You are banned from this space.', 'buddynext' )
			);
		}

		if ( 'active' === $status ) {
			return true; // Already a member — idempotent.
		}

		global $wpdb;

		if ( null !== $status ) {
			// Row exists (pending/invited) — promote to active.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_space_members',
				array( 'status' => 'active' ),
				array(
					'space_id' => $space_id,
					'user_id'  => $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// New member.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, joined_at)
					 VALUES (%d, %d, 'member', 'active', %s)",
					$space_id,
					$user_id,
					current_time( 'mysql', true )
				)
			);

			if ( 0 === $wpdb->rows_affected ) {
				return true; // Race condition — already inserted.
			}
		}

		$this->adjust_member_count( $space_id, 1 );
		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a user becomes an active space member.
		 *
		 * @param int    $space_id Space joined.
		 * @param int    $user_id  Joining user.
		 * @param string $role     Member role assigned (always 'member' on direct join).
		 */
		do_action( 'buddynext_space_member_joined', $space_id, $user_id, 'member' );

		return true;
	}

	/**
	 * Submit a join request for a private space (status='pending').
	 *
	 * A duplicate request is silently accepted (idempotent). A banned user
	 * cannot request to join.
	 *
	 * @param int $space_id Space to request membership in.
	 * @param int $user_id  User requesting membership.
	 * @return true|WP_Error
	 */
	public function request_join( int $space_id, int $user_id ): true|WP_Error {
		// Pre-load space row so listeners receive the actual required_ability + type.
		$space = $this->load_space_row( $space_id );

		// An archived space is read-only — it accepts no new members or requests.
		if ( ! empty( $space['is_archived'] ) ) {
			return new WP_Error(
				'space_archived',
				__( 'This space is archived and is not accepting new members.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		/**
		 * Filter whether the user is permitted to request membership in a space.
		 *
		 * Pro can return false to block access for non-members of a gated tier.
		 * When false is returned the method short-circuits with a WP_Error before
		 * any DB work is performed.
		 *
		 * @since 1.0.0
		 *
		 * @param bool   $can     Whether the user may proceed. Default true.
		 * @param array  $space   Space row from bn_spaces (empty array when row missing).
		 * @param int    $user_id User attempting to request membership.
		 * @param string $action  Action being performed — always 'request' from this method.
		 */
		$can = (bool) apply_filters( 'buddynext_can_join_space', true, $space, $user_id, 'request' );
		if ( ! $can ) {
			return $this->denied_join_error( $space_id, $user_id, $space, 'request' );
		}

		if ( $this->is_hard_banned( $space_id, $user_id ) ) {
			return new WP_Error(
				'user_banned',
				__( 'You are banned from this space.', 'buddynext' )
			);
		}

		$status = $this->get_status( $space_id, $user_id );

		if ( 'banned' === $status ) {
			return new WP_Error(
				'user_banned',
				__( 'You are banned from this space.', 'buddynext' )
			);
		}

		if ( 'active' === $status || 'pending' === $status ) {
			return true; // Already a member or request already pending.
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, joined_at)
				 VALUES (%d, %d, 'member', 'pending', %s)",
				$space_id,
				$user_id,
				current_time( 'mysql', true )
			)
		);

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires when a user requests to join a private space.
		 *
		 * @param int $space_id Space requested.
		 * @param int $user_id  Requesting user.
		 */
		do_action( 'buddynext_space_join_requested', $space_id, $user_id );

		return true;
	}

	/**
	 * Whether a user may invite members to a space (owner, moderator, or admin).
	 *
	 * @param int $space_id   Space ID.
	 * @param int $inviter_id User who would send the invitation.
	 * @return bool
	 */
	public function can_invite( int $space_id, int $inviter_id ): bool {
		$inviter_role = $this->get_role( $space_id, $inviter_id );

		return in_array( $inviter_role, array( 'owner', 'moderator' ), true )
			|| user_can( $inviter_id, 'manage_options' );
	}

	/**
	 * Invite an existing member to a space (status='invited').
	 *
	 * @param int $space_id        Space to invite into.
	 * @param int $inviter_id      Acting user (must be owner/mod or admin).
	 * @param int $invited_user_id User being invited.
	 * @return true|WP_Error
	 */
	public function invite( int $space_id, int $inviter_id, int $invited_user_id ): true|WP_Error {
		if ( ! $this->can_invite( $space_id, $inviter_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Only the space owner or a moderator can invite members.', 'buddynext' )
			);
		}

		$current_status = $this->get_status( $space_id, $invited_user_id );

		if ( 'active' === $current_status ) {
			return true; // Already a member.
		}

		global $wpdb;

		if ( null !== $current_status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_space_members',
				array( 'status' => 'invited' ),
				array(
					'space_id' => $space_id,
					'user_id'  => $invited_user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, joined_at)
					 VALUES (%d, %d, 'member', 'invited', %s)",
					$space_id,
					$invited_user_id,
					current_time( 'mysql', true )
				)
			);
		}

		$this->invalidate_cache( $space_id, $invited_user_id );

		/**
		 * Fires when a user is invited to a space.
		 *
		 * @param int $invited_user_id Invited user.
		 * @param int $space_id        Space ID.
		 * @param int $inviter_id      User who sent the invitation.
		 */
		do_action( 'buddynext_space_member_invited', $invited_user_id, $space_id, $inviter_id );

		return true;
	}

	/**
	 * Approve a pending join request.
	 *
	 * Only the owner, a moderator, or a user with manage_options can approve.
	 *
	 * @param int $space_id Space ID.
	 * @param int $actor_id User approving the request.
	 * @param int $user_id  User whose request is being approved.
	 * @return true|WP_Error
	 */
	public function approve_request( int $space_id, int $actor_id, int $user_id ): true|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if (
			! in_array( $actor_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $actor_id, 'manage_options' )
		) {
			return new WP_Error(
				'forbidden',
				__( 'Only the space owner or a moderator can approve join requests.', 'buddynext' )
			);
		}

		$current_status = $this->get_status( $space_id, $user_id );

		if ( 'pending' !== $current_status ) {
			return new WP_Error(
				'no_pending_request',
				__( 'No pending join request found for this user.', 'buddynext' )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'status' => 'active' ),
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		$this->adjust_member_count( $space_id, 1 );
		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a join request is approved.
		 *
		 * @param int $space_id    Space ID.
		 * @param int $user_id     Newly approved member.
		 * @param int $by_user_id  Moderator / owner who approved the request.
		 */
		do_action( 'buddynext_space_join_approved', $space_id, $user_id, $actor_id );

		/**
		 * Fires after a user becomes an active space member (via approval).
		 *
		 * @param int    $space_id Space joined.
		 * @param int    $user_id  Joining user.
		 * @param string $role     Member role assigned.
		 */
		do_action( 'buddynext_space_member_joined', $space_id, $user_id, 'member' );

		return true;
	}

	/**
	 * Decline a pending join request.
	 *
	 * Only the owner, a moderator, or a user with manage_options can decline.
	 * The pending membership row is deleted so the user may re-apply later.
	 *
	 * @param int $space_id Space ID.
	 * @param int $actor_id User declining the request.
	 * @param int $user_id  User whose request is being declined.
	 * @return true|WP_Error
	 */
	public function decline_request( int $space_id, int $actor_id, int $user_id ): true|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if (
			! in_array( $actor_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $actor_id, 'manage_options' )
		) {
			return new WP_Error(
				'forbidden',
				__( 'Only the space owner or a moderator can decline join requests.', 'buddynext' )
			);
		}

		$current_status = $this->get_status( $space_id, $user_id );

		if ( 'pending' !== $current_status ) {
			return new WP_Error(
				'no_pending_request',
				__( 'No pending join request found for this user.', 'buddynext' )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a join request is declined.
		 *
		 * @param int $space_id  Space ID.
		 * @param int $user_id   User whose request was declined.
		 * @param int $actor_id  User who declined.
		 */
		do_action( 'buddynext_space_join_declined', $space_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Ban a user from a space.
	 *
	 * The owner cannot be banned. Only the owner, a moderator, or a user with
	 * manage_options can ban members. Banning an active member decrements the
	 * member count. The ban is also written to bn_space_bans so it persists if
	 * the membership row is later deleted.
	 *
	 * @param int    $space_id Space ID.
	 * @param int    $actor_id User performing the ban.
	 * @param int    $user_id  User to ban.
	 * @param string $reason   Optional reason for the ban.
	 * @return true|WP_Error
	 */
	public function ban( int $space_id, int $actor_id, int $user_id, string $reason = '' ): true|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if (
			! in_array( $actor_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $actor_id, 'manage_options' )
		) {
			return new WP_Error(
				'forbidden',
				__( 'Only the space owner or a moderator can ban members.', 'buddynext' )
			);
		}

		$target_role = $this->get_role( $space_id, $user_id );

		if ( 'owner' === $target_role ) {
			return new WP_Error(
				'cannot_ban_owner',
				__( 'The space owner cannot be banned.', 'buddynext' )
			);
		}

		$was_active = ( 'active' === $this->get_status( $space_id, $user_id ) );

		global $wpdb;

		$current_status = $this->get_status( $space_id, $user_id );

		if ( null !== $current_status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'bn_space_members',
				array( 'status' => 'banned' ),
				array(
					'space_id' => $space_id,
					'user_id'  => $user_id,
				),
				array( '%s' ),
				array( '%d', '%d' )
			);
		} else {
			// No existing row — insert banned record to block future joins.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, joined_at)
					 VALUES (%d, %d, 'member', 'banned', %s)",
					$space_id,
					$user_id,
					current_time( 'mysql', true )
				)
			);
		}

		// Record in permanent ban table so the ban persists if membership row is deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_bans (space_id, user_id, banned_by, reason) VALUES (%d, %d, %d, %s)",
				$space_id,
				$user_id,
				$actor_id,
				$reason
			)
		);

		if ( $was_active ) {
			$this->adjust_member_count( $space_id, -1 );
		}

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a user is banned from a space by a moderator.
		 *
		 * @param int $space_id     Space ID.
		 * @param int $user_id      Removed user.
		 * @param int $by_user_id   User who performed the removal.
		 */
		do_action( 'buddynext_space_member_removed', $space_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Leave a space.
	 *
	 * The owner of a space cannot leave.
	 *
	 * @param int $space_id Space to leave.
	 * @param int $user_id  User leaving.
	 * @return true|WP_Error
	 */
	public function leave( int $space_id, int $user_id ): true|WP_Error {
		if ( 'owner' === $this->get_role( $space_id, $user_id ) ) {
			return new WP_Error(
				'owner_cannot_leave',
				__( 'The space owner cannot leave. Delete the space or transfer ownership first.', 'buddynext' )
			);
		}

		$was_active = ( 'active' === $this->get_status( $space_id, $user_id ) );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		if ( $wpdb->rows_affected > 0 ) {
			if ( $was_active ) {
				$this->adjust_member_count( $space_id, -1 );
			}

			$this->invalidate_cache( $space_id, $user_id );

			/**
			 * Fires after a user leaves a space.
			 *
			 * @param int $space_id Space left.
			 * @param int $user_id  User who left.
			 */
			do_action( 'buddynext_space_member_left', $space_id, $user_id );
		}

		return true;
	}

	// ── Queries ─────────────────────────────────────────────────────────────

	/**
	 * Check whether a user is an active member of a space.
	 *
	 * @param int $space_id Space to check.
	 * @param int $user_id  User to check.
	 * @return bool
	 */
	public function is_member( int $space_id, int $user_id ): bool {
		return null !== $this->get_role( $space_id, $user_id );
	}

	/**
	 * Allowed notification preferences for a space membership.
	 */
	private const NOTIFICATION_PREFS = array( 'all', 'mentions_only', 'none' );

	/**
	 * Set the per-space notification preference for an active member.
	 *
	 * Writes to bn_space_members.notification_pref. Returns a WP_Error when
	 * the user is not an active member of the space or the requested pref is
	 * not in the allow-list.
	 *
	 * @param int    $space_id Space ID.
	 * @param int    $user_id  User changing their own preference.
	 * @param string $pref     One of 'all', 'mentions_only', 'none'.
	 * @return true|WP_Error
	 */
	public function set_notification_pref( int $space_id, int $user_id, string $pref ): true|WP_Error {
		if ( ! in_array( $pref, self::NOTIFICATION_PREFS, true ) ) {
			return new WP_Error(
				'invalid_pref',
				__( 'Invalid notification preference.', 'buddynext' )
			);
		}

		if ( ! $this->is_member( $space_id, $user_id ) ) {
			return new WP_Error(
				'not_a_member',
				__( 'You must be a member of the space to change notification preferences.', 'buddynext' )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'notification_pref' => $pref ),
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'active',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a member updates their per-space notification preference.
		 *
		 * @param int    $space_id Space ID.
		 * @param int    $user_id  User who changed the preference.
		 * @param string $pref     New preference ('all', 'mentions_only', 'none').
		 */
		do_action( 'buddynext_space_notification_pref_updated', $space_id, $user_id, $pref );

		return true;
	}

	/**
	 * Get the per-space notification preference for a user.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 * @return string Notification preference; defaults to 'all'.
	 */
	public function get_notification_pref( int $space_id, int $user_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pref = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT notification_pref FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND user_id = %d AND status = 'active'",
				$space_id,
				$user_id
			)
		);

		return in_array( $pref, self::NOTIFICATION_PREFS, true ) ? $pref : 'all';
	}

	/**
	 * Alias for request_join() to satisfy the documented public method name.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User requesting access.
	 * @return true|WP_Error
	 */
	public function cancel_request( int $space_id, int $user_id ): true|WP_Error {
		$status = $this->get_status( $space_id, $user_id );
		if ( 'pending' !== $status ) {
			return new WP_Error(
				'no_pending_request',
				__( 'There is no pending request to cancel.', 'buddynext' )
			);
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'pending',
			),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a member cancels their pending join request.
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  User whose request was cancelled.
		 */
		do_action( 'buddynext_space_join_request_cancelled', $space_id, $user_id );

		return true;
	}

	/**
	 * Return the user's role in a space, or null if not an active member.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 * @return string|null Role string ('owner', 'moderator', 'member') or null.
	 */
	public function get_role( int $space_id, int $user_id ): ?string {
		$cache_key = "role_{$space_id}_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return ( '' === $cached ) ? null : (string) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$role = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT role FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND user_id = %d AND status = 'active'",
				$space_id,
				$user_id
			)
		);

		// Cache empty string for "not an active member" to distinguish from cache miss.
		wp_cache_set( $cache_key, $role ?? '', self::CACHE_GROUP, self::CACHE_TTL );

		return $role;
	}

	/**
	 * Return the raw membership status for a user/space pair, or null if no row exists.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 * @return string|null 'active', 'pending', 'invited', 'banned', or null.
	 */
	public function get_status( int $space_id, int $user_id ): ?string {
		$cache_key = "status_{$space_id}_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return ( 'none' === $cached ) ? null : (string) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		// Cache 'none' sentinel so we can distinguish null (no row) from cache miss (false).
		wp_cache_set( $cache_key, $status ?? 'none', self::CACHE_GROUP, self::CACHE_TTL );

		return $status;
	}

	/**
	 * Remove a member from a space (non-ban forceful removal).
	 *
	 * Only the space owner, a moderator, or a site admin may remove members.
	 * The owner of a space cannot be removed. On success the member_count is
	 * decremented and the `buddynext_space_member_removed` action fires.
	 *
	 * @param int $space_id       Space ID.
	 * @param int $user_id        User to remove.
	 * @param int $acting_user_id User performing the removal.
	 * @return bool True on success, false if no row deleted or permission denied.
	 */
	public function remove( int $space_id, int $user_id, int $acting_user_id ): bool {
		$acting_role = $this->get_role( $space_id, $acting_user_id );

		if (
			! in_array( $acting_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $acting_user_id, 'manage_options' )
		) {
			return false;
		}

		if ( 'owner' === $this->get_role( $space_id, $user_id ) ) {
			return false;
		}

		$was_active = ( 'active' === $this->get_status( $space_id, $user_id ) );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 0 === $wpdb->rows_affected ) {
			return false;
		}

		if ( $was_active ) {
			$this->adjust_member_count( $space_id, -1 );
		}

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a member is forcefully removed from a space.
		 *
		 * Canonical removal hook (also fired by ban()); consumed by the sidebar
		 * widget cache buster. Replaces the orphan buddynext_member_removed_from_space.
		 *
		 * @param int $space_id       Space ID.
		 * @param int $user_id        Removed user.
		 * @param int $acting_user_id User who performed the removal.
		 */
		do_action( 'buddynext_space_member_removed', $space_id, $user_id, $acting_user_id );

		return true;
	}

	/**
	 * Change a member's role within a space.
	 *
	 * Only the owner or a user with manage_options can promote/demote members.
	 *
	 * @param int    $space_id  Space ID.
	 * @param int    $target_id User whose role is being changed.
	 * @param string $new_role  New role: 'owner', 'moderator', or 'member'.
	 * @param int    $actor_id  User performing the change.
	 * @return true|WP_Error
	 */
	public function change_role( int $space_id, int $target_id, string $new_role, int $actor_id ): true|WP_Error {
		if ( ! in_array( $new_role, self::ALLOWED_ROLES, true ) ) {
			return new WP_Error( 'invalid_role', __( 'Invalid role.', 'buddynext' ) );
		}

		$actor_role = $this->get_role( $space_id, $actor_id );

		if ( 'owner' !== $actor_role && ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can change member roles.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'role' => $new_role ),
			array(
				'space_id' => $space_id,
				'user_id'  => $target_id,
				'status'   => 'active',
			),
			array( '%s' ),
			array( '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->invalidate_cache( $space_id, $target_id );

		/**
		 * Fires after a space member's role changes.
		 *
		 * The only SpaceMemberService mutation that previously fired no hook, so
		 * webhooks / notifications could not react to a promotion or demotion.
		 *
		 * @param int    $space_id  Space ID.
		 * @param int    $target_id Member whose role changed.
		 * @param string $new_role  The new role slug.
		 * @param int    $actor_id  User who performed the change.
		 */
		do_action( 'buddynext_space_role_changed', $space_id, $target_id, $new_role, $actor_id );

		return true;
	}

	/**
	 * Return the denormalized member count for a space.
	 *
	 * @param int $space_id Space ID.
	 * @return int
	 */
	public function member_count( int $space_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return all active members of a space with their roles.
	 *
	 * @param int $space_id  Space ID.
	 * @param int $viewer_id Viewing user ID; when non-zero, blocked users are excluded.
	 * @return array[] Each item: user_id, role, joined_at.
	 */
	public function get_members( int $space_id, int $viewer_id = 0 ): array {
		global $wpdb;

		$block_where = '';
		if ( $viewer_id > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$block_where = $wpdb->prepare(
				" AND sm.user_id NOT IN (
				      SELECT blocked_id  FROM {$wpdb->prefix}bn_blocks WHERE blocker_id = %d
				      UNION
				      SELECT blocker_id  FROM {$wpdb->prefix}bn_blocks WHERE blocked_id  = %d
				  )",
				$viewer_id,
				$viewer_id
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.role, sm.joined_at
				 FROM {$wpdb->prefix}bn_space_members sm
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$block_where}
				 ORDER BY sm.joined_at ASC",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			fn( $r ) => array(
				'user_id'   => (int) $r['user_id'],
				'role'      => $r['role'],
				'joined_at' => $r['joined_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Return all pending join requests for a space.
	 *
	 * @param int $space_id Space ID.
	 * @return array[] Each item: user_id, joined_at (request date).
	 */
	public function get_pending_requests( int $space_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, joined_at
				 FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'pending'
				 ORDER BY joined_at ASC",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			fn( $r ) => array(
				'user_id'      => (int) $r['user_id'],
				'requested_at' => $r['joined_at'],
			),
			(array) $rows
		);
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Increment or decrement the member_count on the space row.
	 *
	 * Uses GREATEST(1, member_count) - 1 to floor at zero WITHOUT underflowing
	 * the UNSIGNED column (member_count - 1 on a 0 value would wrap to ~1.8e19
	 * before GREATEST sees it).
	 *
	 * @param int $space_id Space ID.
	 * @param int $delta    +1 to increment, -1 to decrement.
	 */
	private function adjust_member_count( int $space_id, int $delta ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $delta > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_spaces SET member_count = member_count + 1 WHERE id = %d",
					$space_id
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_spaces SET member_count = GREATEST(1, member_count) - 1 WHERE id = %d",
					$space_id
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
	}

	/**
	 * Invalidate role, status, and member-list cache keys for a space/user pair.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 */
	private function invalidate_cache( int $space_id, int $user_id ): void {
		wp_cache_delete( "role_{$space_id}_{$user_id}", self::CACHE_GROUP );
		wp_cache_delete( "status_{$space_id}_{$user_id}", self::CACHE_GROUP );
		wp_cache_delete( "members_{$space_id}", self::CACHE_GROUP );
	}

	/**
	 * Check whether a user has a hard ban row in bn_space_bans for a space.
	 *
	 * Hard bans block join attempts even when no bn_space_members row exists,
	 * preventing re-registration after a ban-and-remove sequence.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	/**
	 * Lift a space ban and allow the user to rejoin freely.
	 *
	 * Removes the permanent record from bn_space_bans and deletes the
	 * banned membership row from bn_space_members. The user must
	 * re-request to join if the space is private.
	 *
	 * @param int $space_id Space ID.
	 * @param int $actor_id Owner, moderator, or site admin lifting the ban.
	 * @param int $user_id  User to unban.
	 * @return true|WP_Error
	 */
	public function unban( int $space_id, int $actor_id, int $user_id ): true|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if (
			! in_array( $actor_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $actor_id, 'manage_options' )
		) {
			return new WP_Error(
				'forbidden',
				__( 'Only the space owner or a moderator can lift bans.', 'buddynext' )
			);
		}

		if ( ! $this->is_hard_banned( $space_id, $user_id ) ) {
			return new WP_Error( 'not_banned', __( 'This user is not banned from the space.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Remove the permanent ban record.
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		// Remove the banned membership row so the user may rejoin.
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'banned',
			),
			array( '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a space ban is lifted.
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  Unbanned user.
		 * @param int $actor_id User who lifted the ban.
		 */
		do_action( 'buddynext_space_member_unbanned', $space_id, $user_id, $actor_id );

		return true;
	}

	/**
	 * Check whether a user is permanently banned from a space.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User to check.
	 * @return bool
	 */
	public function is_banned_from_space( int $space_id, int $user_id ): bool {
		return $this->is_hard_banned( $space_id, $user_id );
	}

	// ── Block 2 — service-layer ban API (callers own capability checks) ─────

	/**
	 * Ban a user from a space by inserting into bn_space_bans, removing them
	 * from bn_space_members if they are an active member, and adjusting the
	 * member count accordingly.
	 *
	 * Callers are responsible for capability checks before calling this method.
	 *
	 * @param int    $space_id  Space ID.
	 * @param int    $user_id   User to ban.
	 * @param int    $banned_by Actor user ID (0 = system).
	 * @param string $reason    Optional ban reason.
	 * @return bool|WP_Error True on success or WP_Error on validation/DB failure.
	 */
	public function ban_from_space( int $space_id, int $user_id, int $banned_by = 0, string $reason = '' ): bool|WP_Error {
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return new WP_Error( 'invalid_args', __( 'Invalid space or user ID.', 'buddynext' ) );
		}

		// Defense-in-depth: an actor-initiated ban must come from someone who can
		// moderate the space (owner / moderator / site admin). $banned_by === 0 is
		// a system ban (automated moderation) and is exempt. The REST route also
		// gates this, but the service primitive must not be bypassable by any
		// other / future caller.
		if ( $banned_by > 0 && ! buddynext_service( 'permissions' )->can( $banned_by, 'buddynext-moderate-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to ban members from this space.', 'buddynext' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id'  => $space_id,
				'user_id'   => $user_id,
				'banned_by' => max( 0, $banned_by ),
				'reason'    => sanitize_textarea_field( $reason ),
			),
			array( '%d', '%d', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $inserted || '' !== $wpdb->last_error ) {
			return new WP_Error( 'db_error', $wpdb->last_error );
		}

		// Remove from active membership and adjust the count if they were a member.
		$was_active = ( 'active' === $this->get_status( $space_id, $user_id ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $was_active ) {
			$this->adjust_member_count( $space_id, -1 );
		}

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a user is banned from a space.
		 *
		 * @param int $space_id  Space ID.
		 * @param int $user_id   Banned user.
		 * @param int $banned_by Actor user ID.
		 */
		do_action( 'buddynext_space_user_banned', $space_id, $user_id, $banned_by );

		return true;
	}

	/**
	 * Remove a ban from a user for a given space by deleting the bn_space_bans row.
	 *
	 * Self-guards when an actor is supplied: an actor-initiated unban must come
	 * from someone who can moderate the space. $actor_id === 0 is a system unban
	 * and is exempt.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User to unban.
	 * @param int $actor_id Acting user (0 = system).
	 * @return bool|WP_Error True if a row was deleted, false if none existed,
	 *                       WP_Error when the actor lacks permission.
	 */
	public function unban_from_space( int $space_id, int $user_id, int $actor_id = 0 ): bool|WP_Error {
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		if ( $actor_id > 0 && ! buddynext_service( 'permissions' )->can( $actor_id, 'buddynext-moderate-space', array( 'space_id' => $space_id ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to unban members from this space.', 'buddynext' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a space ban is lifted.
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  Unbanned user.
		 */
		do_action( 'buddynext_space_user_unbanned', $space_id, $user_id );

		return (bool) $deleted;
	}

	/**
	 * List active bans for a space, oldest first.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max rows (scale contract: capped at 50).
	 * @return array<int,array<string,mixed>> Ban rows.
	 */
	public function get_space_bans( int $space_id, int $limit = 50 ): array {
		global $wpdb;

		if ( $space_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_space_bans WHERE space_id = %d ORDER BY created_at ASC, user_id ASC LIMIT %d",
				$space_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Check the permanent ban table for a space+user combination.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User to check.
	 * @return bool
	 */
	private function is_hard_banned( int $space_id, int $user_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_bans
				 WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $count > 0;
	}

	/**
	 * Build the WP_Error returned when a join/request is denied by the
	 * buddynext_can_join_space gate.
	 *
	 * The error data carries a `status` (403, since this is an authorization
	 * denial rather than a malformed request) plus any `paywall` metadata that a
	 * listener attaches via the buddynext_space_join_denied_data filter. Pro's
	 * gated-spaces integration hooks that filter to inject the paywall HTML, CTA
	 * url/label, required tier slug/name, and the space id — so both the REST
	 * response body and the Interactivity store can render an upgrade path
	 * instead of a bare "you cannot join" message. WP_REST_Server serialises the
	 * error data into the JSON response under `data`, making the payload
	 * available to every client (web + app).
	 *
	 * When no listener attaches anything the error degrades to the original
	 * generic message with no paywall keys — no fatal, no behaviour change for
	 * sites without Pro.
	 *
	 * @param int                  $space_id Space the user was denied.
	 * @param int                  $user_id  User attempting to join.
	 * @param array<string, mixed> $space    Space row (may be empty on miss).
	 * @param string               $action   'join' or 'request'.
	 * @return WP_Error
	 */
	private function denied_join_error( int $space_id, int $user_id, array $space, string $action ): WP_Error {
		$data = array( 'status' => 403 );

		/**
		 * Filter the data payload attached to a gated join/request denial.
		 *
		 * Listeners (notably Pro's gated-spaces paywall) may add a `paywall`
		 * sub-array carrying rendered HTML and CTA metadata. The returned array
		 * is set verbatim as the WP_Error data, so it surfaces in the REST
		 * response body under `data` for both web and app clients.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $data     Error data. Always includes `status`.
		 * @param int                  $space_id Space the user was denied.
		 * @param int                  $user_id  User attempting to join.
		 * @param array<string, mixed> $space    Space row (may be empty on miss).
		 * @param string               $action   'join' or 'request'.
		 */
		$data = (array) apply_filters( 'buddynext_space_join_denied_data', $data, $space_id, $user_id, $space, $action );

		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 403;
		}

		$message = isset( $data['message'] ) && is_string( $data['message'] ) && '' !== $data['message']
			? $data['message']
			: __( 'You cannot join this space.', 'buddynext' );
		unset( $data['message'] );

		return new WP_Error( 'cannot_join_space', $message, $data );
	}

	/**
	 * Load a single bn_spaces row as an associative array. Returns [] on miss.
	 *
	 * Used by join() / request_join() to give the buddynext_can_join_space
	 * filter listeners the actual space data (notably required_ability for the
	 * Pro gated-spaces gate).
	 *
	 * @param int $space_id Space ID.
	 * @return array<string, mixed>
	 */
	private function load_space_row( int $space_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $row : array();
	}
}
