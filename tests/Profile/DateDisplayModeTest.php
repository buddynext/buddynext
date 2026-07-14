<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The Date "Display as" setting must actually reduce what is published.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_UnitTestCase;

/**
 * An owner who asks for "Age only" was still publishing the full date of birth.
 *
 * The admin offered date | month_year | year | age, validated it, and saved it into
 * options['display'] — and NOT ONE LINE read it back. Every date rendered as the raw stored
 * Y-m-d.
 *
 * That is not cosmetic. The obvious use is a BIRTHDAY field: a member does not want their full
 * date of birth published, and age alone is enough. The owner picks "Age only" to guarantee
 * that. The product accepted the choice, said it was saved, and published the DOB anyway —
 * with no way for anyone to notice, because the admin screen shows exactly what was picked.
 *
 * @covers \BuddyNext\Profile\FieldType::format_date
 * @covers \BuddyNext\Profile\FieldType::rest_value
 */
class DateDisplayModeTest extends WP_UnitTestCase {

	/**
	 * A date field configured with a display mode.
	 *
	 * @param string $mode Display mode.
	 * @return array<string,mixed>
	 */
	private function date_field( string $mode ): array {
		return array(
			'field_key' => 'birthday',
			'label'     => 'Birthday',
			'type'      => 'date',
			'options'   => array( 'display' => $mode ),
		);
	}

	/**
	 * A birth date 34 years ago (and a bit), so the age is unambiguous.
	 *
	 * @return string
	 */
	private function birth_date(): string {
		return gmdate( 'Y-m-d', strtotime( '-34 years -2 months' ) );
	}

	/**
	 * "Age only" must never render the date.
	 *
	 * @return void
	 */
	public function test_age_mode_shows_an_age_and_never_the_date(): void {
		$rendered = FieldType::display_text( $this->date_field( 'age' ), $this->birth_date() );

		$this->assertStringContainsString( '34', $rendered );
		$this->assertStringNotContainsString(
			$this->birth_date(),
			$rendered,
			'The owner asked for AGE ONLY and the member’s full date of birth was published anyway.'
		);
	}

	/**
	 * "Year only" must not leak the day or month.
	 *
	 * @return void
	 */
	public function test_year_mode_shows_only_the_year(): void {
		$year = gmdate( 'Y', strtotime( $this->birth_date() ) );

		$this->assertSame( $year, FieldType::display_text( $this->date_field( 'year' ), $this->birth_date() ) );
	}

	/**
	 * "Month and year" must not leak the day.
	 *
	 * @return void
	 */
	public function test_month_year_mode_hides_the_day(): void {
		$rendered = FieldType::display_text( $this->date_field( 'month_year' ), $this->birth_date() );

		$this->assertStringNotContainsString(
			$this->birth_date(),
			$rendered,
			'month_year still published the exact date.'
		);
		$this->assertStringContainsString( gmdate( 'Y', strtotime( $this->birth_date() ) ), $rendered );
	}

	/**
	 * Full-date mode still shows the full date — the setting must not refuse everything.
	 *
	 * Without this, returning '' for every mode would satisfy the assertions above while
	 * making date fields useless.
	 *
	 * @return void
	 */
	public function test_date_mode_still_shows_the_date(): void {
		$rendered = FieldType::display_text( $this->date_field( 'date' ), $this->birth_date() );

		$this->assertNotSame( '', $rendered );
		$this->assertStringContainsString( gmdate( 'Y', strtotime( $this->birth_date() ) ), $rendered );
	}

	/**
	 * THE HALF THAT WOULD HAVE MADE THE FIX WORTHLESS.
	 *
	 * Format the profile but leave the REST payload carrying the exact Y-m-d, and the app
	 * renders "34 years old" while the API hands the date of birth to anyone who reads the
	 * response. The leak survives the fix, in the surface nobody looks at.
	 *
	 * @return void
	 */
	public function test_the_rest_payload_does_not_leak_the_date_in_a_reduced_mode(): void {
		foreach ( array( 'age', 'year', 'month_year' ) as $mode ) {
			$payload = FieldType::rest_value( $this->date_field( $mode ), $this->birth_date() );

			$this->assertStringNotContainsString(
				$this->birth_date(),
				(string) $payload,
				"REST still emits the raw date of birth in '{$mode}' mode. The profile hides it and the API gives it away."
			);
		}
	}

	/**
	 * In full-date mode REST still gets the real date — clients need it to format.
	 *
	 * @return void
	 */
	public function test_the_rest_payload_keeps_the_date_in_full_date_mode(): void {
		$this->assertSame(
			$this->birth_date(),
			FieldType::rest_value( $this->date_field( 'date' ), $this->birth_date() )
		);
	}

	/**
	 * A future date has no age. Show nothing, not a negative number.
	 *
	 * @return void
	 */
	public function test_a_future_date_does_not_render_a_negative_age(): void {
		$future = gmdate( 'Y-m-d', strtotime( '+5 years' ) );

		$this->assertSame( '', FieldType::display_text( $this->date_field( 'age' ), $future ) );
	}
}
