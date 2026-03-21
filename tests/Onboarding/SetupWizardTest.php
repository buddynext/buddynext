<?php
/**
 * Tests for the admin setup wizard.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\SetupWizard;

/**
 * Verifies SetupWizard state transitions and option persistence.
 *
 * @covers \BuddyNext\Onboarding\SetupWizard
 */
class SetupWizardTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var SetupWizard
	 */
	private SetupWizard $wizard;

	/**
	 * Fresh wizard and cleared options before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->wizard = new SetupWizard();
		delete_option( 'buddynext_setup_complete' );
		delete_option( 'buddynext_setup_step' );
	}

	/**
	 * Wizard is not complete before finish() is called.
	 */
	public function test_is_complete_returns_false_before_finish(): void {
		$this->assertFalse( $this->wizard->is_complete() );
	}

	/**
	 * Default current step is 1.
	 */
	public function test_get_current_step_starts_at_one(): void {
		$this->assertSame( 1, $this->wizard->get_current_step() );
	}

	/**
	 * Calling advance() moves to the next step.
	 */
	public function test_advance_increments_step(): void {
		$this->wizard->advance();
		$this->assertSame( 2, $this->wizard->get_current_step() );
	}

	/**
	 * Calling advance() writes the new step to wp_options.
	 */
	public function test_advance_saves_step_to_options(): void {
		$this->wizard->advance();
		$this->assertSame( 2, (int) get_option( 'buddynext_setup_step' ) );
	}

	/**
	 * Calling finish() marks the wizard as complete.
	 */
	public function test_finish_marks_complete(): void {
		$this->wizard->finish();
		$this->assertTrue( $this->wizard->is_complete() );
	}

	/**
	 * Calling finish() sets buddynext_setup_complete to '1'.
	 */
	public function test_finish_sets_option(): void {
		$this->wizard->finish();
		$this->assertSame( '1', get_option( 'buddynext_setup_complete' ) );
	}

	/**
	 * Calling finish() fires the buddynext_setup_complete action.
	 */
	public function test_finish_fires_action(): void {
		$fired = false;
		add_action(
			'buddynext_setup_complete',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);
		$this->wizard->finish();
		$this->assertTrue( $fired );
	}

	/**
	 * Calling save_settings() persists the site_name to wp_options.
	 */
	public function test_save_settings_persists_site_name(): void {
		$this->wizard->save_settings( array( 'site_name' => 'My Community' ) );
		$this->assertSame( 'My Community', get_option( 'buddynext_site_name' ) );
	}

	/**
	 * Calling save_settings() passes brand_color through sanitize_hex_color.
	 */
	public function test_save_settings_sanitizes_brand_color(): void {
		$this->wizard->save_settings( array( 'brand_color' => '#00aabb' ) );
		$this->assertSame( '#00aabb', get_option( 'buddynext_brand_color' ) );
	}

	/**
	 * Calling save_settings() silently ignores unknown keys.
	 */
	public function test_save_settings_ignores_unknown_keys(): void {
		$this->wizard->save_settings( array( 'evil_key' => 'payload' ) );
		$this->assertFalse( get_option( 'buddynext_evil_key' ) );
	}

	/**
	 * Calling advance() more than TOTAL_STEPS times does not exceed the max.
	 */
	public function test_advance_does_not_exceed_max_step(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			$this->wizard->advance();
		}
		$this->assertLessThanOrEqual( SetupWizard::TOTAL_STEPS, $this->wizard->get_current_step() );
	}
}
