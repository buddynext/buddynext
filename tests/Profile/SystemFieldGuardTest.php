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
	 * The installer flags exactly bio/headline/location as system fields.
	 *
	 * @return void
	 */
	public function test_seed_marks_exactly_the_loadbearing_trio_as_system(): void {
		global $wpdb;

		$system_keys = $wpdb->get_col(
			"SELECT field_key FROM {$wpdb->prefix}bn_profile_fields WHERE is_system = 1 ORDER BY field_key ASC"
		);

		$this->assertSame( array( 'bio', 'headline', 'location' ), $system_keys );
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
