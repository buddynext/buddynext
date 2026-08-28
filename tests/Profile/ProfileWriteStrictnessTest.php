<?php
/**
 * Tests pinning the strict-input contract on the profile write endpoints.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Profile\FieldType;
use WP_REST_Request;

/**
 * A profile write must never report success for a payload it did not understand.
 *
 * Three shipped defects shared one cause: WordPress ignores undeclared params, so
 * every one of these answered 200 while writing nothing (or writing something the
 * caller did not ask for).
 *
 * @covers \BuddyNext\Profile\ProfileController::update_profile
 * @covers \BuddyNext\Profile\ProfileController::update_field
 * @covers \BuddyNext\Profile\ProfileController::create_field
 * @covers \BuddyNext\Profile\ProfileService::update_field
 */
class ProfileWriteStrictnessTest extends \WP_Test_REST_TestCase {

	/**
	 * An administrator (profile fields are an admin-only surface).
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * A flat group to hang test fields on.
	 *
	 * @var int
	 */
	private int $group_id;

	/**
	 * Install the schema and seed the actors.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		global $wpdb;
		$this->group_id = (int) $wpdb->get_var(
			"SELECT id FROM {$wpdb->prefix}bn_profile_groups WHERE type = 'flat' ORDER BY id ASC LIMIT 1"
		);
	}

	/**
	 * Dispatch a JSON request as the seeded administrator.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $route  Route path.
	 * @param array<string,mixed> $body   Request body.
	 * @return \WP_REST_Response
	 */
	private function json( string $method, string $route, array $body ) {
		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	/**
	 * Read a field definition row.
	 *
	 * @param int $id Field ID.
	 * @return array<string,mixed>
	 */
	private function field_row( int $id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $id ),
			ARRAY_A
		);
	}

	/**
	 * Create a field over REST and return its ID.
	 *
	 * @param array<string,mixed> $overrides Field attributes.
	 * @return int
	 */
	private function create_field( array $overrides = array() ): int {
		$response = $this->json(
			'POST',
			'/buddynext/v1/profile-fields',
			array_merge(
				array(
					'group_id'  => $this->group_id,
					'field_key' => 'strict_' . wp_rand( 100000, 999999 ),
					'label'     => 'Strict Probe',
					'type'      => 'text',
				),
				$overrides
			)
		);

		$this->assertSame( 201, $response->get_status() );

		return (int) $response->get_data()['id'];
	}

	/**
	 * A wrapped profile payload is refused, not silently accepted.
	 *
	 * PUT {"fields":{"headline":"x"}} answered 200 {"saved":true,"errors":[]} and
	 * persisted nothing at all, so an integration sending the wrong shape could
	 * "succeed" indefinitely while every edit was discarded.
	 *
	 * @return void
	 */
	public function test_wrapped_profile_payload_is_refused(): void {
		$response = $this->json(
			'PUT',
			'/buddynext/v1/me/profile',
			array( 'fields' => array( 'headline' => 'wrapped' ) )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'bn_unknown_params', $response->get_data()['code'] ?? '' );
	}

	/**
	 * One unknown key refuses the WHOLE write — a valid sibling key is not applied.
	 *
	 * All-or-nothing: a partly-applied write leaves the profile in a state neither
	 * side asked for, and makes a corrected retry unsafe.
	 *
	 * @return void
	 */
	public function test_a_valid_key_alongside_an_unknown_one_saves_nothing(): void {
		$before = get_user_meta( $this->admin_id, 'bn_field_headline', true );

		$response = $this->json(
			'PUT',
			'/buddynext/v1/me/profile',
			array(
				'headline'  => 'should-not-land',
				'nonsense'  => 'x',
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame(
			$before,
			get_user_meta( $this->admin_id, 'bn_field_headline', true ),
			'the valid half of a refused payload must not be applied'
		);
	}

	/**
	 * A real profile field key is still accepted — the gate is not a blanket refusal.
	 *
	 * @return void
	 */
	public function test_a_declared_profile_field_still_saves(): void {
		$response = $this->json(
			'PUT',
			'/buddynext/v1/me/profile',
			array( 'headline' => 'accepted' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['saved'] );
	}

	/**
	 * WordPress' own reserved params never trip the gate.
	 *
	 * @return void
	 */
	public function test_reserved_params_are_allowed(): void {
		$response = $this->json(
			'PUT',
			'/buddynext/v1/me/profile',
			array(
				'headline' => 'reserved-ok',
				'_locale'  => 'user',
			)
		);

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * PUT /profile-fields/{id} honours every attribute the service supports.
	 *
	 * Five of the eleven (description, placeholder, is_searchable,
	 * show_on_register, show_in_header) were read by nothing and answered with
	 * {"updated":true} — the admin screen could set them and the API could not.
	 *
	 * @return void
	 */
	public function test_update_field_persists_all_supported_attributes(): void {
		$field_id = $this->create_field( array( 'visibility' => 'public' ) );

		$response = $this->json(
			'PUT',
			'/buddynext/v1/profile-fields/' . $field_id,
			array(
				'description'      => 'A description',
				'placeholder'      => 'A placeholder',
				'is_searchable'    => true,
				'show_on_register' => true,
				'show_in_header'   => true,
			)
		);

		$this->assertSame( 200, $response->get_status() );

		$row = $this->field_row( $field_id );
		$this->assertSame( 'A description', $row['description'] );
		$this->assertSame( 'A placeholder', $row['placeholder'] );
		$this->assertSame( '1', (string) $row['is_searchable'] );
		$this->assertSame( '1', (string) $row['show_on_register'] );
		$this->assertSame( '1', (string) $row['show_in_header'] );
	}

	/**
	 * is_searchable is not stored against a type that can never be searched.
	 *
	 * @return void
	 */
	public function test_is_searchable_is_dropped_for_an_incapable_type(): void {
		$this->assertFalse( FieldType::is_text_searchable( 'date' ), 'precondition: date is not free-text searchable' );

		$field_id = $this->create_field(
			array(
				'type'          => 'date',
				'visibility'    => 'public',
				'is_searchable' => true,
			)
		);

		$this->assertSame( '0', (string) $this->field_row( $field_id )['is_searchable'] );
	}

	/**
	 * is_searchable is not stored against a visibility whose values never reach an index.
	 *
	 * @return void
	 */
	public function test_is_searchable_is_dropped_for_an_incapable_visibility(): void {
		$field_id = $this->create_field(
			array(
				'type'          => 'text',
				'visibility'    => 'private',
				'is_searchable' => true,
			)
		);

		$this->assertSame( '0', (string) $this->field_row( $field_id )['is_searchable'] );
	}

	/**
	 * A stored is_searchable is re-evaluated when the combination stops being possible.
	 *
	 * Flipping a searchable text field to Private otherwise left is_searchable=1
	 * against a combination search can never honour.
	 *
	 * @return void
	 */
	public function test_changing_visibility_clears_a_now_impossible_searchable_flag(): void {
		$field_id = $this->create_field(
			array(
				'type'          => 'text',
				'visibility'    => 'public',
				'is_searchable' => true,
			)
		);
		$this->assertSame( '1', (string) $this->field_row( $field_id )['is_searchable'], 'precondition' );

		$response = $this->json(
			'PUT',
			'/buddynext/v1/profile-fields/' . $field_id,
			array( 'visibility' => 'private' )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0', (string) $this->field_row( $field_id )['is_searchable'] );
	}

	/**
	 * PUT refuses a type that is not in the registry, exactly as POST always has.
	 *
	 * The value was written straight into the column, producing a field with no
	 * render or save pipeline that the admin UI could not repair.
	 *
	 * @return void
	 */
	public function test_update_field_refuses_an_unregistered_type(): void {
		$field_id = $this->create_field();

		$response = $this->json(
			'PUT',
			'/buddynext/v1/profile-fields/' . $field_id,
			array( 'type' => 'not_a_real_type' )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'text', $this->field_row( $field_id )['type'] );
	}

	/**
	 * Options mean the same thing on both verbs — array or newline-separated string.
	 *
	 * @return void
	 */
	public function test_options_accepts_both_shapes_on_both_verbs(): void {
		$field_id = $this->create_field(
			array(
				'type'    => 'select',
				'options' => array( 'Red', 'Blue' ),
			)
		);
		$this->assertSame( array( 'Red', 'Blue' ), json_decode( $this->field_row( $field_id )['options'], true ) );

		$this->json(
			'PUT',
			'/buddynext/v1/profile-fields/' . $field_id,
			array( 'options' => "Green\nYellow" )
		);
		$this->assertSame( array( 'Green', 'Yellow' ), json_decode( $this->field_row( $field_id )['options'], true ) );
	}

	/**
	 * Updating a field that does not exist reports 404 rather than success.
	 *
	 * @return void
	 */
	public function test_updating_a_missing_field_is_not_reported_as_updated(): void {
		$response = $this->json(
			'PUT',
			'/buddynext/v1/profile-fields/99999999',
			array( 'label' => 'Ghost' )
		);

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'bn_field_not_found', $response->get_data()['code'] ?? '' );
	}
}
