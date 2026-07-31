<?php
/**
 * Plugin Name: BN Mock OAuth Provider (dev harness)
 * Description: Registers a fake OAuth provider through BuddyNext's real buddynext_oauth_providers seam, with the provider's authorize/token/userinfo endpoints served by THIS site. Lets the whole social-login pipeline (start, state cookie, code exchange, profile fetch, account resolution, session, app bridge) run end to end with no external identity provider. Dev sites only.
 *
 * The point: every line of PRODUCTION code executes - SocialLogin::start(),
 * the CSRF state round trip, exchange_code()'s wp_remote_post, fetch_profile(),
 * resolve_user()'s registration-policy gates, SessionIssuer - only the identity
 * provider at the far end is simulated. That is the same technique the plugin's
 * own unit tests use, lifted to a live-site harness so the mobile app's social
 * button can be exercised on a simulator.
 *
 * Never load this anywhere near production.
 */

defined( 'ABSPATH' ) || exit;

// Hard environment gate: local-class environments only.
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true )
	&& false === strpos( home_url(), '.local' ) ) {
	return;
}

const BN_MOCK_OAUTH_ID = 'mockoauth';

/**
 * The identity the provider asserts. One fixed persona, like a one-user IdP.
 * Email is verified TRUE - the case Apple/Google present - so the real
 * account-resolution path (auto-link / create) runs rather than the
 * unverified refusal.
 */
function bn_mock_oauth_profile(): array {
	return array(
		'sub'            => 'mock-user-001',
		'email'          => 'mockmember@example.test',
		'email_verified' => true,
		'name'           => 'Mock Member',
		'picture'        => '',
	);
}

// 1) Register the provider definition through the REAL seam.
add_filter(
	'buddynext_oauth_providers',
	static function ( array $providers ): array {
		$providers[ BN_MOCK_OAUTH_ID ] = array(
			'label'       => 'MockOAuth',
			'icon'        => 'globe',
			'authorize'   => rest_url( 'bn-mock/v1/authorize' ),
			'token'       => rest_url( 'bn-mock/v1/token' ),
			'userinfo'    => rest_url( 'bn-mock/v1/userinfo' ),
			'scope'       => 'openid email profile',
			'map'         => array(
				'id'       => 'sub',
				'email'    => 'email',
				'verified' => 'email_verified',
				'name'     => 'name',
				'picture'  => 'picture',
			),
			'console_url' => '',
			'setup_steps' => array(),
		);
		return $providers;
	}
);

// 2) Auto-configure it (enabled + credentials) so is_ready() passes without
// touching the stored option the admin screen owns.
add_filter(
	'option_buddynext_social_login',
	static function ( $value ) {
		$value = is_array( $value ) ? $value : array();
		if ( ! isset( $value[ BN_MOCK_OAUTH_ID ] ) ) {
			$value[ BN_MOCK_OAUTH_ID ] = array(
				'enabled'       => true,
				'client_id'     => 'mock-client-id',
				'client_secret' => 'mock-client-secret',
			);
		}
		return $value;
	}
);

// 3) Serve the provider's three endpoints from this site.
add_action(
	'rest_api_init',
	static function (): void {
		// authorize: the "provider login page". A real IdP authenticates the
		// member here; the mock consents instantly and bounces back with a code.
		register_rest_route(
			'bn-mock/v1',
			'/authorize',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$redirect = esc_url_raw( (string) $request->get_param( 'redirect_uri' ) );
					$state    = sanitize_text_field( (string) $request->get_param( 'state' ) );
					if ( '' === $redirect ) {
						return new WP_Error( 'bn_mock_bad_request', 'redirect_uri required', array( 'status' => 400 ) );
					}
					$to = add_query_arg(
						array(
							'code'  => rawurlencode( 'mock-code-' . wp_generate_password( 8, false ) ),
							'state' => rawurlencode( $state ),
						),
						$redirect
					);
					wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the callback URL the plugin registered.
					exit;
				},
			)
		);

		// token: code -> access token.
		register_rest_route(
			'bn-mock/v1',
			'/token',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return new WP_REST_Response(
						array(
							'access_token' => 'mock-access-token',
							'token_type'   => 'Bearer',
						),
						200
					);
				},
			)
		);

		// userinfo: the asserted identity.
		register_rest_route(
			'bn-mock/v1',
			'/userinfo',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return new WP_REST_Response( bn_mock_oauth_profile(), 200 );
				},
			)
		);
	}
);
