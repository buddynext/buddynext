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
 * - Reply / mention / accepted-answer notifications are mirrored for display only by
 *   JetonomyBridgeListener (from jetonomy_notification_created); Jetonomy owns the row
 *   text and the email, so BN never creates a second row or emails on its behalf
 * - Unified nav: BuddyNext subnav injected on all Jetonomy pages (jetonomy_before_content);
 *   Jetonomy's own community nav suppressed (jetonomy_show_community_nav → false)
 * - Space Discussions tab (linked or on-demand forum) + profile Discussions count
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Profile\Handle;
use BuddyNext\Search\SearchService;
use BuddyNext\Feed\IntegrationActivity;
use BuddyNext\Feed\PostService;

/**
 * Jetonomy ↔ BuddyNext integration layer.
 */
class JetonomyBridge {

	/**
	 * Object-cache group for the bridge's per-view count/list reads.
	 */
	private const CACHE_GROUP = 'buddynext_jetonomy';

	/**
	 * Cache TTL (seconds). A hard staleness bound even if an invalidation hook is
	 * ever missed; the create/delete hooks clear the affected keys immediately.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Re-entrancy guard for the two-way discussion sync.
	 *
	 * Set true while this bridge mirrors a comment into a forum reply (or a reply
	 * into a comment). The reciprocal hook fires synchronously during that write;
	 * seeing the flag, its handler returns immediately, so the mirror never loops.
	 *
	 * Raise it only through mirror(), never by hand — see that method for why.
	 *
	 * It is also an IN-REQUEST guard. It cannot protect a cycle that crosses a
	 * request boundary, so if any leg of this sync is ever moved to a queue, a
	 * cron tick or a webhook, the flag stops working and the pair record
	 * (bn_comments.sync_reply_id) has to carry the guard instead: write the
	 * counterpart id first, and bail when it is already set.
	 *
	 * @var bool
	 */
	private static bool $syncing = false;

	/**
	 * Perform a cross-boundary write with the echo guard raised, and lower it
	 * whatever happens.
	 *
	 * The guard being static means its blast radius is the PHP process, not the
	 * function. If anything throws between raising and lowering it, every later
	 * mirror in that process is silently skipped — no error, no log entry, just
	 * replies that stop appearing in the feed. That failure is invisible in
	 * testing because a request mirroring ONE comment has no second comment to
	 * lose; it shows up in bulk actions, imports and CLI runs, where nobody
	 * looks.
	 *
	 * The window deliberately contains third-party code — the callbacks re-fire
	 * the far side's own hooks so its notifications and counters still run — so
	 * it must never depend on the body completing. `finally` also covers any
	 * early return added to a callback later.
	 *
	 * @param callable $write The cross-boundary write.
	 * @return void
	 */
	private static function mirror( callable $write ): void {
		self::$syncing = true;
		try {
			$write();
		} finally {
			self::$syncing = false;
		}
	}

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

		// Register Discussions on BOTH the member-profile and space nav surfaces via
		// the unified Nav API (one registry, one renderer) — profile tab carries a
		// count badge; the space tab links to the forum (or the on-demand provision
		// trigger). Replaces the old buddynext_profile_extra_data + buddynext_space_tabs.
		// A space's type is the authority for its discussion's visibility, so
		// follow it whenever the space is saved. Creation-time correctness
		// alone would let the leak back in the first time an owner locks a
		// space down.
		add_action( 'buddynext_space_updated', array( $this, 'sync_discussion_visibility' ), 10, 3 );

		add_action( 'buddynext_register_nav', array( $this, 'register_nav_items' ) );

		// Owner controls: register on the integration registry so the site owner can
		// toggle the Discussions tab + the discussion activity from BuddyNext →
		// Integrations (default on). The nav/feed gating below reads those toggles.
		add_filter( 'buddynext_integrations', array( $this, 'register_integration' ) );
		add_action( 'buddynext_integration_search_disabled', array( $this, 'on_search_disabled' ) );

		// On-demand space forum: provision + redirect when a member first opens a
		// forumless space's Discussions tab (web).
		// Priority 5 so the on-demand forum provision/redirect runs before
		// PageRouter::dispatch_hub_template (template_redirect:10) renders the
		// spaces directory and exits — otherwise the Discussions tab URL
		// (/spaces/?bn_provision_forum=N) shows the directory instead.
		add_action( 'template_redirect', array( $this, 'maybe_provision_and_redirect' ), 5 );

		// Two-way discussion sync: a comment on a discussion card in the BuddyNext
		// feed becomes a reply in the originating Jetonomy forum topic, and a reply
		// in the forum becomes a comment on the card — so a member who engages on
		// either surface is heard on both. A static in-request guard (self::$syncing)
		// blocks the reciprocal mirror, so the two hooks can never loop.
		add_action( 'buddynext_comment_created', array( $this, 'sync_comment_to_forum' ), 10, 4 );
		add_action( 'jetonomy_after_create_reply', array( $this, 'sync_reply_to_feed' ), 10, 2 );

		// Edit + delete propagation, both directions, resolved through the pair id
		// stored on the comment at creation (bn_comments.sync_reply_id). Same static
		// guard blocks the reciprocal mirror. Without this the surfaces desync — an
		// edit/delete on one side leaves the other side stale.
		add_action( 'buddynext_comment_updated', array( $this, 'sync_comment_edit_to_forum' ), 10, 1 );
		add_action( 'buddynext_comment_deleted', array( $this, 'sync_comment_delete_to_forum' ), 10, 1 );
		add_action( 'jetonomy_reply_updated', array( $this, 'sync_reply_edit_to_feed' ), 10, 1 );
		add_action( 'jetonomy_after_delete_reply', array( $this, 'sync_reply_delete_to_feed' ), 10, 1 );

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

		// Gated on the owner's "Include in search" switch. This was unconditional, with a
		// comment that said "Always-on" — so an owner who switched Jetonomy off still had
		// every new discussion enter community search, complete with its own Discussions
		// tab on the results page (the tab list is built from DISTINCT object_type in the
		// index, so the rows generate the tab that displays them).
		if ( buddynext_integration_enabled( 'jetonomy', 'search' ) ) {
			// Two corrections on one line, both leaks.
			//
			// Visibility was the literal 'public' while `is_private` and `status` were
			// selected and then ignored, so a PRIVATE topic — and a draft — entered
			// community search, where the guest gate is exactly `visibility = 'public'`.
			// is_public_discussion() is the predicate the feed path already uses for
			// this same decision; search now shares it instead of holding a second,
			// wrong opinion.
			//
			// The id stamped into bn_search_index.space_id was the JETONOMY forum id.
			// That column is matched against BuddyNext space ids by the search gate
			// (`si.space_id IN (viewer's bn spaces)`), so a member of BuddyNext space N
			// was granted search access to every discussion in unrelated Jetonomy forum
			// N. The feed path maps the forum back to its linked bn_spaces row; search
			// must use the same mapping or the value is meaningless in the column it
			// lands in.
			$bn_space_id = $this->space_id_for_forum( $space_id );
			$visibility  = $this->is_public_discussion( $space_id, (int) $post->is_private, (string) $post->status )
				? 'public'
				: 'private';

			( new SearchService() )->index( 'discussion', $post_id, $title, $content, $author_id, $visibility, $bn_space_id );
		}

		// Always-on: parse @username mentions from the discussion body. Collect the
		// unique logins first, then resolve them all in ONE query — the previous
		// get_user_by('login') per match was an N+1 (and fired a duplicate
		// notification when the same user was mentioned twice). number caps a
		// pathological mention flood.
		preg_match_all( Handle::mention_regex(), $content, $matches );

		// Resolved as HANDLES, not logins — see PostService for why. This costs a
		// lookup per DISTINCT handle rather than one login__in query, which is the
		// price of resolving the same way the handle is displayed: a custom slug
		// lives in usermeta and cannot be answered by a users-table IN(). The 100
		// cap that bounded the old query is kept as a cap on distinct handles, so
		// a pathological mention flood still cannot fan out.
		$mention_handles = array();
		foreach ( $matches[1] as $raw_username ) {
			$username = (string) $raw_username;
			if ( '' !== $username ) {
				$mention_handles[ $username ] = true;
			}
		}

		if ( ! empty( $mention_handles ) ) {
			$mentioned_ids = array();
			foreach ( array_slice( array_keys( $mention_handles ), 0, 100 ) as $handle ) {
				$user = Handle::resolve( $handle );
				if ( $user instanceof \WP_User ) {
					$mentioned_ids[] = (int) $user->ID;
				}
			}

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
			&& buddynext_integration_enabled( 'jetonomy', 'feed' )
			&& (bool) apply_filters( 'buddynext_jetonomy_discussion_activity', true, $post_id ) ) {
			$url = $this->discussion_url( $post_id, $space_id );
			if ( '' !== $url ) {
				$excerpt = wp_trim_words( wp_strip_all_tags( $content ), 30, '…' );

				// Stamp the BuddyNext space this discussion belongs to. $space_id here
				// is a Jetonomy FORUM id, which no BuddyNext surface understands — so
				// it has to be mapped back to the bn_spaces row the forum is linked to.
				// Without it the activity lands with space_id = NULL, which means the
				// space's "Share activity to the main feed" toggle cannot suppress it
				// (FeedService lets a NULL space through) and the space's own feed
				// cannot show it (it queries WHERE space_id = %d). Both surfaces are
				// fixed by this one value.
				IntegrationActivity::publish(
					$author_id,
					__( 'started a discussion', 'buddynext' ),
					$url,
					$title,
					'discussion',
					$excerpt,
					$this->space_id_for_forum( $space_id )
				);
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

		// A new discussion changes the author's profile count/list and the space's
		// count/list — drop those cached reads so the next view is accurate.
		$this->invalidate_member_caches( $author_id );
		$this->invalidate_space_caches( $space_id );
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

		// Deleting a discussion drops the author's published count — invalidate the
		// author's + the space's cached reads. Jetonomy soft-deletes (status → trash)
		// so the row still resolves the author id.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$author_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT author_id FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->invalidate_member_caches( $author_id );
		$this->invalidate_space_caches( $space_id );
	}

	/**
	 * Drop a member's cached discussion count + panel list.
	 *
	 * @param int $user_id Discussion author whose caches to clear.
	 * @return void
	 */
	private function invalidate_member_caches( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		wp_cache_delete( 'jt_dcount_' . $user_id, self::CACHE_GROUP );
		wp_cache_delete( 'jt_ulist_' . $user_id, self::CACHE_GROUP );
	}

	/**
	 * Drop a space forum's cached discussion count + panel list.
	 *
	 * @param int $forum_id Resolved Jetonomy forum id (jt_posts.space_id).
	 * @return void
	 */
	private function invalidate_space_caches( int $forum_id ): void {
		if ( $forum_id <= 0 ) {
			return;
		}
		wp_cache_delete( 'jt_scount_' . $forum_id, self::CACHE_GROUP );
		wp_cache_delete( 'jt_slist_' . $forum_id, self::CACHE_GROUP );
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

		return $this->discussion_permalink( $space_slug, $post_slug );
	}

	/**
	 * Build a Jetonomy discussion (topic) permalink from its space + post slug.
	 *
	 * Single source of truth for the discussion URL shape. The base segment always
	 * comes from Jetonomy's configurable base slug (an owner may rename it from
	 * `community` to `discuss` or anything else via Settings → General), resolved
	 * through \Jetonomy\base_url() so BuddyNext never hardcodes the base. Falls back
	 * to the stored `jetonomy_settings['base_slug']` option (Jetonomy's own default
	 * `community`) only when the Jetonomy helper is unavailable.
	 *
	 * @param string $space_slug jt_spaces.slug.
	 * @param string $post_slug  jt_posts.slug.
	 * @return string Discussion permalink, or '' when either slug is empty.
	 */
	public function discussion_permalink( string $space_slug, string $post_slug ): string {
		if ( '' === $space_slug || '' === $post_slug ) {
			return '';
		}

		if ( function_exists( 'Jetonomy\base_url' ) ) {
			$base = (string) \Jetonomy\base_url();
		} else {
			$settings  = get_option( 'jetonomy_settings', array() );
			$base_slug = isset( $settings['base_slug'] ) && '' !== (string) $settings['base_slug'] ? (string) $settings['base_slug'] : 'community';
			$base      = home_url( '/' . ltrim( $base_slug, '/' ) ); // bn-route-ok: built from Jetonomy's configured base slug.
		}

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

		return home_url( '/' . $base_slug . '/s/' . rawurlencode( $jt_slug ) . '/' ); // bn-route-ok: built from Jetonomy's configured base slug.
	}

	/**
	 * Whether a BuddyNext space currently has a Discussion linked.
	 *
	 * A Discussion is opt-in and never mandatory — a space has one only after the
	 * owner enabled or linked it. Thin boolean wrapper over the link meta for the
	 * owner-facing control and nav gating.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return bool True when a Jetonomy discussion is linked.
	 */
	public function space_has_discussion( int $space_id ): bool {
		return $this->forum_id_for_space( $space_id ) > 0;
	}

	/**
	 * Whether a space's dedicated Discussion is currently ENABLED (shown to
	 * members). A space keeps its one dedicated discussion for its lifetime; the
	 * owner toggles it on/off with this flag, which hides the tab without ever
	 * discarding the discussion or its content.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return bool True when a discussion exists AND is toggled on.
	 */
	public function space_discussion_enabled( int $space_id ): bool {
		if ( ! $this->space_has_discussion( $space_id ) ) {
			return false;
		}
		return '1' === (string) buddynext_get_space_field( $space_id, 'discussion_enabled' );
	}

	/**
	 * Turn a space's dedicated Discussion on or off. Only flips the enabled flag —
	 * the permanent jetonomy_forum_id link and all discussion content are never
	 * touched, so re-enabling restores the exact same discussion.
	 *
	 * @param int  $space_id BuddyNext space ID.
	 * @param bool $enabled  Desired state.
	 * @return void
	 */
	public function set_discussion_enabled( int $space_id, bool $enabled ): void {
		update_space_meta( absint( $space_id ), 'discussion_enabled', $enabled ? '1' : '0' );
	}

	/**
	 * Resolve the per-space Discussion status for the owner control + tab.
	 *
	 * `has_discussion` — the space has a dedicated discussion (permanent, 1:1).
	 * `enabled`        — the owner has it toggled on (members see the tab).
	 * `name` / `url`   — which discussion is linked, so the settings screen can
	 *                    always show it. The bridge owns all jt_* access.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return array{has_discussion:bool,enabled:bool,forum_id:int,name:string,url:string}
	 */
	public function space_discussion_status( int $space_id ): array {
		$forum_id = $this->forum_id_for_space( $space_id );
		if ( $forum_id <= 0 ) {
			return array(
				'has_discussion' => false,
				'enabled'        => false,
				'forum_id'       => 0,
				'name'           => '',
				'url'            => '',
			);
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$name = (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT title FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'has_discussion' => true,
			'enabled'        => $this->space_discussion_enabled( $space_id ),
			'forum_id'       => $forum_id,
			'name'           => $name,
			'url'            => $this->space_forum_url( $space_id ),
		);
	}

	/**
	 * Jetonomy discussions a space owner may attach to their space — ONLY the
	 * discussions that owner authored. Populates the "link an existing discussion"
	 * picker, so a space owner can never attach someone else's discussion.
	 *
	 * @param int $owner_id The space owner the picker is scoped to.
	 * @param int $limit    Max rows (1-200). Default 100.
	 * @return array<int,array{id:int,title:string}>
	 */
	public function linkable_discussions( int $owner_id, int $limit = 100 ): array {
		$owner_id = absint( $owner_id );
		if ( $owner_id <= 0 || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return array();
		}

		$limit = max( 1, min( 200, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, title FROM {$wpdb->prefix}jt_spaces WHERE author_id = %d AND status = 'active' ORDER BY title ASC LIMIT %d",
				$owner_id,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $bn_ld_space ) {
			if ( isset( $bn_ld_space->id, $bn_ld_space->title ) ) {
				$out[] = array(
					'id'    => (int) $bn_ld_space->id,
					'title' => (string) $bn_ld_space->title,
				);
			}
		}

		return $out;
	}

	/**
	 * Whether a Jetonomy discussion (jt_spaces id) exists and is active — the
	 * link-authorization guard for SITE ADMINS, who may attach any discussion.
	 * Members are held to the stricter discussion_owned_by() check instead.
	 *
	 * @param int $forum_id jt_spaces id.
	 * @return bool
	 */
	public function discussion_exists( int $forum_id ): bool {
		$forum_id = absint( $forum_id );
		if ( $forum_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE id = %d AND status = 'active' LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $found > 0;
	}

	/**
	 * Whether a Jetonomy discussion is authored by a given space owner — the
	 * server-side authorization guard for the "link existing" action, so a crafted
	 * POST can never attach a discussion the space owner does not own.
	 *
	 * @param int $forum_id jt_spaces id.
	 * @param int $owner_id The space owner who must own the discussion.
	 * @return bool
	 */
	public function discussion_owned_by( int $forum_id, int $owner_id ): bool {
		$forum_id = absint( $forum_id );
		$owner_id = absint( $owner_id );
		if ( $forum_id <= 0 || $owner_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$author_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT author_id FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1", $forum_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $author_id > 0 && $author_id === $owner_id;
	}

	/**
	 * Search discussions by title for the "link an existing discussion" typeahead
	 * — bounded so it scales to sites with thousands of discussions (never dumps
	 * the full set). Scope mirrors the save-time authorization: pass $owner_id > 0
	 * to restrict to that member's OWN discussions; pass 0 for the admin-only
	 * "search across all discussions" scope.
	 *
	 * @param string $q        Title query (substring match).
	 * @param int    $owner_id Restrict to this author; 0 = all authors (admin scope).
	 * @param int    $limit    Max rows (1-50). Default 20.
	 * @return array<int,array{id:int,title:string}>
	 */
	public function search_discussions( string $q, int $owner_id, int $limit = 20 ): array {
		if ( ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return array();
		}

		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		$like = '%' . $wpdb->esc_like( trim( $q ) ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $owner_id > 0 ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title FROM {$wpdb->prefix}jt_spaces WHERE author_id = %d AND status = 'active' AND title LIKE %s ORDER BY title ASC LIMIT %d",
					absint( $owner_id ),
					$like,
					$limit
				)
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title FROM {$wpdb->prefix}jt_spaces WHERE status = 'active' AND title LIKE %s ORDER BY title ASC LIMIT %d",
					$like,
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$out = array();
		foreach ( (array) $rows as $bn_sd_row ) {
			if ( isset( $bn_sd_row->id, $bn_sd_row->title ) ) {
				$out[] = array(
					'id'    => (int) $bn_sd_row->id,
					'title' => (string) $bn_sd_row->title,
				);
			}
		}

		return $out;
	}

	/**
	 * Unlink a space's Discussion (owner opt-out). Clears the link only; the
	 * Jetonomy discussion and all its content are preserved and can be re-linked
	 * later. Never deletes anyone's posts.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return void
	 */
	public function unlink_space_discussion( int $space_id ): void {
		update_space_meta( absint( $space_id ), 'jetonomy_forum_id', 0 );
	}

	/**
	 * The discussion visibility a BuddyNext space type requires.
	 *
	 * ONE definition, used both when the discussion is created and when the
	 * space's type later changes, so the two can never disagree. This used to
	 * be the literal 'public' at the creation site and nothing at all on
	 * change: enabling Discussion on a private or secret space published its
	 * entire discussion history to the world, silently and permanently, and
	 * nothing re-evaluated it afterwards.
	 *
	 * The enums map 1:1. An unrecognised type resolves to 'hidden' rather than
	 * 'public' - if we cannot tell what a space is, the safe answer is the one
	 * that exposes nothing, and a hidden discussion is recoverable where a
	 * leaked one is not.
	 *
	 * @param string $space_type BuddyNext space type: open | private | secret.
	 * @return string Jetonomy visibility: public | private | hidden.
	 */
	public static function discussion_visibility_for( string $space_type ): string {
		switch ( $space_type ) {
			case \BuddyNext\Spaces\SpaceService::TYPE_OPEN:
				return 'public';
			case \BuddyNext\Spaces\SpaceService::TYPE_PRIVATE:
				return 'private';
			case \BuddyNext\Spaces\SpaceService::TYPE_SECRET:
				return 'hidden';
			default:
				return 'hidden';
		}
	}

	/**
	 * Keep a linked discussion's visibility in step with its space's type.
	 *
	 * Creation-time correctness is not enough: an owner who opens a space to
	 * the public, or locks a public one down, expects the conversation inside
	 * it to follow. Without this the discussion keeps whatever it was born
	 * with, so the first time anyone tightens a space the leak reappears.
	 *
	 * Only writes when the value actually differs, so an unrelated space edit
	 * does not churn Jetonomy rows.
	 *
	 * @param int   $space_id BuddyNext space ID.
	 * @param int   $user_id  Actor (unused; the space's type is the authority).
	 * @param array $fields   Fields saved.
	 * @return void
	 */
	public function sync_discussion_visibility( $space_id, $user_id = 0, $fields = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $user_id/$fields are required by the buddynext_space_updated hook signature (3 args); the space's type is the authority here.
		$space_id = (int) $space_id;
		$forum_id = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
		if ( $space_id <= 0 || $forum_id <= 0 || ! class_exists( '\Jetonomy\Models\Space' ) ) {
			return;
		}

		$space = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
		if ( null === $space ) {
			return;
		}

		$want = self::discussion_visibility_for( (string) ( $space['type'] ?? '' ) );
		$jt   = \Jetonomy\Models\Space::find( $forum_id );
		if ( ! $jt || (string) ( $jt->visibility ?? '' ) === $want ) {
			return;
		}

		\Jetonomy\Models\Space::update( $forum_id, array( 'visibility' => $want ) );
	}

	/**
	 * Provision (once) the ONE dedicated Jetonomy discussion a BuddyNext space
	 * owns for its lifetime, and store the permanent link.
	 *
	 * A space's discussion is 1:1 and permanent: once created it is NEVER
	 * duplicated or recreated. If the space already has a linked discussion id
	 * this returns it unchanged (so turning the toggle off then on reuses the
	 * exact same discussion — the enabled flag, not this link, is what hides it).
	 *
	 * The new discussion is created with a collision-proof slug derived from the
	 * space slug (suffixed with the space id when that base slug is already taken),
	 * so it can NEVER silently adopt an unrelated Jetonomy space that merely shares
	 * a slug — that slug-matching was a hijack risk and is gone.
	 *
	 * Visibility is derived from the space's own type, never assumed.
	 *
	 * @param int $space_id BuddyNext space ID.
	 * @return int Jetonomy discussion (jt_spaces) id, or 0 on failure.
	 */
	public function provision_space_forum( int $space_id ): int {
		$existing = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
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

		/**
		 * Adopt an existing Jetonomy space instead of provisioning a new one.
		 *
		 * ASKED, not assumed. This file cannot know why a space exists - a
		 * community linked to a Learnomy course already has a Jetonomy space
		 * carrying that course's access rule, and provisioning a second one
		 * gives the course TWO discussions: the one the rule gates, and this
		 * one, which carries no rule and which nobody is enrolled into.
		 * Reproduced before this guard existed: one course, jt spaces #37 and
		 * #38.
		 *
		 * Free has no business knowing what a Learnomy course is, so it asks
		 * and whoever does know answers. Return a jt_spaces id to adopt, or 0
		 * to let provisioning proceed.
		 *
		 * @since 1.1.2
		 *
		 * @param int   $forum_id Adopted discussion id, or 0 to create one.
		 * @param int   $space_id BuddyNext space being given a discussion.
		 * @param array $space    The space row.
		 */
		$adopted = (int) apply_filters( 'buddynext_adopt_discussion_space', 0, $space_id, $space );
		if ( $adopted > 0 ) {
			update_space_meta( $space_id, 'jetonomy_forum_id', $adopted );
			$this->set_discussion_enabled( $space_id, true );

			return $adopted;
		}

		$owner_id = (int) ( $space['owner_id'] ?? 0 );
		$forum_id = (int) \Jetonomy\Models\Space::create(
			array(
				'title'      => (string) ( $space['name'] ?? '' ),
				'slug'       => $this->unique_discussion_slug( (string) ( $space['slug'] ?? '' ), $space_id ),
				'author_id'  => $owner_id,
				'visibility' => self::discussion_visibility_for( (string) ( $space['type'] ?? '' ) ),
				'status'     => 'active',
			),
			$owner_id
		);

		if ( $forum_id > 0 ) {
			update_space_meta( $space_id, 'jetonomy_forum_id', $forum_id );

			// Provisioning a forum IS the intent to use it: the Discussions tab
			// only renders when BOTH a linked forum exists AND the
			// discussion_enabled flag is set (space_discussion_enabled()), so a
			// programmatic provision used to create a working forum members
			// could never see until someone separately called
			// set_discussion_enabled(). One call now yields a visible tab.
			$this->set_discussion_enabled( $space_id, true );

			/**
			 * A discussion was just provisioned for a BuddyNext space.
			 *
			 * The other half of the adopt guard above. When the community IS the
			 * first thing created for a Learnomy course, nothing has written that
			 * course's access rule yet - so Jetonomy, which decides whether to
			 * provision by looking for exactly that rule, would go on to create a
			 * SECOND space later. A listener claims this one for the course
			 * instead, which makes the rule the single source of truth for
			 * "the course's discussion" no matter which plugin got there first.
			 *
			 * @since 1.1.2
			 *
			 * @param int $space_id BuddyNext space.
			 * @param int $forum_id The discussion just created.
			 */
			do_action( 'buddynext_space_discussion_provisioned', $space_id, $forum_id );
		}

		return $forum_id;
	}

	/**
	 * Build a jt_spaces slug that is guaranteed not to collide with an existing
	 * Jetonomy space. Prefers the BuddyNext space slug; when that is already taken
	 * (e.g. an unrelated discussion, or a previously-deleted-then-recreated space),
	 * suffixes the BuddyNext space id — unique per space — and falls back to a
	 * numbered variant in the vanishingly unlikely event that is taken too.
	 *
	 * @param string $base     Preferred slug (the BuddyNext space slug).
	 * @param int    $space_id BuddyNext space ID (used to disambiguate).
	 * @return string A slug not currently present in jt_spaces.
	 */
	private function unique_discussion_slug( string $base, int $space_id ): string {
		$base = '' !== trim( $base ) ? sanitize_title( $base ) : 'space-' . $space_id;

		if ( ! $this->discussion_slug_exists( $base ) ) {
			return $base;
		}

		$candidate = $base . '-' . $space_id;
		$suffix    = 2;
		while ( $this->discussion_slug_exists( $candidate ) ) {
			$candidate = $base . '-' . $space_id . '-' . $suffix;
			++$suffix;
		}

		return $candidate;
	}

	/**
	 * Whether a jt_spaces row already uses a given slug.
	 *
	 * @param string $slug Slug to test.
	 * @return bool
	 */
	private function discussion_slug_exists( string $slug ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE slug = %s LIMIT 1", $slug )
		) > 0;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			// Land on the new-topic composer so the member who clicked "Start the
			// first discussion" can write it immediately, not on the empty forum.
			wp_safe_redirect( trailingslashit( $url ) . 'new/' );
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
	 * Two-way sync — a comment on a discussion card in the BuddyNext feed becomes a
	 * reply in the originating Jetonomy forum topic. Fires on `buddynext_comment_created`.
	 *
	 * @param int    $comment_id  New comment id.
	 * @param string $object_type Commented object type ('post', …).
	 * @param int    $object_id   Commented object id (a bn_posts card).
	 * @param int    $user_id     Commenter.
	 * @return void
	 */
	public function sync_comment_to_forum( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		if ( self::$syncing || 'post' !== $object_type || $object_id <= 0 || $user_id <= 0 ) {
			return;
		}
		if ( ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}

		$card = ( new PostService() )->get( $object_id );
		if ( null === $card || 'discussion' !== (string) ( $card['type'] ?? '' ) ) {
			return;
		}
		$topic_id = $this->topic_id_for_card( $card );
		if ( $topic_id <= 0 ) {
			return;
		}

		$comment = ( new \BuddyNext\Comments\CommentService() )->get( $comment_id );
		$content = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( '' === $content ) {
			return;
		}

		self::mirror(
			function () use ( $topic_id, $user_id, $content, $comment_id ) {
				$reply_id = \Jetonomy\Models\Reply::create(
					array(
						'post_id'       => $topic_id,
						'author_id'     => $user_id,
						'content'       => wp_kses_post( $content ),
						'content_plain' => wp_strip_all_tags( $content ),
					)
				);
				if ( ! is_wp_error( $reply_id ) && (int) $reply_id > 0 ) {
					// Persist the pair on the comment so edit/delete can propagate later
					// (both directions resolve through bn_comments.sync_reply_id).
					( new \BuddyNext\Comments\CommentService() )->set_sync_reply_id( $comment_id, (int) $reply_id );
					// Fire the same post-create signal Jetonomy's REST reply path fires so the
					// engine's own listeners (notifications, activity, counts) treat this reply
					// like any other. The reciprocal handler bails on self::$syncing.
					do_action( 'jetonomy_after_create_reply', (int) $reply_id, $topic_id );
				}
			}
		);
	}

	/**
	 * Two-way sync — a reply in a Jetonomy forum topic becomes a comment on that
	 * topic's discussion card in the BuddyNext feed. Fires on `jetonomy_after_create_reply`.
	 *
	 * @param int $reply_id New reply id.
	 * @param int $post_id  Parent Jetonomy topic (jt_posts) id.
	 * @return void
	 */
	public function sync_reply_to_feed( int $reply_id, int $post_id ): void {
		if ( self::$syncing || $reply_id <= 0 || $post_id <= 0 ) {
			return;
		}
		$card_id = $this->card_id_for_topic( $post_id );
		if ( $card_id <= 0 ) {
			return; // This topic isn't surfaced as a feed card — nothing to mirror onto.
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$reply = $wpdb->get_row(
			$wpdb->prepare( "SELECT author_id, content, status FROM {$wpdb->prefix}jt_replies WHERE id = %d LIMIT 1", $reply_id ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$author_id = null !== $reply ? (int) $reply['author_id'] : 0;
		$content   = null !== $reply ? trim( (string) $reply['content'] ) : '';
		if ( $author_id <= 0 || '' === $content || 'publish' !== (string) ( $reply['status'] ?? '' ) ) {
			return; // Only mirror a published reply by a real author.
		}

		self::mirror(
			function () use ( $author_id, $card_id, $content, $reply_id ) {
				// CommentService applies its own permission/verification gate and sanitizes;
				// a WP_Error (e.g. an unverified author) simply means no mirrored comment.
				$comments   = new \BuddyNext\Comments\CommentService();
				$comment_id = $comments->create( $author_id, 'post', $card_id, $content );
				if ( ! is_wp_error( $comment_id ) && (int) $comment_id > 0 ) {
					// Persist the pair so edit/delete propagate in both directions.
					$comments->set_sync_reply_id( (int) $comment_id, $reply_id );
				}
			}
		);
	}

	/**
	 * Two-way sync — editing a feed comment updates the mirrored forum reply.
	 * Fires on `buddynext_comment_updated`. Resolves the pair via the comment's
	 * sync_reply_id; a no-op for unsynced comments. Reply::update is model-level
	 * (fires no controller hook), so it cannot loop back.
	 *
	 * @param int $comment_id Edited comment id.
	 * @return void
	 */
	public function sync_comment_edit_to_forum( int $comment_id ): void {
		if ( self::$syncing || $comment_id <= 0 || ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}
		$comments = new \BuddyNext\Comments\CommentService();
		$reply_id = $comments->get_sync_reply_id( $comment_id );
		if ( $reply_id <= 0 ) {
			return;
		}
		$comment = $comments->get( $comment_id );
		$content = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( '' === $content ) {
			return;
		}
		self::mirror(
			function () use ( $reply_id, $content ) {
				\Jetonomy\Models\Reply::update(
					$reply_id,
					array(
						'content'       => wp_kses_post( $content ),
						'content_plain' => wp_strip_all_tags( $content ),
					)
				);
			}
		);
	}

	/**
	 * Two-way sync — deleting a feed comment removes the mirrored forum reply.
	 * Fires on `buddynext_comment_deleted`. The comment is soft-deleted (row
	 * persists), so its sync_reply_id is still readable at delete time.
	 *
	 * @param int $comment_id Deleted comment id.
	 * @return void
	 */
	public function sync_comment_delete_to_forum( int $comment_id ): void {
		if ( self::$syncing || $comment_id <= 0 || ! class_exists( '\Jetonomy\Models\Reply' ) ) {
			return;
		}
		$reply_id = ( new \BuddyNext\Comments\CommentService() )->get_sync_reply_id( $comment_id );
		if ( $reply_id <= 0 ) {
			return;
		}
		self::mirror(
			function () use ( $reply_id ) {
				\Jetonomy\Models\Reply::delete( $reply_id );
			}
		);
	}

	/**
	 * Two-way sync — editing a forum reply updates the mirrored feed comment.
	 * Fires on `jetonomy_reply_updated`. Resolves the pair via find_by_sync_reply_id
	 * and updates as the comment's own author so BuddyNext's ownership gate passes.
	 * CommentService::update fires buddynext_comment_updated, which the guard blocks.
	 *
	 * @param int $reply_id Edited reply id.
	 * @return void
	 */
	public function sync_reply_edit_to_feed( int $reply_id ): void {
		if ( self::$syncing || $reply_id <= 0 ) {
			return;
		}
		$comments = new \BuddyNext\Comments\CommentService();
		$comment  = $comments->find_by_sync_reply_id( $reply_id );
		if ( null === $comment ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$content = trim( (string) $wpdb->get_var( $wpdb->prepare( "SELECT content FROM {$wpdb->prefix}jt_replies WHERE id = %d LIMIT 1", $reply_id ) ) );
		if ( '' === $content ) {
			return;
		}
		self::mirror(
			function () use ( $comments, $comment, $content ) {
				$comments->update( (int) $comment['id'], (int) $comment['user_id'], $content );
			}
		);
	}

	/**
	 * Two-way sync — deleting a forum reply removes the mirrored feed comment.
	 * Fires on `jetonomy_after_delete_reply`. Deletes as the comment's own author.
	 * CommentService::delete fires buddynext_comment_deleted, which the guard blocks.
	 *
	 * @param int $reply_id Deleted reply id.
	 * @return void
	 */
	public function sync_reply_delete_to_feed( int $reply_id ): void {
		if ( self::$syncing || $reply_id <= 0 ) {
			return;
		}
		$comments = new \BuddyNext\Comments\CommentService();
		$comment  = $comments->find_by_sync_reply_id( $reply_id );
		if ( null === $comment ) {
			return;
		}
		self::mirror(
			function () use ( $comments, $comment ) {
				$comments->delete( (int) $comment['id'], (int) $comment['user_id'] );
			}
		);
	}

	/**
	 * Resolve the Jetonomy topic (jt_posts) id a discussion card points at.
	 *
	 * The card's link_url is the discussion permalink `…/s/{space}/t/{post}/`; the
	 * slugs map back to jt_spaces + jt_posts (scoped, so slugs need not be globally
	 * unique).
	 *
	 * @param array<string,mixed> $card A bn_posts discussion card row.
	 * @return int Topic id, or 0.
	 */
	private function topic_id_for_card( array $card ): int {
		$url = (string) ( $card['link_url'] ?? '' );
		if ( '' === $url || ! preg_match( '~/s/([^/]+)/t/([^/?#]+)~', $url, $m ) ) {
			return 0;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_spaces WHERE slug = %s LIMIT 1", $m[1] ) );
		if ( $space_id <= 0 ) {
			return 0;
		}
		$topic_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_posts WHERE slug = %s AND space_id = %d LIMIT 1", $m[2], $space_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $topic_id;
	}

	/**
	 * Resolve the BuddyNext discussion card id for a Jetonomy topic, or 0 when the
	 * topic was never surfaced as a feed card.
	 *
	 * @param int $topic_id Jetonomy topic (jt_posts) id.
	 * @return int Card id, or 0.
	 */
	private function card_id_for_topic( int $topic_id ): int {
		if ( $topic_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1", $topic_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $space_id <= 0 ) {
			return 0;
		}
		$url = $this->discussion_url( $topic_id, $space_id );
		if ( '' === $url ) {
			return 0;
		}
		return ( new PostService() )->get_id_by_link( 'discussion', $url );
	}

	/**
	 * REST permission gate for management-only forum routes (discussion-search).
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
	 * REST permission gate for POST /spaces/{id}/forum.
	 *
	 * View-level, NOT manage-level: this route is the app's only way to RESOLVE a
	 * space's forum id, and every member who can see the space needs that. Gating
	 * it on buddynext-moderate-space made the Discussions tab a hard error for
	 * every non-owner (even when the forum already existed, readable via
	 * /jetonomy/v1). Whether the caller may PROVISION a missing forum is decided
	 * in the handler (rest_provision_forum) — a plain member gets the zero-state,
	 * never a 403, matching the web tab's owner-only "Start the first discussion"
	 * trigger.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return true|\WP_Error
	 */
	public function rest_forum_access_permission( \WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_not_logged_in', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}

		$space_id = (int) $request['id'];
		$space    = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );

		// Same 404 for missing and not-viewable (secret) so existence isn't leaked.
		if ( null === $space || ! \BuddyNext\Spaces\SpaceVisibility::can_view_space( $space, get_current_user_id() ) ) {
			return new \WP_Error( 'not_found', __( 'Space not found.', 'buddynext' ), array( 'status' => 404 ) );
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
				'permission_callback' => array( $this, 'rest_forum_access_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Typeahead for the "link an existing discussion" picker. Bounded search so
		// it scales past a bounded <select> on sites with thousands of discussions.
		// Same manage-space gate as provisioning; scope is role-derived server-side
		// (admins search all, members only their own) — the client cannot widen it.
		register_rest_route(
			'buddynext/v1',
			'/spaces/(?P<id>\d+)/discussion-search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_search_discussions' ),
				'permission_callback' => array( $this, 'rest_provision_permission' ),
				'args'                => array(
					'id' => array(
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'q'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// Member Discussions credential panel (app coverage). Public read — the same
		// credential-first payload the SSR profile tab renders, so web and app show
		// the same accepted-answers/reputation strip plus recent discussions.
		register_rest_route(
			'buddynext/v1',
			'/members/(?P<id>\d+)/discussions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_member_discussions' ),
				'permission_callback' => '__return_true',
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
	 * REST: a member's Discussions credential panel (credentials + recent
	 * discussions). Mirrors the SSR profile tab so the native app renders it too.
	 *
	 * @param \WP_REST_Request $request Request ({id}).
	 * @return \WP_REST_Response|\WP_Error 404 when the member does not exist.
	 */
	public function rest_member_discussions( \WP_REST_Request $request ) {
		$user_id = absint( $request['id'] );
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		// SECURITY: same profile-visibility gate the profile route applies.
		//
		// This route is public (`permission_callback => '__return_true'`) and only
		// checked that the id resolved, so a private / followers-only / blocked-from
		// profile still handed out discussion titles, URLs, space names, reputation
		// and trust level. ProfileController::get_item() has gated this since the
		// equivalent leak was closed there; the app-parity route beside it did not.
		//
		// Same 404 as a missing member, deliberately, so existence is not leaked
		// either - a 403 here would confirm the account exists.
		$privacy = buddynext_service( 'privacy' );
		if ( $privacy instanceof \BuddyNext\SocialGraph\PrivacyService
			&& ! $privacy->can_view_profile( get_current_user_id(), $user_id ) ) {
			return new \WP_Error( 'not_found', __( 'Member not found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$payload = $this->member_discussion_profile( $user_id, 20 );

		$discussions = array_map(
			static function ( $row ): array {
				return array(
					'id'          => (int) $row->id,
					'title'       => (string) $row->title,
					'url'         => isset( $row->url ) ? (string) $row->url : '',
					'reply_count' => (int) $row->reply_count,
					'vote_score'  => (int) $row->vote_score,
					'space_name'  => isset( $row->space_name ) ? (string) $row->space_name : '',
					'created_at'  => (string) $row->created_at,
				);
			},
			$payload['discussions']
		);

		return new \WP_REST_Response(
			array(
				'accepted_answers' => (int) $payload['accepted_answers'],
				'reputation'       => (int) $payload['reputation'],
				'trust_level'      => (int) $payload['trust_level'],
				'discussion_count' => (int) $payload['discussion_count'],
				'discussions'      => $discussions,
			),
			200
		);
	}

	/**
	 * REST: typeahead search for the "link an existing discussion" picker.
	 *
	 * Scope is derived from the caller's role, NOT the request: a site admin
	 * searches across all discussions; any other manager searches only the space
	 * owner's own discussions — matching the save-time authorization so the client
	 * can never widen its own scope.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_search_discussions( \WP_REST_Request $request ): \WP_REST_Response {
		$space_id = (int) $request['id'];
		$q        = (string) $request->get_param( 'q' );

		$owner_id = 0;
		if ( ! current_user_can( 'manage_options' ) ) {
			$space    = ( new \BuddyNext\Spaces\SpaceService() )->get( $space_id );
			$owner_id = (int) ( $space['owner_id'] ?? 0 );
		}

		return new \WP_REST_Response(
			array( 'results' => $this->search_discussions( $q, $owner_id ) ),
			200
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
		$existing = (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );

		// Resolve-first: an existing forum is returned to ANY viewer the
		// permission gate admitted (view-level). Only a MISSING forum requires
		// the moderate-space capability to create — a plain member gets the
		// zero-state instead of a 403, mirroring the web tab where "Start the
		// first discussion" is an owner/moderator-only trigger.
		$forum_id = $existing;
		if ( 0 >= $forum_id && $this->can_provision_forum( $space_id, get_current_user_id() ) ) {
			$forum_id = $this->provision_space_forum( $space_id );
		}

		return new \WP_REST_Response(
			array(
				'forum_id'      => $forum_id,
				'forum_url'     => $forum_id > 0 ? $this->space_forum_url( $space_id ) : '',
				// The zero-state discriminator: false + forum_id 0 → "no
				// discussions yet, and you can't start them"; true → the caller
				// may POST again after owner action, or just provisioned.
				'can_provision' => $this->can_provision_forum( $space_id, get_current_user_id() ),
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
		// Active AND the owner hasn't hidden the Discussions tab (Integrations control).
		$jetonomy_active = static fn(): bool => class_exists( 'Jetonomy\Jetonomy' )
			&& buddynext_integration_enabled( 'jetonomy', 'nav' );

		// Profile: a primary tab owning the member's discussions panel — clean URL +
		// the content-seam render, same as every other profile tab.
		$registry->register(
			array(
				'id'        => 'discussions',
				'surface'   => 'profile',
				'layer'     => 'primary',
				'label'     => __( 'Discussions', 'buddynext' ),
				'icon'      => 'message-square',
				'priority'  => 60,
				'condition' => $jetonomy_active,
				'url'       => static fn( \BuddyNext\Nav\NavContext $c ): string => trailingslashit( \BuddyNext\Core\PageRouter::profile_url( $c->subject_id ) ) . 'discussions/',
				'count'     => fn( \BuddyNext\Nav\NavContext $c ): int => $this->discussion_count( $c->subject_id ),
				'render'    => function ( \BuddyNext\Nav\NavContext $c ): void {
					$this->render_profile_discussions_panel( $c->subject_id );
				},
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
				// Per-space: only when THIS space's owner has enabled its dedicated
				// discussion. A space with none (or with it toggled off) shows no tab.
				'condition' => fn( \BuddyNext\Nav\NavContext $c ): bool => $jetonomy_active()
					&& $this->space_discussion_enabled( $c->subject_id ),
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
	 * Register Jetonomy on the integration registry so the owner can toggle its
	 * Discussions tab + discussion activity from BuddyNext → Integrations.
	 *
	 * @param array<string,array<string,mixed>> $items Registered integrations.
	 * @return array<string,array<string,mixed>>
	 */
	public function register_integration( array $items ): array {
		if ( class_exists( 'Jetonomy\Jetonomy' ) ) {
			$items['jetonomy'] = array(
				'label'      => __( 'Jetonomy', 'buddynext' ),
				'version'    => defined( 'JETONOMY_VERSION' ) ? JETONOMY_VERSION : null,
				'has_nav'    => true,
				'has_feed'   => true,
				'has_search' => true,
			);
		}
		return $items;
	}

	/**
	 * Purge Jetonomy discussions from the search index when the owner switches search off.
	 *
	 * Gating the write path only stops NEW content. Everything indexed while the
	 * integration was enabled would otherwise keep surfacing in search forever, with no way
	 * for the owner to reach it — which is the half of this bug that is actually visible to
	 * members.
	 *
	 * The bridge owns the discussion <-> jetonomy mapping, not Free core: core fires the
	 * action with the integration KEY and each bridge decides which object_type that means.
	 *
	 * @param string $key Integration key that was switched off.
	 * @return void
	 */
	public function on_search_disabled( string $key ): void {
		if ( 'jetonomy' !== $key ) {
			return;
		}

		( new SearchService() )->deindex_type( 'discussion' );
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
				// Provisioning (creating the forum) is gated to the space owner /
				// moderator, unlike posting. The empty-state "Start the first
				// discussion" CTA must follow provisioning, not posting: a member who
				// could post but not provision otherwise saw a CTA that failed the
				// permission check and fell through to All Spaces.
				'can_provision' => $this->can_provision_forum( $space_id, $viewer_id ),
			)
		);
	}

	/**
	 * Render the profile Discussions panel — the registry content seam for the
	 * profile Discussions tab. The bridge owns all jt_* access, so it self-fetches
	 * the member's discussions and hands them to the shared part. Replaces the old
	 * hardcoded profile-tab-panel.php branch.
	 *
	 * @param int $user_id Profile being viewed.
	 * @return void
	 */
	public function render_profile_discussions_panel( int $user_id ): void {
		$profile = $this->member_discussion_profile( $user_id, 20 );
		buddynext_get_template(
			'parts/profile/discussions-panel.php',
			array(
				'profile_user_id'  => $user_id,
				'discussions'      => $profile['discussions'],
				'discussion_count' => $profile['discussion_count'],
				'accepted_answers' => $profile['accepted_answers'],
				'reputation'       => $profile['reputation'],
				'trust_level'      => $profile['trust_level'],
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

		// Nav badges call this on EVERY profile view — cache the COUNT so a large
		// forum does not pay a jt_posts scan per page load. Invalidated on the
		// member's post create/delete (invalidate_member_caches).
		$cache_key = 'jt_dcount_' . $user_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE author_id = %d AND status = 'publish'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
	}

	/**
	 * List a user's published Jetonomy discussions, newest first, joined to
	 * their space for the name/slug. Powers the profile "Discussions" tab so the
	 * template never reaches into jt_* tables itself (the bridge owns that access).
	 *
	 * @param int $user_id Discussion author ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return object[] Each row: id, title, slug, reply_count, vote_score, created_at, space_name, space_slug, url.
	 */
	public function user_discussions( int $user_id, int $limit = 20 ): array {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 || ! class_exists( 'Jetonomy\Models\Post' ) ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		// The profile panel + REST both request the default page; cache that hot
		// path (invalidated on the member's post create/delete). Non-default limits
		// are rare and bypass the cache so they never serve a truncated set.
		$use_cache = ( 20 === $limit );
		$cache_key = 'jt_ulist_' . $user_id;
		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

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

		if ( ! is_array( $rows ) ) {
			return array();
		}

		// Resolve each discussion's permalink through the base-slug-aware helper so
		// the template renders a ready URL and never rebuilds the /s/…/t/… path.
		foreach ( $rows as $row ) {
			$row->url = $this->discussion_permalink( (string) $row->space_slug, (string) $row->slug );
		}

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $rows;
	}

	/**
	 * Count a member's accepted answers — the real credential Jetonomy tracks.
	 *
	 * An accepted answer (jt_replies.is_accepted) is an outcome (this member solved
	 * someone's question), unlike started-discussion counts which are activity churn.
	 * The bridge owns all jt_* access, so it self-fetches.
	 *
	 * @param int $user_id Reply author ID.
	 * @return int
	 */
	public function accepted_answer_count( int $user_id ): int {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_replies WHERE author_id = %d AND is_accepted = 1 AND status = 'publish'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Read a member's Jetonomy reputation + trust level from its UserProfile model.
	 *
	 * Reputation/trust are the standing Jetonomy maintains for a member — the
	 * credential worth surfacing on a profile. Returns zeros when Jetonomy or the
	 * profile row is unavailable so callers never have to null-check.
	 *
	 * @param int $user_id Member ID.
	 * @return array{reputation:int,trust_level:int}
	 */
	public function member_reputation( int $user_id ): array {
		$user_id = absint( $user_id );
		$out     = array(
			'reputation'  => 0,
			'trust_level' => 0,
		);
		if ( $user_id <= 0 || ! class_exists( '\Jetonomy\Models\UserProfile' ) ) {
			return $out;
		}

		$profile = \Jetonomy\Models\UserProfile::find_by_user( $user_id );
		if ( $profile ) {
			$out['reputation']  = (int) ( $profile->reputation ?? 0 );
			$out['trust_level'] = (int) ( $profile->trust_level ?? 0 );
		}

		return $out;
	}

	/**
	 * Assemble the full profile Discussions payload — the single source both the
	 * SSR panel and the REST route consume, so web and app render the same tab
	 * (app-coverage / REST-first house rule).
	 *
	 * Credential-first: accepted answers + reputation/trust are the outcomes worth
	 * showing; the recent-discussions list is supporting context, not the headline.
	 *
	 * @param int $user_id Member being viewed.
	 * @param int $limit   Max discussion rows (1-50). Default 20.
	 * @return array{discussions:object[],discussion_count:int,accepted_answers:int,reputation:int,trust_level:int}
	 */
	public function member_discussion_profile( int $user_id, int $limit = 20 ): array {
		$credentials = $this->member_reputation( $user_id );

		return array(
			'discussions'      => $this->user_discussions( $user_id, $limit ),
			'discussion_count' => $this->discussion_count( $user_id ),
			'accepted_answers' => $this->accepted_answer_count( $user_id ),
			'reputation'       => $credentials['reputation'],
			'trust_level'      => $credentials['trust_level'],
		);
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
		return (int) buddynext_get_space_field( $space_id, 'jetonomy_forum_id' );
	}

	/**
	 * Resolve the BuddyNext space a Jetonomy forum is linked to — the inverse of
	 * forum_id_for_space().
	 *
	 * Needed because every Jetonomy hook hands us a `jt_spaces.id` (a forum id),
	 * while every BuddyNext feed/privacy surface keys on a `bn_spaces.id`. Without
	 * this mapping a discussion activity is written with no space at all, and the
	 * space's own "Share activity to the main feed" toggle can never suppress it —
	 * FeedService excludes by `space_id` and lets a NULL space through.
	 *
	 * Memoized per request: one discussion hook can fan out to several callers.
	 *
	 * @param int $forum_id Jetonomy forum id (jt_spaces.id).
	 * @return int BuddyNext space id, or 0 when the forum is not linked to a space.
	 */
	private function space_id_for_forum( int $forum_id ): int {
		static $memo = array();

		$forum_id = absint( $forum_id );
		if ( $forum_id <= 0 ) {
			return 0;
		}

		if ( isset( $memo[ $forum_id ] ) ) {
			return $memo[ $forum_id ];
		}

		global $wpdb;

		// Space meta has no lookup-by-VALUE accessor, so this reverse resolution has
		// to be a direct query. Memoized above, so it runs at most once per forum
		// per request.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$space_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT bn_space_id FROM {$wpdb->bn_spacemeta}
				 WHERE meta_key = 'jetonomy_forum_id' AND meta_value = %s
				 LIMIT 1",
				(string) $forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$memo[ $forum_id ] = $space_id;

		return $space_id;
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

		// Cache the default page (keyed by the resolved forum id, matching the
		// invalidation hooks); odd limits bypass the cache.
		$use_cache = ( 20 === $limit );
		$cache_key = 'jt_slist_' . $forum_id;
		if ( $use_cache ) {
			$cached = wp_cache_get( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

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

		$rows = is_array( $rows ) ? $rows : array();

		if ( $use_cache ) {
			wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		}

		return $rows;
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

		// Nav badges call this on EVERY space view — cache the COUNT keyed by the
		// resolved forum id (invalidated on that forum's post create/delete).
		$cache_key = 'jt_scount_' . $forum_id;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE space_id = %d AND status = 'publish'",
				$forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_set( $cache_key, $count, self::CACHE_GROUP, self::CACHE_TTL );

		return $count;
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
		// Base the trigger on the SPACE's own URL, never the /spaces/ directory: if
		// the provision handler falls through (expired nonce, or a viewer who may
		// post but not provision), WordPress renders the base URL — and rendering
		// the All Spaces directory is exactly the "thrown back to All Spaces"
		// symptom. Off the space URL, a fall-through stays on the space.
		$base = \BuddyNext\Core\PageRouter::space_url( absint( $space_id ) );
		if ( '' === $base ) {
			$base = \BuddyNext\Core\PageRouter::spaces_url();
		}
		return add_query_arg(
			array(
				'bn_provision_forum' => absint( $space_id ),
				'_bnpf'              => wp_create_nonce( 'bn_provision_forum_' . absint( $space_id ) ),
			),
			$base
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

		$base        = function_exists( 'Jetonomy\base_url' ) ? \Jetonomy\base_url() : home_url( '/community' ); // bn-route-ok: canonical Jetonomy API first, literal only as absent-plugin fallback.
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
				'url'         => '' !== $jt_url ? $jt_url : home_url( '/community/' ), // bn-route-ok: bridge-supplied URL first, literal only as fallback.
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
