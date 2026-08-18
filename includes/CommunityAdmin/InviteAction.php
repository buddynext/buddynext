<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Front-end single-invite action for the Community Admin > Invites section.
 *
 * The wp-admin single-invite handler (InviteManager::handle_single_invite) is
 * gated on manage_options and redirects into wp-admin, so it cannot serve the
 * front-end panel, which is reachable by moderators with no backend access. This
 * is the panel-safe path: it wraps InviteService::create() with the panel's own
 * capability gate and redirects back to the Invites section.
 *
 * @package BuddyNext\CommunityAdmin
 * @since 1.1.5
 */

declare( strict_types=1 );

namespace BuddyNext\CommunityAdmin;

/**
 * Handles admin_post_bn_ca_send_invite from the Community Admin panel.
 */
final class InviteAction {

	/**
	 * Register the admin-post handler.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_bn_ca_send_invite', array( $this, 'handle' ) );
	}

	/**
	 * Create a single invite from the front-end Community Admin panel.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! function_exists( 'buddynext_can' ) || ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) {
			wp_die( esc_html__( 'You are not allowed to send invites.', 'buddynext' ), 403 );
		}

		check_admin_referer( 'bn_ca_send_invite' );

		$email = sanitize_email( (string) wp_unslash( $_POST['invite_email'] ?? '' ) );
		$first = sanitize_text_field( (string) wp_unslash( $_POST['invite_first_name'] ?? '' ) );

		$status = 'error';
		if ( is_email( $email ) ) {
			$svc = buddynext_service( 'invite' );
			if ( is_object( $svc ) && method_exists( $svc, 'create' ) ) {
				// create() returns the new invite id, or 0 when it dedups against an
				// existing account / live pending invite.
				$status = ( (int) $svc->create( $email, $first ) > 0 ) ? 'sent' : 'skipped';
			}
		}

		// wp_get_referer() is validated by WordPress; fall back to the routed hub.
		$back = wp_get_referer();
		if ( false === $back ) {
			$back = function_exists( 'buddynext_community_admin_url' ) ? buddynext_community_admin_url() : home_url( '/' );
		}
		wp_safe_redirect( add_query_arg( 'bn_invite', $status, remove_query_arg( 'bn_invite', $back ) ) );
		exit;
	}
}
