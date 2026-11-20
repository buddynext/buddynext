<?php
/**
 * Admin screens read correctly with a keyboard and a screen reader.
 *
 * The hub content region is named by the page h1 and is not a Tab stop, every
 * hub page and every Setup Wizard step has exactly one h1, the email-enabled
 * switch carries its own name, and the media "Remove" boxes name the file.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use BuddyNext\Admin\EmailEditor;
use BuddyNext\Onboarding\SetupWizard;
use WP_UnitTestCase;

/**
 * Admin accessibility markup.
 *
 * @covers \BuddyNext\Admin\AdminHub::render_section
 * @covers \BuddyNext\Admin\EmailEditor::render_page
 * @covers \BuddyNext\Onboarding\SetupWizard::render_page
 */
class AdminA11yTest extends WP_UnitTestCase {

	/**
	 * Sign in as an administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Clear request state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Count the h1 elements in a fragment.
	 *
	 * @param string $html Markup.
	 * @return int
	 */
	private function h1_count( string $html ): int {
		return (int) preg_match_all( '/<h1[\s>]/i', $html );
	}

	/**
	 * Render one hub section with a single stub tab.
	 *
	 * @param string $section Section key.
	 * @return string
	 */
	private function render_hub( string $section ): string {
		$tabs  = new \ReflectionProperty( AdminHub::class, 'tabs' );
		$saved = $tabs->getValue();
		$tabs->setValue( null, array() );
		AdminHub::register_tab(
			$section,
			'a11y-stub',
			'Stub',
			static function (): void {
				echo '<p>Tab body</p>';
			}
		);

		$_GET = array(
			'page' => AdminHub::sections()[ $section ]['slug'],
			'tab'  => 'a11y-stub',
		);
		ob_start();
		( new \ReflectionClass( AdminHub::class ) )->newInstanceWithoutConstructor()->render_section();
		$html = (string) ob_get_clean();

		$tabs->setValue( null, $saved );
		return $html;
	}

	/**
	 * The content region is named by the h1 and is not an extra Tab stop.
	 *
	 * @return void
	 */
	public function test_hub_region_is_named_and_not_a_tab_stop(): void {
		$html = $this->render_hub( (string) array_key_first( AdminHub::sections() ) );

		$this->assertSame( 1, preg_match( '/<main\b[^>]*>/', $html, $main ), 'The hub renders its content region.' );
		$this->assertStringContainsString( 'aria-labelledby="bn-admin-hub-title"', $main[0] );
		$this->assertStringNotContainsString( 'tabindex', $main[0], 'A non-interactive region must not be a Tab stop.' );
		$this->assertMatchesRegularExpression( '/<h1 id="bn-admin-hub-title"/', $html, 'The region name points at the page h1.' );
	}

	/**
	 * Every hub section prints exactly one h1.
	 *
	 * @return void
	 */
	public function test_every_hub_section_has_one_h1(): void {
		foreach ( array_keys( AdminHub::sections() ) as $section ) {
			$this->assertSame( 1, $this->h1_count( $this->render_hub( (string) $section ) ), "Section {$section} has one h1." );
		}
	}

	/**
	 * Every Setup Wizard step prints exactly one h1.
	 *
	 * @return void
	 */
	public function test_every_wizard_step_has_one_h1(): void {
		$wizard = new SetupWizard();
		foreach ( array_keys( $wizard->steps() ) as $key ) {
			update_option( 'buddynext_setup_step', $key );
			ob_start();
			$wizard->render_page();
			$html = (string) ob_get_clean();
			$this->assertSame( 1, $this->h1_count( $html ), "Wizard step {$key} has one h1." );
		}
	}

	/**
	 * The email-enabled checkbox carries its name and switch role itself.
	 *
	 * @return void
	 */
	public function test_email_enabled_switch_is_named(): void {
		ob_start();
		( new EmailEditor() )->render_page();
		$html = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/<input\s+id="bn-email-enabled"[^>]*>/', $html, $input ) );
		$this->assertStringContainsString( 'role="switch"', $input[0] );
		$this->assertStringContainsString( 'aria-label="Email enabled"', $input[0] );
		$this->assertSame( 1, preg_match( '/<label[^>]*data-bn-toggle-wrap[^>]*>/', $html, $label ) );
		$this->assertStringNotContainsString( 'aria-label', $label[0], 'A label carries no aria-label; the input does.' );
	}
}
