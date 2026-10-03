<?php
/**
 * The app opens a shared space link: by slug, with its invite token.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceInviteLinkService;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The web link (/spaces/{slug}/?invite=TOKEN) unlocks a secret space's preview;
 * REST had no slug lookup and ignored the token.
 *
 * @covers \BuddyNext\Spaces\SpaceController::get_space_by_slug
 * @covers \BuddyNext\Spaces\SpaceController::get_space
 */
class SpaceInviteLinkRestTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->forget_unlocks();
	}

	/**
	 * An invite unlock lasts for one request; a PHPUnit run is one process,
	 * so clear it where a new request would start clean.
	 *
	 * @return void
	 */
	private function forget_unlocks(): void {
		( new \ReflectionProperty( \BuddyNext\Spaces\SpaceVisibility::class, 'invite_unlocked' ) )->setValue( null, array() );
	}

	/**
	 * GET a route, returning the status code.
	 *
	 * @param string $route Route under buddynext/v1.
	 * @param string $token Invite token, or ''.
	 * @return int
	 */
	private function status( string $route, string $token = '' ): int {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1' . $route );
		if ( '' !== $token ) {
			$request->set_param( 'invite', $token );
		}
		return rest_do_request( $request )->get_status();
	}

	/**
	 * A valid token unlocks the secret space; no token or a dead one does not.
	 *
	 * @return void
	 */
	public function test_invite_token_and_slug(): void {
		$owner = self::factory()->user->create();
		$slug  = 'hidden-' . wp_rand( 1000, 9999 );
		$id    = (int) ( new SpaceService() )->create( $owner, array( 'name' => 'Hidden', 'slug' => $slug, 'type' => 'secret' ) );
		( new SpaceInviteLinkService() )->create( $id, $owner, '', 0 );
		$token = (string) ( ( new SpaceInviteLinkService() )->get( $id )['token'] ?? '' );
		$this->assertNotSame( '', $token, 'Link created.' );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, $this->status( '/spaces/' . $id ), 'Secret stays hidden without a token.' );
		$this->assertSame( 404, $this->status( '/spaces/slug/' . $slug ), 'By slug, still hidden without a token.' );
		$this->assertSame( 404, $this->status( '/spaces/slug/no-such-space' ) );
		$this->assertSame( 403, $this->status( '/spaces/' . $id, 'not-the-token' ), 'A dead token says so.' );

		$this->assertSame( 200, $this->status( '/spaces/' . $id, $token ), 'A valid token unlocks the preview.' );
		$this->assertSame( 200, $this->status( '/spaces/slug/' . $slug, $token ), 'The shared link resolves by slug.' );

		( new SpaceInviteLinkService() )->revoke( $id );
		$this->forget_unlocks();
		$this->assertSame( 403, $this->status( '/spaces/' . $id, $token ), 'A revoked link no longer unlocks.' );
	}

	/**
	 * A stale token never blocks a space the viewer could open anyway; the
	 * response just says the link is no longer valid.
	 *
	 * @return void
	 */
	public function test_stale_token_on_an_open_space_still_opens(): void {
		$owner = self::factory()->user->create();
		$id    = (int) ( new SpaceService() )->create( $owner, array( 'name' => 'Open', 'slug' => 'open-' . wp_rand( 1000, 9999 ), 'type' => 'open' ) );

		wp_set_current_user( self::factory()->user->create() );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces/' . $id );
		$request->set_param( 'invite', 'stale' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'invalid', $response->get_data()['invite_link'] );
	}
}
