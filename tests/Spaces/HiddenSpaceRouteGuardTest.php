<?php
/**
 * Every /spaces/{id}/... route answers a viewer who may not know a space
 * exists exactly as for an id that does not exist (card 10369170447).
 *
 * The gate is SpaceController::hide_unseen_space(), a rest_request_before_callbacks
 * filter, so it is exercised directly here with real requests.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceController;
use BuddyNext\Spaces\SpaceInviteLinkService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @group spaces
 */
class HiddenSpaceRouteGuardTest extends \WP_UnitTestCase {

	private int $owner;
	private int $secret;
	private int $private;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$spaces        = new SpaceService();
		$this->owner   = self::factory()->user->create();
		$this->secret  = (int) $spaces->create( $this->owner, array( 'name' => 'Guard Secret', 'slug' => 'guard-secret', 'type' => 'secret' ) );
		$this->private = (int) $spaces->create( $this->owner, array( 'name' => 'Guard Private', 'slug' => 'guard-private', 'type' => 'private' ) );
	}

	/**
	 * Run the gate for one request as one user.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route.
	 * @param int    $user   Viewer.
	 * @param array  $params Query params.
	 * @return mixed
	 */
	private function gate( string $method, string $route, int $user, array $params = array() ) {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$request->set_param( $k, $v );
		}
		return SpaceController::hide_unseen_space( null, array(), $request );
	}

	public function test_secret_space_answers_like_a_missing_one(): void {
		$stranger = self::factory()->user->create();
		foreach ( array( array( 'GET', '/members' ), array( 'POST', '/join' ), array( 'PUT', '' ), array( 'PUT', '/permissions' ), array( 'POST', '/archive' ) ) as list( $method, $tail ) ) {
			$secret  = $this->gate( $method, "/buddynext/v1/spaces/{$this->secret}{$tail}", $stranger );
			$missing = $this->gate( $method, "/buddynext/v1/spaces/99999999{$tail}", $stranger );
			$this->assertWPError( $secret, "{$method} {$tail} let a stranger through." );
			$this->assertSame( array( $missing->get_error_code(), $missing->get_error_data()['status'] ), array( $secret->get_error_code(), $secret->get_error_data()['status'] ), "{$method} {$tail} tells secret from missing." );
		}
		$this->assertWPError( $this->gate( 'GET', "/BUDDYNEXT/V1/SPACES/{$this->secret}/MEMBERS", $stranger ), 'Upper-case route bypassed the gate.' );
		$this->assertWPError( $this->gate( 'POST', "/buddynext/v1/spaces/{$this->secret}/join", $stranger, array( 'invite' => 'nottoken' ) ), 'A bad invite token let a stranger through.' );
		$slug    = $this->gate( 'GET', '/buddynext/v1/spaces/slug/guard-secret', $stranger );
		$no_slug = $this->gate( 'GET', '/buddynext/v1/spaces/slug/no-such-space', $stranger );
		$this->assertWPError( $slug, 'The slug route let a stranger see a secret space.' );
		$this->assertSame( $no_slug->get_error_code(), $slug->get_error_code() );
	}

	public function test_people_who_may_know_it_pass(): void {
		$this->assertNull( $this->gate( 'GET', "/buddynext/v1/spaces/{$this->secret}/members", $this->owner ), 'Owner' );
		$this->assertNull( $this->gate( 'GET', '/buddynext/v1/spaces/slug/guard-secret', $this->owner ), 'Owner by slug' );

		$invited = self::factory()->user->create();
		( new SpaceMemberService() )->invite( $this->secret, $this->owner, $invited );
		$this->assertNull( $this->gate( 'POST', "/buddynext/v1/spaces/{$this->secret}/join", $invited ), 'Invited member' );

		$link = ( new SpaceInviteLinkService() )->create( $this->secret, $this->owner, 'never', 0 );
		$this->assertNull( $this->gate( 'POST', "/buddynext/v1/spaces/{$this->secret}/join", self::factory()->user->create(), array( 'invite' => (string) $link['token'] ) ), 'Valid invite token' );
	}

	public function test_visible_spaces_keep_their_own_answers(): void {
		$stranger = self::factory()->user->create();
		$this->assertNull( $this->gate( 'GET', "/buddynext/v1/spaces/{$this->private}/members", $stranger ), 'A private space is visible; its route decides.' );
		$this->assertNull( $this->gate( 'GET', '/buddynext/v1/spaces', $stranger ), 'Non-id routes are untouched.' );
	}
}
