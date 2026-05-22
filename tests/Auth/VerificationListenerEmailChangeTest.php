<?php
/**
 * Tests for the email-change confirmation arm of VerificationListener.
 *
 * Exercises the buddynext_email_change_requested hook and the
 * handle_email_change_verify_request handler. Both go through a
 * transient-backed token so the test does not need to stand up the
 * VerificationService schema or mock wp_mail beyond fast asserts.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\VerificationListener;
use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Auth\VerificationListener::on_email_change_requested
 * @covers \BuddyNext\Auth\VerificationListener::handle_email_change_verify_request
 */
class VerificationListenerEmailChangeTest extends \WP_UnitTestCase {

	private VerificationListener $listener;
	private int $user_id;

	/**
	 * Last URL passed to wp_safe_redirect during the test (captured to avoid
	 * the headers-already-sent / wp_die noise of letting the real redirect run).
	 */
	private string $redirected_to = '';

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->listener      = new VerificationListener( new VerificationService() );
		$this->user_id       = self::factory()->user->create(
			array(
				'user_email' => 'before@example.test',
			)
		);
		$this->redirected_to = '';

		add_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 99, 1 );
	}

	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'capture_redirect' ), 99 );
		parent::tear_down();
	}

	public function capture_redirect( $location ) {
		$this->redirected_to = (string) $location;
		// Throwing here short-circuits both the underlying header() call AND
		// the explicit exit() in the listener, so PHPUnit keeps the process.
		throw new \WPDieException( 'redirect-captured' );
	}

	private function invoke_handler(): void {
		try {
			$this->listener->handle_email_change_verify_request();
			$this->fail( 'Handler should always wp_safe_redirect.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
	}

	public function test_on_email_change_requested_stores_transient_keyed_by_token(): void {
		// Capture wp_mail rather than firing it.
		$captured = array();
		add_filter(
			'pre_wp_mail',
			static function ( $short_circuit, $atts ) use ( &$captured ) {
				$captured[] = $atts;
				return true;
			},
			10,
			2
		);

		$this->listener->on_email_change_requested( $this->user_id, 'after@example.test' );

		$this->assertCount( 1, $captured, 'Confirmation email should fire exactly once.' );
		$this->assertSame( 'after@example.test', $captured[0]['to'], 'Confirmation must go to the candidate, never the current address.' );
		$this->assertStringContainsString( 'bn_verify_email=', $captured[0]['message'], 'Email body must carry the token-bearing URL.' );

		// Extract the token from the email body and verify the transient.
		preg_match( '/bn_verify_email=([a-zA-Z0-9]+)/', $captured[0]['message'], $matches );
		$this->assertNotEmpty( $matches[1] ?? '', 'Token must be parseable from the URL.' );

		$transient = get_transient( 'bn_email_change_' . $matches[1] );
		$this->assertIsArray( $transient );
		$this->assertSame( $this->user_id, $transient['user_id'] );
		$this->assertSame( 'after@example.test', $transient['candidate'] );
	}

	public function test_handle_email_change_swaps_address_and_clears_pending_meta(): void {
		update_user_meta( $this->user_id, 'bn_pending_email', 'after@example.test' );

		$token = 'unit-test-token-' . wp_generate_password( 16, false );
		set_transient(
			'bn_email_change_' . $token,
			array(
				'user_id'   => $this->user_id,
				'candidate' => 'after@example.test',
			),
			DAY_IN_SECONDS
		);

		$_GET['bn_verify_email'] = $token;

		$this->invoke_handler();

		$user = get_userdata( $this->user_id );
		$this->assertSame( 'after@example.test', $user->user_email, 'Address should swap to the candidate.' );
		$this->assertEmpty( get_user_meta( $this->user_id, 'bn_pending_email', true ), 'bn_pending_email meta should be cleared.' );
		$this->assertFalse( get_transient( 'bn_email_change_' . $token ), 'Single-use token should be deleted after swap.' );
		$this->assertStringContainsString( 'bn_email_changed=1', $this->redirected_to, 'Success redirect should pass the success flag.' );

		unset( $_GET['bn_verify_email'] );
	}

	public function test_handle_email_change_rejects_stale_token(): void {
		$_GET['bn_verify_email'] = 'definitely-not-stored';

		$this->invoke_handler();

		$user = get_userdata( $this->user_id );
		$this->assertSame( 'before@example.test', $user->user_email, 'Stale tokens must not mutate the address.' );
		$this->assertStringContainsString( 'bn_email_changed=0', $this->redirected_to, 'Stale-token path should pass the failure flag.' );

		unset( $_GET['bn_verify_email'] );
	}

	public function test_handle_email_change_rejects_when_candidate_taken_by_other_user(): void {
		$other_user_id = self::factory()->user->create(
			array(
				'user_email' => 'after@example.test',
			)
		);

		$token = 'race-condition-' . wp_generate_password( 16, false );
		set_transient(
			'bn_email_change_' . $token,
			array(
				'user_id'   => $this->user_id,
				'candidate' => 'after@example.test',
			),
			DAY_IN_SECONDS
		);

		$_GET['bn_verify_email'] = $token;

		$this->invoke_handler();

		$victim   = get_userdata( $this->user_id );
		$squatter = get_userdata( $other_user_id );
		$this->assertSame( 'before@example.test', $victim->user_email, 'Original user must keep their address.' );
		$this->assertSame( 'after@example.test', $squatter->user_email, 'Address that was claimed first must stay with that account.' );

		unset( $_GET['bn_verify_email'] );
	}
}
