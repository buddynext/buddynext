<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Cache + invalidation tests for PollService::results (change-index item C2).
 *
 * Poll results are read by many viewers of a popular post; they are object-cached
 * per post_id and busted on every vote so the tallies stay correct.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PollService;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Poll-results caching behaviour.
 */
class PollServiceCacheTest extends WP_UnitTestCase {

	/**
	 * Poll service under test.
	 *
	 * @var PollService
	 */
	private PollService $service;

	/**
	 * Poll post ID.
	 *
	 * @var int
	 */
	private int $poll_id;

	/**
	 * First option ID.
	 *
	 * @var int
	 */
	private int $option_a;

	/**
	 * A voting user.
	 *
	 * @var int
	 */
	private int $voter;

	/**
	 * Seed a two-option poll.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$posts         = new PostService();
		$this->service = new PollService();
		$author        = self::factory()->user->create();
		$this->voter   = self::factory()->user->create();

		$this->poll_id  = $posts->create(
			$author,
			array(
				'type'    => 'poll',
				'content' => 'Which is best?',
				'options' => array( 'Option A', 'Option B' ),
			)
		);
		$post           = $posts->get( $this->poll_id );
		$this->option_a = (int) $post['poll_options'][0]['id'];
	}

	/**
	 * Vote count for Option A in a results payload.
	 *
	 * @param array<int, array<string, mixed>> $results Poll results.
	 * @return int
	 */
	private function count_a( array $results ): int {
		foreach ( $results as $row ) {
			if ( 'Option A' === $row['option_text'] ) {
				return (int) $row['vote_count'];
			}
		}
		return -1;
	}

	/**
	 * Cached results are busted by a vote so the tally updates.
	 *
	 * @return void
	 */
	public function test_results_cached_and_busted_on_vote(): void {
		$before = $this->count_a( $this->service->results( $this->poll_id ) ); // Primes the cache.
		$this->service->vote( $this->voter, $this->poll_id, $this->option_a );
		$after = $this->count_a( $this->service->results( $this->poll_id ) );

		$this->assertSame( $before + 1, $after, 'A vote must bust the cache so results reflect it.' );
	}

	/**
	 * recount_all_poll_votes() evicts a purged voter's per-viewer vote cache.
	 *
	 * Class-preventing guard for card 10264292330: the recount loop only busted
	 * results_{pid}, so a voter whose cross-linked vote the recount PURGES kept
	 * seeing "you voted" (uservote_{uid}_{pid}) until the TTL expired. Poison a
	 * real vote into a cross-linked orphan, prime the per-viewer cache from it, run
	 * the recount, and the viewer must read "no vote" — not the stale cached one.
	 *
	 * @return void
	 */
	public function test_recount_clears_purged_voter_uservote_cache(): void {
		$this->service->vote( $this->voter, $this->poll_id, $this->option_a );

		global $wpdb;
		$foreign = 999999; // An option id no bn_poll_options row owns → cross-linked.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_poll_votes SET option_id = %d WHERE post_id = %d AND user_id = %d",
				$foreign,
				$this->poll_id,
				$this->voter
			)
		);

		// Prime the per-viewer "you voted" cache from the poisoned row.
		$this->assertSame( $foreign, $this->service->user_vote( $this->voter, $this->poll_id ) );

		$this->service->recount_all_poll_votes();

		$this->assertNull(
			$this->service->user_vote( $this->voter, $this->poll_id ),
			'A purged voter must not keep seeing "you voted" from a stale uservote cache.'
		);
	}

	/**
	 * A repeat read with no vote is served from cache (identical payload).
	 *
	 * @return void
	 */
	public function test_results_repeat_read_is_cached(): void {
		$this->assertSame(
			$this->service->results( $this->poll_id ),
			$this->service->results( $this->poll_id )
		);
	}
}
