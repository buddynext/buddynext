<?php
/**
 * The profile payload carries the same connection block the directory does.
 *
 * The member-directory row shipped {state, can_message} but GET /users/{id}/profile
 * did not, and GET /users/{id}/connection/status returned a direction-blind
 * 'pending' — the same value whether the viewer sent the request or received it.
 * So a profile screen could not tell "Requested" from "Respond" without a second
 * call to /connection/status AND a scan of /me/connection-requests.
 *
 * The shaping now lives once, in ConnectionService, and all three surfaces read it.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionService;
use WP_REST_Request;

/**
 * Direction-aware connection state across the REST surfaces.
 *
 * @covers \BuddyNext\SocialGraph\ConnectionService::directional_status
 * @covers \BuddyNext\SocialGraph\ConnectionService::state_block
 * @covers \BuddyNext\SocialGraph\ConnectionService::connection_block
 */
class ConnectionBlockTest extends \WP_UnitTestCase {

	/**
	 * Connection service under test.
	 *
	 * @var ConnectionService
	 */
	private $connections;

	/**
	 * The member who sends the request.
	 *
	 * @var int
	 */
	private $sender = 0;

	/**
	 * The member who receives it.
	 *
	 * @var int
	 */
	private $recipient = 0;

	/**
	 * Create the schema, two members and the REST server.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->connections = buddynext_service( 'connections' );
		$this->sender      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->recipient   = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * The connection block from a viewer's profile view of a peer.
	 *
	 * @param int $viewer Viewer user ID.
	 * @param int $peer   Peer user ID.
	 * @return array<string, mixed>
	 */
	private function profile_block( int $viewer, int $peer ): array {
		wp_set_current_user( $viewer );

		$data = (array) rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $peer . '/profile' ) )->get_data();

		return (array) ( $data['connection'] ?? array() );
	}

	/**
	 * With no relationship, both sides read 'none'.
	 *
	 * @return void
	 */
	public function test_no_relationship_reads_none(): void {
		$this->assertSame(
			array(
				'state'       => 'none',
				'can_message' => false,
			),
			$this->profile_block( $this->sender, $this->recipient )
		);
	}

	/**
	 * The reported bug: a pending request must read differently on each side.
	 *
	 * @return void
	 */
	public function test_a_pending_request_is_direction_aware(): void {
		$this->connections->send_request( $this->sender, $this->recipient );

		$this->assertSame( 'pending-sent', $this->profile_block( $this->sender, $this->recipient )['state'] );
		$this->assertSame( 'pending-received', $this->profile_block( $this->recipient, $this->sender )['state'] );
	}

	/**
	 * An accepted connection reads the same from both sides, and unlocks DM.
	 *
	 * @return void
	 */
	public function test_an_accepted_connection_reads_accepted_both_ways(): void {
		$this->connections->send_request( $this->sender, $this->recipient );
		$this->connections->accept_request( $this->recipient, $this->sender );

		foreach ( array( array( $this->sender, $this->recipient ), array( $this->recipient, $this->sender ) ) as $pair ) {
			$block = $this->profile_block( $pair[0], $pair[1] );
			$this->assertSame( 'accepted', $block['state'] );
			$this->assertTrue( $block['can_message'] );
		}
	}

	/**
	 * A member viewing their own profile gets 'none' rather than a nonsense
	 * self-connection state the app would have to special-case.
	 *
	 * @return void
	 */
	public function test_viewing_your_own_profile_reads_none(): void {
		$this->assertSame( 'none', $this->profile_block( $this->sender, $this->sender )['state'] );
	}

	/**
	 * /connection/status keeps its original `status` value — an existing client
	 * comparing against 'pending' must not break — and gains the block beside it.
	 *
	 * @return void
	 */
	public function test_connection_status_stays_backward_compatible(): void {
		$this->connections->send_request( $this->sender, $this->recipient );

		wp_set_current_user( $this->sender );
		$data = (array) rest_do_request(
			new WP_REST_Request( 'GET', '/buddynext/v1/users/' . $this->recipient . '/connection/status' )
		)->get_data();

		$this->assertSame( 'pending', $data['status'], 'The legacy symmetric status changed shape.' );
		$this->assertSame( 'pending-sent', $data['connection']['state'] );
	}

	/**
	 * The directory and the profile answer from the same shaping, so a row and a
	 * header can never describe one relationship two ways.
	 *
	 * @return void
	 */
	public function test_the_shaping_is_shared_with_the_directory(): void {
		$this->connections->send_request( $this->sender, $this->recipient );

		$this->assertSame(
			ConnectionService::state_block( 'pending-sent' ),
			$this->connections->connection_block( $this->sender, $this->recipient ),
			'connection_block() drifted from the shared state_block().'
		);

		// A bare 'pending' from a caller that did not resolve direction must fall
		// back to 'received' — offering Respond, never falsely claiming the viewer
		// already asked.
		$this->assertSame( 'pending-received', ConnectionService::state_block( 'pending' )['state'] );
		$this->assertSame( 'none', ConnectionService::state_block( 'declined' )['state'] );
		$this->assertSame( 'none', ConnectionService::state_block( null )['state'] );
	}
}
