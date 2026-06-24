<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for SpaceMemberService::flush_user_caches (change-index BUG-1).
 *
 * Deleting a space bulk-removes bn_space_members + bn_space_bans without firing
 * per-user hooks, so cached role / status entries would survive the space. The
 * flush busts them; SpaceService::delete() calls it with the affected user set.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Spaces\SpaceMemberService;
use WP_UnitTestCase;

/**
 * Membership/ban cache-flush behaviour.
 */
class SpaceMemberFlushTest extends WP_UnitTestCase {

	private const GROUP = 'buddynext_space_members';

	/**
	 * Flushing busts the role / status / members keys for each user.
	 *
	 * @return void
	 */
	public function test_flush_user_caches_busts_keys(): void {
		wp_cache_set( 'role_5_7', 'owner', self::GROUP );
		wp_cache_set( 'status_5_7', 'active', self::GROUP );
		wp_cache_set( 'role_5_9', 'member', self::GROUP );
		wp_cache_set( 'status_5_9', 'banned', self::GROUP );
		wp_cache_set( 'members_5', array( 7, 9 ), self::GROUP );

		( new SpaceMemberService() )->flush_user_caches( 5, array( 7, 9 ) );

		$this->assertFalse( wp_cache_get( 'role_5_7', self::GROUP ) );
		$this->assertFalse( wp_cache_get( 'status_5_7', self::GROUP ) );
		$this->assertFalse( wp_cache_get( 'role_5_9', self::GROUP ) );
		$this->assertFalse( wp_cache_get( 'status_5_9', self::GROUP ) );
		$this->assertFalse( wp_cache_get( 'members_5', self::GROUP ) );
	}

	/**
	 * An empty user set is a harmless no-op.
	 *
	 * @return void
	 */
	public function test_flush_empty_set_is_noop(): void {
		wp_cache_set( 'role_5_7', 'owner', self::GROUP );
		( new SpaceMemberService() )->flush_user_caches( 5, array() );
		$this->assertSame( 'owner', wp_cache_get( 'role_5_7', self::GROUP ) );
	}
}
