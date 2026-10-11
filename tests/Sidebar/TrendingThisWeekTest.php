<?php
/**
 * Trending Topics ranks by the last week, not by all-time totals.
 *
 * @package BuddyNext\Tests\Sidebar
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Sidebar;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Sidebar\WidgetCache;
use BuddyNext\Sidebar\WidgetService;

/**
 * Trending window tests.
 *
 * @covers \BuddyNext\Sidebar\WidgetService::trending_hashtags
 */
class TrendingThisWeekTest extends \WP_UnitTestCase {

	/**
	 * Tag a new public post by a fresh member.
	 *
	 * @param string $slug Hashtag slug.
	 * @return int Post id.
	 */
	private function tagged_post( string $slug ): int {
		$post_id = ( new PostService() )->create(
			self::factory()->user->create(),
			array(
				'type'    => 'text',
				'content' => 'About ' . $slug,
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $post_id );
		( new HashtagService() )->sync( 'post', (int) $post_id, array( $slug ) );

		return (int) $post_id;
	}

	/**
	 * A tag used this week is listed; one used only long ago is not, however big.
	 *
	 * @return void
	 */
	public function test_only_tags_used_in_the_window_are_listed(): void {
		global $wpdb;
		Installer::run();

		$old = array( $this->tagged_post( 'oldbig' ), $this->tagged_post( 'oldbig' ), $this->tagged_post( 'oldbig' ) );
		$this->tagged_post( 'freshsmall' );
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_post_hashtags SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY )
			 WHERE post_id IN ( " . implode( ',', array_map( 'intval', $old ) ) . ' )'
		);

		$slugs = array_map(
			static fn( $row ) => $row->slug,
			( new WidgetService( new WidgetCache() ) )->trending_hashtags( 5 )
		);

		$this->assertSame( array( 'freshsmall' ), $slugs );
	}

	/**
	 * The card prints the window's count, and follows the window when it changes.
	 *
	 * @return void
	 */
	public function test_count_and_window_follow_the_filter(): void {
		global $wpdb;
		Installer::run();

		$old = array( $this->tagged_post( 'mixed' ), $this->tagged_post( 'mixed' ) );
		$this->tagged_post( 'mixed' );
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_post_hashtags SET created_at = DATE_SUB( UTC_TIMESTAMP(), INTERVAL 30 DAY )
			 WHERE post_id IN ( " . implode( ',', array_map( 'intval', $old ) ) . ' )'
		);

		$widgets = new WidgetService( new WidgetCache() );
		$week    = $widgets->trending_hashtags( 5 );
		$this->assertSame( 1, (int) $week[0]->post_count, 'One post this week, three all-time: the card says one.' );

		// A wider window is a different list, served at once (not the cached week).
		$wide = static fn(): int => 24 * 60;
		add_filter( 'buddynext_trending_topics_hours', $wide );
		$sixty_days = $widgets->trending_hashtags( 5 );
		remove_filter( 'buddynext_trending_topics_hours', $wide );

		$this->assertSame( 3, (int) $sixty_days[0]->post_count );
	}
}
