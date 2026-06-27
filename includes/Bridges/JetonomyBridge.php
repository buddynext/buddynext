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
		add_action( 'jetonomy_after_create_reply', array( $this, 'notify_discussion_reply' ), 10, 1 );

		// Register Discussions on BOTH the member-profile and space nav surfaces via
		// the unified Nav API (one registry, one renderer) — profile tab carries a
		// count badge; the space tab links to the forum (or the on-demand provision
		// trigger). Replaces the old buddynext_profile_extra_data + buddynext_space_tabs.
		add_action( 'buddynext_register_nav', array( $this, 'register_nav_items' ) );

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

		// Always-on: parse @username mentions from the discussion body. Collect the
		// unique logins first, then resolve them all in ONE query — the previous
		// get_user_by('login') per match was an N+1 (and fired a duplicate
		// notification when the same user was mentioned twice). number caps a
		// pathological mention flood.
		preg_match_all( '/@([a-zA-Z0-9_-]+)/', $content, $matches );
		$mention_logins = array();
		foreach ( $matches[1] as $raw_username ) {
			$username = sanitize_user( (string) $raw_username, true );
			if ( '' !== $username ) {
				$mention_logins[ $username ] = true;
			}
		}

		if ( ! empty( $mention_logins ) ) {
			$mentioned_ids = get_users(
				array(
					'login__in' => array_keys( $mention_logins ),
					'fields'    => 'ID',
					'number'    => 100,
				)
			);
			foreach ( $mentioned_ids as $mentioned_id ) {
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
				do_action( 'buddynext_user_mentioned', (int) $mentioned_id, $author_id, $post_id );
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
	 * Inject a person-specific Discussions link into the BuddyNext left rail.
	 *
	 * Appends a "Discussions" rail item pointing at the logged-in member's OWN
	 * profile Discussions tab (their authored discussions), not the global forum
	 * home — a "my discussions" shortcut. Hidden for guests. The `active` flag is
	 * computed from the current REQUEST_URI against that profile-tab path.
	 *
	 * Hooked on: buddynext_rail_items( array $items, string $hub )
	 *
	 * @param array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}> $items Existing rail items.
	 * @return array<int, array{key: string, label: string, url: string, icon: string, show: bool, active?: bool}>
	 */
	public function inject_discussions_nav_item( array $items ): array {
		// Person-specific: the rail "Discussions" link points the viewer at their
		// OWN profile Discussions tab (the in-hub panel listing the discussions they
		// authored), not the global forum landing page. Only shown when logged in —
		// there is no "my discussions" for a guest.
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return $items;
		}

		$disc_url  = trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $uid ) ) . 'discussions/';
		$disc_path = rtrim( (string) ( wp_parse_url( $disc_url, PHP_URL_PATH ) ?? '' ), '/' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$is_active   = '' !== $disc_path && str_starts_with( rtrim( $request_uri, '/' ), $disc_path );

		$items[] = array(
			'key'    => 'discussions',
			'label'  => __( 'Discussions', 'buddynext' ),
			'url'    => $disc_url,
			'icon'   => 'list',
			'show'   => true,
			'active' => $is_active,
			// Personal "You" group — it is the viewer's own discussions, so it sits
			// with Profile / Media / Bookmarks, not in the community group up top.
			'group'  => 'you',
			'order'  => 206,
		);

		return $items;
	}

	/**
	 * Resolve the public URL of a BuddyNext space's linked Jetonomy forum.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string Forum URL, or '' when no forum is linked yet.
	 */
	private function space_forum_url( int $space_id ): string {
		$forum_id = $this->forum_id_for_space( $space_id );
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified immediately below before any state change.
		$space_id = isset( $_GET['bn_provision_forum'] ) ? absint( wp_unslash( $_GET['bn_provision_forum'] ) ) : 0;
		if ( $space_id <= 0 ) {
			return;
		}

		// CSRF guard: this GET handler MUTATES the space (creates its forum), so it
		// must carry the per-space nonce the Discussions tab link embeds. Capability
		// alone does not stop CSRF — a moderator could be tricked into loading a
		// forged URL. Verify the nonce before anything else.
		$bn_nonce = isset( $_GET['_bnpf'] ) ? sanitize_text_field( wp_unslash( $_GET['_bnpf'] ) ) : '';
		if ( ! wp_verify_nonce( $bn_nonce, 'bn_provision_forum_' . $space_id ) ) {
			return;
		}

		// Provisioning is also gated to the space's owner/moderator (or a site
		// admin) — not any logged-in user, who could otherwise provision a forum on
		// any space by id.
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
	 * Register the Discussions tab on the profile AND space nav surfaces.
	 *
	 * Hooked on `buddynext_register_nav`. Profile tab carries a lazy count badge of
	 * the member's published discussions; the space tab is a clean link to the
	 * space's forum (or the on-demand provision trigger). Both gated on Jetonomy.
	 *
	 * @param \BuddyNext\Nav\NavRegistry $registry The shared nav registry.
	 * @return void
	 */
	public function register_nav_items( \BuddyNext\Nav\NavRegistry $registry ): void {
		$jetonomy_active = static fn(): bool => class_exists( 'Jetonomy\Jetonomy' );

		// Profile: a primary tab owning the member's discussions panel.
		$registry->register(
			array(
				'id'        => 'discussions',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Discussions', 'buddynext' ),
				'tab'       => 'discussions',
				'icon'      => 'message-square',
				'priority'  => 60,
				'condition' => $jetonomy_active,
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => $this->discussion_count( $c->subject_id ),
			)
		);

		// Space: a primary tab owning the space's discussions panel in-hub — the
		// counterpart to the profile Discussions tab. URL-only like every other
		// space tab (clean /spaces/{slug}/discussions/ link rendered as a real <a>,
		// NOT a reactive in-page tab): the space surface server-renders one panel
		// per clean URL rather than pre-rendering all of them. The panel lists the
		// linked forum's threads and links out to Jetonomy (full-load, deny-listed)
		// for reading/posting; the no-forum empty state offers the on-demand
		// provision trigger. Count badges the linked forum's published thread total.
		$registry->register(
			array(
				'id'        => 'discussions',
				'surface'   => 'space',
				'layer'     => 'primary',
				'label'     => __( 'Discussions', 'buddynext' ),
				'icon'      => 'message-square',
				'priority'  => 35,
				'condition' => $jetonomy_active,
				'url'       => function ( \BuddyNext\Nav\NavContext $c ): string {
					return trailingslashit( \BuddyNext\Core\PageRouter::space_url( $c->subject_id ) ) . 'discussions/';
				},
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => $this->space_discussion_count( $c->subject_id ),
				'render'    => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_space_discussions_panel( $c->subject_id, $c->viewer_id );
				},
			)
		);
	}

	/**
	 * Render the space Discussions panel — the registry content seam for the space
	 * Discussions tab. Self-contained: the bridge owns all jt_* access, so it
	 * resolves the linked forum's threads + the forum/provision context + the
	 * viewer's posting permission from just the space id + viewer, then renders the
	 * shared discussions part. This replaces the old hardcoded spaces/home.php
	 * branch that instantiated the bridge directly, so the space tab and its panel
	 * are now declared together like every other registry tab.
	 *
	 * @param int $space_id Space ID.
	 * @param int $viewer_id Current viewer user ID (0 = logged out).
	 * @return void
	 */
	public function render_space_discussions_panel( int $space_id, int $viewer_id ): void {
		$space = ( new \BuddyNext\Spaces\SpaceService() )->get_object( $space_id );
		if ( null === $space ) {
			return;
		}

		// Posting permission mirrors the feed composer gate: an active member, on a
		// non-archived space, whose role meets the space's "who can post" threshold.
		$status    = $viewer_id > 0 ? (string) ( new \BuddyNext\Spaces\SpaceMemberService() )->get_status( $space_id, $viewer_id ) : '';
		$is_member = 'active' === $status;
		$can_post  = $is_member
			&& empty( $space->is_archived )
			&& \BuddyNext\Spaces\SpacePostGuard::can_post( $space_id, $viewer_id );

		$forum_ctx = $this->space_forum_context( $space_id );

		buddynext_get_template(
			'parts/space-discussions-panel.php',
			array(
				'space'         => $space,
				'discussions'   => $this->space_discussions( $space_id, 20 ),
				'forum_url'     => (string) $forum_ctx['forum_url'],
				'forum_linked'  => (bool) $forum_ctx['linked'],
				'provision_url' => (string) $forum_ctx['provision_url'],
				'can_post'      => $can_post,
			)
		);
	}

	/**
	 * Count a member's published Jetonomy discussions (the bridge owns jt_* access).
	 *
	 * @param int $user_id Discussion author ID.
	 * @return int
	 */
	public function discussion_count( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE author_id = %d AND status = 'publish'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * List a user's published Jetonomy discussions, newest first, joined to
	 * their space for the name/slug. Powers the profile "Discussions" tab so the
	 * template never reaches into jt_* tables itself (the bridge owns that access).
	 *
	 * @param int $user_id Discussion author ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return object[] Each row: id, title, slug, reply_count, vote_score, created_at, space_name, space_slug.
	 */
	public function user_discussions( int $user_id, int $limit = 20 ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        s.title AS space_name, s.slug AS space_slug
				 FROM {$wpdb->prefix}jt_posts p
				 LEFT JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				 WHERE p.author_id = %d AND p.status = 'publish'
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Resolve the Jetonomy forum (jt_spaces) id linked to a BuddyNext space.
	 *
	 * The link option stores the Jetonomy forum id — jt_posts.space_id is that
	 * forum id, NOT the BN space id, so every jt_posts query keyed by space must
	 * map through here first. Returns 0 when no forum has been provisioned yet.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int Jetonomy forum id, or 0 when unlinked.
	 */
	private function forum_id_for_space( int $space_id ): int {
		$space_id = absint( $space_id );
		if ( $space_id <= 0 ) {
			return 0;
		}
		return (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
	}

	/**
	 * List a space's published Jetonomy discussions, newest first, joined to the
	 * author for the display name/login. Powers the in-hub space "Discussions" tab
	 * so the template never reaches into jt_* tables itself (the bridge owns that
	 * access) — the space counterpart to user_discussions(). Takes a BuddyNext
	 * space id and maps to the linked Jetonomy forum internally.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @param int $limit    Max rows (1-50). Default 20.
	 * @return object[] Each row: id, title, slug, reply_count, vote_score, created_at, author_id, author_name, author_login.
	 */
	public function space_discussions( int $space_id, int $limit = 20 ): array {
		if ( ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return array();
		}
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.title, p.slug, p.reply_count, p.vote_score, p.created_at,
				        p.author_id, u.display_name AS author_name, u.user_login AS author_login
				 FROM {$wpdb->prefix}jt_posts p
				 LEFT JOIN {$wpdb->users} u ON u.ID = p.author_id
				 WHERE p.space_id = %d AND p.status = 'publish'
				 ORDER BY p.created_at DESC
				 LIMIT %d",
				$forum_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count a space's published Jetonomy discussions (the bridge owns jt_* access).
	 * Takes a BuddyNext space id and maps to the linked Jetonomy forum internally.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int
	 */
	public function space_discussion_count( int $space_id ): int {
		if ( ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return 0;
		}
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish'",
				$forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Build the on-demand provision-trigger URL for a space's forum. Visiting it
	 * (handled on template_redirect) creates the forum the first time and redirects
	 * to it — so no empty forums exist until a member actually opens Discussions.
	 * Carries a per-space nonce because the GET handler mutates state (CSRF guard).
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return string
	 */
	private function provision_forum_url( int $space_id ): string {
		return add_query_arg(
			array(
				'bn_provision_forum' => absint( $space_id ),
				'_bnpf'              => wp_create_nonce( 'bn_provision_forum_' . absint( $space_id ) ),
			),
			home_url( '/spaces/' )
		);
	}

	/**
	 * Display bundle for the in-hub space Discussions panel: the linked forum URL
	 * (full-load, deny-listed) for "open in community", whether a forum is linked
	 * yet, and the provision-trigger URL for the no-forum empty state. Keeps the
	 * template free of jt_* tables, link options, and nonce plumbing.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return array{forum_url:string,linked:bool,provision_url:string}
	 */
	public function space_forum_context( int $space_id ): array {
		return array(
			'forum_url'     => $this->space_forum_url( $space_id ),
			'linked'        => $this->forum_id_for_space( $space_id ) > 0,
			'provision_url' => $this->provision_forum_url( $space_id ),
		);
	}

	/**
	 * Create a BuddyNext notification when someone replies to a Jetonomy discussion.
	 *
	 * The space context is resolved from the reply's parent post, so the hook's
	 * second argument is not needed and is not requested (accepted_args = 1).
	 *
	 * @param int $reply_id Jetonomy reply ID.
	 */
	public function notify_discussion_reply( int $reply_id ): void {
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

		$replier      = get_userdata( $reply_author_id );
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
