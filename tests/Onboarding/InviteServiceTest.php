<?php
/**
 * Tests for the admin bulk invite service.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\InviteService;

/**
 * Verifies invite creation, token lookup, and status transitions.
 *
 * @covers \BuddyNext\Onboarding\InviteService
 */
class InviteServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var InviteService
	 */
	private InviteService $service;

	/**
	 * Create a fresh service before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new InviteService();
	}

	/**
	 * Calling create() returns a positive integer invite ID.
	 */
	public function test_create_returns_invite_id(): void {
		$id = $this->service->create( 'alice@example.com', 'Alice' );
		$this->assertGreaterThan( 0, $id );
	}

	/**
	 * Calling get_by_token() returns the invite row for a known valid token.
	 */
	public function test_get_by_token_returns_invite_row(): void {
		$this->service->create( 'bob@example.com', 'Bob' );
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT * FROM {$wpdb->prefix}bn_invites WHERE email = 'bob@example.com'",
			ARRAY_A
		);
		$this->assertNotNull( $row );
		$found = $this->service->get_by_token( (string) $row['token'] );
		$this->assertSame( 'bob@example.com', $found['email'] );
	}

	/**
	 * Calling get_by_token() returns null for an unknown token.
	 */
	public function test_get_by_token_returns_null_for_unknown_token(): void {
		$this->assertNull( $this->service->get_by_token( 'no-such-token' ) );
	}

	/**
	 * Calling mark_registered() sets the invite status to 'registered'.
	 */
	public function test_mark_registered_sets_status(): void {
		$id = $this->service->create( 'carol@example.com', 'Carol' );
		$this->service->mark_registered( $id );
		global $wpdb;
		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id )
		);
		$this->assertSame( 'registered', $status );
	}

	/**
	 * Calling mark_bounced() sets the invite status to 'bounced'.
	 */
	public function test_mark_bounced_sets_status(): void {
		$id = $this->service->create( 'dave@example.com', 'Dave' );
		$this->service->mark_bounced( $id );
		global $wpdb;
		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id )
		);
		$this->assertSame( 'bounced', $status );
	}

	/**
	 * Calling get_pending() includes newly created pending invites.
	 */
	public function test_get_pending_returns_created_invite(): void {
		$this->service->create( 'eve@example.com', 'Eve' );
		$pending = $this->service->get_pending();
		$emails  = array_column( $pending, 'email' );
		$this->assertContains( 'eve@example.com', $emails );
	}

	/**
	 * Each create() call generates a distinct token.
	 */
	public function test_create_generates_unique_tokens(): void {
		$id1 = $this->service->create( 'f1@example.com', 'F1' );
		$id2 = $this->service->create( 'f2@example.com', 'F2' );
		global $wpdb;
		$t1 = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id1 )
		);
		$t2 = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id2 )
		);
		$this->assertNotSame( $t1, $t2 );
	}

	/**
	 * An invite with a negative TTL is already expired and cannot be retrieved.
	 */
	public function test_expired_invite_not_valid(): void {
		$id = $this->service->create( 'g@example.com', 'G', -1 );
		global $wpdb;
		$token  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id )
		);
		$invite = $this->service->get_by_token( (string) $token );
		$this->assertNull( $invite );
	}
}
