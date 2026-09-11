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

	/**
	 * Hard cap on rows returned by a single roster read — a member list is ALWAYS
	 * bounded so no caller can load a full 50k roster into memory. Paginate via
	 * $offset for more. The historic $limit = 0 "all" default now clamps to this.
	 */
	private const MAX_MEMBERS_PER_QUERY = 200;

	/**
	 * Hard cap on an ID-ONLY roster page ({@see get_member_ids()}). Higher than
	 * MAX_MEMBERS_PER_QUERY because a bare user_id column is cheap to read where a
	 * hydrated roster row (name, nicename, avatar) is not — this is the read a
	 * consumer walks in batches to compute a subset over a large space's whole
	 * membership. Still bounded, so no caller loads a 50k roster in one query.
	 */
	private const MAX_MEMBER_IDS_PER_QUERY = 1000;

	// ── Public membership API ───────────────────────────────────────────────

	/**
	 * Resolve the space's configured default notification preference for new
	 * members, used by every membership-creation path (join / request / invite)
	 * so the owner's "Default notifications for new members" setting is honoured
	 * once, in one place.
	 *
	 * @param int $space_id Space ID.
	 * @return string One of 'all' | 'mentions_only' | 'none'.
	 */
	private function default_notification_pref( int $space_id ): string {
		$pref = (string) buddynext_get_space_field( $space_id, 'default_notification_pref' );

		// Fallback mirrors the field default (CoreSpaceFields): new members start on
		// the quieter 'mentions_only' and opt UP to all activity, never silently
		// subscribed to every post on join (owner ruling, card 10294398101 item 7).
		return in_array( $pref, array( 'all', 'mentions_only', 'none' ), true ) ? $pref : 'mentions_only';
	}

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
	public function join( int $space_id, int $user_id ): bool|WP_Error {
		// Pre-load space row so listeners on buddynext_can_join_space (including
		// Pro's gated-space gate) receive the actual required_ability + type.
		$space = $this->load_space_row( $space_id );

		// A missing space (just deleted, or a bad ID) must 404 before any DB write —
		// otherwise load_space_row's empty array slips past the archived check and a
		// membership row is inserted for a space_id that no longer exists.
		if ( empty( $space ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'This space no longer exists.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

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
			// New member — seed notification preference from the space default.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, notification_pref, joined_at)
					 VALUES (%d, %d, 'member', 'active', %s, %s)",
					$space_id,
					$user_id,
					$this->default_notification_pref( $space_id ),
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
	public function request_join( int $space_id, int $user_id ): bool|WP_Error {
		// Pre-load space row so listeners receive the actual required_ability + type.
		$space = $this->load_space_row( $space_id );

		// A missing space (just deleted, or a bad ID) must 404 before any DB write —
		// otherwise load_space_row's empty array slips past the archived check and a
		// membership row is inserted for a space_id that no longer exists.
		if ( empty( $space ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'This space no longer exists.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

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
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, notification_pref, joined_at)
				 VALUES (%d, %d, 'member', 'pending', %s, %s)",
				$space_id,
				$user_id,
				$this->default_notification_pref( $space_id ),
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
		if ( user_can( $inviter_id, 'manage_options' ) ) {
			return true;
		}

		$inviter_role = (string) $this->get_role( $space_id, $inviter_id );
		if ( '' === $inviter_role ) {
			return false; // Not a member of the space.
		}

		// Honour the per-space "who can invite" setting (members | mods | owner),
		// using the shared role-rank model (SpaceRoles) — same gate as who_can_post.
		$who = (string) buddynext_get_space_field( $space_id, 'who_can_invite' );
		$can = SpaceRoles::meets( $inviter_role, $who, 2 );

		/**
		 * Filter whether a user may invite others to a space, after the per-space
		 * who_can_invite gate.
		 *
		 * @param bool   $can          Whether inviting is allowed.
		 * @param int    $space_id     Space ID.
		 * @param int    $inviter_id   User attempting to invite.
		 * @param string $inviter_role The inviter's role in the space.
		 */
		return (bool) apply_filters( 'buddynext_space_can_invite', $can, $space_id, $inviter_id, $inviter_role );
	}

	/**
	 * Invite an existing member to a space (status='invited').
	 *
	 * @param int $space_id        Space to invite into.
	 * @param int $inviter_id      Acting user (must be owner/mod or admin).
	 * @param int $invited_user_id User being invited.
	 * @return true|WP_Error
	 */
	public function invite( int $space_id, int $inviter_id, int $invited_user_id ): bool|WP_Error {
		if ( empty( $this->load_space_row( $space_id ) ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'This space no longer exists.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

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
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status, notification_pref, joined_at)
					 VALUES (%d, %d, 'member', 'invited', %s, %s)",
					$space_id,
					$invited_user_id,
					$this->default_notification_pref( $space_id ),
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
	public function approve_request( int $space_id, int $actor_id, int $user_id ): bool|WP_Error {
		if ( empty( $this->load_space_row( $space_id ) ) ) {
			return new WP_Error(
				'space_not_found',
				__( 'This space no longer exists.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$actor_role = $this->get_role( $space_id, $actor_id );

		if ( ! SpaceRoles::can_moderate( $actor_role, $actor_id ) ) {
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
	public function decline_request( int $space_id, int $actor_id, int $user_id ): bool|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if ( ! SpaceRoles::can_moderate( $actor_role, $actor_id ) ) {
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
	public function ban( int $space_id, int $actor_id, int $user_id, string $reason = '' ): bool|WP_Error {
		$actor_role = $this->get_role( $space_id, $actor_id );

		if ( ! SpaceRoles::can_moderate( $actor_role, $actor_id ) ) {
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
		 * Fires after a member is removed from a space (the ban also removes the
		 * membership). Kept so removal listeners — e.g. sidebar cache busting —
		 * react regardless of WHY the member left.
		 *
		 * @param int $space_id   Space ID.
		 * @param int $user_id    Removed user.
		 * @param int $actor_id   User who performed the removal.
		 */
		do_action( 'buddynext_space_member_removed', $space_id, $user_id, $actor_id );

		/**
		 * Fires after a user is banned from a space. Distinct from the removal
		 * event above: this is ban-specific so listeners (notifications, the
		 * banned-users list) fire on the settings-UI ban path too, matching the
		 * REST moderation path (ban_from_space()). Previously only _removed fired
		 * here, so ban-specific listeners missed bans issued from space settings.
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  Banned user.
		 * @param int $actor_id User who issued the ban.
		 */
		do_action( 'buddynext_space_user_banned', $space_id, $user_id, $actor_id );

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
	public function leave( int $space_id, int $user_id ): bool|WP_Error {
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
	public function set_notification_pref( int $space_id, int $user_id, string $pref ): bool|WP_Error {
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
	public function cancel_request( int $space_id, int $user_id ): bool|WP_Error {
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
	/**
	 * Prime the per-pair role cache for one viewer across a page of spaces.
	 *
	 * The directory batch-fetches the viewer's memberships in one query and then
	 * calls can_invite() / get_role() per row - which each read the
	 * "role_{space}_{user}" cache this service owns. Without priming, the batch
	 * fed a local array only and every row still missed cache (~3 uncached
	 * permission resolutions per row at directory scale). The caller hands over
	 * what its batch already knows; misses are cached as '' on purpose - "not an
	 * active member" is an answer, and the empty string is how get_role()
	 * distinguishes it from a cache miss.
	 *
	 * @param int                $user_id       Viewer whose roles are known.
	 * @param array<int, string> $role_by_space space_id => active role, or '' when
	 *                                          the viewer is not an ACTIVE member
	 *                                          (get_role()'s own semantics).
	 * @return void
	 */
	public function prime_viewer_roles( int $user_id, array $role_by_space ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		foreach ( $role_by_space as $space_id => $role ) {
			wp_cache_set( 'role_' . (int) $space_id . '_' . $user_id, (string) $role, self::CACHE_GROUP, self::CACHE_TTL );
		}
	}

	/**
	 * A member's role in a space, or null when they are not a member.
	 *
	 * Cached per (space, user) in CACHE_GROUP; the cache is dropped whenever the
	 * membership row changes.
	 *
	 * @param int $space_id Space id.
	 * @param int $user_id  User id.
	 * @return string|null Role slug (owner|moderator|member), or null.
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

		if ( ! SpaceRoles::can_moderate( $acting_role, $acting_user_id ) ) {
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
	public function change_role( int $space_id, int $target_id, string $new_role, int $actor_id ): bool|WP_Error {
		if ( ! in_array( $new_role, self::ALLOWED_ROLES, true ) ) {
			return new WP_Error( 'invalid_role', __( 'Invalid role.', 'buddynext' ) );
		}

		$actor_role = $this->get_role( $space_id, $actor_id );

		if ( 'owner' !== $actor_role && ! user_can( $actor_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'Only the space owner can change member roles.', 'buddynext' ) );
		}

		// Ownership does not move through this method, in either direction.
		//
		// This is a lockout guard, not tidiness. `bn_spaces.owner_id` and the
		// `role = 'owner'` row in bn_space_members are two records of the same fact,
		// and only assign_owner() moves them together (see SpaceAssignOwnerTest,
		// "both tables must move together - this is the divergence bug"). This method
		// writes bn_space_members alone.
		//
		// Reproduced before adding this: an owner demoting THEMSELVES to member
		// succeeded, leaving 0 rows with role='owner' while bn_spaces.owner_id still
		// named them. Every space gate resolves through get_role(), which reads
		// bn_space_members - so the ex-owner immediately lost both
		// buddynext-manage-space and buddynext-own-space, and the space could no
		// longer be managed, deleted or transferred by anyone short of a site admin.
		// One click, from the members screen, permanently.
		//
		// Promoting TO owner is refused for the mirror reason: it would mint a second
		// owner row that bn_spaces.owner_id does not know about. Callers that mean to
		// transfer ownership call assign_owner(), which demotes the outgoing owner and
		// updates both tables in one transaction.
		if ( 'owner' === $new_role ) {
			return new WP_Error(
				'cannot_promote_to_owner',
				__( 'Ownership is transferred, not assigned as a role. Use the transfer-ownership action.', 'buddynext' )
			);
		}

		if ( 'owner' === $this->get_role( $space_id, $target_id ) ) {
			return new WP_Error(
				'cannot_change_owner_role',
				__( 'The space owner\'s role cannot be changed here. Transfer ownership first.', 'buddynext' )
			);
		}

		// Re-assigning the current role is a no-op — skip the write and the
		// buddynext_space_role_changed hook (it promises an actual change, so
		// consumers may notify or award on it).
		if ( $this->get_role( $space_id, $target_id ) === $new_role ) {
			return true;
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
	 * How many DISTINCT people are in these spaces, counted once each.
	 *
	 * The batched answer an owner dashboard needs, and the reason it has to exist:
	 * summing `bn_spaces.member_count` across spaces double-counts anyone who
	 * belongs to two of them, and the error grows with exactly the communities that
	 * are working - active members join more spaces. "412 members" when the real
	 * number is 300 is not a rounding problem, it is a different claim.
	 *
	 * The alternative available before this was a loop of `member_count()` calls,
	 * which is an N+1 AND still double-counts. There was no correct option.
	 *
	 * Counts `status = 'active'` only: invited, pending and banned rows are not
	 * members. That matches `member_count()`'s denormalised column, so the batched
	 * and singular answers agree for a single space.
	 *
	 * @since 1.1.6
	 *
	 * @param array<int,int> $space_ids Spaces to count across.
	 * @return int Distinct active members, 0 when the list is empty.
	 */
	public function count_distinct_members( array $space_ids ): int {
		$space_ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );

		if ( empty( $space_ids ) ) {
			return 0;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id IN ($placeholders) AND status = 'active'",
				$space_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * List DISTINCT active members across a set of spaces, paginated — the
	 * cross-space companion to count_distinct_members().
	 *
	 * An addon hub (e.g. Wellbee Circles) needs a platform-wide, paginated member
	 * list scoped to ITS category's spaces without querying bn_space_members
	 * directly (card 10264292... 10276234622). count_distinct_members() gives the
	 * count; every list method was single-space. One user in several of the spaces
	 * is ONE row: GROUP BY user_id, earliest joined_at, highest role (owner >
	 * moderator > member, which is also the alphabetical MAX), and the set of space
	 * ids they belong to. Names join wp_users in the same query — no per-row lookup
	 * (big-site: real COUNT for total, bounded LIMIT/OFFSET, indexed (space_id,
	 * status), one user query).
	 *
	 * @param array<string,mixed> $args Keys: space_ids (int[], the spaces to list
	 *                                  across, takes precedence); category_id (int,
	 *                                  resolve space_ids from this non-archived space
	 *                                  category when space_ids is empty); page (int,
	 *                                  1-based, default 1); per_page (int, 1-100,
	 *                                  default 20); role (string, restrict to one
	 *                                  membership role); search (string, LIKE match on
	 *                                  display_name / user_login / user_nicename).
	 * @return array{items: array<int, array{user_id:int, space_ids:int[], role:string, joined_at:string, display_name:string, user_nicename:string}>, total: int}
	 */
	public function list_members_across_spaces( array $args ): array {
		global $wpdb;

		$space_ids = isset( $args['space_ids'] ) ? (array) $args['space_ids'] : array();
		$space_ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );

		// Resolve a category to its (non-archived) space ids when space_ids was not
		// given directly, so the addon can pass its circle category and nothing else.
		$category_id = isset( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		if ( empty( $space_ids ) && $category_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE category_id = %d AND is_archived = 0", $category_id )
				)
			);
		}

		if ( empty( $space_ids ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$per_page = max( 1, min( 100, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset   = ( $page - 1 ) * $per_page;
		$role     = isset( $args['role'] ) ? sanitize_key( (string) $args['role'] ) : '';
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		// One WHERE, shared by the COUNT and the page, so total always matches the
		// list under the same role/search filter.
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		$where        = "sm.space_id IN ($placeholders) AND sm.status = 'active'";
		$params       = $space_ids;

		if ( '' !== $role ) {
			$where   .= ' AND sm.role = %s';
			$params[] = $role;
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where   .= ' AND ( u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT sm.user_id)
				 FROM {$wpdb->prefix}bn_space_members sm
				 JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE {$where}",
				$params
			)
		);

		$rows = $total > 0 ? (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id,
				        MIN(sm.joined_at) AS joined_at,
				        MAX(sm.role) AS role,
				        GROUP_CONCAT(DISTINCT sm.space_id) AS space_ids,
				        u.display_name, u.user_nicename
				 FROM {$wpdb->prefix}bn_space_members sm
				 JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE {$where}
				 GROUP BY sm.user_id, u.display_name, u.user_nicename
				 ORDER BY joined_at ASC, sm.user_id ASC
				 LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		) : array();
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$items = array();
		foreach ( $rows as $r ) {
			$items[] = array(
				'user_id'       => (int) $r['user_id'],
				'space_ids'     => array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $r['space_ids'] ?? '' ) ) ) ) ),
				'role'          => (string) ( $r['role'] ?? '' ),
				'joined_at'     => (string) ( $r['joined_at'] ?? '' ),
				'display_name'  => (string) ( $r['display_name'] ?? '' ),
				'user_nicename' => (string) ( $r['user_nicename'] ?? '' ),
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Pending join requests waiting across these spaces, in one query.
	 *
	 * The batched sibling of `count_pending_requests( int $space_id )` further down,
	 * named `_for_spaces` to match `ModerationService::count_open_reports_for_spaces()`.
	 * Singular takes an int, plural takes an array - one rule across both services.
	 *
	 * Rows rather than distinct people, deliberately, and the opposite call from
	 * `count_distinct_members()` above: this is a queue length. One person asking to
	 * join three spaces is three decisions an owner has to make, and collapsing them
	 * to one would under-report the work waiting.
	 *
	 * @since 1.1.6
	 *
	 * @param array<int,int> $space_ids Spaces to count across.
	 * @return int Pending requests, 0 when the list is empty.
	 */
	public function count_pending_requests_for_spaces( array $space_ids ): int {
		$space_ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );

		if ( empty( $space_ids ) ) {
			return 0;
		}

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id IN ($placeholders) AND status = 'pending'",
				$space_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Build the "exclude blocked users" SQL fragment for member queries.
	 *
	 * Returns a prepared ` AND sm.user_id NOT IN (...)` clause, or '' when no
	 * viewer is supplied. Shared by get_members() and count_members() so the
	 * count always matches the paginated rows.
	 *
	 * @param int $viewer_id Viewing user ID.
	 * @return string
	 */
	private function member_block_where( int $viewer_id ): string {
		if ( $viewer_id <= 0 ) {
			return '';
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->prepare(
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

	/**
	 * Return active members of a space with their roles.
	 *
	 * Pagination is opt-in: pass $limit > 0 to bound the result with LIMIT/OFFSET
	 * (a large space would otherwise load its full roster into memory). The
	 * default $limit = 0 preserves the original unbounded behaviour for existing
	 * callers. Use count_members() for the matching total.
	 *
	 * The projection now also carries display_name and user_nicename (joined from
	 * wp_users) so the members template can render and link a member without a
	 * per-row lookup. Existing callers that only read user_id/role/joined_at are
	 * unaffected.
	 *
	 * Optional $args refine the roster (all default to the original behaviour):
	 *   search (string)  — match display_name / user_login / user_nicename (LIKE).
	 *   role   (string)  — restrict to one role: 'owner' | 'moderator' | 'member'.
	 *   exclude_suspended (bool) — compose ModerationService::moderation_exclude_sql()
	 *                       to drop suspended + shadow-banned users (default false,
	 *                       so existing callers keep the full active roster).
	 *
	 * @param int                  $space_id  Space ID.
	 * @param int                  $viewer_id Viewing user ID; when non-zero, blocked users are excluded.
	 * @param int                  $limit     Max rows to return; 0 = no limit.
	 * @param int                  $offset    Row offset (applied only when $limit > 0).
	 * @param array<string, mixed> $args      Optional search / role / exclude_suspended refinements.
	 * @return array[] Each item: user_id, role, joined_at, display_name, user_nicename.
	 */
	public function get_members( int $space_id, int $viewer_id = 0, int $limit = 0, int $offset = 0, array $args = array() ): array {
		// Always bounded — $limit <= 0 (the historic "all") clamps to the cap, a larger
		// ask clamps down, so a full 50k roster is never loaded in one read.
		$limit = ( $limit <= 0 ) ? self::MAX_MEMBERS_PER_QUERY : min( $limit, self::MAX_MEMBERS_PER_QUERY );

		// Cache the roster page under a per-space version salt (the membership_rows()
		// idiom): invalidate_cache() bumps that salt on every join / leave / role
		// change, retiring all variants at once. Keyed on the full arg set (viewer +
		// role/search filters + limit + offset) so no two shapes collide. Finishes a
		// previously half-wired cache — invalidate_cache() already dropped a members_*
		// key, but nothing ever wrote it, so every space-page roster read hit the DB.
		$cache_key = 'members_v' . self::cache_version( "members_ver_{$space_id}" ) . '_'
			. md5( (string) wp_json_encode( array( $space_id, $viewer_id, $limit, max( 0, $offset ), $args ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$block_where = $this->member_block_where( $viewer_id );

		// Optional role filter (validated against the allow-list).
		$role_where = '';
		$role       = isset( $args['role'] ) ? (string) $args['role'] : '';
		if ( in_array( $role, self::ALLOWED_ROLES, true ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$role_where = $wpdb->prepare( ' AND sm.role = %s', $role );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Optional name search across the three identity columns.
		$search_where = '';
		$search       = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$search_where = $wpdb->prepare(
				' AND ( u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s )',
				$like,
				$like,
				$like
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Optional suspension / shadow-ban exclusion via the canonical builder.
		$moderation_where = '';
		if ( ! empty( $args['exclude_suspended'] ) ) {
			$moderation_where = ' ' . buddynext_service( 'moderation' )->moderation_exclude_sql( 'sm.user_id' );
		}

		// $limit was clamped to the cap at the top (before the cache key was built).
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.role, sm.joined_at, u.display_name, u.user_nicename
				 FROM {$wpdb->prefix}bn_space_members sm
				 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$block_where}{$role_where}{$search_where}{$moderation_where}
				 ORDER BY sm.joined_at ASC{$limit_sql}",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->prime_roster_users( (array) $rows );

		$members = array_map(
			fn( $r ) => array(
				'user_id'       => (int) $r['user_id'],
				'role'          => $r['role'],
				'joined_at'     => $r['joined_at'],
				'display_name'  => (string) ( $r['display_name'] ?? '' ),
				'user_nicename' => (string) ( $r['user_nicename'] ?? '' ),
				// Included so a native app can render the member list without a
				// per-user avatar fetch (client-side N+1).
				'avatar_url'    => get_avatar_url( (int) $r['user_id'], array( 'size' => 96 ) ),
			),
			(array) $rows
		);

		// The mapped rows carry display_name + avatar_url, so a hit needs no
		// re-priming — the whole page is self-contained.
		wp_cache_set( $cache_key, $members, self::CACHE_GROUP, self::CACHE_TTL );

		return $members;
	}

	/**
	 * A stable, ID-only page of a space's active member user IDs.
	 *
	 * The cheap read that lets a consumer walk EVERY member of a large space in
	 * batches. get_members() clamps each page to MAX_MEMBERS_PER_QUERY (200) and
	 * hydrates every row (name, nicename, avatar), so it cannot be used to compute
	 * a SUBSET over a space past 200 members — e.g. intersecting the membership
	 * with an external paid-enrolment set to split paid/free (Basecamp 10229921690).
	 * That undercounted silently once a space passed 200; count_members() stayed
	 * correct (COUNT(*)), so only the subset split was wrong.
	 *
	 * Returns only user_ids, ORDERED BY user_id so successive offsets never repeat
	 * or skip a row (joined_at can tie; user_id is unique), and applies the SAME
	 * active / block / role / suspension gates as get_members()/count_members() so
	 * a full walk and count_members() agree. Walk until a page returns fewer than
	 * `$limit` ids.
	 *
	 * @param int                  $space_id  Space ID.
	 * @param int                  $viewer_id Viewing user ID; when non-zero, blocked users are excluded.
	 * @param int                  $limit     Page size (>0). Clamped to MAX_MEMBER_IDS_PER_QUERY (1000); 0 uses the cap.
	 * @param int                  $offset    Row offset.
	 * @param array<string, mixed> $args      Optional role / exclude_suspended refinements (same keys as count_members()).
	 * @return int[] Member user IDs for this page, ascending.
	 */
	public function get_member_ids( int $space_id, int $viewer_id = 0, int $limit = 0, int $offset = 0, array $args = array() ): array {
		$limit  = ( $limit <= 0 ) ? self::MAX_MEMBER_IDS_PER_QUERY : min( $limit, self::MAX_MEMBER_IDS_PER_QUERY );
		$offset = max( 0, $offset );

		// Per-space version salt (the membership_rows() idiom) — invalidate_cache()
		// bumps it on every join / leave / role change, retiring all pages at once.
		$cache_key = 'member_ids_v' . self::cache_version( "members_ver_{$space_id}" ) . '_'
			. md5( (string) wp_json_encode( array( $space_id, $viewer_id, $limit, $offset, $args ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$block_where = $this->member_block_where( $viewer_id );

		$role_where = '';
		$role       = isset( $args['role'] ) ? (string) $args['role'] : '';
		if ( in_array( $role, self::ALLOWED_ROLES, true ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$role_where = $wpdb->prepare( ' AND sm.role = %s', $role );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$moderation_where = '';
		if ( ! empty( $args['exclude_suspended'] ) ) {
			$moderation_where = ' ' . buddynext_service( 'moderation' )->moderation_exclude_sql( 'sm.user_id' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sm.user_id
				 FROM {$wpdb->prefix}bn_space_members sm
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$block_where}{$role_where}{$moderation_where}
				 ORDER BY sm.user_id ASC
				 LIMIT %d OFFSET %d",
				$space_id,
				$limit,
				$offset
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = array_map( 'intval', (array) $ids );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::CACHE_TTL );

		return $ids;
	}

	/**
	 * Build the shared active-member filter WHERE fragment (block + role +
	 * optional search + optional suspension), so the keyset readers below apply
	 * the SAME gates as get_members()/count_members() without re-authoring them.
	 *
	 * Search touches the joined wp_users columns (u.*), so it is only valid where
	 * the caller's query joins wp_users — get_member_ids has no join, so it passes
	 * $with_search = false.
	 *
	 * @param int                  $viewer_id   Viewer (0 = none); non-zero excludes blocked users.
	 * @param array<string, mixed> $args        role / search / exclude_suspended refinements.
	 * @param bool                 $with_search Whether to apply the u.* name search.
	 * @return string SQL fragment beginning with a leading space, or ''.
	 */
	private function member_filters_sql( int $viewer_id, array $args, bool $with_search ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = $this->member_block_where( $viewer_id );
		$role = isset( $args['role'] ) ? (string) $args['role'] : '';
		if ( in_array( $role, self::ALLOWED_ROLES, true ) ) {
			$sql .= $wpdb->prepare( ' AND sm.role = %s', $role );
		}
		if ( $with_search ) {
			$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
			if ( '' !== $search ) {
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				$sql .= $wpdb->prepare(
					' AND ( u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s )',
					$like,
					$like,
					$like
				);
			}
		}
		if ( ! empty( $args['exclude_suspended'] ) ) {
			$sql .= ' ' . buddynext_service( 'moderation' )->moderation_exclude_sql( 'sm.user_id' );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return $sql;
	}

	/**
	 * Keyset roster page — the scale replacement for get_members()'s OFFSET read.
	 *
	 * Same gates and same hydrated row shape as get_members(), but seeks to the
	 * cursor on (joined_at, user_id) instead of skipping OFFSET rows, so page N of
	 * a 50k roster costs the same as page 1. The original ORDER BY was joined_at
	 * only (non-unique); the keyset adds sm.user_id as the tiebreak so pages never
	 * skip or repeat a member who shares a joined_at. Card 10284805802.
	 *
	 * @param int                  $space_id  Space ID.
	 * @param int                  $viewer_id Viewer (0 = none).
	 * @param string|null          $cursor    Prior page's cursor, or null for page 1.
	 * @param int                  $per_page  Rows per page (clamped to MAX_MEMBERS_PER_QUERY).
	 * @param array<string, mixed> $args      role / search / exclude_suspended refinements.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	public function get_members_keyset( int $space_id, int $viewer_id = 0, ?string $cursor = null, int $per_page = 24, array $args = array() ): array {
		$space_id = absint( $space_id );
		$per_page = ( $per_page <= 0 ) ? 24 : min( $per_page, self::MAX_MEMBERS_PER_QUERY );
		if ( $space_id <= 0 ) {
			return array(
				'items'       => array(),
				'next_cursor' => null,
			);
		}

		$cache_key = 'members_ks_v' . self::cache_version( "members_ver_{$space_id}" ) . '_'
			. md5( (string) wp_json_encode( array( $space_id, $viewer_id, $per_page, (string) $cursor, $args ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$filters       = $this->member_filters_sql( $viewer_id, $args, true );
		$cursor_data   = ( null !== $cursor && '' !== $cursor ) ? \BuddyNext\Core\CursorCodec::decode( $cursor ) : null;
		$cursor_where  = '';
		$cursor_params = array();
		if ( null !== $cursor_data ) {
			$cursor_where  = ' AND ( sm.joined_at > %s OR ( sm.joined_at = %s AND sm.user_id > %d ) )';
			$cursor_params = array( $cursor_data['created_at'], $cursor_data['created_at'], $cursor_data['id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.role, sm.joined_at, u.display_name, u.user_nicename
				 FROM {$wpdb->prefix}bn_space_members sm
				 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$filters}{$cursor_where}
				 ORDER BY sm.joined_at ASC, sm.user_id ASC
				 LIMIT %d",
				...array_merge( array( $space_id ), $cursor_params, array( $per_page + 1 ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$rows        = (array) $rows;
		$has_more    = count( $rows ) > $per_page;
		$rows        = $has_more ? array_slice( $rows, 0, $per_page ) : $rows;
		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = \BuddyNext\Core\CursorCodec::encode( (string) $last['joined_at'], (int) $last['user_id'] );
		}

		$this->prime_roster_users( $rows );

		$items = array_map(
			fn( $r ) => array(
				'user_id'       => (int) $r['user_id'],
				'role'          => $r['role'],
				'joined_at'     => $r['joined_at'],
				'display_name'  => (string) ( $r['display_name'] ?? '' ),
				'user_nicename' => (string) ( $r['user_nicename'] ?? '' ),
				'avatar_url'    => get_avatar_url( (int) $r['user_id'], array( 'size' => 96 ) ),
			),
			$rows
		);

		$result = array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Keyset ID-only page — the scale replacement for get_member_ids()'s OFFSET
	 * walk. ORDER BY sm.user_id (unique), so the cursor is a single user_id and a
	 * full walk never repeats or skips a row. Card 10284805802.
	 *
	 * @param int                  $space_id  Space ID.
	 * @param int                  $viewer_id Viewer (0 = none).
	 * @param string|null          $cursor    Prior page's cursor, or null for page 1.
	 * @param int                  $per_page  Page size (clamped to MAX_MEMBER_IDS_PER_QUERY).
	 * @param array<string, mixed> $args      role / exclude_suspended refinements.
	 * @return array{ids: int[], next_cursor: string|null}
	 */
	public function get_member_ids_keyset( int $space_id, int $viewer_id = 0, ?string $cursor = null, int $per_page = 0, array $args = array() ): array {
		$space_id = absint( $space_id );
		$per_page = ( $per_page <= 0 ) ? self::MAX_MEMBER_IDS_PER_QUERY : min( $per_page, self::MAX_MEMBER_IDS_PER_QUERY );
		if ( $space_id <= 0 ) {
			return array(
				'ids'         => array(),
				'next_cursor' => null,
			);
		}

		$cache_key = 'member_ids_ks_v' . self::cache_version( "members_ver_{$space_id}" ) . '_'
			. md5( (string) wp_json_encode( array( $space_id, $viewer_id, $per_page, (string) $cursor, $args ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$filters       = $this->member_filters_sql( $viewer_id, $args, false );
		$cursor_data   = ( null !== $cursor && '' !== $cursor ) ? \BuddyNext\Core\CursorCodec::decode( $cursor ) : null;
		$cursor_where  = '';
		$cursor_params = array();
		if ( null !== $cursor_data ) {
			$cursor_where  = ' AND sm.user_id > %d';
			$cursor_params = array( $cursor_data['id'] );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT sm.user_id
				 FROM {$wpdb->prefix}bn_space_members sm
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$filters}{$cursor_where}
				 ORDER BY sm.user_id ASC
				 LIMIT %d",
				...array_merge( array( $space_id ), $cursor_params, array( $per_page + 1 ) )
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$ids         = array_map( 'intval', (array) $ids );
		$has_more    = count( $ids ) > $per_page;
		$ids         = $has_more ? array_slice( $ids, 0, $per_page ) : $ids;
		$next_cursor = null;
		if ( $has_more && ! empty( $ids ) ) {
			// user_id-only keyset: encode with an empty created_at slot; decode reads id.
			$next_cursor = \BuddyNext\Core\CursorCodec::encode( '', (int) end( $ids ) );
		}

		$result = array(
			'ids'         => $ids,
			'next_cursor' => $next_cursor,
		);
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Prime the user cache for a roster page.
	 *
	 * The get_avatar_url() call resolves the user behind each id, so mapping it over a page of
	 * members was one user lookup PER ROW. Bounded (MAX_MEMBERS_PER_QUERY caps the page), so it never
	 * ran away — it was just up to 200 avoidable queries per roster page. One batched fetch,
	 * and every get_avatar_url() below is served from cache.
	 *
	 * @param array<int,array<string,mixed>> $rows Roster rows carrying user_id.
	 * @return void
	 */
	private function prime_roster_users( array $rows ): void {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( static fn( $r ): int => (int) ( $r['user_id'] ?? 0 ), $rows )
				)
			)
		);

		if ( ! empty( $ids ) ) {
			cache_users( $ids );
		}
	}

	/**
	 * Map a user's membership across a set of spaces in one query.
	 *
	 * Powers the directory grid, which previously ran an inline IN(...) query to
	 * decorate each card with the viewer's role/status. Returns only the spaces
	 * the user has a row in; absent space IDs mean "not a member".
	 *
	 * @param int   $user_id   Member to look up.
	 * @param int[] $space_ids Space IDs to check.
	 * @return array<int, array{role: string, status: string}> Keyed by space_id.
	 */
	public function membership_map( int $user_id, array $space_ids ): array {
		$user_id   = absint( $user_id );
		$space_ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );

		if ( $user_id <= 0 || empty( $space_ids ) ) {
			return array();
		}

		global $wpdb;

		// $placeholders is a "%d,%d,..." string from array_fill( count( $space_ids ) ),
		// bound through ...$space_ids; the analyser only counts the one literal %d, so
		// it reports ReplacementsWrongNumber even though every value is bound.
		$placeholders = implode( ', ', array_fill( 0, count( $space_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT space_id, role, status
				 FROM {$wpdb->prefix}bn_space_members
				 WHERE user_id = %d AND space_id IN ( {$placeholders} )",
				$user_id,
				...$space_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['space_id'] ] = array(
				'role'   => (string) $row['role'],
				'status' => (string) $row['status'],
			);
		}

		return $map;
	}

	/**
	 * Return active members eligible to receive ownership of a space.
	 *
	 * Everyone except the current owner — the candidate list a settings screen
	 * offers when transferring ownership. Joined to wp_users so the picker can
	 * render a name without a per-row lookup.
	 *
	 * @param int $space_id        Space ID.
	 * @param int $exclude_owner_id Current owner to leave out of the list.
	 * @return array[] Each item: user_id, role, display_name.
	 */
	public function transfer_candidates( int $space_id, int $exclude_owner_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// Bounded + moderators first so the likely transfer targets are always
				// in the (capped) candidate set, even on a large space. A very large
				// space would want a search-backed picker (follow-up); the cap keeps the
				// read safe meanwhile.
				"SELECT sm.user_id, sm.role, u.display_name
				 FROM {$wpdb->prefix}bn_space_members sm
				 INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id
				 WHERE sm.space_id = %d AND sm.status = 'active' AND sm.user_id <> %d
				 ORDER BY FIELD(sm.role, 'owner', 'moderator', 'member'), u.display_name ASC
				 LIMIT 200",
				$space_id,
				$exclude_owner_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static fn( $r ) => array(
				'user_id'      => (int) $r['user_id'],
				'role'         => (string) $r['role'],
				'display_name' => (string) $r['display_name'],
			),
			(array) $rows
		);
	}

	/**
	 * Return the space IDs a user is an active member of.
	 *
	 * A bulk accessor for "is the viewer in any of these spaces" decorations
	 * (e.g. onboarding's joined-set) so a template never loops get_status().
	 * Ordered newest-joined first.
	 *
	 * @param int $user_id Member to look up.
	 * @return int[] Active space IDs.
	 */
	public function spaces_for_user( int $user_id ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$wpdb->prefix}bn_space_members
				 WHERE user_id = %d AND status = 'active'
				 ORDER BY joined_at DESC",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Return the spaces a user actively belongs to, joined to the space row so
	 * each result carries the id, name, slug and the member's role.
	 *
	 * Powers the profile "Spaces" sidebar/strip, which needs the display fields
	 * (name/slug) plus the viewer's role per space without a per-row lookup.
	 * Ordered newest-joined first; capped by $limit.
	 *
	 * @param int $user_id Member to look up.
	 * @param int $limit   Max rows (1-50). Default 5.
	 * @return object[] Each row: id, name, slug, role.
	 */
	public function membership_rows( int $user_id, int $limit = 5 ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// Over-fetch a bounded ceiling so the addon filter below (which drops
		// category-flagged spaces, e.g. Wellbee Circles) sees the FULL membership set
		// BEFORE the cap. Filtering an already-LIMITed list left the flyout short of
		// its own badge — the badge counts the same visible set (card 10276700689).
		// The rail only ever renders a handful; count_memberships() gives the true
		// total for the "See all" link.
		$fetch = 200; // ponytail: fixed ceiling; keyset if a member ever exceeds it.

		// The "My spaces" rail flyout runs this on EVERY hub page a logged-in member
		// loads, alongside its count. Two queries on every page of the site, for a list
		// that only changes when the member joins or leaves something. Cache the
		// unfiltered ceiling once per member (not per requested limit); the addon
		// filter + the slice run per call so an exclusion change is never served stale.
		$cache_key = 'membership_rows_v' . self::membership_summary_version( $user_id ) . "_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			$rows = $cached;
		} else {
			global $wpdb;

			// s.category_id is selected so a consumer of the filter below can drop a
			// category-flagged space (an addon hub, e.g. Wellbee Circles) without a
			// per-row lookup — the row already carries the category.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.name, s.slug, s.category_id, sm.role
					 FROM {$wpdb->prefix}bn_spaces s
					 INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id
					 WHERE sm.user_id = %d AND sm.status = 'active'
					 ORDER BY sm.joined_at DESC
					 LIMIT %d",
					$user_id,
					$fetch
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$rows = is_array( $rows ) ? $rows : array();

			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		/**
		 * Filter the member's "My spaces" summary rows before they render — the rail
		 * flyout (templates/shell/rail.php), the profile "Member of" list, and the
		 * profile sidebar all read this method, so filtering here covers every
		 * surface at once. An addon hub drops its own category-flagged spaces so they
		 * do not leak into the native Spaces UI; each row carries category_id, so the
		 * exclusion needs no extra query. Applied on both the cached and freshly
		 * queried path (card 10276700689).
		 *
		 * @since 1.2.0
		 *
		 * @param array<int,object> $rows    Space rows (id, name, slug, category_id, role).
		 * @param int               $user_id The member whose spaces these are.
		 * @param int               $limit   Row cap the caller requested.
		 */
		$rows = apply_filters( 'buddynext_membership_rows', $rows, $user_id, $limit );

		// Slice to the requested cap AFTER the addon exclusion, so a member always
		// sees up to $limit VISIBLE spaces, not $limit-minus-hidden.
		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Count the spaces a user actively belongs to.
	 *
	 * The rail "My spaces" flyout shows a capped preview of membership_rows(); this
	 * gives it the TRUE total so the count badge is accurate and a "See all" link can
	 * appear once the member belongs to more spaces than the preview shows. A single
	 * COUNT(*) on the (user_id, status) index — big-site safe at thousands of spaces.
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function count_memberships( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		$cache_key = 'membership_count_v' . self::membership_summary_version( $user_id ) . "_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$count = (int) $cached;
		} else {
			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE user_id = %d AND status = 'active'",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );
		}

		/**
		 * Filter the member's active-membership count — the "My spaces" badge — so it
		 * stays consistent with buddynext_membership_rows(): an addon hub that hides
		 * its category-flagged spaces from the list nets the same spaces out of the
		 * count here, or the badge reads a total the list does not show (card
		 * 10276700689).
		 *
		 * @since 1.2.0
		 *
		 * @param int $count   Active membership count.
		 * @param int $user_id The member.
		 */
		return (int) apply_filters( 'buddynext_membership_count', $count, $user_id );
	}

	/**
	 * Count active members of a space (respecting the same block filter as
	 * get_members()), without loading the rows. Powers paginated totals.
	 *
	 * Accepts the same optional $args refinements as get_members() so a filtered
	 * roster (search / role / exclude_suspended) reports a matching total — the
	 * members template paginates on this count, so any divergence from the listed
	 * rows skews the page count. When a name search is active the wp_users JOIN is
	 * added so the search columns resolve.
	 *
	 * @param int                  $space_id  Space ID.
	 * @param int                  $viewer_id Viewing user ID; when non-zero, blocked users are excluded.
	 * @param array<string, mixed> $args      Optional search / role / exclude_suspended refinements.
	 * @return int
	 */
	public function count_members( int $space_id, int $viewer_id = 0, array $args = array() ): int {
		global $wpdb;

		$block_where = $this->member_block_where( $viewer_id );

		// Optional role filter (validated against the allow-list) — mirrors get_members().
		$role_where = '';
		$role       = isset( $args['role'] ) ? (string) $args['role'] : '';
		if ( in_array( $role, self::ALLOWED_ROLES, true ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$role_where = $wpdb->prepare( ' AND sm.role = %s', $role );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Optional name search across the three identity columns — needs the JOIN.
		$search_where = '';
		$search_join  = '';
		$search       = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';
		if ( '' !== $search ) {
			$search_join = " INNER JOIN {$wpdb->users} u ON u.ID = sm.user_id";
			$like        = '%' . $wpdb->esc_like( $search ) . '%';
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$search_where = $wpdb->prepare(
				' AND ( u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_nicename LIKE %s )',
				$like,
				$like,
				$like
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Optional suspension / shadow-ban exclusion via the canonical builder.
		$moderation_where = '';
		if ( ! empty( $args['exclude_suspended'] ) ) {
			$moderation_where = ' ' . buddynext_service( 'moderation' )->moderation_exclude_sql( 'sm.user_id' );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_space_members sm{$search_join}
				 WHERE sm.space_id = %d AND sm.status = 'active'
				   {$block_where}{$role_where}{$search_where}{$moderation_where}",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Return pending join requests for a space.
	 *
	 * Pagination is opt-in (see get_members()): $limit = 0 keeps the original
	 * unbounded behaviour; pass $limit > 0 for LIMIT/OFFSET. Use
	 * count_pending_requests() for the matching total.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max rows to return; 0 = no limit.
	 * @param int $offset   Row offset (applied only when $limit > 0).
	 * @return array[] Each item: user_id, requested_at (request date).
	 */
	public function get_pending_requests( int $space_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		$limit_sql = '';
		if ( $limit > 0 ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$limit_sql = $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, max( 0, $offset ) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, joined_at
				 FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'pending'
				 ORDER BY joined_at ASC{$limit_sql}",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			fn( $r ) => array(
				'user_id'      => (int) $r['user_id'],
				'requested_at' => $r['joined_at'],
			),
			(array) $rows
		);
	}

	/**
	 * Count pending join requests for a space without loading the rows.
	 *
	 * @param int $space_id Space ID.
	 * @return int
	 */
	public function count_pending_requests( int $space_id ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'pending'",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Increment or decrement the member_count on the space row.
	 *
	 * The canonical member_count mutator — the only place that writes
	 * bn_spaces.member_count. Public so other services (e.g. SpaceService's
	 * assign_owner(), which upserts bn_space_members directly and therefore
	 * bypasses join()/leave()) can keep the denormalised count correct
	 * instead of duplicating this SQL.
	 *
	 * Uses GREATEST(1, member_count) - 1 to floor at zero WITHOUT underflowing
	 * the UNSIGNED column (member_count - 1 on a 0 value would wrap to ~1.8e19
	 * before GREATEST sees it).
	 *
	 * @param int $space_id Space ID.
	 * @param int $delta    +1 to increment, -1 to decrement.
	 * @return void
	 */
	public function adjust_member_count( int $space_id, int $delta ): void {
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

		// A join or leave changes the member_count, and the directory's DEFAULT sort is
		// by member_count - so a join reorders the grid for everyone, not just for the
		// member who joined. Routed through the owning service rather than deleting its
		// keys with a literal group from over here.
		SpaceService::flush_space_lists();
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
		// Roster list: bump the per-space version salt (not a single fixed-key
		// delete) — get_members() is parameterised by viewer/role/limit/offset, so
		// it lives under many keys; one bump retires them all.
		self::bump_cache_version( "members_ver_{$space_id}" );

		// The member's own "My spaces" rail. It is rebuilt from these same rows, so it
		// goes stale on exactly the events this method already handles — joining, leaving,
		// being removed, a role change. Busting it here rather than at the call sites
		// means a new membership path cannot forget it: everything that changes a
		// membership already comes through this choke point.
		self::invalidate_membership_summary( $user_id );
	}

	/**
	 * Version of one member's cached "My spaces" summary.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	private static function membership_summary_version( int $user_id ): int {
		return self::cache_version( "membership_ver_{$user_id}" );
	}

	/**
	 * Read a version salt for a parameterised cache (seeding it to 1 when absent).
	 *
	 * The shared primitive behind every versioned cache in this service — the
	 * per-user "My spaces" summary and the per-space members roster both embed a
	 * salt from here in their keys, so a single bump retires every variant at once
	 * (the object-cache-safe substitute for a wildcard delete).
	 *
	 * @param string $key Salt cache key (e.g. "membership_ver_{uid}", "members_ver_{space}").
	 * @return int
	 */
	private static function cache_version( string $key ): int {
		$version = wp_cache_get( $key, self::CACHE_GROUP );

		if ( false === $version ) {
			$version = 1;
			wp_cache_set( $key, $version, self::CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Bump a version salt, retiring every cache entry keyed on it. Call AFTER the
	 * DB write it invalidates, never before.
	 *
	 * @param string $key Salt cache key.
	 * @return void
	 */
	private static function bump_cache_version( string $key ): void {
		wp_cache_set( $key, self::cache_version( $key ) + 1, self::CACHE_GROUP );
	}

	/**
	 * Drop the cached "My spaces" rail summary for one member.
	 *
	 * A version bump rather than keyed deletes, because membership_rows() takes an
	 * arbitrary limit (1-50) and is therefore cached under an arbitrary number of keys.
	 * Deleting a fixed list of them would quietly miss whichever limit a caller picked
	 * next, and the rail would keep showing a space the member had left.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	private static function invalidate_membership_summary( int $user_id ): void {
		self::bump_cache_version( "membership_ver_{$user_id}" );
	}

	/**
	 * Flush the membership / ban cache for a set of users in a space.
	 *
	 * Used when a space is deleted: the bulk row deletes do not fire per-user
	 * hooks, so the cached role / status entries (and anything keyed off the ban
	 * rows) would otherwise survive the space. Public so SpaceService can call it
	 * with the affected user set captured before the cascade.
	 *
	 * @param int        $space_id Space ID.
	 * @param array<int> $user_ids Affected user IDs.
	 * @return void
	 */
	public function flush_user_caches( int $space_id, array $user_ids ): void {
		foreach ( $user_ids as $user_id ) {
			$this->invalidate_cache( $space_id, (int) $user_id );
		}
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

		// No row removed (no active ban, or a DB error): report failure rather than
		// busting cache and firing the unbanned hook for a no-op.
		if ( empty( $deleted ) ) {
			return false;
		}

		// Clear the SOFT ban too, or the unban does not actually unban.
		//
		// ban() writes to BOTH tables — it inserts the bn_space_bans row AND flips
		// the member row to status='banned'. Unban only ever deleted the ban row, so
		// the member row was left banned: is_space_banned() kept hard-denying every
		// space capability and join() kept refusing them. The member stayed locked
		// out of a space they had supposedly been unbanned from, with no way back and
		// nothing in the UI to explain it.
		//
		// Delete the row rather than reviving it to 'active': being unbanned restores
		// the right to ASK, not a membership they no longer hold. They can now join
		// (or request to join) exactly like anyone else — which is what decline_request()
		// does with the row it clears.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
				'status'   => 'banned',
			),
			array( '%d', '%d', '%s' )
		);

		$this->invalidate_cache( $space_id, $user_id );

		/**
		 * Fires after a space ban is lifted.
		 *
		 * @param int $space_id Space ID.
		 * @param int $user_id  Unbanned user.
		 */
		do_action( 'buddynext_space_user_unbanned', $space_id, $user_id );

		return true;
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
		// Join wp_users so the ban list is renderable on its own — a banned user is no
		// longer a member, so the members projection can't supply their name. Mirrors the
		// members list's shape (display_name + get_avatar_url) so the app reuses one row type.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.space_id, b.user_id, b.banned_by, b.reason, b.created_at, u.display_name, u.user_nicename
				 FROM {$wpdb->prefix}bn_space_bans b
				 LEFT JOIN {$wpdb->users} u ON u.ID = b.user_id
				 WHERE b.space_id = %d
				 ORDER BY b.created_at ASC, b.user_id ASC
				 LIMIT %d",
				$space_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			static function ( array $r ): array {
				$uid = (int) $r['user_id'];
				return array(
					'space_id'      => (int) ( $r['space_id'] ?? 0 ),
					'user_id'       => $uid,
					'display_name'  => (string) ( $r['display_name'] ?? '' ),
					'user_nicename' => (string) ( $r['user_nicename'] ?? '' ),
					'avatar_url'    => get_avatar_url( $uid, array( 'size' => 96 ) ),
					'reason'        => (string) ( $r['reason'] ?? '' ),
					'created_at'    => (string) ( $r['created_at'] ?? '' ),
					'banned_by'     => (int) ( $r['banned_by'] ?? 0 ),
				);
			},
			$rows
		);
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
	 * Would this user be allowed to join, without attempting it?
	 *
	 * Read-only evaluation of the same `buddynext_can_join_space` gate that
	 * join() and request_join() run, so a surface can ask the question before
	 * offering the button.
	 *
	 * This exists because the space templates offered "Join space" to everyone
	 * looking at an open space, including members a listener was certain to
	 * refuse. On a plan-gated space that produced a screen telling the member
	 * they needed a paid plan while two buttons beside it invited them to join
	 * anyway. Asking the gate is the only way to be sure the offer is real —
	 * Free cannot know what Pro will decide.
	 *
	 * @param array|object $space   Space row (array from bn_spaces, or the object
	 *                              the templates carry).
	 * @param int          $user_id User being considered, 0 for logged out.
	 * @return bool
	 */
	public function can_join( array|object $space, int $user_id ): bool {
		$space_row = is_object( $space ) ? (array) $space : $space;

		// A suspended member reads spaces but cannot join one (POST /spaces/{id}/join
		// 403s at RestHoldGate), so the Join / Request CTA must hide rather than 403
		// on click. Routed through the same buddynext_can() seam every other write
		// hides on; the space_id context also lets a space-banned member be refused
		// here. Guests (user_id 0) are unaffected — they get the "Log in to join" CTA.
		if ( $user_id > 0 && function_exists( 'buddynext_can' )
			&& ! buddynext_can( $user_id, 'buddynext-spaces/join', array( 'space_id' => (int) ( $space_row['id'] ?? 0 ) ) ) ) {
			return false;
		}

		return (bool) apply_filters( 'buddynext_can_join_space', true, $space_row, $user_id, 'join' );
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
