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
 *   3. Captcha                 — Turnstile / reCAPTCHA when configured (opt-in).
 *   4. Score                   — honeypot, time-trap, disposable email + the
 *                                buddynext_registration_spam_score filter.
 *
 * Hooks:
 *   filter buddynext_spam_protection_enabled (bool)
 *   filter buddynext_register_rate_limit     (int, per hour per IP)
 *   filter buddynext_registration_spam_score (int $score, array $ctx)
 *   filter buddynext_disposable_domains       (string[])
 *   action buddynext_registration_blocked     (array $ctx, int $score)
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates a registration attempt for spam signals.
 */
class RegistrationGuard {

	private const RATE_PREFIX = 'bn_reg_rl_';
	private const RATE_MAX    = 5;
	private const MIN_SECONDS = 2;
	private const TOKEN_TTL   = 3600;
	private const OPTION      = 'buddynext_registration_captcha';

	/**
	 * Evaluate a registration attempt.
	 *
	 * @param array<string, mixed> $ctx email, user_login, ip, honeypot, token, captcha_token.
	 * @return true|WP_Error True to allow, WP_Error (with a user-safe message) to block.
	 */
	public function check( array $ctx ): bool|WP_Error {
		if ( ! (bool) apply_filters( 'buddynext_spam_protection_enabled', true ) ) {
			return true;
		}

		// 1) Never block a privileged user creating accounts (admin/import/CLI).
		if ( is_user_logged_in() && current_user_can( 'create_users' ) ) {
			return true;
		}

		$ip = isset( $ctx['ip'] ) ? (string) $ctx['ip'] : '';

		// 2) Rate limit — hard stop on hammering.
		$max = (int) apply_filters( 'buddynext_register_rate_limit', self::RATE_MAX );
		if ( $max > 0 && '' !== $ip ) {
			$key = self::RATE_PREFIX . md5( $ip );
			if ( (int) get_transient( $key ) >= $max ) {
				return new WP_Error( 'bn_reg_rate', __( 'Too many sign-up attempts from your network. Please wait a few minutes and try again.', 'buddynext' ) );
			}
		}

		// 3) Captcha — only when configured (opt-in).
		$cap = self::captcha_settings();
		if ( '' !== $cap['provider'] && '' !== $cap['secret'] ) {
			if ( ! $this->verify_captcha( $cap, (string) ( $ctx['captcha_token'] ?? '' ), $ip ) ) {
				return new WP_Error( 'bn_reg_captcha', __( 'Please complete the verification challenge and try again.', 'buddynext' ) );
			}
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

		// Passed — count this attempt toward the rate window.
		if ( $max > 0 && '' !== $ip ) {
			$key = self::RATE_PREFIX . md5( $ip );
			set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
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
	 * Captcha settings ([provider, site_key, secret]).
	 *
	 * @return array{provider:string, site_key:string, secret:string}
	 */
	public static function captcha_settings(): array {
		$o = get_option( self::OPTION, array() );
		$o = is_array( $o ) ? $o : array();
		return array(
			'provider' => isset( $o['provider'] ) ? sanitize_key( (string) $o['provider'] ) : '',
			'site_key' => isset( $o['site_key'] ) ? (string) $o['site_key'] : '',
			'secret'   => isset( $o['secret'] ) ? (string) $o['secret'] : '',
		);
	}

	/**
	 * Verify a captcha token against the configured provider.
	 *
	 * @param array{provider:string, site_key:string, secret:string} $cap   Settings.
	 * @param string                                                 $token Client token.
	 * @param string                                                 $ip    Remote IP.
	 * @return bool
	 */
	private function verify_captcha( array $cap, string $token, string $ip ): bool {
		if ( '' === $token ) {
			return false;
		}
		$endpoints = array(
			'turnstile' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
			'recaptcha' => 'https://www.google.com/recaptcha/api/siteverify',
		);
		$url       = $endpoints[ $cap['provider'] ] ?? '';
		if ( '' === $url ) {
			return false;
		}

		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'body'    => array(
					'secret'   => $cap['secret'],
					'response' => $token,
					'remoteip' => $ip,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && ! empty( $body['success'] );
	}
}
