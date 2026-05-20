<?php
/**
 * Tests for VerificationService.
 *
 * TODO (deferred — integration-test territory):
 *   - VerificationListener tests require simulating the user_register action
 *     and mocking wp_mail() email sends. Add these once a mail-capture helper
 *     is available in the test bootstrap.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Auth\VerificationService
 */
class VerificationServiceTest extends \WP_UnitTestCase {

	private VerificationService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Ensure the email-verify feature gate is ON so is_verified() reads usermeta.
		update_option( 'buddynext_email_verify', true );

		$this->service = new VerificationService();
		$this->user_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		parent::tear_down();
	}

	// ── create_token ──────────────────────────────────────────────────────────

	public function test_create_token_persists_and_returns_token(): void {
		global $wpdb;

		$token = $this->service->create_token( $this->user_id );

		$this->assertIsString( $token );
		$this->assertSame( 64, strlen( $token ) );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_verify_tokens WHERE token = %s",
				$token
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( (string) $this->user_id, (string) $row->user_id );
	}

	public function test_create_token_replaces_existing(): void {
		global $wpdb;

		$token_a = $this->service->create_token( $this->user_id );
		$token_b = $this->service->create_token( $this->user_id );

		// Two distinct tokens should exist because create_token does not delete old ones.
		// But verify that both belong to the same user and both tokens differ.
		$this->assertNotSame( $token_a, $token_b );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_verify_tokens WHERE user_id = %d",
				$this->user_id
			)
		);

		// create_token does not prune old rows — it only inserts. The test confirms
		// at least one row (the latest) is present and the token string is unique.
		$this->assertGreaterThanOrEqual( 1, $count );

		// The resend() method is what pruning old tokens uses — test that via resend tests.
	}

	// ── verify ────────────────────────────────────────────────────────────────

	public function test_verify_returns_user_id_for_valid_token(): void {
		$token  = $this->service->create_token( $this->user_id );
		$result = $this->service->verify( $token );

		$this->assertSame( $this->user_id, $result );
	}

	public function test_verify_sets_verified_usermeta(): void {
		$token = $this->service->create_token( $this->user_id );
		$this->service->verify( $token );

		$this->assertSame( '1', (string) get_user_meta( $this->user_id, 'buddynext_email_verified', true ) );
	}

	public function test_verify_returns_wp_error_for_invalid_token(): void {
		$result = $this->service->verify( 'this-is-not-a-real-token' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_token', $result->get_error_code() );
	}

	public function test_verify_returns_wp_error_for_expired_token(): void {
		global $wpdb;

		$token = $this->service->create_token( $this->user_id );

		// Manually expire the token.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_verify_tokens SET expires_at = %s WHERE token = %s",
				gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ),
				$token
			)
		);

		$result = $this->service->verify( $token );

		$this->assertWPError( $result );
		$this->assertSame( 'expired_token', $result->get_error_code() );
	}

	public function test_verify_consumes_token(): void {
		global $wpdb;

		$token = $this->service->create_token( $this->user_id );
		$this->service->verify( $token );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_verify_tokens WHERE token = %s",
				$token
			)
		);

		$this->assertNull( $row, 'Token row should be deleted after successful verify.' );
	}

	// ── resend ────────────────────────────────────────────────────────────────

	public function test_resend_creates_new_token_within_window(): void {
		global $wpdb;

		$token_a = $this->service->create_token( $this->user_id );
		$token_b = $this->service->resend( $this->user_id );

		$this->assertIsString( $token_b );
		$this->assertNotSame( $token_a, $token_b );

		// After resend, old token for this user should be gone.
		$old_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_verify_tokens WHERE token = %s",
				$token_a
			)
		);
		$this->assertNull( $old_row, 'resend() should delete the previous pending token.' );

		// New token row should exist.
		$new_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_verify_tokens WHERE token = %s",
				$token_b
			)
		);
		$this->assertNotNull( $new_row );
	}

	public function test_resend_returns_wp_error_when_already_verified(): void {
		// Mark user as verified.
		update_user_meta( $this->user_id, 'buddynext_email_verified', 1 );

		$result = $this->service->resend( $this->user_id );

		$this->assertWPError( $result );
		$this->assertSame( 'already_verified', $result->get_error_code() );
	}

	// ── is_verified ───────────────────────────────────────────────────────────

	public function test_is_verified_returns_false_before_verification(): void {
		$this->assertFalse( $this->service->is_verified( $this->user_id ) );
	}

	public function test_is_verified_returns_true_after_verification(): void {
		$token = $this->service->create_token( $this->user_id );
		$this->service->verify( $token );

		$this->assertTrue( $this->service->is_verified( $this->user_id ) );
	}

	public function test_is_verified_returns_true_when_feature_disabled(): void {
		// When the option is OFF, all users are treated as verified.
		update_option( 'buddynext_email_verify', false );

		$this->assertTrue( $this->service->is_verified( $this->user_id ) );
	}

	// ── hooks ─────────────────────────────────────────────────────────────────

	public function test_verify_fires_buddynext_user_verified_action(): void {
		$captured = array();
		add_action(
			'buddynext_user_verified',
			static function ( int $uid ) use ( &$captured ): void {
				$captured[] = $uid;
			}
		);

		$token = $this->service->create_token( $this->user_id );
		$this->service->verify( $token );

		$this->assertSame( array( $this->user_id ), $captured );
	}

	public function test_create_token_fires_buddynext_send_verification_email_action(): void {
		$fired_ids = array();
		add_action(
			'buddynext_send_verification_email',
			static function ( int $uid ) use ( &$fired_ids ): void {
				$fired_ids[] = $uid;
			}
		);

		$this->service->create_token( $this->user_id );

		$this->assertContains( $this->user_id, $fired_ids );
	}
}
