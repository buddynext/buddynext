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

	/**
	 * THE TEST THE BROWSER FORCED. The PAYLOAD must not carry the date, not just the formatter.
	 *
	 * Every other test in this file calls FieldType directly, and they all passed — while the
	 * live profile went on serving the raw date of birth. get_profile() emitted `value` straight
	 * from the row and templates/profile/view.php printed exactly that, so display_text() was
	 * never on the path. A formatter nothing calls is not a fix.
	 *
	 * Only a browser check caught it. This is that check, in a test.
	 *
	 * @return void
	 */
	public function test_the_profile_payload_never_carries_the_date_for_a_stranger(): void {
		global $wpdb;

		\BuddyNext\Core\Installer::install_schema();

		$owner    = self::factory()->user->create();
		$stranger = self::factory()->user->create();
		$dob      = '1990-03-14';

		$group_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups ORDER BY id ASC LIMIT 1" );

		if ( $group_id <= 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_groups',
				array(
					'group_key'  => 'about-probe',
					'label'      => 'About',
					'type'       => 'flat',
					'visibility' => 'public',
				)
			);
			$group_id = (int) $wpdb->insert_id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'   => $group_id,
				'field_key'  => 'dob_probe',
				'label'      => 'Birthday',
				'type'       => 'date',
				'options'    => wp_json_encode( array( 'display' => 'age' ) ),
				'visibility' => 'public',
			)
		);
		$field_id = (int) $wpdb->insert_id;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'     => $owner,
				'field_id'    => $field_id,
				// Part of UNIQUE KEY (user_id, field_id, entry_index). Supplied
				// rather than left to the column default so this row cannot
				// collide with one another test left behind.
				'entry_index' => 0,
				'value'       => $dob,
			)
		);
		update_user_meta( $owner, 'bn_field_dob_probe', $dob );

		wp_cache_flush();

		$service = new \BuddyNext\Profile\ProfileService();

		$seen = static function ( array $profile, string $key ): ?array {
			foreach ( (array) ( $profile['groups'] ?? array() ) as $group ) {
				foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
					if ( ( $field['field_key'] ?? '' ) === $key ) {
						return $field;
					}
				}
			}

			return null;
		};

		$as_stranger = $seen( $service->get_profile( $owner, $stranger ), 'dob_probe' );

		$this->assertNotNull( $as_stranger, 'precondition: the field must be in the payload at all' );

		$this->assertNotSame(
			$dob,
			(string) $as_stranger['value'],
			'The profile PAYLOAD still carries the raw date of birth. The owner asked for AGE ONLY; every consumer that prints `value` — including templates/profile/view.php — publishes the DOB.'
		);
		$this->assertStringContainsString( '36 years old', (string) $as_stranger['value'] );
		$this->assertNull(
			$as_stranger['value_raw'] ?? null,
			'A stranger was handed value_raw. The raw date must reach nobody but the owner.'
		);

		// And the owner still gets the real date, or they cannot edit their own birthday.
		$as_owner = $seen( $service->get_profile( $owner, $owner ), 'dob_probe' );

		$this->assertSame(
			$dob,
			(string) ( $as_owner['value_raw'] ?? '' ),
			'The owner lost the raw date, so the edit form cannot prefill and they can never correct their own birthday.'
		);
	}

	/**
	 * render_display() presents the value view_value() already formatted, for every
	 * mode, without re-parsing it.
	 *
	 * The bug (card 10123106438): render_display() re-ran strtotime()+date_i18n() on
	 * the already-formatted value, so "1996" became "July 23, 1996" and "April 1996"
	 * became "April 1, 1996" — the "Display as" setting looked ignored. This walks
	 * the real About-tab path (view_value -> render_display) and asserts the result.
	 *
	 * @dataProvider display_mode_provider
	 *
	 * @param string $mode     Display-as mode.
	 * @param string $expected Expected rendered text.
	 * @return void
	 */
	public function test_render_display_presents_the_formatted_value_per_mode( string $mode, string $expected ): void {
		$field = $this->date_field( $mode );

		$view_value = $this->call_view_value( $field, '1996-04-21' );
		$html       = \BuddyNext\Profile\FieldType::render_display( $field, $view_value );

		$this->assertStringContainsString( 'bn-field-value bn-field-date', $html, 'Every mode must keep the field wrapper — age lost it before.' );
		$this->assertSame( $expected, trim( wp_strip_all_tags( $html ) ), "Mode {$mode} rendered wrong." );
	}

	/**
	 * The four modes and what a 1996-04-21 date should read as.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function display_mode_provider(): array {
		return array(
			'year'       => array( 'year', '1996' ),
			'month_year' => array( 'month_year', 'April 1996' ),
			'date'       => array( 'date', 'April 21, 1996' ),
		);
	}

	/**
	 * A stray raw date reaching render_display directly is still formatted, so a
	 * caller that holds the raw value is not left with a bare Y-m-d.
	 *
	 * @return void
	 */
	public function test_render_display_still_formats_a_raw_date_from_a_direct_caller(): void {
		$html = \BuddyNext\Profile\FieldType::render_display( $this->date_field( 'year' ), '1996-04-21' );
		$this->assertSame( '1996', trim( wp_strip_all_tags( $html ) ) );
	}

	/**
	 * The exact regression: a pre-formatted reduced value never becomes a full date.
	 *
	 * @return void
	 */
	public function test_preformatted_value_is_not_reparsed_into_a_full_date(): void {
		$html = trim( wp_strip_all_tags( \BuddyNext\Profile\FieldType::render_display( $this->date_field( 'year' ), '1996' ) ) );
		$this->assertSame( '1996', $html );
		$this->assertStringNotContainsString( ',', $html, 'A re-parsed "1996" would print a full "Month d, 1996".' );
	}

	/**
	 * Call the private ProfileService::view_value the way the About tab does.
	 *
	 * @param array<string,mixed> $field Field.
	 * @param string              $value Raw stored value.
	 * @return mixed
	 */
	private function call_view_value( array $field, string $value ) {
		$m = new \ReflectionMethod( \BuddyNext\Profile\ProfileService::class, 'view_value' );
		$m->setAccessible( true );

		return $m->invoke( null, $field, $value );
	}
}
