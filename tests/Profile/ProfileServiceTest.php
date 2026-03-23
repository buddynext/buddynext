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
}
