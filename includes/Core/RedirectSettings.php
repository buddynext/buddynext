<?php
/**
 * Owner-configurable redirect destinations (after login / logout / onboarding).
 *
 * One small resolver reused by every apply point so the behaviour is identical
 * everywhere: an empty option keeps the built-in default (so nothing changes
 * until an owner sets a value), and a configured value is validated with
 * wp_validate_redirect(). An off-site address the owner saved is honoured:
 * its host is added to allowed_redirect_hosts (allow_saved_hosts()), and only
 * that host, so the setting does what its field promises.
 *
 * Options are registered for save/sanitize on the Registration & Login settings
 * tab via the admin settings registry (Settings::fields_registration(), url type
 * -> esc_url_raw).
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
	 * The BuddyNext auth hub applies the configured login target at its own call
	 * site, but that misses every OTHER login path (wp-login.php, post-verification,
	 * a theme login form, programmatic sign-in) where WordPress would bounce a
	 * member to wp-admin. The core `login_redirect` filter is the one place that
	 * covers all of them, mirroring `logout_redirect`. Onboarding is applied at its
	 * own call site.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'login_redirect', array( self::class, 'filter_login_redirect' ), 10, 3 );
		add_filter( 'logout_redirect', array( self::class, 'filter_logout_redirect' ) );
		add_action( 'login_form_login', array( self::class, 'seed_wp_login_return' ) );
		add_filter( 'allowed_redirect_hosts', array( self::class, 'allow_saved_hosts' ) );
	}

	/**
	 * Let WordPress redirect to an off-site address the owner saved.
	 *
	 * The fields promise "a page on your site or a full address", but every apply
	 * point validates with wp_validate_redirect() / wp_safe_redirect(), which only
	 * pass this site's host: an owner who saved a landing page on another domain
	 * saw "Settings saved" and members never went there. Only the hosts of the
	 * three saved values are added, and only a site admin can save them, so this
	 * opens no redirect the owner did not choose.
	 *
	 * @param string[] $hosts Hosts WordPress already allows.
	 * @return string[]
	 */
	public static function allow_saved_hosts( $hosts ): array {
		$hosts = (array) $hosts;
		foreach ( array( self::OPT_LOGIN, self::OPT_LOGOUT, self::OPT_ONBOARDING ) as $option ) {
			$host = wp_parse_url( (string) get_option( $option, '' ), PHP_URL_HOST );
			if ( is_string( $host ) && '' !== $host ) {
				$hosts[] = strtolower( $host );
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * Carry the visitor's page through a wp-login.php sign-in.
	 *
	 * Themes that link to wp_login_url() with no argument (BuddyX's header) land
	 * on wp-login.php, whose form then posts back to itself, so by sign-in time
	 * the page the visitor came from is gone and they were sent to the default.
	 * Seeding redirect_to while the form renders puts that page in core's hidden
	 * field, the same rule BuddyNext's own login page applies.
	 *
	 * @return void
	 */
	public static function seed_wp_login_return(): void {
		if ( isset( $_REQUEST['redirect_to'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check.
			return;
		}
		$destination = \BuddyNext\Auth\AuthController::referer_destination();
		if ( '' !== $destination ) {
			$_REQUEST['redirect_to'] = $destination;
		}
	}

	/**
	 * Save-time clean-up for the three redirect settings.
	 *
	 * Owners type a page, not a URL: "activity", "/spaces/" or a full address.
	 * Run through esc_url_raw alone, "activity" became "http://activity", which
	 * resolve() then threw away as off-site, so the setting silently did nothing.
	 * Anything without a scheme is a path on this site.
	 *
	 * @param mixed $value Submitted value.
	 * @return string Absolute URL, or '' for the built-in default.
	 */
	public static function sanitize( $value ): string {
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $raw ) ) {
			$raw = home_url( '/' . ltrim( $raw, '/' ) );
		}
		return esc_url_raw( $raw );
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
		// This site, or an off-site host the owner saved (allow_saved_hosts());
		// a malformed value falls back to $fallback.
		$url = ( '' === $raw ) ? $fallback : wp_validate_redirect( $raw, $fallback );

		$contexts = array(
			self::OPT_LOGIN      => 'login',
			self::OPT_LOGOUT     => 'logout',
			self::OPT_ONBOARDING => 'onboarding',
		);

		/**
		 * Filter a resolved BuddyNext redirect destination.
		 *
		 * The single developer seam for changing where a member is sent after
		 * logging in, logging out, or finishing onboarding. It runs last, so it
		 * takes precedence over both the owner's setting and the built-in default
		 * — a developer with a different preference (a custom dashboard, an
		 * external portal, a per-role destination) overrides here without touching
		 * the settings UI. WordPress's own wp_safe_redirect at the apply point is
		 * the safety net, so an off-site target still needs `allowed_redirect_hosts`.
		 *
		 * @param string $url      Resolved destination (owner setting, else built-in default).
		 * @param string $context  Which redirect: 'login' | 'logout' | 'onboarding'.
		 * @param string $fallback The built-in default for this redirect.
		 */
		return (string) apply_filters( 'buddynext_redirect_url', $url, $contexts[ $option ] ?? $option, $fallback );
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
	 * `logout_redirect` filter callback — send a logged-out member to the BuddyNext
	 * login page.
	 *
	 * A community member has no reason to land on wp-login.php (WordPress's default
	 * logout target) or a generic home page after signing out — the branded
	 * BuddyNext login screen is the natural place to land, ready to sign back in.
	 * We default there, but honour an explicit front-end `redirect_to` (anything
	 * that isn't the wp-login.php / wp-admin default), and the owner can still
	 * override site-wide via the OPT_LOGOUT setting.
	 *
	 * @param string $redirect_to The redirect target WordPress resolved.
	 * @return string
	 */
	public static function filter_logout_redirect( $redirect_to ): string {
		$requested = (string) $redirect_to;
		// Treat WordPress's default logout target (wp-login.php?loggedout=true) and
		// any admin URL as "no explicit intent" so they resolve to the login page.
		$is_wp_default = '' === $requested
			|| false !== strpos( $requested, 'wp-login.php' )
			|| false !== strpos( $requested, '/wp-admin' );
		$fallback      = $is_wp_default ? \BuddyNext\Core\PageRouter::auth_url() : $requested;
		return self::resolve( self::OPT_LOGOUT, $fallback );
	}

	/**
	 * `login_redirect` filter callback — keep community members on the front end.
	 *
	 * Covers login paths that don't run through the BuddyNext auth hub
	 * (wp-login.php, post-verification, a theme login form, programmatic sign-in),
	 * where WordPress would bounce the member to wp-admin. A member has no backend
	 * to land on, so route them to the configured login destination (default: the
	 * activity feed). Admins keep their intended destination, and an explicit
	 * front-end `redirect_to` is honoured — only the default admin bounce is
	 * overridden.
	 *
	 * @param string             $redirect_to           Redirect target WordPress resolved.
	 * @param string             $requested_redirect_to The requested redirect_to, if any.
	 * @param \WP_User|\WP_Error $user                The logged-in user, or WP_Error on failure.
	 * @return string
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to = '', $user = null ): string {
		if ( ! ( $user instanceof \WP_User ) ) {
			return (string) $redirect_to;
		}

		// Admins keep their intended destination (they may want wp-admin).
		if ( user_can( $user, 'manage_options' ) ) {
			return (string) $redirect_to;
		}

		// Honour an explicit front-end request; only override the admin bounce.
		$requested = (string) $requested_redirect_to;
		if ( '' !== $requested && false === strpos( $requested, '/wp-admin' ) ) {
			return (string) $redirect_to;
		}

		return self::login( \BuddyNext\Core\PageRouter::activity_url() );
	}
}
