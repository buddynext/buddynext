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

		// Flush rewrites whenever any hub slug changes.
		foreach ( array( 'activity', 'people', 'spaces', 'messages', 'notifications', 'auth', 'onboarding' ) as $hub ) {
			add_action( 'update_option_buddynext_slug_' . $hub, array( $this, 'flush_on_slug_change' ) );
		}

		add_filter( 'request', array( $this, 'suppress_default_query' ) );
		add_action( 'template_redirect', array( $this, 'dispatch_hub_template' ) );
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
	private const ROUTER_VERSION = '2026-06-14-pretty-profile-tabs';

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
			if ( $front_id === (int) get_option( $option ) ) {
				return $slug;
			}
		}

		return '';
	}

	public function dispatch_hub_template(): void {
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

		// Direct-messaging guard: when the site owner turns DMs off
		// (buddynext_enable_dm), the /messages/ route is dead — bounce any
		// visitor to the activity hub rather than render a hub the community has
		// turned off. The nav entry points hide themselves via
		// MessagesData::dm_enabled(); this blocks direct URL access too.
		if ( 'messages' === $hub
			&& ! \BuddyNext\Messages\MessagesData::dm_enabled()
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
			$guarded_feed_sections = array( '', 'home', 'bookmarks', 'saved' );

			// The explore feed shares the 'feed' hub with an empty feed_section,
			// so it would otherwise be swept up by the guarded-section check
			// below. Its guest access is governed solely by the public-explore
			// guard above (buddynext_public_explore) — exempt it here so that,
			// when explore is public, guests actually reach it.
			$is_explore = ( 'feed' === $hub && 'explore' === $activity_action );

			$needs_login =
				in_array( $hub, array( 'messages', 'notifications', 'onboarding', 'settings' ), true )
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
		$wp_query->is_404     = false;
		$wp_query->is_page    = true;
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
		$GLOBALS['post']            = $virtual_post;
		$wp_query->post             = $virtual_post;
		$wp_query->posts            = array( $virtual_post );
		$wp_query->queried_object   = $virtual_post;
		$wp_query->queried_object_id = 0;
		$wp_query->post_count       = 1;
		$wp_query->found_posts      = 1;

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
			if ( null !== $space_record ) {
				$space_name   = (string) ( $space_record['name'] ?? '' );
				$space_action = (string) ( $context['space_action'] ?? '' );
				$bn_tab       = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

				$section_label = '';
				if ( 'settings' === $space_action ) {
					$section_label = __( 'Settings', 'buddynext' );
				} elseif ( 'moderation' === $space_action ) {
					$section_label = __( 'Moderation', 'buddynext' );
				} elseif ( 'admin' === $space_action ) {
					$section_label = __( 'Admin', 'buddynext' );
				} elseif ( 'members' === $space_action || 'members' === $bn_tab ) {
					$section_label = __( 'Members', 'buddynext' );
				} elseif ( 'about' === $bn_tab ) {
					$section_label = __( 'About', 'buddynext' );
				} elseif ( 'media' === $bn_tab ) {
					$section_label = __( 'Media', 'buddynext' );
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
		$indexing   = (string) get_option( 'buddynext_google_indexing', 'public_posts' );
		$is_posts   = ( 'feed' === $hub || 'activity' === $hub );
		$is_public  = ( $is_posts || 'people' === $hub || 'spaces' === $hub );
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
		$this->enqueue_hub_assets( $hub, $context );

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
		if ( $author_id > 0 && $author_id !== $viewer_id && ! user_can( $viewer_id, 'manage_options' ) ) {
			$author_suspended = (bool) get_user_meta( $author_id, 'bn_suspended', true );
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
	 * Called from dispatch_hub_template() after the hub and context are known,
	 * so per-hub and per-action asset decisions can be made accurately.
	 *
	 * @param string               $hub     Active bn_hub value.
	 * @param array<string, mixed> $context Template context built by build_hub_context().
	 * @return void
	 */
	private function enqueue_hub_assets( string $hub, array $context ): void {
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
			'restNonce'         => wp_create_nonce( 'wp_rest' ),
			'restSearchUrl'     => esc_url_raw( rest_url( 'buddynext/v1/search' ) ),
			'restNotifsUrl'     => esc_url_raw( rest_url( 'buddynext/v1/me/notifications?per_page=5' ) ),
			'restNotifsReadUrl' => esc_url_raw( rest_url( 'buddynext/v1/me/notifications/read-all' ) ),
			'restUserUrl'       => esc_url_raw( rest_url( 'buddynext/v1/users/' ) ),
			'feedUrl'           => self::activity_url(),
			'navUrls'           => array(
				'feed'          => self::activity_url(),
				'members'       => self::people_url(),
				'spaces'        => self::spaces_url(),
				'notifications' => self::notifications_url(),
				'messages'      => self::messages_url(),
			),
		);
		add_action(
			'wp_enqueue_scripts',
			static function () use ( $bn_shell_data ): void {
				wp_localize_script( 'bn-shell-extras', 'bnShellData', $bn_shell_data );
			},
			20
		);

		switch ( $hub ) {
			case 'feed':
				$assets->enqueue( 'feed' );
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
				// Single-profile view vs. member directory.
				if ( ! empty( $context['user_id'] ) ) {
					$assets->enqueue( 'profile' );
					$assets->enqueue( 'feed' ); // Post cards on profile use bn-feed.css classes.
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
				// The members sub-view renders Remove-member / Change-role buttons
				// bound to the buddynext/space-members store, so that module must
				// load there too — without it the buttons render but never hydrate.
				$bn_space_tab = isset( $_GET['bn_tab'] ) ? sanitize_key( wp_unslash( $_GET['bn_tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( 'members' === $space_action_v || 'members' === $bn_space_tab ) {
					$assets->enqueue( 'space-members' );
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
					$user               = get_user_by( 'slug', $user_slug );
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
						case 'settings':
							return 'spaces/settings.php';
						case 'moderation':
							return 'spaces/moderation.php';
						case 'admin':
							return 'spaces/admin.php';
						default:
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
					case 'login':
					default:
						return 'auth/login.php';
				}

			case 'moderation':
				return 'moderation/queue.php';

			case 'onboarding':
				return 'onboarding/index.php';

			default:
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
	 * Register Bookmarks hub rewrite rule.
	 *
	 * /me/bookmarks/ resolves to the feed hub with the bookmarks section, which
	 * renders the authenticated user's saved-post list. Auth is enforced inside
	 * the bookmarks template (guests redirected to the auth surface).
	 *
	 * @return void
	 */
	private function register_bookmarks_rules(): void {
		add_rewrite_rule(
			'^me/bookmarks/?$',
			'index.php?bn_hub=feed&bn_feed_section=bookmarks',
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
	private function register_spaces_rules(): void {
		$s = self::hub_slug( 'buddynext_slug_spaces', 'spaces' );

		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/members/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=members',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/settings/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=settings',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/moderation/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=moderation',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $s, '/' ) . '/([^/]+)/admin/?$',
			'index.php?bn_hub=spaces&bn_space_slug=$matches[1]&bn_space_action=admin',
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
	 * Single rule — the auth hub has no sub-endpoints.
	 *
	 * @return void
	 */
	private function register_auth_rules(): void {
		$a      = self::hub_slug( 'buddynext_slug_auth', 'login' );
		$signup = (string) get_option( 'buddynext_slug_signup', 'signup' );
		$verify = (string) get_option( 'buddynext_slug_verify', 'verify-email' );

		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/?$',
			'index.php?bn_hub=auth&bn_auth_action=login',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $signup, '/' ) . '/?$',
			'index.php?bn_hub=auth&bn_auth_action=signup',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $verify, '/' ) . '/?$',
			'index.php?bn_hub=auth&bn_auth_action=verify',
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
	public static function hub_url( string $slug_option, string $page_option ): string {
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
		$map = array(
			'buddynext_slug_activity'        => 'activity',
			'buddynext_slug_people'          => 'members',
			'buddynext_slug_spaces'          => 'spaces',
			'buddynext_slug_messages'        => 'messages',
			'buddynext_slug_notifications'   => 'notifications',
			'buddynext_slug_auth'            => 'login',
			'buddynext_slug_onboarding'      => 'onboarding',
			'buddynext_slug_community_admin' => 'bn-community-admin',
		);
		return $map[ $option_name ] ?? 'community';
	}
}
