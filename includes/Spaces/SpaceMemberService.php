<?php
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
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status)
					 VALUES (%d, %d, 'member', 'active')",
					$space_id,
					$user_id
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
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status)
				 VALUES (%d, %d, 'member', 'pending')",
				$space_id,
				$user_id
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
	 * Invite a user to a secret (or any) space (status='invited').
	 *
	 * Only the owner or a moderator may invite. If the user is already an
	 * active member the call is a no-op.
	 *
	 * @param int $space_id         Space ID.
	 * @param int $inviter_id       User sending the invitation.
	 * @param int $invited_user_id  User being invited.
	 * @return true|WP_Error
	 */
	public function invite( int $space_id, int $inviter_id, int $invited_user_id ): true|WP_Error {
		$inviter_role = $this->get_role( $space_id, $inviter_id );

		if (
			! in_array( $inviter_role, array( 'owner', 'moderator' ), true )
			&& ! user_can( $inviter_id, 'manage_options' )
		) {
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
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status)
					 VALUES (%d, %d, 'member', 'invited')",
					$space_id,
					$invited_user_id
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
		 * @param int $space_id  Space ID.
		 * @param int $user_id   Newly approved member.
		 * @param int $actor_id  User who approved.
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
					"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role, status)
					 VALUES (%d, %d, 'member', 'banned')",
					$space_id,
					$user_id
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
		 * Fires after a user is removed from a space by a moderator.
		 *
		 * @param int $space_id  Space ID.
		 * @param int $user_id   Removed user.
		 * @param int $actor_id  User who performed the removal.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		$this->invalidate_cache( $space_id, $target_id );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT member_count FROM {$wpdb->prefix}bn_spaces WHERE id = %d",
				$space_id
			)
		);
	}

	/**
	 * Return all active members of a space with their roles.
	 *
	 * @param int $space_id Space ID.
	 * @return array[] Each item: user_id, role, joined_at.
	 */
	public function get_members( int $space_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, role, joined_at
				 FROM {$wpdb->prefix}bn_space_members
				 WHERE space_id = %d AND status = 'active'
				 ORDER BY joined_at ASC",
				$space_id
			),
			ARRAY_A
		);

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Uses GREATEST(0, ...) to prevent negative counts on unexpected decrements.
	 *
	 * @param int $space_id Space ID.
	 * @param int $delta    +1 to increment, -1 to decrement.
	 */
	private function adjust_member_count( int $space_id, int $delta ): void {
		global $wpdb;

		if ( $delta > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_spaces SET member_count = member_count + 1 WHERE id = %d",
					$space_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_spaces SET member_count = GREATEST(0, member_count - 1) WHERE id = %d",
					$space_id
				)
			);
		}

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

		// Remove the permanent ban record.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_bans',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			),
			array( '%d', '%d' )
		);

		// Remove the banned membership row so the user may rejoin.
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

	/**
	 * Check the permanent ban table for a space+user combination.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User to check.
	 * @return bool
	 */
	private function is_hard_banned( int $space_id, int $user_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_bans
				 WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		) > 0;
	}
}
