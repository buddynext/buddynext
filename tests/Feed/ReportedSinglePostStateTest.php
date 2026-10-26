<?php
/**
 * The single-post page hands the report handler a place to send the member back to.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

class ReportedSinglePostStateTest extends \WP_UnitTestCase {

	public function test_single_post_template_carries_the_feed_url_the_report_state_links_to(): void {
		$template = file_get_contents( BUDDYNEXT_DIR . 'templates/feed/single-post.php' );
		$script   = file_get_contents( BUDDYNEXT_DIR . 'assets/js/feed/post-card.js' );

		$this->assertStringContainsString( 'data-bn-feed-url=', $template );
		$this->assertStringContainsString( 'page.dataset.bnFeedUrl', $script );
	}
}
