<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests the shared Backdate helper importers use to set historical timestamps.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Backdate;
use WP_UnitTestCase;

/**
 * Backdate::resolve() must mirror PostService's importer-seam semantics:
 * UTC "Y-m-d H:i:s" in, clamped to now, fallback now-UTC.
 */
class BackdateTest extends WP_UnitTestCase {

	/**
	 * A valid past timestamp is returned verbatim (normalised).
	 *
	 * @return void
	 */
	public function test_valid_past_value_is_honoured(): void {
		$this->assertSame( '2019-05-04 12:30:00', Backdate::resolve( '2019-05-04 12:30:00' ) );
	}

	/**
	 * Null/empty fall back to now (UTC).
	 *
	 * @return void
	 */
	public function test_null_and_empty_fall_back_to_now(): void {
		$before = time();
		foreach ( array( null, '' ) as $value ) {
			$ts = strtotime( Backdate::resolve( $value ) . ' UTC' );
			$this->assertGreaterThanOrEqual( $before, $ts );
			$this->assertLessThanOrEqual( time() + 2, $ts );
		}
	}

	/**
	 * A future value is clamped to now — backdating only, scheduling has its
	 * own mechanisms.
	 *
	 * @return void
	 */
	public function test_future_value_is_clamped_to_now(): void {
		$before = time();
		$ts     = strtotime( Backdate::resolve( gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $ts );
		$this->assertLessThanOrEqual( time() + 2, $ts );
	}

	/**
	 * Garbage falls back to now rather than erroring or writing junk.
	 *
	 * @return void
	 */
	public function test_unparseable_value_falls_back_to_now(): void {
		$before = time();
		$ts     = strtotime( Backdate::resolve( 'not-a-date' ) . ' UTC' );
		$this->assertGreaterThanOrEqual( $before, $ts );
	}
}
