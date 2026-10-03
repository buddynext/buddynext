<?php
/**
 * GET /spaces/featured: the featured spaces members see on the directory.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\FeaturedSpaces;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The only featured route was the admin's management endpoint (403 for members).
 *
 * @covers \BuddyNext\Spaces\SpaceController::featured_for_viewer
 */
class FeaturedSpacesRestTest extends WP_UnitTestCase {

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
	 * Names in the featured list for the current viewer.
	 *
	 * @return string[]
	 */
	private function featured(): array {
		return wp_list_pluck( rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/spaces/featured' ) )->get_data(), 'name' );
	}

	/**
	 * Owner order kept; a secret space shows only to its members.
	 *
	 * @return void
	 */
	public function test_curated_order_and_secret_visibility(): void {
		$owner  = self::factory()->user->create();
		$spaces = new SpaceService();
		$secret = (int) $spaces->create( $owner, array( 'name' => 'Hidden', 'slug' => 'fh-' . wp_rand( 1000, 9999 ), 'type' => 'secret' ) );
		$open_b = (int) $spaces->create( $owner, array( 'name' => 'Bravo', 'slug' => 'fb-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		$open_a = (int) $spaces->create( $owner, array( 'name' => 'Alpha', 'slug' => 'fa-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );
		FeaturedSpaces::set_ids( array( $secret, $open_b, $open_a ) );

		wp_set_current_user( 0 );
		$this->assertSame( array( 'Bravo', 'Alpha' ), $this->featured(), 'Guest: owner order, secret left out.' );

		wp_set_current_user( $owner );
		$this->assertSame( array( 'Hidden', 'Bravo', 'Alpha' ), $this->featured(), 'A member of the secret space sees it.' );

		FeaturedSpaces::set_ids( array() );
	}
}
