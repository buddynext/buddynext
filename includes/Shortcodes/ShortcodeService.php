<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext shortcode registration service — Hub + Endpoint model.
 *
 * Registers exactly five hub shortcodes. Each shortcode reads the current
 * hub query vars (set by PageRouter::set_hub_vars) and routes to the correct
 * template for the active endpoint.
 *
 *   [buddynext_activity]      Activity hub — feed, explore, hashtag, search, leaderboard
 *   [buddynext_people]        People hub   — directory, profile, edit, connections
 *   [buddynext_spaces]        Spaces hub   — directory, space home, members, settings, etc.
 *   [buddynext_messages]      Messages hub — list, thread, requests (auth-gated)
 *   [buddynext_notifications] Notifications (auth-gated)
 *
 * @package BuddyNext\Shortcodes
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Shortcodes;

use BuddyNext\Core\Container;
use BuddyNext\Core\PageRouter;

/**
 * Registers and handles BuddyNext hub shortcodes.
 */
class ShortcodeService {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register all hub shortcodes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'buddynext_activity', array( $this, 'render_activity' ) );
		add_shortcode( 'buddynext_people', array( $this, 'render_people' ) );
		add_shortcode( 'buddynext_spaces', array( $this, 'render_spaces' ) );
		add_shortcode( 'buddynext_messages', array( $this, 'render_messages' ) );
		add_shortcode( 'buddynext_notifications', array( $this, 'render_notifications' ) );
		add_shortcode( 'buddynext_auth', array( $this, 'render_auth' ) );
	}

	// ── Shortcode handlers ────────────────────────────────────────────────────

	/**
	 * Render the Activity hub shortcode.
	 *
	 * Routes by bn_activity_action query var:
	 *   explore     → feed/explore.php
	 *   hashtag     → hashtags/feed.php  (bn_hashtag = sanitized tag slug)
	 *   search      → search/results.php (?q=… from GET)
	 *   leaderboard → gamification/leaderboard.php
	 *   (default)   → feed/home.php
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_activity( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$action = (string) get_query_var( 'bn_activity_action', '' );

		switch ( $action ) {
			case 'explore':
				return $this->capture( 'feed/explore.php', array() );

			case 'hashtag':
				return $this->capture(
					'hashtags/feed.php',
					array( 'hashtag' => sanitize_title( (string) get_query_var( 'bn_hashtag', '' ) ) )
				);

			case 'search':
				return $this->capture(
					'search/results.php',
					array( 'query' => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				);

			case 'leaderboard':
				return $this->capture( 'gamification/leaderboard.php', array() );

			default:
				return $this->capture( 'feed/home.php', array() );
		}
	}

	/**
	 * Render the People hub shortcode.
	 *
	 * When no user slug is present, shows the member directory.
	 * When a slug is resolved, routes by bn_profile_action:
	 *   edit        → profile/edit.php  (owner or admin only; others redirected)
	 *   connections → profile/connections.php
	 *   (default)   → profile/view.php
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_people( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$user_slug = (string) get_query_var( 'bn_user_slug', '' );

		if ( '' === $user_slug ) {
			return $this->capture( 'directory/members.php', array() );
		}

		$user_id = (int) get_query_var( 'bn_resolved_user_id', 0 );
		$action  = (string) get_query_var( 'bn_profile_action', '' );

		switch ( $action ) {
			case 'edit':
				if ( ! is_user_logged_in() ) {
					return $this->login_required_html();
				}

				// Only the profile owner or an admin may access the edit page.
				if ( get_current_user_id() !== $user_id && ! current_user_can( 'manage_options' ) ) {
					wp_safe_redirect( PageRouter::profile_url( $user_id ) );
					exit;
				}

				return $this->capture( 'profile/edit.php', array( 'user_id' => get_current_user_id() ) );

			case 'connections':
				return $this->capture( 'profile/connections.php', array( 'user_id' => $user_id ) );

			default:
				return $this->capture( 'profile/view.php', array( 'user_id' => $user_id ) );
		}
	}

	/**
	 * Render the Spaces hub shortcode.
	 *
	 * When no space slug is present, shows the spaces directory.
	 * When a slug is resolved, routes by bn_space_action:
	 *   members    → spaces/members.php
	 *   settings   → spaces/settings.php
	 *   moderation → spaces/moderation.php
	 *   admin      → community-admin.php
	 *   (default)  → spaces/home.php
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_spaces( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$space_slug = (string) get_query_var( 'bn_space_slug', '' );

		if ( '' === $space_slug ) {
			return $this->capture( 'spaces/directory.php', array() );
		}

		$space_id = (int) get_query_var( 'bn_resolved_space_id', 0 );
		$action   = (string) get_query_var( 'bn_space_action', '' );

		switch ( $action ) {
			case 'members':
				return $this->capture( 'spaces/members.php', array( 'space_id' => $space_id ) );

			case 'settings':
				return $this->capture( 'spaces/settings.php', array( 'space_id' => $space_id ) );

			case 'moderation':
				return $this->capture( 'spaces/moderation.php', array( 'space_id' => $space_id ) );

			case 'admin':
				return $this->capture( 'community-admin.php', array( 'space_id' => $space_id ) );

			default:
				return $this->capture( 'spaces/home.php', array( 'space_id' => $space_id ) );
		}
	}

	/**
	 * Render the Messages hub shortcode.
	 *
	 * Requires login. Routes by bn_msg_action / bn_conv_id:
	 *   bn_msg_action=requests → messages/requests.php
	 *   bn_conv_id > 0         → messages/thread.php
	 *   (default)              → messages/list.php
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_messages( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return $this->login_required_html();
		}

		$action  = (string) get_query_var( 'bn_msg_action', '' );
		$conv_id = (int) get_query_var( 'bn_conv_id', 0 );

		if ( 'requests' === $action ) {
			return $this->capture( 'messages/requests.php', array() );
		}

		if ( $conv_id > 0 ) {
			return $this->capture( 'messages/thread.php', array( 'conv_id' => $conv_id ) );
		}

		return $this->capture( 'messages/list.php', array() );
	}

	/**
	 * Render the Notifications hub shortcode.
	 *
	 * Requires login.
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_notifications( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return $this->login_required_html();
		}

		return $this->capture( 'notifications/index.php', array() );
	}

	/**
	 * Render the Auth hub shortcode.
	 *
	 * Logged-in users are redirected to the Activity hub immediately.
	 * Guests are shown auth/login.php which handles both login and registration.
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_auth( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( is_user_logged_in() ) {
			wp_safe_redirect( PageRouter::activity_url() );
			exit;
		}

		return $this->capture( 'auth/login.php', array() );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return a login-required message with a link to the WP login page.
	 *
	 * @return string Escaped HTML.
	 */
	private function login_required_html(): string {
		return sprintf(
			'<p class="bn-login-required">%s <a href="%s">%s</a></p>',
			esc_html__( 'You must be logged in to view this page.', 'buddynext' ),
			esc_url( wp_login_url( get_permalink() ) ),
			esc_html__( 'Log in', 'buddynext' )
		);
	}

	/**
	 * Render a template to a string using the TemplateLoader service.
	 *
	 * Returns the rendered HTML, or an empty string when the template is
	 * missing (caller handles fallback presentation if needed).
	 *
	 * @param string               $relative  Template path relative to the templates/ directory.
	 * @param array<string, mixed> $variables Variables to extract into template scope.
	 * @return string Rendered HTML.
	 */
	private function capture( string $relative, array $variables ): string {
		$loader = Container::instance()->get( 'template_loader' );
		return $loader->capture( $relative, $variables );
	}
}
