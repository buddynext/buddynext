<?php
/**
 * GDPR regression — bn_invites (invitee email + first name) must be erasable on
 * request and must not linger forever.
 *
 * bn_invites is email-keyed, so the id-keyed member purge never touched it: a
 * member's own invite row survived their erasure, and an invited non-member's
 * email persisted indefinitely (Gate 5 blind spot — the erasure gate only saw
 * integer id columns). Now PrivacyTools::erase() has an email-keyed branch that
 * runs even without a WP_User, and LogRetentionService ages out spent/expired
 * invites. See free-internal security shelf; Basecamp 10321451748.
 *
 * @package BuddyNext\Tests\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Privacy;

use BuddyNext\Core\Installer;
use BuddyNext\Core\LogRetentionService;
use BuddyNext\Privacy\PrivacyTools;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Privacy\PrivacyTools::erase
 * @covers \BuddyNext\Core\LogRetentionService::purge
 */
class InviteErasureAndRetentionTest extends WP_UnitTestCase {

	private string $table;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$this->table = $wpdb->prefix . 'bn_invites';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$this->table}" );
	}

	private function seed( string $email, string $status, string $expires_at, string $created_at ): void {
		global $wpdb;
		$wpdb->insert(
			$this->table,
			array(
				'email'      => $email,
				'first_name' => 'Test',
				'token'      => wp_generate_password( 20, false, false ),
				'status'     => $status,
				'expires_at' => $expires_at,
				'created_at' => $created_at,
			)
		);
	}

	private function count_for( string $email ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE email = %s", $email ) );
	}

	private function total(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	private function future(): string {
		return gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
	}

	private function long_ago(): string {
		return gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS );
	}

	// ── Erasure ──────────────────────────────────────────────────────────────

	public function test_erasing_an_invited_non_member_removes_their_invite(): void {
		$this->seed( 'invitee@example.test', 'pending', $this->future(), gmdate( 'Y-m-d H:i:s' ) );
		$this->assertSame( 1, $this->count_for( 'invitee@example.test' ) );

		// No WP_User exists for this address — the old eraser bailed here.
		$result = ( new PrivacyTools() )->erase( 'invitee@example.test' );

		$this->assertSame( 0, $this->count_for( 'invitee@example.test' ), 'An invited non-member\'s email survived erasure.' );
		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erasing_a_member_who_was_invited_also_clears_their_invite_row(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'joined@example.test' ) );
		$this->assertIsInt( $user );
		$this->seed( 'joined@example.test', 'registered', $this->future(), gmdate( 'Y-m-d H:i:s' ) );

		( new PrivacyTools() )->erase( 'joined@example.test' );

		$this->assertSame( 0, $this->count_for( 'joined@example.test' ) );
	}

	public function test_erasure_leaves_other_peoples_invites_alone(): void {
		$this->seed( 'a@example.test', 'pending', $this->future(), gmdate( 'Y-m-d H:i:s' ) );
		$this->seed( 'b@example.test', 'pending', $this->future(), gmdate( 'Y-m-d H:i:s' ) );

		( new PrivacyTools() )->erase( 'a@example.test' );

		$this->assertSame( 0, $this->count_for( 'a@example.test' ) );
		$this->assertSame( 1, $this->count_for( 'b@example.test' ) );
	}

	// ── Retention ────────────────────────────────────────────────────────────

	public function test_retention_purges_spent_and_expired_invites_but_keeps_live_ones(): void {
		$this->seed( 'registered-old@example.test', 'registered', $this->future(), $this->long_ago() );
		$this->seed( 'bounced-old@example.test', 'bounced', $this->future(), $this->long_ago() );
		$this->seed( 'pending-expired@example.test', 'pending', $this->long_ago(), $this->long_ago() );
		$this->seed( 'pending-live@example.test', 'pending', $this->future(), gmdate( 'Y-m-d H:i:s' ) );

		$deleted = ( new LogRetentionService() )->purge();

		$this->assertSame( 0, $this->count_for( 'registered-old@example.test' ), 'A spent (registered) invite past the window should be purged.' );
		$this->assertSame( 0, $this->count_for( 'bounced-old@example.test' ) );
		$this->assertSame( 0, $this->count_for( 'pending-expired@example.test' ), 'A pending invite past its expiry should be purged.' );
		$this->assertSame( 1, $this->count_for( 'pending-live@example.test' ), 'A live, unexpired pending invite must be kept.' );
		$this->assertSame( 3, (int) $deleted['invites'] );
		$this->assertSame( 1, $this->total() );
	}
}
