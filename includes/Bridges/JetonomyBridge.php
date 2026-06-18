<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Jetonomy bridge.
 *
 * Routes Jetonomy events into BuddyNext surfaces:
 *
 * - Discussion created → bn_search_index (type: discussion) + @mention parsing
 * - Discussion created → BN feed activity (engagement; link card to Jetonomy,
 *   via Feed\IntegrationActivity; filter buddynext_jetonomy_discussion_activity)
 * - Discussion deleted → removes the search entry + the feed activity
 * - Reply notifications are handled by JetonomyBridgeListener (jetonomy_after_create_reply)
 * - Unified nav: BuddyNext subnav injected on all Jetonomy pages (jetonomy_before_content);
 *   Jetonomy's own community nav suppressed (jetonomy_show_community_nav → false)
 * - Space Discussions tab (linked or on-demand forum) + profile Discussions count
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Search\SearchService;
use BuddyNext\Feed\IntegrationActivity;

/**
 * Jetonomy ↔ BuddyNext integration layer.
 */
class JetonomyBridge {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 * Bails when Jetonomy is not active so no hooks are wasted on other sites.
	 */
	public function init(): void {
		if ( ! class_exists( 'Jetonomy\Jetonomy' ) ) {
			return;
		}

		// jetonomy_after_create_post fires ($post_id, $space_id) — 2 args only.
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 10, 2 );

		// jetonomy_post_deleted fires ($post_id, $space_id, $user_id) — 3 args.
		add_action( 'jetonomy_post_deleted', array( $this, 'on_post_deleted' ), 10, 3 );

		// Inject a Discussions link into the BuddyNext left navigation rail.
		add_filter( 'buddynext_rail_items', array( $this, 'inject_discussions_nav_item' ) );

		// Bridge: hashtag ↔ tag — when BuddyNext renders a hashtag feed, pull
		// related Jetonomy discussions that share the same tag slug.
		add_filter( 'buddynext_hashtag_related_discussions', array( $this, 'get_related_discussions' ), 10, 2 );

		// Level 2 context nav: Discussion sub-pages (Home / Search / Leaderboard).
		add_filter( 'buddynext_context_nav', array( $this, 'inject_discussion_context_nav' ), 10, 2 );

		// Jetonomy pages (e.g. /community/) render as the plugin's own default —
		// BuddyNext does not inject its nav/wrapper or suppress Jetonomy's own
		// navigation. (Owner rule: BN must not touch Jetonomy pages.) The link
		// INTO discussions lives on BuddyNext's own rail (inject_discussions_nav_item).

		// Cross-plugin notifications: JT reply → BN notification for post author.
		add_action( 'jetonomy_after_create_reply', array( $this, 'notify_discussion_reply' ), 10, 2 );

		// Inject a Discussions tab into BuddyNext spaces that have a linked Jetonomy forum.
		add_filter( 'buddynext_space_tabs', array( $this, 'inject_space_forum_tab' ), 10, 2 );

		// Inject a Discussions stat block into BuddyNext user profiles.
		add_filter( 'buddynext_profile_extra_data', array( $this, 'inject_profile_discussion_count' ), 10, 2 );

		// On-demand space forum: provision + redirect when a member first opens a
		// forumless space's Discussions tab (web).
		// Priority 5 so the on-demand forum provision/redirect runs before
		// PageRouter::dispatch_hub_template (template_redirect:10) renders the
		// spaces directory and exits — otherwise the Discussions tab URL
		// (/spaces/?bn_provision_forum=N) shows the directory instead.
		add_action( 'template_redirect', array( $this, 'maybe_provision_and_redirect' ), 5 );

		// App coverage: REST to provision/fetch a space's forum URL.
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Messaging is BuddyNext's native domain. When BN messaging is available,
		// suppress Jetonomy Pro's private-messaging extension on the front end and
		// REST: it registers /messages/ rewrite rules + a template_redirect at
		// priority 1 that hijacks BN's own /messages/ route, which made the Message
		// action non-functional whenever Jetonomy was active. Filtered at read time
		// (option_jetonomy_pro_extensions) so nothing is persisted and it reverts
		// automatically if BN messaging is disabled. Left untouched in wp-admin so
		// the Jetonomy extensions screen still reflects/saves the real setting.
		add_filter( 'option_jetonomy_pro_extensions', array( $this, 'suppress_jetonomy_messaging' ) );
	}

	/**
	 * Drop Jetonomy Pro's private-messaging extension from the active list when
	 * BuddyNext messaging is available, so BN owns the /messages/ route.
	 *
	 * @param mixed $enabled Stored jetonomy_pro_extensions option value.
	 * @return mixed Filtered list (array) with 'private-messaging' removed, or the
	 *               original value untouched in wp-admin / when BN messaging is off.
	 */
	public function suppress_jetonomy_messaging( $enabled ) {
		if ( ! is_array( $enabled ) || is_admin() ) {
			return $enabled;
		}
		if ( ! class_exists( '\BuddyNext\Messages\MessagesData' )
			|| ! \BuddyNext\Messages\MessagesData::available() ) {
			return $enabled;
		}
		return array_values( array_diff( $enabled, array( 'private-messaging' ) ) );
	}

	/**
	 * Index a Jetonomy discussion in bn_search_index, parse @mentions, and
	 * optionally push a feed entry when the feed sync option is enabled.
	 *
	 * Hooked on: jetonomy_after_create_post( int $post_id, int $space_id )
	 *
	 * Note: Jetonomy fires only 2 args — post_id and space_id. Author, title,
	 * and content are fetched from jt_posts to avoid relying on a wider signature
	 * that may never ship.
	 *
	 * @param int $post_id  Jetonomy discussion ID (jt_posts.id).
	 * @param int $space_id Jetonomy space ID the discussion belongs to.
	 */
	public function on_post_created( int $post_id, int $space_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT author_id, title, content_plain, is_private, status FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $post ) {
			return;
		}

		$author_id = (int) $post->author_id;
		$title     = (string) $post->title;
		$content   = (string) $post->content_plain;

		// Always-on: index for BuddyNext unified search.
		( new SearchService() )->index( 'discussion', $post_id, $title, $content, $author_id, 'public', $space_id );

		// Always-on: parse @username mentions from the discussion body.
		preg_match_all( '/@([a-zA-Z0-9_-]+)/', $content, $matches );
		foreach ( $matches[1] as $raw_username ) {
			$username       = sanitize_user( (string) $raw_username, true );
			$mentioned_user = get_user_by( 'login', $username );
			if ( $mentioned_user instanceof \WP_User ) {
				/**
				 * Fires when a user is @mentioned in a Jetonomy forum post.
				 *
				 * Matches NotificationListener::on_user_mentioned( int, int, int ):
				 * the third argument is the context id (the post the mention is in),
				 * not a context slug — passing a string here threw a TypeError and
				 * 500'd the reply/post request.
				 *
				 * @param int $mentioned_user_id ID of the user who was mentioned.
				 * @param int $mentioner_id      ID of the user who wrote the post.
				 * @param int $context_id        Jetonomy post ID containing the mention.
				 */
				do_action( 'buddynext_user_mentioned', $mentioned_user->ID, $author_id, $post_id );
			}
		}

		// Single source of truth: a Jetonomy topic in a connected PUBLIC space
		// becomes a `discussion` activity in bn_posts, so the feed + Explore show
		// it like any other activity (one feed, one data source). Sync is ON by
		// default whenever Jetonomy is active (this bridge only loads then), and
		// the owner can still flip it off via Integrations → "Jetonomy Feed Sync".
		//
		// Privacy gate: only PUBLIC spaces, public (non-private) topics, and
		// published posts produce a public activity — a private/secret space or a
		// private topic must never leak into the public heartbeat.
		$is_public_discussion = $this->is_public_discussion( $space_id, (int) $post->is_private, (string) $post->status );

		if ( $is_public_discussion
			&& '0' !== (string) get_option( 'buddynext_jetonomy_feed_sync', '1' )
			&& (bool) apply_filters( 'buddynext_jetonomy_discussion_activity', true, $post_id ) ) {
			$url = $this->discussion_url( $post_id, $space_id );
			if ( '' !== $url ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 30, '…' );
				IntegrationActivity::publish( $author_id, __( 'started a discussion', 'buddynext' ), $url, $title, 'discussion', $excerpt );
			}
		}

		/**
		 * Fires after a Jetonomy discussion is indexed in BuddyNext search.
		 *
		 * Third-party code (e.g. per-space feed sync toggle) can hook here to push
		 * the discussion into bn_posts for a specific space when enabled.
		 *
		 * @param int    $post_id   Discussion ID.
		 * @param int    $space_id  Jetonomy space ID.
		 * @param int    $author_id Author user ID.
		 * @param string $title     Discussion title.
		 * @param string $content   Discussion content (plain text).
		 */
		do_action( 'buddynext_jetonomy_post_indexed', $post_id, $space_id, $author_id, $title, $content );
	}

	/**
	 * Remove a deleted Jetonomy discussion from BuddyNext surfaces.
	 *
	 * Hooked on: jetonomy_post_deleted( int $post_id, int $space_id, int $user_id )
	 *
	 * Deletes the bn_search_index entry and the discussion's feed activity.
	 *
	 * @param int $post_id  Jetonomy discussion ID.
	 * @param int $space_id Jetonomy space ID (used to rebuild the discussion URL).
	 * @param int $_user_id User who deleted the discussion (unused — kept for hook signature).
	 */
	public function on_post_deleted( int $post_id, int $space_id, int $_user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_user_id kept for the hook signature.
		global $wpdb;

		// Remove from search index. Jetonomy "delete" is a soft-delete (status →
		// trash), so the jt_posts/jt_spaces rows still exist and the URL resolves.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => 'discussion',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Remove the feed activity for this discussion.
		$url = $this->discussion_url( $post_id, $space_id );
		if ( '' !== $url ) {
			IntegrationActivity::remove( $url, 'discussion' );
		}
	}

	/**
	 * Whether a Jetonomy topic should surface as a PUBLIC BuddyNext activity.
	 *
	 * True only when the topic is published, not flagged private, and lives in a
	 * space whose Jetonomy visibility is `public`. Private/secret spaces and
	 * private topics return false so they never leak into the public feed/Explore.
	 *
	 * @param int    $space_id         Jetonomy space ID.
	 * @param int    $is_private_topic jt_posts.is_private (1 = private).
	 * @param string $status           jt_posts.status (expects 'publish').
	 * @return bool
	 */
	private function is_public_discussion( int $space_id, int $is_private_topic, string $status ): bool {
		if ( 0 !== $is_private_topic ) {
			return false;
		}
		if ( '' !== $status && 'publish' !== $status ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$visibility = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT visibility FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $space_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return 'public' === $visibility;
	}

	/**
	 * Build the public URL of a Jetonomy discussion (`base/s/{space}/t/{post}/`).
	 *
	 * Reads the post + space slugs from jt_posts/jt_spaces (Jetonomy fires the
	 * create/delete hooks with ids only). Returns '' when either slug is missing.
	 *
	 * @param int $post_id  jt_posts id.
	 * @param int $space_id jt_spaces id.
	 * @return string
	 */
	private function discussion_url( int $post_id, int $space_id ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_slug  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $post_id ) );
		$space_slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $space_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' === $post_slug || '' === $space_slug ) {
			return '';
		}

		$base = function_exists( 'Jetonomy\base_url' ) ? (string) \Jetonomy\base_url() : home_url( '/community' );

		return rtrim( $base, '/' ) . '/s/' . rawurlencode( $space_slug ) . '/t/' . rawurlencode( $post_slug ) . '/';
	}

	/**
	 * Inject a Discussions link into the BuddyNext left navigation rail.
	 *
	 * Appends a public "Discussions" rail item pointing to the Jetonomy community
	 * home. The `active` flag is computed from the current REQUEST_URI and honoured
	 * wherever the rail renders (BuddyNext's own hubs); on the forum's own pages the
	 * rail is replaced by the Level-2 context sub-nav, so the flag is set defensively.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_discussions_nav_item( array $items ): array {
		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = isset( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community';

		// Derive the forum base path from home_url() so subdirectory installs work.
		$forum_url  = home_url( '/' . $base_slug . '/' );
		$forum_path = (string) ( wp_parse_url( $forum_url, PHP_URL_PATH ) ?? '/' . $base_slug . '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = str_starts_with( $request_uri, $forum_path );

		$items[] = array(
			'key'    => 'discussions',
			'label'  => __( 'Discussions', 'buddynext' ),
			'url'    => $forum_url,
			'icon'   => 'list',
			'show'   => true,
			'active' => $is_active,
		);

		return $items;
	}

	/**
	 * Inject a Discussions tab into BuddyNext space navigation (always shown).
	 *
	 * If the space already has a linked Jetonomy forum, the tab links straight to
	 * it. Otherwise the tab points at the on-demand provision trigger, so the
	 * forum is created + linked the first time a member opens Discussions (no
	 * empty forums, no admin friction).
	 *
	 * Hooked on: buddynext_space_tabs( array $tabs, int $space_id )
	 *
	 * @param array<string, string|array<string,string>> $tabs     Existing tab map (key → label or ['label','url']).
	 * @param int                                        $space_id BuddyNext space ID.
	 * @return array<string, string|array<string,string>>
	 */
	public function inject_space_forum_tab( array $tabs, int $space_id ): array {
		$forum_url = $this->space_forum_url( $space_id );

		$tabs['discussions'] = array(
			'label' => __( 'Discussions', 'buddynext' ),
			'url'   => '' !== $forum_url
				? $forum_url
				: add_query_arg( 'bn_provision_forum', $space_id, home_url( '/spaces/' ) ),
		);

		return $tabs;
	}

	/**
	 * Resolve the public URL of a BuddyNext space's linked Jetonomy forum.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string Forum URL, or '' when no forum is linked yet.
	 */
	private function space_forum_url( int $space_id ): string {
		$forum_id = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
		if ( $forum_id <= 0 ) {
			return '';
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jt_slug = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( '' === $jt_slug ) {
			return '';
		}

		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = isset( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community';

		return home_url( '/' . $base_slug . '/s/' . rawurlencode( $jt_slug ) . '/' );
	}

	/**
	 * Provision (once) a Jetonomy forum for a BuddyNext space and link it.
	 *
	 * Idempotent: returns the existing forum id when already linked. Creates a
	 * paired jt_space named after the BN space (Jetonomy's Space::create) and
	 * stores the `bn_space_{id}_jetonomy_forum_id` link option.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int Jetonomy forum (jt_spaces) id, or 0 on failure.
	 */
	public function provision_space_forum( int $space_id ): int {
		$existing = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
		if ( $existing > 0 ) {
			return $existing;
		}

		if ( ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return 0;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return 0;
		}

		$forum_id = (int) \Jetonomy\Models\Space::create(
			array(
				'title'      => (string) ( $space['name'] ?? '' ),
				'slug'       => (string) ( $space['slug'] ?? '' ),
				'visibility' => 'public',
				'status'     => 'active',
			),
			(int) ( $space['owner_id'] ?? 0 )
		);

		if ( $forum_id > 0 ) {
			update_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', $forum_id, false );
		}

		return $forum_id;
	}

	/**
	 * On-demand web flow: provision the forum for `?bn_provision_forum={space}` and
	 * redirect to it. Fired on template_redirect.
	 *
	 * @return void
	 */
	public function maybe_provision_and_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only provision trigger from a tab link.
		$space_id = isset( $_GET['bn_provision_forum'] ) ? absint( wp_unslash( $_GET['bn_provision_forum'] ) ) : 0;
		// Provisioning mutates the space (creates its forum), so it must be gated
		// to the space's owner/moderator (or a site admin) — not any logged-in
		// user, who could otherwise provision a forum on any space by id.
		if ( ! $this->can_provision_forum( $space_id, get_current_user_id() ) ) {
			return;
		}

		$this->provision_space_forum( $space_id );
		$url = $this->space_forum_url( $space_id );
		if ( '' !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Whether a user may provision (create) a space's forum.
	 *
	 * Gated on buddynext-moderate-space (space owner/moderator, plus site admins
	 * via the manage_options passthrough) so provisioning — which mutates the
	 * space — cannot be triggered by an arbitrary logged-in user.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  Acting user ID.
	 * @return bool
	 */
	private function can_provision_forum( int $space_id, int $user_id ): bool {
		if ( $space_id <= 0 || $user_id <= 0 ) {
			return false;
		}
		return (bool) buddynext_service( 'permissions' )->can( $user_id, 'buddynext-moderate-space', array( 'space_id' => $space_id ) );
	}

	/**
	 * REST permission gate for POST /spaces/{id}/forum.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function rest_provision_permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}
		$space_id = (int) $request['id'];
		if ( ! $this->can_provision_forum( $space_id, get_current_user_id() ) ) {
			return new \WP_Error( 'rest_forbidden', __( 'You do not have permission to set up this space forum.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Register the space-forum REST route (app coverage).
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/forum',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_provision_forum' ),
				'permission_callback' => array( $this, 'rest_provision_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST: provision (or fetch) a space's forum and return its URL — for the app.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_provision_forum( \WP_REST_Request $request ): \WP_REST_Response {
		$space_id = (int) $request['id'];
		$forum_id = $this->provision_space_forum( $space_id );

		return new \WP_REST_Response(
			array(
				'forum_id'  => $forum_id,
				'forum_url' => $forum_id > 0 ? $this->space_forum_url( $space_id ) : '',
			),
			200
		);
	}

	/**
	 * Inject a Discussions stat block into BuddyNext user profiles.
	 *
	 * Counts published Jetonomy discussions authored by the profile user and
	 * appends a stat entry so the number shows in the profile header stat row.
	 *
	 * Hooked on: buddynext_profile_extra_data( array $extra, int $user_id )
	 *
	 * @param array<int, array{label: string, value: string|int}> $extra           Existing extra stat entries.
	 * @param int                                                 $profile_user_id ID of the user whose profile is being viewed.
	 * @return array<int, array{label: string, value: string|int}>
	 */
	public function inject_profile_discussion_count( array $extra, int $profile_user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE author_id = %d AND status = 'publish'",
				$profile_user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Carry the tab hint so the stat pill jumps to the in-page Discussions
		// tab on click (same affordance as the core Posts/Followers stats),
		// instead of sitting as dead static text next to a clickable tab. The
		// Discussions tab is present whenever this bridge is booted.
		$extra[] = array(
			'label'       => __( 'Discussions', 'buddynext' ),
			'value'       => $count,
			'wp_on_click' => 'actions.setTab',
			'data_tab'    => 'discussions',
		);

		return $extra;
	}

	/**
	 * Create a BuddyNext notification when someone replies to a Jetonomy discussion.
	 *
	 * @param int $reply_id Jetonomy reply ID.
	 * @param int $space_id Jetonomy space ID.
	 */
	public function notify_discussion_reply( int $reply_id, int $space_id ): void {
		if ( ! class_exists( 'Jetonomy\Models\Reply' ) || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return;
		}

		$reply = \Jetonomy\Models\Reply::find( $reply_id );
		if ( ! $reply ) {
			return;
		}

		$post = \Jetonomy\Models\Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return;
		}

		$reply_author_id = (int) $reply->author_id;
		$post_author_id  = (int) $post->author_id;

		// Don't notify yourself.
		if ( $reply_author_id === $post_author_id || 0 === $post_author_id ) {
			return;
		}

		$replier = get_userdata( $reply_author_id );
		$replier_name = $replier ? $replier->display_name : __( 'Someone', 'buddynext' );

		( new \BuddyNext\Notifications\NotificationService() )->create(
			array(
				'recipient_id' => $post_author_id,
				'sender_id'    => $reply_author_id,
				'type'         => 'bn.jetonomy_reply',
				'object_type'  => 'jetonomy_post',
				'object_id'    => (int) $reply->post_id,
				'message'      => sprintf(
					/* translators: %s: replier name */
					__( '%s replied to your discussion', 'buddynext' ),
					$replier_name
				),
			)
		);
	}

	/**
	 * Inject Discussion context nav items (Home / Search / Leaderboard).
	 *
	 * Only fires when the main nav section is "discussions".
	 *
	 * @param array  $items   Existing context nav items.
	 * @param string $section Current active section.
	 * @return array
	 */
	public function inject_discussion_context_nav( array $items, string $section ): array {
		if ( 'discussions' !== $section ) {
			return $items;
		}

		$base        = function_exists( 'Jetonomy\base_url' ) ? \Jetonomy\base_url() : home_url( '/community' );
		$current_url = home_url( add_query_arg( array() ) );

		$items[] = array(
			'label'  => __( 'Home', 'buddynext' ),
			'url'    => $base . '/',
			'active' => trailingslashit( $current_url ) === trailingslashit( $base . '/' ),
		);
		$items[] = array(
			'label'  => __( 'Search', 'buddynext' ),
			'url'    => $base . '/search/',
			'active' => false !== strpos( $current_url, '/search/' ),
		);
		$items[] = array(
			'label'  => __( 'Leaderboard', 'buddynext' ),
			'url'    => $base . '/leaderboard/',
			'active' => false !== strpos( $current_url, '/leaderboard/' ),
		);

		return $items;
	}

	/**
	 * Filter callback: return Jetonomy discussions tagged with the given slug.
	 *
	 * Hooked to `buddynext_hashtag_related_discussions` so the hashtag feed
	 * template can display "Related Discussions" from Jetonomy forums.
	 *
	 * @param array  $discussions Existing discussions array (empty by default).
	 * @param string $hashtag_slug The hashtag/tag slug to search for.
	 * @return array Array of discussion objects with id, title, post_slug, author_name, reply_count, vote_score, created_at.
	 */
	public function get_related_discussions( array $discussions, string $hashtag_slug ): array {
		if ( ! class_exists( 'Jetonomy\Models\Tag' ) ) {
			return $discussions;
		}

		if ( ! \Jetonomy\Models\Tag::exists( $hashtag_slug ) ) {
			return $discussions;
		}

		$jt_posts = \Jetonomy\Models\Tag::list_by_tag( $hashtag_slug, 5 );

		foreach ( $jt_posts as $jt_post ) {
			// Resolve the public discussion URL here (the bridge owns Jetonomy
			// table access) so the hashtag template never queries jt_* itself.
			$jt_url = $this->discussion_url( (int) $jt_post->id, (int) $jt_post->space_id );

			$discussions[] = array(
				'id'          => (int) $jt_post->id,
				'title'       => $jt_post->title,
				'slug'        => $jt_post->post_slug,
				'space_id'    => (int) $jt_post->space_id,
				'url'         => '' !== $jt_url ? $jt_url : home_url( '/community/' ),
				'author_id'   => (int) $jt_post->author_id,
				'author_name' => $jt_post->author_name,
				'reply_count' => (int) $jt_post->reply_count,
				'vote_score'  => (int) $jt_post->vote_score,
				'created_at'  => $jt_post->created_at,
				'source'      => 'jetonomy',
			);
		}

		return $discussions;
	}
}
