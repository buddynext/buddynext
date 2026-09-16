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

	/**
	 * Editing the privacy policy page changes the version visitors acknowledge.
	 *
	 * The notice always renders (page caches stay correct) and the browser
	 * hides it only when the cookie holds this version, so a new version is
	 * what shows it to everyone again.
	 *
	 * @return void
	 */
	public function test_policy_edit_changes_notice_version(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'wp_page_for_privacy_policy', $page_id );
		$service = new CookieConsentService();

		$_COOKIE['bn_cookie_consent'] = 'anything';
		$before                       = $this->rendered_version( $service );
		unset( $_COOKIE['bn_cookie_consent'] );
		$this->assertNotSame( '', $before, 'The notice renders even when a consent cookie exists.' );

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2030-01-01 00:00:00' ), array( 'ID' => $page_id ) );
		clean_post_cache( $page_id );

		$this->assertNotSame( $before, $this->rendered_version( $service ), 'An edited policy must change the version.' );
	}

	/**
	 * Version attribute printed by render(), or '' when nothing rendered.
	 *
	 * @param CookieConsentService $service Service under test.
	 * @return string
	 */
	private function rendered_version( CookieConsentService $service ): string {
		ob_start();
		$service->render();
		return preg_match( '/data-cookie-version="([a-f0-9]+)"/', (string) ob_get_clean(), $m ) ? $m[1] : '';
	}
}
