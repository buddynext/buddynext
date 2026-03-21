<?php
/**
 * Space membership service.
 *
 * Manages join, leave, and role changes for space members. Member counts are
 * kept in sync with the denormalized bn_spaces.member_count column. The owner
 * cannot leave a space — they must delete it or transfer ownership first.
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
	 * Join a space.
	 *
	 * A duplicate join is silently ignored (INSERT IGNORE). Does not re-increment
	 * member_count on a duplicate — only fires on a new insert.
	 *
	 * @param int $space_id Space to join.
	 * @param int $user_id  User joining.
	 * @return true
	 */
	public function join( int $space_id, int $user_id ): true {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_space_members (space_id, user_id, role)
				 VALUES (%d, %d, 'member')",
				$space_id,
				$user_id
			)
		);

		if ( $wpdb->rows_affected > 0 ) {
			$this->adjust_member_count( $space_id, 1 );
			$this->invalidate_cache( $space_id, $user_id );

			/**
			 * Fires after a user joins a space.
			 *
			 * @param int $user_id  Joining user.
			 * @param int $space_id Space joined.
			 */
			do_action( 'buddynext_member_joined_space', $user_id, $space_id );
		}

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
			$this->adjust_member_count( $space_id, -1 );
			$this->invalidate_cache( $space_id, $user_id );

			/**
			 * Fires after a user leaves a space.
			 *
			 * @param int $user_id  User who left.
			 * @param int $space_id Space left.
			 */
			do_action( 'buddynext_member_left_space', $user_id, $space_id );
		}

		return true;
	}

	/**
	 * Check whether a user is a member of a space.
	 *
	 * @param int $space_id Space to check.
	 * @param int $user_id  User to check.
	 * @return bool
	 */
	public function is_member( int $space_id, int $user_id ): bool {
		return null !== $this->get_role( $space_id, $user_id );
	}

	/**
	 * Return the user's role in a space, or null if not a member.
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
				"SELECT role FROM {$wpdb->prefix}bn_space_members WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		// Cache empty string for "not a member" to distinguish from cache miss.
		wp_cache_set( $cache_key, $role ?? '', self::CACHE_GROUP, self::CACHE_TTL );

		return $role;
	}

	/**
	 * Change a member's role within a space.
	 *
	 * Only the owner or a user with manage_options can promote/demote members.
	 *
	 * @param int    $space_id    Space ID.
	 * @param int    $target_id   User whose role is being changed.
	 * @param string $new_role    New role: 'owner', 'moderator', or 'member'.
	 * @param int    $actor_id    User performing the change.
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
			),
			array( '%s' ),
			array( '%d', '%d' )
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
	 * Return all members of a space with their roles.
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
				 WHERE space_id = %d
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
	}

	/**
	 * Invalidate role and member-list cache keys for a space/user pair.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  User ID.
	 */
	private function invalidate_cache( int $space_id, int $user_id ): void {
		wp_cache_delete( "role_{$space_id}_{$user_id}", self::CACHE_GROUP );
		wp_cache_delete( "members_{$space_id}", self::CACHE_GROUP );
	}
}
