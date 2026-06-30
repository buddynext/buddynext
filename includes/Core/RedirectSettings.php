<?php
/**
 * Owner-configurable redirect destinations (after login / logout / onboarding).
 *
 * One small resolver reused by every apply point so the behaviour is identical
 * everywhere: an empty option keeps the built-in default (so nothing changes
 * until an owner sets a value), and a configured value is validated with
 * wp_validate_redirect() — local-host only — so a stale or off-site value can
 * never redirect a member away from the site.
 *
 * Options are registered for save/sanitize on the Registration & Login settings
 * tab (Settings::SETTINGS_MAP, esc_url_raw).
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Resolves the configured post-login / post-logout / post-onboarding redirects.
 */
class RedirectSettings {

	/**
	 * Option key: where a member lands after logging in.
	 */
	public const OPT_LOGIN = 'buddynext_login_redirect';

	/**
	 * Option key: where a member lands after logging out.
	 */
	public const OPT_LOGOUT = 'buddynext_logout_redirect';

	/**
	 * Option key: where a new member lands after finishing onboarding.
	 */
	public const OPT_ONBOARDING = 'buddynext_onboarding_redirect';

	/**
	 * Wire the logout redirect filter. Called once from Plugin::init().
	 *
	 * Login and onboarding are applied at their own call sites (they already
	 * compute a redirect target); logout has no single call site, so the core
	 * `logout_redirect` filter is the one place that covers every logout link.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'logout_redirect', array( self::class, 'filter_logout_redirect' ) );
	}

	/**
	 * Resolve a configured redirect option to a safe URL.
	 *
	 * @param string $option  Option key (one of the OPT_* constants).
	 * @param string $fallback Built-in default URL when the option is empty/invalid.
	 * @return string Absolute URL.
	 */
	public static function resolve( string $option, string $fallback ): string {
		$raw = trim( (string) get_option( $option, '' ) );
		if ( '' === $raw ) {
			return $fallback;
		}

		// Local-host only; an off-site or malformed value falls back to $fallback.
		return wp_validate_redirect( $raw, $fallback );
	}

	/**
	 * Configured post-login destination, or the given default.
	 *
	 * @param string $fallback Default URL (today's behaviour, e.g. the activity feed).
	 * @return string
	 */
	public static function login( string $fallback ): string {
		return self::resolve( self::OPT_LOGIN, $fallback );
	}

	/**
	 * Configured post-onboarding destination, or the given default.
	 *
	 * @param string $fallback Default URL (today's behaviour, e.g. the member profile).
	 * @return string
	 */
	public static function onboarding( string $fallback ): string {
		return self::resolve( self::OPT_ONBOARDING, $fallback );
	}

	/**
	 * `logout_redirect` filter callback — apply the configured logout destination,
	 * defaulting to wherever WordPress was already sending the user.
	 *
	 * @param string $redirect_to The redirect target WordPress resolved.
	 * @return string
	 */
	public static function filter_logout_redirect( $redirect_to ): string {
		$fallback = '' !== (string) $redirect_to ? (string) $redirect_to : home_url( '/' );
		return self::resolve( self::OPT_LOGOUT, $fallback );
	}
}
