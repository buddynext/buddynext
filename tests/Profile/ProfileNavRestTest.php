<?php
/**
 * GET /users/{id}/profile carries the profile's tabs and metric pills.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use BuddyNext\Nav\NavContext;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The app could not know which tabs a profile has, their order, or the
 * owner's choices; the web resolves them through buddynext_nav().
 *
 * @covers \BuddyNext\Profile\ProfileController::get_profile
 */
class ProfileNavRestTest extends WP_UnitTestCase {

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
	 * Same tabs and pills as the web profile, for the owner and for others.
	 *
	 * @return void
	 */
	public function test_nav_matches_the_web_profile_per_viewer(): void {
		$member = self::factory()->user->create();

		foreach ( array( $member, self::factory()->user->create(), 0 ) as $viewer ) {
			wp_set_current_user( $viewer );
			$web = buddynext_nav( new NavContext( 'profile', $member, $viewer ) );
			$api = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $member . '/profile' ) )->get_data()['nav'];

			$this->assertSame( wp_list_pluck( array_values( $web->layer( 'primary' ) ), 'id' ), wp_list_pluck( $api['tabs'], 'id' ), "Tabs for viewer {$viewer}." );
			$this->assertSame( wp_list_pluck( array_values( $web->layer( 'metric' ) ), 'count_value' ), wp_list_pluck( $api['metrics'], 'count' ), "Pill counts for viewer {$viewer}." );
		}
	}
}
