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

		// The unlock lasts for the request, and every request in a PHPUnit run
		// shares one process: check the locked cases before any token is used.
		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 404, $this->status( '/spaces/' . $id ), 'Secret stays hidden without a token.' );
		$this->assertSame( 404, $this->status( '/spaces/slug/' . $slug ), 'By slug, still hidden without a token.' );
		$this->assertSame( 404, $this->status( '/spaces/slug/no-such-space' ) );
		$this->assertSame( 403, $this->status( '/spaces/' . $id, 'not-the-token' ), 'A dead token says so.' );

		$this->assertSame( 200, $this->status( '/spaces/' . $id, $token ), 'A valid token unlocks the preview.' );
		$this->assertSame( 200, $this->status( '/spaces/slug/' . $slug, $token ), 'The shared link resolves by slug.' );

		( new SpaceInviteLinkService() )->revoke( $id );
		$this->assertSame( 403, $this->status( '/spaces/' . $id, $token ), 'A revoked link no longer unlocks.' );
	}
}
