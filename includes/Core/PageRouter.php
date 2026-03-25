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
		add_action( 'pre_get_posts', array( $this, 'set_hub_vars' ) );

		// Flush rewrites whenever any hub slug changes.
		foreach ( array( 'activity', 'people', 'spaces', 'messages', 'notifications', 'auth', 'onboarding' ) as $hub ) {
			add_action( 'update_option_buddynext_slug_' . $hub, array( $this, 'flush_on_slug_change' ) );
		}

		add_filter( 'request', array( $this, 'suppress_default_query' ) );
		add_action( 'template_redirect', array( $this, 'dispatch_hub_template' ) );

	}

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
	public function dispatch_hub_template(): void {
		$hub = (string) get_query_var( 'bn_hub', '' );
		if ( '' === $hub ) {
			return;
		}

		$template = $this->resolve_hub_template( $hub );
		if ( null === $template ) {
			return;
		}

		$context = $this->build_hub_context( $hub );

		// Auth hub: redirect logged-in users before any output is sent so
		// wp_safe_redirect() can still set Location headers.
		if ( 'auth' === $hub && is_user_logged_in() ) {
			wp_safe_redirect( self::hub_url( 'buddynext_slug_activity', 'buddynext_page_activity' ) );
			exit;
		}

		// ── Virtual page setup ────────────────────────────────────────────
		// No backing WordPress pages exist. Tell WP this is a real page so
		// it sends 200, generates correct <title>, and themes render their
		// full-width layout. Same technique BuddyPress uses for component
		// pages without cluttering the site owner's Pages list.
		global $wp_query;
		$wp_query->is_404  = false;
		$wp_query->is_page = true;
		status_header( 200 );

		// Set the document <title> via the standard wp_title parts filter.
		$hub_titles   = array(
			'feed'          => __( 'Activity Feed', 'buddynext' ),
			'people'        => __( 'Members', 'buddynext' ),
			'spaces'        => __( 'Spaces', 'buddynext' ),
			'messages'      => __( 'Messages', 'buddynext' ),
			'notifications' => __( 'Notifications', 'buddynext' ),
			'auth'          => __( 'Login', 'buddynext' ),
			'onboarding'    => __( 'Get Started', 'buddynext' ),
		);
		$hub_title    = $hub_titles[ $hub ] ?? ucfirst( $hub );
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

		do_action( 'buddynext_before_hub', $hub, $template );

		// Delegate the full page frame to the active theme. get_header() fires
		// wp_head() internally, and get_footer() fires wp_footer() — so the
		// WordPress Interactivity API runtime and all enqueued scripts load.
		get_header();
		buddynext_get_template( $template, $context );
		get_footer();

		exit;
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

		switch ( $hub ) {
			case 'feed':
				$assets->enqueue( 'feed' );
				// Hashtag feed additionally needs the hashtag store module.
				if ( 'hashtag' === (string) get_query_var( 'bn_activity_action', '' ) ) {
					$assets->enqueue( 'hashtags' );
				}
				break;

			case 'people':
				// Single-profile view vs. member directory.
				if ( ! empty( $context['user_id'] ) ) {
					$assets->enqueue( 'profile' );
					$assets->enqueue( 'feed' ); // Post cards on profile use bn-feed.css classes.
				} else {
					$assets->enqueue( 'members' );
				}
				break;

			case 'spaces':
				$assets->enqueue( 'spaces' );
				$assets->enqueue( 'feed' ); // Post cards on space pages use bn-feed.css classes.
				break;

			case 'messages':
				$assets->enqueue( 'messages' );
				break;

			case 'notifications':
				$assets->enqueue( 'notifications' );
				break;

			case 'auth':
				$assets->enqueue( 'auth' );
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

			case 'people':
				$user_slug = (string) get_query_var( 'bn_user_slug', '' );
				if ( '' !== $user_slug ) {
					$profile_action = (string) get_query_var( 'bn_profile_action', '' );
					switch ( $profile_action ) {
						case 'edit':
							return 'profile/edit.php';
						case 'connections':
							return 'profile/connections.php';
						case 'media':
							return 'profile/media.php';
						case 'badges':
							return 'profile/badges.php';
						default:
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
				return 'notifications/index.php';

			case 'auth':
				return 'auth/login.php';

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

		$this->register_activity_rules();
		$this->register_people_rules();
		$this->register_spaces_rules();
		$this->register_messages_rules();
		$this->register_notifications_rules();
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
	 * Register People hub rewrite rules.
	 *
	 * @return void
	 */
	private function register_people_rules(): void {
		$p = self::hub_slug( 'buddynext_slug_people', 'members' );

		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/edit/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=edit',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/connections/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=connections',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/media/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=media',
			'top'
		);
		add_rewrite_rule(
			'^' . preg_quote( $p, '/' ) . '/([^/]+)/badges/?$',
			'index.php?bn_hub=people&bn_user_slug=$matches[1]&bn_profile_action=badges',
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

		add_rewrite_rule(
			'^' . preg_quote( $n, '/' ) . '/?$',
			'index.php?bn_hub=notifications',
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
		$a = self::hub_slug( 'buddynext_slug_auth', 'login' );

		add_rewrite_rule(
			'^' . preg_quote( $a, '/' ) . '/?$',
			'index.php?bn_hub=auth',
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
		if ( $user_id <= 0 ) {
			return self::people_url();
		}

		$base = self::people_url();

		$custom_slug = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom_slug ) {
			return $base . rawurlencode( $custom_slug ) . '/connections/';
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof WP_User && '' !== $user->user_nicename ) {
			return $base . rawurlencode( $user->user_nicename ) . '/connections/';
		}

		return $base . 'user-' . $user_id . '/connections/';
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
