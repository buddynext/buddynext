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
 * add Apple/others by extending self::get_providers() (and its client-secret JWT).
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

use BuddyNext\Core\RateLimiter;

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
	 * Provider definitions (endpoints + claim mapping), filterable.
	 *
	 * This is the single source of truth for every OAuth flow — the login UI,
	 * the /oauth/{id}/ start + callback routes, the token exchange, and the
	 * profile-claim mapping all read from here. Register a third-party provider
	 * via the buddynext_oauth_providers filter and it works end-to-end; the
	 * definition must keep the full shape: each entry needs label, icon (a
	 * BuddyNext icon slug), authorize, token, userinfo, scope, and a map with
	 * id/email/verified/name claim keys.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_providers(): array {
		/**
		 * Filter the OAuth provider definitions.
		 *
		 * @param array<string, array<string, mixed>> $providers Provider map keyed by id.
		 */
		return (array) apply_filters( 'buddynext_oauth_providers', self::provider_defaults() );
	}

	/**
	 * Built-in provider definitions (endpoints + claim mapping).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function provider_defaults(): array {
		return array(
			'google'   => array(
				'label'       => 'Google',
				'icon'        => 'google',
				'authorize'   => 'https://accounts.google.com/o/oauth2/v2/auth',
				'token'       => 'https://oauth2.googleapis.com/token',
				'userinfo'    => 'https://openidconnect.googleapis.com/v1/userinfo',
				'scope'       => 'openid email profile',
				'map'         => array(
					'id'       => 'sub',
					'email'    => 'email',
					'verified' => 'email_verified',
					'name'     => 'name',
					'picture'  => 'picture',
				),
				'console_url' => 'https://console.cloud.google.com/apis/credentials',
				'setup_steps' => array(
					__( 'Open Google Cloud Console and pick (or create) a project.', 'buddynext' ),
					__( 'Go to "APIs & Services" → "OAuth consent screen" and fill in your app name and support email.', 'buddynext' ),
					__( 'Go to "Credentials" → "Create credentials" → "OAuth client ID" → choose "Web application".', 'buddynext' ),
					__( 'Under "Authorized redirect URIs" paste the redirect URI shown below, then click Create.', 'buddynext' ),
					__( 'Copy the Client ID and Client secret it shows you and paste them here.', 'buddynext' ),
				),
			),
			'facebook' => array(
				'label'       => 'Facebook',
				'icon'        => 'facebook',
				'authorize'   => 'https://www.facebook.com/v19.0/dialog/oauth',
				'token'       => 'https://graph.facebook.com/v19.0/oauth/access_token',
				'userinfo'    => 'https://graph.facebook.com/me?fields=id,name,email,picture.width(192)',
				'scope'       => 'email public_profile',
				'map'         => array(
					'id'       => 'id',
					'email'    => 'email',
					'verified' => null,
					'name'     => 'name',
					'picture'  => 'picture.data.url',
				),
				// Facebook does not return a per-response verified flag, but its
				// platform only exposes confirmed primary emails, so the address
				// can be trusted for account linking.
				'trust_email' => true,
				'console_url' => 'https://developers.facebook.com/apps/',
				'setup_steps' => array(
					__( 'Open Facebook for Developers and click "Create App" (choose the "Authenticate and request data from users" use case).', 'buddynext' ),
					__( 'In the app, add the "Facebook Login" product.', 'buddynext' ),
					__( 'Under Facebook Login → Settings, paste the redirect URI shown below into "Valid OAuth Redirect URIs".', 'buddynext' ),
					__( 'Open Settings → Basic and copy the "App ID" (Client ID) and "App Secret" (Client Secret) here.', 'buddynext' ),
					__( 'Switch the app to "Live" mode so anyone can sign in.', 'buddynext' ),
				),
			),
			'github'   => array(
				'label'          => 'GitHub',
				'icon'           => 'github',
				'authorize'      => 'https://github.com/login/oauth/authorize',
				'token'          => 'https://github.com/login/oauth/access_token',
				'userinfo'       => 'https://api.github.com/user',
				'email_endpoint' => 'https://api.github.com/user/emails',
				'scope'          => 'read:user user:email',
				'map'            => array(
					'id'      => 'id',
					'email'   => 'email',
					'name'    => 'name',
					'picture' => 'avatar_url',
				),
				'console_url'    => 'https://github.com/settings/developers',
				'setup_steps'    => array(
					__( 'Open GitHub → Settings → Developer settings → "OAuth Apps" → "New OAuth App".', 'buddynext' ),
					__( 'Set the "Authorization callback URL" to the redirect URI shown below.', 'buddynext' ),
					__( 'Click "Register application".', 'buddynext' ),
					__( 'Copy the "Client ID", then click "Generate a new client secret" and copy that too.', 'buddynext' ),
				),
			),
			'discord'  => array(
				'label'       => 'Discord',
				'icon'        => 'discord',
				'authorize'   => 'https://discord.com/api/oauth2/authorize',
				'token'       => 'https://discord.com/api/oauth2/token',
				'userinfo'    => 'https://discord.com/api/users/@me',
				'scope'       => 'identify email',
				'map'         => array(
					'id'       => 'id',
					'email'    => 'email',
					'verified' => 'verified',
					'name'     => 'global_name',
					'picture'  => null,
				),
				'console_url' => 'https://discord.com/developers/applications',
				'setup_steps' => array(
					__( 'Open the Discord Developer Portal and click "New Application".', 'buddynext' ),
					__( 'Open the "OAuth2" tab and copy the "Client ID" and "Client Secret" here.', 'buddynext' ),
					__( 'Still on OAuth2, under "Redirects", add the redirect URI shown below and save.', 'buddynext' ),
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
		$user_id  = get_current_user_id();

		// Refuse to delete the member's LAST credential. A social sign-up is created
		// with a random password it never shows anyone (see create_member), so a
		// Google-only member who unlinks Google is left with no way in: they cannot
		// sign in, and they cannot even change their password because they do not
		// know the current one. They would have to guess that a "forgot password"
		// flow exists for a password they never had.
		if ( self::is_last_credential( $user_id, $provider ) ) {
			return new \WP_REST_Response(
				array(
					'unlinked' => false,
					'code'     => 'bn_last_credential',
					'message'  => __( 'This is the only way you can sign in. Set a password first, then you can unlink it.', 'buddynext' ),
				),
				409
			);
		}

		// Drop the indexed lookup key alongside the readable one. Leaving it behind
		// would keep pointing this provider identity at a member who is no longer
		// linked to it.
		$identity = (string) get_user_meta( $user_id, 'bn_social_' . $provider . '_id', true );
		$indexed  = self::identity_key( $provider, $identity );
		if ( '' !== $indexed ) {
			delete_user_meta( $user_id, $indexed );
		}

		delete_user_meta( $user_id, 'bn_social_' . $provider . '_id' );
		return new \WP_REST_Response( array( 'unlinked' => true ), 200 );
	}

	/**
	 * The INDEXED user-meta key for one provider identity.
	 *
	 * WordPress indexes usermeta.meta_key but NOT meta_value, so the natural lookup
	 * ("find the row whose bn_social_google_id equals this id") narrows on the key
	 * and then scans every row that carries it — every Google-linked member on the
	 * site, on every single sign-in. At fleet scale (100k members, most on one
	 * provider) that is a six-figure row scan per login.
	 *
	 * Putting the provider's id INTO the key makes the existing meta_key index do
	 * the work: one indexed seek instead of a scan. Provider ids are far shorter
	 * than the index's 191-character prefix, so the whole key is covered.
	 *
	 * The value-carrying `bn_social_{provider}_id` meta stays — unlink and the
	 * linked-providers list read it, and it remains the human-readable record.
	 *
	 * @param string $provider    Provider id (e.g. 'google').
	 * @param string $identity_id The provider's own user id.
	 * @return string Meta key, or '' when the identity is empty.
	 */
	private static function identity_key( string $provider, string $identity_id ): string {
		$provider    = sanitize_key( $provider );
		$identity_id = trim( $identity_id );

		if ( '' === $provider || '' === $identity_id ) {
			return '';
		}

		// md5 keeps the key bounded and index-friendly whatever a provider emits.
		// This is a lookup key, not a secret, so a fast digest is the right tool.
		return 'bn_social_' . $provider . '_uid_' . md5( $identity_id );
	}

	/**
	 * Which member owns this provider identity, if any?
	 *
	 * Tries the indexed key first. Falls back to the old unindexed scan for members
	 * linked before the indexed key existed — and, on a hit, writes the indexed key
	 * so that member is never scanned for again. The backfill is lazy and
	 * self-healing: no migration, and the scan disappears as members sign in.
	 *
	 * @param string $provider    Provider id.
	 * @param string $identity_id The provider's own user id.
	 * @return int Owning user id, or 0 when nobody has linked this identity.
	 */
	private static function owner_of_identity( string $provider, string $identity_id ): int {
		$indexed = self::identity_key( $provider, $identity_id );
		if ( '' === $indexed ) {
			return 0;
		}

		$hit = get_users(
			array(
				'meta_key' => $indexed, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- the point of this key is that it IS indexed.
				'number'   => 1,
				'fields'   => 'ID',
			)
		);

		if ( ! empty( $hit ) ) {
			return (int) $hit[0];
		}

		// Legacy row: linked before the indexed key existed.
		$legacy = get_users(
			array(
				'meta_key'   => 'bn_social_' . sanitize_key( $provider ) . '_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $identity_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'ID',
			)
		);

		if ( empty( $legacy ) ) {
			return 0;
		}

		$owner = (int) $legacy[0];
		update_user_meta( $owner, $indexed, 1 ); // Backfill, so this member is never scanned for again.

		return $owner;
	}

	/**
	 * Link a provider identity to a member — both the readable meta and the
	 * indexed lookup key, so the two can never disagree.
	 *
	 * @param int    $user_id     Member.
	 * @param string $provider    Provider id.
	 * @param string $identity_id The provider's own user id.
	 * @return void
	 */
	public static function link_identity( int $user_id, string $provider, string $identity_id ): void {
		update_user_meta( $user_id, 'bn_social_' . sanitize_key( $provider ) . '_id', $identity_id );

		$indexed = self::identity_key( $provider, $identity_id );
		if ( '' !== $indexed ) {
			update_user_meta( $user_id, $indexed, 1 );
		}
	}

	/**
	 * Would unlinking this provider leave the member with no way to sign in?
	 *
	 * True when it is their last linked provider AND they have no password they
	 * actually know. WordPress always stores a hash, so the hash cannot tell us
	 * this — a social sign-up is given a random one it never reveals. The
	 * `bn_password_set` marker records that the member chose the password
	 * themselves (at signup, on change, or via a reset).
	 *
	 * @param int    $user_id  Member being changed.
	 * @param string $provider Provider about to be unlinked.
	 * @return bool True when the unlink would strand them.
	 */
	public static function is_last_credential( int $user_id, string $provider ): bool {
		if ( \BuddyNext\Auth\AuthController::has_known_password( $user_id ) ) {
			return false; // They can still sign in with a password.
		}

		foreach ( self::linked_for( $user_id ) as $id => $linked ) {
			if ( $linked && $id !== $provider ) {
				return false; // Another provider still gets them in.
			}
		}

		return true;
	}

	/**
	 * Providers the current user has linked.
	 *
	 * @param int $user_id User id.
	 * @return array<string, bool> [ provider_id => linked ].
	 */
	public static function linked_for( int $user_id ): array {
		$out = array();
		foreach ( array_keys( self::get_providers() ) as $id ) {
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
		foreach ( self::get_providers() as $id => $def ) {
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
		return home_url( '/oauth/' . rawurlencode( $id ) . '/callback/' ); // bn-route-ok: plugin-registered fixed /oauth/ rewrite.
	}

	/**
	 * Register configured providers into the login/signup SSO row.
	 *
	 * @param array<int, array<string, string>> $providers Existing providers.
	 * @return array<int, array<string, string>>
	 */
	public function expose_providers( $providers ) {
		$providers = is_array( $providers ) ? $providers : array();

		// Carry an invitation token onto the social buttons. Someone who opened
		// /signup/?invite=TOKEN and then clicked "Continue with Google" used to lose
		// the invitation on the redirect, so on an invite-only community the button
		// rejected them — despite them holding a valid invite.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invite = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['invite'] ) ) : '';

		foreach ( self::get_providers() as $id => $def ) {
			if ( ! self::is_ready( $id ) ) {
				continue;
			}

			$url = home_url( '/oauth/' . rawurlencode( $id ) . '/' ); // bn-route-ok: plugin-registered fixed /oauth/ rewrite.
			if ( '' !== $invite ) {
				$url = add_query_arg( 'invite', rawurlencode( $invite ), $url );
			}

			$providers[] = array(
				'id'    => $id,
				'label' => (string) $def['label'],
				'icon'  => (string) apply_filters( 'buddynext_social_icon', (string) $def['icon'], $id ),
				'url'   => $url,
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
		// Throttle the START of the flow, not just the callback. This route is an
		// unauthenticated GET that unconditionally writes a 10-minute transient on
		// every single hit — with an attacker-controlled redirect_to payload inside
		// it. Unthrottled, hammering /oauth/google/ filled wp_options with unbounded
		// _transient_bn_social_state_* rows (the default path with no persistent
		// object cache): storage exhaustion, no auth, no cost to the attacker.
		$this->rate_limit();

		$defs = self::get_providers();
		if ( ! isset( $defs[ $id ] ) || ! self::is_ready( $id ) ) {
			$this->bail( 'unavailable' );
		}

		$s     = self::settings()[ $id ];
		$state = wp_generate_password( 32, false );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_GET['redirect_to'] ) ) : '';

		// Carry the invitation token through the round-trip. Without this the state
		// transient held only redirect_to, so an invite could never be redeemed via
		// OAuth — and on an invite-only community the social buttons rejected
		// EVERYONE, including people holding a perfectly valid invitation. Respected
		// is not the same as usable.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$invite = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['invite'] ) ) : '';

		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'provider'    => $id,
				'redirect_to' => $redirect_to,
				'invite'      => $invite,
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

		$defs = self::get_providers();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! isset( $defs[ $id ] ) || ! self::is_ready( $id ) || '' === $code || '' === $state ) {
			$this->bail( 'cancelled' );
		}

		// Same-browser check: the state cookie set at start must match.
		$cookie_state = isset( $_COOKIE[ self::STATE_COOKIE ] ) ? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::STATE_COOKIE ] ) ) : '';
		$this->clear_state_cookie();
		if ( ! hash_equals( $cookie_state, $state ) ) {
			$this->bail( 'bad_browser' );
		}

		$stored = get_transient( self::STATE_PREFIX . $state );
		delete_transient( self::STATE_PREFIX . $state );
		if ( ! is_array( $stored ) || ( $stored['provider'] ?? '' ) !== $id ) {
			$this->bail( 'expired' );
		}

		$token = $this->exchange_code( $id, $code );
		if ( '' === $token ) {
			$this->bail( 'provider_failed' );
		}

		$profile = $this->fetch_profile( $id, $token );
		if ( empty( $profile['email'] ) ) {
			$this->bail( 'no_email' );
		}

		$invite   = (string) ( $stored['invite'] ?? '' );
		$resolved = $this->resolve_user( $id, $profile, $invite );
		if ( is_wp_error( $resolved ) ) {
			$this->bail( $this->code_for( $resolved ) );
		}

		// A pending-signup token: the owner requires something OAuth cannot give
		// us (terms consent, a required profile field). No account exists yet.
		// Send them to finish on a real form.
		if ( is_string( $resolved ) ) {
			wp_safe_redirect(
				add_query_arg(
					'bn_pending',
					rawurlencode( $resolved ),
					\BuddyNext\Core\PageRouter::auth_url() . 'complete/'
				)
			);
			exit;
		}

		// Session issuance goes through SessionIssuer — the ONLY thing allowed to
		// hand out an auth cookie. This is what closes both critical bypasses:
		// the admin-approval hold is a wp_authenticate_user filter and two-factor
		// interposes on the authenticate chain, and setting the cookie by hand
		// here (as this method used to) skipped both of them entirely.
		$session = buddynext_service( 'session' )->start( (int) $resolved, true );

		if ( is_wp_error( $session ) ) {
			// Two-factor: the member must complete the challenge before any session
			// exists. Hand the ticket to the auth hub rather than signing them in.
			if ( 'bn_2fa_required' === $session->get_error_code() ) {
				$ticket = (string) ( $session->get_error_data()['ticket'] ?? '' );
				wp_safe_redirect(
					add_query_arg( 'bn_2fa', rawurlencode( $ticket ), \BuddyNext\Core\PageRouter::auth_url() )
				);
				exit;
			}

			// Anything else (notably the admin-approval hold) is a refusal.
			$this->bail( $this->code_for( $session ) );
		}

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
		$defs = self::get_providers();
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
	 * Fetch the user's profile from the provider.
	 *
	 * Returns the canonical id, email, display name, avatar URL, and crucially
	 * whether the provider asserts the email is VERIFIED — the account-resolution
	 * step only merges into an existing local account when that is true, which is
	 * the difference between safe sign-in and email-based account takeover.
	 *
	 * @param string $id    Provider id.
	 * @param string $token Access token.
	 * @return array{id:string,email:string,name:string,picture:string,email_verified:bool}
	 */
	private function fetch_profile( string $id, string $token ): array {
		$defs  = self::get_providers();
		$def   = (array) $defs[ $id ];
		$map   = (array) $def['map'];
		$empty = array(
			'id'             => '',
			'email'          => '',
			'name'           => '',
			'picture'        => '',
			'email_verified' => false,
		);

		$d = $this->api_get_json( (string) $def['userinfo'], $token );
		if ( null === $d ) {
			return $empty;
		}

		$email    = sanitize_email( (string) $this->claim( $d, (string) ( $map['email'] ?? '' ) ) );
		$verified = false;

		// GitHub keeps email on a separate endpoint and ONLY there reports which
		// address is primary AND verified — read it from the horse's mouth.
		//
		// This used to run only when /user returned no email at all. But GitHub's
		// /user DOES return `email` whenever the member has set a public profile
		// email, so for those members the lookup was skipped — and since GitHub has
		// no inline `verified` claim and no trust_email, they came out UNVERIFIED.
		// Same site, same provider: one member auto-linked, the next hit the "sign
		// in with your password first" wall, depending on a setting on THEIR GitHub
		// profile that neither they nor the admin would ever connect to the
		// failure. With email verification on, those accounts were stuck unverified
		// forever. If the provider has an email endpoint, it is authoritative:
		// always ask.
		if ( ! empty( $def['email_endpoint'] ) ) {
			$emails = $this->api_get_json( (string) $def['email_endpoint'], $token );
			if ( is_array( $emails ) ) {
				foreach ( $emails as $row ) {
					if ( ! empty( $row['primary'] ) && ! empty( $row['verified'] ) && ! empty( $row['email'] ) ) {
						$email    = sanitize_email( (string) $row['email'] );
						$verified = true;
						break;
					}
				}
			}
		} else {
			// Inline verified claim (Google: email_verified, Discord: verified),
			// or a provider explicitly flagged as always returning trusted emails
			// (Facebook). Unknown/custom providers stay UNtrusted by default — the
			// safe stance against email-based account takeover.
			$verified_key = $map['verified'] ?? null;
			if ( null !== $verified_key ) {
				$verified = filter_var( $this->claim( $d, (string) $verified_key ), FILTER_VALIDATE_BOOLEAN );
			} elseif ( ! empty( $def['trust_email'] ) ) {
				$verified = true;
			}
		}

		/**
		 * Filter whether a provider's email address may be treated as verified.
		 *
		 * This one boolean decides whether an OAuth identity may be merged into an
		 * EXISTING local account, so it is the most security-sensitive value in the
		 * flow. Facebook ships trusted on the platform's own assurance; an owner who
		 * is not comfortable with that assumption can revoke it here rather than
		 * having to disable the provider outright.
		 *
		 * @param bool                 $verified Whether the provider asserts the address is verified.
		 * @param string               $id       Provider id (google, facebook, github, discord…).
		 * @param array<string, mixed> $d        Raw provider profile payload.
		 */
		$verified = (bool) apply_filters( 'buddynext_social_email_verified', (bool) $verified, $id, (array) $d );

		return array(
			'id'             => (string) $this->claim( $d, (string) ( $map['id'] ?? '' ) ),
			'email'          => $email,
			'name'           => sanitize_text_field( (string) $this->claim( $d, (string) ( $map['name'] ?? '' ) ) ),
			'picture'        => esc_url_raw( (string) $this->claim( $d, (string) ( $map['picture'] ?? '' ) ) ),
			'email_verified' => (bool) $verified,
		);
	}

	/**
	 * GET a Bearer-authenticated JSON endpoint and decode it.
	 *
	 * @param string $url   Endpoint.
	 * @param string $token Access token.
	 * @return array<mixed>|null Decoded array, or null on transport failure.
	 */
	private function api_get_json( string $url, string $token ): ?array {
		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					// GitHub (and good manners) require a User-Agent.
					'User-Agent'    => 'BuddyNext',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return null;
		}
		$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $d ) ? $d : null;
	}

	/**
	 * Read a possibly-nested claim from a decoded payload using a dotted path
	 * (e.g. "picture.data.url" for Facebook). Returns '' when absent.
	 *
	 * @param array<mixed> $data Decoded payload.
	 * @param string       $path Dotted claim path.
	 * @return mixed
	 */
	private function claim( array $data, string $path ) {
		if ( '' === $path ) {
			return '';
		}
		$node = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( is_array( $node ) && array_key_exists( $segment, $node ) ) {
				$node = $node[ $segment ];
			} else {
				return '';
			}
		}
		return is_scalar( $node ) ? $node : '';
	}

	/**
	 * Match an existing account by social id or verified email, or create one.
	 *
	 * Security: an unlinked provider identity is merged into an EXISTING local
	 * account only when the provider asserts the email is verified — otherwise an
	 * attacker who registered a provider account under someone else's address
	 * could take over that local account (the classic social-login takeover, e.g.
	 * Nextend CVE-2024-9893). An unverified address can no longer create a new
	 * account either — it used to be allowed on the reasoning that there was
	 * nothing to take over, but it let an attacker squat someone else's email and
	 * permanently block the real owner from ever registering.
	 *
	 * @param string                                                                       $id      Provider id.
	 * @param array{id:string,email:string,name:string,picture:string,email_verified:bool} $profile Provider profile.
	 * @param string                                                                       $invite  Invitation token carried through the OAuth round-trip.
	 * @return int|string|\WP_Error User id, a pending-signup token, or an error.
	 */
	private function resolve_user( string $id, array $profile, string $invite = '' ) {
		$meta_key = 'bn_social_' . $id . '_id';

		// Who, if anyone, already owns this provider identity?
		$owner = 0;
		if ( '' !== $profile['id'] ) {
			$owner = self::owner_of_identity( $id, (string) $profile['id'] );
		}

		// Connect flow — a logged-in member is linking this provider to their
		// own account from profile settings. Refuse if it already belongs to
		// someone else; otherwise link and return.
		if ( is_user_logged_in() ) {
			$current = get_current_user_id();
			if ( $owner && $owner !== $current ) {
				return new \WP_Error( 'bn_social_taken', __( 'That account is already linked to another member.', 'buddynext' ) );
			}
			self::link_identity( (int) $current, $id, (string) $profile['id'] );
			return $current;
		}

		// 1) Login flow — already linked to this provider identity.
		if ( $owner ) {
			return $owner;
		}

		// 2) An account already uses this email. Only auto-link when the provider
		// verified the address; otherwise send them to sign in and link manually.
		$existing = get_user_by( 'email', $profile['email'] );
		if ( $existing instanceof \WP_User ) {
			if ( ! $profile['email_verified'] ) {
				return new \WP_Error(
					'bn_social_unverified',
					__( 'An account already uses this email. Please sign in with your password, then link this account from your profile settings.', 'buddynext' )
				);
			}
			update_user_meta( $existing->ID, $meta_key, $profile['id'] );
			return (int) $existing->ID;
		}

		// 3) No existing account. From here the SHARED registration gate decides —
		// the same one the BuddyNext form and the WordPress core form pass through
		// — so the owner's policy binds on this door too. It previously did not:
		// social login reimplemented registration and inherited none of it.

		// A provider-verified address is required to CREATE an account, not just to
		// link into an existing one. It used to be required only for linking, so an
		// unverified address could still mint a brand-new account under someone
		// else's email — squatting it, because the real owner is then permanently
		// blocked from registering ("an account already exists with this email").
		// Registration denial-of-service, and a ready-made phishing surface.
		if ( ! $profile['email_verified'] ) {
			return new \WP_Error(
				'bn_social_unverified_new',
				__( 'Your provider did not confirm that this email address belongs to you, so we could not create an account. Please sign up with your email instead.', 'buddynext' )
			);
		}

		$policy = buddynext_service( 'registration_policy' );

		// Access policy: registration mode (incl. closed), the invite requirement,
		// and the allowed-domain allowlist. The allowlist in particular is an
		// access decision the owner made about who may join — a community
		// restricted to one corporate domain used to be wide open to any Google
		// account, because this door never asked.
		$access = $policy->check_access(
			(string) $profile['email'],
			'' !== $invite ? $invite : null,
			RegistrationPolicy::SOURCE_SOCIAL
		);
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// Spam signals that do not need a form: the rate limit and the
		// disposable-domain list. The honeypot, time-trap and human check are
		// properties of a rendered form, so RegistrationGuard skips them for this
		// source rather than failing a sign-in that could never carry them.
		$guard = ( new RegistrationGuard() )->check(
			array(
				'source' => RegistrationPolicy::SOURCE_SOCIAL,
				'email'  => (string) $profile['email'],
				'ip'     => AuthController::client_ip(),
			)
		);
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$pending = array(
			'provider'       => $id,
			'uid'            => (string) $profile['id'],
			'email'          => (string) $profile['email'],
			'email_verified' => (bool) $profile['email_verified'],
			'name'           => (string) $profile['name'],
			'picture'        => (string) $profile['picture'],
			// The invite must survive the finish-signup step, or a social sign-up
			// that needs terms consent would silently lose its invitation.
			'invite'         => $invite,
		);

		// The owner may require terms consent and/or profile fields that OAuth
		// simply cannot supply. Park the signup and finish it on a real form —
		// do NOT create an account we would then have to patch. Creating first and
		// patching after is exactly what let a visitor defeat admin-approval mode
		// by clicking the social button twice.
		if ( ! empty( $policy->missing( array( 'email' => $profile['email'] ) ) ) ) {
			return PendingSignup::park( $pending );
		}

		return $this->create_member( $pending );
	}

	/**
	 * Create a member from a (complete) social signup.
	 *
	 * Goes through RegistrationService like every other door, so the new account
	 * gets the DM-privacy seed, the profile fields, the approval hold and the
	 * canonical hooks — all of which social login used to skip silently.
	 *
	 * @param array<string,mixed> $pending Parked/complete provider profile.
	 * @param array<string,mixed> $extra   Additional signup data (terms, bn_field_*).
	 * @return int|\WP_Error New user id, or an error.
	 */
	public function create_member( array $pending, array $extra = array() ) {
		$user_id = buddynext_service( 'registration' )->create(
			array_merge(
				$extra,
				array(
					'email'      => (string) $pending['email'],
					'user_login' => RegistrationService::unique_login( (string) $pending['email'] ),
					'password'   => wp_generate_password( 24, true, true ),
					'invite'     => (string) ( $pending['invite'] ?? '' ),
					'social'     => array(
						'provider'       => (string) $pending['provider'],
						'uid'            => (string) $pending['uid'],
						'email_verified' => ! empty( $pending['email_verified'] ),
					),
				)
			)
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = (int) $user_id;

		if ( '' !== (string) $pending['name'] ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => (string) $pending['name'],
				)
			);
		}

		// Adopt the provider avatar when the member has none yet.
		if ( '' !== (string) $pending['picture'] && '' === (string) get_user_meta( $user_id, 'bn_avatar', true ) ) {
			update_user_meta( $user_id, 'bn_avatar', esc_url_raw( (string) $pending['picture'] ) );
		}

		/**
		 * Fires after a social login creates a new BuddyNext account.
		 *
		 * @param int    $user_id  New user id.
		 * @param string $provider Provider id.
		 * @param array  $profile  Provider profile (id, email, name, picture).
		 */
		do_action( 'buddynext_social_user_created', $user_id, (string) $pending['provider'], $pending );

		return $user_id;
	}


	/**
	 * Redirect back to the login screen with an error CODE.
	 *
	 * @param string $code Error code from the error_message() allowlist.
	 * @return void
	 */
	private function bail( string $code ): void {
		// A CODE, never a message. This used to redirect with the human-readable
		// error text in the query string, and templates/auth/login.php rendered
		// whatever it found there into a role="alert" banner. Anyone could hand out
		// a link like ?bn_social_error=Your+account+is+locked.+Call+1-800-... and
		// have that sentence appear on the real, TLS-valid login page of the real
		// domain. Not XSS — the output was escaped — but a first-class phishing
		// primitive on the most trust-sensitive page in the product.
		//
		// PageRouter::auth_url() reads the canonical buddynext_slug_auth option;
		// this previously read a never-written buddynext_slug_login key, so the
		// error redirect broke the moment the auth slug was renamed.
		wp_safe_redirect(
			add_query_arg( 'bn_social_error', rawurlencode( sanitize_key( $code ) ), \BuddyNext\Core\PageRouter::auth_url() )
		);
		exit;
	}

	/**
	 * Resolve a social sign-in error code to its message.
	 *
	 * The map is the allowlist: an unknown code (i.e. anything an attacker put in
	 * the URL) collapses to one generic sentence, so the query string cannot be
	 * used to put words on our login screen.
	 *
	 * @param string $code Error code from bail().
	 * @return string Translated, safe-to-display message.
	 */
	public static function error_message( string $code ): string {
		$messages = array(
			'unavailable'      => __( 'That sign-in method is not available.', 'buddynext' ),
			'cancelled'        => __( 'Sign-in was cancelled or failed.', 'buddynext' ),
			'bad_browser'      => __( 'Sign-in could not be verified for this browser. Please try again.', 'buddynext' ),
			'expired'          => __( 'Sign-in session expired. Please try again.', 'buddynext' ),
			'provider_failed'  => __( 'Could not verify your account with the provider.', 'buddynext' ),
			'no_email'         => __( 'No email address was returned by the provider, so we could not sign you in.', 'buddynext' ),
			'rate_limited'     => __( 'Too many sign-in attempts. Please wait a minute and try again.', 'buddynext' ),
			'taken'            => __( 'That account is already linked to another member.', 'buddynext' ),
			'unverified'       => __( 'An account already uses this email. Please sign in with your password, then link this account from your profile settings.', 'buddynext' ),
			'unverified_new'   => __( 'Your provider did not confirm that this email address belongs to you, so we could not create an account. Please sign up with your email instead.', 'buddynext' ),
			'pending_approval' => __( 'Your account is awaiting administrator approval.', 'buddynext' ),
			'reg_closed'       => __( 'Registration is closed on this community.', 'buddynext' ),
			'reg_invite'       => __( 'This community is invite-only. You need an invitation to join.', 'buddynext' ),
			'reg_domain'       => __( 'Only users from allowed email domains may register.', 'buddynext' ),
		);

		$code = sanitize_key( $code );

		return $messages[ $code ] ?? __( 'Sign-in failed. Please try again.', 'buddynext' );
	}

	/**
	 * Map a WP_Error from the resolve/session steps onto a bail() code.
	 *
	 * @param \WP_Error $error Error to translate.
	 * @return string Bail code.
	 */
	private function code_for( \WP_Error $error ): string {
		$map = array(
			'bn_social_taken'          => 'taken',
			'bn_social_unverified'     => 'unverified',
			'bn_social_unverified_new' => 'unverified_new',
			'bn_social_no_id'          => 'provider_failed',
			'bn_pending_approval'      => 'pending_approval',
			'bn_reg_closed'            => 'reg_closed',
			'bn_reg_invite'            => 'reg_invite',
			'bn_reg_domain'            => 'reg_domain',
			'bn_reg_rate'              => 'rate_limited',
		);

		return $map[ $error->get_error_code() ] ?? 'cancelled';
	}

	/**
	 * Abuse guard: cap callback hits per IP per minute.
	 *
	 * @return void
	 */
	private function rate_limit(): void {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'bn_oauth_rl_' . md5( $ip );
		if ( RateLimiter::count( $key ) >= self::RATE_MAX ) {
			$this->bail( 'rate_limited' );
		}
		RateLimiter::hit( $key, MINUTE_IN_SECONDS );
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
