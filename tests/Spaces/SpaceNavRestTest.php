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

	/**
	 * Add sub-space follows the one rule and the per-parent cap; the app config
	 * says whether this viewer may create a space at all.
	 *
	 * @return void
	 */
	public function test_subspace_limit_and_create_permission(): void {
		$owner    = self::factory()->user->create();
		$service  = new SpaceService();
		$parent   = (int) $service->create( $owner, array( 'name' => 'Parent', 'slug' => 'par-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$read     = static fn(): array => rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $parent ) )->get_data();
		update_option( 'buddynext_space_max_sub_spaces', 1 );

		wp_set_current_user( $owner );
		$this->assertTrue( $read()['can_add_subspace'] );
		$service->create( $owner, array( 'name' => 'Child', 'slug' => 'ch-' . wp_rand( 1000, 9999 ), 'type' => 'open', 'parent_id' => $parent ) );
		$after = $read();
		$this->assertSame( array( 'max' => 1, 'used' => 1 ), $after['subspace_limit'] );
		$this->assertFalse( $after['can_add_subspace'], 'Cap reached: no Add sub-space.' );

		$config = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/app/config' ) )->get_data()['spaces'];
		$this->assertSame( buddynext_can( $owner, 'buddynext-spaces/create' ), $config['can_create'] );
		$this->assertSame( array_keys( \BuddyNext\Spaces\SpaceTypeRegistry::instance()->all() ), wp_list_pluck( $config['types'], 'key' ) );

		delete_option( 'buddynext_space_max_sub_spaces' );
	}

	/**
	 * The sidebar's team card shows to anyone who can see the space; top
	 * contributors only to roster viewers (a private space's outsiders don't).
	 *
	 * @return void
	 */
	public function test_team_and_top_contributors_follow_the_sidebar_rules(): void {
		$owner   = self::factory()->user->create( array( 'display_name' => 'Space Owner' ) );
		$private = (int) ( new SpaceService() )->create( $owner, array( 'name' => 'Priv', 'slug' => 'priv-' . wp_rand( 1000, 9999 ), 'type' => 'private' ) );
		( new \BuddyNext\Feed\PostService() )->create( $owner, array( 'content' => 'Hello space', 'space_id' => $private ) );
		$read = static fn(): array => rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $private ) )->get_data();

		wp_set_current_user( self::factory()->user->create() );
		$outsider = $read();
		$this->assertSame( array( 'Space Owner' ), wp_list_pluck( $outsider['team'], 'display_name' ), 'An outsider still sees who runs the space.' );
		$this->assertSame( array(), $outsider['top_contributors'], 'But not who posts in it.' );

		wp_set_current_user( $owner );
		$this->assertSame( array( $owner ), array_map( 'intval', wp_list_pluck( $read()['top_contributors'], 'user_id' ) ) );
	}
}
