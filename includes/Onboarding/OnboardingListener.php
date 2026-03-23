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
