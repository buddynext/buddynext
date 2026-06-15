<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming.
/**
 * Onboarding listener.
 *
 * Schedules and handles post-registration nudge emails. A 24-hour and a
 * 72-hour cron event are queued for every new user at registration time.
 * Both events are cancelled when the user completes the onboarding flow,
 * and the shared handler skips users who have already finished onboarding.
 *
 * @package BuddyNext\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Onboarding;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Registers onboarding nudge hooks and routes them to the email system.
 */
class OnboardingListener implements ListenerInterface {

	/**
	 * Register all onboarding event hook listeners.
	 *
	 * Called once during Plugin::init(), after the service container is
	 * bootstrapped, so buddynext_service() is available to every handler.
	 */
	public function register(): void {
		add_action( 'user_register', array( $this, 'on_user_register_schedule_nudges' ), 15, 1 );
		add_action( 'buddynext_onboarding_completed', array( $this, 'on_onboarding_completed_cancel_nudges' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_24h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'bn_onboarding_nudge_72h', array( $this, 'handle_onboarding_nudge' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'maybe_redirect_to_onboarding' ), 5 );
	}

	/**
	 * Send un-onboarded members to the welcome wizard on their next front-end view.
	 *
	 * The self-registration flow redirects to the wizard directly, but
	 * admin-created members, the email-verify flow, and ordinary logins never
	 * pass through it. This front-end gate is the canonical trigger: when the
	 * `onboarding` feature is enabled (FeatureRegistry — the authoritative
	 * toggle) and a logged-in member has not yet finished (or skipped) the
	 * wizard, the first non-onboarding front-end page view is redirected to it.
	 *
	 * Skipping the wizard marks it complete (OnboardingService::skip), so a
	 * dismissed wizard never loops back here.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_onboarding(): void {
		// Front-end GET views only — never admin, AJAX, REST, cron, or feeds.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		if ( ! is_user_logged_in() || ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		// Canonical on/off gate. Prefer the FeatureRegistry flag over the
		// legacy buddynext_show_onboarding option.
		if ( ! buddynext_service( 'features' )->is_enabled( 'onboarding' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( buddynext_service( 'onboarding' )->is_complete( $user_id ) ) {
			return;
		}

		// Never redirect when the member is already on the onboarding wizard or
		// inside the auth flow (login / signup / email verify), to avoid loops.
		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( in_array( $hub, array( 'onboarding', 'auth' ), true ) ) {
			return;
		}

		$onboarding_url = \BuddyNext\Core\PageRouter::onboarding_url();

		// Loop guard for the edge case where the onboarding hub is the site's
		// static front page (bn_hub may be empty until dispatch resolves it):
		// bail when the current request path already matches the wizard path.
		$current_path    = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$onboarding_path = wp_parse_url( $onboarding_url, PHP_URL_PATH );
		if ( is_string( $current_path ) && is_string( $onboarding_path ) && untrailingslashit( $current_path ) === untrailingslashit( $onboarding_path ) ) {
			return;
		}

		/**
		 * Filter whether the current request should be redirected to onboarding.
		 *
		 * Returning false lets a specific route opt out of the welcome wizard
		 * gate without disabling the feature globally.
		 *
		 * @param bool $should_redirect Whether to redirect to the wizard.
		 * @param int  $user_id         The logged-in member's user ID.
		 * @param string $hub           The active bn_hub for this request.
		 */
		if ( ! (bool) apply_filters( 'buddynext_onboarding_should_redirect', true, $user_id, $hub ) ) {
			return;
		}

		wp_safe_redirect( $onboarding_url );
		exit;
	}

	/**
	 * Schedule 24h and 72h nudge emails when a new user registers.
	 *
	 * @param int $user_id Newly registered user ID.
	 */
	public function on_user_register_schedule_nudges( int $user_id ): void {
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'bn_onboarding_nudge_24h', array( $user_id ) );
		wp_schedule_single_event( time() + ( 3 * DAY_IN_SECONDS ), 'bn_onboarding_nudge_72h', array( $user_id ) );
	}

	/**
	 * Cancel pending nudge emails when a user completes onboarding.
	 *
	 * @param int $user_id User who completed onboarding.
	 */
	public function on_onboarding_completed_cancel_nudges( int $user_id ): void {
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_24h', array( $user_id ) );
		wp_clear_scheduled_hook( 'bn_onboarding_nudge_72h', array( $user_id ) );
	}

	/**
	 * Send an onboarding nudge email if the user has not yet completed onboarding.
	 *
	 * Shared handler for both the 24h and 72h nudge cron hooks. Bails early
	 * when the user has already finished onboarding so no duplicate emails are sent.
	 *
	 * @param int $user_id User ID to nudge.
	 */
	public function handle_onboarding_nudge( int $user_id ): void {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return;
		}

		if ( buddynext_service( 'onboarding' )->is_complete( $user_id ) ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}

		buddynext_service( 'email_sender' )->send(
			$user_id,
			'bn.onboarding_nudge',
			array(
				'recipient_name' => $user->display_name,
				'onboarding_url' => home_url( '/?bn_hub=profile&bn_endpoint=onboarding' ),
			)
		);
	}
}
