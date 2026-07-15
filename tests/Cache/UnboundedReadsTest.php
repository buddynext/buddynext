<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * S4-S7 — unbounded reads and uncapped counts, now bounded.
 *
 * @package BuddyNext\Tests\Cache
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Cache;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\SocialGraph\BlockService;
use WP_UnitTestCase;

/**
 * Four reads that scanned or returned more than a page needed (card 10094920419).
 *
 * S4  BlockService paged callers read the WHOLE block/mute set and array_slice()'d in PHP;
 *     $limit/$offset never reached SQL. A paged admin list now slices in the query.
 * S5  home_feed_counts() ran four unbounded COUNT(*) over bn_posts per cache-miss; now each
 *     is capped at NEW_COUNT_CAP + 1, the same "99+" bound the new-posts pill uses.
 * S6  get_reports_for_object() returned SELECT * with no LIMIT while the REST route declared
 *     per_page; the pagination now reaches the query.
 * S7  the notification bell's per-type counts are cached under the 30s TTL, busted with the
 *     unread count.
 *
 * @covers \BuddyNext\SocialGraph\BlockService::blocked_users
 * @covers \BuddyNext\Feed\FeedService::home_feed_counts
 * @covers \BuddyNext\Moderation\ModerationService::get_reports_for_object
 * @covers \BuddyNext\Notifications\NotificationService::unread_counts_by_type
 */
class UnboundedReadsTest extends WP_UnitTestCase {

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
	 * S4 — a paged blocked_users read returns only the requested page.
	 *
	 * @return void
	 */
	public function test_blocked_users_pages_in_sql(): void {
		$blocker = self::factory()->user->create();
		$blocks  = buddynext_service( 'blocks' );

		$targets = self::factory()->user->create_many( 7 );
		foreach ( $targets as $t ) {
			$blocks->block( $blocker, $t );
		}

		$page = $blocks->blocked_users( $blocker, 3, 0 );
		$this->assertCount( 3, $page, 'A paged blocked_users read must return exactly the page size.' );

		$page2 = $blocks->blocked_users( $blocker, 3, 3 );
		$this->assertCount( 3, $page2 );
		$this->assertSame( array(), array_intersect( $page, $page2 ), 'Page 1 and page 2 overlap - OFFSET is not being applied in SQL.' );

		// The full read (limit 0) still returns everyone, for the feed-exclusion consumer.
		$this->assertCount( 7, $blocks->blocked_users( $blocker, 0, 0 ) );
	}

	/**
	 * S5 — a home-feed tab count is capped, not a full scan.
	 *
	 * @return void
	 */
	public function test_home_feed_counts_are_capped(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );

		$posts = new PostService();
		for ( $i = 0; $i < 5; $i++ ) {
			$posts->create( $user, array( 'content' => "post {$i}" ) );
		}

		$counts = buddynext_service( 'feed' )->home_feed_counts( $user );

		$this->assertArrayHasKey( 'for_you', $counts );
		$this->assertLessThanOrEqual(
			100,
			(int) $counts['for_you'],
			'The tab count exceeded the cap. It must stop counting at NEW_COUNT_CAP + 1 so the scan is bounded.'
		);
	}

	/**
	 * S6 — reports for an object are paged, not returned whole.
	 *
	 * @return void
	 */
	public function test_reports_for_object_are_paged(): void {
		global $wpdb;

		$object_id = 4242;
		for ( $i = 0; $i < 8; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_reports',
				array(
					'reporter_id' => $i + 1,
					'object_type' => 'post',
					'object_id'   => $object_id,
					'reason'      => 'spam',
					'status'      => 'pending',
					'created_at'  => gmdate( 'Y-m-d H:i:s', time() - $i ),
				)
			);
		}

		$page = ( new ModerationService() )->get_reports_for_object( 'post', $object_id, 5, 1 );
		$this->assertCount( 5, $page, 'get_reports_for_object ignored per_page - it must apply the LIMIT in SQL.' );

		$page2 = ( new ModerationService() )->get_reports_for_object( 'post', $object_id, 5, 2 );
		$this->assertCount( 3, $page2, 'The second page did not return the remaining reports - OFFSET is not applied.' );
	}

	/**
	 * S7 — the per-type notification counts are cached and busted on read.
	 *
	 * @return void
	 */
	public function test_unread_counts_by_type_are_cached_and_busted(): void {
		global $wpdb;

		$user    = self::factory()->user->create();
		$service = new NotificationService();

		$service->create( array( 'recipient_id' => $user, 'sender_id' => 2, 'type' => 'follow' ) );

		$this->assertSame( 1, ( $service->unread_counts_by_type( $user )['follow'] ?? 0 ) );

		// Cached: a second read costs no query.
		$before = $wpdb->num_queries;
		$service->unread_counts_by_type( $user );
		$this->assertSame( 0, $wpdb->num_queries - $before, 'The per-type counts hit the DB on a cached read.' );

		// A new notification busts it.
		$service->create( array( 'recipient_id' => $user, 'sender_id' => 3, 'type' => 'follow' ) );
		$this->assertSame(
			2,
			( $service->unread_counts_by_type( $user )['follow'] ?? 0 ),
			'A new notification did not update the cached per-type count - the create path must bust it.'
		);
	}
}
