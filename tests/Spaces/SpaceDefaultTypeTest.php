<?php
/**
 * A stored default space type that no longer exists never blocks a create.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceTypeRegistry;
use WP_REST_Request;

/**
 * Basecamp 10376173885: buddynext_space_default_type can outlive the type it
 * names. The service fell back to open, the REST route answered 422 for every
 * create that named no type, and /app/config handed the app the dead slug.
 *
 * @covers \BuddyNext\Spaces\SpaceTypeRegistry::default_type
 */
class SpaceDefaultTypeTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function tear_down(): void {
		delete_option( 'buddynext_space_default_type' );
		parent::tear_down();
	}

	public function test_a_registered_default_is_used_and_a_dead_one_falls_back_to_open(): void {
		update_option( 'buddynext_space_default_type', 'private' );
		$this->assertSame( 'private', SpaceTypeRegistry::instance()->default_type() );

		update_option( 'buddynext_space_default_type', 'bogus' );
		$this->assertSame( 'open', SpaceTypeRegistry::instance()->default_type() );
	}

	public function test_rest_create_without_a_type_works_with_a_dead_default(): void {
		update_option( 'buddynext_space_default_type', 'bogus' );
		wp_set_current_user( self::factory()->user->create() );

		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_body_params( array( 'name' => 'No type given' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'open', $response->get_data()['type'] );
	}

	public function test_app_config_reports_a_registered_type(): void {
		update_option( 'buddynext_space_default_type', 'bogus' );
		wp_set_current_user( self::factory()->user->create() );

		$config = (array) rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/app/config' ) )->get_data();
		$found  = null;
		array_walk_recursive(
			$config,
			static function ( $value, $key ) use ( &$found ): void {
				if ( 'default_type' === $key ) {
					$found = $value;
				}
			}
		);

		$this->assertSame( 'open', $found );
	}
}
