<?php
/**
 * The digest control must not offer two options that do the same thing.
 *
 * Settings > Notifications > Email Digest offered Daily / Weekly / Disabled. The
 * only code that reads `buddynext_digest_frequency` is
 * `EmailSender::digests_disabled()`, and it asks one question: is the value
 * `'never'`? Both digest cron jobs are registered unconditionally, and the
 * cadence a member actually receives comes from their own `email_freq`
 * preference. So Daily and Weekly were indistinguishable, and the hint - "how
 * often BuddyNext sends a digest of unread notifications" - described behaviour
 * the control did not have.
 *
 * The control is now Enabled / Disabled, which is what it always was.
 *
 * ## The migration hazard this file exists for
 *
 * Removing an option from a select is not free. A site that stored 'daily' now
 * holds a value with no matching <option>; the browser preselects the first one,
 * and the owner's next save writes THAT back. Order the choices badly and every
 * Daily site silently turns its digests off the next time anyone touches the
 * notifications tab - a data-losing regression shipped inside a cosmetic fix.
 *
 * Two things prevent it, and both are asserted below: a `value_callback` that
 * normalises any non-'never' value to the enabled option, and 'weekly' being
 * declared first so even an unmatched value fails safe.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Settings;
use BuddyNext\Notifications\EmailSender;

/**
 * The site-wide digest control.
 *
 * @covers \BuddyNext\Admin\Settings::settings_fields
 */
class DigestFrequencyControlTest extends \WP_UnitTestCase {

	/**
	 * Restore the option after each test.
	 *
	 * @var mixed
	 */
	private $original;

	/**
	 * Remember the stored value.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->original = get_option( 'buddynext_digest_frequency' );
	}

	/**
	 * Put it back.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		if ( false === $this->original ) {
			delete_option( 'buddynext_digest_frequency' );
		} else {
			update_option( 'buddynext_digest_frequency', $this->original );
		}
		parent::tear_down();
	}

	/**
	 * The digest field, located by key across every settings section.
	 *
	 * @return \BuddyNext\Admin\Settings\Field
	 */
	private function field() {
		foreach ( ( new Settings() )->settings_fields() as $section ) {
			foreach ( $section->fields as $field ) {
				if ( 'buddynext_digest_frequency' === $field->key ) {
					return $field;
				}
			}
		}

		$this->fail( 'The digest-frequency field is no longer registered anywhere in Settings.' );
	}

	/**
	 * No two choices may mean the same thing.
	 *
	 * Asserted against what the option actually DOES rather than against a list
	 * of labels: every choice is fed to the one predicate that reads it, and two
	 * choices producing the same answer is the defect.
	 *
	 * @return void
	 */
	public function test_every_choice_produces_a_distinct_outcome(): void {
		$choices = $this->field()->choices();

		$this->assertNotEmpty( $choices, 'The control offers nothing to choose.' );

		$outcomes = array();
		foreach ( array_keys( $choices ) as $choice ) {
			update_option( 'buddynext_digest_frequency', $choice );
			$outcomes[ $choice ] = EmailSender::digests_enabled() ? 'on' : 'off';
		}

		$this->assertSame(
			count( $outcomes ),
			count( array_unique( $outcomes ) ),
			'Two choices behave identically, so at least one of them is a control that does nothing: ' . wp_json_encode( $outcomes )
		);
	}

	/**
	 * A site that stored the removed 'daily' value keeps its digests ON.
	 *
	 * The regression this fix could have caused. Without the value_callback the
	 * select matches nothing, the first option is preselected, and a save flips
	 * the site to whatever that option is.
	 *
	 * @return void
	 */
	public function test_a_legacy_daily_value_still_displays_as_enabled(): void {
		update_option( 'buddynext_digest_frequency', 'daily' );

		$displayed = (string) $this->field()->display_value();

		$this->assertArrayHasKey( $displayed, $this->field()->choices(), 'The displayed value must match a real option, or the select falls back to its first entry.' );

		update_option( 'buddynext_digest_frequency', $displayed );
		$this->assertTrue(
			EmailSender::digests_enabled(),
			'Saving the form on a legacy Daily site turned its digest emails off.'
		);
	}

	/**
	 * Belt and braces: the enabled option is declared first, so even an
	 * unmatched value fails safe rather than silently disabling digests.
	 *
	 * @return void
	 */
	public function test_the_enabled_choice_is_declared_first(): void {
		$keys = array_keys( $this->field()->choices() );

		$this->assertNotSame( 'never', $keys[0], 'Disabled is the first option, so any unmatched stored value preselects "off".' );
	}

	/**
	 * Disabled still disables. The control has to keep working.
	 *
	 * @return void
	 */
	public function test_disabled_still_disables(): void {
		update_option( 'buddynext_digest_frequency', 'never' );

		$this->assertFalse( EmailSender::digests_enabled() );
	}
}
