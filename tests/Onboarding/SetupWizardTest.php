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
	 * Calling advance() writes the new step KEY to wp_options.
	 *
	 * Progress is persisted as the step key (not a positional integer) so that
	 * inserting or removing a step never shifts an in-flight wizard.
	 */
	public function test_advance_saves_step_to_options(): void {
		$this->wizard->advance();
		$this->assertSame( 'registration', get_option( 'buddynext_setup_step' ) );
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
	 * Calling advance() more times than there are steps clamps at the last step.
	 */
	public function test_advance_does_not_exceed_max_step(): void {
		for ( $i = 0; $i < $this->wizard->step_count() + 5; $i++ ) {
			$this->wizard->advance();
		}
		$this->assertSame( $this->wizard->step_count(), $this->wizard->get_current_step() );
	}

	/**
	 * A legacy positional integer left in the step option resumes at the right
	 * key (back-compat for installs saved mid-wizard on the old scheme).
	 */
	public function test_legacy_numeric_step_maps_to_key(): void {
		update_option( 'buddynext_setup_step', 2 );
		$this->assertSame( 'registration', $this->wizard->current_step_key() );
		$this->assertSame( 2, $this->wizard->get_current_step() );
	}

	/**
	 * The step list is filterable: an add-on can append a keyed step.
	 */
	public function test_steps_are_filterable(): void {
		$cb = function ( array $steps ): array {
			$steps['my_addon'] = array(
				'label'  => 'My Addon',
				'render' => static function (): void {},
				'save'   => null,
			);
			return $steps;
		};
		add_filter( 'buddynext_setup_wizard_steps', $cb );

		$this->assertArrayHasKey( 'my_addon', $this->wizard->steps() );

		remove_filter( 'buddynext_setup_wizard_steps', $cb );
	}

	/**
	 * Return the private preset library.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function presets(): array {
		$method = new \ReflectionMethod( $this->wizard, 'get_profile_group_presets' );
		$method->setAccessible( true );

		return (array) $method->invoke( $this->wizard );
	}

	/**
	 * G7 (card 10055921163): every preset field type must exist in the
	 * FieldType registry — no pseudo-types that silently degrade to text.
	 */
	public function test_preset_field_types_are_all_registered(): void {
		$registered = array_keys( \BuddyNext\Profile\FieldType::types() );

		foreach ( $this->presets() as $preset_key => $preset ) {
			foreach ( (array) $preset['fields'] as $field ) {
				$this->assertContains(
					$field[2],
					$registered,
					"Preset {$preset_key} field {$field[0]} declares unregistered type {$field[2]}."
				);
			}
		}
	}

	/**
	 * G7: the wizard presets and the Installer seed must produce ONE canonical
	 * schema — every preset field key must exist in the seeded
	 * bn_profile_fields with the exact same type.
	 */
	public function test_preset_fields_converge_with_installer_seed(): void {
		global $wpdb;

		$seeded = array();
		$rows   = $wpdb->get_results(
			"SELECT field_key, type FROM {$wpdb->prefix}bn_profile_fields",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$seeded[ (string) $row['field_key'] ] = (string) $row['type'];
		}

		foreach ( $this->presets() as $preset_key => $preset ) {
			foreach ( (array) $preset['fields'] as $field ) {
				$this->assertArrayHasKey(
					$field[0],
					$seeded,
					"Preset {$preset_key} field key {$field[0]} is not in the Installer seed (two provisioning paths, two schemas)."
				);
				$this->assertSame(
					$seeded[ $field[0] ],
					$field[2],
					"Preset {$preset_key} field {$field[0]} type diverges from the Installer seed."
				);
			}
		}
	}

	/**
	 * G7: legacy pseudo-typed rows from old wizard runs are converged to
	 * registered types by the v18 migration (values preserved).
	 */
	public function test_legacy_pseudo_type_rows_are_converged(): void {
		global $wpdb;

		$legacy = array(
			'legacy_social'    => array( 'social', 'url' ),
			'legacy_toggle'    => array( 'toggle', 'boolean' ),
			'legacy_daterange' => array( 'daterange', 'text' ),
		);

		$group_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups ORDER BY id ASC LIMIT 1" );
		foreach ( $legacy as $key => list( $old_type ) ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_fields',
				array(
					'group_id'  => $group_id,
					'field_key' => $key,
					'label'     => $key,
					'type'      => $old_type,
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}

		$migrate = new \ReflectionMethod( Installer::class, 'maybe_migrate_wizard_preset_types' );
		$migrate->setAccessible( true );
		$migrate->invoke( null );

		foreach ( $legacy as $key => list( , $new_type ) ) {
			$this->assertSame(
				$new_type,
				(string) $wpdb->get_var(
					$wpdb->prepare( "SELECT type FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s", $key )
				)
			);
		}
	}
}
