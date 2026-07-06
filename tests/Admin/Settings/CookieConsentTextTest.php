<?php
/**
 * Tests for the editable cookie-consent notice text.
 *
 * @package BuddyNext\Tests\Admin\Settings
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Settings;

use BuddyNext\Admin\Settings;
use BuddyNext\Privacy\CookieConsentService;

/**
 * Verifies the notice-text option is declared and defaulted.
 *
 * @covers \BuddyNext\Admin\Settings
 * @covers \BuddyNext\Privacy\CookieConsentService
 */
class CookieConsentTextTest extends \WP_UnitTestCase {

	/**
	 * The notice-text option is declared in the Privacy descriptors.
	 *
	 * @return void
	 */
	public function test_notice_text_key_present_in_privacy_section(): void {
		$settings = new Settings();
		$keys     = array();
		foreach ( $settings->settings_fields() as $section ) {
			foreach ( $section->fields as $field ) {
				$keys[] = $field->key;
			}
		}
		$this->assertContains( 'buddynext_cookie_consent_text', $keys );
	}

	/**
	 * The default banner message is non-empty (fallback when unset).
	 *
	 * @return void
	 */
	public function test_default_message_is_nonempty(): void {
		$this->assertNotEmpty( CookieConsentService::default_message() );
	}
}
