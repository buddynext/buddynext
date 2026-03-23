<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * WordPress hook listener for email verification events.
 *
 * Wires user_register, the ?bn_verify GET handler, and the
 * buddynext_send_verification_email action to VerificationService.
 *
 * @package BuddyNext\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Auth;

/**
 * Registers all hooks required by the email verification flow.
 */
class VerificationListener {

	/**
	 * Verification service.
	 *
	 * @var VerificationService
	 */
	private VerificationService $svc;

	/**
	 * Constructor.
	 *
	 * @param VerificationService $svc Verification service instance.
	 */
	public function __construct( VerificationService $svc ) {
		$this->svc = $svc;
	}

	/**
	 * Register all hook listeners.
	 *
	 * Called once during Plugin::init(), after services are bound.
	 */
	public function init(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ) );
		add_action( 'init', array( $this, 'handle_verify_request' ) );
		add_action( 'buddynext_send_verification_email', array( $this, 'send_verification_email' ), 10, 2 );
	}

	/**
	 * Create and send a verification token when a new user registers.
	 *
	 * Only fires when the buddynext_email_verify setting is enabled.
	 *
	 * @param int $user_id Newly registered WordPress user ID.
	 */
	public function on_user_register( int $user_id ): void {
		if ( ! (bool) get_option( 'buddynext_email_verify', false ) ) {
			return;
		}

		$this->svc->create_token( $user_id );
	}

	/**
	 * Handle ?bn_verify=TOKEN GET requests.
	 *
	 * On success: fires buddynext_email_verified, then redirects home with
	 * bn_verified=1. On failure: redirects home with bn_verified=0.
	 */
	public function handle_verify_request(): void {
		if ( ! isset( $_GET['bn_verify'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['bn_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = $this->svc->verify( $token );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'bn_verified', '0', home_url( '/' ) ) );
			exit;
		}

		/**
		 * Fires after a user's email address is successfully verified.
		 *
		 * @param int $user_id Verified WordPress user ID.
		 */
		do_action( 'buddynext_email_verified', $result );

		wp_safe_redirect( add_query_arg( 'bn_verified', '1', home_url( '/' ) ) );
		exit;
	}

	/**
	 * Send the verification email via the EmailSender service or wp_mail fallback.
	 *
	 * Fetches the email_verify template from bn_email_templates. When
	 * buddynext_service('email_sender') is available it is used; otherwise the
	 * method falls back to a direct wp_mail() call.
	 *
	 * Hooked to buddynext_send_verification_email (priority 10).
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $verify_url Full verification URL including token.
	 */
	public function send_verification_email( int $user_id, string $verify_url ): void {
		global $wpdb;

		$user = get_userdata( $user_id );
		if ( false === $user || '' === $user->user_email ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$template = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT subject, body_html, enabled FROM {$wpdb->prefix}bn_email_templates
				 WHERE type = %s LIMIT 1",
				'email_verify'
			)
		);

		if ( null !== $template && (bool) $template->enabled ) {
			$data = array(
				'verify_url' => $verify_url,
				'user_name'  => '' !== $user->display_name ? $user->display_name : $user->user_login,
				'site_name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'site_url'   => esc_url( home_url( '/' ) ),
			);

			if ( function_exists( 'buddynext_service' ) ) {
				try {
					buddynext_service( 'email_sender' )->send( $user_id, 'email_verify', $data );
					return;
				} catch ( \RuntimeException $e ) {
					// Container key not found — fall through to direct wp_mail.
					unset( $e );
				}
			}

			// Direct wp_mail fallback with template.
			$tokens  = array();
			$replace = array();
			foreach ( $data as $key => $value ) {
				$tokens[]  = '{{' . $key . '}}';
				$replace[] = (string) $value;
			}

			$subject = str_replace( $tokens, $replace, (string) $template->subject );
			$body    = str_replace( $tokens, $replace, (string) $template->body_html );

			wp_mail(
				$user->user_email,
				$subject,
				'<html><body>' . $body . '</body></html>',
				array( 'Content-Type: text/html; charset=UTF-8' )
			);

			return;
		}

		// No template — send a minimal plain-text fallback email.
		$display_name = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$site_name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Verify your email address on %s', 'buddynext' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: verification URL */
			__(
				"Hi %1\$s,\n\nPlease verify your email address on %2\$s by clicking the link below:\n\n%3\$s\n\nThis link expires in 24 hours.\n\nIf you did not register, please ignore this email.",
				'buddynext'
			),
			$display_name,
			$site_name,
			esc_url_raw( $verify_url )
		);

		wp_mail(
			$user->user_email,
			$subject,
			$body
		);
	}
}
