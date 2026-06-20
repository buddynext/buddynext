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
		add_shortcode( 'buddynext_community_admin', array( $this, 'render_community_admin' ) );
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
		$this->enqueue_shell( 'feed', 'explore', 'hashtags', 'search', 'gamification' );

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
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_people( $atts ): string {
		$this->enqueue_shell( 'members', 'profile', 'connections' );

		$atts      = shortcode_atts( array( 'view' => '' ), $atts, 'buddynext_people' );
		$view      = (string) $atts['view'];
		$user_slug = (string) get_query_var( 'bn_user_slug', '' );

		// No user slug — decide by view mode.
		if ( '' === $user_slug ) {
			// view=profile: show the current user's own profile (redirect guests to login).
			if ( 'profile' === $view ) {
				if ( ! is_user_logged_in() ) {
					return $this->login_required_html();
				}
				return $this->capture( 'profile/view.php', array( 'user_id' => get_current_user_id() ) );
			}
			// Default (members hub): show the member directory.
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
	 *   admin      → spaces/admin.php
	 *   (default)  → spaces/home.php
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_spaces( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->enqueue_shell( 'spaces' );

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
				return $this->capture( 'spaces/admin.php', array( 'space_id' => $space_id ) );

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
		$this->enqueue_shell( 'messages' );

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
		$this->enqueue_shell( 'notifications' );

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

		// Enqueue the auth stylesheet + Interactivity modules. On the hub route
		// PageRouter does this; off-hub (inside [buddynext_auth] on an arbitrary
		// page) nothing did, so the login/register forms rendered unstyled and
		// inert. Both auth-login and auth-signup load because the template carries
		// the sign-in and create-account forms.
		$this->enqueue_shell( 'auth' );
		wp_enqueue_script_module( '@buddynext/auth-login' );
		wp_enqueue_script_module( '@buddynext/auth-signup' );

		return $this->capture( 'auth/login.php', array(), false );
	}

	/**
	 * Render the Community Admin Panel shortcode.
	 *
	 * Site-wide admin overview for community managers. Requires manage_options
	 * or the buddynext-spaces/moderate ability.
	 *
	 * @param array<string, mixed>|string $_atts Shortcode attributes (unused).
	 * @return string HTML output.
	 */
	public function render_community_admin( $_atts ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! is_user_logged_in() ) {
			return $this->login_required_html();
		}

		// The Appeals section's approve/deny controls run on the buddynext/moderation
		// Interactivity store; enqueue it here (the panel is a shortcode page, not a
		// routed hub, so the hub union-enqueue never runs for it). Script modules
		// enqueued during the_content still print in the footer. The bn-moderation
		// stylesheet is already loaded for the panel's .bn-ca-* chrome.
		$this->enqueue_shell( 'moderation' );

		return $this->capture( 'community-admin.php', array(), false );
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Return a login-required message with a link to the WP login page.
	 *
	 * @return string Escaped HTML.
	 */
	private function login_required_html(): string {
		return $this->wrap_embedded(
			sprintf(
				'<p class="bn-login-required">%s <a href="%s">%s</a></p>',
				esc_html__( 'You must be logged in to view this page.', 'buddynext' ),
				esc_url( wp_login_url( get_permalink() ) ),
				esc_html__( 'Log in', 'buddynext' )
			),
			false
		);
	}

	/**
	 * Ensure the shell + feature stylesheets load for an embedded shortcode.
	 *
	 * On the routed hub, PageRouter::enqueue_hub_assets() loads bn-shell and the
	 * per-hub feature bundles before wp_head(). A [buddynext_*] shortcode on an
	 * arbitrary page never hits that path, so without this the wrap_embedded()
	 * `.bn-app` scope would have no stylesheet behind it and the content renders
	 * unstyled. Styles/modules enqueued during the_content print in the footer
	 * (WP late-styles), so this is effective even though shortcodes run after
	 * wp_head(). Idempotent: re-enqueuing an already-loaded handle is a no-op, so
	 * it is safe to call even when the shortcode sits on a routed hub page.
	 *
	 * @param string ...$features Feature slugs to enqueue (e.g. 'feed', 'profile').
	 * @return void
	 */
	private function enqueue_shell( string ...$features ): void {
		wp_enqueue_style( 'bn-shell' );

		$assets = buddynext_service( 'assets' );
		if ( ! is_object( $assets ) || ! method_exists( $assets, 'enqueue' ) ) {
			return;
		}
		foreach ( $features as $feature ) {
			$assets->enqueue( $feature );
		}
	}

	/**
	 * Wrap shortcode output in the BuddyNext scoping canvas.
	 *
	 * The hub-shell wrapper (templates/shell/hub-shell.php) is emitted only on the
	 * routed hub path. A [buddynext_*] shortcode placed on an arbitrary page renders
	 * the bare inner template with no `.bn-app` ancestor, so every `--bn-*` token,
	 * the `.bn-app *` box-sizing reset, and the `.bn-app__main` content column scoped
	 * in bn-shell.css fail to apply and the content looks unstyled. Re-create the
	 * minimal scope here: a class-only `.bn-app.bn-app--embedded` (no `id="bn-app"`,
	 * so two shortcodes on one page never collide on the id and client-nav never
	 * targets it). The `.bn-app--embedded` modifier neutralizes the full-bleed
	 * 100vw / 100vh canvas so the widget flows inside the host page's content column.
	 *
	 * @param string $html      Inner template HTML.
	 * @param bool   $with_main Wrap in the `.bn-app__main` content column (hub content
	 *                          templates). False for templates that carry their own
	 *                          full-bleed chrome (auth, community admin).
	 * @return string Wrapped HTML, or '' when $html is empty.
	 */
	private function wrap_embedded( string $html, bool $with_main = true ): string {
		if ( '' === $html ) {
			return '';
		}

		$open  = '<div class="bn-app bn-app--embedded" data-bn-embedded="1">';
		$open .= $with_main ? '<div class="bn-app__main">' : '';
		$close = ( $with_main ? '</div>' : '' ) . '</div>';

		return $open . $html . $close;
	}

	/**
	 * Render a template to a string using the TemplateLoader service.
	 *
	 * Returns the rendered HTML, or an empty string when the template is
	 * missing (caller handles fallback presentation if needed).
	 *
	 * The output is wrapped in the `.bn-app` scoping canvas via wrap_embedded() so
	 * the shell-scoped CSS applies even when the shortcode sits on an arbitrary page
	 * (off the routed hub path).
	 *
	 * @param string               $relative  Template path relative to the templates/ directory.
	 * @param array<string, mixed> $variables Variables to extract into template scope.
	 * @param bool                 $with_main Wrap in the `.bn-app__main` content column.
	 *                                        False for self-chroming templates (auth, community admin).
	 * @return string Rendered HTML.
	 */
	private function capture( string $relative, array $variables, bool $with_main = true ): string {
		$loader = Container::instance()->get( 'template_loader' );
		return $this->wrap_embedded( $loader->capture( $relative, $variables ), $with_main );
	}
}
