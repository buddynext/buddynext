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

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers all hooks required by the email verification flow.
 */
class VerificationListener implements ListenerInterface {

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
	public function register(): void {
		add_action( 'user_register', array( $this, 'on_user_register' ) );

		// Stamp the moment verification is switched on so is_verified() can
		// grandfather members who registered before it (VerificationService).
		add_action( 'update_option_buddynext_email_verify', array( $this, 'on_verify_option_set' ), 10, 2 );
		add_action( 'add_option_buddynext_email_verify', array( $this, 'on_verify_option_added' ), 10, 2 );
		add_action( 'init', array( $this, 'handle_verify_request' ) );
		add_action( 'init', array( $this, 'handle_email_change_verify_request' ) );
		add_action( 'buddynext_send_verification_email', array( $this, 'send_verification_email' ), 10, 2 );
		add_action( 'buddynext_email_change_requested', array( $this, 'on_email_change_requested' ), 10, 2 );

		// The "full" enforcement level actually gates access. Runs late so the
		// onboarding gate (priority 5) does not fight it.
		add_action( 'template_redirect', array( $this, 'maybe_gate_unverified' ), 6 );
	}

	/**
	 * On the buddynext_email_verify option being updated: stamp the enable time
	 * when it is switched on, so members who predate it are grandfathered.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 * @return void
	 */
	public function on_verify_option_set( $old_value, $new_value ): void {
		unset( $old_value );
		if ( (bool) $new_value ) {
			VerificationService::mark_verification_enabled();
		}
	}

	/**
	 * On the buddynext_email_verify option being added on: stamp the enable time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Option value.
	 * @return void
	 */
	public function on_verify_option_added( $option, $value ): void {
		unset( $option );
		if ( (bool) $value ) {
			VerificationService::mark_verification_enabled();
		}
	}

	/**
	 * How strictly the owner wants email verification enforced.
	 *
	 * Three levels, because across a fleet of owners both postures are legitimate.
	 * A public community wants a new member browsing immediately — a hard gate
	 * kills conversion, since email delivery is genuinely unreliable (spam folders,
	 * corporate filters, typo'd addresses). A professional or paid community wants
	 * nothing to happen until the address is confirmed.
	 *
	 *   off        — verification is not required at all.
	 *   restricted — the member is in, but cannot post or comment. (DEFAULT.)
	 *   full       — the member cannot use the community until they verify.
	 *
	 * `restricted` is what the code has always actually done, while the setting
	 * text promised a full access gate. Rather than pick one and break the other
	 * half of the fleet, the strictness is the owner's, with the safe middle as the
	 * default.
	 *
	 * @return string One of: off, restricted, full.
	 */
	public static function enforcement(): string {
		if ( ! self::feature_active() || ! get_option( 'buddynext_email_verify', false ) ) {
			return 'off';
		}

		$level = (string) get_option( 'buddynext_verify_enforcement', 'restricted' );

		return in_array( $level, array( 'restricted', 'full' ), true ) ? $level : 'restricted';
	}

	/**
	 * Hold an unverified member on the verify screen when enforcement is "full".
	 *
	 * Deliberately NOT a login block: refusing the sign-in would leave the member
	 * with no way to reach the "resend my link" button, which is exactly what they
	 * need. They get a session, and every BuddyNext surface routes them to the one
	 * page that can move them forward.
	 *
	 * @return void
	 */
	public function maybe_gate_unverified(): void {
		if ( 'full' !== self::enforcement() || ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// Never trap an administrator out of their own site.
		if ( user_can( $user_id, 'manage_options' ) ) {
			return;
		}

		if ( buddynext_service( 'verification' )->is_verified( $user_id ) ) {
			return;
		}

		$verify_url = \BuddyNext\Core\PageRouter::verify_url();

		// Already on the verify screen — do not loop.
		$current = (string) home_url( add_query_arg( array() ) );
		if ( false !== strpos( $current, '/verify' ) ) {
			return;
		}

		/**
		 * Filter whether an unverified member is held on the verify screen.
		 *
		 * @param bool $gate    Whether to redirect.
		 * @param int  $user_id Member being gated.
		 */
		if ( ! (bool) apply_filters( 'buddynext_gate_unverified', true, $user_id ) ) {
			return;
		}

		wp_safe_redirect( $verify_url );
		exit;
	}

	/**
	 * Whether the Email Verification feature is active.
	 *
	 * The FeatureRegistry 'verification' toggle is the master switch: when the
	 * owner turns it off the entire verification subsystem is inert, regardless
	 * of the buddynext_email_verify sub-setting (which Settings only surfaces
	 * while this feature is on). Gating every entry point on this one accessor
	 * keeps the contract in a single place.
	 *
	 * @return bool
	 */
	private static function feature_active(): bool {
		return buddynext_feature_enabled( 'verification' );
	}

	/**
	 * Create and send a verification token when a new user registers.
	 *
	 * Only fires when the Email Verification feature is on AND the
	 * buddynext_email_verify setting is enabled.
	 *
	 * @param int $user_id Newly registered WordPress user ID.
	 */
	public function on_user_register( int $user_id ): void {
		if ( ! self::feature_active() ) {
			return;
		}

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

		// Feature off: no token should exist; ignore the request rather than
		// processing it against a disabled subsystem.
		if ( ! self::feature_active() ) {
			return;
		}

		$token  = sanitize_text_field( wp_unslash( $_GET['bn_verify'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = $this->svc->verify( $token );

		$verify_page = \BuddyNext\Core\PageRouter::verify_url();

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'bn_verified', '0', $verify_page ) );
			exit;
		}

		/**
		 * Fires after a user's email address is successfully verified.
		 *
		 * @param int $user_id Verified WordPress user ID.
		 */
		do_action( 'buddynext_email_verified', $result );

		wp_safe_redirect( add_query_arg( 'bn_verified', '1', $verify_page ) );
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

		// Feature off: never dispatch a verification email.
		if ( ! self::feature_active() ) {
			return;
		}

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
				\BuddyNext\Notifications\EmailSender::brand_wrap( $body, $subject ),
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
				"Hi %1\$s,\n\nPlease verify your email address on %2\$s by clicking the link below:\n\n%3\$s\n\nThis link expires in 48 hours.\n\nIf you did not register, please ignore this email.",
				'buddynext'
			),
			$display_name,
			$site_name,
			esc_url_raw( $verify_url )
		);

		\BuddyNext\Notifications\EmailSender::send_with_identity(
			$user->user_email,
			$subject,
			\BuddyNext\Notifications\EmailSender::brand_wrap( wpautop( make_clickable( esc_html( $body ) ) ), $subject ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Send a confirmation link to the candidate address when a user requests an
	 * email change.
	 *
	 * Hooked to buddynext_email_change_requested (from AuthController). Uses a
	 * transient-backed token so the email-change flow stays isolated from the
	 * existing one-shot registration verification table.
	 *
	 * The confirmation link is sent to the CANDIDATE address, not the user's
	 * current email, so the change cannot complete unless the new mailbox is
	 * controlled by the requester.
	 *
	 * @param int    $user_id   Account requesting the change.
	 * @param string $candidate Pending email address.
	 */
	public function on_email_change_requested( int $user_id, string $candidate ): void {
		$user = get_userdata( $user_id );
		if ( false === $user || '' === $candidate ) {
			return;
		}

		$token = wp_generate_password( 32, false );

		set_transient(
			'bn_email_change_' . $token,
			array(
				'user_id'   => $user_id,
				'candidate' => $candidate,
			),
			DAY_IN_SECONDS
		);

		$verify_url = add_query_arg( 'bn_verify_email', $token, home_url( '/' ) );

		$display_name = '' !== $user->display_name ? $user->display_name : $user->user_login;
		$site_name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		// Prefer the admin-editable, branded template (sibling of email_verify),
		// falling back to plain text when no template row exists. The message goes
		// to the CANDIDATE inbox, so it is sent via EmailSender::send_with_identity()
		// (correct From/Reply-To) rather than EmailSender::send(), which targets the
		// account's CURRENT address.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$template = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT subject, body_html, enabled FROM {$wpdb->prefix}bn_email_templates WHERE type = %s LIMIT 1",
				'email_change_confirm'
			)
		);

		if ( null !== $template && (bool) $template->enabled ) {
			$tokens  = array( '{{user_name}}', '{{site_name}}', '{{site_url}}', '{{verify_url}}' );
			$replace = array( $display_name, $site_name, esc_url( home_url( '/' ) ), esc_url_raw( $verify_url ) );
			$subject = str_replace( $tokens, $replace, (string) $template->subject );
			$body    = str_replace( $tokens, $replace, (string) $template->body_html );

			\BuddyNext\Notifications\EmailSender::send_with_identity(
				$candidate,
				$subject,
				\BuddyNext\Notifications\EmailSender::brand_wrap( $body, $subject ),
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( 'Confirm your new email address on %s', 'buddynext' ),
			$site_name
		);

		$body = sprintf(
			/* translators: 1: display name, 2: site name, 3: confirmation URL */
			__(
				"Hi %1\$s,\n\nYou asked to change the email address on your %2\$s account to this inbox. Confirm the change by clicking the link below:\n\n%3\$s\n\nThis link expires in 24 hours. If you did not request the change, ignore this email and the address on your account stays the same.",
				'buddynext'
			),
			$display_name,
			$site_name,
			esc_url_raw( $verify_url )
		);

		\BuddyNext\Notifications\EmailSender::send_with_identity(
			$candidate,
			$subject,
			\BuddyNext\Notifications\EmailSender::brand_wrap( wpautop( make_clickable( esc_html( $body ) ) ), $subject ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	/**
	 * Handle ?bn_verify_email=TOKEN GET requests.
	 *
	 * Reads the matching transient, swaps user_email to the candidate when
	 * valid, clears the bn_pending_email meta, then redirects to the
	 * verify-email page with bn_email_changed=1 on success or bn_email_changed=0
	 * on a stale / unknown token.
	 */
	public function handle_email_change_verify_request(): void {
		if ( ! isset( $_GET['bn_verify_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$token       = sanitize_text_field( wp_unslash( $_GET['bn_verify_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$transient   = '' === $token ? false : get_transient( 'bn_email_change_' . $token );
		$verify_page = \BuddyNext\Core\PageRouter::verify_url();

		if ( ! is_array( $transient ) || empty( $transient['user_id'] ) || empty( $transient['candidate'] ) ) {
			wp_safe_redirect( add_query_arg( 'bn_email_changed', '0', $verify_page ) );
			exit;
		}

		$user_id   = (int) $transient['user_id'];
		$candidate = (string) $transient['candidate'];

		// Last-line defence in case another account grabbed the address while
		// the token was outstanding.
		if ( email_exists( $candidate ) && (int) email_exists( $candidate ) !== $user_id ) {
			delete_transient( 'bn_email_change_' . $token );
			wp_safe_redirect( add_query_arg( 'bn_email_changed', '0', $verify_page ) );
			exit;
		}

		$result = wp_update_user(
			array(
				'ID'         => $user_id,
				'user_email' => $candidate,
			)
		);

		delete_transient( 'bn_email_change_' . $token );
		delete_user_meta( $user_id, 'bn_pending_email' );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'bn_email_changed', '0', $verify_page ) );
			exit;
		}

		/**
		 * Fires after a user's email address is swapped via the change-email
		 * confirm-then-swap flow.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $user_id   Account whose address changed.
		 * @param string $new_email New address now stored on the account.
		 */
		do_action( 'buddynext_email_changed', $user_id, $candidate );

		wp_safe_redirect( add_query_arg( 'bn_email_changed', '1', $verify_page ) );
		exit;
	}
}
