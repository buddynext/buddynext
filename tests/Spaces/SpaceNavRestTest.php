<?php
/**
 * GET /spaces/{id} carries the tab bar the web space header renders.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Nav\NavContext;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The app had only landing_tab, so it could not know which tabs a space has.
 *
 * @covers \BuddyNext\Spaces\SpaceController::get_space
 * @covers \BuddyNext\Nav\NavItem::to_array
 */
class SpaceNavRestTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Tab ids as REST returns them for the current user.
	 *
	 * @param int $space_id Space.
	 * @return array<int,array<string,mixed>>
	 */
	private function rest_nav( int $space_id ): array {
		return rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $space_id ) )->get_data()['nav'];
	}

	/**
	 * Same tabs, order, urls and counts as the web header, per viewer.
	 *
	 * @return void
	 */
	public function test_nav_matches_the_web_header_per_viewer(): void {
		$owner    = self::factory()->user->create();
		$space_id = (int) ( new SpaceService() )->create( $owner, array( 'name' => 'Nav Space', 'slug' => 'nav-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );

		foreach ( array( $owner => 'owner', 0 => '' ) as $viewer => $role ) {
			wp_set_current_user( $viewer );
			$web = buddynext_nav( new NavContext( 'space', $space_id, $viewer, $role ) )->layer( 'primary' );
			$api = $this->rest_nav( $space_id );

			$this->assertSame( wp_list_pluck( array_values( $web ), 'id' ), wp_list_pluck( $api, 'id' ), "Same tabs for viewer {$viewer}." );
			$this->assertSame( wp_list_pluck( array_values( $web ), 'url_value' ), wp_list_pluck( $api, 'url' ) );
			$this->assertSame( wp_list_pluck( array_values( $web ), 'count_value' ), wp_list_pluck( $api, 'count' ) );
		}

		// Whether the owner sees Moderation depends on site settings (covered by
		// the parity checks above); a guest never does.
		wp_set_current_user( 0 );
		$this->assertNotContains( 'moderation', wp_list_pluck( $this->rest_nav( $space_id ), 'id' ), 'A guest does not.' );
	}
}
