<?php
/**
 * Social login (OAuth2) for BuddyNext.
 *
 * Registers providers into the `buddynext_auth_social_providers` seam that the
 * login/signup templates already render, and handles the OAuth round-trip via
 * clean rewrite routes on `template_redirect` (browser redirects, not JSON):
 *
 *   /oauth/{provider}/           → redirect to the provider's authorize endpoint
 *   /oauth/{provider}/callback/  → exchange code, match/create/link, log in
 *
 * Hardening: one-time CSRF `state` transient bound to an httpOnly browser cookie,
 * per-IP callback rate limit, provider-verified email required. A logged-in user
 * hitting the flow links the provider to their account (manage in profile edit).
 *
 * Account policy: a verified social email that matches an existing user logs
 * that user in (and links the social id); a new email creates an account only
 * when registration is open (`buddynext_reg_mode`), otherwise it is rejected.
 *
 * Provider config is a flat map so Google + Facebook share one OAuth2 flow;
 * add Apple/others by extending self::providers() (and its client-secret JWT).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth2 social login orchestrator.
 */
class SocialLogin {

	/**
	 * Option key holding per-provider settings.
	 *
	 * Shape: [ google => [enabled,client_id,client_secret], facebook => [...] ].
	 */
	private const OPTION = 'buddynext_social_login';

	/**
	 * Transient prefix for one-time CSRF state tokens.
	 */
	private const STATE_PREFIX = 'bn_social_state_';

	/**
	 * Browser-binding cookie (httpOnly) for an in-flight OAuth flow.
	 */
	private const STATE_COOKIE = 'bn_oauth_state';

	/**
	 * Max callback attempts per IP per minute (abuse guard).
	 */
	private const RATE_MAX = 12;

	/**
	 * Static provider definitions (endpoints + claim mapping).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function providers(): array {
		return array(
			'google'   => array(
				'label'     => 'Google',
				'icon'      => 'globe',
				'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'     => 'https://oauth2.googleapis.com/token',
				'userinfo'  => 'https://openidconnect.googleapis.com/v1/userinfo',
				'scope'     => 'openid email profile',
				'map'       => array(
					'id'       => 'sub',
					'email'    => 'email',
					'verified' => 'email_verified',
					'name'     => 'name',
				),
			),
			'facebook' => array(
				'label'     => 'Facebook',
				'icon'      => 'users',
				'authorize' => 'https://www.facebook.com/v19.0/dialog/oauth',
				'token'     => 'https://graph.facebook.com/v19.0/oauth/access_token',
				'userinfo'  => 'https://graph.facebook.com/me?fields=id,name,email',
				'scope'     => 'email public_profile',
				'map'       => array(
					'id'       => 'id',
					'email'    => 'email',
					'verified' => null,
					'name'     => 'name',
				),
			),
		);
	}

	/**
	 * Hook the seam filter + the OAuth request handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'buddynext_auth_social_providers', array( $this, 'expose_providers' ) );
		add_action( 'init', array( $this, 'register_routes' ) );
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
	}

	/**
	 * Register the unlink REST route.
	 *
	 * @return void
	 */
	public function register_rest(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/social/(?P<provider>[a-z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'rest_unlink' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);
	}

	/**
	 * REST: unlink a social provider from the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_unlink( \WP_REST_Request $request ): \WP_REST_Response {
		$provider = sanitize_key( (string) $request['provider'] );
		delete_user_meta( get_current_user_id(), 'bn_social_' . $provider . '_id' );
		return new \WP_REST_Response( array( 'unlinked' => true ), 200 );
	}

	/**
	 * Providers the current user has linked.
	 *
	 * @param int $user_id User id.
	 * @return array<string, bool> [ provider_id => linked ].
	 */
	public static function linked_for( int $user_id ): array {
		$out = array();
		foreach ( array_keys( self::providers() ) as $id ) {
			$out[ $id ] = '' !== (string) get_user_meta( $user_id, 'bn_social_' . $id . '_id', true );
		}
		return $out;
	}

	/**
	 * Provider labels for UI.
	 *
	 * @return array<string, string>
	 */
	public static function labels(): array {
		$out = array();
		foreach ( self::providers() as $id => $def ) {
			$out[ $id ] = (string) $def['label'];
		}
		return $out;
	}

	/**
	 * Register the clean OAuth rewrite routes.
	 *
	 *   /oauth/{provider}/           → start the flow
	 *   /oauth/{provider}/callback/  → provider redirect target
	 *
	 * Flushed via PageRouter's shared ROUTER_VERSION sentinel (bumped when this
	 * route set changes), so no per-request rule sniffing on every init.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		add_rewrite_tag( '%bn_oauth_provider%', '([a-z0-9_-]+)' );
		add_rewrite_tag( '%bn_oauth_action%', '(start|callback)' );
		add_rewrite_rule( '^oauth/([a-z0-9_-]+)/callback/?$', 'index.php?bn_oauth_action=callback&bn_oauth_provider=$matches[1]', 'top' );
		add_rewrite_rule( '^oauth/([a-z0-9_-]+)/?$', 'index.php?bn_oauth_action=start&bn_oauth_provider=$matches[1]', 'top' );
	}

	/**
	 * Read stored per-provider settings.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function settings(): array {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Is a provider enabled and fully configured?
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	private static function is_ready( string $id ): bool {
		$s = self::settings();
		$p = isset( $s[ $id ] ) && is_array( $s[ $id ] ) ? $s[ $id ] : array();
		return ! empty( $p['enabled'] ) && ! empty( $p['client_id'] ) && ! empty( $p['client_secret'] );
	}

	/**
	 * The exact OAuth redirect URI a provider must call back to.
	 *
	 * @param string $id Provider id.
	 * @return string
	 */
	public static function callback_url( string $id ): string {
		return home_url( '/oauth/' . rawurlencode( $id ) . '/callback/' );
	}

	/**
	 * Register configured providers into the login/signup SSO row.
	 *
	 * @param array<int, array<string, string>> $providers Existing providers.
	 * @return array<int, array<string, string>>
	 */
	public function expose_providers( $providers ) {
		$providers = is_array( $providers ) ? $providers : array();
		foreach ( self::providers() as $id => $def ) {
			if ( ! self::is_ready( $id ) ) {
				continue;
			}
			$providers[] = array(
				'id'    => $id,
				'label' => (string) $def['label'],
				'icon'  => (string) apply_filters( 'buddynext_social_icon', (string) $def['icon'], $id ),
				'url'   => home_url( '/oauth/' . rawurlencode( $id ) . '/' ),
			);
		}
		return $providers;
	}

	/**
	 * Dispatch OAuth start / callback requests early on init.
	 *
	 * @return void
	 */
	public function maybe_handle(): void {
		$action   = (string) get_query_var( 'bn_oauth_action' );
		$provider = sanitize_key( (string) get_query_var( 'bn_oauth_provider' ) );
		if ( '' === $action || '' === $provider ) {
			return;
		}
		if ( 'start' === $action ) {
			$this->start( $provider );
		} elseif ( 'callback' === $action ) {
			$this->callback( $provider );
		}
	}

	/**
	 * Begin the OAuth flow: store state, redirect to the provider.
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	private function start( string $id ): void {
		$defs = self::providers();
		if ( ! isset( $defs[ $id ] ) || ! self::is_ready( $id ) ) {
			$this->bail( __( 'That sign-in method is not available.', 'buddynext' ) );
		}

		$s     = self::settings()[ $id ];
		$state = wp_generate_password( 32, false );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'provider'    => $id,
				'redirect_to' => $redirect_to,
			),
			10 * MINUTE_IN_SECONDS
		);

		// Bind the flow to this browser: the callback must present the same state
		// in an httpOnly cookie, so a stolen/forged state alone cannot complete it.
		setcookie(
			self::STATE_COOKIE,
			$state,
			array(
				'expires'  => time() + 10 * MINUTE_IN_SECONDS,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$url = add_query_arg(
			array(
				'client_id'     => rawurlencode( (string) $s['client_id'] ),
				'redirect_uri'  => rawurlencode( self::callback_url( $id ) ),
				'response_type' => 'code',
				'scope'         => rawurlencode( (string) $defs[ $id ]['scope'] ),
				'state'         => $state,
			),
			(string) $defs[ $id ]['authorize']
		);

		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external provider URL by design.
		exit;
	}

	/**
	 * Handle the provider callback: verify, exchange, match/create, log in.
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	private function callback( string $id ): void {
		$this->rate_limit();

		$defs = self::providers();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $defs[ $id ] ) || ! self::is_ready( $id ) || '' === $code || '' === $state ) {
			$this->bail( __( 'Sign-in was cancelled or failed.', 'buddynext' ) );
		}

		// Same-browser check: the state cookie set at start must match.
		$cookie_state = isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::STATE_COOKIE ] ) ) : '';
		$this->clear_state_cookie();
		if ( ! hash_equals( $cookie_state, $state ) ) {
			$this->bail( __( 'Sign-in could not be verified for this browser. Please try again.', 'buddynext' ) );
		}

		$stored = get_transient( self::STATE_PREFIX . $state );
		delete_transient( self::STATE_PREFIX . $state );
		if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $id ) {
			$this->bail( __( 'Sign-in session expired. Please try again.', 'buddynext' ) );
		}

		$token = $this->exchange_code( $id, $code );
		if ( '' === $token ) {
			$this->bail( __( 'Could not verify your account with the provider.', 'buddynext' ) );
		}

		$profile = $this->fetch_profile( $id, $token );
		if ( empty( $profile['email'] ) ) {
			$this->bail( __( 'No verified email was returned by the provider.', 'buddynext' ) );
		}

		$user_id = $this->resolve_user( $id, $profile );
		if ( is_wp_error( $user_id ) ) {
			$this->bail( $user_id->get_error_message() );
		}

		wp_set_current_user( (int) $user_id );
		wp_set_auth_cookie( (int) $user_id, true, is_ssl() );

		$dest = ! empty( $stored['redirect_to'] ) ? (string) $stored['redirect_to'] : home_url( '/' );
		wp_safe_redirect( $dest );
		exit;
	}

	/**
	 * Exchange an auth code for an access token.
	 *
	 * @param string $id   Provider id.
	 * @param string $code Auth code.
	 * @return string Access token, or '' on failure.
	 */
	private function exchange_code( string $id, string $code ): string {
		$defs = self::providers();
		$s    = self::settings()[ $id ];

		$res = wp_remote_post(
			(string) $defs[ $id ]['token'],
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/json' ),
				'body'    => array(
					'client_id'     => (string) $s['client_id'],
					'client_secret' => (string) $s['client_secret'],
					'code'          => $code,
					'redirect_uri'  => self::callback_url( $id ),
					'grant_type'    => 'authorization_code',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return '';
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $body ) && ! empty( $body['access_token'] ) ? (string) $body['access_token'] : '';
	}

	/**
	 * Fetch the user's profile (id, email, name) from the provider.
	 *
	 * @param string $id    Provider id.
	 * @param string $token Access token.
	 * @return array{id:string,email:string,name:string}
	 */
	private function fetch_profile( string $id, string $token ): array {
		$defs = self::providers();
		$map  = (array) $defs[ $id ]['map'];

		$res = wp_remote_get(
			(string) $defs[ $id ]['userinfo'],
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return array(
				'id'    => '',
				'email' => '',
				'name'  => '',
			);
		}

		$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$d = is_array( $d ) ? $d : array();

		// A provider that reports an explicit unverified email is rejected.
		$verified_key = $map['verified'] ?? null;
		if ( null !== $verified_key && isset( $d[ $verified_key ] ) && ! filter_var( $d[ $verified_key ], FILTER_VALIDATE_BOOLEAN ) ) {
			return array(
				'id'    => '',
				'email' => '',
				'name'  => '',
			);
		}

		return array(
			'id'    => (string) ( $d[ $map['id'] ] ?? '' ),
			'email' => sanitize_email( (string) ( $d[ $map['email'] ] ?? '' ) ),
			'name'  => sanitize_text_field( (string) ( $d[ $map['name'] ] ?? '' ) ),
		);
	}

	/**
	 * Match an existing account by social id or email, or create one.
	 *
	 * @param string                                    $id      Provider id.
	 * @param array{id:string,email:string,name:string} $profile Provider profile.
	 * @return int|\WP_Error User id, or error when registration is closed.
	 */
	private function resolve_user( string $id, array $profile ) {
		$meta_key = 'bn_social_' . $id . '_id';

		// Who, if anyone, already owns this provider identity?
		$owner = 0;
		if ( '' !== $profile['id'] ) {
			$linked = get_users(
				array(
					'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $profile['id'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'number'     => 1,
					'fields'     => 'ID',
				)
			);
			$owner  = ! empty( $linked ) ? (int) $linked[0] : 0;
		}

		// Connect flow — a logged-in member is linking this provider to their
		// own account from profile settings. Refuse if it already belongs to
		// someone else; otherwise link and return.
		if ( is_user_logged_in() ) {
			$current = get_current_user_id();
			if ( $owner && $owner !== $current ) {
				return new \WP_Error( 'bn_social_taken', __( 'That account is already linked to another member.', 'buddynext' ) );
			}
			update_user_meta( $current, $meta_key, $profile['id'] );
			return $current;
		}

		// 1) Login flow — already linked to this provider identity.
		if ( $owner ) {
			return $owner;
		}

		// 2) Existing account with the same verified email — link + log in.
		$existing = get_user_by( 'email', $profile['email'] );
		if ( $existing instanceof \WP_User ) {
			update_user_meta( $existing->ID, $meta_key, $profile['id'] );
			return (int) $existing->ID;
		}

		// 3) New email — only create when registration is open.
		if ( 'open' !== (string) get_option( 'buddynext_reg_mode', 'open' ) ) {
			return new \WP_Error( 'bn_reg_closed', __( 'Registration is closed, so a new account could not be created.', 'buddynext' ) );
		}

		$login   = $this->unique_login( $profile['email'] );
		$user_id = wp_create_user( $login, wp_generate_password( 24, true, true ), $profile['email'] );
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( '' !== $profile['name'] ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $profile['name'],
				)
			);
		}
		update_user_meta( (int) $user_id, $meta_key, $profile['id'] );
		// Social emails are provider-verified — mark BN email verification satisfied.
		update_user_meta( (int) $user_id, 'bn_email_verified', 1 );

		/**
		 * Fires after a social login creates a new BuddyNext account.
		 *
		 * @param int    $user_id  New user id.
		 * @param string $provider Provider id.
		 * @param array  $profile  Provider profile (id, email, name).
		 */
		do_action( 'buddynext_social_user_created', (int) $user_id, $id, $profile );

		return (int) $user_id;
	}

	/**
	 * Derive a unique login from an email local-part.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function unique_login( string $email ): string {
		$base  = sanitize_user( current( explode( '@', $email ) ), true );
		$base  = '' !== $base ? $base : 'member';
		$login = $base;
		$n     = 1;
		while ( username_exists( $login ) ) {
			++$n;
			$login = $base . $n;
		}
		return $login;
	}

	/**
	 * Redirect back to login with an error message.
	 *
	 * @param string $message Error text.
	 * @return void
	 */
	private function bail( string $message ): void {
		$login = home_url( '/' . (string) get_option( 'buddynext_slug_login', 'login' ) . '/' );
		wp_safe_redirect( add_query_arg( 'bn_social_error', rawurlencode( $message ), $login ) );
		exit;
	}

	/**
	 * Abuse guard: cap callback hits per IP per minute.
	 *
	 * @return void
	 */
	private function rate_limit(): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'bn_oauth_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_MAX ) {
			$this->bail( __( 'Too many sign-in attempts. Please wait a minute and try again.', 'buddynext' ) );
		}
		set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Expire the browser-binding state cookie.
	 *
	 * @return void
	 */
	private function clear_state_cookie(): void {
		setcookie(
			self::STATE_COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}
}
