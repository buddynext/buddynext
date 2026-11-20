<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Site Health: can a logged-out visitor reach the BuddyNext sign-in route?
 *
 * BuddyNext's login, sign-up and password reset screens talk to
 * /wp-json/buddynext/v1/auth/*. Security plugins (All In One Security's
 * "Disallow unauthorized REST requests", Wordfence, Solid Security, Disable
 * REST API) and host firewalls can block REST for logged-out visitors. The
 * screens then fall back to the standard WordPress form, but the owner should
 * know why and how to allow the route.
 *
 * One asynchronous test, run only on the Site Health screen: a guest POST to the
 * login route with an empty body, never credentials. A route that answers asks
 * for the missing fields (400); anything else means something stood in front.
 *
 * @package BuddyNext\Auth
 * @since   1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Site Health test for the guest-reachable auth route.
 */
final class AuthRestHealth {

	/**
	 * Test id. No underscore: core's Site Health script builds the AJAX action
	 * from it by replacing only the first underscore.
	 */
	public const TEST = 'buddynext-auth-rest';

	/**
	 * Security plugins known to block REST for logged-out visitors, keyed by
	 * plugin folder.
	 */
	private const BLOCKERS = array(
		'all-in-one-wp-security-and-firewall' => 'aios',
		'wordfence'                           => 'wordfence',
		'better-wp-security'                  => 'solid',
		'ithemes-security-pro'                => 'solid',
		'disable-json-api'                    => 'dra',
	);

	/**
	 * Wire the test (admin requests only: the Site Health screen and its AJAX call).
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'site_status_tests', array( self::class, 'add_test' ) );
		add_action( 'wp_ajax_health-check-' . self::TEST, array( self::class, 'ajax' ) ); // wp-frontend-rest-only-allow: core Site Health async test runs in wp-admin, not on the member frontend.
	}

	/**
	 * Add the asynchronous test.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function add_test( array $tests ): array {
		$tests['async'][ self::TEST ] = array(
			'label'     => __( 'BuddyNext sign-in route', 'buddynext' ),
			'test'      => self::TEST,
			'has_rest'  => false,
			'skip_cron' => true,
		);
		return $tests;
	}

	/**
	 * AJAX handler core's Site Health screen calls.
	 *
	 * @return void
	 */
	public static function ajax(): void {
		check_ajax_referer( 'health-check-site-status' );
		if ( ! current_user_can( 'view_site_health_checks' ) ) {
			wp_send_json_error();
		}
		wp_send_json_success( self::run() );
	}

	/**
	 * Run the test.
	 *
	 * @return array<string, mixed> Site Health result.
	 */
	public static function run(): array {
		$badge = array(
			'label' => __( 'BuddyNext', 'buddynext' ),
			'color' => 'blue',
		);
		$base  = array(
			'badge' => $badge,
			'test'  => self::TEST,
		);

		// A guest request: no cookies, no nonce, no credentials.
		$response = wp_remote_post(
			rest_url( 'buddynext/v1/auth/login' ),
			array(
				'timeout'   => 10,
				'headers'   => array( 'Content-Type' => 'application/json' ),
				'body'      => '{}',
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $base + array(
				'label'       => __( 'BuddyNext could not test its sign-in route', 'buddynext' ),
				'status'      => 'recommended',
				'description' => '<p>' . esc_html__( 'The site could not send a test request to itself, so BuddyNext could not check whether logged-out visitors can reach its sign-in route. Check the loopback request result on this screen.', 'buddynext' ) . '</p>',
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code   = is_array( $body ) && isset( $body['code'] ) ? (string) $body['code'] : '';

		if ( 400 === $status && in_array( $code, array( 'rest_missing_callback_param', 'rest_invalid_param' ), true ) ) {
			return $base + array(
				'label'       => __( 'Visitors can reach the BuddyNext sign-in route', 'buddynext' ),
				'status'      => 'good',
				'description' => '<p>' . esc_html__( 'The community login, sign-up and password reset screens can reach BuddyNext for visitors who are not signed in.', 'buddynext' ) . '</p>',
			);
		}

		$base['badge']['color'] = 'red';
		return $base + array(
			'label'       => __( 'Something blocks the BuddyNext sign-in route', 'buddynext' ),
			'status'      => 'critical',
			'description' => '<p>' . esc_html(
				sprintf(
					/* translators: %d: HTTP status code of the test request. */
					__( 'A test request from a visitor who is not signed in to /wp-json/buddynext/v1/auth/login was refused (HTTP %d). Members are sent to the standard WordPress sign-in instead of the community login, sign-up and password reset screens until the route is allowed.', 'buddynext' ),
					$status
				)
			) . '</p><p>' . esc_html( self::allow_step( self::blocker() ) ) . '</p>',
		);
	}

	/**
	 * The active security plugin most likely to be blocking, or '' when none is known.
	 *
	 * @return string Blocker key from BLOCKERS, or ''.
	 */
	public static function blocker(): string {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( $active as $plugin ) {
			$folder = strtok( (string) $plugin, '/' );
			if ( false !== $folder && isset( self::BLOCKERS[ $folder ] ) ) {
				return self::BLOCKERS[ $folder ];
			}
		}
		return '';
	}

	/**
	 * The owner's next step for a blocker.
	 *
	 * @param string $blocker Blocker key from blocker().
	 * @return string
	 */
	public static function allow_step( string $blocker ): string {
		switch ( $blocker ) {
			case 'aios':
				return __( 'All In One Security is active. In WP Security, open the Firewall settings for the REST API and add buddynext to the whitelisted REST routes (or turn off "Disallow unauthorized REST requests").', 'buddynext' );
			case 'wordfence':
				return __( 'Wordfence is active. In its firewall and login security settings, allow visitors who are not signed in to reach /wp-json/buddynext/v1/auth/.', 'buddynext' );
			case 'solid':
				return __( 'Solid Security is active. In its REST API setting (Security > Settings > Advanced > WordPress Tweaks), allow visitors who are not signed in to reach /wp-json/buddynext/v1/auth/.', 'buddynext' );
			case 'dra':
				return __( 'Disable REST API is active. In Settings > Disable REST API, allow the buddynext/v1 auth routes for unauthenticated users.', 'buddynext' );
			default:
				return __( 'No known security plugin is active, so the block is likely a host or firewall rule. Ask your host to allow requests from visitors who are not signed in to /wp-json/buddynext/v1/auth/.', 'buddynext' );
		}
	}
}
