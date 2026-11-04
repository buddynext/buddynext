<?php
/**
 * Field width and group intro (GitHub #217).
 *
 * An owner can give a field a width on the edit and sign-up forms (auto / half /
 * full) and a group an intro shown above its fields. Auto must keep the old
 * by-type layout, so a site that never sets either renders exactly as before.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\FieldType::sanitize_width
 * @covers \BuddyNext\Profile\FieldType::is_full_width
 * @covers \BuddyNext\Profile\ProfileService
 */
class FieldLayoutTest extends \WP_UnitTestCase {

	private ProfileService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Auto keeps the by-type rule; an explicit width wins; junk is Auto.
	 */
	public function test_width_rule(): void {
		$this->assertSame( 'auto', FieldType::sanitize_width( 'wide' ) );
		$this->assertSame( 'auto', FieldType::sanitize_width( null ) );
		$this->assertSame( 'half', FieldType::sanitize_width( 'HALF' ) );

		$this->assertTrue( FieldType::is_full_width( array( 'type' => 'textarea' ) ), 'Auto paragraph: full row, as before.' );
		$this->assertFalse( FieldType::is_full_width( array( 'type' => 'text' ) ), 'Auto text: half row, as before.' );
		$this->assertTrue(
			FieldType::is_full_width(
				array(
					'type'  => 'text',
					'width' => 'full',
				)
			)
		);
		$this->assertFalse(
			FieldType::is_full_width(
				array(
					'type'  => 'textarea',
					'width' => 'half',
				)
			)
		);
	}

	/**
	 * Width and intro are stored, read back on every path and updatable; a new
	 * field with no width is Auto.
	 */
	public function test_width_and_intro_round_trip(): void {
		$gid  = $this->service->create_group(
			array(
				'group_key' => 'qa_layout_address',
				'label'     => 'Address',
				'intro'     => "Only staff see these.\nUsed for administration.",
			)
		);
		$zip  = $this->service->create_field(
			array(
				'group_id'         => $gid,
				'field_key'        => 'qa_layout_zip',
				'label'            => 'ZIP',
				'type'             => 'text',
				'width'            => 'half',
				'show_on_register' => 1,
			)
		);
		$city = $this->service->create_field(
			array(
				'group_id'  => $gid,
				'field_key' => 'qa_layout_city',
				'label'     => 'City',
				'type'      => 'text',
			)
		);

		$this->assertSame( 'half', $this->service->get_field( $zip )['width'] );
		$this->assertSame( 'auto', $this->service->get_field( $city )['width'], 'No width given means Auto.' );
		$this->assertSame( "Only staff see these.\nUsed for administration.", $this->service->get_group( $gid )['intro'] );

		$tree = array_values( array_filter( $this->service->get_fields(), static fn( $g ) => (int) $g['id'] === $gid ) );
		$this->assertSame( "Only staff see these.\nUsed for administration.", $tree[0]['intro'], 'get_fields() carries the intro.' );
		$this->assertSame( array( 'half', 'auto' ), array_column( $tree[0]['fields'], 'width' ), 'get_fields() carries each width (through normalize_field_row).' );

		$groups = array_values( array_filter( $this->service->get_groups(), static fn( $g ) => (int) $g['id'] === $gid ) );
		$this->assertSame( "Only staff see these.\nUsed for administration.", $groups[0]['intro'] );

		$reg = array_values( array_filter( $this->service->get_registration_fields(), static fn( $f ) => 'qa_layout_zip' === $f['field_key'] ) );
		$this->assertSame( 'half', $reg[0]['width'], 'Sign-up fields carry the width.' );
		$this->assertSame( "Only staff see these.\nUsed for administration.", $reg[0]['group_intro'], 'Sign-up fields carry their group intro.' );

		$this->assertTrue( $this->service->update_field( $zip, array( 'width' => 'full' ) ) );
		$this->assertSame( 'full', $this->service->get_field( $zip )['width'] );
		$this->service->update_field( $zip, array( 'width' => 'wide' ) );
		$this->assertSame( 'auto', $this->service->get_field( $zip )['width'], 'An unknown width is stored as Auto.' );

		$this->service->update_group( $gid, array( 'intro' => '' ) );
		$this->assertSame( '', (string) $this->service->get_group( $gid )['intro'], 'An empty intro clears it.' );
	}
}
