<?php
/**
 * A plan-limit refusal on create keeps the upgrade_url a filter attached.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Spaces\SpaceController
 */
class CreateSpaceLimitUpgradeUrlTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		remove_all_filters( 'buddynext_plan_limit_error' );
		parent::tear_down();
	}

	public function test_limit_refusal_keeps_the_upgrade_url_in_data(): void {
		global $wpdb;
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'buddynext_space_max_per_member', 1 );
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'       => 'Owned',
				'slug'       => 'owned-' . wp_generate_password( 6, false ),
				'type'       => 'open',
				'owner_id'   => $member,
				'created_at' => current_time( 'mysql', true ),
			)
		);

		// What Pro's EntitlementGates::gate_limit_error() does.
		add_filter(
			'buddynext_plan_limit_error',
			static function ( \WP_Error $error ): \WP_Error {
				$data                = (array) $error->get_error_data();
				$data['upgrade_url'] = 'https://example.test/plans/';
				return new \WP_Error( $error->get_error_code(), 'You can create 1 space.', $data );
			}
		);

		wp_set_current_user( $member );
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/spaces' );
		$request->set_param( 'name', 'One too many' );
		$data = rest_get_server()->dispatch( $request )->get_data();

		$this->assertSame( 'max_spaces_per_member', $data['code'] );
		$this->assertSame( 'https://example.test/plans/', $data['data']['upgrade_url'] ?? null );
		$this->assertSame( 422, $data['data']['status'] );
		$this->assertSame( array(), $data['data']['params'] );
	}
}
