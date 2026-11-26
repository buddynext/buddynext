<?php
/**
 * Relative times are right on a site outside UTC.
 *
 * The Moderation screen compared a stored UTC time with current_time( 'timestamp' ),
 * which is "now" shifted by the site offset, so on a New York site an event from an
 * hour ago read "in 4 hours". Every long-form relative label now goes through
 * Dates::relative(), which compares two real UTC instants.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Admin\ModerationQueue;
use BuddyNext\Core\Dates;

/**
 * @covers \BuddyNext\Core\Dates::relative
 * @covers \BuddyNext\Admin\ModerationQueue
 */
class RelativeTimeTimezoneTest extends \WP_UnitTestCase {

	/**
	 * Put the site in a timezone well away from UTC.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'timezone_string', 'America/New_York' );
		update_option( 'gmt_offset', '' );
	}

	/**
	 * Call the moderation screen's private formatter.
	 *
	 * @param string $utc Stored UTC datetime.
	 * @return string
	 */
	private function queue_ago( string $utc ): string {
		$queue  = ( new \ReflectionClass( ModerationQueue::class ) )->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod( ModerationQueue::class, 'ago' );
		$method->setAccessible( true );
		return (string) $method->invoke( $queue, $utc );
	}

	public function test_a_past_event_reads_as_past_on_a_non_utc_site(): void {
		$utc = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		$this->assertSame( '1 hour ago', Dates::relative( $utc ) );
		$this->assertSame( '1 hour ago', $this->queue_ago( $utc ), 'The moderation log and reports must not say "in 4 hours" for the past.' );
	}

	public function test_a_future_expiry_reads_as_future_on_a_non_utc_site(): void {
		$utc = gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS + 60 );

		$this->assertSame( 'in 3 days', $this->queue_ago( $utc ), 'A suspension expiry is counted from the real now.' );
	}

	public function test_empty_and_zero_dates_give_no_label(): void {
		$this->assertSame( '', Dates::relative( '' ) );
		$this->assertSame( '', Dates::relative( '0000-00-00 00:00:00' ) );
	}
}
