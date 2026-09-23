<?php
/**
 * Tests for the system-field delete guard (bn_profile_fields.is_system).
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * System-field delete-guard behaviour.
 *
 * @covers \BuddyNext\Profile\ProfileService::delete_field
 */
class SystemFieldGuardTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ProfileService
	 */
	private ProfileService $service;

	/**
	 * Create the schema + seeds and the service under test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Return the bn_profile_fields row id for a seeded field key.
	 *
	 * @param string $field_key Field key.
	 * @return int
	 */
	private function field_id( string $field_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = %s",
				$field_key
			)
		);
	}

	/**
	 * The installer flags exactly the code-consumed spine as system fields:
	 * bio, headline, location (search/directory/hero), interests (the suggestion
	 * signal added in the interests Phase 0 migration), pronouns, and the four
	 * Work/Education repeater toggles work_current, work_end_date, edu_current,
	 * edu_end_year.
	 *
	 * pronouns joined the spine in schema v18 and this expectation was not
	 * updated with it, so the test had been failing ever since — which is worse
	 * than useless, because a permanently red guard cannot report the regression
	 * it exists to catch. The seed is correct: the hero renders pronouns by
	 * hardcoded key (ProfileService::HERO_IDENTITY_FIELDS), and a template may
	 * reference a field by name ONLY when that field is guaranteed to exist.
	 * Leaving it deletable is what made it vanishable.
	 *
	 * The four repeater toggles joined the spine for the same reason (card
	 * 10312499657): the end-date toggle JS pairs them by key (CURRENT_TOGGLE_PAIRS
	 * in profile/store.js), so deleting one and re-adding a same-labelled field
	 * mints a new key the JS never rebinds, breaking the toggle unrecoverably. A
	 * field referenced by hardcoded key must not be deletable — same argument as
	 * pronouns. This guard is deliberately brittle: re-run it after any change to
	 * the seeded spine.
	 *
	 * @return void
	 */
	public function test_seed_marks_exactly_the_loadbearing_spine_as_system(): void {
		global $wpdb;

		$system_keys = $wpdb->get_col(
			"SELECT field_key FROM {$wpdb->prefix}bn_profile_fields WHERE is_system = 1 ORDER BY field_key ASC"
		);

		$this->assertSame(
			array( 'bio', 'edu_current', 'edu_end_year', 'headline', 'interests', 'location', 'pronouns', 'work_current', 'work_end_date' ),
			$system_keys
		);
	}

	/**
	 * A pending flag-convergence correction runs even when the schema version is
	 * already current — it must not depend on an unrelated schema bump to ship.
	 *
	 * FLAG_CONVERGENCE exists to ship flag corrections independently of the schema
	 * version, but converge_seeded_field_flags() lives inside run(), which
	 * maybe_upgrade() skips when the schema already matches. Card 10312499657's fix
	 * (locking the four repeater toggles) reached a customer only because an
	 * unrelated schema bump happened to run run() that release; a flag-only release
	 * would have been silently dropped. maybe_upgrade() now also proceeds when the
	 * flag stamp is stale, so this asserts the correction lands with the schema
	 * already at its current value.
	 *
	 * @return void
	 */
	public function test_flag_convergence_runs_with_schema_already_current(): void {
		global $wpdb;
		$keys = array( 'work_current', 'work_end_date', 'edu_current', 'edu_end_year' );

		// Simulate a site already on the current schema, whose flag correction has
		// not been applied and whose four toggles are still unlocked.
		$live_schema = (int) ( new \ReflectionClass( Installer::class ) )->getConstant( 'SCHEMA_VERSION' );
		update_option( 'buddynext_schema_version', $live_schema );
		update_option( 'buddynext_profile_flag_convergence', 'stale-stamp' );
		delete_option( 'buddynext_schema_failure' );
		$in = "'" . implode( "','", $keys ) . "'";
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_profile_fields SET is_system = 0 WHERE field_key IN ($in)" ); // phpcs:ignore WordPress.DB

		Installer::maybe_upgrade();

		$locked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_fields WHERE field_key IN ($in) AND is_system = 1" ); // phpcs:ignore WordPress.DB
		$this->assertSame( 4, $locked, 'Flag convergence must run even when the schema version already matches.' );
	}

	/**
	 * Deleting a system field returns WP_Error('system_field') with 403.
	 *
	 * @return void
	 */
	public function test_delete_field_refuses_system_field(): void {
		global $wpdb;

		$bio_id = $this->field_id( 'bio' );
		$this->assertGreaterThan( 0, $bio_id );

		$result = $this->service->delete_field( $bio_id );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'system_field', $result->get_error_code() );
		$this->assertSame( 403, (int) ( $result->get_error_data()['status'] ?? 0 ) );

		// The definition row must survive the refused delete.
		$this->assertSame( $bio_id, $this->field_id( 'bio' ) );
	}

	/**
	 * A refused delete must leave member values untouched.
	 *
	 * @return void
	 */
	public function test_delete_field_refused_keeps_member_values(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$this->service->save_profile( $user_id, array( 'bio' => 'Keep me.' ) );

		$this->service->delete_field( $this->field_id( 'bio' ) );

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE user_id = %d AND field_id = %d",
				$user_id,
				$this->field_id( 'bio' )
			)
		);

		$this->assertSame( 'Keep me.', $value );
	}

	/**
	 * Non-system fields keep deleting normally.
	 *
	 * @return void
	 */
	public function test_delete_field_still_deletes_regular_field(): void {
		$field_id = $this->service->create_field(
			array(
				'field_key'  => 'disposable',
				'label'      => 'Disposable',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 9,
			)
		);

		$result = $this->service->delete_field( (int) $field_id );

		$this->assertTrue( $result );
		$this->assertSame( 0, $this->field_id( 'disposable' ) );
	}

	/**
	 * The group-delete cascade bypasses the field guard via the force path.
	 *
	 * @return void
	 */
	public function test_group_delete_cascade_force_deletes_flagged_fields(): void {
		global $wpdb;

		// A system-FLAGGED field inside a non-system group: deleting the group
		// (allowed) must still cascade through the field via the force path.
		$field_id = $this->service->create_field(
			array(
				'field_key'  => 'stray_system',
				'label'      => 'Stray System',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'temp_group',
				'sort_order' => 1,
			)
		);
		$wpdb->update(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'is_system' => 1 ),
			array( 'id' => (int) $field_id ),
			array( '%d' ),
			array( '%d' )
		);

		$group_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT group_id FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d",
				(int) $field_id
			)
		);

		$this->assertTrue( $this->service->delete_group( $group_id ) );
		$this->assertSame( 0, $this->field_id( 'stray_system' ) );
	}
}
