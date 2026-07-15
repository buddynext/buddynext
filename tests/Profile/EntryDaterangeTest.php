<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Work/Education entry date ranges must be composed from the REAL stored keys.
 *
 * WHY THIS EXISTS — Zoho #40911 (Markus Kaufmann, bn.myblasmusik.de), card 10099614593.
 *
 * The customer, in his own words:
 *
 *     "Start and end dates are not displayed in the profile"
 *
 * Both display surfaces (About timeline cards, right sidebar) read a phantom
 * `work_daterange` / `edu_daterange` sub-field that no field definition has ever written —
 * the Installer seeds work_start_date/work_end_date and edu_start_year/edu_end_year. The
 * dates a member entered were stored and never displayed anywhere.
 *
 * The rule this pins: **display composes from the stored keys through ONE helper**
 * (FieldType::entry_daterange), so the two surfaces can never drift apart again.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_UnitTestCase;

/**
 * Date-range composition for Work Experience / Education entries.
 *
 * @covers \BuddyNext\Profile\FieldType::entry_daterange
 */
class EntryDaterangeTest extends WP_UnitTestCase {

	/**
	 * Template-shaped entry from key => value pairs.
	 *
	 * @param array<string, string> $values field_key => value.
	 * @return array<int, array<string, string>>
	 */
	private function entry( array $values ): array {
		$fields = array();
		foreach ( $values as $key => $value ) {
			$fields[] = array(
				'field_key' => $key,
				'value'     => $value,
			);
		}
		return $fields;
	}

	/**
	 * Start + end compose into a range; the customer's exact missing case.
	 *
	 * @return void
	 */
	public function test_work_start_and_end_compose_a_range(): void {
		$range = FieldType::entry_daterange(
			$this->entry(
				array(
					'work_start_date' => '2019-03-01',
					'work_end_date'   => '2022-06-30',
				)
			),
			'work'
		);
		$this->assertSame( '2019-03-01 – 2022-06-30', $range );
	}

	/**
	 * "Currently working" supersedes the end date with Present.
	 *
	 * @return void
	 */
	public function test_current_entry_reads_present(): void {
		$range = FieldType::entry_daterange(
			$this->entry(
				array(
					'work_start_date' => '2022-07-01',
					'work_end_date'   => '',
					'work_current'    => '1',
				)
			),
			'work'
		);
		$this->assertSame( '2022-07-01 – Present', $range );
	}

	/**
	 * Education composes from the year keys.
	 *
	 * @return void
	 */
	public function test_education_uses_year_keys(): void {
		$range = FieldType::entry_daterange(
			$this->entry(
				array(
					'edu_start_year' => '2016',
					'edu_end_year'   => '2020',
				)
			),
			'edu'
		);
		$this->assertSame( '2016 – 2020', $range );
	}

	/**
	 * Edge shapes: single side, current-only, and empty.
	 *
	 * @return void
	 */
	public function test_partial_and_empty_entries(): void {
		$this->assertSame( '2019-03-01', FieldType::entry_daterange( $this->entry( array( 'work_start_date' => '2019-03-01' ) ), 'work' ) );
		$this->assertSame( 'Current', FieldType::entry_daterange( $this->entry( array( 'work_current' => '1' ) ), 'work' ) );
		$this->assertSame( '', FieldType::entry_daterange( $this->entry( array() ), 'work' ) );
	}
}
