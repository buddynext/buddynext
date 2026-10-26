<?php
/**
 * Community Admin opens on "Review the moderation queue", not "Moderate spaces".
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PermissionService;

/**
 * @covers \BuddyNext\Core\PermissionService
 */
class CommunityAdminGateTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'bn_role_map_overrides' );
		( new \ReflectionProperty( PermissionService::class, 'role_map_cache' ) )->setValue( null, null );
		parent::tear_down();
	}

	public function test_a_stricter_moderate_spaces_row_keeps_moderators_in_the_panel(): void {
		update_option( 'bn_role_map_overrides', array( 'buddynext-spaces/moderate' => 'admin' ) );
		( new \ReflectionProperty( PermissionService::class, 'role_map_cache' ) )->setValue( null, null );

		$moderator = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $moderator, 'bn_community_role', 'moderator' );

		$this->assertTrue( buddynext_can( $moderator, 'buddynext-moderation/review-queue' ) );
		$this->assertFalse( buddynext_can( $moderator, 'buddynext-spaces/moderate' ) );
	}

	/**
	 * "Moderate spaces" governs moderation inside a space only, so every check of it
	 * must carry a space_id; the site-wide surfaces gate on review-queue.
	 */
	public function test_moderate_spaces_is_never_checked_without_a_space(): void {
		$root  = dirname( __DIR__, 2 );
		$found = array();
		foreach ( array( 'includes', 'templates' ) as $dir ) {
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir ) );
			foreach ( $files as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				foreach ( file( $file->getPathname() ) as $n => $line ) {
					if ( preg_match( "/buddynext_can\(.*'buddynext-spaces\/moderate'\s*\)/", $line ) ) {
						$found[] = substr( $file->getPathname(), strlen( $root ) + 1 ) . ':' . ( $n + 1 );
					}
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
