<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * S2 — related() is a public, cacheable self-join; it must be cached and windowed.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Hashtags\HashtagService;
use WP_UnitTestCase;

/**
 * HashtagService::related() served /hashtags/{slug}/related -- anonymous, cacheable -- and
 * was a completely uncached self-join over bn_post_hashtags with no time bound (every post
 * the tag ever appeared on). It is pure tag metadata, not viewer-scoped, so it caches
 * globally by (slug, limit), the same two-layer way get_trending() does. A 90-day window
 * on the seed bounds the scan and keeps "related" meaning "related lately".
 *
 * @covers \BuddyNext\Hashtags\HashtagService::related
 */
class RelatedCacheTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var HashtagService
	 */
	private HashtagService $service;

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		$this->service = new HashtagService();
	}

	/**
	 * Slugs in a related() result.
	 *
	 * @param array<int, array<string, mixed>> $rows Related rows.
	 * @return array<int, string>
	 */
	private function slugs( array $rows ): array {
		return array_map( static fn( array $r ): string => (string) ( $r['slug'] ?? '' ), $rows );
	}

	/**
	 * Related tags come back, and a second call is served from cache (no query).
	 *
	 * @return void
	 */
	public function test_related_is_returned_and_then_cached(): void {
		global $wpdb;

		// Two posts co-tag 'php' with 'wordpress' and 'laravel'.
		$this->service->sync( 'post', 1, array( 'php', 'wordpress' ) );
		$this->service->sync( 'post', 2, array( 'php', 'laravel' ) );

		$first = $this->slugs( $this->service->related( 'php', 6 ) );
		$this->assertContains( 'wordpress', $first, 'wordpress co-occurs with php and should be related.' );
		$this->assertContains( 'laravel', $first );

		$before = $wpdb->num_queries;
		$second = $this->slugs( $this->service->related( 'php', 6 ) );

		$this->assertSame( $first, $second, 'The cached result differs from the first.' );
		$this->assertSame(
			0,
			$wpdb->num_queries - $before,
			'related() hit the database on a cached read. It is a public REST route and a self-join -- the second call must come from cache.'
		);
	}

	/**
	 * Co-occurrences older than the window are not counted.
	 *
	 * "Related" is a recency signal, and the window is also what bounds the self-join on a
	 * tag with years of history. A co-tag from long ago must drop out.
	 *
	 * @return void
	 */
	public function test_co_occurrences_outside_the_window_are_excluded(): void {
		global $wpdb;

		// A recent co-occurrence: php + recenttag.
		$this->service->sync( 'post', 10, array( 'php', 'recenttag' ) );

		// An OLD co-occurrence: php + ancienttag, backdated well past the window.
		$this->service->sync( 'post', 11, array( 'php', 'ancienttag' ) );
		$old = gmdate( 'Y-m-d H:i:s', time() - ( 200 * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_post_hashtags SET created_at = %s WHERE post_id = 11", $old ) );

		wp_cache_flush();
		delete_transient( 'bn_related_' . md5( 'php_6' ) );

		$slugs = $this->slugs( $this->service->related( 'php', 6 ) );

		$this->assertContains( 'recenttag', $slugs, 'A recent co-occurrence should be related.' );
		$this->assertNotContains(
			'ancienttag',
			$slugs,
			'A co-occurrence from 200 days ago is still counted. The seed window must bound related() to recent history.'
		);
	}
}
