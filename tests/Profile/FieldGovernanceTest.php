<?php
/**
 * Tests for the profile-field freedom pass (governance plan §4 + G3):
 * ungated preset groups, impact-confirm counts, batched value purge,
 * and is_required enforcement on save.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Admin\Members\ProfileFieldsManager;
use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * Freedom-pass behaviour: group ungating, delete impact, batched purge,
 * required-on-save.
 *
 * @covers \BuddyNext\Profile\ProfileService
 */
class FieldGovernanceTest extends \WP_UnitTestCase {

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
	 * Return the bn_profile_groups row for a group key.
	 *
	 * @param string $group_key Group key.
	 * @return array<string, mixed>|null
	 */
	private function group_row( string $group_key ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, is_system FROM {$wpdb->prefix}bn_profile_groups WHERE group_key = %s",
				$group_key
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	// ── §4.1 Ungated preset groups ────────────────────────────────────────────

	/**
	 * The seeder leaves exactly basic_info, skills and interests as system
	 * groups; the showcase sections are ungated.
	 *
	 * @return void
	 */
	public function test_seed_ungates_the_preset_groups(): void {
		global $wpdb;

		$system_groups = $wpdb->get_col(
			"SELECT group_key FROM {$wpdb->prefix}bn_profile_groups WHERE is_system = 1 ORDER BY group_key ASC"
		);

		$this->assertSame( array( 'basic_info', 'interests', 'skills' ), $system_groups );

		foreach ( array( 'social_links', 'work_experience', 'education' ) as $ungated ) {
			$row = $this->group_row( $ungated );
			$this->assertNotNull( $row, "Seeded group {$ungated} missing." );
			$this->assertSame( 0, (int) $row['is_system'], "Group {$ungated} must be deletable." );
		}
	}

	/**
	 * An existing install with the legacy is_system=1 preset groups is
	 * converged by the v18 seeder UPDATE (idempotent, runs inside run()).
	 *
	 * @return void
	 */
	public function test_upgrade_ungates_legacy_system_preset_groups(): void {
		global $wpdb;

		// Regress the rows to the pre-v18 shape.
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_profile_groups SET is_system = 1
			  WHERE group_key IN ('social_links', 'work_experience', 'education')"
		);

		Installer::run();

		foreach ( array( 'social_links', 'work_experience', 'education' ) as $ungated ) {
			$this->assertSame( 0, (int) $this->group_row( $ungated )['is_system'] );
		}

		// The spine groups stay protected.
		$this->assertSame( 1, (int) $this->group_row( 'basic_info' )['is_system'] );
		$this->assertSame( 1, (int) $this->group_row( 'interests' )['is_system'] );
	}

	/**
	 * Group delete now works for an ungated preset group and cascades its
	 * fields; system groups still refuse.
	 *
	 * @return void
	 */
	public function test_ungated_preset_group_deletes_and_system_groups_refuse(): void {
		global $wpdb;

		$education_id = (int) $this->group_row( 'education' )['id'];
		$this->assertTrue( $this->service->delete_group( $education_id ) );
		$this->assertNull( $this->group_row( 'education' ) );

		$remaining_fields = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_fields WHERE group_id = %d",
				$education_id
			)
		);
		$this->assertSame( 0, $remaining_fields );

		foreach ( array( 'basic_info', 'interests' ) as $protected ) {
			$result = $this->service->delete_group( (int) $this->group_row( $protected )['id'] );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'system_group', $result->get_error_code() );
		}
	}

	// ── §4.2 Impact-confirm counts ────────────────────────────────────────────

	/**
	 * Impact counts count DISTINCT members per field and aggregate per group
	 * (count_users_with_field_values + value_user_counts).
	 *
	 * @return void
	 */
	public function test_impact_counts_count_distinct_members(): void {
		$user_a = self::factory()->user->create();
		$user_b = self::factory()->user->create();

		$this->service->save_profile( $user_a, array( 'location' => 'Lisbon' ) );
		$this->service->save_profile( $user_b, array( 'location' => 'Porto' ) );
		$this->service->save_profile(
			$user_a,
			array(
				'work_experience' => array(
					0 => array( 'work_company' => 'Acme' ),
					1 => array( 'work_company' => 'Globex' ),
				),
			)
		);

		global $wpdb;
		$location_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'location'" );
		$company_id  = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'work_company'" );
		$work_gid    = (int) $this->group_row( 'work_experience' )['id'];

		$this->assertSame( 2, $this->service->count_users_with_field_values( array( $location_id ) ) );
		// Two entries, one member — DISTINCT must collapse them.
		$this->assertSame( 1, $this->service->count_users_with_field_values( array( $company_id ) ) );

		$counts = $this->service->value_user_counts();
		$this->assertSame( 2, $counts['fields'][ $location_id ] ?? 0 );
		$this->assertSame( 1, $counts['fields'][ $company_id ] ?? 0 );
		$this->assertSame( 1, $counts['groups'][ $work_gid ] ?? 0 );

		$this->assertSame( 0, $this->service->count_users_with_field_values( array() ) );
	}

	/**
	 * The admin type-to-confirm token accepts the item name or DELETE,
	 * case-insensitively, and rejects everything else.
	 *
	 * @return void
	 */
	public function test_confirm_text_matches_name_or_delete(): void {
		$manager = new ProfileFieldsManager();
		$method  = new \ReflectionMethod( $manager, 'confirm_text_matches' );
		$method->setAccessible( true );

		$cases = array(
			array( 'Education', true ),
			array( 'education', true ),
			array( '  Education  ', true ),
			array( 'DELETE', true ),
			array( 'delete', true ),
			array( 'Educ', false ),
			array( '', false ),
		);

		foreach ( $cases as list( $input, $expected ) ) {
			$_POST['bn_confirm_text'] = $input;
			$this->assertSame( $expected, $method->invoke( $manager, 'Education' ), "Input: '{$input}'" );
		}

		unset( $_POST['bn_confirm_text'] );
	}

	// ── §4.3 Batched purge ────────────────────────────────────────────────────

	/**
	 * Deleting a field removes the definition immediately and the stored values
	 * are purged in bounded batches (async when Action Scheduler is live,
	 * inline otherwise) — never left behind.
	 *
	 * @return void
	 */
	public function test_delete_field_purges_values_in_batches(): void {
		global $wpdb;

		$field_id = (int) $this->service->create_field(
			array(
				'group_name' => 'purge_group',
				'field_key'  => 'purge_me',
				'label'      => 'Purge Me',
				'type'       => 'text',
			)
		);

		for ( $i = 1; $i <= 3; $i++ ) {
			$user_id = self::factory()->user->create();
			$this->service->save_profile( $user_id, array( 'purge_me' => "value {$i}" ) );
		}

		$this->assertSame( 3, $this->service->count_users_with_field_values( array( $field_id ) ) );

		$this->assertTrue( $this->service->delete_field( $field_id ) );

		// The definition row is gone synchronously — the admin row and every
		// reader (INNER JOIN on the definition) drop the field immediately.
		$this->assertSame(
			0,
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'purge_me'" )
		);

		// Drain the batched worker (covers both the AS-scheduled path — by
		// running the worker directly — and the inline fallback, which has
		// already finished when AS is absent).
		$guard = 0;
		while ( $this->service->purge_field_values( $field_id ) > 0 && $guard < 10 ) {
			++$guard;
		}

		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d", $field_id )
		);
		$this->assertSame( 0, $remaining );
	}

	/**
	 * The purge worker deletes at most one bounded batch per call.
	 *
	 * @return void
	 */
	public function test_purge_field_values_is_bounded_per_call(): void {
		global $wpdb;

		// Orphan rows on a synthetic field id — the worker must not need the
		// definition (it runs after the definition is gone).
		$field_id = 987654;
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert(
				$wpdb->prefix . 'bn_profile_values',
				array(
					'user_id'     => $i + 1,
					'field_id'    => $field_id,
					'entry_index' => 0,
					'value'       => 'x',
				),
				array( '%d', '%d', '%d', '%s' )
			);
		}

		$deleted = $this->service->purge_field_values( $field_id );
		$this->assertSame( 5, $deleted );
		$this->assertSame( 0, $this->service->purge_field_values( $field_id ) );
		$this->assertSame( 0, $this->service->purge_field_values( 0 ) );
	}

	// ── G3 is_required enforced on save ──────────────────────────────────────

	/**
	 * Submitting an empty value for a required flat field is rejected with a
	 * per-field error; the stored value is never cleared and sibling fields
	 * still persist.
	 *
	 * @return void
	 */
	public function test_save_profile_rejects_empty_required_flat_field(): void {
		global $wpdb;

		$this->service->update_field(
			(int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'location'" ),
			array( 'is_required' => 1 )
		);

		$user_id = self::factory()->user->create();
		$this->assertTrue( $this->service->save_profile( $user_id, array( 'location' => 'Berlin' ) ) );

		$result = $this->service->save_profile(
			$user_id,
			array(
				'location' => '',
				'pronouns' => 'they/them',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'profile_fields_invalid', $result->get_error_code() );

		$fields = (array) ( $result->get_error_data()['fields'] ?? array() );
		$this->assertArrayHasKey( 'location', $fields );
		$this->assertStringContainsString( 'required', strtolower( (string) $fields['location'] ) );

		// The stored value survives the rejected clear; the valid sibling saved.
		$profile = $this->service->get_profile( $user_id, $user_id );
		$values  = array();
		foreach ( $profile['fields'] as $field ) {
			$values[ $field['field_key'] ] = $field['value'];
		}
		$this->assertSame( 'Berlin', $values['location'] );
		$this->assertSame( 'they/them', $values['pronouns'] );
	}

	/**
	 * An omitted required field is a partial update, not an error — the
	 * registration path (which submits only its own opted-in fields) is
	 * unchanged.
	 *
	 * @return void
	 */
	public function test_save_profile_allows_omitted_required_field(): void {
		global $wpdb;

		$this->service->update_field(
			(int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'location'" ),
			array( 'is_required' => 1 )
		);

		$user_id = self::factory()->user->create();
		$result  = $this->service->save_profile( $user_id, array( 'pronouns' => 'she/her' ) );

		$this->assertTrue( $result );
	}

	/**
	 * A required repeater sub-field submitted empty is rejected with the
	 * composite entry key; the entry's valid sub-fields still persist.
	 *
	 * @return void
	 */
	public function test_save_profile_rejects_empty_required_repeater_subfield(): void {
		global $wpdb;

		$this->service->update_field(
			(int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_fields WHERE field_key = 'work_company'" ),
			array( 'is_required' => 1 )
		);

		$user_id = self::factory()->user->create();
		$result  = $this->service->save_profile(
			$user_id,
			array(
				'work_experience' => array(
					0 => array(
						'work_company' => '',
						'work_title'   => 'Engineer',
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$fields = (array) ( $result->get_error_data()['fields'] ?? array() );
		$this->assertArrayHasKey( 'work_experience[0][work_company]', $fields );
	}
}
