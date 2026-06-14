<?php
/**
 * Tests for ProfileService::get_field_key.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\ProfileService::get_field_key
 */
class FieldKeyTest extends \WP_UnitTestCase {

	private ProfileService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	public function test_get_field_key_returns_key(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'  => 1,
				'field_key' => 'favourite_colour',
				'label'     => 'Favourite Colour',
				'type'      => 'text',
			)
		);
		$id = (int) $wpdb->insert_id;

		$this->assertSame( 'favourite_colour', $this->service->get_field_key( $id ) );
	}

	public function test_get_field_key_empty_for_missing(): void {
		$this->assertSame( '', $this->service->get_field_key( 999999 ) );
		$this->assertSame( '', $this->service->get_field_key( 0 ) );
	}
}
