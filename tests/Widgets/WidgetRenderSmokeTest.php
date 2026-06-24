<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Render smoke tests for the sidebar widgets after the caching refactor
 * (change-index items J2 + I2/J1).
 *
 * The widgets echo HTML, so these confirm they render without fatal through the
 * new cached paths (TrendingHashtags via the canonical get_trending store; Recent
 * Activity via the short-TTL cache + primed author cache).
 *
 * @package BuddyNext\Tests\Widgets
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Widgets;

use BuddyNext\Core\Installer;
use BuddyNext\Widgets\RecentActivityWidget;
use BuddyNext\Widgets\TrendingHashtagsWidget;
use WP_UnitTestCase;

/**
 * Widget render smoke.
 */
class WidgetRenderSmokeTest extends WP_UnitTestCase {

	/**
	 * Ensure the schema exists.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Standard widget chrome args.
	 *
	 * @return array<string, string>
	 */
	private function args(): array {
		return array(
			'before_widget' => '<section>',
			'after_widget'  => '</section>',
			'before_title'  => '<h3>',
			'after_title'   => '</h3>',
		);
	}

	/**
	 * TrendingHashtags renders (empty state) without fatal via get_trending.
	 *
	 * @return void
	 */
	public function test_trending_hashtags_renders(): void {
		ob_start();
		( new TrendingHashtagsWidget() )->widget( $this->args(), array( 'limit' => 5 ) );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( '<section>', $out );
		$this->assertStringContainsString( 'No trending hashtags yet.', $out );
	}

	/**
	 * RecentActivity renders (empty state) without fatal via the cached path.
	 *
	 * @return void
	 */
	public function test_recent_activity_renders(): void {
		ob_start();
		( new RecentActivityWidget() )->widget( $this->args(), array( 'limit' => 5 ) );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( '<section>', $out );
		$this->assertStringContainsString( 'No recent activity yet.', $out );
	}
}
