<?php
/**
 * The 14-day new members chart shows its dates and counts as text.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Insights;
use BuddyNext\Core\Installer;

/**
 * Card 10455450184: the bars carried their date and count only in a hover
 * title, so touch and keyboard users saw fourteen unlabelled bars.
 *
 * @covers \BuddyNext\Admin\Insights
 */
class InsightsSignupChartTest extends \WP_UnitTestCase {

	public function test_each_day_shows_its_count_and_date_without_hover(): void {
		Installer::run();
		Insights::flush();
		self::factory()->user->create();

		ob_start();
		( new Insights() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 14, substr_count( $html, 'class="bn-spark__col"' ) );
		$this->assertSame( 14, substr_count( $html, 'class="bn-spark__value"' ) );
		$this->assertSame( 14, substr_count( $html, 'class="bn-spark__day"' ) );
		$this->assertStringNotContainsString( 'bn-spark__bar" style="height:6%" title=', $html, 'no hover-only title' );

		$today = (string) wp_date( 'j' );
		$this->assertMatchesRegularExpression( '/<span class="bn-spark__day" aria-hidden="true">' . $today . '<\/span><span class="screen-reader-text">\d+ signups? on [^<]+<\/span><\/li><\/ol>/', $html, 'the last column is today, with a full sentence for screen readers' );
		$this->assertStringContainsString( 'class="bn-spark__range"', $html );
	}
}
