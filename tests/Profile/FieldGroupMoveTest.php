<?php
/**
 * Tests for moving a profile field between groups.
 *
 * A field's values key on (user_id, field_id, entry_index) and never on the
 * group, so a move is normally pure metadata. The one exception is the two
 * groups disagreeing about what entry_index MEANS, which is the whole reason
 * field_move_blocker() exists.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\ProfileService;

/**
 * @covers \BuddyNext\Profile\ProfileService::update_field
 * @covers \BuddyNext\Profile\ProfileService::field_move_blocker
 */
class FieldGroupMoveTest extends \WP_UnitTestCase {

	private ProfileService $service;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ProfileService();
	}

	/**
	 * Create a group and return its id.
	 *
	 * @param string $key  Group key.
	 * @param string $type flat|repeater.
	 * @return int
	 */
	private function make_group( string $key, string $type ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_groups',
			array(
				'group_key' => $key,
				'label'     => ucfirst( $key ),
				'type'      => $type,
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Create a field in a group and return its id.
	 *
	 * @param int    $group_id Owning group.
	 * @param string $key      Field key.
	 * @return int
	 */
	private function make_field( int $group_id, string $key ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_fields',
			array(
				'group_id'  => $group_id,
				'field_key' => $key,
				'label'     => ucfirst( $key ),
				'type'      => 'text',
			),
			array( '%d', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Store one value for a member at a given entry index.
	 *
	 * @param int    $user_id  Member.
	 * @param int    $field_id Field.
	 * @param int    $index    Entry index.
	 * @param string $value    Stored value.
	 * @return void
	 */
	private function store_value( int $user_id, int $field_id, int $index, string $value ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_profile_values',
			array(
				'user_id'     => $user_id,
				'field_id'    => $field_id,
				'entry_index' => $index,
				'value'       => $value,
			),
			array( '%d', '%d', '%d', '%s' )
		);
	}

	/**
	 * Current group of a field, read straight from the table.
	 *
	 * @param int $field_id Field.
	 * @return int
	 */
	private function group_of( int $field_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT group_id FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id )
		);
	}

	/**
	 * The customer's actual report: a field would not move between groups.
	 */
	public function test_field_moves_between_groups_of_the_same_type(): void {
		$from  = $this->make_group( 'grp_from', 'flat' );
		$to    = $this->make_group( 'grp_to', 'flat' );
		$field = $this->make_field( $from, 'mover' );

		$this->assertTrue( $this->service->update_field( $field, array( 'group_id' => $to ) ) );
		$this->assertSame( $to, $this->group_of( $field ) );
	}

	/**
	 * A move must not disturb stored values — they key on field_id, not group.
	 */
	public function test_stored_values_survive_a_move(): void {
		global $wpdb;

		$from  = $this->make_group( 'vals_from', 'flat' );
		$to    = $this->make_group( 'vals_to', 'flat' );
		$field = $this->make_field( $from, 'keeps_values' );

		$this->store_value( 11, $field, 0, 'eleven' );
		$this->store_value( 22, $field, 0, 'twenty-two' );

		$this->service->update_field( $field, array( 'group_id' => $to ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$values = $wpdb->get_col(
			$wpdb->prepare( "SELECT value FROM {$wpdb->prefix}bn_profile_values WHERE field_id = %d ORDER BY user_id", $field )
		);

		$this->assertSame( array( 'eleven', 'twenty-two' ), $values );
	}

	/**
	 * A group that does not exist is refused rather than written.
	 */
	public function test_move_to_a_missing_group_is_refused(): void {
		$from  = $this->make_group( 'ghost_from', 'flat' );
		$field = $this->make_field( $from, 'ghost_mover' );

		$result = $this->service->update_field( $field, array( 'group_id' => 999999 ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bn_group_not_found', $result->get_error_code() );
		$this->assertSame( $from, $this->group_of( $field ), 'The field must not move.' );
	}

	/**
	 * repeater -> flat with multi-entry data is refused: a flat group renders
	 * entry 0 only, so entries 1..n would stop appearing while still sitting in
	 * the table. The member sees data vanish and the owner sees no error.
	 */
	public function test_repeater_to_flat_is_refused_when_members_have_extra_entries(): void {
		$repeater = $this->make_group( 'rep_lossy', 'repeater' );
		$flat     = $this->make_group( 'flat_target', 'flat' );
		$field    = $this->make_field( $repeater, 'lossy_mover' );

		$this->store_value( 31, $field, 0, 'first job' );
		$this->store_value( 31, $field, 1, 'second job' );
		$this->store_value( 32, $field, 0, 'first job' );
		$this->store_value( 32, $field, 1, 'second job' );

		$result = $this->service->update_field( $field, array( 'group_id' => $flat ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bn_field_move_would_hide_entries', $result->get_error_code() );
		$this->assertSame( 2, $result->get_error_data()['affected_members'] );
		$this->assertSame( $repeater, $this->group_of( $field ), 'The field must not move.' );
	}

	/**
	 * The same repeater -> flat move is ALLOWED when nobody actually has a
	 * second entry.
	 *
	 * This is the guard's mutation test: if it blocked on the group's TYPE
	 * rather than on the data, this would fail — and the test above would still
	 * pass, so the pair is what proves the rule is data-driven.
	 */
	public function test_repeater_to_flat_is_allowed_when_no_one_has_extra_entries(): void {
		$repeater = $this->make_group( 'rep_safe', 'repeater' );
		$flat     = $this->make_group( 'flat_safe', 'flat' );
		$field    = $this->make_field( $repeater, 'safe_mover' );

		$this->store_value( 41, $field, 0, 'only job' );

		$this->assertTrue( $this->service->update_field( $field, array( 'group_id' => $flat ) ) );
		$this->assertSame( $flat, $this->group_of( $field ) );
	}

	/**
	 * flat -> repeater is always safe: every existing value sits at entry 0 and
	 * becomes the first entry, so nothing is hidden.
	 */
	public function test_flat_to_repeater_is_allowed(): void {
		$flat     = $this->make_group( 'flat_src', 'flat' );
		$repeater = $this->make_group( 'rep_dest', 'repeater' );
		$field    = $this->make_field( $flat, 'promoting_mover' );

		$this->store_value( 51, $field, 0, 'value' );

		$this->assertTrue( $this->service->update_field( $field, array( 'group_id' => $repeater ) ) );
		$this->assertSame( $repeater, $this->group_of( $field ) );
	}

	/**
	 * Multi-entry data does not block a move between two REPEATING groups —
	 * entry_index means the same thing on both sides.
	 */
	public function test_repeater_to_repeater_is_allowed_with_extra_entries(): void {
		$from  = $this->make_group( 'rep_a', 'repeater' );
		$to    = $this->make_group( 'rep_b', 'repeater' );
		$field = $this->make_field( $from, 'rep_mover' );

		$this->store_value( 61, $field, 0, 'first' );
		$this->store_value( 61, $field, 1, 'second' );

		$this->assertTrue( $this->service->update_field( $field, array( 'group_id' => $to ) ) );
		$this->assertSame( $to, $this->group_of( $field ) );
	}

	/**
	 * The REST route must accept group_id on UPDATE, not only on create. It was
	 * declared create-only, so the update route dropped the param before the
	 * service saw it and answered {"updated":true} regardless.
	 */
	public function test_rest_update_moves_the_field(): void {
		$from  = $this->make_group( 'rest_from', 'flat' );
		$to    = $this->make_group( 'rest_to', 'flat' );
		$field = $this->make_field( $from, 'rest_mover' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$request = new \WP_REST_Request( 'PUT', '/buddynext/v1/profile-fields/' . $field );
		$request->set_param( 'id', $field );
		$request->set_param( 'group_id', $to );

		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $to, $this->group_of( $field ) );
	}
}
