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

	/**
	 * The hub render deferred to core's template stage: [ hub, template, context ].
	 *
	 * Populated by dispatch_hub_template() once every gate has passed. Consumed by
	 * the loader template returned from the `template_include` filter.
	 *
	 * @var array{0:string,1:string,2:array<string,mixed>}|null
	 */
	private ?array $pending_render = null;

	/**
	 * The instance holding a pending render, for the loader template to reach.
	 *
	 * The loader is a plain PHP file `include`d by core, so it has no reference to
	 * the router. It cannot construct one either: the pending render lives on the
	 * instance whose gates just ran.
	 *
	 * @var self|null
	 */
	private static ?self $rendering = null;

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
		// Deferred to init:1 — the registry is populated on init:0 (see
		// Plugin::init(); moved off plugins_loaded so the descriptors' translated
		// titles don't trip WP 6.7's _load_textdomain_just_in_time notice). The
		// update_option_* hooks only ever fire on admin saves, long after init.
		add_action(
			'init',
			function (): void {
				foreach ( HubRegistry::instance()->all() as $bn_hub ) {
					add_action( 'update_option_' . $bn_hub->slug_option, array( $this, 'flush_on_slug_change' ) );
				}
			},
			1
		);

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
	private const ROUTER_VERSION = '2026-08-26-hub-registry-seam';

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

		// buddynext_page_* option → hub key, built from the registry so every
		// page-backed hub participates (the old hardcoded map omitted auth, so a
		// site with the Auth page set as the WP front page never resolved "/" to
		// login, and addon hubs were invisible here too).
		$map = array();
		foreach ( HubRegistry::instance()->all() as $bn_descriptor ) {
			if ( $bn_descriptor->backing_page ) {
				$map[ $bn_descriptor->page_option ] = $bn_descriptor->key;
			}
		}
		// Explore is a sub-route of the Activity hub with its own page option but no
		// registry descriptor of its own.
		$map['buddynext_page_explore'] = 'feed';

		foreach ( $map as $option => $slug ) {
			if ( (int) get_option( $option ) === $front_id ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Resolve the BuddyNext hub for this request and queue it for rendering.
	 *
	 * Hooked on template_redirect — the stage for gates, redirects and head-meta
	 * that must precede wp_head(). It runs the legacy /search/ → /activity/search/
	 * redirect, the private-community and per-feature route guards, resolves the
	 * hub template + context, then DEFERS the render: it stores the pending hub
	 * and points core's `template_include` at templates/hub-loader.php, which
	 * calls render_pending(). The render therefore happens inside core's normal
	 * template stage — so template_include, wp_before_include_template and the
	 * output-buffer filters all fire — with the active theme's header and footer.
	 * This method never emits the document itself. See GH #137.
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

		// Private-community lockdown: when enabled, every hub requires login except
		// the auth hub (login / register / reset / verify). A logged-out visitor on
		// any other hub — feed, members, a single profile, spaces, notifications,
		// settings, search … — is sent to the auth page with a redirect_to back to
		// the page they wanted. This closes the routing gap where BuddyNext's own
		// pages bypassed membership plugins: they are simply unreachable when logged
		// out. The matching REST data gate lives in PrivateCommunity::gate_rest().
		if ( 'auth' !== $hub
			&& PrivateCommunity::is_enabled()
			&& ! PrivateCommunity::can_access()
		) {
			global $wp;
			$return = home_url( user_trailingslashit( (string) ( $wp->request ?? '' ) ) );
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $return ), self::auth_url() ) );
			exit;
		}

		// Feature guard: a whole hub whose FeatureRegistry feature the admin has
		// disabled must not render — send visitors to the activity hub instead of
		// showing a hub the site turned off. The gated hubs declare their feature
		// on the descriptor (spaces => 'spaces', onboarding => 'onboarding', both
		// default-on), so this one guard replaces the former per-hub copies and
		// any add-on hub that sets `feature` is covered automatically. Sub-route
		// gates (hashtag, bookmarks, messages availability) stay specific below.
		$bn_hub_desc = HubRegistry::instance()->get( $hub );
		if ( $bn_hub_desc
			&& null !== $bn_hub_desc->feature
			&& function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( $bn_hub_desc->feature )
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

		/*
		 * Direct messaging unavailable: EXPLAIN, do not bounce.
		 *
		 * This used to redirect to the activity hub whenever DMs were off or the
		 * WPMediaVerse engine was absent. The visitor clicked Messages and landed
		 * on the feed with nothing said — and templates/messages/native.php has
		 * carried a purpose-built notice for exactly this state all along, with
		 * separate copy for administrators ("Direct messaging requires
		 * WPMediaVerse") and for members ("Messaging isn't available right now").
		 * The redirect fired first, so ALL of it was unreachable: the card
		 * reported the admin half, but neither branch could ever render.
		 *
		 * A silent redirect is the worst of the options. It looks like a broken
		 * link to the member and hides the cause from the one person who can fix
		 * it. The template already knows what to say to each of them.
		 *
		 * The nav is unaffected — the entry points already hide themselves through
		 * the same entry_enabled() gate, so nothing links here while it is off.
		 * This is only about what happens when someone arrives anyway: a bookmark,
		 * a shared link, or the page-list fallback on a site with no menu.
		 */

		// Bookmarks guard: the personal Bookmarks list (feed hub, bn_feed_section
		// 'bookmarks') is the toggleable Bookmarks feature (FeatureRegistry
		// 'bookmarks', default-on). When the owner turns it off, the list must not
		// render — send visitors to the activity hub, mirroring the guards above.
		if ( 'feed' === $hub
			&& 'bookmarks' === (string) get_query_var( 'bn_feed_section', '' )
			&& function_exists( 'buddynext_service' )
			&& ! buddynext_service( 'features' )->is_enabled( 'bookmarks' )
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
		// unverified user must still see the "check your inbox" state, and
		// connect-app because its approve screen exists FOR the signed-in
		// member — bouncing them to the feed would make the app bridge
		// unreachable for exactly the people who can complete it.
		if ( 'auth' === $hub && is_user_logged_in() ) {
			$auth_action = (string) get_query_var( 'bn_auth_action', '' );
			if ( ! in_array( $auth_action, array( 'verify', 'connect-app' ), true ) ) {
				wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
				exit;
			}
		}

		// Connect-app for a LOGGED-OUT visitor: send them through this site's
		// own sign-in (password, social, 2FA — whatever the site runs) with the
		// full bridge URL as the destination, so approving lands them straight
		// back here. When the app asked for a specific ready provider, skip the
		// chooser and start that provider's OAuth flow directly.
		if ( 'auth' === $hub
			&& 'connect-app' === (string) get_query_var( 'bn_auth_action', '' )
			&& ! is_user_logged_in()
		) {
			nocache_headers();

			// Read-only routing params; the one-time bridge token gates the
			// actual mint, not this hop.
			$bridge_query = array();
			foreach ( array( 'app_name', 'app_id', 'scheme', 'state', 'provider' ) as $bn_param ) {
				if ( isset( $_GET[ $bn_param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$bridge_query[ $bn_param ] = sanitize_text_field( wp_unslash( (string) $_GET[ $bn_param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				}
			}
			$bn_provider = (string) ( $bridge_query['provider'] ?? '' );
			unset( $bridge_query['provider'] );

			$bridge_url = add_query_arg( array_map( 'rawurlencode', $bridge_query ), \BuddyNext\App\AppConnectService::connect_url() );

			$ready_ids = array_column( \BuddyNext\Auth\SocialLogin::ready_providers(), 'id' );
			if ( '' !== $bn_provider && in_array( $bn_provider, $ready_ids, true ) ) {
				wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $bridge_url ), home_url( '/oauth/' . rawurlencode( $bn_provider ) . '/' ) ) ); // bn-route-ok: plugin-registered fixed /oauth/ rewrite.
				exit;
			}

			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( $bridge_url ), self::auth_url() ) );
			exit;
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

			// A feed-hub sub-route that NAMES an activity action — hashtag, search,
			// leaderboard, explore — is its own public surface, not the member's
			// personalised home feed. Only the bare feed is guarded below.
			//
			// Both guards used to key on $feed_section being empty, which every one
			// of those sub-routes also satisfies (they set bn_activity_action, never
			// bn_feed_section). So a guest was swept off ALL of them: hashtag and
			// search and leaderboard 302'd to Explore when public-explore was on, and
			// to /login/ when it was off — unreachable either way. Hashtag pages are
			// meant to be a public share surface, every #tag in every post links to
			// one for guests too, and /search/'s own REST routes are registered with
			// permission_callback => __return_true.
			//
			// Testing the ACTION rather than adding a per-route carve-out is the
			// point: $is_explore was a one-item allowlist, so each new sub-route
			// inherited the bug by default. Bookmarks / saved / account-status set a
			// feed_section and no action, so they stay guarded exactly as before.
			$is_home_feed = ( 'feed' === $hub && '' === $activity_action );

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
			if ( $is_home_feed
				&& in_array( $feed_section, array( '', 'home' ), true )
				&& ! $override_login
				&& (bool) get_option( 'buddynext_public_explore', true )
			) {
				if ( is_front_page() ) {
					// The feed hub IS the site's front page (Settings > Reading).
					// Redirecting a guest off "/" would make the canonical home
					// URL effectively /activity/explore/ — every logged-out
					// visitor and crawler 302'd off the root, with the SEO and
					// social-preview consequences the owner never opted into,
					// and an owner testing while logged in would never see it.
					// Render the explore view IN PLACE instead: the guest still
					// gets public content (the point of the setting) and the
					// homepage stays the homepage. /activity/ itself keeps the
					// redirect below, exactly as before.
					set_query_var( 'bn_activity_action', 'explore' );
					$is_home_feed = false; // Now the public explore surface, not the guarded home feed.
				} else {
					wp_safe_redirect( self::explore_url() );
					exit;
				}
			}

			$needs_login =
				$override_login
				|| in_array( $hub, array( 'messages', 'notifications', 'onboarding', 'settings' ), true )
				|| ( $is_home_feed && in_array( $feed_section, $guarded_feed_sections, true ) );

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

		// ── No-cache: token-bearing pages ─────────────────────────────────
		// The auth hub (login / signup / reset / verify) is server-rendered for
		// anonymous visitors and embeds per-request security tokens: the wp_rest
		// nonce and, on signup, the RegistrationGuard time-trap token plus the
		// human-check challenge token (both minted at render time). A full-page
		// cache (WP Rocket, Varnish, Cloudflare) would serve every anonymous
		// visitor the same copy with a FROZEN token timestamp; once that copy is
		// older than the token TTL, every sign-up posted from it is scored as
		// automated and rejected — a site-wide registration outage. Mark the whole
		// hub uncacheable before any output: the general rule for any
		// nonce/token-bearing form page, not just signup.
		if ( 'auth' === $hub ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // Honoured by WP Rocket, W3TC, WP Super Cache, LiteSpeed, Batcache.
			}
			nocache_headers(); // Cache-Control: no-store … — reverse proxies (Varnish, Cloudflare) and browsers.
		}

		// ── Space visibility gate ─────────────────────────────────────────
		// A hidden (secret) space must answer with a REAL 404 status header, not a
		// 200 carrying a not-found body. The gate used to live inside
		// templates/spaces/home.php, which runs AFTER get_header() has flushed the
		// document — so its status_header( 404 ) came too late and every secret
		// space URL replied "200 OK". Enforcing it here, before any output, is the
		// only place the header can still be set. The decision itself comes from
		// the canonical resolver, so this route and the REST contract agree.
		if ( 'spaces' === $hub && ! empty( $context['space_id'] ) ) {
			$bn_gate_space = ( new \BuddyNext\Spaces\SpaceService() )->get( (int) $context['space_id'] );
			if ( ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $bn_gate_space, get_current_user_id() ) ) {
				$this->send_404();
				return;
			}
		}

		// ── Space existence gate ──────────────────────────────────────────
		// A slug that resolves to NO space must also answer here, for the same
		// before-any-output reason as the two gates around it. Without this the
		// request fell through to the template, whose wp_die( 'Space not
		// found.' ) guard replies with wp_die's default status — HTTP 500 — so
		// every stale or mistyped space URL read as a server failure to
		// monitoring, crawlers, and anyone reading the status line.
		// space_id is 0 only when a slug was supplied and matched nothing; the
		// bare /spaces/ directory sets no slug and never reaches this branch.
		if ( 'spaces' === $hub
			&& '' !== (string) ( $context['space_slug'] ?? '' )
			&& empty( $context['space_id'] ) ) {
			$this->send_404();
			return;
		}

		// ── Member existence gate ─────────────────────────────────────────
		// /members/{slug}/ for a slug that resolves to nobody answered "200 OK"
		// with an empty shell — the theme chrome and the nav, and no content at
		// all. A blank 200 is the worst of both outcomes: the visitor gets no
		// explanation, and search engines index the empty page as real.
		//
		// It has to be decided HERE for the same reason the space gate above does:
		// the virtual-page setup immediately below sets is_404 = false, so any
		// later attempt to 404 produces a soft "200 with a not-found body".
		//
		// user_id is 0 only when a slug was supplied and resolve_user() matched
		// nothing — the bare /members/ directory sets no slug and never reaches
		// this branch.
		if ( 'people' === $hub
			&& '' !== (string) get_query_var( 'bn_user_slug', '' )
			&& empty( $context['user_id'] ) ) {
			$this->send_404();
			return;
		}

		// ── Community Admin gate ──────────────────────────────────────────
		// Moderators (community role) and administrators only. This is the same
		// check the panel template uses at templates/community-admin.php:23, so
		// route, nav visibility and template gate never drift: buddynext_can()
		// already treats manage_options as an allow (PermissionService::can()),
		// and also honours per-user ability grants and the buddynext_user_can
		// filter — things a bare RoleService::is_moderator() call would miss.
		// A logged-out user resolves to false and gets a clean 404, not a fatal.
		if ( 'community_admin' === $hub
			&& ! buddynext_can( get_current_user_id(), 'buddynext-spaces/moderate' ) ) {
			$this->send_404();
			return;
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

		/*
		 * A hub is a page, so it is NOT the blog home — unless the owner really
		 * did set it as the static front page.
		 *
		 * These two flags used to be left set. On a default install
		 * (show_on_front = posts) the main query for a URL that matches no post
		 * falls through to the blog index, so is_home stayed true, and
		 * is_front_page() is true whenever is_home() is on a posts-front-page
		 * site. The result was a query that claimed to be BOTH a singular page
		 * and the blog home, and every consumer that asks WordPress "what page
		 * is this?" believed the second answer: SEO plugins computed HOMEPAGE
		 * title, canonical and og:url for every community URL (two conflicting
		 * canonicals on a single post, the wrong one first), and core built a
		 * front-page-shaped document title with no site name in it.
		 *
		 * hub_for_front_page() already knows when a hub genuinely is the front
		 * page; that case keeps both flags so it still resolves correctly. It
		 * must be read BEFORE the flags are cleared — it calls is_front_page()
		 * itself, so clearing first would always answer ''.
		 */
		$front_hub = $this->hub_for_front_page();
		if ( '' === $front_hub || $front_hub !== $hub ) {
			$wp_query->is_home       = false;
			$wp_query->is_front_page = false;
		}

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

		/*
		 * Point the query at the hub's MAPPED page when the owner has one.
		 *
		 * With queried_object_id left at 0 an SEO plugin has no page to read, so
		 * a title the owner typed into Yoast on the mapped Members page could
		 * never be found — BuddyNext stepping back from the title (above) would
		 * then leave them with the bare site name, which is worse than the
		 * problem it fixed. Naming the real page lets the plugin resolve that
		 * page's own settings, which is exactly what the customer asked for
		 * (Zoho #41057, Basecamp 10173643793).
		 *
		 * The virtual post still backs the render — themes read $GLOBALS['post']
		 * for body classes and template tags — so only the IDENTITY changes, and
		 * only when a mapped page actually exists.
		 */
		$bn_hub_page_id = self::hub_page_id( $hub );
		if ( $bn_hub_page_id > 0 ) {
			$virtual_post->ID = $bn_hub_page_id;
		}

		$GLOBALS['post']             = $virtual_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional virtual post for synthetic hub page rendering.
		$wp_query->post              = $virtual_post;
		$wp_query->posts             = array( $virtual_post );
		$wp_query->queried_object    = $bn_hub_page_id > 0 ? get_post( $bn_hub_page_id ) : $virtual_post;
		$wp_query->queried_object_id = $bn_hub_page_id;
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
			} elseif ( 'connect-app' === $auth_action ) {
				$hub_title = __( 'Connect the app', 'buddynext' );
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
			// <title> for a viewer who cannot see it (existence + name disclosure).
			// The route gate above already 404s such a request; this keeps the title
			// honest for any other path into this branch, and reads its answer from
			// the same canonical resolver rather than re-deriving the rule.
			if ( ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $space_record, get_current_user_id() ) ) {
				$space_record = null;
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

		// Viewing your notifications list clears the badge — the count is expected
		// to reset when you look at them (every mainstream app does this). Done
		// before the title + rail render (both read the badge count) so the tab
		// title, rail badge, and mobile-bar badge all show 0 on this same page
		// load. This marks the list SEEN (advances a last-seen timestamp and busts
		// the badge cache) — it does NOT mark rows read: the items stay unread/bold
		// and the Unread tab stays populated until an explicit click or "Mark all
		// read", exactly like GitHub / Slack / X.
		//
		// The preferences form no longer needs excluding by hand: it is a Settings
		// tab (bn_hub=settings), so this block cannot fire for it. That is load
		// bearing, not incidental — marking a member's notifications seen because
		// they opened a settings page would be worse than the layout bug this move
		// fixes. Covered by a test.
		if ( 'notifications' === $hub
			&& is_user_logged_in()
			&& function_exists( 'buddynext_service' ) ) {
			buddynext_service( 'notifications' )->mark_seen( get_current_user_id() );
		}

		// The Notifications TAB in Settings keeps its own title; it is a preferences
		// form, not "Settings". Set here beside the other hub titles rather than in
		// the template, which is where every other hub resolves its title.
		if ( 'settings' === $hub
			&& 'notifications' === (string) get_query_var( 'bn_settings_section', '' ) ) {
			$hub_title = __( 'Notification preferences', 'buddynext' );
		}

		// Specialise the Notifications hub title:
		// - List with unread > 0 → "Notifications (3)" / "Notifications (99+)".
		// Mirrors the Profile / Spaces patterns above so the document <title>
		// reflects the active sub-route and the live unread count. The unread
		// count read is cheap (single COUNT on an indexed column) and only
		// fires when the hub matches.
		if ( 'notifications' === $hub ) {
			if ( is_user_logged_in() ) {
				$notif_user_id = get_current_user_id();
				// Badge-family count (unseen), consistent with the bell/rail badges —
				// this is 0 right after the list is marked seen above, not the raw
				// unread total (which now persists in the Unread tab).
				$unread_for_title = ( new \BuddyNext\Notifications\NotificationService() )->unseen_count( $notif_user_id );
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
					? sprintf( /* translators: %s: member display name. */ __( 'Edit Profile · %s', 'buddynext' ), $profile_name )
					: __( 'Edit Profile', 'buddynext' );
			} elseif ( 'profile/view.php' === $template ) {
				$hub_title = '' !== $profile_name
					? sprintf( /* translators: %s: member display name. */ __( '%s · Profile', 'buddynext' ), $profile_name )
					: __( 'Profile', 'buddynext' );
			}
		}

		// Site-wide search-engine indexing policy (Settings → Privacy → "Allow
		// search engines to index"). Only ever ADDS noindex so it composes with
		// the per-profile/space privacy opt-outs below; it never forces a page to
		// be indexable. 'all' = public hubs indexable, 'public_posts' = only the
		// feed/posts, 'none' = noindex every BuddyNext page. Private hubs
		// (messages/notifications/auth/onboarding) are never indexable.
		$indexing = (string) get_option( 'buddynext_google_indexing', 'public_posts' );
		// 'post' is the hub key for the /p/{id}/ PERMALINK — the canonical,
		// shareable URL for a single post, and the page BuddyNext emits a full
		// Open Graph card for. It was in neither list below, so under every
		// value of this setting (including 'all', whose own label is "public
		// hubs indexable") a public post permalink shipped noindex,nofollow:
		// there was no configuration in which it could be indexed. The setting
		// that reads "only the feed/posts" meant the feed HUB, never the posts.
		$is_posts      = ( 'feed' === $hub || 'activity' === $hub || 'post' === $hub );
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

		/*
		 * Set the hub title ONLY when no SEO plugin owns the head.
		 *
		 * An owner who installs Yoast and types a title into it has made an
		 * explicit choice about their own site; overwriting it unconditionally
		 * silently discarded that choice (Zoho #41057, Basecamp 10173643793).
		 * The meta-description path in this same file has always deferred
		 * correctly — this is the same rule applied to the title, so BuddyNext
		 * keeps its title on the default install (no SEO plugin, and the right
		 * default) and stops fighting the owner on sites that have one.
		 */
		$title_frozen = (string) apply_filters( 'buddynext_document_title', $hub_title, $hub );
		if ( '' !== $title_frozen && ! self::seo_plugin_active() ) {
			self::$title_claimed = true;
			add_filter(
				'document_title_parts',
				static function ( array $parts ) use ( $title_frozen ): array {
					$parts['title'] = $title_frozen;
					return $parts;
				}
			);
		}

		self::apply_community_name_to_title();

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

		// Social + canonical head for every OTHER shareable surface — spaces,
		// profiles, directories. Until 1.1.3 the single-post permalink above was
		// the only surface that emitted anything, so a shared space or profile
		// rendered as a bare imageless link everywhere (Basecamp 10181599620).
		// Runs before wp_head for the same reason the post meta above does.
		SurfaceMeta::register( $hub, $context );

		// Community description (Settings → General) as the page meta description
		// on every BN hub — the help text promises it appears "in meta tags".
		// SurfaceMeta already emits a description for the hubs it describes; this
		// stays for any surface it does not, and both defer to an SEO plugin.
		$this->maybe_register_community_meta_description();

		do_action( 'buddynext_before_hub', $hub, $template );

		// BuddyNext sits *inside* the active theme's chrome. The theme owns
		// the document — DOCTYPE / <html> / <head> / wp_head() / <body> /
		// wp_body_open() / wp_footer() / </html> all come from the theme via
		// get_header() and get_footer(). BuddyNext only renders the .bn-app
		// canvas in between. The canvas bursts to 100vw in CSS so it stays
		// edge-to-edge regardless of whatever container the theme wraps
		// content in. There is no opt-out filter; the host theme's header +
		// footer always render on BN-mapped slugs.
		// Hand the RENDER to core's template stage; do not render here and exit.
		//
		// Everything above this line still runs at template_redirect, which is
		// where it belongs: the gates, the redirects, and the head-meta
		// registration that has to precede wp_head().
		//
		// The render itself used to happen here, followed by exit. That ended the
		// request in the middle of core's template pipeline, so three things that
		// come AFTER template_redirect never ran on any BuddyNext page:
		//
		// apply_filters( 'template_include' ) - template-loader.php:114;
		// do_action( 'wp_before_include_template' ) - template-loader.php:130;
		// and any template_redirect callback at priority > 10.
		//
		// The second of those is what starts core's template-enhancement output
		// buffer, so `wp_template_enhancement_output_buffer` filters - image
		// optimisation, lazy-loading, any HTML post-processing - silently did
		// nothing on /activity/, /members/, /spaces/ and the rest. Verified with a
		// probe before and after: on a BuddyNext page only template_redirect fired;
		// on an ordinary page all five did.
		//
		// Core's own docblock above the template_redirect hook warns against this
		// exact pattern (template-loader.php:16-17) and names template_include as
		// the correct alternative. See github.com/buddynext/buddynext/issues/137.
		$this->pending_render = array( $hub, $template, $context );
		self::$rendering      = $this;

		add_filter( 'template_include', array( $this, 'use_hub_loader' ) );
	}

	/**
	 * Point core's template loader at the BuddyNext loader file.
	 *
	 * Registered only once a hub render is pending, so a request this router did
	 * not claim keeps whatever template the theme resolved.
	 *
	 * @param string $template Template core resolved.
	 * @return string
	 */
	public function use_hub_loader( string $template ): string {
		return null === $this->pending_render
			? $template
			: BUDDYNEXT_DIR . 'templates/hub-loader.php';
	}

	/**
	 * Render the pending hub. Called by the loader template.
	 *
	 * @return void
	 */
	public static function render_pending(): void {
		$router = self::$rendering;
		if ( ! $router instanceof self || null === $router->pending_render ) {
			return;
		}

		[ $hub, $template, $context ] = $router->pending_render;

		// Cleared before rendering, not after: the shell fires hooks, and a
		// listener that somehow re-entered here would otherwise render twice.
		$router->pending_render = null;
		self::$rendering        = null;

		$router->render_shell_with_theme_chrome( $hub, $template, $context );
	}

	/**
	 * Send a real WordPress 404 for a BuddyNext route and stop.
	 *
	 * Called at template_redirect — BEFORE get_header() flushes the document — so
	 * the status line actually carries 404 instead of the soft "200 OK with a
	 * not-found body" a late status_header() call produces. Renders the active
	 * theme's 404 template so the visitor sees the site's own not-found page.
	 *
	 * @return void Never returns: exits.
	 */
	private function send_404(): void {
		global $wp_query;

		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		// Set the state and hand back to core; do not load the template here.
		//
		// This used to call get_404_template() and exit, which produced a correct
		// 404 status but skipped the rest of core's pipeline for the SAME reason
		// the hub render did: template_include and wp_before_include_template come
		// after template_redirect. Measured directly - a core 404
		// (/definitely-not-real/) ran the full pipeline while a BuddyNext 404
		// (/members/nobody-here/) ran only template_redirect, so the 404 page was
		// invisible to the template-enhancement buffer and to any other plugin
		// filtering the template.
		//
		// With is_404 set and no render pending, core resolves the 404 template
		// itself through get_query_template(), applies template_include to it, and
		// includes it inside the buffer. Both callers return immediately after
		// this, so nothing further in dispatch runs.
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

		/*
		 * Space-content gate — the same SpaceVisibility decision point the REST
		 * read path uses. This method emits the og:/twitter: description for a
		 * post permalink, so the secret-only check it replaces published the body
		 * of a PRIVATE space's post into crawler-readable meta tags for anyone
		 * with the link.
		 */
		$space_id = (int) ( $post['space_id'] ?? 0 );
		if ( $space_id > 0 ) {
			$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
			if ( null !== $space && ! \BuddyNext\Spaces\SpaceVisibility::can_view_content( $space, $viewer_id ) ) {
				return;
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

		// Author content-hidden / shadow-ban gate (admins + the author see through).
		// Uses hides_posts() (bn_user_suspensions, hide_posts=1) — the same
		// predicate as the feed and the shared-post embed — so a single-post view
		// stays consistent with how the post appears elsewhere. is_suspended()
		// would over-hide action-restricted (hide_posts=0) members' content.
		if ( $author_id > 0 && $author_id !== $viewer_id && ! user_can( $viewer_id, 'manage_options' ) ) {
			$author_suspended = buddynext_service( 'moderation' )->hides_posts( $author_id );
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
	/**
	 * Put the Community Name in the tab title's site half, on BuddyNext pages.
	 *
	 * The Community Name field's own hint promises the browser title, and it
	 * never reached it: both head emitters set `$parts['title']` and nothing
	 * ever touched `$parts['site']`, which WordPress fills from the WP Site
	 * Title. So an owner renaming their community saw every BuddyNext page keep
	 * announcing the WordPress site name in the tab and in bookmarks, with no
	 * indication the setting had only done half its job.
	 *
	 * Only the site half, and only on BuddyNext's own surfaces: the WP Site
	 * Title is what a blog post or a shop page should carry, and renaming the
	 * community is not a request to rename WordPress.
	 *
	 * Deferred when an SEO plugin owns the head, for the same reason the title
	 * half is (Zoho #41057, Basecamp 10173643793) - an owner who typed a title
	 * into Yoast made an explicit choice, and this is not the place to overrule
	 * it.
	 *
	 * @return void
	 */
	public static function apply_community_name_to_title(): void {
		if ( self::seo_plugin_active() ) {
			return;
		}

		$community = buddynext_site_name();

		// Identical to the WP Site Title is the common case, and re-stating it
		// through a filter buys nothing.
		if ( '' === $community || (string) get_bloginfo( 'name' ) === $community ) {
			return;
		}

		add_filter(
			'document_title_parts',
			static function ( array $parts ) use ( $community ): array {
				$parts['site'] = $community;
				return $parts;
			}
		);
	}

	/**
	 * Whether a hub render has claimed the document title this request.
	 *
	 * @var bool
	 */
	private static bool $title_claimed = false;

	/**
	 * Has a hub render already claimed the document title?
	 *
	 * The hub title is the MEMBER-facing one ("Notifications (99+)"), and it is
	 * more specific than the social descriptor HeadMeta emits afterwards. Both
	 * used to claim `document_title_parts` at priority 10, so registration order
	 * decided and HeadMeta - registered second - silently won, costing every hub
	 * its real title and printing a bare site name on messages and notifications.
	 *
	 * @return bool True when render_hub() has already filtered the title.
	 */
	public static function title_claimed(): bool {
		return self::$title_claimed;
	}

	/**
	 * Clear the document-title claim. Test seam only.
	 *
	 * @return void
	 */
	public static function reset_title_claim(): void {
		self::$title_claimed = false;
	}

	/**
	 * Is a major SEO plugin managing this site's head?
	 *
	 * The single answer both BuddyNext head emitters ask before overriding
	 * anything the owner may have configured. It used to be inlined in the
	 * meta-description path only, which is why the description deferred
	 * correctly while the document TITLE was overwritten unconditionally — the
	 * inconsistency a paying customer reported (Zoho #41057, Basecamp
	 * 10173643793): they typed a title into Yoast and BuddyNext discarded it.
	 *
	 * @return bool
	 */
	public static function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' )              // Yoast SEO.
			|| class_exists( 'RankMath' )                 // Rank Math.
			|| defined( 'AIOSEO_VERSION' )                // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )              // SEOPress.
			|| defined( 'THE_SEO_FRAMEWORK_VERSION' );    // The SEO Framework.

		/**
		 * Filter whether BuddyNext should treat the head as owned by an SEO plugin.
		 *
		 * @since 1.1.3
		 *
		 * @param bool $active Whether a known SEO plugin is active.
		 */
		return (bool) apply_filters( 'buddynext_seo_plugin_active', $active );
	}

	/**
	 * Emit the community meta description on hubs that have no richer one.
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

		// Defer to SurfaceMeta when it has already described this response.
		// Both emitters hook wp_head at priority 1, so without this guard every
		// BuddyNext URL shipped TWO <meta name="description"> tags — and on a
		// profile or a single post they made two DIFFERENT claims (the member's
		// own description, plus the site-wide community blurb). This fallback
		// now only covers surfaces SurfaceMeta does not describe.
		if ( HeadMeta::has_emitted() ) {
			return;
		}

		// Defer to an active SEO plugin — emitting our own tag would duplicate
		// the head meta description.
		if ( self::seo_plugin_active() ) {
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
			// Shell-level string dictionary for the framework-agnostic JS helpers
			// (assets/js/shell/dialog.js, assets/js/social/relation-remove.js).
			// Those helpers build DOM directly and are not Interactivity Script
			// Modules, so they cannot read a per-store wp_interactivity_state() dict.
			// They read window.bnShellData.i18n.<key> here (English literal kept as a
			// JS fallback). The report-reason list in particular is internal to
			// dialog.js and unreachable from any caller — this is its only translatable
			// source. Keep in sync with the keys read in those two files.
			'i18n'               => array(
				// Profile bio collapse (shell/extras.js).
				'bioShowMore'            => __( 'Show more', 'buddynext' ),
				'bioShowLess'            => __( 'Show less', 'buddynext' ),
				// Shared modal frame.
				'close'                  => __( 'Close', 'buddynext' ),
				'confirm'                => __( 'Confirm', 'buddynext' ),
				'cancel'                 => __( 'Cancel', 'buddynext' ),
				// Report dialog.
				'reportTitle'            => __( 'Report', 'buddynext' ),
				'reportBody'             => __( 'Reports are reviewed by moderators. The person you report is not notified.', 'buddynext' ),
				'reportSubmit'           => __( 'Submit report', 'buddynext' ),
				'reportReasonLabel'      => __( 'Reason', 'buddynext' ),
				'reportNotesLabel'       => __( 'Additional details (optional)', 'buddynext' ),
				'reportNotesPlaceholder' => __( 'Tell us more about what you saw…', 'buddynext' ),
				// Individual reason strings used to live here as a fifth copy of the
				// list — and it had already drifted, missing `fake` entirely, so
				// "Fake account" was offered in the profile modal and nowhere else.
				// The dialog now receives the whole vocabulary as `reportReasons`
				// below, which is what makes buddynext_report_reasons reach the JS
				// surfaces at all. These keys remain as a fallback for a cached
				// script still reading them by name.
				'reasonSpam'             => __( 'Spam', 'buddynext' ),
				'reasonHarassment'       => __( 'Harassment or hate speech', 'buddynext' ),
				'reasonMisinformation'   => __( 'Misinformation', 'buddynext' ),
				'reasonInappropriate'    => __( 'Inappropriate content', 'buddynext' ),
				'reasonImpersonation'    => __( 'Impersonation', 'buddynext' ),
				'reasonOther'            => __( 'Something else', 'buddynext' ),
				// Connection-note dialog.
				'connectTitle'           => __( 'Add a note', 'buddynext' ),
				'connectBody'            => __( 'Add a personal message to your connection request, or send it without one.', 'buddynext' ),
				'connectSubmit'          => __( 'Send request', 'buddynext' ),
				'connectPlaceholder'     => __( 'e.g. We met at the design meetup — I’d love to stay connected.', 'buddynext' ),
				// Generic fallback toast (relation-remove.js).
				'updateFailed'           => __( 'Could not update. Try again.', 'buddynext' ),
			),
			// The report vocabulary as ordered [ slug, label ] pairs — the single
			// source every surface reads, so a reason added through
			// buddynext_report_reasons is OFFERED and not merely accepted.
			'reportReasons'      => array_map(
				static function ( $bn_slug, $bn_label ): array {
					return array( (string) $bn_slug, (string) $bn_label );
				},
				array_keys( \BuddyNext\Moderation\ModerationService::reason_choices() ),
				array_values( \BuddyNext\Moderation\ModerationService::reason_choices() )
			),
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
			/**
			 * Whether the single-key shortcuts are active (n, /, g+f and friends).
			 *
			 * On by default: they cost nothing to ignore and readers who want them
			 * expect them. But a single unmodified keypress is a surprisingly
			 * strong claim on someone's keyboard - it fires for anyone who is not
			 * focused in a field, including assistive-technology users navigating
			 * by character, and on a site where the community is not the whole
			 * product an owner may simply not want them.
			 *
			 * A filter rather than a setting: this is a per-site preference an
			 * owner either holds or does not, and it does not deserve a control
			 * every other owner has to read past.
			 *
			 *     add_filter( 'buddynext_keyboard_shortcuts_enabled', '__return_false' );
			 *
			 * @param bool $enabled Whether to bind the shortcut handler.
			 */
			// Emitted as '1'/'0', NOT as a bool. wp_localize_script() casts every
			// value to a string, so `false` arrives in JS as the empty string ''
			// and a `!== false` test on the other side is never true - the filter
			// would return false, the handler would bind anyway, and the whole
			// option would be decorative. Caught by switching the filter on and
			// watching the shortcut still fire.
			'shortcuts'          => apply_filters( 'buddynext_keyboard_shortcuts_enabled', true ) ? '1' : '0',
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
			// Connect button opens a note dialog (LinkedIn) and the note is shown
			// to the recipient in their connection-request inbox, next to Accept
			// and Decline. Read once here so every connect surface shares one
			// source of truth instead of threading the flag through each button's
			// data-wp-context.
			//
			// No delivery dependency any more. This used to be AND-ed with the
			// messaging engine being active, because the note was stored but never
			// rendered and a DM was the only way it reached anyone — so with the
			// engine off, turning the setting on asked members to write notes that
			// reached nobody (Basecamp 10185178801). The request inbox now renders
			// the note itself, so the note always has a reader and the setting
			// means what it says on every site (Basecamp 10244757451).
			'connectRequireNote' => '1' === (string) get_option( 'buddynext_connection_require_note', '0' ),
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
					// Not only CSS: the feed module owns the profile Share button
					// (buddynext/share-modal) and, on the activity tab, the post-card
					// react/comment/share/bookmark actions. Verified 2026-08-04 — a
					// profile renders a live share-modal island with zero post-cards,
					// so bn-feed.js is a behavioural dependency here, not just styling.
					$assets->enqueue( 'feed' );
					$assets->enqueue( 'media-upload' ); // Owner-only upload composer on the Media tab.
					$assets->enqueue( 'media-albums' ); // Media | Albums sub-nav + albums UI.
					// Followers / Following / Connections render as in-page tabs in
					// the profile shell (parts/member-grid.php, server-rendered and
					// toggled client-side), so the shared member cards are always in
					// the DOM. Always load bn-members.css (grid + card-action styling)
					// and the @buddynext/members store (card follow/connect + overflow
					// menus) so the panels are never unstyled.
					$assets->enqueue( 'members' );
					// The Files tab renders BuddyNext's own document drive + the
					// single-document reader (bn-space-files.css + the
					// @buddynext/space-files preview island), same as the space
					// Files tab. Scoped to the files action so no other profile
					// tab pays for it.
					if ( 'files' === (string) get_query_var( 'bn_profile_action', '' ) ) {
						$assets->enqueue( 'space-files' );
					}
				} else {
					$assets->enqueue( 'members' );
				}
				break;

			case 'spaces':
				$assets->enqueue( 'spaces' );
				// Not only CSS: a space feed runs the FULL feed module —
				// buddynext/post-card, post-composer and share-modal islands are
				// live in-space with react/comment/share/compose all wired
				// (verified 2026-08-04). bn-feed.js is a behavioural dependency on
				// space pages, identical to the activity hub.
				$assets->enqueue( 'feed' );
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
				// The space Media tab renders the SAME gallery template as a
				// profile, so it needs the same two modules. Without them the
				// Media | Albums toggle and the upload dropzone are in the DOM but
				// never hydrate: the buttons do nothing and the grid stays empty,
				// which is exactly how the tab looked before this line existed.
				if ( 'media' === $space_action_v || 'media' === $bn_space_tab ) {
					$assets->enqueue( 'media-upload' );
					$assets->enqueue( 'media-albums' );
				}
				// The space Files tab is a server-rendered document-drive browser
				// (no store) — it needs only its stylesheet, keyed on the action.
				if ( 'files' === $space_action_v || 'files' === $bn_space_tab ) {
					$assets->enqueue( 'space-files' );
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
				break;

			case 'settings':
				// Relocated account/privacy/appearance sections reuse the profile
				// editor's `.bn-ep-*` styles, plus the shared settings-tab chrome.
				$assets->enqueue( 'profile' );
				$assets->enqueue( 'settings' );

				// The preferences form's own store. This used to live in the
				// `notifications` case gated on bn_notif_section === 'prefs', with a
				// comment already admitting "the prefs page is the Settings hub's
				// Notifications tab" — the intent was right and the routing was not.
				if ( 'notifications' === (string) get_query_var( 'bn_settings_section', '' ) ) {
					$assets->enqueue( 'notification-prefs' );
				}
				break;

			case 'auth':
				$assets->enqueue( 'auth' );
				$auth_action = (string) get_query_var( 'bn_auth_action', '' );
				switch ( $auth_action ) {
					case 'signup':
					case 'complete':
						// The finish-signup form reuses the signup store: same field
						// collection, same inline 422 handling, different endpoint.
						wp_enqueue_script_module( '@buddynext/auth-signup' );
						break;
					case 'verify':
						wp_enqueue_script_module( '@buddynext/auth-verify' );
						break;
					case 'reset':
						wp_enqueue_script_module( '@buddynext/auth-reset' );
						break;
					case 'connect-app':
						wp_enqueue_script_module( '@buddynext/auth-connect-app' );
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

			case 'community_admin':
				// The panel's .bn-ca-* chrome lives in bn-moderation.css and its
				// Appeals approve/deny controls run on the buddynext/moderation
				// store — the same assets the [buddynext_community_admin] shortcode
				// enqueues via enqueue_shell( 'moderation' ). Without this the
				// routed hub renders the panel unstyled.
				$assets->enqueue( 'moderation' );
				// The Members view's role controls run on the buddynext/community-admin store.
				$assets->enqueue( 'community-admin' );
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
	 * Register the feed hub's rewrite rules (activity routes + the /me/ sections).
	 *
	 * The feed hub owns both the activity routes (home / explore / hashtag /
	 * search / leaderboard, plus the legacy /search/ redirect) and the personal
	 * /me/bookmarks + /me/account-status sections, so its descriptor callback
	 * registers both. (The single-post /p/{id}/ route is not a hub — it stays a
	 * direct call in register_rewrites().)
	 *
	 * @return void
	 */
	public static function register_feed_rules(): void {
		self::register_activity_rules();
		self::register_bookmarks_rules();
	}

	/**
	 * Resolve the feed hub template from its sub-route query vars.
	 *
	 * @param string $hub The bn_hub value (always 'feed' here).
	 * @return string|null
	 */
	public static function resolve_feed_template( string $hub ): ?string {
		unset( $hub );
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
	}

	/**
	 * Resolve the people (members) hub template.
	 *
	 * @param string $hub The bn_hub value (always 'people' here).
	 * @return string|null
	 */
	public static function resolve_people_template( string $hub ): ?string {
		unset( $hub );
		$user_slug = (string) get_query_var( 'bn_user_slug', '' );
		if ( '' !== $user_slug ) {
			$profile_action = (string) get_query_var( 'bn_profile_action', '' );
			switch ( $profile_action ) {
				case 'edit':
					return 'profile/edit.php';
				default:
					// `media` (and any other profile action without a dedicated
					// template) opens the profile view; view.php deep-links the
					// matching tab from bn_profile_action.
					return 'profile/view.php';
			}
		}
		return 'directory/members.php';
	}

	/**
	 * Resolve the spaces hub template.
	 *
	 * @param string $hub The bn_hub value (always 'spaces' here).
	 * @return string|null
	 */
	public static function resolve_spaces_template( string $hub ): ?string {
		unset( $hub );
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
					// feed / about / media (+ any in-page integration tab) render
					// the space home, which reads bn_space_action for the active
					// tab. Members + Moderation keep their richer standalone pages.
					return 'spaces/home.php';
			}
		}
		return 'spaces/directory.php';
	}

	/**
	 * Resolve the messages hub template.
	 *
	 * @param string $hub The bn_hub value (always 'messages' here).
	 * @return string|null
	 */
	public static function resolve_messages_template( string $hub ): ?string {
		unset( $hub );
		$conv_id    = (int) get_query_var( 'bn_conv_id', 0 );
		$msg_action = (string) get_query_var( 'bn_msg_action', '' );
		if ( $conv_id > 0 ) {
			return 'messages/thread.php';
		}
		if ( 'requests' === $msg_action ) {
			return 'messages/requests.php';
		}
		return 'messages/list.php';
	}

	/**
	 * Resolve the notifications hub template.
	 *
	 * @param string $hub The bn_hub value (always 'notifications' here).
	 * @return string|null
	 */
	public static function resolve_notifications_template( string $hub ): ?string {
		unset( $hub );
		// No prefs branch: the preferences form is the Settings hub's
		// Notifications tab and renders settings/notifications.php.
		return 'notifications/index.php';
	}

	/**
	 * Resolve the auth hub template.
	 *
	 * @param string $hub The bn_hub value (always 'auth' here).
	 * @return string|null
	 */
	public static function resolve_auth_template( string $hub ): ?string {
		unset( $hub );
		$auth_action = (string) get_query_var( 'bn_auth_action', '' );
		switch ( $auth_action ) {
			case 'signup':
				return 'auth/signup.php';
			case 'complete':
				return 'auth/complete.php';
			case 'verify':
				return 'auth/verify.php';
			case 'reset':
				return 'auth/reset.php';
			case 'connect-app':
				return 'auth/connect-app.php';
			case 'login':
			default:
				return 'auth/login.php';
		}
	}

	/**
	 * Resolve the onboarding hub template.
	 *
	 * @param string $hub The bn_hub value (always 'onboarding' here).
	 * @return string|null
	 */
	public static function resolve_onboarding_template( string $hub ): ?string {
		unset( $hub );
		return 'onboarding/index.php';
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
		// Non-hub routes without a descriptor resolve here: the single-post
		// permalink, the settings sub-tabs, and the moderation queue (post folds
		// into feed; settings + moderation get descriptors — plan Phase 4). Every
		// hub, core or addon, resolves through its descriptor's resolve_template
		// callback in the default branch, so there is one path.
		switch ( $hub ) {
			case 'post':
				return 'feed/single-post.php';

			case 'settings':
				$settings_section = (string) get_query_var( 'bn_settings_section', '' );
				if ( ! in_array( $settings_section, array( 'account', 'privacy', 'appearance', 'notifications' ), true ) ) {
					$settings_section = 'account';
				}
				return 'settings/' . $settings_section . '.php';

			case 'moderation':
				return 'moderation/queue.php';

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
		// A third profile path segment for a tab that addresses one entity by a
		// clean URL — today the Files tab's document view
		// (/members/{slug}/files/{id}/), mirroring %bn_space_sub% for spaces.
		add_rewrite_tag( '%bn_profile_sub%', '([^/]+)' );
		add_rewrite_tag( '%bn_space_slug%', '([^/]+)' );
		add_rewrite_tag( '%bn_space_action%', '([^/]*)' );
		// A third path segment for a tab that addresses a single entity by a clean
		// URL — today the Files tab's document view (/spaces/{slug}/files/{id}/).
		// Generic on purpose so any tab can adopt one without a new rule.
		add_rewrite_tag( '%bn_space_sub%', '([^/]+)' );
		add_rewrite_tag( '%bn_conv_id%', '([0-9]+)' );
		add_rewrite_tag( '%bn_msg_action%', '([^/]*)' );
		add_rewrite_tag( '%bn_auth_action%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_settings_section%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_post_id%', '([0-9]+)' );
		add_rewrite_tag( '%bn_feed_section%', '([a-z-]+)' );
		add_rewrite_tag( '%bn_legacy_search%', '([01])' );

		// Non-hub routes that have no descriptor: single-post permalink, the
		// settings sub-tabs, and the moderation queue. These stay explicit until
		// they gain their own descriptors (post folds into feed; settings +
		// moderation get descriptors — plan Phase 4).
		self::register_post_rules();
		self::register_settings_rules();
		self::register_moderation_rules();

		// Every hub — core and addon — registers its own rewrite rules through
		// its descriptor's register_rules callback. Core hubs now ride the exact
		// same seam an addon does (CoreHubs wires register_feed_rules,
		// register_people_rules, … onto their descriptors), so there is one path,
		// not a parallel hardcoded call list that could drift.
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
	public static function register_activity_rules(): void {
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
	public static function register_bookmarks_rules(): void {
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
	public static function register_people_rules(): void {
		$p = self::hub_slug( 'buddynext_slug_people', 'members' );

		// Generic profile sub-route: ANY tab slug becomes a pretty URL
		// (/members/{slug}/{tab}/). Replaces the per-action rules so core tabs
		// (edit, connections, followers, following, media, badges, replies,
		// likes, about, discussions) AND integration tabs (portfolio, …) all
		// deep-link without a ?tab= query arg. resolve_hub_template() sends
		// 'edit' to the edit template; every other action renders the profile
		// view, which activates the matching tab from bn_profile_action.
		// Three-segment tab entity: /members/{slug}/files/{doc_id}/ deep-links the
		// Files tab's single-document view. Registered BEFORE the two-segment rule
		// so /members/x/files/882/ is not swallowed as a two-segment match.
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/files/([^/]+)/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=files&bn_profile_sub=$matches[2]',
			'top'
		);
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
		// There is deliberately NO /members/{type-slug}/ rule.
		//
		// One lived here, registered 'bottom', with a comment claiming the
		// user-slug rules took precedence and that bn_member_type was only stored
		// "when no user was resolved". Rewrite rules do not work that way: they
		// match on the PATTERN, not on whether the capture resolves to anything.
		// The 'top' rule directly above has the identical shape, so it always won
		// and bn_member_type was never populated — the rule never appeared in the
		// generated rewrite_rules option at all. /members/staff/ was therefore
		// handled as a profile URL for a member named "staff", which is exactly
		// why it rendered blank.
		//
		// The pretty form cannot be made to work: /members/{type}/ is
		// indistinguishable from /members/{username}/, and usernames must win.
		// Member-type filtering is the ?type= query argument, which the directory
		// already reads and PageRouter::member_type_url() already emits.
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
	public static function register_spaces_rules(): void {
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
		// A tab that addresses one entity by a clean URL: /spaces/{slug}/{action}/{sub}/.
		// Anchored, so it never overlaps the two-segment tab rule below. The tab's
		// render callback reads bn_space_sub (the Files tab treats it as a document id).
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/([^/]+)/([^/]+)/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=$matches[2]&bn_space_sub=$matches[3]',
			'top'
		);
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
	public static function register_messages_rules(): void {
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
	public static function register_notifications_rules(): void {
		$n = self::hub_slug( 'buddynext_slug_notifications', 'notifications' );

		// /notifications/preferences/ — the legacy alias. It resolves to the SETTINGS
		// hub now, like /settings/notifications/, so the old URL keeps working while
		// `bn_notif_section=prefs` ceases to exist anywhere. Leaving it pointing at
		// this hub would have kept the second code path alive for one route — which
		// is exactly the shape that produced the bug this card removes.
		add_rewrite_rule(
			'^' . preg_quote( $n, '/' ) . '/preferences/?$',
			'index.php?bn_hub=settings&bn_settings_section=notifications',
			'top'
		);

		// NOTE: /settings/notifications/ is NOT registered here any more. It is a
		// Settings tab and now resolves through register_settings_rules() like its
		// three siblings. Routing it through this hub is what made the sidebar
		// surface fall back to `notifications` and fill a preferences form's right
		// column with Quick filters / By type / Unread only — controls that filter a
		// list the page does not have.
		add_rewrite_rule(
			'^' . preg_quote( $n, '/' ) . '/?$',
			'index.php?bn_hub=notifications',
			'top'
		);
	}

	/**
	 * Register the Settings hub rewrite rules.
	 *
	 * The Settings hub is a tabbed home for per-user preferences. All four tabs —
	 * account, privacy, appearance and notifications — resolve here, through the
	 * same loop, to `bn_hub=settings`. `/settings/` defaults to the Account tab.
	 *
	 * Notifications used to be the exception, routed through the notifications hub
	 * as `bn_notif_section=prefs`. That mismatch generated a real bug rather than
	 * merely looking untidy: anything keying off the hub got the wrong answer for
	 * this one tab, and the sidebar surface did exactly that.
	 *
	 * @return void
	 */
	private function register_settings_rules(): void {
		foreach ( array( 'account', 'privacy', 'appearance', 'notifications' ) as $section ) {
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
	public static function register_auth_rules(): void {
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
		// Finish a social sign-up that the provider could not complete on its own
		// (terms consent, required profile fields). No account exists yet.
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/complete/?$',
			'index.php?bn_hub=auth&bn_auth_action=complete',
			'top'
		);
		// The native-app connect bridge: the mobile app opens this in a browser
		// auth session; after any sign-in method, the approve screen mints an
		// Application Password and deep-links it back to the app.
		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/connect-app/?$',
			'index.php?bn_hub=auth&bn_auth_action=connect-app',
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
	public static function register_onboarding_rules(): void {
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
			// Reserved directory-scope words are never space slugs. If the generic
			// /spaces/{slug}/ rewrite rule captured "mine" (it out-orders the pretty
			// /spaces/mine/ rule on installs where Learnomy/other plugins inject an
			// early spaces/{slug} rule), re-route to the My-Spaces directory view
			// instead of resolving a non-existent space (which 404s "Space not
			// found."). Order-independent — no reliance on rewrite-rule priority.
			$reserved = (array) apply_filters( 'buddynext_reserved_space_slugs', array( 'mine' ) );
			if ( in_array( $raw_space_slug, $reserved, true ) ) {
				$query->set( 'bn_scope', 'mine' );
				$action = sanitize_key( (string) $query->get( 'bn_space_action', '' ) );
				if ( 'managed' === $action || 'joined' === $action ) {
					$query->set( 'bn_membership', $action );
				}
				$query->set( 'bn_space_slug', '' );
				$query->set( 'bn_space_action', '' );
			} else {
				$space_id = $this->resolve_space( sanitize_title( $raw_space_slug ) );
				$query->set( 'bn_resolved_space_id', $space_id );
			}
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
		// SOFT flush: this hook fires inside the save request, AFTER
		// register_rewrites (init:10) already registered rules built from the
		// OLD slug - an immediate flush_rewrite_rules() would regenerate and
		// persist those stale rules, so the new slug 404s until a manual
		// flush. Deleting the option instead makes the NEXT request rebuild
		// the rules with the fresh option values.
		delete_option( 'rewrite_rules' );
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
	 * The WordPress page mapped to a hub, if the owner has one.
	 *
	 * Hubs render virtually, but each is backed by a real page the owner can
	 * open in wp-admin — which is where they configure SEO settings. This is
	 * the bridge between the two.
	 *
	 * @param string $hub Hub key.
	 * @return int Page ID, or 0 when the hub has no mapped page.
	 */
	public static function hub_page_id( string $hub ): int {
		// Resolve the page option from the hub registry, not a hardcoded core-only
		// map: any hub registered with backing_page:true (including addon hubs via
		// buddynext_register_hubs) then resolves its SEO/queried-object page, and a
		// non-page-backed hub (onboarding, community-admin) correctly returns 0.
		$descriptor = HubRegistry::instance()->get( $hub );
		if ( ! $descriptor instanceof HubDescriptor || ! $descriptor->backing_page ) {
			return 0;
		}

		$page_id = (int) get_option( $descriptor->page_option, 0 );

		return ( $page_id > 0 && 'page' === get_post_type( $page_id ) ) ? $page_id : 0;
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
	 * The member's public @handle — the single source of truth for the mention.
	 *
	 * Returns bn_profile_slug ?: user_nicename, never user_login. user_login is a
	 * CREDENTIAL: WordPress accepts login by username, publishing a confirmed-valid
	 * username on a public surface aids enumeration, and WP core itself never exposes
	 * it (its REST API returns the nicename as `slug`). This is the same value
	 * profile_url()/resolve_user() build the profile URL from, so the @handle a
	 * member sees always resolves to that member's profile.
	 *
	 * Every surface that renders an "@handle" (member cards, space member lists, the
	 * profile hero, the "online now" sidebar) must resolve it here, so the credential
	 * can never leak and the two never drift. Prime the usermeta cache
	 * (`cache_users()` / `update_meta_cache( 'user', $ids )`) before a loop of these.
	 *
	 * @param int $user_id User ID.
	 * @return string Public handle without the leading '@', or '' for an invalid user.
	 */
	public static function member_handle( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom_slug ) {
			return $custom_slug;
		}

		$user = get_userdata( $user_id );

		return $user instanceof WP_User ? (string) $user->user_nicename : '';
	}

	/**
	 * Return the member directory URL filtered to a specific member type.
	 *
	 * Uses the `?type=` query argument — the single member-type filter contract
	 * the directory already reads (members.php reads get_query_var/`$_GET['type']`)
	 * and the reactive JS filter and the "By role" facet already emit. A pretty
	 * `/members/{slug}/` URL cannot be used here: it is indistinguishable from a
	 * profile URL (`/members/{username}/`), and the user-slug rewrite always wins,
	 * so such a link dead-ends on a blank profile instead of the filtered list.
	 *
	 * @param string $type_slug Member type slug (lowercase alphanumeric + hyphens).
	 * @return string Absolute directory URL, filtered by type when a slug is given.
	 */
	public static function member_type_url( string $type_slug ): string {
		$type_slug = sanitize_key( $type_slug );
		if ( '' === $type_slug ) {
			return self::people_url();
		}

		return add_query_arg( 'type', $type_slug, self::people_url() );
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
	 * THE RULE: this must refuse everything resolve_user() can resolve. The two
	 * are a matched pair — one decides who owns a URL, the other decides who may
	 * take one — and when they disagree the gap is claimable.
	 *
	 * They did disagree. resolve_user() has always resolved by bn_profile_slug,
	 * then user-{id}, then user_nicename. This checked only the FIRST of the
	 * three, so every member without a custom slug — who routes by nicename, the
	 * default for a whole site — read as "available" and another member could
	 * take their profile URL. Verified before the fix: is_slug_available(
	 * 'sim_member', $other ) returned true and PUT /me/profile-slug returned 200
	 * with the victim's own URL (Basecamp 10251987462).
	 *
	 * Every writer funnels through here — the REST set and check routes, the
	 * admin member editor, ProfileService::save and onboarding — so the union is
	 * enforced once rather than five times.
	 *
	 * A slug is unavailable when:
	 *   - Another user already holds it as bn_profile_slug usermeta.
	 *   - Another user holds it as their user_nicename.
	 *   - It matches the reserved "user-{numeric_id}" pattern for another user.
	 *   - It is a reserved word (see buddynext_reserved_profile_slugs).
	 *
	 * @since 1.0.0
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

		if ( in_array( $slug, self::reserved_profile_slugs(), true ) ) {
			return false;
		}

		// user_nicename. Checked EVEN IF that member also has a custom slug: the
		// nicename stays a live fallback in resolve_user(), so their old URL still
		// reaches them and handing it to someone else would silently redirect it.
		$nicename_owner = get_user_by( 'slug', $slug );
		if ( $nicename_owner instanceof \WP_User && (int) $nicename_owner->ID !== $user_id ) {
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

	/**
	 * Profile slugs no member may claim.
	 *
	 * Deliberately SHORT, and honest about what it is: defence in depth, not a
	 * fix for a live collision. Measured on 1.1.6 — a member holding the slug
	 * `edit` gets /members/edit/ and it resolves to them, while
	 * /members/someone/edit/ still reaches the edit screen. The members rewrite
	 * takes the first segment as a slug and the second as the action, so a
	 * one-word slug cannot shadow a two-segment route today.
	 *
	 * What it guards is tomorrow: the moment anyone adds a LISTING route under
	 * the members base — /members/search/, /members/online/ — a member already
	 * holding that word shadows it, and by then the slug is in their profile URL
	 * and in links other people have shared. Refusing a handful of routing words
	 * now costs nothing; reclaiming one later costs a member their URL.
	 *
	 * `me` is here for a second reason: BuddyNext already routes /me/... at the
	 * top level, and a member whose profile is /members/me/ makes every "me"
	 * link in support ambiguous to read.
	 *
	 * Owners extend it — a brand term, a landing page they intend to add — and
	 * an owner who wants none of it can return an empty array.
	 *
	 * @since 1.1.6
	 *
	 * @return string[] Sanitized, lowercase slugs.
	 */
	public static function reserved_profile_slugs(): array {
		$reserved = array( 'me', 'edit', 'settings', 'admin', 'search', 'new', 'all' );

		/**
		 * Filter the profile slugs no member may claim.
		 *
		 * @since 1.1.6
		 *
		 * @param string[] $reserved Default reserved slugs.
		 */
		$reserved = (array) apply_filters( 'buddynext_reserved_profile_slugs', $reserved );

		return array_values( array_filter( array_map( 'sanitize_title', $reserved ) ) );
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
		// The registry is the only source of hub default slugs (community_admin
		// included). No literal fallback map: an option no hub owns is a caller
		// bug, not a hub with a 'community' slug.
		foreach ( HubRegistry::instance()->all() as $hub ) {
			if ( $hub->slug_option === $option_name ) {
				return $hub->default_slug;
			}
		}
		// Unknown option: surface it in debug rather than silently routing to a
		// slug no hub owns. Empty means "no default", so hub_slug() falls back to
		// whatever the option stores (or '').
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong( __METHOD__, esc_html( "No registered hub owns slug option '{$option_name}'." ), '1.2.0' );
		}
		return '';
	}
}
