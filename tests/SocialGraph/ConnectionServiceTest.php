<?php
/**
 * Tests for ConnectionService.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionService;
use WP_Error;

/**
 * @covers \BuddyNext\SocialGraph\ConnectionService
 */
class ConnectionServiceTest extends \WP_UnitTestCase {

	private ConnectionService $service;
	private int $alice;
	private int $bob;
	private int $carol;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ConnectionService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->carol   = self::factory()->user->create();
	}

	public function test_send_request_creates_pending_connection(): void {
		$result = $this->service->send_request( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertSame( 'pending', $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_cannot_send_request_to_self(): void {
		$result = $this->service->send_request( $this->alice, $this->alice );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_connect_self', $result->get_error_code() );
	}

	public function test_duplicate_request_returns_error(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$result = $this->service->send_request( $this->alice, $this->bob );

		$this->assertWPError( $result );
		$this->assertSame( 'request_already_exists', $result->get_error_code() );
	}

	public function test_accept_request_sets_accepted_status(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$result = $this->service->accept_request( $this->bob, $this->alice );

		$this->assertTrue( $result );
		$this->assertSame( 'accepted', $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_accept_fires_action(): void {
		$fired = false;
		add_action(
			'buddynext_connection_accepted',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );

		$this->assertTrue( $fired );
	}

	public function test_decline_request_sets_declined_status(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$result = $this->service->decline_request( $this->bob, $this->alice );

		$this->assertTrue( $result );
		$this->assertSame( 'declined', $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_withdraw_request_removes_connection(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$result = $this->service->withdraw_request( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertNull( $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_are_connected_is_true_after_accept(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );

		$this->assertTrue( $this->service->are_connected( $this->alice, $this->bob ) );
		$this->assertTrue( $this->service->are_connected( $this->bob, $this->alice ) );
	}

	public function test_are_connected_is_false_while_pending(): void {
		$this->service->send_request( $this->alice, $this->bob );

		$this->assertFalse( $this->service->are_connected( $this->alice, $this->bob ) );
	}

	public function test_connections_returns_accepted_list(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );
		$this->service->send_request( $this->alice, $this->carol );
		$this->service->accept_request( $this->carol, $this->alice );

		$connections = $this->service->connections( $this->alice );

		$this->assertContains( $this->bob, $connections );
		$this->assertContains( $this->carol, $connections );
	}

	public function test_pending_received_returns_list(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$this->service->send_request( $this->carol, $this->bob );

		$pending = $this->service->pending_received( $this->bob );

		$this->assertContains( $this->alice, $pending );
		$this->assertContains( $this->carol, $pending );
	}

	public function test_connection_count(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );
		$this->service->send_request( $this->alice, $this->carol );
		$this->service->accept_request( $this->carol, $this->alice );

		$this->assertSame( 2, $this->service->connection_count( $this->alice ) );
	}

	public function test_status_returns_null_for_no_relationship(): void {
		$this->assertNull( $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_status_is_symmetric_after_accept(): void {
		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );

		$this->assertSame( 'accepted', $this->service->status( $this->bob, $this->alice ) );
		$this->assertSame( 'accepted', $this->service->status( $this->alice, $this->bob ) );
	}

	public function test_send_request_fires_buddynext_connection_requested_with_connection_id(): void {
		$captured = null;
		add_action(
			'buddynext_connection_requested',
			function ( int $connection_id, int $requester_id, int $recipient_id ) use ( &$captured ): void {
				$captured = array( $connection_id, $requester_id, $recipient_id );
			},
			10,
			3
		);

		$this->service->send_request( $this->alice, $this->bob );

		$this->assertNotNull( $captured );
		$this->assertGreaterThan( 0, $captured[0] );
		$this->assertSame( $this->alice, $captured[1] );
		$this->assertSame( $this->bob, $captured[2] );
	}

	public function test_accept_fires_buddynext_connection_accepted_with_connection_id(): void {
		$captured = null;
		add_action(
			'buddynext_connection_accepted',
			function ( int $connection_id, int $user_id_1, int $user_id_2 ) use ( &$captured ): void {
				$captured = array( $connection_id, $user_id_1, $user_id_2 );
			},
			10,
			3
		);

		$this->service->send_request( $this->alice, $this->bob );
		$this->service->accept_request( $this->bob, $this->alice );

		$this->assertNotNull( $captured );
		$this->assertGreaterThan( 0, $captured[0] );
		$this->assertSame( $this->alice, $captured[1] );
		$this->assertSame( $this->bob, $captured[2] );
	}
}
