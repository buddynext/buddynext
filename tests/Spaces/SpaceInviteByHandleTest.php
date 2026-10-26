<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Inviting by the @handle a member shows (card 10435625744). A dotted email
 * gives a login like "markus.kaufmann" and a handle like "@markus-kaufmann";
 * the owner types the handle they see and must reach the right member.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\SpaceController::invite_member
 * @covers \BuddyNext\Profile\Handle::user_for_identifier
 */
class SpaceInviteByHandleTest extends \WP_Test_REST_TestCase {

	private int $owner;
	private int $space_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->owner    = self::factory()->user->create();
		$this->space_id = (int) ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Handle room',
				'slug' => 'handle-room',
				'type' => 'open',
			)
		);
		wp_set_current_user( $this->owner );
	}

	private function invite( string $identifier ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', "/buddynext/v1/spaces/{$this->space_id}/invite" );
		$request->set_body_params( array( 'identifier' => $identifier ) );
		return rest_do_request( $request );
	}

	public function test_invite_by_handle_that_differs_from_login(): void {
		$invitee = self::factory()->user->create(
			array(
				'user_login'    => 'markus.kaufmann',
				'user_nicename' => 'markus-kaufmann',
			)
		);

		$response = $this->invite( '@markus-kaufmann' );

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertTrue( $response->get_data()['invited'] ?? false );
		$this->assertSame( 'invited', ( new \BuddyNext\Spaces\SpaceMemberService() )->get_status( $this->space_id, $invitee ) );
	}

	public function test_unknown_handle_is_not_found(): void {
		$this->assertSame( 404, $this->invite( '@nobody-by-that-name' )->get_status() );
	}
}
