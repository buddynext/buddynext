<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A5 — the directory total is exact and cached, and stops lying about size.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\MemberDirectoryService;
use WP_UnitTestCase;

/**
 * The SSR directory used to count members itself, uncached, on every page load.
 *
 * templates/directory/members.php ran a SECOND WP_User_Query purely to produce the "N
 * members in the community" number, on every directory load, bypassing the cached service.
 * That is A5.
 *
 * It also CAPPED that count at 1,000, so a 100k-member community announced "1,000 members
 * in the community" and its "By role > Members" row read 1,000 -- a flat, false figure.
 * Found on the 100k-member Redis box.
 *
 * WordPress itself does not do this. core's count_users() runs an unbounded COUNT(*), and
 * its own docblock says the default strategy "should handle around 10^7 users"; the Users
 * screen shows the real figure on a site of any size and paginates all of them. Measured
 * here, the exact filtered count over 100k members is ~70ms cold and this is cached for a
 * minute, so it amortises to nothing. directory_total() is exact now, like core's, and the
 * cap is gone from both this method and list_members() so the two surfaces agree.
 *
 * @covers \BuddyNext\Profile\MemberDirectoryService::directory_total
 */
class DirectoryTotalCacheTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
	}

	/**
	 * The total is the EXACT number of members, not a capped floor.
	 *
	 * @return void
	 */
	public function test_the_total_is_exact_not_capped(): void {
		$viewer = self::factory()->user->create();

		$before = ( new MemberDirectoryService() )->directory_total( $viewer, array() );

		self::factory()->user->create_many( 7 );
		wp_cache_flush(); // force a recount; the salt is not bumped by plain user creation.

		$after = ( new MemberDirectoryService() )->directory_total( $viewer, array() );

		$this->assertSame(
			$before + 7,
			$after,
			'The directory total did not move by exactly the seven members created. It is meant to be an exact count now, like core count_users(), not a capped or approximate one.'
		);
	}

	/**
	 * The exact count still excludes what the directory hides (a suspended member).
	 *
	 * The point of a filtered count is that it matches what the directory would SHOW.
	 * Making it exact must not make it count people the grid leaves out.
	 *
	 * @return void
	 */
	public function test_the_exact_count_still_excludes_suspended_members(): void {
		$viewer = self::factory()->user->create();
		$victim = self::factory()->user->create();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$service = new MemberDirectoryService();

		wp_cache_flush();
		$before = $service->directory_total( $viewer, array() );

		// suspend_user requires an actor with manage_options -- a plain member cannot
		// suspend anyone, which is correct. Use an admin actor.
		$result = buddynext_service( 'moderation' )->suspend_user( $victim, $admin, 'test' );
		$this->assertNotWPError( $result, 'The suspension fixture failed to apply.' );

		wp_cache_flush();
		$after = $service->directory_total( $viewer, array() );

		$this->assertSame(
			$before - 1,
			$after,
			'Suspending a member did not drop the directory total by one. The exact count must still exclude the members the directory hides, or the number will not match the grid.'
		);
	}

	/**
	 * The total is served from cache on the second call, not recomputed.
	 *
	 * @return void
	 */
	public function test_the_total_is_cached_across_calls(): void {
		global $wpdb;

		$viewer = self::factory()->user->create();
		self::factory()->user->create_many( 3 );

		$service = new MemberDirectoryService();

		$service->directory_total( $viewer, array() ); // warm

		$before = $wpdb->num_queries;
		$service->directory_total( $viewer, array() );

		$this->assertSame(
			0,
			$wpdb->num_queries - $before,
			'The directory total hit the database on a cached read. It runs on every directory page load, so the second read must come from cache.'
		);
	}
}
