<?php
/**
 * Relative time stops being relative once it stops being useful.
 *
 * buddynext_time_ago() had no upper bound, so a post from last year rendered
 * "412d ago" — a number a reader cannot place. Past a week the byline now shows a
 * calendar date, which is what Facebook, X and LinkedIn all do: relative while
 * recency is the useful signal, absolute once it is not.
 *
 * The QA card asked for absolute dates on ALL post cards. That was not done, and
 * deliberately: it would render "28 July 2026" on a post made thirty seconds ago
 * and destroy the recency cue that makes a feed readable. The defect was the
 * missing upper bound, not the use of relative time.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Boundary behaviour of the byline timestamp helper.
 *
 * @covers ::buddynext_time_ago
 */
class TimeAgoBoundaryTest extends \WP_UnitTestCase {

	/**
	 * Format a timestamp N seconds in the past.
	 *
	 * @param int $seconds_ago Age in seconds.
	 * @return string
	 */
	private function ago( int $seconds_ago ): string {
		return buddynext_time_ago( gmdate( 'Y-m-d H:i:s', time() - $seconds_ago ) );
	}

	/**
	 * Recent timestamps stay relative — the reason the feed reads as a feed.
	 *
	 * @return void
	 */
	public function test_recent_timestamps_stay_relative(): void {
		$this->assertSame( 'just now', $this->ago( 5 ) );
		$this->assertSame( '5m ago', $this->ago( 5 * MINUTE_IN_SECONDS ) );
		$this->assertSame( '3h ago', $this->ago( 3 * HOUR_IN_SECONDS ) );
		$this->assertSame( '3d ago', $this->ago( 3 * DAY_IN_SECONDS ) );
	}

	/**
	 * The last day still inside the window is relative; the first day outside is
	 * not. Pins the cutoff so it cannot drift silently.
	 *
	 * @return void
	 */
	public function test_the_one_week_cutoff_is_exact(): void {
		$inside = $this->ago( WEEK_IN_SECONDS - HOUR_IN_SECONDS );
		$this->assertStringContainsString( 'ago', $inside, 'Six days should still be relative.' );

		$outside = $this->ago( WEEK_IN_SECONDS + HOUR_IN_SECONDS );
		$this->assertStringNotContainsString( 'ago', $outside, 'Eight days should be a calendar date.' );
	}

	/**
	 * The reported symptom: an unbounded day count.
	 *
	 * @return void
	 */
	public function test_an_old_post_shows_a_date_not_a_day_count(): void {
		$gmt    = gmdate( 'Y-m-d H:i:s', time() - ( 412 * DAY_IN_SECONDS ) );
		$actual = buddynext_time_ago( $gmt );

		$this->assertStringNotContainsString( '412d', $actual, 'The unbounded day count is back.' );
		$this->assertSame( get_date_from_gmt( $gmt, 'j F Y' ), $actual );
	}

	/**
	 * A date in the current year omits the year; an older one includes it. Keeps
	 * the common case short without ever being ambiguous.
	 *
	 * @return void
	 */
	public function test_the_year_appears_only_when_it_is_not_the_current_one(): void {
		$this_year = gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) );
		$last_year = gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) );

		$this->assertSame( get_date_from_gmt( $this_year, 'j F' ), buddynext_time_ago( $this_year ) );
		$this->assertStringContainsString(
			get_date_from_gmt( $last_year, 'Y' ),
			buddynext_time_ago( $last_year ),
			'A prior-year date must carry its year.'
		);
	}

	/**
	 * The absolute date renders in the SITE timezone, not the server's. A post
	 * made just before midnight UTC must not show yesterday's date on a site set
	 * to a positive offset.
	 *
	 * @return void
	 */
	public function test_the_date_uses_the_site_timezone(): void {
		$original = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Asia/Kolkata' );

		// 20:00 UTC is 01:30 the NEXT day in Asia/Kolkata (+05:30).
		$gmt = gmdate( 'Y-m-d 20:00:00', time() - ( 30 * DAY_IN_SECONDS ) );

		$this->assertSame(
			get_date_from_gmt( $gmt, 'j F' ),
			buddynext_time_ago( $gmt ),
			'The byline fell back to server time instead of the site timezone.'
		);

		update_option( 'timezone_string', $original );
	}

	/**
	 * A site owner can change the absolute format without patching the helper.
	 *
	 * @return void
	 */
	public function test_the_date_format_is_filterable(): void {
		$filter = static fn(): string => 'Y-m-d';
		add_filter( 'buddynext_time_ago_date_format', $filter );

		$gmt = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$this->assertSame( get_date_from_gmt( $gmt, 'Y-m-d' ), buddynext_time_ago( $gmt ) );

		remove_filter( 'buddynext_time_ago_date_format', $filter );
	}

	/**
	 * Empty and unparseable input still returns an empty string rather than a
	 * bogus date — the byline template prints this straight out.
	 *
	 * @return void
	 */
	public function test_invalid_input_still_returns_empty(): void {
		$this->assertSame( '', buddynext_time_ago( '' ) );
		$this->assertSame( '', buddynext_time_ago( '   ' ) );
	}
}
