<?php
/**
 * An invalid value written through raw space meta must never be stored as a
 * WP_Error, and a row already holding one must read as unset.
 *
 * Regression cover for card 10335421251: the register_meta sanitize_callback
 * returned FieldType::sanitize()'s WP_Error, WordPress serialized it into
 * bn_space_meta, and buddynext_get_space_field() then fatally cast it to string,
 * taking the whole space page down (HTTP 500).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Invalid space-field meta degrades to the field default, never a fatal.
 *
 * @covers \BuddyNext\Spaces\SpaceFieldRegistry
 */
class SpaceFieldInvalidMetaTest extends WP_UnitTestCase {

	/** @var int */
	private $space = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$owner       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->space = (int) ( new SpaceService() )->create(
			$owner,
			array( 'name' => 'Color', 'slug' => 'bad-color', 'type' => 'public' )
		);
	}

	/**
	 * A raw meta write of an invalid color stores '' rather than a WP_Error.
	 *
	 * @return void
	 */
	public function test_invalid_raw_write_is_not_stored_as_error(): void {
		update_metadata( 'bn_space', $this->space, 'brand_color', 'not-a-color' );

		$this->assertSame( '', get_metadata( 'bn_space', $this->space, 'brand_color', true ) );
		$this->assertSame( '', buddynext_get_space_field( $this->space, 'brand_color' ) );
	}

	/**
	 * A row corrupted before the fix (serialized WP_Error) reads as unset.
	 *
	 * @return void
	 */
	public function test_legacy_error_row_reads_as_unset(): void {
		global $wpdb;
		update_metadata( 'bn_space', $this->space, 'brand_color', '#ff5500' );
		$wpdb->update(
			$wpdb->prefix . 'bn_space_meta',
			array( 'meta_value' => serialize( new \WP_Error( 'bn_invalid_color', 'x' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- reproduces the pre-fix stored shape.
			array(
				'bn_space_id' => $this->space,
				'meta_key'    => 'brand_color', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);
		wp_cache_flush();

		$this->assertSame( '', buddynext_get_space_field( $this->space, 'brand_color' ) );
	}
}
