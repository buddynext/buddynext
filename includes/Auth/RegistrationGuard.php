<?php
/**
 * Registration spam protection for BuddyNext.
 *
 * Layered, default-on, zero-config protection that any registration path can
 * call. Built like a tool, not a SaaS: every check is a filterable seam, the
 * whole guard can be disabled, and third parties (Akismet, custom rules) plug
 * into the score via `buddynext_registration_spam_score`.
 *
 * Layers (in order):
 *   1. Admin bypass            — never block a logged-in user who can create users.
 *   2. Per-IP rate limit       — hard stop on hammering (filterable threshold).
 *   3. Human check             — in-house, accessibility-friendly arithmetic
 *                                challenge verified with a signed token; no
 *                                third-party service, no cookies (opt-in via
 *                                Settings → Registration).
 *   4. Score                   — honeypot, time-trap, disposable email + the
 *                                buddynext_registration_spam_score filter.
 *
 * Options (Settings → Registration):
 *   buddynext_reg_spam_protection ('1'|'')  master switch, default on
 *   buddynext_reg_challenge       ('1'|'')  show the human-check question, default on
 *
 * Hooks:
 *   filter buddynext_spam_protection_enabled  (bool)
 *   filter buddynext_registration_challenge_enabled (bool)
 *   filter buddynext_register_rate_limit      (int, per hour per IP)
 *   filter buddynext_registration_spam_score  (int $score, array $ctx)
 *   filter buddynext_disposable_domains        (string[])
 *   action buddynext_registration_blocked      (array $ctx, int $score)
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;
use BuddyNext\Core\RateLimiter;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a registration attempt for spam signals.
 */
class RegistrationGuard {

	private const RATE_PREFIX    = 'bn_reg_rl_';
	private const RATE_MAX       = 5;
	private const MIN_SECONDS    = 2;
	private const TOKEN_TTL      = 3600;
	private const OPT_PROTECTION = 'buddynext_reg_spam_protection';
	private const OPT_CHALLENGE  = 'buddynext_reg_challenge';

	/**
	 * Evaluate a registration attempt.
	 *
	 * @param array<string, mixed> $ctx email, user_login, ip, honeypot, token,
	 *                                  challenge_token, challenge_answer.
	 * @return true|WP_Error True to allow, WP_Error (with a user-safe message) to block.
	 */
	public function check( array $ctx ): bool|WP_Error {
		// Master switch — default on, admin can disable in Settings → Registration.
		$enabled = (bool) get_option( self::OPT_PROTECTION, true );
		if ( ! (bool) apply_filters( 'buddynext_spam_protection_enabled', $enabled ) ) {
			return true;
		}

		// 1) Never block a privileged user creating accounts (admin/import/CLI).
		if ( is_user_logged_in() && current_user_can( 'create_users' ) ) {
			return true;
		}

		$ip = isset( $ctx['ip'] ) ? (string) $ctx['ip'] : '';

		// 2) Rate limit — hard stop on hammering. Admin-set in Settings →
		// Registration; the filter still overrides for code-level control.
		$max = (int) apply_filters( 'buddynext_register_rate_limit', (int) get_option( 'buddynext_reg_rate_limit', self::RATE_MAX ) );
		if ( $max > 0 && '' !== $ip ) {
			$key = self::RATE_PREFIX . md5( $ip );
			if ( RateLimiter::count( $key ) >= $max ) {
				return new WP_Error( 'bn_reg_rate', __( 'Too many sign-up attempts from your network. Please wait a few minutes and try again.', 'buddynext' ) );
			}
		}

		// 3) Human check — in-house arithmetic challenge (opt-in).
		if ( self::challenge_enabled() ) {
			$ok = self::verify_challenge(
				(string) ( $ctx['challenge_token'] ?? '' ),
				(string) ( $ctx['challenge_answer'] ?? '' )
			);
			if ( ! $ok ) {
				return new WP_Error( 'bn_reg_challenge', __( 'That answer was not correct. Please solve the verification question and try again.', 'buddynext' ) );
			}
		}

		// 3.5) Allowed email domains — when the admin has configured an allowlist
		// in Settings → Registration, only addresses on those domains may register.
		// Empty allowlist = allow all domains (the documented default behaviour).
		if ( ! $this->domain_allowed( (string) ( $ctx['email'] ?? '' ) ) ) {
			return new WP_Error( 'bn_reg_domain', __( 'Only users from allowed email domains may register.', 'buddynext' ) );
		}

		// 4) Score-based signals.
		$score = 0;
		if ( ! empty( $ctx['honeypot'] ) ) {
			$score += 100; // Hidden field only a bot would fill.
		}
		if ( $this->too_fast( (string) ( $ctx['token'] ?? '' ) ) ) {
			$score += 100; // Submitted implausibly fast, or a forged/expired form token.
		}
		if ( $this->is_disposable( (string) ( $ctx['email'] ?? '' ) ) ) {
			$score += 100; // Throwaway email domain.
		}

		/**
		 * Filter the registration spam score. >= 100 blocks. Plug in Akismet,
		 * IP reputation, username heuristics, etc.
		 *
		 * @param int                  $score Accumulated score.
		 * @param array<string, mixed> $ctx   Attempt context.
		 */
		$score = (int) apply_filters( 'buddynext_registration_spam_score', $score, $ctx );

		if ( $score >= 100 ) {
			/**
			 * Fires when a registration is blocked as spam.
			 *
			 * @param array<string, mixed> $ctx   Attempt context.
			 * @param int                  $score Final score.
			 */
			do_action( 'buddynext_registration_blocked', $ctx, $score );
			return new WP_Error( 'bn_reg_spam', __( 'Your sign-up looked automated and was blocked. If this is a mistake, please try again.', 'buddynext' ) );
		}

		// Passed — count this attempt toward the rate window (atomic incr).
		if ( $max > 0 && '' !== $ip ) {
			RateLimiter::hit( self::RATE_PREFIX . md5( $ip ), HOUR_IN_SECONDS );
		}

		return true;
	}

	/**
	 * Issue a signed, time-stamped token for the form's time-trap.
	 *
	 * Rendered as a hidden field; verified on submit to reject sub-second bot
	 * posts and stale/forged forms — without a server-side per-form transient.
	 *
	 * @return string
	 */
	public static function issue_token(): string {
		$ts  = (string) time();
		$sig = hash_hmac( 'sha256', $ts, wp_salt( 'nonce' ) );
		return base64_encode( $ts . '|' . $sig ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * The hidden honeypot field name (filterable so it can be rotated).
	 *
	 * @return string
	 */
	public static function honeypot_field(): string {
		return (string) apply_filters( 'buddynext_registration_honeypot_field', 'bn_website' );
	}

	/**
	 * Whether the submission is implausibly fast or the token is bad.
	 *
	 * @param string $token Signed token from issue_token().
	 * @return bool
	 */
	private function too_fast( string $token ): bool {
		$raw = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || false === strpos( $raw, '|' ) ) {
			return true; // No/garbled token → treat as bot.
		}
		list( $ts, $sig ) = explode( '|', $raw, 2 );
		$expected         = hash_hmac( 'sha256', $ts, wp_salt( 'nonce' ) );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return true; // Forged token.
		}
		$elapsed = time() - (int) $ts;
		return $elapsed < self::MIN_SECONDS || $elapsed > self::TOKEN_TTL;
	}

	/**
	 * Is the email's domain permitted by the admin allowlist?
	 *
	 * Reads `buddynext_allowed_domains` (one domain per line, as rendered in
	 * Settings → Registration). When the option is blank the allowlist is
	 * inactive and every domain is permitted. When it is non-empty, only emails
	 * whose domain matches an entry (case-insensitive) are allowed.
	 *
	 * @param string $email Email address being registered.
	 * @return bool True when the domain is allowed (or no allowlist is set).
	 */
	private function domain_allowed( string $email ): bool {
		$raw = trim( (string) get_option( 'buddynext_allowed_domains', '' ) );
		if ( '' === $raw ) {
			return true; // No allowlist configured — allow all domains.
		}

		$allowed = array();
		$lines   = preg_split( '/[\r\n,]+/', $raw );
		if ( false === $lines ) {
			$lines = array();
		}
		foreach ( $lines as $line ) {
			$line = strtolower( trim( (string) $line ) );
			$line = ltrim( $line, '@' ); // Tolerate "@example.com" entries.
			if ( '' !== $line ) {
				$allowed[] = $line;
			}
		}

		/**
		 * Filter the registration allowed-domain list.
		 *
		 * @param string[] $allowed Lowercase, normalised domains.
		 * @param string   $email   Email being evaluated.
		 */
		$allowed = (array) apply_filters( 'buddynext_registration_allowed_domains', $allowed, $email );
		if ( empty( $allowed ) ) {
			return true; // Allowlist resolved to nothing — fail open, allow all.
		}

		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false; // No parseable domain cannot match a configured allowlist.
		}
		$domain = strtolower( substr( $email, $at + 1 ) );

		return in_array( $domain, $allowed, true );
	}

	/**
	 * Is the email on a known disposable/throwaway domain?
	 *
	 * @param string $email Email.
	 * @return bool
	 */
	private function is_disposable( string $email ): bool {
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$domain = strtolower( substr( $email, $at + 1 ) );

		$defaults = array(
			'mailinator.com',
			'guerrillamail.com',
			'10minutemail.com',
			'tempmail.com',
			'temp-mail.org',
			'throwawaymail.com',
			'yopmail.com',
			'getnada.com',
			'trashmail.com',
			'sharklasers.com',
			'maildrop.cc',
			'dispostable.com',
		);

		/**
		 * Filter the disposable-domain blocklist.
		 *
		 * @param string[] $domains Lowercase domains.
		 */
		$domains = (array) apply_filters( 'buddynext_disposable_domains', $defaults );

		return in_array( $domain, array_map( 'strtolower', $domains ), true );
	}

	/**
	 * Whether the human-check question should be shown and enforced.
	 *
	 * On by default, but only when the master spam-protection switch is also on —
	 * so the sign-up form never shows a question the guard would not enforce. An
	 * admin can switch the question off on its own in Settings → Registration
	 * (the other in-house signals — honeypot, time-trap, rate limit — stay on).
	 * The template and the guard both call this, so they always agree.
	 *
	 * @return bool
	 */
	public static function challenge_enabled(): bool {
		if ( ! (bool) get_option( self::OPT_PROTECTION, true ) ) {
			return false;
		}
		$on = (bool) get_option( self::OPT_CHALLENGE, true );
		return (bool) apply_filters( 'buddynext_registration_challenge_enabled', $on );
	}

	/**
	 * Issue a fresh human-check question and its signed token.
	 *
	 * A small randomised arithmetic question whose operands are rendered as
	 * words ("three plus five") so it is accessible to screen readers and not
	 * trivially scraped, while the answer is a single number. The expected
	 * answer is never sent to the client: it is folded into an HMAC, so the
	 * token is self-verifying with no server-side state.
	 *
	 * @return array{question:string, token:string} Display question and hidden token.
	 */
	public static function issue_challenge(): array {
		$a = wp_rand( 1, 9 );
		$b = wp_rand( 1, 9 );

		$question = sprintf(
			/* translators: 1: first number as a word, 2: second number as a word. */
			__( 'What is %1$s plus %2$s?', 'buddynext' ),
			self::number_word( $a ),
			self::number_word( $b )
		);

		return array(
			'question' => $question,
			'token'    => self::sign_answer( (string) ( $a + $b ) ),
		);
	}

	/**
	 * Verify a submitted answer against its signed challenge token.
	 *
	 * Recomputes the HMAC from the *submitted* answer: it matches only when the
	 * submitted number equals the one signed at issue time, and the token is
	 * within its TTL. The expected answer is never compared in the clear.
	 *
	 * @param string $token  Token from issue_challenge().
	 * @param string $answer User-supplied answer.
	 * @return bool
	 */
	public static function verify_challenge( string $token, string $answer ): bool {
		$raw = base64_decode( $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || false === strpos( $raw, '|' ) ) {
			return false;
		}
		list( $ts, $sig ) = explode( '|', $raw, 2 );
		if ( time() - (int) $ts > self::TOKEN_TTL ) {
			return false;
		}

		// Normalise to the bare integer the question asks for.
		$normalised = (string) (int) preg_replace( '/[^0-9-]/', '', $answer );
		$expected   = hash_hmac( 'sha256', $normalised . '|' . $ts, wp_salt( 'nonce' ) );

		return hash_equals( $expected, (string) $sig );
	}

	/**
	 * Sign an answer into a base64 `ts|hmac` token (the answer stays server-side).
	 *
	 * @param string $answer Canonical (integer-string) answer.
	 * @return string
	 */
	private static function sign_answer( string $answer ): string {
		$ts  = (string) time();
		$sig = hash_hmac( 'sha256', $answer . '|' . $ts, wp_salt( 'nonce' ) );
		return base64_encode( $ts . '|' . $sig ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Render a single digit (1–9) as its English word for the question text.
	 *
	 * @param int $n Number 1–9.
	 * @return string
	 */
	private static function number_word( int $n ): string {
		$words = array(
			1 => __( 'one', 'buddynext' ),
			2 => __( 'two', 'buddynext' ),
			3 => __( 'three', 'buddynext' ),
			4 => __( 'four', 'buddynext' ),
			5 => __( 'five', 'buddynext' ),
			6 => __( 'six', 'buddynext' ),
			7 => __( 'seven', 'buddynext' ),
			8 => __( 'eight', 'buddynext' ),
			9 => __( 'nine', 'buddynext' ),
		);
		return $words[ $n ] ?? (string) $n;
	}
}
