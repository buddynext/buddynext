<?php
/**
 * Tests for ProfileService.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\ProfileService
 */
class ProfileServiceTest extends \WP_UnitTestCase {

	private ProfileService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
		$this->user_id = self::factory()->user->create(
			array(
				'display_name' => 'Alice Test',
				'user_email'   => 'alice@example.com',
			)
		);
	}

	public function test_get_fields_returns_array(): void {
		$fields = $this->service->get_fields();

		$this->assertIsArray( $fields );
	}

	public function test_create_field_and_retrieve(): void {
		$field_id = $this->service->create_field(
			array(
				'field_key'  => 'bio',
				'label'      => 'Biography',
				'type'       => 'textarea',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$this->assertIsInt( $field_id );
		$this->assertGreaterThan( 0, $field_id );

		$fields   = $this->service->get_fields();
		$all_keys = array();
		foreach ( $fields as $group ) {
			foreach ( $group['fields'] as $field ) {
				$all_keys[] = $field['field_key'];
			}
		}
		$this->assertContains( 'bio', $all_keys );
	}

	public function test_save_and_get_profile_value(): void {
		$field_id = $this->service->create_field(
			array(
				'field_key'  => 'location',
				'label'      => 'Location',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 1,
			)
		);

		$result = $this->service->save_profile(
			$this->user_id,
			array( 'location' => 'New York' )
		);

		$this->assertTrue( $result );

		$profile = $this->service->get_profile( $this->user_id, $this->user_id );

		$this->assertArrayHasKey( 'fields', $profile );
		$field_values = array_column( $profile['fields'], 'value', 'field_key' );
		$this->assertSame( 'New York', $field_values['location'] );
	}

	public function test_get_profile_includes_wp_core_fields(): void {
		$profile = $this->service->get_profile( $this->user_id, $this->user_id );

		$this->assertArrayHasKey( 'user_id', $profile );
		$this->assertArrayHasKey( 'display_name', $profile );
		$this->assertArrayHasKey( 'avatar_url', $profile );
		$this->assertSame( $this->user_id, $profile['user_id'] );
		$this->assertSame( 'Alice Test', $profile['display_name'] );
	}

	public function test_private_field_hidden_from_other_viewer(): void {
		$this->service->create_field(
			array(
				'field_key'  => 'phone',
				'label'      => 'Phone',
				'type'       => 'text',
				'visibility' => 'private',
				'group_name' => 'contact',
				'sort_order' => 0,
			)
		);

		$this->service->save_profile( $this->user_id, array( 'phone' => '555-1234' ) );

		$viewer_id = self::factory()->user->create();
		$profile   = $this->service->get_profile( $this->user_id, $viewer_id );

		$field_keys = array_column( $profile['fields'], 'field_key' );
		$this->assertNotContains( 'phone', $field_keys );
	}

	public function test_private_field_visible_to_owner(): void {
		$this->service->create_field(
			array(
				'field_key'  => 'private_note',
				'label'      => 'Private Note',
				'type'       => 'text',
				'visibility' => 'private',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$this->service->save_profile( $this->user_id, array( 'private_note' => 'secret' ) );

		$profile     = $this->service->get_profile( $this->user_id, $this->user_id );
		$field_keys  = array_column( $profile['fields'], 'field_key' );
		$this->assertContains( 'private_note', $field_keys );
	}

	public function test_save_profile_updates_existing_value(): void {
		$this->service->create_field(
			array(
				'field_key'  => 'website',
				'label'      => 'Website',
				'type'       => 'url',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 2,
			)
		);

		$this->service->save_profile( $this->user_id, array( 'website' => 'https://old.example.com' ) );
		$this->service->save_profile( $this->user_id, array( 'website' => 'https://new.example.com' ) );

		$profile      = $this->service->get_profile( $this->user_id, $this->user_id );
		$field_values = array_column( $profile['fields'], 'value', 'field_key' );
		$this->assertSame( 'https://new.example.com', $field_values['website'] );
	}

	public function test_index_user_writes_search_record(): void {
		global $wpdb;

		$this->service->index_user( $this->user_id );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'user' AND object_id = %d",
				$this->user_id
			)
		);

		$this->assertSame( 1, $count );
	}

	/**
	 * Rejected fields must be reported instead of silently claiming success: a
	 * value the validate filter rejects yields a WP_Error carrying a
	 * field => message map, while still persisting the other valid fields.
	 */
	public function test_save_profile_returns_wp_error_when_field_rejected(): void {
		$this->service->create_field(
			array(
				'field_key'  => 'nickname',
				'label'      => 'Nickname',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$reject = static function ( $result, $type, $value, $field ) {
			if ( 'nickname' === ( $field['field_key'] ?? '' ) && 'bad' === $value ) {
				return new \WP_Error( 'rejected', 'Not allowed.' );
			}
			return $result;
		};
		add_filter( 'buddynext_profile_field_validate', $reject, 10, 4 );

		$result = $this->service->save_profile( $this->user_id, array( 'nickname' => 'bad' ) );

		remove_filter( 'buddynext_profile_field_validate', $reject, 10 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertArrayHasKey( 'fields', $data );
		$this->assertArrayHasKey( 'nickname', $data['fields'] );
		$this->assertSame( 422, $data['status'] );
	}

	/**
	 * A clean save still returns the literal true contract.
	 */
	public function test_save_profile_returns_true_on_clean_save(): void {
		$this->service->create_field(
			array(
				'field_key'  => 'pronouns',
				'label'      => 'Pronouns',
				'type'       => 'text',
				'visibility' => 'public',
				'group_name' => 'general',
				'sort_order' => 0,
			)
		);

		$result = $this->service->save_profile( $this->user_id, array( 'pronouns' => 'they/them' ) );

		$this->assertTrue( $result );
	}

	/**
	 * Created fields must persist show_on_register (previously dropped from the
	 * create_field() INSERT even though the column and REST param exist).
	 */
	public function test_create_field_persists_show_on_register(): void {
		global $wpdb;

		$field_id = $this->service->create_field(
			array(
				'field_key'        => 'company',
				'label'            => 'Company',
				'type'             => 'text',
				'visibility'       => 'public',
				'group_name'       => 'general',
				'show_on_register' => 1,
				'sort_order'       => 0,
			)
		);

		$stored = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT show_on_register FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d",
				$field_id
			)
		);

		$this->assertSame( 1, $stored );
	}

	/**
	 * A searchable repeater sub-field writes ONE aggregated bn_field_{key} mirror
	 * holding every entry's value (the single-valued mirror can't be written
	 * per-entry without clobbering), so directory search matches a member by a
	 * value in any entry.
	 */
	public function test_repeater_searchable_field_writes_aggregated_mirror(): void {
		$group_id = $this->service->create_group(
			array(
				'group_key'  => 'rep_test',
				'label'      => 'Repeater Test',
				'type'       => 'repeater',
				'visibility' => 'public',
			)
		);

		$this->service->create_field(
			array(
				'group_id'      => $group_id,
				'field_key'     => 'rep_company',
				'label'         => 'Company',
				'type'          => 'text',
				'visibility'    => 'public',
				'is_searchable' => 1,
				'sort_order'    => 0,
			)
		);

		$this->service->save_profile(
			$this->user_id,
			array(
				'rep_test' => array(
					array( 'rep_company' => 'Acme Corp' ),
					array( 'rep_company' => 'Globex' ),
				),
			)
		);

		$mirror = (string) get_user_meta( $this->user_id, 'bn_field_rep_company', true );

		$this->assertStringContainsString( 'Acme Corp', $mirror );
		$this->assertStringContainsString( 'Globex', $mirror );
	}
}
