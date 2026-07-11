<?php
/**
 * Tests for the RegistrationGuard hardening + social error codes.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationGuard;
use BuddyNext\Auth\RegistrationPolicy;
use BuddyNext\Auth\SocialLogin;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Auth\RegistrationGuard
 * @covers \BuddyNext\Auth\SocialLogin
 */
class GuardHardeningTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema, spam protection on, generous rate limit unless overridden.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		update_option( 'users_can_register', '1' );
		update_option( 'buddynext_reg_mode', 'open' );
		update_option( 'buddynext_reg_spam_protection', '1' );
		update_option( 'buddynext_reg_challenge', '' );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
	}

	/**
	 * Build a guard context for a form signup that would otherwise pass.
	 *
	 * @param string $email Email address.
	 * @return array<string,mixed>
	 */
	private function ctx( string $email ): array {
		return array(
			'source' => RegistrationPolicy::SOURCE_FORM,
			'email'  => $email,
			'ip'     => '203.0.113.9',
			'token'  => RegistrationGuard::issue_token(),
		);
	}

	/**
	 * REGRESSION: the rate limiter used to count only SUCCESSFUL registrations.
	 * Every early return skipped the counter, so a bot that failed the checks was
	 * never counted and could hammer the endpoint forever — the owner's
	 * "sign-ups per hour" throttled only the people who succeeded.
	 */
	public function test_failed_attempts_now_count_toward_the_rate_limit(): void {
		update_option( 'buddynext_reg_rate_limit', 3 );
		$guard = new RegistrationGuard();

		// Three attempts that all FAIL (disposable domain scores 100 → blocked).
		for ( $i = 0; $i < 3; $i++ ) {
			$result = $guard->check( $this->ctx( 'spam@mailinator.com' ) );
			$this->assertWPError( $result );
			$this->assertSame( 'bn_reg_spam', $result->get_error_code() );
		}

		// The budget is now spent. A FOURTH attempt — even a perfectly clean one —
		// must be rate-limited. Previously all four sailed through the limiter
		// because none of the failures had been counted.
		$result = $guard->check( $this->ctx( 'realperson@example.com' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bn_reg_rate', $result->get_error_code(), 'failed attempts must consume the rate budget' );
	}

	/**
	 * REGRESSION: a solved human-check token used to stay valid for its full TTL
	 * and could be replayed without limit — solve the question once, then register
	 * as many accounts as you like on the same answer for the next hour.
	 */
	public function test_a_solved_challenge_token_cannot_be_replayed(): void {
		update_option( 'buddynext_reg_challenge', '1' );

		$challenge = RegistrationGuard::issue_challenge();
		$answer    = $this->solve( $challenge['question'] );

		$this->assertTrue(
			RegistrationGuard::verify_challenge( $challenge['token'], $answer ),
			'the first redemption must succeed'
		);

		$this->assertFalse(
			RegistrationGuard::verify_challenge( $challenge['token'], $answer ),
			'the same token must not be redeemable twice'
		);
	}

	/**
	 * A WRONG answer must not burn the token — the member retypes and retries the
	 * same question, which is what they expect.
	 */
	public function test_a_wrong_answer_does_not_spend_the_token(): void {
		update_option( 'buddynext_reg_challenge', '1' );

		$challenge = RegistrationGuard::issue_challenge();
		$answer    = $this->solve( $challenge['question'] );

		$this->assertFalse( RegistrationGuard::verify_challenge( $challenge['token'], '99' ) );
		$this->assertTrue(
			RegistrationGuard::verify_challenge( $challenge['token'], $answer ),
			'a wrong guess must not lock the member out of their own question'
		);
	}

	/**
	 * Two challenges issued in the same second must be independent — redeeming one
	 * must not invalidate the other. (The token carries a nonce for exactly this.)
	 */
	public function test_two_challenges_issued_together_are_independent(): void {
		$a = RegistrationGuard::issue_challenge();
		$b = RegistrationGuard::issue_challenge();

		$this->assertNotSame( $a['token'], $b['token'] );

		$this->assertTrue( RegistrationGuard::verify_challenge( $a['token'], $this->solve( $a['question'] ) ) );
		$this->assertTrue(
			RegistrationGuard::verify_challenge( $b['token'], $this->solve( $b['question'] ) ),
			'redeeming one token must not spend another'
		);
	}

	/**
	 * REGRESSION: bn_social_error used to render whatever text was in the query
	 * string into a role="alert" banner on the real login page — a phishing
	 * primitive. Unknown codes must now collapse to one generic sentence.
	 */
	public function test_an_attacker_cannot_put_words_on_the_login_screen(): void {
		$attack = SocialLogin::error_message( 'Your account is locked. Call 1-800-555-0100 to restore access.' );

		$this->assertSame( 'Sign-in failed. Please try again.', $attack );
		$this->assertStringNotContainsString( '1-800', $attack );

		// Known codes still resolve to their real message.
		$this->assertSame(
			'Sign-in session expired. Please try again.',
			SocialLogin::error_message( 'expired' )
		);
	}

	/**
	 * Solve the arithmetic question the way a real client does — read it.
	 *
	 * @param string $question e.g. "What is three plus five?".
	 * @return string
	 */
	private function solve( string $question ): string {
		$words = array(
			'one'   => 1,
			'two'   => 2,
			'three' => 3,
			'four'  => 4,
			'five'  => 5,
			'six'   => 6,
			'seven' => 7,
			'eight' => 8,
			'nine'  => 9,
		);

		$total = 0;
		foreach ( $words as $word => $value ) {
			$total += $value * preg_match_all( '/\b' . $word . '\b/', strtolower( $question ) );
		}

		return (string) $total;
	}
}
