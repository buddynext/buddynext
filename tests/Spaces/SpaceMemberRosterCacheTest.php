<?php
/**
 * Tests the per-space roster cache in SpaceMemberService::get_members().
 *
 * The get_members() read is versioned-cached under a per-space salt; any membership write
 * (join / leave / role change) bumps that salt through invalidate_cache(), so a
 * new member appears on the next read instead of after the TTL.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * Roster cache read + version-salt invalidation behaviour.
 *
 * @covers \BuddyNext\Spaces\SpaceMemberService::get_members
 */
class SpaceMemberRosterCacheTest extends \WP_UnitTestCase {

	/**
	 * Member service under test.
	 *
	 * @var SpaceMemberService
	 */
	private SpaceMemberService $service;

	/**
	 * A seeded open space id.
	 *
	 * @var int
	 */
	private int $space_id;

	/**
	 * Install the schema and seed an open space.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service  = new SpaceMemberService();
		$owner          = self::factory()->user->create();
		$this->space_id = ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Roster Cache Space',
				'slug' => 'roster-cache-space',
				'type' => 'open',
			)
		);
	}

	/**
	 * A repeat read is served from cache, and a join busts it so the newest
	 * member shows at once (not after the TTL).
	 *
	 * @return void
	 */
	public function test_get_members_is_cached_and_a_join_busts_it(): void {
		$a = self::factory()->user->create();
		$this->service->join( $this->space_id, $a );

		$first = $this->service->get_members( $this->space_id );
		$this->assertCount( 2, $first, 'owner + one joined member' );

		// A second read with no membership change is served from the versioned cache.
		$this->assertSame( $first, $this->service->get_members( $this->space_id ) );

		// A new join must bump the roster version salt so the next read is fresh — a
		// member joining and not appearing until the TTL would read as broken.
		$b = self::factory()->user->create();
		$this->service->join( $this->space_id, $b );

		$after = $this->service->get_members( $this->space_id );
		$this->assertCount( 3, $after, 'a join must bust the cached roster, not serve the stale page' );
		$ids = array_map( static fn( $m ) => (int) $m['user_id'], $after );
		$this->assertContains( $b, $ids, 'the newly joined member appears immediately' );
	}

	/**
	 * A leave also busts the cached roster.
	 *
	 * @return void
	 */
	public function test_a_leave_busts_the_cached_roster(): void {
		$a = self::factory()->user->create();
		$this->service->join( $this->space_id, $a );

		$this->assertCount( 2, $this->service->get_members( $this->space_id ) ); // Warm the cache.

		$this->service->leave( $this->space_id, $a );

		$after = $this->service->get_members( $this->space_id );
		$ids   = array_map( static fn( $m ) => (int) $m['user_id'], $after );
		$this->assertNotContains( $a, $ids, 'a member who left must drop off the roster immediately' );
	}
}
