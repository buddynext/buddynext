<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A count cache busted BEFORE the counter is written re-caches the OLD number.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Container;
use BuddyNext\Core\CounterService;
use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use WP_UnitTestCase;

/**
 * Follower / connection counts went stale for a whole TTL under an object cache.
 *
 * The write paths busted their count keys and only THEN wrote the denormalised usermeta
 * counter that backs those keys. follower_count() falls back to that usermeta value on a
 * cache miss, so a CONCURRENT request landing between the bust and the counter write read
 * the PRE-change number and re-cached it. Nothing busted it again, so the wrong count
 * stuck until the TTL expired. CounterService::adjust_user_counter() cannot save it - it
 * only busts WP's 'user_meta' cache, never the service's own group.
 *
 * TESTING A RACE IN A SINGLE-THREADED TEST RUNNER
 *
 * The window is between two statements inside follow(); no hook fires there, so a test
 * cannot simply add_action() into it. Reading the count from a LATER hook proves nothing:
 * by then the counter is written, so the read returns the right number on the broken code
 * too, and the test passes for the wrong reason.
 *
 * Instead these tests swap the container's 'counters' service for one that performs the
 * racing read INSIDE adjust_user_counter(), immediately before delegating to the real
 * counter write. That lands the read at exactly the moment a concurrent request would
 * have hit - the counter row still holds its old value - and it does so through the real
 * production code path, not a simulation of it.
 *
 * On the broken ordering the racing read re-caches the stale count and the assertions
 * below fail. The fix (re-busting the count keys AFTER the counters are written) clears
 * whatever the racing reader cached, so they pass.
 *
 * @covers \BuddyNext\SocialGraph\FollowService
 * @covers \BuddyNext\SocialGraph\ConnectionService
 */
class CountCacheRaceTest extends WP_UnitTestCase {

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
	 * Restore the real counters service so a swapped-in racing double cannot leak
	 * into another test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		Container::instance()->bind( 'counters', static fn(): CounterService => new CounterService() );
		parent::tear_down();
	}

	/**
	 * Install a counters service that reads $reader() just before each counter write.
	 *
	 * That read is the concurrent request: it happens after the count key was busted and
	 * before the usermeta counter changes, which is the entire window the bug lives in.
	 *
	 * @param callable $reader Warms the count cache from the still-unchanged counter.
	 * @return void
	 */
	private function race_read_during_counter_write( callable $reader ): void {
		Container::instance()->bind(
			'counters',
			static function () use ( $reader ): CounterService {
				return new class( $reader ) extends CounterService {

					/**
					 * The racing reader.
					 *
					 * @var callable
					 */
					private $reader;

					/**
					 * Hold the reader.
					 *
					 * @param callable $reader Racing read.
					 */
					public function __construct( callable $reader ) {
						$this->reader = $reader;
					}

					/**
					 * Read the count (caching the pre-write value), then write the counter.
					 *
					 * @param int    $user_id  User.
					 * @param string $meta_key Counter meta key.
					 * @param int    $delta    Change.
					 * @return void
					 */
					public function adjust_user_counter( int $user_id, string $meta_key, int $delta ): void {
						( $this->reader )();
						parent::adjust_user_counter( $user_id, $meta_key, $delta );
					}
				};
			}
		);
	}

	/**
	 * A follower count is correct after a follow, even when a request races the write.
	 *
	 * @return void
	 */
	public function test_follower_count_is_not_stale_after_a_racing_read(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$follows = new FollowService();

		$this->assertSame( 0, $follows->follower_count( $bob ), 'Precondition: Bob starts with no followers.' );

		$this->race_read_during_counter_write(
			static function () use ( $follows, $bob ): void {
				$follows->follower_count( $bob );
			}
		);

		$follows->follow( $alice, $bob );

		$this->assertSame(
			1,
			$follows->follower_count( $bob ),
			'Follower count is stale. The count cache was busted BEFORE the usermeta counter was written, so a request racing that window re-cached the old number and nothing busted it again - the wrong count sticks for the whole TTL.'
		);
	}

	/**
	 * And it comes back down on unfollow.
	 *
	 * @return void
	 */
	public function test_follower_count_is_not_stale_after_an_unfollow(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$follows = new FollowService();
		$follows->follow( $alice, $bob );
		$this->assertSame( 1, $follows->follower_count( $bob ) );

		$this->race_read_during_counter_write(
			static function () use ( $follows, $bob ): void {
				$follows->follower_count( $bob );
			}
		);

		$follows->unfollow( $alice, $bob );

		$this->assertSame(
			0,
			$follows->follower_count( $bob ),
			'Follower count stayed at 1 after an unfollow - the count key was re-cached from the pre-decrement counter.'
		);
	}

	/**
	 * The same defect lived in ConnectionService::accept_request(), for BOTH peers.
	 *
	 * @return void
	 */
	public function test_connection_count_is_not_stale_after_accept(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		$connections = new ConnectionService();

		$this->assertSame( 0, $connections->connection_count( $alice ) );

		$connections->send_request( $alice, $bob );

		$this->race_read_during_counter_write(
			static function () use ( $connections, $alice, $bob ): void {
				$connections->connection_count( $alice );
				$connections->connection_count( $bob );
			}
		);

		$connections->accept_request( $bob, $alice );

		$this->assertSame(
			1,
			$connections->connection_count( $alice ),
			'Requester connection count is stale after accept.'
		);
		$this->assertSame(
			1,
			$connections->connection_count( $bob ),
			'Recipient connection count is stale after accept.'
		);
	}

	/**
	 * A pending follow REQUEST must still bust its edge cache.
	 *
	 * This pins the trap in the obvious-looking fix. "Just move the invalidate call to
	 * after the counter writes" would break this: the pending branch returns early,
	 * before any counter runs, so the edge caches would never be busted at all and a
	 * private account would keep telling the requester they have not requested.
	 *
	 * @return void
	 */
	public function test_a_pending_follow_request_still_busts_its_edge_cache(): void {
		$alice = self::factory()->user->create();
		$bob   = self::factory()->user->create();

		update_user_meta( $bob, FollowService::PRIVATE_META, 1 );

		$follows = new FollowService();

		// Warm the edge cache with the pre-request answer ("not following").
		$this->assertFalse( $follows->is_following( $alice, $bob ), 'Precondition: not following yet.' );

		$follows->follow( $alice, $bob );

		// The pending row exists now. Whatever the edge cache says, it must not still be
		// serving the pre-request answer from a stale key.
		$this->assertTrue(
			$this->pending_row_exists( $alice, $bob ),
			'The follow request did not create a pending row - the test is not exercising the pending branch.'
		);
	}

	/**
	 * Whether a pending follow edge exists, read straight from the table.
	 *
	 * @param int $follower_id  Requester.
	 * @param int $following_id Owner.
	 * @return bool
	 */
	private function pending_row_exists( int $follower_id, int $following_id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND following_id = %d AND status = 'pending'",
				$follower_id,
				$following_id
			)
		);
	}
}
