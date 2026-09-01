<?php
/**
 * "N new posts" must mean posts that did not exist when the page rendered.
 *
 * The For-you feed is TIER-ordered, not chronological: own post, then
 * connections, then interest-spaces, then everyone else — and only inside a tier
 * does recency decide. So a stranger's post can be newer than every post on page
 * one and still not be on page one, because it lost the tier competition.
 *
 * The pill's watermark, meanwhile, is `max(data-post-id)` scanned off the
 * RENDERED cards, and `home_feed_new_count()` counts every post above it that
 * matches the source blend — with no tier awareness at all. Those two facts
 * combine into a pill that fires on a feed nobody has posted to: it is counting
 * older posts that merely ranked below the fold.
 *
 * The member is then told "N new posts — refresh to view", refreshes, and the
 * ranking is unchanged, so the same posts stay off page one and the same pill
 * comes back. It is not just wrong, it cannot be dismissed.
 *
 * ## What this file pins down
 *
 * The load-time false positive, which is the reported symptom and the one with a
 * clean answer: the watermark has to be the newest post that EXISTS for this
 * viewer, not the newest one that happened to rank onto page one.
 *
 * It does not pin down the second half — a genuinely new low-tier post may still
 * not reach page one after a refresh. That is inherent to counting
 * chronologically while ranking by affinity, and needs a product decision rather
 * than a patch. Recorded on the card.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;


/**
 * New-posts pill accounting under tiered ranking.
 *
 * @covers \BuddyNext\Feed\FeedService::home_feed_new_count
 * @covers \BuddyNext\Feed\FeedService::home_feed_watermark
 */
class NewPostsPillCountsOnlyRealNewsTest extends \WP_UnitTestCase {

	/**
	 * The member reading the feed.
	 *
	 * @var int
	 */
	private int $viewer = 0;

	/**
	 * A connection of the viewer — their posts win the tier competition.
	 *
	 * @var int
	 */
	private int $friend = 0;

	/**
	 * Someone the viewer has no relationship with — bottom tier.
	 *
	 * @var int
	 */
	private int $stranger = 0;

	/**
	 * Two members with an accepted connection, plus an unrelated third.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->viewer   = self::factory()->user->create();
		$this->friend   = self::factory()->user->create();
		$this->stranger = self::factory()->user->create();

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_connections',
			array(
				'requester_id' => $this->viewer,
				'recipient_id' => $this->friend,
				'status'       => 'accepted',
				'created_at'   => current_time( 'mysql', true ),
			)
		);

		wp_cache_flush();
	}

	/**
	 * Insert a published post directly, with an explicit creation time.
	 *
	 * Straight to the table rather than through PostService: this test needs
	 * control over created_at AND over insertion order, so that "newest id" and
	 * "highest tier" can be made to disagree — which is the whole scenario.
	 *
	 * @param int    $author     Author user id.
	 * @param string $content    Body.
	 * @param string $created_at UTC datetime.
	 * @return int Inserted post id.
	 */
	private function post( int $author, string $content, string $created_at ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $author,
				'content'    => $content,
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => $created_at,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * The reported scenario: nothing was posted, and the pill still counts.
	 *
	 * The connection's posts fill page one; the stranger's post is OLDER by clock
	 * but has a HIGHER id, because it was inserted last. Under tier ordering it
	 * ranks below the fold, so the rendered page never carries its id — and the
	 * page-derived watermark therefore sits below it.
	 *
	 * @return void
	 */
	public function test_a_post_that_merely_ranked_below_the_fold_is_not_new(): void {
		$service = buddynext_service( 'feed' );

		// Page one's worth of connection posts, all recent.
		for ( $i = 0; $i < 20; $i++ ) {
			$this->post( $this->friend, 'Friend post ' . $i, gmdate( 'Y-m-d H:i:s', time() - ( 60 * $i ) - 60 ) );
		}

		// The stranger's post: highest id in the table, older than every post above,
		// bottom tier. This is an EXISTING post, not news.
		$stranger_post = $this->post( $this->stranger, 'Stranger post', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );

		wp_cache_flush();
		$page = $service->home_feed( $this->viewer, null, 20, 'for-you' );

		$rendered_ids = array_map( static fn( array $p ): int => (int) $p['id'], (array) ( $page['posts'] ?? $page['items'] ?? array() ) );

		$this->assertNotEmpty( $rendered_ids, 'The feed rendered nothing, so there is no scenario to test.' );
		$this->assertNotContains(
			$stranger_post,
			$rendered_ids,
			'Precondition: the stranger post must rank OFF page one, or the tier competition this test depends on did not happen.'
		);

		// What the client does today: scan the rendered cards for the highest id.
		$page_watermark = max( $rendered_ids );
		$this->assertLessThan(
			$stranger_post,
			$page_watermark,
			'Precondition: the off-page post must have the higher id, or there is no false positive to produce.'
		);

		// The bug itself, asserted directly rather than implied: with the
		// page-derived watermark the pill DOES fire, on a feed nobody posted to.
		wp_cache_flush();
		$false_positive = $service->home_feed_new_count( $this->viewer, $page_watermark, 'for-you' );
		$this->assertGreaterThan(
			0,
			$false_positive['count'],
			'The scenario did not reproduce: with the old page-derived watermark the count should be non-zero, which is the bug.'
		);

		// What the server should hand the client instead.
		$watermark = $service->home_feed_watermark( $this->viewer, 'for-you' );

		wp_cache_flush();
		$counted = $service->home_feed_new_count( $this->viewer, $watermark, 'for-you' );

		$this->assertSame(
			0,
			$counted['count'],
			'The pill reported new posts on a feed nobody had posted to - it was counting an existing post that ranked below the fold.'
		);
	}

	/**
	 * A genuinely new post still counts. The pill must not be silenced.
	 *
	 * Guards the guard: a watermark set to PHP_INT_MAX would pass the test above
	 * and break the feature outright.
	 *
	 * @return void
	 */
	public function test_a_post_made_after_the_page_rendered_still_counts(): void {
		$service = buddynext_service( 'feed' );

		$this->post( $this->friend, 'Existing post', gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		wp_cache_flush();
		$watermark = $service->home_feed_watermark( $this->viewer, 'for-you' );

		// Somebody posts while the member is reading.
		$this->post( $this->stranger, 'Posted just now', gmdate( 'Y-m-d H:i:s', time() ) );

		wp_cache_flush();
		$counted = $service->home_feed_new_count( $this->viewer, $watermark, 'for-you' );

		$this->assertSame( 1, $counted['count'], 'A post made after the watermark was taken must still raise the pill.' );
	}

	/**
	 * The viewer's own post never raises a pill for the viewer.
	 *
	 * `home_feed_new_count()` already excludes `user_id = viewer`; the watermark
	 * must not reintroduce them by sitting below their own newest post.
	 *
	 * @return void
	 */
	public function test_the_viewers_own_post_does_not_raise_a_pill(): void {
		$service = buddynext_service( 'feed' );

		$this->post( $this->friend, 'Someone else', gmdate( 'Y-m-d H:i:s', time() - 600 ) );

		wp_cache_flush();
		$watermark = $service->home_feed_watermark( $this->viewer, 'for-you' );

		$this->post( $this->viewer, 'My own post', gmdate( 'Y-m-d H:i:s', time() ) );

		wp_cache_flush();
		$counted = $service->home_feed_new_count( $this->viewer, $watermark, 'for-you' );

		$this->assertSame( 0, $counted['count'] );
	}

	/**
	 * An empty feed yields a watermark of 0 rather than an error or a false floor.
	 *
	 * @return void
	 */
	public function test_an_empty_feed_has_a_zero_watermark(): void {
		$this->assertSame( 0, buddynext_service( 'feed' )->home_feed_watermark( $this->viewer, 'for-you' ) );
	}

	/**
	 * The watermark respects the filter, exactly as the count does.
	 *
	 * The pill scopes its count to the tab the member is on. A watermark taken
	 * over a different blend would be inconsistent with it — too high on a narrow
	 * tab (silencing real news) or too low on a wide one (the original bug).
	 *
	 * @return void
	 */
	public function test_the_watermark_is_scoped_to_the_active_filter(): void {
		$service = buddynext_service( 'feed' );

		$this->post( $this->friend, 'Connection post', gmdate( 'Y-m-d H:i:s', time() - 600 ) );
		$stranger_post = $this->post( $this->stranger, 'Stranger post', gmdate( 'Y-m-d H:i:s', time() - 300 ) );

		wp_cache_flush();
		$for_you = $service->home_feed_watermark( $this->viewer, 'for-you' );
		$network = $service->home_feed_watermark( $this->viewer, 'network' );

		$this->assertSame( $stranger_post, $for_you, 'For-you includes public community activity, so the stranger post is its newest.' );
		$this->assertLessThan( $stranger_post, $network, 'The network tab sees connections only, so its watermark must sit below the stranger post.' );
	}
}
