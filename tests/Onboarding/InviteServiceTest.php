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
		$id = $this->service->create( 'alice@example.com', 'Alice' )['invite_id'];
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
		$id = $this->service->create( 'carol@example.com', 'Carol' )['invite_id'];
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
		$id = $this->service->create( 'dave@example.com', 'Dave' )['invite_id'];
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
		$id1 = $this->service->create( 'f1@example.com', 'F1' )['invite_id'];
		$id2 = $this->service->create( 'f2@example.com', 'F2' )['invite_id'];
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
		$id = $this->service->create( 'g@example.com', 'G', -1 )['invite_id'];
		global $wpdb;
		$token  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT token FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id )
		);
		$invite = $this->service->get_by_token( (string) $token );
		$this->assertNull( $invite );
	}

	/**
	 * Every caller can tell a new invite from each kind of skip.
	 */
	public function test_create_answers_what_actually_happened(): void {
		global $wpdb;
		$member = self::factory()->user->create( array( 'user_email' => 'member@example.com' ) );
		$this->assertGreaterThan( 0, $member );

		$first = $this->service->create( 'h@example.com', 'H' );
		$this->assertContains( $first['status'], array( 'sent', 'queued' ) );

		$again = $this->service->create( 'h@example.com', 'H' );
		$this->assertSame( 'already_invited', $again['status'] );
		$this->assertSame( $first['invite_id'], $again['invite_id'] );

		$this->assertSame( 'existing_member', $this->service->create( 'member@example.com' )['status'] );
		$this->assertSame( 'invalid', $this->service->create( '' )['status'] );

		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_invites WHERE email = %s", 'h@example.com' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( 1, $rows, 'a duplicate never writes a second row' );
	}

	/**
	 * A pending invite with no space takes on the space; one bound to a space never moves.
	 */
	public function test_second_space_invite_attaches_or_reports(): void {
		global $wpdb;
		$space_of = static fn( int $id ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_invites WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$site = $this->service->create( 'site@example.com' );
		$this->assertSame( 'attached', $this->service->create( 'site@example.com', '', 7, 11 )['status'] );
		$this->assertSame( 11, $space_of( $site['invite_id'] ) );

		$this->assertSame( 'already_invited', $this->service->create( 'site@example.com', '', 7, 11 )['status'] );
		$this->assertSame( 'invited_elsewhere', $this->service->create( 'site@example.com', '', 7, 22 )['status'] );
		$this->assertSame( 11, $space_of( $site['invite_id'] ), 'the first space keeps its invite' );

		$this->assertSame( 'already_invited', $this->service->create( 'site@example.com' )['status'], 'asking for no space changes nothing' );
	}

	/**
	 * A refused email is reported and leaves a resendable bounced row, not a live invite.
	 */
	public function test_refused_email_marks_the_invite_bounced(): void {
		global $wpdb;
		$created = $this->service->create( 'bounce@example.com' );
		add_filter( 'pre_wp_mail', '__return_false' );
		$sent = $this->service->deliver_invite_email(
			array(
				'id'    => $created['invite_id'],
				'email' => 'bounce@example.com',
				'token' => 'x',
			)
		);
		remove_filter( 'pre_wp_mail', '__return_false' );

		$this->assertFalse( $sent );
		$this->assertSame(
			'bounced',
			$wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_invites WHERE id = %d", $created['invite_id'] ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		);
	}

	/**
	 * The CSV summary counts every skip by reason and owns up to the row cap.
	 */
	public function test_csv_counts_skips_by_reason_and_reports_the_cap(): void {
		self::factory()->user->create( array( 'user_email' => 'csvmember@example.com' ) );
		$this->service->create( 'csvpending@example.com' );

		$csv = wp_tempnam( 'bn-invite.csv' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $csv, "email,first_name\ncsvmember@example.com,M\ncsvpending@example.com,P\nnot-an-email,X\ncsvnew@example.com,N\ncsvnew@example.com,N\nover1@example.com,O\nover2@example.com,O\n" );

		$cap = static fn() => 5;
		add_filter( 'buddynext_invite_csv_max_rows', $cap );
		$summary = $this->service->import_from_csv( 0, $csv );
		remove_filter( 'buddynext_invite_csv_max_rows', $cap );
		wp_delete_file( $csv );

		$this->assertSame( 1, $summary['imported'] );
		$this->assertSame( 4, $summary['skipped'] );
		$this->assertSame(
			array(
				'already_invited' => 2,
				'existing_member' => 1,
				'invalid'         => 1,
			),
			$summary['reasons']
		);
		$this->assertSame( array(), $summary['errors'], 'a skip is not an error' );
		$this->assertSame( 2, $summary['not_read'] );
		$this->assertSame( 5, $summary['max_rows'] );
	}
}
