<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext front-end URL router — Hub + Endpoint model.
 *
 * Five configurable-slug hubs, each with sub-endpoints:
 *
 *   Activity hub  /activity/          → home feed
 *                 /activity/explore/  → explore
 *                 /activity/hashtag/{tag}/ → hashtag feed
 *                 /activity/search/   → search results
 *                 /activity/leaderboard/ → leaderboard
 *
 *   People hub    /members/           → member directory
 *                 /members/{slug}/    → profile view
 *                 /members/{slug}/edit/        → profile edit
 *                 /members/{slug}/connections/ → connections
 *
 *   Spaces hub    /spaces/            → spaces directory
 *                 /spaces/{slug}/     → space home
 *                 /spaces/{slug}/members/    → members list
 *                 /spaces/{slug}/settings/   → settings
 *                 /spaces/{slug}/moderation/ → moderation
 *                 /spaces/{slug}/admin/      → admin panel
 *
 *   Messages hub  /messages/          → conversation list
 *                 /messages/requests/ → message requests
 *                 /messages/{id}/     → conversation thread
 *
 *   Notifications hub /notifications/ → notifications
 *
 * Hub slugs are configurable via options and flush rewrite rules on change.
 *
 * @package BuddyNext\Core
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use WP_Query;
use WP_User;

/**
 * Manages BuddyNext rewrite rules and URL builders for all five community hubs.
 */
class PageRouter {

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_rewrites' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 20 );
		add_action( 'pre_get_posts', array( $this, 'set_hub_vars' ) );

		// Flush rewrites whenever any hub slug changes (sourced from the registry).
		foreach ( HubRegistry::instance()->all() as $bn_hub ) {
			add_action( 'update_option_' . $bn_hub->slug_option, array( $this, 'flush_on_slug_change' ) );
		}

		add_filter( 'request', array( $this, 'suppress_default_query' ) );
		add_filter( 'query_vars', array( $this, 'register_directory_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'dispatch_hub_template' ) );

		// Hub pages render from a virtual WP_Post (ID 0), so core's admin-bar
		// "Edit Page" resolves to wp-admin/edit.php. Drop that node on hub routes.
		add_action( 'admin_bar_menu', array( $this, 'remove_hub_edit_node' ), 999 );
	}

	/**
	 * Remove the admin-bar "Edit Page" node on BuddyNext hub routes.
	 *
	 * Hubs are not editable as a single post (the rendered WP_Post is virtual,
	 * ID 0), so the core edit link points at wp-admin/edit.php. Removing the node
	 * avoids a dead-end link for admins viewing a hub.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	public function remove_hub_edit_node( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! self::is_bn_route() ) {
			return;
		}
		$wp_admin_bar->remove_node( 'edit' );
	}

	/**
	 * Auto-flush rewrite rules when the rule set changes between deploys.
	 *
	 * The constant ROUTER_VERSION below is bumped whenever this file's
	 * rewrite rule registration changes. On a request after deploy the
	 * stored option mismatches the constant and we flush exactly once.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		$stored = (string) get_option( 'buddynext_router_version', '' );
		if ( self::ROUTER_VERSION === $stored ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'buddynext_router_version', self::ROUTER_VERSION, true );
	}

	/**
	 * Version sentinel for rewrite rule set. Bump when register_rewrites()
	 * emits a new rule so deploys auto-flush.
	 */
	private const ROUTER_VERSION = '2026-07-01-spaces-mine-reflush';

	// ── Request filter ────────────────────────────────────────────────────────

	/**
	 * Suppress the default WP_Query for BuddyNext hub requests.
	 *
	 * No backing WordPress pages are needed. The hub route is handled
	 * entirely by dispatch_hub_template() at template_redirect. This
	 * method simply prevents WP from running a pointless slug-based
	 * query that would return a 404.
	 *
	 * @param array<string,mixed> $query_vars Parsed query vars from WP::parse_request().
	 * @return array<string,mixed>
	 */
	public function suppress_default_query( array $query_vars ): array {
		if ( ! isset( $query_vars['bn_hub'] ) ) {
			return $query_vars;
		}

		// Strip slug-based lookups so WP_Query does not try to resolve a
		// page by post_name (which would 404 since no backing page exists).
		unset( $query_vars['pagename'], $query_vars['name'], $query_vars['page'] );

		// Return an empty query — dispatch_hub_template() handles output.
		$query_vars['post__in'] = array( 0 );

		return $query_vars;
	}

	// ── Template dispatcher ───────────────────────────────────────────────────

	/**
	 * Load a BuddyNext hub template using the active theme's header and footer.
	 *
	 * Hooked on 'template_redirect'. When the current request is a BuddyNext
	 * hub route this method resolves the correct relative template path,
	 * enqueues hub-specific assets, injects BuddyNext body classes, then
	 * delegates the full page frame to the active theme via get_header() and
	 * get_footer() — so the theme's navigation, widgets, and footer render
	 * exactly as they do on every other page of the site.
	 *
	 * The 'bn-page' body class is the primary integration signal. BuddyXBridge
	 * reads it so BuddyX skips its .container wrapper on hub pages. The
	 * 'no-sidebar' class is kept for other popular themes (Astra, GeneratePress,
	 * etc.) that suppress their sidebar based on body class — BuddyX does not
	 * use it; its layout is controlled via the buddyx_is_full_width_page filter.
	 *
	 * Calls exit so WordPress never renders its own page content.
	 *
	 * @return void
	 */

	/**
	 * Resolve the hub slug when a BuddyNext hub page is the static front page.
	 *
	 * Returns '' unless the request is the front page, the site shows a static
	 * page on front, and that page is one assigned to a BuddyNext hub via the
	 * buddynext_page_* options. Lets "/" render the assigned hub (Activity,
	 * Members, Spaces, …) instead of an empty page.
	 *
	 * @return string Hub slug (a bn_hub value), or '' when not applicable.
	 */
	private function hub_for_front_page(): string {
		if ( ! is_front_page() || 'page' !== (string) get_option( 'show_on_front' ) ) {
			return '';
		}
		$front_id = (int) get_option( 'page_on_front' );
		if ( $front_id <= 0 ) {
			return '';
		}

		// buddynext_page_* option → bn_hub slug.
		$map = array(
			'buddynext_page_activity'      => 'feed',
			'buddynext_page_explore'       => 'feed',
			'buddynext_page_people'        => 'people',
			'buddynext_page_spaces'        => 'spaces',
			'buddynext_page_messages'      => 'messages',
			'buddynext_page_notifications' => 'notifications',
		);
		foreach ( $map as $option => $slug ) {
			if ( (int) get_option( $option ) === $front_id ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Render the resolved hub template as a standalone document.
	 *
	 * The terminal step of the routing chain: handles the legacy /search/ →
	 * /activity/search/ redirect, sets up a virtual post so theme template tags
	 * resolve, resolves the hub template, and emits the full HTML document
	 * (wp_head + content + wp_footer). Hooked on template_redirect.
	 *
	 * @return void
	 */
	public function dispatch_hub_template(): void {
		// Legacy /search/ → canonical /activity/search/ (301), preserving ?q=.
		if ( '' !== (string) get_query_var( 'bn_legacy_search', '' ) ) {
			$q = isset( $_GET['q'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				? sanitize_text_field( wp_unslash( $_GET['q'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: '';
			wp_safe_redirect( self::search_url( $q ), 301 );
			exit;
		}

		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( '' === $hub ) {
			// When a BuddyNext hub page is set as the WordPress static front
			// page, the rewrite rules don't fire for "/", so bn_hub is never
			// populated and the hub would render blank. Detect that case and
			// resolve the hub from the assigned page so the homepage renders the
			// hub (e.g. Activity) instead of an empty page.
			$hub = $this->hub_for_front_page();
			if ( '' === $hub ) {
				return;
			}
			set_query_var( 'bn_hub', $hub );
		}

		// Feature guard: a hub whose feature the admin has disabled must not
		// render. Spaces is the toggleable hub (FeatureRegistry 'spaces',
		// default-on) — when it is off, send visitors to the activity hub
		// rather than showing a hub the site has turned off.
		if ( 'spaces' === $hub
			&& function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( 'spaces' )
		) {
			wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
			exit;
		}

		// Onboarding is a toggleable hub (FeatureRegistry 'onboarding',
		// default-on). When the owner turns it off, direct visits to the
		// onboarding page must not render it — send them to the activity hub,
		// mirroring the Spaces guard above.
		if ( 'onboarding' === $hub
			&& function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( 'onboarding' )
		) {
			wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
			exit;
		}

		// Hashtag guard: the hashtag feed (/activity/hashtag/{tag}/) belongs to
		// the toggleable Hashtags feature (FeatureRegistry 'hashtags',
		// default-on). When the owner turns it off, the per-tag feed must not
		// render — send visitors to the activity hub.
		if ( 'feed' === $hub
			&& 'hashtag' === (string) get_query_var( 'bn_activity_action', '' )
			&& function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( 'hashtags' )
		) {
			wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
			exit;
		}

		// Public-explore guard: the explore feed (/activity/explore/) is guest-
		// readable by default. When the site owner turns "Public explore feed"
		// off (buddynext_public_explore), explore becomes members-only — send
		// logged-out visitors to the auth page. Logged-in members are never
		// affected. Mirrors the FeedController::require_public_explore REST gate.
		if ( 'feed' === $hub
			&& 'explore' === (string) get_query_var( 'bn_activity_action', '' )
			&& ! is_user_logged_in()
			&& ! (bool) get_option( 'buddynext_public_explore', true )
		) {
			wp_safe_redirect( self::auth_url() );
			exit;
		}

		// Direct-messaging guard: the /messages/ route is dead whenever DMs are
		// off (buddynext_enable_dm) OR the WPMediaVerse engine is absent — bounce
		// the visitor to the activity hub rather than render an unusable hub. Use
		// the canonical entry_enabled() gate (dm_enabled && available), the same
		// one that hides the nav entry points, so the route and the nav agree.
		if ( 'messages' === $hub
			&& ! \BuddyNext\Messages\MessagesData::entry_enabled()
		) {
			wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
			exit;
		}

		$template = $this->resolve_hub_template( $hub );
		if ( null === $template ) {
			return;
		}

		$context = $this->build_hub_context( $hub );

		// Auth hub: redirect logged-in users away from login + signup
		// surfaces. Verify-email stays accessible because a logged-in but
		// unverified user must still see the "check your inbox" state.
		if ( 'auth' === $hub && is_user_logged_in() ) {
			$auth_action = (string) get_query_var( 'bn_auth_action', '' );
			if ( 'verify' !== $auth_action ) {
				wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
				exit;
			}
		}

		// Auth hub /signup/: bounce to /login/?registration=disabled when WP
		// registration is closed. The login template already handles the
		// query param with a friendly notice. Doing this here keeps the
		// redirect before wp_head so no PHP warnings surface.
		if ( 'auth' === $hub
			&& 'signup' === (string) get_query_var( 'bn_auth_action', '' )
			&& ! (bool) get_option( 'users_can_register' )
		) {
			wp_safe_redirect( add_query_arg( 'registration', 'disabled', self::auth_url() ) );
			exit;
		}

		// Login-required hubs: redirect logged-out visitors to /auth/login/
		// BEFORE any output starts. Previously each template handled this
		// itself, but a template runs after wp_head() has emitted CSS, so
		// the late wp_safe_redirect() produced "headers already sent"
		// warnings. Doing it here keeps the redirect clean and routes every
		// gated surface to BN's auth page (not WP's wp-login.php).
		if ( ! is_user_logged_in() ) {
			$feed_section          = (string) get_query_var( 'bn_feed_section', '' );
			$activity_action       = (string) get_query_var( 'bn_activity_action', '' );
			$guarded_feed_sections = array( '', 'home', 'bookmarks', 'saved', 'account-status' );

			// The explore feed shares the 'feed' hub with an empty feed_section,
			// so it would otherwise be swept up by the guarded-section check
			// below. Its guest access is governed solely by the public-explore
			// guard above (buddynext_public_explore) — exempt it here so that,
			// when explore is public, guests actually reach it.
			$is_explore = ( 'feed' === $hub && 'explore' === $activity_action );

			// Honour the per-tab "Login required" option from Settings → Navigation
			// (buddynext_nav_overrides, main scope, keyed by hub slug). Hiding the
			// nav link alone never stopped a guest visiting the hub URL directly, so
			// the option appeared to do nothing for hubs like Spaces — enforce it at
			// the route. Explore keeps its own public-explore gate above, so it is
			// exempt here even if the Feed tab is marked login-required.
			$nav_overrides  = (array) get_option( 'buddynext_nav_overrides', array() );
			$hub_override   = isset( $nav_overrides[ $hub ] ) ? (array) $nav_overrides[ $hub ] : array();
			$override_login = ! empty( $hub_override['login_required'] ) && ! $is_explore;

			// Public explore landing: when "Public explore feed" is on and the Feed
			// tab is not explicitly login-required, a guest hitting the personalised
			// base feed (/activity/ or its Home view) should land on the public
			// explore feed rather than the login wall — that is the whole point of
			// the setting. Personal sections (bookmarks/saved) still require login.
			if ( 'feed' === $hub
				&& ! $is_explore
				&& in_array( $feed_section, array( '', 'home' ), true )
				&& ! $override_login
				&& (bool) get_option( 'buddynext_public_explore', true )
			) {
				wp_safe_redirect( self::explore_url() );
				exit;
			}

			$needs_login =
				$override_login
				|| in_array( $hub, array( 'messages', 'notifications', 'onboarding', 'settings' ), true )
				|| ( 'feed' === $hub && ! $is_explore && in_array( $feed_section, $guarded_feed_sections, true ) );

			if ( $needs_login ) {
				wp_safe_redirect( self::auth_url() );
				exit;
			}
		}

		// Onboarding hub: skip the wizard when the user has already finished
		// it. The `?redo=1` query keeps the back-door so admins can re-run
		// the wizard on their own account. Mirrors the gate above so the
		// redirect runs before any template output.
		if ( 'onboarding' === $hub && is_user_logged_in() ) {
			$redo = isset( $_GET['redo'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! $redo && (bool) get_user_meta( get_current_user_id(), 'bn_onboarding_complete', true ) ) {
				wp_safe_redirect( self::activity_url() );
				exit;
			}
		}

		// ── Virtual page setup ────────────────────────────────────────────
		// No backing WordPress pages exist. Tell WP this is a real page so
		// it sends 200, generates correct <title>, and themes render their
		// full-width layout. Same technique BuddyPress uses for component
		// pages without cluttering the site owner's Pages list.
		global $wp_query;
		$wp_query->is_404  = false;
		$wp_query->is_page = true;
		// Present every hub page as a singular page, on page 1 AND when paginated
		// (?paged=2). Without this, paged>1 flips the underlying query to
		// non-home/non-singular, so themes (e.g. Reign) fall through to their
		// generic page-header branch and render a sub-header on page 2 only —
		// the inconsistency QA saw on the members directory. is_home /
		// is_front_page are intentionally left untouched so a hub set as the
		// static front page still resolves correctly.
		$wp_query->is_singular = true;
		$wp_query->is_archive  = false;
		$wp_query->is_paged    = false;

		// Because we present the hub as singular (is_singular = true), the theme's
		// header runs WP's singular code path — body_class() reads $post->ID /
		// post_type / post_parent off the global $post. On these virtual routes
		// there is no backing post, so without a stub the global $post is null and
		// WP emits "Attempt to read property ... on null" warnings from
		// post-template.php on every hub page. Prime a lightweight virtual WP_Post
		// (and point the query's queried object at it) so every singular-path
		// consumer has a valid object to read. Mirrors how BuddyPress stubs a
		// dummy post for its component pages.
		$virtual_post = new \WP_Post(
			(object) array(
				'ID'             => 0,
				'post_author'    => 0,
				'post_title'     => '',
				'post_name'      => $hub,
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_parent'    => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
				'filter'         => 'raw',
			)
		);
		// A BuddyNext hub page has no real WP post, but theme template tags and
		// the_post()-style helpers read $GLOBALS['post']. Assigning the virtual
		// post here is the documented way to make a synthetic page render; it is
		// intentional, not an accidental global mutation.
		$GLOBALS['post']             = $virtual_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional virtual post for synthetic hub page rendering.
		$wp_query->post              = $virtual_post;
		$wp_query->posts             = array( $virtual_post );
		$wp_query->queried_object    = $virtual_post;
		$wp_query->queried_object_id = 0;
		$wp_query->post_count        = 1;
		$wp_query->found_posts       = 1;

		status_header( 200 );

		// Set the document <title> via the standard wp_title parts filter.
		$hub_titles = array(
			'feed'          => __( 'Activity Feed', 'buddynext' ),
			'post'          => __( 'Post', 'buddynext' ),
			'people'        => __( 'Members', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'messages'      => __( 'Messages', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'auth'          => __( 'Login', 'buddynext' ),
			'onboarding'    => __( 'Get Started', 'buddynext' ),
		);

		$hub_title = $hub_titles[ $hub ] ?? ucfirst( $hub );

		// Auth hub has three actions (login / signup / verify) — give each
		// its own document title so the browser tab and SEO bot see the
		// right surface name.
		if ( 'auth' === $hub ) {
			$auth_action = (string) get_query_var( 'bn_auth_action', '' );
			if ( 'signup' === $auth_action ) {
				$hub_title = __( 'Create an account', 'buddynext' );
			} elseif ( 'verify' === $auth_action ) {
				$hub_title = __( 'Verify your email', 'buddynext' );
			}
		}

		// Bookmarks hub: override the bare "Activity Feed" title with a
		// dedicated label so the document <title> reads "Bookmarks · BuddyNext".
		if ( 'feed' === $hub && 'bookmarks' === (string) get_query_var( 'bn_feed_section', '' ) ) {
			$hub_title = __( 'Bookmarks', 'buddynext' );
		}

		// Specialise the title for per-space surfaces. Mirrors the
		// document_title_parts pattern used for Profile titles below so a
		// space URL renders "{Space} · Spaces" / "Settings · {Space}" /
		// "Members · {Space}" / "About · {Space}" instead of the bare
		// "Spaces" hub fallback. Secret spaces stay leak-proof: the slug
		// resolver only finds rows in bn_spaces, so unresolved slugs fall
		// back to the bare hub title.
		if ( 'spaces' === $hub && ! empty( $context['space_slug'] ) ) {
			$space_record = ( new \BuddyNext\Spaces\SpaceService() )->get_by_slug( (string) $context['space_slug'] );
			// Leak-proof: a secret/unlisted space's name must not appear in the page
			// <title> for a viewer who cannot see it. The body already 404s, but the
			// title was still emitting "{name} · Spaces" (existence + name
			// disclosure). Mirror the body's secret-space gate.
			if ( null !== $space_record
				&& \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_hidden_from_non_members( (string) ( $space_record['type'] ?? '' ) ) ) {
				$bn_title_viewer = get_current_user_id();
				$bn_title_member = $bn_title_viewer > 0
					&& ( new \BuddyNext\Spaces\SpaceMemberService() )->is_member( (int) ( $space_record['id'] ?? 0 ), $bn_title_viewer );
				if ( ! $bn_title_member && ! user_can( $bn_title_viewer, 'manage_options' ) ) {
					$space_record = null;
				}
			}
			if ( null !== $space_record ) {
				$space_name = (string) ( $space_record['name'] ?? '' );
				// Clean URLs: the tab is always bn_space_action now (no ?bn_tab=).
				$space_action  = (string) ( $context['space_action'] ?? '' );
				$section_label = '';
				switch ( $space_action ) {
					case 'settings':
						$section_label = __( 'Settings', 'buddynext' );
						break;
					case 'moderation':
						$section_label = __( 'Moderation', 'buddynext' );
						break;
					case 'admin':
						$section_label = __( 'Admin', 'buddynext' );
						break;
					case 'members':
						$section_label = __( 'Members', 'buddynext' );
						break;
					case 'about':
						$section_label = __( 'About', 'buddynext' );
						break;
					case 'media':
						$section_label = __( 'Media', 'buddynext' );
						break;
				}

				if ( '' !== $section_label && '' !== $space_name ) {
					$hub_title = sprintf(
						/* translators: 1: section name (Settings/Members/About/Moderation), 2: space name. */
						__( '%1$s · %2$s', 'buddynext' ),
						$section_label,
						$space_name
					);
				} elseif ( '' !== $space_name ) {
					$hub_title = sprintf(
						/* translators: %s: space name. */
						__( '%s · Spaces', 'buddynext' ),
						$space_name
					);
				}
			}
		}

		// Specialise the Notifications hub title:
		// - Prefs section → "Notification preferences".
		// - List with unread > 0 → "Notifications (3)" / "Notifications (99+)".
		// Mirrors the Profile / Spaces patterns above so the document <title>
		// reflects the active sub-route and the live unread count. The unread
		// count read is cheap (single COUNT on an indexed column) and only
		// fires when the hub matches.
		if ( 'notifications' === $hub ) {
			$notif_section_for_title = (string) get_query_var( 'bn_notif_section', '' );
			if ( 'prefs' === $notif_section_for_title ) {
				$hub_title = __( 'Notification preferences', 'buddynext' );
			} elseif ( is_user_logged_in() ) {
				$notif_user_id    = get_current_user_id();
				$unread_for_title = ( new \BuddyNext\Notifications\NotificationService() )->unread_count( $notif_user_id );
				if ( $unread_for_title > 0 ) {
					$unread_display = $unread_for_title > 99 ? '99+' : (string) $unread_for_title;
					$hub_title      = sprintf(
						/* translators: %s: unread notification count (formatted, e.g. "3" or "99+"). */
						__( 'Notifications (%s)', 'buddynext' ),
						$unread_display
					);
				}
			}
		}

		// Search results — specialise the title to "Search: {query}" so
		// browser history, bookmarks, and tab strips show what the user
		// looked for instead of the generic "Activity Feed" hub fallback.
		// Read `q` from the request directly since search lives under the
		// activity hub.
		if ( 'feed' === $hub && 'search' === (string) get_query_var( 'bn_activity_action', '' ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display
			$bn_search_q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
			$hub_title   = '' !== $bn_search_q
				? sprintf(
					/* translators: %s: search query string. */
					__( 'Search: %s', 'buddynext' ),
					$bn_search_q
				)
				: __( 'Search', 'buddynext' );
		}

		// Specialise the title when the template gives us a richer label,
		// e.g. "Edit Profile : Varun" instead of the bare hub fallback.
		if ( 'people' === $hub && ! empty( $context['user_id'] ) ) {
			$profile_user_id = (int) $context['user_id'];
			$profile_userobj = get_userdata( $profile_user_id );
			$profile_name    = $profile_userobj ? $profile_userobj->display_name : '';

			if ( 'profile/edit.php' === $template ) {
				$hub_title = '' !== $profile_name
					? sprintf( /* translators: %s: member display name */ __( 'Edit Profile · %s', 'buddynext' ), $profile_name )
					: __( 'Edit Profile', 'buddynext' );
			} elseif ( 'profile/view.php' === $template ) {
				$hub_title = '' !== $profile_name
					? sprintf( /* translators: %s: member display name */ __( '%s · Profile', 'buddynext' ), $profile_name )
					: __( 'Profile', 'buddynext' );
			}
		}

		// Site-wide search-engine indexing policy (Settings → Privacy → "Allow
		// search engines to index"). Only ever ADDS noindex so it composes with
		// the per-profile/space privacy opt-outs below; it never forces a page to
		// be indexable. 'all' = public hubs indexable, 'public_posts' = only the
		// feed/posts, 'none' = noindex every BuddyNext page. Private hubs
		// (messages/notifications/auth/onboarding) are never indexable.
		$indexing      = (string) get_option( 'buddynext_google_indexing', 'public_posts' );
		$is_posts      = ( 'feed' === $hub || 'activity' === $hub );
		$is_public     = ( $is_posts || 'people' === $hub || 'spaces' === $hub );
		$force_noindex = ( 'none' === $indexing )
			|| ( 'public_posts' === $indexing && ! $is_posts )
			|| ( 'all' === $indexing && ! $is_public );
		if ( $force_noindex ) {
			add_filter(
				'wp_robots',
				static function ( array $robots ): array {
					$robots['noindex']  = true;
					$robots['nofollow'] = true;
					unset( $robots['index'], $robots['follow'] );
					return $robots;
				}
			);
		}

		// Per-profile search-engine opt-out. Members are indexable by default;
		// only an explicit '0' on bn_privacy_search_indexable opts out. Runs
		// here (before get_header()/wp_head) so the wp_robots filter applies.
		if ( 'people' === $hub && 'profile/view.php' === $template && ! empty( $context['user_id'] ) ) {
			if ( '0' === (string) get_user_meta( (int) $context['user_id'], 'bn_privacy_search_indexable', true ) ) {
				add_filter(
					'wp_robots',
					static function ( array $robots ): array {
						$robots['noindex']  = true;
						$robots['nofollow'] = true;
						unset( $robots['index'], $robots['follow'] );
						return $robots;
					}
				);
			}
		}

		$title_frozen = $hub_title;
		add_filter(
			'document_title_parts',
			static function ( array $parts ) use ( $title_frozen ): array {
				$parts['title'] = $title_frozen;
				return $parts;
			}
		);

		// Enqueue hub-specific asset bundles before wp_head() fires (which
		// happens inside get_header() → theme's header.php).
		$this->enqueue_hub_assets( $hub );

		// Inject BuddyNext body classes via the standard body_class filter so
		// the active theme's <body> tag carries them alongside its own classes.
		// 'bn-page' is the BuddyX signal (see BuddyXBridge + header.php);
		// 'no-sidebar' is kept for other themes that honour it.
		$hub_snapshot = $hub;
		add_filter(
			'body_class',
			static function ( array $classes ) use ( $hub_snapshot ): array {
				$classes[] = 'bn-page';
				$classes[] = 'bn-hub-' . $hub_snapshot;
				$classes[] = 'no-sidebar';
				return $classes;
			}
		);

		// v2 token system reads density/theme/text-scale modes off <html>
		// via [data-bn-*] selectors. Density is stamped server-side because
		// it's not user-configurable yet. Theme is owned by
		// assets/js/shell/font-scale.js (head-blocking script) which reads
		// localStorage `bn_theme` and `prefers-color-scheme`; the :root rule
		// in bn-base.css already aliases to light tokens, so the brief
		// pre-script moment paints with the correct light defaults.
		add_filter(
			'language_attributes',
			static function ( string $output ): string {
				if ( false !== strpos( $output, 'data-bn-density=' ) ) {
					return $output;
				}
				return $output . ' data-bn-density="comfortable"';
			}
		);

		// Single-post permalink (/p/{id}/): wire OG / Twitter / canonical
		// head meta tags BEFORE get_header() fires wp_head. Without this hook,
		// the template can't influence <head> because by the time it runs,
		// wp_head() has already been emitted. Gates mirror the template so
		// private / followers-only / secret-space / blocked posts never leak
		// OG previews to scrapers.
		if ( 'post' === $hub ) {
			$this->maybe_register_single_post_meta( (int) ( $context['post_id'] ?? 0 ) );
		}

		// Community description (Settings → General) as the page meta description
		// on every BN hub — the help text promises it appears "in meta tags".
		$this->maybe_register_community_meta_description();

		do_action( 'buddynext_before_hub', $hub, $template );

		// htmx partial swap: when request has HX-Request header, return only
		// the template content (no theme header/footer). This enables SPA-like
		// navigation where only the content area swaps on link clicks.
		if ( ! empty( $_SERVER['HTTP_HX_REQUEST'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			header( 'HX-Push-Url: ' . esc_url( home_url( add_query_arg( array() ) ) ) );
			header( 'HX-Trigger: bnPageSwap' );
			buddynext_get_template( $template, $context );
			exit;
		}

		// BuddyNext sits *inside* the active theme's chrome. The theme owns
		// the document — DOCTYPE / <html> / <head> / wp_head() / <body> /
		// wp_body_open() / wp_footer() / </html> all come from the theme via
		// get_header() and get_footer(). BuddyNext only renders the .bn-app
		// canvas in between. The canvas bursts to 100vw in CSS so it stays
		// edge-to-edge regardless of whatever container the theme wraps
		// content in. There is no opt-out filter; the host theme's header +
		// footer always render on BN-mapped slugs.
		$this->render_shell_with_theme_chrome( $hub, $template, $context );
		exit;
	}

	/**
	 * Render the .bn-app shell wrapped by the active theme's header + footer.
	 *
	 * Extracted from dispatch_hub_template() so unit tests can exercise the
	 * render path without hitting the trailing exit. Production code always
	 * reaches this through dispatch_hub_template(), which then exits.
	 *
	 * @param string               $hub      Active bn_hub query var.
	 * @param string               $template Relative template path resolved for the hub.
	 * @param array<string, mixed> $context  Template context built by build_hub_context().
	 * @return void
	 */
	public function render_shell_with_theme_chrome( string $hub, string $template, array $context ): void {
		$shell_context = array_merge(
			$context,
			array(
				'inner_template' => $template,
				'hub'            => $hub,
				'context'        => $context,
			)
		);

		// Auth surfaces (login, signup, verify-email) use a slim centered
		// single-column shell — not the rail + main + sidebar feed shell.
		// Auth + onboarding share the slim, full-viewport shell — both are
		// focused wizards the user must complete linearly and should not
		// see the BN navigation rail while doing so. Every other hub uses
		// the standard two-column hub shell with the navigation visible.
		$shell_template = in_array( $hub, array( 'auth', 'onboarding' ), true )
			? 'shell/auth-shell.php'
			: 'shell/hub-shell.php';

		get_header();
		buddynext_get_template( $shell_template, $shell_context );
		get_footer();
	}

	/**
	 * Hydrate the single-post record and register head-meta tags when visible.
	 *
	 * Called from dispatch_hub_template() at template_redirect (before
	 * get_header() fires wp_head). Mirrors the visibility gates in
	 * templates/feed/single-post.php so we never emit OG / Twitter previews
	 * for private, blocked, followers-only, or secret-space posts.
	 *
	 * @param int $post_id Post ID resolved from the /p/{id}/ rewrite.
	 * @return void
	 */
	private function maybe_register_single_post_meta( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}
		if ( ! class_exists( \BuddyNext\Feed\SinglePostMeta::class ) ) {
			return;
		}

		$post = ( new \BuddyNext\Feed\PostService() )->get( $post_id );
		if ( null === $post ) {
			return;
		}

		$viewer_id = get_current_user_id();
		$author_id = (int) ( $post['user_id'] ?? 0 );

		// Status gate: only published posts get OG previews (drafts and
		// archived rows shouldn't deep-link into chat clients).
		if ( isset( $post['status'] ) && 'published' !== $post['status'] && $viewer_id !== $author_id ) {
			return;
		}

		// Block gate (bidirectional).
		if ( $viewer_id > 0 && $author_id > 0 && $viewer_id !== $author_id ) {
			$blocks = function_exists( 'buddynext_service' )
				? buddynext_service( 'blocks' )
				: new \BuddyNext\SocialGraph\BlockService();
			if ( $blocks->is_blocking_either( $viewer_id, $author_id ) ) {
				return;
			}
		}

		// Secret-space gate.
		$space_id = (int) ( $post['space_id'] ?? 0 );
		if ( $space_id > 0 ) {
			$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
			if ( null !== $space && \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_hidden_from_non_members( (string) ( $space['type'] ?? '' ) ) ) {
				$is_member = $viewer_id > 0 && ( new \BuddyNext\Spaces\SpaceMemberService() )->is_member( $space_id, $viewer_id );
				if ( ! $is_member && ! user_can( $viewer_id, 'manage_options' ) ) {
					return;
				}
			}
		}

		// Followers-only gate.
		if ( 'followers' === ( $post['privacy'] ?? '' ) && $author_id !== $viewer_id ) {
			$follows     = function_exists( 'buddynext_service' )
				? buddynext_service( 'follows' )
				: new \BuddyNext\SocialGraph\FollowService();
			$is_follower = $viewer_id > 0 && $follows->is_following( $viewer_id, $author_id );
			if ( ! $is_follower ) {
				return;
			}
		}

		// Private gate.
		if ( 'private' === ( $post['privacy'] ?? '' ) && $author_id !== $viewer_id ) {
			return;
		}

		// Author suspension / shadow-ban gate (admins + the author see through).
		// Read suspension via the canonical ModerationService::is_suspended()
		// (the bn_user_suspensions table) rather than the bn_suspended usermeta —
		// the meta is only set by the admin-panel path, so strike-threshold
		// auto-suspensions (which write only the suspensions row) were leaking
		// through this gate.
		if ( $author_id > 0 && $author_id !== $viewer_id && ! user_can( $viewer_id, 'manage_options' ) ) {
			$author_suspended = buddynext_service( 'moderation' )->is_suspended( $author_id );
			$author_shadow    = (bool) get_user_meta( $author_id, 'bn_shadow_banned', true );
			if ( $author_suspended || $author_shadow ) {
				return;
			}
		}

		\BuddyNext\Feed\SinglePostMeta::emit_for_post( $post );
	}

	/**
	 * Emit the community description as the page <meta name="description"> on
	 * BN hubs, fulfilling the Settings → General help text ("shown on the
	 * community landing page and in meta tags").
	 *
	 * Skips when the owner left the description blank, and when a major SEO
	 * plugin is active (Yoast, Rank Math, AIOSEO, SEOPress, The SEO Framework)
	 * so we never emit a duplicate meta description alongside the plugin's own.
	 * Filterable via `buddynext_meta_description` for full per-site control.
	 *
	 * @return void
	 */
	private function maybe_register_community_meta_description(): void {
		$description = trim( (string) get_option( 'buddynext_description', '' ) );

		/**
		 * Filter the BuddyNext community meta description.
		 *
		 * Return an empty string to suppress the tag entirely.
		 *
		 * @param string $description Community description (Settings → General).
		 */
		$description = (string) apply_filters( 'buddynext_meta_description', $description );
		if ( '' === $description ) {
			return;
		}

		// Defer to an active SEO plugin — emitting our own tag would duplicate
		// the head meta description.
		if (
			defined( 'WPSEO_VERSION' )              // Yoast SEO.
			|| class_exists( 'RankMath' )           // Rank Math.
			|| defined( 'AIOSEO_VERSION' )          // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )        // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' ) // The SEO Framework.
		) {
			return;
		}

		add_action(
			'wp_head',
			static function () use ( $description ): void {
				echo '<meta name="description" content="' . esc_attr( $description ) . '">' . "\n";
			},
			1
		);
	}

	/**
	 * Enqueue the CSS/JS bundle(s) for the current hub before wp_head() fires.
	 *
	 * Called from dispatch_hub_template() once the active hub is known, so
	 * per-hub asset decisions can be made accurately.
	 *
	 * @param string $hub Active bn_hub value.
	 * @return void
	 */
	private function enqueue_hub_assets( string $hub ): void {
		$assets = buddynext_service( 'assets' );

		// Shell CSS + font-scale script — required on every BN hub so the
		// .bn-app wrapper and rail render correctly. The active theme's
		// get_header() is the only top navigation; BN no longer owns a topbar.
		wp_enqueue_style( 'bn-shell' );
		wp_enqueue_script( 'bn-shell-font-scale' );
		wp_enqueue_script( 'bn-shell-extras' );

		// Social-buttons store powers the standalone Follow + Connect partials
		// (sidebar widgets, block-rendered buttons, etc.) on every BN hub.
		wp_enqueue_script_module( '@buddynext/social-buttons' );

		// Notifications store runs the background unread-count poll on every hub
		// so the header bell badge (.bn-notification-badge, rendered site-wide)
		// and the mobile nav badge stay live everywhere — not just on the
		// /notifications/ page. The poll reads its REST base/nonce from
		// window.bnShellData when the on-page Interactivity wrapper is absent.
		if ( is_user_logged_in() ) {
			wp_enqueue_script_module( '@buddynext/notifications' );
		}

		// Client-side navigation action — owns the .bn-app navigate handler and
		// lazy-loads the Interactivity router. Loaded ONLY when client-nav is
		// enabled. While it is off (the rollout default), hub-shell.php does not
		// render the navigate directive, so the module would have no consumer —
		// shipping it would be a dead asset on every visitor's page. Gate on the
		// same buddynext_client_nav_enabled filter the shell reads so the enqueue
		// and the directive stay in lockstep.
		$bn_client_nav = (bool) apply_filters( 'buddynext_client_nav_enabled', false );
		if ( $bn_client_nav ) {
			wp_enqueue_script_module( '@buddynext/navigate' );
		}

		// Localize REST endpoints + nav URLs for shell/extras.js.
		//
		// This method runs at template_redirect, which fires BEFORE the
		// wp_enqueue_scripts hook where bn-shell-extras is registered (priority
		// 10). Attaching localized data to an unregistered handle is a silent
		// no-op (WP_Dependencies::add_data() bails when the handle is unknown),
		// so a direct wp_localize_script() here would drop window.bnShellData
		// entirely — and with it the hover card, search overlay, and notif
		// dropdown all lose their REST config. Defer the attach to
		// wp_enqueue_scripts (priority 20), once the handle exists.
		$bn_shell_data = array(
			'restNonce'          => wp_create_nonce( 'wp_rest' ),
			'restSearchUrl'      => esc_url_raw( rest_url( 'buddynext/v1/search' ) ),
			'restNotifsUrl'      => esc_url_raw( rest_url( 'buddynext/v1/me/notifications?per_page=5' ) ),
			'restNotifsReadUrl'  => esc_url_raw( rest_url( 'buddynext/v1/me/notifications/read-all' ) ),
			'restUserUrl'        => esc_url_raw( rest_url( 'buddynext/v1/users/' ) ),
			'feedUrl'            => self::activity_url(),
			// Soft chime played by notifications/store.js maybePlaySound() when the
			// member has the "Play a sound" channel enabled. The asset previously
			// did not exist and this key was never injected, so the sound channel
			// was a dead toggle.
			'notifSoundUrl'      => defined( 'BUDDYNEXT_URL' ) ? esc_url_raw( BUDDYNEXT_URL . 'assets/sounds/notif.wav' ) : '',
			'navUrls'            => array(
				'feed'          => self::activity_url(),
				'members'       => self::people_url(),
				'spaces'        => self::spaces_url(),
				'notifications' => self::notifications_url(),
				'messages'      => self::messages_url(),
			),
			// Rollout master switch for client-side navigation. OFF until the
			// per-surface init() handlers are made nav-aware (Phase 3) and
			// browser-verified (Phase 5) — enabling client-nav before a surface
			// is hardened would let its imperative setup die after a swap (the
			// exact bug class the standard prevents). The navigate action is
			// wired and inert until this flips true. Filterable for staged
			// activation once surfaces are verified.
			'clientNav'          => $bn_client_nav,
			// Deny-list path prefixes for the client-side navigate action.
			// Routes matching these full-load instead of client-navigating
			// (rich editors + security-sensitive flows). Resolved server-side
			// because hub slugs are admin-configurable — the action cannot
			// assume fixed path segments. Default = client-nav (deny-list, not
			// allow-list), so new routes are fast by default.
			/**
			 * Client-nav deny-list: path prefixes that must FULL-LOAD instead of
			 * client-navigating. Routes here render in their own Interactivity
			 * router region (or are rich/secure flows), so the buddynext/main
			 * router cannot swap them in — a client-side swap would inject
			 * region-less HTML and break the page.
			 *
			 * Filterable so integrations whose surfaces live OUTSIDE buddynext/main
			 * (Career Board jobs/companies/resumes, Listora listings, Learnomy
			 * courses, Gamification) register their own bases — otherwise links to
			 * those pages break under client-nav. Each value is a path prefix or an
			 * array of prefixes; the navigate action full-loads any matching prefix.
			 *
			 * @param array<string,string|string[]> $deny Deny-list keyed by surface.
			 */
			'navDeny'            => apply_filters(
				'buddynext_client_nav_deny',
				array(
					'auth'        => wp_parse_url( self::auth_url(), PHP_URL_PATH ),
					'signup'      => wp_parse_url( self::signup_url(), PHP_URL_PATH ),
					'verify'      => wp_parse_url( self::verify_url(), PHP_URL_PATH ),
					'reset'       => wp_parse_url( self::reset_url(), PHP_URL_PATH ),
					'onboarding'  => wp_parse_url( self::onboarding_url(), PHP_URL_PATH ),
					'spaces'      => wp_parse_url( self::spaces_url(), PHP_URL_PATH ),
					'people'      => wp_parse_url( self::people_url(), PHP_URL_PATH ),
					// Partner-plugin surfaces (WPMediaVerse, Jetonomy) render in their
					// OWN router region, not buddynext/main, so they must FULL-LOAD.
					// Both bases are ADMIN-CONFIGURABLE, so resolve them from each
					// plugin's own config — never hardcode /media/ or /community/.
					'media'       => self::wpmediaverse_deny_paths(),
					'discussions' => self::jetonomy_deny_paths(),
				)
			),
			// Rich-route deny PATTERNS — sub-routes that must FULL-LOAD because they
			// host a rich editor / their own router region (NOT whole-surface bases,
			// which live in navDeny above). Owned here because PageRouter defines these
			// routes; emitted as JS-RegExp source strings tested against the path, so
			// the transport carries ZERO hardcoded route literals. Built from the live,
			// admin-configurable people/space bases so a renamed base stays accurate.
			'navDenyPatterns'    => array_values(
				array_filter(
					(array) apply_filters(
						'buddynext_client_nav_deny_patterns',
						array(
							// Profile edit — rich uploader + repeater fields.
							preg_quote( rtrim( (string) wp_parse_url( self::people_url(), PHP_URL_PATH ), '/' ), '/' ) . '/[^/]+/edit/?$',
							// Space settings / admin — cover-icon upload + forms.
							preg_quote( rtrim( (string) wp_parse_url( self::spaces_url(), PHP_URL_PATH ), '/' ), '/' ) . '/[^/]+/(settings|admin)/?$',
							// Single-post permalink — rich reply composer.
							'/p/\\d+/?$',
							// Membership checkout — Stripe Embedded Checkout mounts here.
							'/(checkout|membership/checkout)/?$',
						)
					),
					static fn( $p ): bool => is_string( $p ) && '' !== $p
				)
			),
			// Connect-request style. Default false = 1-click connect (Facebook).
			// When the owner turns on buddynext_connection_require_note, the
			// Connect button opens a note dialog (LinkedIn) and the note is
			// delivered to the recipient's DM as a message request. Read once here
			// so every connect surface shares one source of truth instead of
			// threading the flag through each button's data-wp-context.
			'connectRequireNote' => ( '1' === (string) get_option( 'buddynext_connection_require_note', '0' ) ),
		);
		// Base config for the shared REST client module (@buddynext/rest-client).
		// Emitted on bn-shell-extras (always enqueued on every hub) so the
		// inline classic script runs before the deferred store modules read
		// window.buddynextRestData. restNonce is the fallback; it self-refreshes
		// via GET /auth/nonce when a 403 rest_cookie_invalid_nonce is hit.
		$bn_rest_data = array(
			'restBase'  => esc_url_raw( rest_url( 'buddynext/v1' ) ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
		);
		add_action(
			'wp_enqueue_scripts',
			static function () use ( $bn_shell_data, $bn_rest_data ): void {
				wp_localize_script( 'bn-shell-extras', 'bnShellData', $bn_shell_data );
				wp_localize_script( 'bn-shell-extras', 'buddynextRestData', $bn_rest_data );
			},
			20
		);

		// Client-side navigation swaps <main> without re-running this method, so
		// the destination hub's store module + CSS must already be present on the
		// page the user navigates FROM. When client-nav is active, load the
		// region-content union on every hub. Gated on the rollout flag so that
		// while client-nav is off the lighter per-hub enqueue below is unchanged.
		if ( (bool) apply_filters( 'buddynext_client_nav_enabled', false ) ) {
			foreach ( array( 'feed', 'profile', 'spaces', 'members', 'messages', 'notifications', 'search', 'hashtags', 'gamification', 'moderation', 'space-members' ) as $bn_union_feature ) {
				$assets->enqueue( $bn_union_feature );
			}
			// Explore reuses the feed store; only its stylesheet is separate.
			wp_enqueue_style( 'bn-explore' );
		}

		switch ( $hub ) {
			case 'feed':
				$assets->enqueue( 'feed' );
				// Account-status (the viewer's own moderation standing) reuses the
				// moderation stylesheet for its banner + detail-row chrome and the
				// moderation Interactivity store for the appeal-submission form.
				if ( 'account-status' === (string) get_query_var( 'bn_feed_section', '' ) ) {
					$assets->enqueue( 'moderation' );
				}
				// Explore is BuddyNext's signature discovery surface — its own
				// stylesheet (bn-explore.css) so the masonry grid + varied cards
				// evolve independently of the activity feed (bn-feed.css).
				if ( 'explore' === (string) get_query_var( 'bn_activity_action', '' ) ) {
					wp_enqueue_style( 'bn-explore' );
				}
				// Hashtag feed additionally needs the hashtag store module.
				if ( 'hashtag' === (string) get_query_var( 'bn_activity_action', '' ) ) {
					$assets->enqueue( 'hashtags' );
				}
				// Search results live under the activity hub
				// (/activity/search/) — pull in the search store so
				// the date/sort filters, `/` keyboard shortcut, and
				// recent-searches panel hydrate.
				if ( 'search' === (string) get_query_var( 'bn_activity_action', '' ) ) {
					$assets->enqueue( 'search' );
				}
				// Leaderboard lives under the activity hub (/activity/leaderboard/)
				// — load the gamification bundle so the board styles + period
				// tabs hydrate.
				if ( 'leaderboard' === (string) get_query_var( 'bn_activity_action', '' ) ) {
					$assets->enqueue( 'gamification' );
				}
				break;

			case 'post':
				// Single-post permalink page reuses the feed bundle — post cards,
				// composer (for the reply form), and the share modal are all
				// driven by the feed Interactivity store.
				$assets->enqueue( 'feed' );
				break;

			case 'people':
				// Single-profile view/edit vs. member directory. Gate on the
				// user-slug query var, not $context['user_id'] — the own-profile
				// edit route (/members/{slug}/edit/) resolves to the current user
				// and leaves $context['user_id'] empty, which previously dropped
				// bn-profile.css and left the entire edit hero (.bn-ep-* avatar,
				// cover, and field chrome) unstyled.
				if ( '' !== (string) get_query_var( 'bn_user_slug', '' ) ) {
					$assets->enqueue( 'profile' );
					$assets->enqueue( 'feed' ); // Post cards on profile use bn-feed.css classes.
					$assets->enqueue( 'media-upload' ); // Owner-only upload composer on the Media tab.
					$assets->enqueue( 'media-albums' ); // Media | Albums sub-nav + albums UI.
					// Followers / Following / Connections render as in-page tabs in
					// the profile shell (parts/member-grid.php, server-rendered and
					// toggled client-side), so the shared member cards are always in
					// the DOM. Always load bn-members.css (grid + card-action styling)
					// and the @buddynext/members store (card follow/connect + overflow
					// menus) so the panels are never unstyled.
					$assets->enqueue( 'members' );
				} else {
					$assets->enqueue( 'members' );
				}
				break;

			case 'spaces':
				$assets->enqueue( 'spaces' );
				$assets->enqueue( 'feed' ); // Post cards on space pages use bn-feed.css classes.
				// Cover/icon uploads on the settings sub-route POST directly to the
				// REST API (ImageStorageService) — no wp.media / attachment picker.
				$space_action_v = (string) get_query_var( 'bn_space_action', '' );
				// Space moderation sub-page reuses the buddynext/moderation store
				// for its report-action buttons (dismiss/warn/remove/remove-from-
				// space), so the module must load here too — the spaces store does
				// not define those actions.
				if ( 'moderation' === $space_action_v ) {
					$assets->enqueue( 'moderation' );
				}
				// The members sub-view AND the settings "Members" panel render
				// Remove / Change-role / Ban / Invite buttons bound to the
				// buddynext/space-members store, so that module must load on both —
				// without it the buttons render but never hydrate.
				$bn_space_tab = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( in_array( $space_action_v, array( 'members', 'settings' ), true ) || 'members' === $bn_space_tab ) {
					$assets->enqueue( 'space-members' );
				}
				// The settings "Custom fields" panel saves registered space fields
				// over REST via the buddynext/space-fields store.
				if ( 'settings' === $space_action_v ) {
					$assets->enqueue( 'space-fields' );
				}
				// Localize the spaces URL base + i18n so the spaces store can
				// rebuild URLs without reloading the page (reactive directory,
				// create-space redirect target). Deferred to wp_enqueue_scripts
				// for the same reason as bnShellData above — bn-shell-extras is
				// not registered yet at template_redirect, so an inline script
				// attached now would be dropped.
				$bn_spaces_data = wp_json_encode(
					array(
						'spaceUrlBase' => esc_url_raw( self::spaces_url() . '__slug__/' ),
						'directoryUrl' => esc_url_raw( self::spaces_url() ),
						'restNonce'    => wp_create_nonce( 'wp_rest' ),
						'restUrl'      => esc_url_raw( rest_url( 'buddynext/v1' ) ),
					)
				);
				add_action(
					'wp_enqueue_scripts',
					static function () use ( $bn_spaces_data ): void {
						wp_add_inline_script(
							'bn-shell-extras',
							'window.bnSpaces = window.bnSpaces || ' . $bn_spaces_data . ';',
							'before'
						);
					},
					20
				);
				break;

			case 'messages':
				$assets->enqueue( 'messages' );
				break;

			case 'notifications':
				$assets->enqueue( 'notifications' );
				$notif_section = (string) get_query_var( 'bn_notif_section', '' );
				if ( 'prefs' === $notif_section ) {
					$assets->enqueue( 'notification-prefs' );
					// The prefs page is the Settings hub's "Notifications" tab.
					wp_enqueue_style( 'bn-settings' );
				}
				break;

			case 'settings':
				// Relocated account/privacy/appearance sections reuse the profile
				// editor's `.bn-ep-*` styles, plus the shared settings-tab chrome.
				$assets->enqueue( 'profile' );
				$assets->enqueue( 'settings' );
				break;

			case 'auth':
				$assets->enqueue( 'auth' );
				$auth_action = (string) get_query_var( 'bn_auth_action', '' );
				switch ( $auth_action ) {
					case 'signup':
						wp_enqueue_script_module( '@buddynext/auth-signup' );
						break;
					case 'verify':
						wp_enqueue_script_module( '@buddynext/auth-verify' );
						break;
					case 'reset':
						wp_enqueue_script_module( '@buddynext/auth-reset' );
						break;
					case 'login':
					default:
						wp_enqueue_script_module( '@buddynext/auth-login' );
						break;
				}
				break;

			case 'moderation':
				$assets->enqueue( 'moderation' );
				break;

			case 'onboarding':
				$assets->enqueue( 'onboarding' );
				break;
		}
	}

	/**
	 * Resolve the WPMediaVerse client-nav deny-list paths.
	 *
	 * WPMediaVerse renders its surfaces in its OWN Interactivity router region,
	 * so links to them must full-load (not client-swap into buddynext/main). BN's
	 * only WPMediaVerse nav target is the admin-mapped Explore Media page (option
	 * mvs_page_explore) — resolved from config so a renamed page still denies it —
	 * plus the /media/ rewrite base for any media-item permalink in BN content.
	 *
	 * @return string[] Path prefixes that must full-load (empty when MVS inactive).
	 */
	private static function wpmediaverse_deny_paths(): array {
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' ) ) {
			return array();
		}
		// BN links to ONE WPMediaVerse surface — the Explore Media page (the Media
		// nav tab). My Media / Upload are not BN nav targets (BN renders its own
		// media tab on the member profile + uploads via its composer), so they are
		// deliberately not denied. The /media/ rewrite base is kept so a media-item
		// permalink surfaced in BN content still full-loads.
		$paths      = array( '/media/' );
		$explore_id = (int) get_option( 'mvs_page_explore', 0 );
		if ( $explore_id > 0 ) {
			$bn_path = wp_parse_url( (string) get_permalink( $explore_id ), PHP_URL_PATH );
			if ( is_string( $bn_path ) && '' !== $bn_path ) {
				$paths[] = $bn_path;
			}
		}
		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * Resolve the Jetonomy client-nav deny-list paths.
	 *
	 * Jetonomy renders in its own router region under an admin-configurable
	 * Community Base URL (default /community/); read it from Jetonomy's own
	 * base_url() so a renamed base is still denied.
	 *
	 * @return string[] Path prefixes that must full-load (empty when Jetonomy inactive).
	 */
	private static function jetonomy_deny_paths(): array {
		if ( ! function_exists( 'Jetonomy\\base_url' ) ) {
			return array();
		}
		$bn_path = wp_parse_url( (string) \Jetonomy\base_url(), PHP_URL_PATH );
		return ( is_string( $bn_path ) && '' !== $bn_path ) ? array( $bn_path ) : array( '/community/' );
	}

	/**
	 * Build the template context array for the current hub request.
	 *
	 * Resolves URL-segment query vars (user slugs, space slugs, conversation
	 * IDs) into the scalar values each template expects as local variables.
	 *
	 * @param string $hub The active bn_hub query var value.
	 * @return array<string,mixed>
	 */
	private function build_hub_context( string $hub ): array {
		global $wpdb;

		$context = array();

		switch ( $hub ) {
			case 'people':
				$user_slug = (string) get_query_var( 'bn_user_slug', '' );
				if ( '' !== $user_slug ) {
					// resolve_user() honours a member's custom bn_profile_slug
					// (then user-{id}, then user_nicename); get_user_by('slug')
					// alone only matches user_nicename, so a custom profile URL
					// would soft-404 to the members directory.
					$user               = $this->resolve_user( $user_slug );
					$context['user_id'] = $user instanceof WP_User ? (int) $user->ID : 0;
				}
				$context['profile_action'] = (string) get_query_var( 'bn_profile_action', '' );
				break;

			case 'spaces':
				$space_slug = (string) get_query_var( 'bn_space_slug', '' );
				if ( '' !== $space_slug ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$space_id = (int) $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s LIMIT 1",
							$space_slug
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$context['space_id'] = $space_id;
				}
				$context['space_slug']   = $space_slug;
				$context['space_action'] = (string) get_query_var( 'bn_space_action', '' );
				break;

			case 'messages':
				$context['conv_id']    = (int) get_query_var( 'bn_conv_id', 0 );
				$context['msg_action'] = (string) get_query_var( 'bn_msg_action', '' );
				break;

			case 'feed':
				$context['activity_action'] = (string) get_query_var( 'bn_activity_action', '' );
				$context['hashtag']         = (string) get_query_var( 'bn_hashtag', '' );
				$context['feed_section']    = (string) get_query_var( 'bn_feed_section', '' );
				break;

			case 'post':
				$context['post_id'] = (int) get_query_var( 'bn_post_id', 0 );
				break;
		}

		return $context;
	}

	/**
	 * Map a hub + active sub-action query vars to a relative template path.
	 *
	 * Returns null when the hub value is not recognised, which causes
	 * dispatch_hub_template() to fall through and let WordPress handle the
	 * request normally (e.g. during unit tests or misconfigured setups).
	 *
	 * @param string $hub The bn_hub query var value.
	 * @return string|null Relative path without extension, e.g. 'feed/home'.
	 */
	private function resolve_hub_template( string $hub ): ?string {
		switch ( $hub ) {
			case 'feed':
				$section = (string) get_query_var( 'bn_feed_section', '' );
				if ( 'bookmarks' === $section ) {
					return 'feed/bookmarks.php';
				}
				if ( 'account-status' === $section ) {
					return 'moderation/account-status.php';
				}
				$action = (string) get_query_var( 'bn_activity_action', '' );
				switch ( $action ) {
					case 'explore':
						return 'feed/explore.php';
					case 'hashtag':
						return 'hashtags/feed.php';
					case 'search':
						return 'search/results.php';
					case 'leaderboard':
						return 'gamification/leaderboard.php';
					default:
						return 'feed/home.php';
				}

			case 'post':
				return 'feed/single-post.php';

			case 'people':
				$user_slug = (string) get_query_var( 'bn_user_slug', '' );
				if ( '' !== $user_slug ) {
					$profile_action = (string) get_query_var( 'bn_profile_action', '' );
					switch ( $profile_action ) {
						case 'edit':
							return 'profile/edit.php';
						default:
							// `media` (and any other profile action without a
							// dedicated template) opens the profile view; view.php
							// deep-links the matching tab from bn_profile_action.
							return 'profile/view.php';
					}
				}
				return 'directory/members.php';

			case 'spaces':
				$space_slug = (string) get_query_var( 'bn_space_slug', '' );
				if ( '' !== $space_slug ) {
					$space_action = (string) get_query_var( 'bn_space_action', '' );
					switch ( $space_action ) {
						case 'members':
							return 'spaces/members.php';
						case 'moderation':
							return 'spaces/moderation.php';
						case 'settings':
							return 'spaces/settings.php';
						case 'admin':
							return 'spaces/admin.php';
						default:
							// feed / about / media (+ any in-page integration tab)
							// render the space home, which reads bn_space_action for
							// the active tab. Members + Moderation keep their richer
							// standalone pages (full member management / report queue).
							return 'spaces/home.php';
					}
				}
				return 'spaces/directory.php';

			case 'messages':
				$conv_id    = (int) get_query_var( 'bn_conv_id', 0 );
				$msg_action = (string) get_query_var( 'bn_msg_action', '' );
				if ( $conv_id > 0 ) {
					return 'messages/thread.php';
				}
				if ( 'requests' === $msg_action ) {
					return 'messages/requests.php';
				}
				return 'messages/list.php';

			case 'notifications':
				$notif_section = (string) get_query_var( 'bn_notif_section', '' );
				if ( 'prefs' === $notif_section ) {
					return 'notifications/prefs.php';
				}
				return 'notifications/index.php';

			case 'settings':
				$settings_section = (string) get_query_var( 'bn_settings_section', '' );
				if ( ! in_array( $settings_section, array( 'account', 'privacy', 'appearance' ), true ) ) {
					$settings_section = 'account';
				}
				return 'settings/' . $settings_section . '.php';

			case 'auth':
				$auth_action = (string) get_query_var( 'bn_auth_action', '' );
				switch ( $auth_action ) {
					case 'signup':
						return 'auth/signup.php';
					case 'verify':
						return 'auth/verify.php';
					case 'reset':
						return 'auth/reset.php';
					case 'login':
					default:
						return 'auth/login.php';
				}

			case 'moderation':
				return 'moderation/queue.php';

			case 'onboarding':
				return 'onboarding/index.php';

			default:
				$bn_descriptor = HubRegistry::instance()->get( $hub );
				if ( null !== $bn_descriptor && is_callable( $bn_descriptor->resolve_template ) ) {
					return ( $bn_descriptor->resolve_template )( $hub );
				}
				return null;
		}
	}

	// ── Rewrite registration ──────────────────────────────────────────────────

	/**
	 * Register all rewrite tags and hub rewrite rules.
	 *
	 * Called on the 'init' action. Specific patterns are always registered
	 * before catch-all patterns so WordPress matches them first ('top' priority).
	 *
	 * @return void
	 */
	public function register_rewrites(): void {
		// ── Rewrite tags ──────────────────────────────────────────────────────
		add_rewrite_tag( '%bn_hub%', '([a-z]+)' );
		add_rewrite_tag( '%bn_activity_action%', '([^/]*)' );
		add_rewrite_tag( '%bn_hashtag%', '([^/]+)' );
		add_rewrite_tag( '%bn_user_slug%', '([^/]+)' );
		add_rewrite_tag( '%bn_profile_action%', '([^/]*)' );
		add_rewrite_tag( '%bn_space_slug%', '([^/]+)' );
		add_rewrite_tag( '%bn_space_action%', '([^/]*)' );
		add_rewrite_tag( '%bn_conv_id%', '([0-9]+)' );
		add_rewrite_tag( '%bn_msg_action%', '([^/]*)' );
		add_rewrite_tag( '%bn_member_type%', '([a-z0-9-]+)' );
		add_rewrite_tag( '%bn_auth_action%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_notif_section%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_settings_section%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_post_id%', '([0-9]+)' );
		add_rewrite_tag( '%bn_feed_section%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_legacy_search%', '([01])' );

		$this->register_activity_rules();
		$this->register_post_rules();
		$this->register_bookmarks_rules();
		$this->register_people_rules();
		$this->register_spaces_rules();
		$this->register_messages_rules();
		$this->register_notifications_rules();
		$this->register_settings_rules();
		$this->register_auth_rules();
		$this->register_moderation_rules();
		$this->register_onboarding_rules();

		// Addon hubs (registered via buddynext_register_hubs) declare their own
		// rewrite rules through the registry.
		foreach ( HubRegistry::instance()->all() as $bn_hub ) {
			if ( is_callable( $bn_hub->register_rules ) ) {
				( $bn_hub->register_rules )();
			}
		}
	}

	/**
	 * Register Activity hub rewrite rules.
	 *
	 * @return void
	 */
	private function register_activity_rules(): void {
		$a = self::hub_slug( 'buddynext_slug_activity', 'activity' );

		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/explore/?$',
			'index.php?bn_hub=feed&bn_activity_action=explore',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/hashtag/([^/]+)/?$',
			'index.php?bn_hub=feed&bn_activity_action=hashtag&bn_hashtag=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/search/?$',
			'index.php?bn_hub=feed&bn_activity_action=search',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/leaderboard/?$',
			'index.php?bn_hub=feed&bn_activity_action=leaderboard',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/?$',
			'index.php?bn_hub=feed',
			'top'
		);

		// Legacy /search/ → canonical /activity/search/. Search lives under the
		// activity hub; this bare top-level rule catches bookmarks and hand-added
		// nav-menu links so they 301 to the real surface instead of 404ing. The
		// redirect (with ?q= preserved) is issued in dispatch_hub_template().
		add_rewrite_rule(
			'^search/?$',
			'index.php?bn_legacy_search=1',
			'top'
		);
	}

	/**
	 * Register single-post permalink rule.
	 *
	 * /p/{id}/ resolves to the post hub, which renders a dedicated single-post
	 * page with breadcrumb, full post card, OG meta tags, and expanded comment
	 * thread. The short /p/ slug intentionally avoids the activity-hub prefix
	 * so the URL stays compact for sharing.
	 *
	 * @return void
	 */
	private function register_post_rules(): void {
		add_rewrite_rule(
			'^p/([0-9]+)/?$',
			'index.php?bn_hub=post&bn_post_id=$matches[1]',
			'top'
		);
	}

	/**
	 * Register the personal /me/ section rewrite rules.
	 *
	 * /me/bookmarks/ resolves to the feed hub's bookmarks section (the viewer's
	 * saved-post list); /me/account-status/ resolves to the account-status
	 * section (the viewer's own moderation standing — suspensions, strikes,
	 * warnings, appeals). Both are login-gated upstream in
	 * dispatch_hub_template() via $guarded_feed_sections.
	 *
	 * @return void
	 */
	private function register_bookmarks_rules(): void {
		add_rewrite_rule(
			'^me/bookmarks/?$',
			'index.php?bn_hub=feed&bn_feed_section=bookmarks',
			'top'
		);
		add_rewrite_rule(
			'^me/account-status/?$',
			'index.php?bn_hub=feed&bn_feed_section=account-status',
			'top'
		);
	}

	/**
	 * Register People hub rewrite rules.
	 *
	 * @return void
	 */
	private function register_people_rules(): void {
		$p = self::hub_slug( 'buddynext_slug_people', 'members' );

		// Generic profile sub-route: ANY tab slug becomes a pretty URL
		// (/members/{slug}/{tab}/). Replaces the per-action rules so core tabs
		// (edit, connections, followers, following, media, badges, replies,
		// likes, about, discussions) AND integration tabs (portfolio, …) all
		// deep-link without a ?tab= query arg. resolve_hub_template() sends
		// 'edit' to the edit template; every other action renders the profile
		// view, which activates the matching tab from bn_profile_action.
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/([^/]+)/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]',
			'top'
		);
		// Member-type directory filter URL: /members/{type-slug}/
		// Registered with 'bottom' priority so the user-slug rules above take precedence.
		// The set_hub_vars() callback only stores bn_member_type when no user was resolved,
		// preventing type slugs from incorrectly matching as user profile URLs.
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([a-z0-9-]+)/?$',
			'index.php?bn_hub=people&bn_member_type=$matches[1]',
			'bottom'
		);
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/?$',
			'index.php?bn_hub=people',
			'top'
		);
	}

	/**
	 * Register Spaces hub rewrite rules.
	 *
	 * @return void
	 */
	/**
	 * Whitelist the spaces-directory scope query vars so the pretty /spaces/mine/
	 * rewrite rules below can pass them through (WP strips unknown vars).
	 *
	 * @param array<int,string> $vars Registered public query vars.
	 * @return array<int,string>
	 */
	public function register_directory_query_vars( array $vars ): array {
		$vars[] = 'bn_scope';
		$vars[] = 'bn_membership';
		foreach ( HubRegistry::instance()->all() as $bn_hub ) {
			foreach ( $bn_hub->query_vars as $bn_qv ) {
				$vars[] = $bn_qv;
			}
		}
		return $vars;
	}

	/**
	 * Register Spaces hub rewrite rules (directory, /spaces/mine/ views, and the
	 * generic /spaces/{slug}/{action}/ space routes).
	 *
	 * @return void
	 */
	private function register_spaces_rules(): void {
		$s = self::hub_slug( 'buddynext_slug_spaces', 'spaces' );

		// Pretty "My Spaces" directory views: /spaces/mine/ (sectioned managed +
		// joined) and /spaces/mine/managed|joined/ (one bucket, paginated). Added
		// BEFORE the generic {slug} rules below — add_rewrite_rule( 'top' ) preserves
		// addition order within the top bucket, so these match first and "mine" is
		// never read as a space slug. Reserves only the word "mine" as a non-slug.
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/mine/(managed|joined)/?$',
			'index.php?bn_hub=spaces&bn_scope=mine&bn_membership=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/mine/?$',
			'index.php?bn_hub=spaces&bn_scope=mine',
			'top'
		);

		// One generic rule for every space sub-route: /spaces/{slug}/{action}/.
		// The dispatcher (get_template_for) routes by action — content tabs
		// (feed/members/about/media/moderation) all render spaces/home.php so the
		// space nav is one consistent clean-URL surface; settings/admin keep their
		// own config screens. Any action slug (incl. integration tabs) is captured.
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/([^/]+)/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=$matches[2]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/?$',
			'index.php?bn_hub=spaces',
			'top'
		);
	}

	/**
	 * Register Messages hub rewrite rules.
	 *
	 * @return void
	 */
	private function register_messages_rules(): void {
		$m = self::hub_slug( 'buddynext_slug_messages', 'messages' );

		add_rewrite_rule(
			'^' . preg_quote( $m, '/' ) . '/requests/?$',
			'index.php?bn_hub=messages&bn_msg_action=requests',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $m, '/' ) . '/([0-9]+)/?$',
			'index.php?bn_hub=messages&bn_conv_id=$matches[1]',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $m, '/' ) . '/?$',
			'index.php?bn_hub=messages',
			'top'
		);
	}

	/**
	 * Register Notifications hub rewrite rules.
	 *
	 * @return void
	 */
	private function register_notifications_rules(): void {
		$n = self::hub_slug( 'buddynext_slug_notifications', 'notifications' );

		// /notifications/preferences/ — same hub, prefs section.
		add_rewrite_rule(
			'^' . preg_quote( $n, '/' ) . '/preferences/?$',
			'index.php?bn_hub=notifications&bn_notif_section=prefs',
			'top'
		);

		// /settings/notifications/ — canonical entry point requested by the
		// production-readiness checklist. The "settings" prefix is reserved for
		// per-user preference surfaces; no other hub uses it yet.
		add_rewrite_rule(
			'^settings/notifications/?$',
			'index.php?bn_hub=notifications&bn_notif_section=prefs',
			'top'
		);

		add_rewrite_rule(
			'^' . preg_quote( $n, '/' ) . '/?$',
			'index.php?bn_hub=notifications',
			'top'
		);
	}

	/**
	 * Register the Settings hub rewrite rules.
	 *
	 * The Settings hub is a tabbed home for per-user account/privacy/appearance
	 * preferences. Notifications keep their own canonical route
	 * (`/settings/notifications/`, registered above) and render as the
	 * Notifications tab. `/settings/` defaults to the Account tab.
	 *
	 * @return void
	 */
	private function register_settings_rules(): void {
		foreach ( array( 'account', 'privacy', 'appearance' ) as $section ) {
			add_rewrite_rule(
				'^settings/' . $section . '/?$',
				'index.php?bn_hub=settings&bn_settings_section=' . $section,
				'top'
			);
		}

		// `/settings/` lands on the Account tab.
		add_rewrite_rule(
			'^settings/?$',
			'index.php?bn_hub=settings&bn_settings_section=account',
			'top'
		);
	}

	/**
	 * Register Moderation hub rewrite rules.
	 *
	 * Single rule — the moderation hub has no sub-endpoints.
	 *
	 * @return void
	 */
	private function register_moderation_rules(): void {
		$m = self::hub_slug( 'buddynext_slug_moderation', 'moderation' );

		add_rewrite_rule(
			'^' . preg_quote( $m, '/' ) . '/?$',
			'index.php?bn_hub=moderation',
			'top'
		);
	}

	/**
	 * Register Auth hub rewrite rules.
	 *
	 * One auth namespace, one slug: login is the hub root and signup + verify
	 * are sub-routes beneath it, so renaming the auth slug moves all three
	 * together and the admin "Login / Register" mapping is truthful. URLs:
	 *   /{auth}/         -> login
	 *   /{auth}/signup/  -> register
	 *   /{auth}/verify/  -> verify email
	 *
	 * @return void
	 */
	private function register_auth_rules(): void {
		$a = self::hub_slug( 'buddynext_slug_auth', 'login' );

		// Sub-routes first (more specific), bare hub last. The `$` anchors mean
		// they never overlap, but ordering keeps intent clear.
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/signup/?$',
			'index.php?bn_hub=auth&bn_auth_action=signup',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/verify/?$',
			'index.php?bn_hub=auth&bn_auth_action=verify',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/reset/?$',
			'index.php?bn_hub=auth&bn_auth_action=reset',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/?$',
			'index.php?bn_hub=auth&bn_auth_action=login',
			'top'
		);
	}

	/**
	 * Register Onboarding hub rewrite rules.
	 *
	 * Single rule — the onboarding hub has no sub-endpoints.
	 *
	 * @return void
	 */
	private function register_onboarding_rules(): void {
		$o = self::hub_slug( 'buddynext_slug_onboarding', 'onboarding' );

		add_rewrite_rule(
			'^' . preg_quote( $o, '/' ) . '/?$',
			'index.php?bn_hub=onboarding',
			'top'
		);
	}

	// ── Query filter ──────────────────────────────────────────────────────────

	/**
	 * Resolve hub query vars on the main query and store resolved IDs.
	 *
	 * Resolves bn_user_slug → bn_resolved_user_id
	 * Resolves bn_space_slug → bn_resolved_space_id
	 *
	 * @param WP_Query $query Current WordPress query.
	 * @return void
	 */
	public function set_hub_vars( WP_Query $query ): void {
		if ( ! $query->is_main_query() ) {
			return;
		}

		$raw_user_slug = (string) $query->get( 'bn_user_slug', '' );
		if ( '' !== $raw_user_slug ) {
			$user    = $this->resolve_user( sanitize_title( $raw_user_slug ) );
			$user_id = $user instanceof WP_User ? $user->ID : 0;
			$query->set( 'bn_resolved_user_id', $user_id );
		}

		$raw_space_slug = (string) $query->get( 'bn_space_slug', '' );
		if ( '' !== $raw_space_slug ) {
			$space_id = $this->resolve_space( sanitize_title( $raw_space_slug ) );
			$query->set( 'bn_resolved_space_id', $space_id );
		}

		// Member-type filter: the 'bottom'-priority rewrite rule populates bn_member_type
		// only when no user slug matched. Sanitize and store it for the directory template.
		$raw_type_slug = (string) $query->get( 'bn_member_type', '' );
		if ( '' !== $raw_type_slug ) {
			$query->set( 'bn_member_type', sanitize_key( $raw_type_slug ) );
		}
	}

	// ── Slug-change flush ─────────────────────────────────────────────────────

	/**
	 * Flush rewrite rules after a hub slug option changes.
	 *
	 * Registered on update_option_buddynext_slug_{hub} for all five hubs.
	 *
	 * @return void
	 */
	public function flush_on_slug_change(): void {
		flush_rewrite_rules();
	}

	/**
	 * Return true when the current request is a BuddyNext hub route.
	 *
	 * Safe to call from any hook after parse_request.
	 *
	 * @return bool
	 */
	public static function is_bn_route(): bool {
		return '' !== (string) get_query_var( 'bn_hub', '' );
	}

	// ── Static URL builders ───────────────────────────────────────────────────

	/**
	 * Build the base URL for a hub using its page option.
	 *
	 * Falls back to home_url('/hub-slug/') when the page option is not set
	 * so that URL builders always return a usable string.
	 *
	 * @param string $slug_option The option name for the hub's slug.
	 * @param string $page_option The option name for the hub's page ID.
	 * @return string Trailing-slashed absolute URL.
	 */
	public static function hub_url( string $slug_option, string $page_option ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $page_option is retained for public-API stability; the slug is now the canonical URL source (see below).
		// Always use the configurable slug — the WP page is a backing object
		// for WP_Query resolution, not the canonical URL source.
		$slug = self::hub_slug( $slug_option, self::default_slug( $slug_option ) );
		return trailingslashit( home_url( '/' . $slug ) );
	}

	/**
	 * Return the Activity hub base URL.
	 *
	 * @return string
	 */
	public static function activity_url(): string {
		return self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' );
	}

	/**
	 * Return the Explore sub-page URL.
	 *
	 * @return string
	 */
	public static function explore_url(): string {
		return self::activity_url() . 'explore/';
	}

	/**
	 * Return the hashtag feed URL for a given hashtag.
	 *
	 * @param string $hashtag Hashtag slug (without the # character).
	 * @return string
	 */
	public static function hashtag_feed_url( string $hashtag ): string {
		return self::activity_url() . 'hashtag/' . rawurlencode( sanitize_title( $hashtag ) ) . '/';
	}

	/**
	 * Return the search results URL, optionally pre-filling the query string.
	 *
	 * @param string $query Search query to append as ?q=...
	 * @return string
	 */
	public static function search_url( string $query = '' ): string {
		$url = self::activity_url() . 'search/';
		if ( '' !== $query ) {
			$url = add_query_arg( 'q', rawurlencode( $query ), $url );
		}
		return $url;
	}

	/**
	 * Return the Leaderboard page URL.
	 *
	 * @return string
	 */
	public static function leaderboard_url(): string {
		return self::activity_url() . 'leaderboard/';
	}

	/**
	 * Return the canonical permalink URL for a single post.
	 *
	 * Compact `/p/{id}/` form — chosen over `/activity/post/{id}/` so the
	 * URL stays share-friendly. Used by post-card timestamps, share modal,
	 * notifications, email links, and OG og:url meta tags.
	 *
	 * @param int $post_id Post primary key.
	 * @return string Absolute trailing-slashed URL, or empty string when post_id <= 0.
	 */
	public static function post_url( int $post_id ): string {
		if ( $post_id <= 0 ) {
			return '';
		}

		return trailingslashit( home_url( '/p/' . $post_id ) );
	}

	/**
	 * Return the Bookmarks hub URL for the active user.
	 *
	 * Path is /me/bookmarks/ — same for every viewer; the bookmarks template
	 * reads the current user's saved-post list directly.
	 *
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function bookmarks_url(): string {
		return trailingslashit( home_url( '/me/bookmarks' ) );
	}

	/**
	 * Return the Account Status URL for the active user.
	 *
	 * Path is /me/account-status/ — same for every viewer; the template reads
	 * the current user's own moderation standing (active suspension, strikes,
	 * warnings, appeals). This is the destination for moderation notifications
	 * about the recipient's own account, so a suspended/warned member lands on a
	 * page that explains the action instead of their profile's Posts tab.
	 *
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function account_status_url(): string {
		return trailingslashit( home_url( '/me/account-status' ) );
	}

	/**
	 * Return the People (member directory) hub base URL.
	 *
	 * @return string
	 */
	public static function people_url(): string {
		return self::hub_url( 'buddynext_slug_people', 'buddynext_page_people' );
	}

	/**
	 * Return the canonical profile URL for a user.
	 *
	 * Slug priority:
	 *   1. bn_profile_slug usermeta (custom slug chosen by the member)
	 *   2. user_nicename (URL-safe, human-readable)
	 *   3. user-{id} (safe fallback — never exposes WP credentials)
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Absolute URL, or empty string when user_id is invalid.
	 */
	public static function profile_url( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$base = self::people_url();

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom_slug ) {
			return $base . rawurlencode( $custom_slug ) . '/';
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && '' !== $user->user_nicename ) {
			return $base . rawurlencode( $user->user_nicename ) . '/';
		}

		return $base . 'user-' . $user_id . '/';
	}

	/**
	 * Return the Edit Profile URL for a user.
	 *
	 * When $user_id is 0, uses the currently logged-in user.
	 * Falls back to the WP admin profile page when the page option is not set.
	 *
	 * @param int $user_id WordPress user ID (0 = current user).
	 * @return string Absolute URL.
	 */
	public static function edit_profile_url( int $user_id = 0 ): string {
		if ( 0 === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( $user_id <= 0 ) {
			return get_edit_profile_url( 0 );
		}

		$base = self::people_url();

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom_slug ) {
			return $base . rawurlencode( $custom_slug ) . '/edit/';
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && '' !== $user->user_nicename ) {
			return $base . rawurlencode( $user->user_nicename ) . '/edit/';
		}

		return $base . 'user-' . $user_id . '/edit/';
	}

	/**
	 * Return the Connections page URL for a user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Absolute URL.
	 */
	public static function connections_url( int $user_id ): string {
		return self::profile_subroute_url( $user_id, 'connections' );
	}

	/**
	 * Return the canonical URL for a user's followers list.
	 *
	 * @param int $user_id User whose followers list is being linked.
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function followers_url( int $user_id ): string {
		return self::profile_subroute_url( $user_id, 'followers' );
	}

	/**
	 * Return the canonical URL for a user's following list.
	 *
	 * @param int $user_id User whose following list is being linked.
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function following_url( int $user_id ): string {
		return self::profile_subroute_url( $user_id, 'following' );
	}

	/**
	 * Build a profile sub-route URL (e.g. /members/{slug}/{section}/) for any user.
	 *
	 * Falls back through bn_profile_slug → user_nicename → user-{ID} so the URL
	 * always resolves, even when a member has no friendly nicename.
	 *
	 * @param int    $user_id User ID.
	 * @param string $section Sub-route segment (e.g. 'connections', 'followers').
	 * @return string Absolute trailing-slashed URL.
	 */
	private static function profile_subroute_url( int $user_id, string $section ): string {
		if ( $user_id <= 0 ) {
			return self::people_url();
		}

		$base    = self::people_url();
		$section = trim( $section, '/' );

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom_slug ) {
			return $base . rawurlencode( $custom_slug ) . '/' . $section . '/';
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && '' !== $user->user_nicename ) {
			return $base . rawurlencode( $user->user_nicename ) . '/' . $section . '/';
		}

		return $base . 'user-' . $user_id . '/' . $section . '/';
	}

	/**
	 * Return the member directory URL filtered to a specific member type.
	 *
	 * The URL uses the 'bottom'-priority rewrite rule registered in
	 * register_people_rules() so that the user-slug rules always take
	 * precedence when a URL segment also happens to be a valid user slug.
	 *
	 * @param string $type_slug Member type slug (lowercase alphanumeric + hyphens).
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function member_type_url( string $type_slug ): string {
		if ( '' === $type_slug ) {
			return self::people_url();
		}

		return self::people_url() . rawurlencode( sanitize_key( $type_slug ) ) . '/';
	}

	/**
	 * Return the Spaces hub base URL.
	 *
	 * @return string
	 */
	public static function spaces_url(): string {
		return self::hub_url( 'buddynext_slug_spaces', 'buddynext_page_spaces' );
	}

	/**
	 * Return the canonical URL for a single space.
	 *
	 * Queries the bn_spaces table for the space's slug by ID.
	 *
	 * @param int $space_id Space primary key.
	 * @return string Absolute URL, or spaces hub URL when space not found.
	 */
	public static function space_url( int $space_id ): string {
		if ( $space_id <= 0 ) {
			return self::spaces_url();
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$slug = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $slug || '' === (string) $slug ) {
			return self::spaces_url();
		}

		return self::spaces_url() . rawurlencode( (string) $slug ) . '/';
	}

	/**
	 * Return the Messages hub base URL.
	 *
	 * @return string
	 */
	public static function messages_url(): string {
		return self::hub_url( 'buddynext_slug_messages', 'buddynext_page_messages' );
	}

	/**
	 * Return the URL for a specific conversation thread.
	 *
	 * @param int $conv_id Conversation ID.
	 * @return string Absolute URL.
	 */
	public static function conversation_url( int $conv_id ): string {
		if ( $conv_id <= 0 ) {
			return self::messages_url();
		}

		return self::messages_url() . $conv_id . '/';
	}

	/**
	 * Return the Notifications hub base URL.
	 *
	 * @return string
	 */
	public static function notifications_url(): string {
		return self::hub_url( 'buddynext_slug_notifications', 'buddynext_page_notifications' );
	}

	/**
	 * Return the Notification preferences URL.
	 *
	 * Uses the canonical `/settings/notifications/` entry point, which is
	 * served by the same template + Interactivity store as
	 * `/notifications/preferences/`.
	 *
	 * @return string
	 */
	public static function notification_prefs_url(): string {
		return trailingslashit( home_url( '/settings/notifications' ) );
	}

	/**
	 * Return a Settings hub URL.
	 *
	 * `/settings/` (default → Account), or `/settings/{section}/` for a specific
	 * tab. Notifications resolve to the canonical notification_prefs_url().
	 *
	 * @param string $section '', 'account', 'notifications', 'privacy', 'appearance'.
	 * @return string Absolute trailing-slashed URL.
	 */
	public static function settings_url( string $section = '' ): string {
		$section = sanitize_key( $section );
		if ( 'notifications' === $section ) {
			return self::notification_prefs_url();
		}
		if ( '' === $section || 'account' === $section ) {
			return trailingslashit( home_url( '/settings' ) );
		}
		return trailingslashit( home_url( '/settings/' . $section ) );
	}

	/**
	 * Return the Auth (login/register) hub base URL.
	 *
	 * @return string
	 */
	public static function auth_url(): string {
		return self::hub_url( 'buddynext_slug_auth', 'buddynext_page_auth' );
	}

	/**
	 * Return the registration (signup) URL — a sub-route of the auth hub.
	 *
	 * @return string
	 */
	public static function signup_url(): string {
		/**
		 * Filter the registration URL.
		 *
		 * @param string $url Default {auth}/signup/ URL.
		 */
		return (string) apply_filters( 'buddynext_signup_url', trailingslashit( self::auth_url() ) . 'signup/' );
	}

	/**
	 * Return the email-verification screen URL — a sub-route of the auth hub.
	 *
	 * This is the status screen the verification + email-change flows redirect
	 * to. The tokenized link inside the verification email is separate (it rides
	 * a query var on home_url so it works regardless of this slug).
	 *
	 * @return string
	 */
	public static function verify_url(): string {
		/**
		 * Filter the email-verification screen URL.
		 *
		 * @param string $url Default {auth}/verify/ URL.
		 */
		return (string) apply_filters( 'buddynext_verify_url', trailingslashit( self::auth_url() ) . 'verify/' );
	}

	/**
	 * Return the password-reset URL — a sub-route of the auth hub.
	 *
	 * Serves both steps: the request form (no query args) and the
	 * set-new-password form (reached with ?key=...&login=... from the email).
	 *
	 * @return string
	 */
	public static function reset_url(): string {
		/**
		 * Filter the password-reset URL.
		 *
		 * @param string $url Default {auth}/reset/ URL.
		 */
		return (string) apply_filters( 'buddynext_reset_url', trailingslashit( self::auth_url() ) . 'reset/' );
	}

	/**
	 * Return the Onboarding hub base URL.
	 *
	 * @return string
	 */
	public static function onboarding_url(): string {
		return self::hub_url( 'buddynext_slug_onboarding', 'buddynext_page_onboarding' );
	}

	/**
	 * Return the Community Admin Panel URL.
	 *
	 * @return string
	 */
	public static function community_admin_url(): string {
		return self::hub_url( 'buddynext_slug_community_admin', 'buddynext_page_community_admin' );
	}

	/**
	 * Check whether a profile slug is available for a given user to claim.
	 *
	 * A slug is unavailable when:
	 *   - Another user already holds it as bn_profile_slug usermeta.
	 *   - It matches the reserved "user-{numeric_id}" pattern for any user
	 *     other than the requesting user.
	 *
	 * @param string $slug    Proposed slug (sanitized with sanitize_title internally).
	 * @param int    $user_id User requesting the slug (excluded from conflict checks).
	 * @return bool True when the slug is available.
	 */
	public static function is_slug_available( string $slug, int $user_id ): bool {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		// Block the reserved "user-{id}" pattern for any other user's ID.
		if ( preg_match( '/^user-(\d+)$/', $slug, $m ) && (int) $m[1] !== $user_id ) {
			return false;
		}

		// Check bn_profile_slug usermeta (indexed; slow-query warning is a false positive).
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$taken_by_meta = get_users(
			array(
				'meta_key'   => 'bn_profile_slug',
				'meta_value' => $slug,
				'exclude'    => array( $user_id ),
				'number'     => 1,
				'fields'     => 'ID',
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		return empty( $taken_by_meta );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Resolve a URL slug to a WordPress user.
	 *
	 * Check order:
	 *   1. bn_profile_slug usermeta (custom slug set by the member).
	 *   2. Reserved "user-{id}" pattern (system default).
	 *   3. user_nicename fallback.
	 *
	 * @param string $slug URL-decoded, sanitized slug.
	 * @return WP_User|null
	 */
	private function resolve_user( string $slug ): ?WP_User {
		// 1. Custom slug set by the member (meta lookup is intentional — indexed column).
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$by_meta = get_users(
			array(
				'meta_key'   => 'bn_profile_slug',
				'meta_value' => $slug,
				'number'     => 1,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		if ( ! empty( $by_meta ) ) {
			return $by_meta[0] instanceof WP_User ? $by_meta[0] : null;
		}

		// 2. Reserved "user-{id}" pattern.
		if ( preg_match( '/^user-(\d+)$/', $slug, $m ) ) {
			$by_id = get_user_by( 'ID', (int) $m[1] );
			return $by_id instanceof WP_User ? $by_id : null;
		}

		// 3. user_nicename fallback.
		$by_nicename = get_user_by( 'slug', $slug );
		return $by_nicename instanceof WP_User ? $by_nicename : null;
	}

	/**
	 * Resolve a space URL slug to its primary-key ID.
	 *
	 * @param string $slug URL-decoded, sanitized slug.
	 * @return int Space ID, or 0 when not found.
	 */
	private function resolve_space( string $slug ): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_spaces WHERE slug = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$slug
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $space_id;
	}

	/**
	 * Read a hub's slug from options, falling back to a sensible default.
	 *
	 * @param string $option_name The option key, e.g. 'buddynext_slug_activity'.
	 * @param string $fallback    Default slug value when option is empty.
	 * @return string
	 */
	private static function hub_slug( string $option_name, string $fallback ): string {
		$slug = (string) get_option( $option_name, $fallback );
		$slug = trim( $slug );
		return '' !== $slug ? $slug : $fallback;
	}

	/**
	 * Return the built-in default slug for a hub slug option.
	 *
	 * @param string $option_name The option key.
	 * @return string
	 */
	private static function default_slug( string $option_name ): string {
		// Hub defaults come from the registry; the non-hub community-admin slug
		// and the ultimate fallback stay here.
		$map = array( 'buddynext_slug_community_admin' => 'bn-community-admin' );
		foreach ( HubRegistry::instance()->all() as $hub ) {
			$map[ $hub->slug_option ] = $hub->default_slug;
		}
		return $map[ $option_name ] ?? 'community';
	}
}
