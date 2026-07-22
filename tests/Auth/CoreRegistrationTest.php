<?php
/**
 * Tests for the WordPress core registration form under BuddyNext's policy.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\CoreRegistration;
use BuddyNext\Core\Installer;

/**
 * BuddyNext force-enabled wp-login.php?action=register and protected none of it,
 * while also overwriting the owner's own users_can_register flag on every save.
 *
 * @covers \BuddyNext\Auth\CoreRegistration
 */
class CoreRegistrationTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case, with the policy wired.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		( new CoreRegistration() )->register();
	}

	/**
	 * We are a plugin, not a SaaS: an owner who closes registration must find it
	 * still closed. `closed` used to be unreachable in the UI, so every save of
	 * our own tab forced users_can_register back to 1 — an owner literally could
	 * not turn registration off, and turning it off in Settings -> General was
	 * silently undone by us.
	 */
	public function test_closed_mode_disables_core_registration_and_stays_disabled(): void {
		update_option( 'buddynext_reg_mode', 'closed' );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );

		// Saving the tab again must not silently re-open it.
		update_option( 'buddynext_reg_mode', 'closed' );
		$this->assertSame( '0', (string) get_option( 'users_can_register' ) );
	}

	/**
	 * The other modes still mirror through, so registration works out of the box.
	 */
	public function test_open_mode_enables_core_registration(): void {
		update_option( 'buddynext_reg_mode', 'open' );
		$this->assertSame( '1', (string) get_option( 'users_can_register' ) );
	}

	/**
	 * The core form is off by default — BuddyNext is the one front door.
	 */
	public function test_core_form_is_not_allowed_by_default(): void {
		$this->assertFalse( CoreRegistration::is_allowed() );
	}

	/**
	 * When the owner re-enables the core form, their allowlist still binds on it.
	 * Choosing a different signup UI must never mean opting out of the access
	 * policy and spam protection they configured.
	 */
	public function test_core_form_enforces_the_allowed_domain_allowlist(): void {
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'users_can_register', '1' );
		update_option( CoreRegistration::OPT_ALLOW, '1' );
		update_option( 'buddynext_allowed_domains', "acme.com\n" );

		$rejected = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@gmail.com' );
		$this->assertTrue( $rejected->has_errors() );
		$this->assertNotEmpty( $rejected->get_error_message( 'bn_reg_domain' ) );

		$allowed = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@acme.com' );
		$this->assertFalse( $allowed->has_errors(), 'an allowed domain must pass' );
	}

	/**
	 * And a closed community rejects the core form too.
	 */
	public function test_core_form_rejects_when_registration_is_closed(): void {
		update_option( CoreRegistration::OPT_ALLOW, '1' );
		update_option( 'buddynext_reg_mode', 'closed' );

		$errors = apply_filters( 'registration_errors', new \WP_Error(), 'someone', 'someone@example.com' );

		$this->assertTrue( $errors->has_errors() );
		$this->assertNotEmpty( $errors->get_error_message( 'bn_reg_closed' ) );
	}

	/**
	 * The desync warning survives on BuddyNext's OWN screens.
	 *
	 * AdminHub clears admin_notices on every BuddyNext screen so third-party setup
	 * nags do not crowd the settings UI. remove_all_actions() cannot tell a foreign
	 * nag from one of ours, so this warning vanished on the exact screen that owns
	 * the setting — and the Setup Checklist links the owner there, so the guidance
	 * disappeared at the moment they acted on it.
	 *
	 * The inline renderer is what fixes that without touching the suppression. If
	 * this fails, the owner is back to configuring invite-only with no warning.
	 *
	 * @return void
	 */
	public function test_desync_warning_renders_inline_for_the_settings_panel(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', '0' );

		$this->assertTrue( CoreRegistration::is_desynced(), 'Fixture must actually be desynced.' );

		ob_start();
		CoreRegistration::render_desync_inline();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'invitations are not working', $html );
		$this->assertStringContainsString( 'bn-notice', $html, 'Must use the in-hub notice primitive, not .notice (which is cleared).' );
		$this->assertStringContainsString(
			'options-general.php',
			$html,
			'Must say where users_can_register lives — it is core\'s, not on this screen.'
		);
	}

	/**
	 * The inline and global warnings say the SAME thing.
	 *
	 * They are rendered from one source for exactly this reason: an owner meeting
	 * two different explanations of one problem is worse off than meeting none.
	 *
	 * @return void
	 */
	public function test_inline_and_global_desync_warnings_share_one_source(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', '0' );

		$state = CoreRegistration::desync_state();
		$this->assertIsArray( $state );

		ob_start();
		CoreRegistration::render_desync_inline();
		$inline = (string) ob_get_clean();

		ob_start();
		( new CoreRegistration() )->render_desync_notice();
		$global = (string) ob_get_clean();

		// Both are escaped output, so entities have to be decoded before the copy
		// can be compared against its own source string.
		$plain = static function ( string $html ): string {
			return html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		};

		foreach ( array( $state['headline'], $state['explain'] ) as $copy ) {
			$this->assertStringContainsString( $copy, $plain( $inline ) );
			$this->assertStringContainsString( $copy, $plain( $global ) );
		}
	}

	/**
	 * Nothing is rendered when the settings agree.
	 *
	 * @return void
	 */
	public function test_no_desync_warning_when_settings_agree(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', '1' );

		$this->assertNull( CoreRegistration::desync_state() );

		ob_start();
		CoreRegistration::render_desync_inline();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * A member without manage_options is never shown owner configuration warnings.
	 *
	 * @return void
	 */
	public function test_desync_warning_is_owner_only(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		update_option( 'buddynext_reg_mode', 'invite' );
		update_option( 'users_can_register', '0' );

		ob_start();
		CoreRegistration::render_desync_inline();
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * The registration panel actually CALLS the inline renderers.
	 *
	 * The renderers passing their own tests proves nothing if nobody invokes them —
	 * which is exactly the shape of the original bug: working code that no screen
	 * reached. Asserted against the source because the panel is a private method
	 * on an admin class that needs a full screen context to render.
	 *
	 * @return void
	 */
	public function test_registration_panel_wires_both_inline_warnings(): void {
		$settings = file_get_contents( BUDDYNEXT_DIR . 'includes/Admin/Settings.php' );

		$this->assertIsString( $settings );

		$panel = substr( $settings, (int) strpos( $settings, 'private function render_tab_registration' ) );
		$panel = substr( $panel, 0, (int) strpos( $panel, 'private function render_tab_' , 10 ) );

		$this->assertStringContainsString( 'render_desync_inline', $panel, 'The registration panel must render the desync warning.' );
		$this->assertStringContainsString( 'render_terms_inline', $panel, 'The registration panel must render the consent warning.' );
	}

	/**
	 * The consent warning renders inline, and does NOT link to the screen it is on.
	 *
	 * The global version's button pointed at the registration tab — the very screen
	 * where the notice is suppressed — so following it made the message disappear.
	 * Inline, the setting is right there, so a link would send the owner in a circle.
	 *
	 * @return void
	 */
	public function test_terms_warning_renders_inline_without_a_circular_link(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		update_option( 'buddynext_require_terms', true );
		update_option( 'buddynext_terms_page_id', 0 );

		$this->assertTrue( CoreRegistration::has_terms_gap() );

		ob_start();
		CoreRegistration::render_terms_inline();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'no Terms page is set', $html );
		$this->assertStringNotContainsString( 'tab=registration', $html, 'Must not link back to the screen it is rendered on.' );

		// Resolved once a page is chosen.
		update_option( 'buddynext_terms_page_id', 99 );
		$this->assertFalse( CoreRegistration::has_terms_gap() );
	}
}
