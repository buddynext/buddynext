<?php
/**
 * Tests for the shared handle-repair service.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\Handle;
use BuddyNext\Profile\HandleRepair;

/**
 * The one implementation the WP-CLI command and the admin button both call.
 *
 * @covers \BuddyNext\Profile\HandleRepair
 */
class HandleRepairTest extends \WP_UnitTestCase {

	/**
	 * Give a member an email-shaped nicename, the way an import does.
	 *
	 * factory()->user->create() runs the value through sanitize_user, which keeps
	 * the '@', but core still derives a safe nicename — so the broken value has to
	 * be written straight to the column, exactly as the real fault arrives.
	 *
	 * @param string $nicename Raw nicename to force.
	 * @return int User id.
	 */
	private function seed_broken( string $nicename ): int {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$wpdb->update( $wpdb->users, array( 'user_nicename' => $nicename ), array( 'ID' => $user_id ) );
		clean_user_cache( $user_id );

		return $user_id;
	}

	/**
	 * find_unsafe() returns only the members whose handle cannot be mentioned.
	 *
	 * @return void
	 */
	public function test_find_unsafe_returns_only_broken_handles(): void {
		$broken = $this->seed_broken( 'name@somewhere-com' );
		$good   = self::factory()->user->create( array( 'user_login' => 'cleanuser' ) );

		$ids = array_map(
			static fn( array $row ): int => (int) $row['ID'],
			( new HandleRepair() )->find_unsafe()
		);

		$this->assertContains( $broken, $ids );
		$this->assertNotContains( $good, $ids );
	}

	/**
	 * The count is what the admin warning shows, and it caches.
	 *
	 * @return void
	 */
	public function test_count_unsafe_caches_and_recounts_on_fresh(): void {
		$repair = new HandleRepair();
		delete_transient( HandleRepair::COUNT_CACHE );

		$this->seed_broken( 'a@b-com' );
		$this->assertSame( 1, $repair->count_unsafe( true ), 'A fresh count sees the new broken handle.' );

		// A second broken handle is invisible to the cached count until refreshed.
		$this->seed_broken( 'c@d-com' );
		$this->assertSame( 1, $repair->count_unsafe(), 'The cached value stands until something clears it.' );
		$this->assertSame( 2, $repair->count_unsafe( true ), 'A fresh count sees both.' );
	}

	/**
	 * repair_all() normalises to WordPress's own nicename rules and reports each change.
	 *
	 * @return void
	 */
	public function test_repair_all_normalises_and_resolves(): void {
		$user_id = $this->seed_broken( 'jane@doe-com' );

		$result = ( new HandleRepair() )->repair_all();

		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( 0, $result['skipped'] );
		$this->assertSame( 'jane@doe-com', $result['changes'][0]['from'] );
		$this->assertSame( 'janedoe-com', $result['changes'][0]['to'] );

		// The whole point: the member is mentionable afterwards.
		$this->assertSame( $user_id, Handle::resolve( 'janedoe-com' )->ID );
		$this->assertTrue( Handle::is_safe( get_userdata( $user_id )->user_nicename ) );
	}

	/**
	 * A dry run reports the change but writes nothing.
	 *
	 * @return void
	 */
	public function test_dry_run_writes_nothing(): void {
		$user_id = $this->seed_broken( 'x@y-com' );

		$result = ( new HandleRepair() )->repair_all( true );

		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( 'x@y-com', get_userdata( $user_id )->user_nicename, 'Dry run must not touch the row.' );
	}

	/**
	 * An all-foreign handle is skipped, never written empty.
	 *
	 * Writing an empty nicename would break the member's profile URL outright,
	 * which is worse than the fault being repaired.
	 *
	 * @return void
	 */
	public function test_unrepairable_handle_is_skipped_not_blanked(): void {
		$user_id = $this->seed_broken( 'name@somewhere-com' );
		$hopeless = $this->seed_broken( '@@@' );

		$result = ( new HandleRepair() )->repair_all();

		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( 1, $result['skipped'] );
		$this->assertSame( $hopeless, $result['skips'][0]['id'] );

		// The hopeless one keeps its (broken) nicename rather than an empty string.
		$this->assertNotSame( '', get_userdata( $hopeless )->user_nicename );
		// The repairable one was still fixed in the same pass.
		$this->assertTrue( Handle::is_safe( get_userdata( $user_id )->user_nicename ) );
	}

	/**
	 * Two handles that normalise onto the same string do not collide.
	 *
	 * `a.b@corp.com` and `a-b@corp.com` both reduce to `abcorp-com`; the second
	 * must be suffixed rather than silently rejected or clobbering the first.
	 *
	 * @return void
	 */
	public function test_colliding_normalised_handles_are_made_unique(): void {
		$first  = $this->seed_broken( 'a.b@corp-com' );
		$second = $this->seed_broken( 'a-b@corp-com' );

		( new HandleRepair() )->repair_all();

		$h1 = get_userdata( $first )->user_nicename;
		$h2 = get_userdata( $second )->user_nicename;

		$this->assertNotSame( $h1, $h2, 'Collided handles must end up distinct.' );
		$this->assertTrue( Handle::is_safe( $h1 ) );
		$this->assertTrue( Handle::is_safe( $h2 ) );
		$this->assertSame( $first, Handle::resolve( $h1 )->ID );
		$this->assertSame( $second, Handle::resolve( $h2 )->ID );
	}

	/**
	 * Repairing clears the cached count, so the warning does not linger.
	 *
	 * @return void
	 */
	public function test_repair_clears_the_count_cache(): void {
		$this->seed_broken( 'stale@cache-com' );
		$repair = new HandleRepair();

		$repair->count_unsafe( true ); // prime the cache at 1
		$repair->repair_all();

		$this->assertFalse( get_transient( HandleRepair::COUNT_CACHE ), 'Repair must invalidate the count.' );
		$this->assertSame( 0, $repair->count_unsafe( true ) );
	}
}
