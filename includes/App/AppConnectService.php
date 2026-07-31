<?php
/**
 * Native-app connect bridge — browser login to Application Password.
 *
 * The mobile app authenticates with WordPress Application Passwords over HTTP
 * Basic. Getting that credential used to mean either typing a password into
 * the app (impossible for a social-only member, and against the app's own
 * "no password is typed into the app" posture) or walking wp-admin's
 * authorize-application screen (admin chrome, other plugins' notices, and
 * "New Application Password Name" shown to an ordinary member).
 *
 * The bridge replaces both: the app opens {auth}/connect-app/ in a secure
 * in-app browser session, the member signs in however THIS SITE allows —
 * password, Google, Apple, two-factor, admin approval, all of it, because the
 * site's own login stack runs — and an approve screen then mints the
 * Application Password and hands it back to the app on its custom scheme,
 * using the exact query shape WP core's authorize-application flow produces,
 * so the app-side parser is shared.
 *
 * This class owns the bridge's invariants:
 *  - the SCHEME ALLOWLIST — the credential redirects only to a scheme we
 *    recognise, never wherever a crafted URL points;
 *  - the ONE-TIME TOKEN — a rendered approve screen is usable exactly once,
 *    so a stale or shared bridge URL cannot re-mint credentials;
 *  - the deep-link assembly, including the app-supplied `state` echo the app
 *    uses to reject redirects it never initiated.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\App;

defined( 'ABSPATH' ) || exit;

/**
 * Scheme allowlist, one-time bridge tokens, and deep-link assembly.
 */
class AppConnectService {

	/**
	 * Custom URL schemes the bridge may redirect a credential to.
	 *
	 * @var string[]
	 */
	private const SCHEMES = array( 'buddynextapp' );

	/**
	 * Transient prefix for one-time bridge tokens.
	 */
	private const TOKEN_PREFIX = 'bn_app_bridge_';

	/**
	 * Bridge-token lifetime. Long enough to read the approve screen, short
	 * enough that a leaked URL goes stale fast.
	 */
	private const TOKEN_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * The schemes this site will hand credentials to.
	 *
	 * @return string[]
	 */
	public static function schemes(): array {
		/**
		 * Filter the custom URL schemes the app-connect bridge may redirect to.
		 *
		 * Every scheme here can RECEIVE AN APPLICATION PASSWORD. Add a scheme
		 * only for an app you ship; never a wildcard.
		 *
		 * @since 1.1.1
		 *
		 * @param string[] $schemes Allowed schemes.
		 */
		$schemes = (array) apply_filters( 'buddynext_app_connect_schemes', self::SCHEMES );

		return array_values( array_filter( array_map( 'strval', $schemes ) ) );
	}

	/**
	 * Is this scheme one the bridge may redirect a credential to?
	 *
	 * Shape first (RFC 3986 scheme grammar), then allowlist membership — the
	 * allowlist alone would still let a filter typo admit `javascript`.
	 *
	 * @param string $scheme Candidate scheme.
	 * @return bool
	 */
	public static function allowed_scheme( string $scheme ): bool {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ) {
			return false;
		}

		return in_array( $scheme, self::schemes(), true );
	}

	/**
	 * Mint a one-time bridge token for a freshly rendered approve screen.
	 *
	 * @return string
	 */
	public static function issue_bridge_token(): string {
		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN_PREFIX . $token, get_current_user_id(), self::TOKEN_TTL );

		return $token;
	}

	/**
	 * Consume a bridge token. True exactly once per token, and only for the
	 * member it was issued to — a token minted in one member's approve screen
	 * cannot authorise a mint for anybody else.
	 *
	 * @param string $token   Token from the approve screen.
	 * @param int    $user_id Member attempting the mint.
	 * @return bool
	 */
	public static function consume_bridge_token( string $token, int $user_id ): bool {
		if ( '' === $token || 1 !== preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) {
			return false;
		}

		$key   = self::TOKEN_PREFIX . $token;
		$owner = get_transient( $key );
		if ( false === $owner ) {
			return false;
		}

		delete_transient( $key );

		return (int) $owner === $user_id && $user_id > 0;
	}

	/**
	 * Assemble the deep link that returns the credential to the app.
	 *
	 * Query shape matches WP core's authorize-application.php redirect
	 * (site_url, user_login, password) so the app-side parser is one shared
	 * implementation. `state` is echoed back verbatim when the app supplied
	 * one — the app rejects a redirect whose state it did not generate, which
	 * closes forced-login redirects pushed at it from outside.
	 *
	 * @param string $scheme     Allowlisted scheme.
	 * @param string $user_login Member login.
	 * @param string $password   Freshly minted Application Password.
	 * @param string $state      App-supplied state nonce, '' when absent.
	 * @return string
	 */
	public static function deep_link( string $scheme, string $user_login, string $password, string $state = '' ): string {
		$args = array(
			'site_url'   => home_url(),
			'user_login' => $user_login,
			'password'   => $password,
		);
		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		return $scheme . '://auth?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Public URL of the bridge entry point, for discovery via /app/config.
	 *
	 * @return string
	 */
	public static function connect_url(): string {
		return \BuddyNext\Core\PageRouter::auth_url() . 'connect-app/';
	}
}
