<?php
/**
 * Built-in profile text fields refuse an over-long value instead of cutting it.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\FieldType;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * One cap source, shared by the form, REST and save_profile().
 *
 * @covers \BuddyNext\Profile\ProfileService::text_cap_errors
 * @covers \BuddyNext\Profile\FieldType::text_cap
 */
class ProfileTextCapTest extends WP_UnitTestCase {

	/**
	 * Member under test.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create the member and boot the routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->user_id );
		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Send PUT /me/profile.
	 *
	 * @param array $body JSON body.
	 * @return \WP_REST_Response
	 */
	private function put( array $body ) {
		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/me/profile' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The member's stored value for a field.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function stored( string $key ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT v.value FROM {$wpdb->prefix}bn_profile_values v JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id WHERE v.user_id = %d AND f.field_key = %s",
				$this->user_id,
				$key
			)
		);
	}

	/**
	 * An over-long value is a 422 on that field and nothing is stored or cut.
	 *
	 * @return void
	 */
	public function test_over_long_values_get_a_field_error_and_are_not_truncated(): void {
		$this->put( array( 'bio' => 'kept' ) );

		$res = $this->put(
			array(
				'bio'      => str_repeat( 'b', 1001 ),
				'headline' => str_repeat( 'h', 161 ),
			)
		);

		$this->assertSame( 422, $res->get_status() );
		$this->assertArrayHasKey( 'bio', $res->get_data()['errors'] );
		$this->assertArrayHasKey( 'headline', $res->get_data()['errors'] );
		$this->assertSame( 'kept', $this->stored( 'bio' ) );
		$this->assertSame( '', $this->stored( 'headline' ) );
	}

	/**
	 * The over-cap check does not leave the display name half written.
	 *
	 * @return void
	 */
	public function test_a_refused_save_changes_nothing_else(): void {
		$before = get_userdata( $this->user_id )->display_name;

		$res = $this->put(
			array(
				'display_name' => 'QA-FIX changed',
				'bio'          => str_repeat( 'b', 1001 ),
			)
		);

		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( $before, get_userdata( $this->user_id )->display_name );
	}

	/**
	 * Exactly at the cap saves, whole.
	 *
	 * @return void
	 */
	public function test_a_value_at_the_cap_saves_whole(): void {
		$bio = str_repeat( 'é', 1000 );
		$res = $this->put( array( 'bio' => $bio ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $bio, $this->stored( 'bio' ) );
	}

	/**
	 * A value stored over the cap by an older version still saves unchanged, and
	 * the rest of the form saves with it. Editing it, still over the cap, is refused.
	 *
	 * @return void
	 */
	public function test_an_existing_over_cap_value_saves_only_while_unchanged(): void {
		global $wpdb;

		$this->put( array( 'bio' => 'seed' ) );
		$long = str_repeat( 'x', 1500 );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_profile_values v JOIN {$wpdb->prefix}bn_profile_fields f ON f.id = v.field_id SET v.value = %s WHERE v.user_id = %d AND f.field_key = 'bio'",
				$long,
				$this->user_id
			)
		);

		$res = $this->put(
			array(
				'bio'      => $long,
				'headline' => 'Fresh headline',
			)
		);
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( $long, $this->stored( 'bio' ) );
		$this->assertSame( 'Fresh headline', $this->stored( 'headline' ) );

		$res = $this->put( array( 'bio' => $long . 'y' ) );
		$this->assertSame( 422, $res->get_status() );
		$this->assertSame( $long, $this->stored( 'bio' ) );
	}

	/**
	 * A CRLF form post measures what the browser's maxlength measured.
	 *
	 * @return void
	 */
	public function test_crlf_counts_as_one_character(): void {
		$value  = str_repeat( "ab\r\n", 300 ) . str_repeat( 'c', 100 );
		$errors = buddynext_service( 'profiles' )->text_cap_errors( $this->user_id, array( 'bio' => $value ) );

		$this->assertSame( array(), $errors );
	}

	/**
	 * Admin route and direct save_profile() callers get the same rule.
	 *
	 * @return void
	 */
	public function test_save_profile_itself_refuses(): void {
		$result = buddynext_service( 'profiles' )->save_profile( $this->user_id, array( 'pronouns' => str_repeat( 'p', 41 ) ) );

		$this->assertWPError( $result );
		$this->assertArrayHasKey( 'pronouns', $result->get_error_data()['fields'] );
	}

	/**
	 * The rendered inputs carry the same numbers, and a structured re-type has none.
	 *
	 * @return void
	 */
	public function test_inputs_carry_the_server_caps(): void {
		foreach ( FieldType::TEXT_CAPS as $key => $cap ) {
			$type = 'bio' === $key ? 'textarea' : 'text';
			$html = FieldType::render_input(
				array(
					'field_key' => $key,
					'type'      => $type,
				),
				'',
				$key
			);
			$this->assertStringContainsString( 'maxlength="' . $cap . '"', $html );
		}

		$this->assertSame(
			0,
			FieldType::text_cap(
				array(
					'field_key' => 'location',
					'type'      => 'location',
				)
			)
		);
		$this->assertStringNotContainsString(
			'maxlength',
			FieldType::render_input(
				array(
					'field_key' => 'qafix_other',
					'type'      => 'text',
				),
				'',
				'qafix_other'
			)
		);
	}
}
