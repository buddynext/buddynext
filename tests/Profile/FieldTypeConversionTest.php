<?php
/**
 * Changing a field's TYPE migrates existing values into the new type's format,
 * so an established (and undeletable, system) field like location can be upgraded
 * to a map without a duplicate and without losing what members already entered.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Profile\ProfileService::convert_field_values
 */
class FieldTypeConversionTest extends WP_UnitTestCase {

	private ProfileService $svc;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->svc = new ProfileService();
	}

	/**
	 * Seed a text field with a value for one user; return the field id.
	 *
	 * @param string $value The stored text value.
	 * @return int Field id.
	 */
	private function text_field_with_value( string $value ): int {
		global $wpdb;
		$gid = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}bn_profile_groups LIMIT 1" );
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array( 'group_id' => $gid, 'field_key' => 'conv_test_loc', 'label' => 'Where', 'type' => 'text', 'visibility' => 'public', 'sort_order' => 99 )
		);
		$fid = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array( 'user_id' => self::factory()->user->create(), 'field_id' => $fid, 'value' => $value )
		);
		return $fid;
	}

	/**
	 * The value of $field for its (single) member.
	 */
	private function stored_value( int $field_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d LIMIT 1", $field_id ) );
	}

	public function test_text_to_location_wraps_the_value_as_address_json(): void {
		$fid = $this->text_field_with_value( 'Lucknow, Uttar Pradesh, 226027, India' );

		$this->svc->convert_field_values( $fid, 'text', 'location' );

		$decoded = json_decode( $this->stored_value( $fid ), true );
		$this->assertIsArray( $decoded, 'The value is now JSON.' );
		$this->assertSame( 'Lucknow, Uttar Pradesh, 226027, India', $decoded['address'] ?? null, 'The address is preserved.' );
		$this->assertArrayHasKey( 'lat', $decoded );
		$this->assertNull( $decoded['lat'], 'lat is empty until the member re-saves.' );
		$this->assertArrayHasKey( 'lng', $decoded );
		$this->assertNull( $decoded['lng'], 'lng is empty until the member re-saves.' );
	}

	public function test_conversion_is_idempotent(): void {
		$fid = $this->text_field_with_value( 'Lucknow' );

		$this->svc->convert_field_values( $fid, 'text', 'location' );
		$once = $this->stored_value( $fid );
		$this->svc->convert_field_values( $fid, 'text', 'location' );
		$twice = $this->stored_value( $fid );

		$this->assertSame( $once, $twice, 'Running the conversion again must not double-wrap an already-JSON value.' );
	}

	public function test_unhandled_conversion_leaves_values_untouched(): void {
		$fid = $this->text_field_with_value( 'plain text' );

		$this->svc->convert_field_values( $fid, 'text', 'number' );

		$this->assertSame( 'plain text', $this->stored_value( $fid ), 'A conversion with no migration path leaves the value as-is.' );
	}
}
