<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The framing rule shared by the member cover and the Space cover.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\CoverFocal;
use WP_UnitTestCase;

class CoverFocalTest extends WP_UnitTestCase {

	public function test_a_valid_position_is_kept_and_rounded(): void {
		$this->assertSame(
			array(
				'x'    => 33.33,
				'y'    => 66.67,
				'zoom' => 1.235,
			),
			CoverFocal::clean( '33.3333', 66.66666, '1.23456' )
		);
	}

	public function test_zoom_is_clamped_and_a_missing_zoom_is_one(): void {
		$this->assertSame( 3.0, CoverFocal::clean( 10, 10, 9 )['zoom'] );
		$this->assertSame( 1.0, CoverFocal::clean( 10, 10, 0.2 )['zoom'] );
		$this->assertSame( 1.0, CoverFocal::clean( 10, 10 )['zoom'] );
		$this->assertSame( 1.0, CoverFocal::clean( 10, 10, 'abc' )['zoom'] );
	}

	public function test_an_out_of_range_or_non_numeric_pan_is_refused_not_clamped(): void {
		foreach ( array( array( -1, 50 ), array( 50, 101 ), array( 'abc', 50 ), array( 50, null ), array( null, null ) ) as $pair ) {
			$this->assertNull( CoverFocal::clean( $pair[0], $pair[1], 1 ), wp_json_encode( $pair ) );
		}
		$this->assertSame( 0.0, CoverFocal::clean( 0, 100 )['x'], 'The edges 0 and 100 are valid.' );
	}

	public function test_from_params_reads_the_three_request_fields(): void {
		$this->assertSame(
			array(
				'x'    => 12.0,
				'y'    => 34.0,
				'zoom' => 2.0,
			),
			CoverFocal::from_params(
				array(
					'focal_x'    => '12',
					'focal_y'    => '34',
					'focal_zoom' => '2',
				)
			)
		);
		$this->assertNull( CoverFocal::from_params( array( 'focal_zoom' => 2 ) ), 'No pan, nothing to store.' );
	}
}
