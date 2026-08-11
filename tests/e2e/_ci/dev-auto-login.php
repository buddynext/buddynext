<?php
/**
 * Plugin Name: BuddyNext E2E Auto-Login (CI only)
 * Description: Logs in via ?autologin=1 (admin) or ?autologin=<login> so the
 *              Playwright auth fixture never fills a login form. DROPPED INTO
 *              wp-content/mu-plugins ONLY by the CI e2e harness — never shipped in
 *              a release build. Mirrors the local dev auto-login mu-plugin.
 *
 * @package BuddyNext\Tests
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function (): void {
		if ( ! isset( $_GET['autologin'] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$param = sanitize_text_field( wp_unslash( $_GET['autologin'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user  = ( '1' === $param || 'admin' === $param )
			? get_user_by( 'ID', 1 )
			: ( get_user_by( 'login', $param ) ?: get_user_by( 'email', $param ) );

		if ( $user ) {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true );
			wp_safe_redirect( remove_query_arg( 'autologin' ) );
			exit;
		}
	},
	1
);
