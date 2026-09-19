<?php
/**
 * Declared defaults apply, and "Restore defaults" resets a tab's resettable
 * options while leaving owner data and other tabs untouched (card 9995933507).
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings;
use BuddyNext\Admin\Settings\SettingsDriver;
use BuddyNext\Admin\Settings\SettingsRegistry;

/**
 * @covers \BuddyNext\Admin\Settings\SettingsDriver
 */
class RestoreDefaultsTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// register() hooks register_settings() on admin_init; call it directly so
		// register_setting() runs and get_option() inherits the declared defaults
		// (no admin request in a unit test).
		$settings = new Settings();
		$settings->register();
		$settings->register_settings();
	}

	/**
	 * A declared default applies on a fresh install (no option row).
	 *
	 * @return void
	 */
	public function test_declared_defaults_apply_when_the_option_is_absent(): void {
		delete_option( 'buddynext_reg_ask_name' );
		delete_option( 'buddynext_reg_rate_limit' );

		// Default-ON toggle: absent row must read true, not WP's absent-default false.
		$this->assertTrue( (bool) get_option( 'buddynext_reg_ask_name' ), 'reg_ask_name defaults ON.' );
		// Number: absent row reads the declared 5.
		$this->assertSame( 5, (int) get_option( 'buddynext_reg_rate_limit' ), 'reg_rate_limit defaults to 5.' );
	}

	/**
	 * A dynamic default_callback resolves to a real value at read time.
	 *
	 * @return void
	 */
	public function test_dynamic_default_callback_resolves(): void {
		$field = null;
		foreach ( SettingsRegistry::all_fields() as $f ) {
			if ( 'buddynext_reg_mode' === $f->key ) {
				$field = $f;
				break;
			}
		}
		$this->assertNotNull( $field, 'reg_mode field is registered.' );
		$this->assertContains( $field->resolve_default(), array( 'open', 'closed', 'invite' ), 'reg_mode default_callback resolves to a mode.' );
	}

	/**
	 * resettable_fields_for_tab() excludes readonly/custom and owner data.
	 *
	 * @return void
	 */
	public function test_resettable_fields_exclude_owner_data(): void {
		$reg = SettingsDriver::resettable_fields_for_tab( 'registration' );

		$this->assertArrayHasKey( 'buddynext_reg_rate_limit', $reg, 'a config field is resettable.' );
		$this->assertArrayNotHasKey( 'buddynext_terms_page_id', $reg, 'a page-mapping (owner data) is NOT resettable.' );
		$this->assertArrayNotHasKey( 'buddynext_allowed_domains', $reg, 'an owner-curated list is NOT resettable.' );
	}

	/**
	 * The preview lists only the resettable options that actually differ.
	 *
	 * @return void
	 */
	public function test_preview_lists_only_changed_resettable_options(): void {
		update_option( 'buddynext_reg_rate_limit', 99 );   // resettable, changed
		update_option( 'buddynext_terms_page_id', 4242 );  // owner data, changed but protected

		$preview = SettingsDriver::tab_reset_preview( 'registration' );
		$keys    = wp_list_pluck( $preview['changes'], 'key' );

		$this->assertContains( 'buddynext_reg_rate_limit', $keys, 'a changed resettable option is previewed.' );
		$this->assertNotContains( 'buddynext_terms_page_id', $keys, 'protected owner data is never previewed.' );
	}

	/**
	 * Restoring a tab resets its changed resettable options, leaves owner data and
	 * other tabs untouched.
	 *
	 * @return void
	 */
	public function test_reset_tab_resets_resettable_and_protects_the_rest(): void {
		update_option( 'buddynext_reg_rate_limit', 99 );      // registration, resettable
		update_option( 'buddynext_terms_page_id', 4242 );     // registration, owner data
		update_option( 'buddynext_banned_words', "spam\nx" ); // moderation, owner data

		$reset = SettingsDriver::reset_tab( 'registration' );

		$this->assertContains( 'buddynext_reg_rate_limit', $reset, 'the changed resettable option was reset.' );
		$this->assertSame( 5, (int) get_option( 'buddynext_reg_rate_limit' ), 'it is back to its default.' );
		$this->assertSame( 4242, (int) get_option( 'buddynext_terms_page_id' ), 'owner data on the same tab is untouched.' );
		$this->assertSame( "spam\nx", get_option( 'buddynext_banned_words' ), 'another tab is untouched.' );
	}

	/**
	 * An option already at default is not counted as reset.
	 *
	 * @return void
	 */
	public function test_reset_is_a_noop_when_nothing_differs(): void {
		delete_option( 'buddynext_reg_rate_limit' ); // at default
		$this->assertNotContains( 'buddynext_reg_rate_limit', SettingsDriver::reset_tab( 'registration' ) );
	}

	/**
	 * A non-admin cannot run the reset handler.
	 *
	 * @return void
	 */
	public function test_non_admin_is_rejected(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		SettingsDriver::handle_restore_defaults();
	}
}
