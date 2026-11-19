<?php
/**
 * Jetonomy membership model stubs shared by the bridge tests.
 *
 * Jetonomy is not loaded in BuddyNext tests. Each class can be declared once per
 * process, so every test that needs SpaceMember or JoinRequest requires this one
 * file instead of declaring its own (the first declaration would win for all).
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace Jetonomy\Models;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

if ( ! class_exists( SpaceMember::class ) ) {
	/**
	 * In-memory forum roster that records whether each add ran as a mirror.
	 */
	class SpaceMember {
		/**
		 * Mirror flag of each add(), in order.
		 *
		 * @var bool[]
		 */
		public static array $mirrored = array();

		/**
		 * Roles by forum id and user id.
		 *
		 * @var array<int,array<int,string>>
		 */
		public static array $roster = array();

		/**
		 * Empty the roster and the record.
		 */
		public static function reset(): void {
			self::$mirrored = array();
			self::$roster   = array();
		}

		public static function is_member( int $forum_id, int $user_id ): bool {
			return isset( self::$roster[ $forum_id ][ $user_id ] );
		}

		public static function add( int $forum_id, int $user_id, string $role = 'member' ): bool {
			self::$mirrored[]                        = \BuddyNext\Feed\IntegrationActivity::is_mirror();
			self::$roster[ $forum_id ][ $user_id ] = $role;
			return true;
		}

		public static function set_role( int $forum_id, int $user_id, string $role ): bool {
			self::$roster[ $forum_id ][ $user_id ] = $role;
			return true;
		}

		public static function remove( int $forum_id, int $user_id ): bool {
			unset( self::$roster[ $forum_id ][ $user_id ] );
			return true;
		}
	}
}

if ( ! class_exists( JoinRequest::class ) ) {
	/**
	 * Join request model over the shadow jt_join_requests table a test creates.
	 */
	class JoinRequest {
		/**
		 * Delete a still-pending request, as the model does, and fire its hook.
		 *
		 * @param int $space_id Forum id.
		 * @param int $user_id  Requester.
		 * @return bool
		 */
		public static function withdraw( int $space_id, int $user_id ): bool {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}jt_join_requests WHERE space_id = %d AND user_id = %d AND status = 'pending'", $space_id, $user_id ) );
			if ( 1 > $removed ) {
				return false;
			}
			do_action( 'jetonomy_join_request_withdrawn', $space_id, $user_id );
			return true;
		}
	}
}
