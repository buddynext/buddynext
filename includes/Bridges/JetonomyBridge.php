<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Jetonomy bridge.
 *
 * Routes Jetonomy events into BuddyNext surfaces:
 *
 * ALWAYS-ON:
 * - Discussion created → bn_search_index (type: discussion)
 * - Discussion created → @mention parsing → buddynext_user_mentioned action
 * - Discussion deleted → removes entry from bn_search_index
 * - Discussion deleted → removes feed card from bn_posts (when feed sync is active)
 * - Reply notifications are handled by JetonomyBridgeListener (jetonomy_after_create_reply)
 * - Unified nav: BuddyNext subnav injected on all Jetonomy pages (jetonomy_before_content)
 * - Unified nav: Jetonomy's own community nav suppressed (jetonomy_show_community_nav → false)
 *
 * OPT-IN (admin toggle buddynext_jetonomy_feed_sync, default off):
 * - Discussion created → bn_posts entry (type: forum_post)
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Search\SearchService;

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
		if ( ! class_exists( 'Jetonomy\Core\Plugin' ) ) {
			return;
		}

		// jetonomy_after_create_post fires ($post_id, $space_id) — 2 args only.
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 10, 2 );

		// jetonomy_post_deleted fires ($post_id, $space_id, $user_id) — 3 args.
		add_action( 'jetonomy_post_deleted', array( $this, 'on_post_deleted' ), 10, 3 );

		// Inject a Discussions link into the BuddyNext top nav bar.
		add_filter( 'buddynext_nav_items', array( $this, 'inject_discussions_nav_item' ) );

		// On Jetonomy pages: replace Jetonomy's own nav with the BuddyNext subnav
		// so the whole platform shares one unified navigation system.
		add_action( 'jetonomy_before_content', array( $this, 'render_buddynext_nav_on_jetonomy' ), 5 );
		add_filter( 'jetonomy_show_community_nav', '__return_false' );

		// Inject a Discussions tab into BuddyNext spaces that have a linked Jetonomy forum.
		add_filter( 'buddynext_space_tabs', array( $this, 'inject_space_forum_tab' ), 10, 2 );

		// Inject a Discussions stat block into BuddyNext user profiles.
		add_filter( 'buddynext_profile_extra_data', array( $this, 'inject_profile_discussion_count' ), 10, 2 );
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
				"SELECT author_id, title, content_plain FROM {$wpdb->prefix}jt_posts WHERE id = %d LIMIT 1",
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
				 * @param int    $mentioned_user_id ID of the user who was mentioned.
				 * @param int    $author_id         ID of the user who wrote the post.
				 * @param string $context           Context slug identifying the mention source.
				 * @param int    $post_id           Jetonomy post ID containing the mention.
				 */
				do_action( 'buddynext_user_mentioned', $mentioned_user->ID, $author_id, 'jetonomy_post', $post_id );
			}
		}

		// Opt-in: push a forum_post card into bn_posts when the site-wide feed
		// sync option is explicitly enabled. Default off per spec to avoid reply
		// fragmentation and feed noise.
		if ( get_option( 'buddynext_jetonomy_feed_sync', false ) ) {
			$link_url = get_permalink( $post_id );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id'  => $author_id,
					'type'     => 'forum_post',
					'content'  => wp_trim_words( $content, 55, '...' ),
					'link_url' => $link_url ? $link_url : null,
					'privacy'  => 'public',
					'status'   => 'published',
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Deletes the bn_search_index entry and, when feed sync is active,
	 * removes the linked forum_post card from bn_posts.
	 *
	 * @param int $post_id   Jetonomy discussion ID.
	 * @param int $_space_id Jetonomy space ID (unused — kept for hook signature).
	 * @param int $_user_id  User who deleted the discussion (unused — kept for hook signature).
	 */
	public function on_post_deleted( int $post_id, int $_space_id, int $_user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- hook signature requires all 3 args.
		global $wpdb;

		// Always-on: remove from search index.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_search_index',
			array(
				'object_type' => 'discussion',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);

		// Opt-in: remove the feed card only when feed sync was active.
		if ( get_option( 'buddynext_jetonomy_feed_sync', false ) ) {
			$link_url = get_permalink( $post_id );
			if ( $link_url ) {
				$wpdb->delete(
					$wpdb->prefix . 'bn_posts',
					array(
						'type'     => 'forum_post',
						'link_url' => $link_url,
					),
					array( '%s', '%s' )
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Inject a Discussions link into the BuddyNext top navigation bar.
	 *
	 * Appends a public "Discussions" nav item pointing to the Jetonomy community home.
	 * Active state is detected by comparing the current REQUEST_URI against the
	 * Jetonomy base path so the item highlights on every forum page.
	 *
	 * Hooked on: buddynext_nav_items( array $items )
	 *
	 * @param array<int, array{label: string, url: string, icon?: string, active?: bool}> $items Existing nav items.
	 * @return array<int, array{label: string, url: string, icon?: string, active?: bool}>
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
			'icon'   => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
			'active' => $is_active,
		);

		return $items;
	}

	/**
	 * Render the BuddyNext subnav on Jetonomy pages.
	 *
	 * Fired early (priority 5) on jetonomy_before_content so the unified nav
	 * appears before Jetonomy's header partial. Jetonomy's own community nav is
	 * suppressed via the jetonomy_show_community_nav → false filter, making
	 * BuddyNext the sole navigation across both plugin surfaces.
	 *
	 * Bails silently when BuddyNext is not yet fully booted (e.g. during cron)
	 * so no output is generated before headers are sent.
	 *
	 * Hooked on: jetonomy_before_content( array $data )
	 */
	public function render_buddynext_nav_on_jetonomy(): void {
		if ( ! function_exists( 'buddynext_get_template' ) || ! did_action( 'buddynext_loaded' ) ) {
			return;
		}
		buddynext_get_template( 'partials/nav' );
	}

	/**
	 * Inject a Forum tab into BuddyNext space navigation when a Jetonomy forum is linked.
	 *
	 * Reads the `bn_space_{space_id}_jetonomy_forum_id` option (set via Space Settings).
	 * When non-zero, fetches the Jetonomy space slug from jt_spaces and appends an
	 * external-link tab pointing to the forum URL.
	 *
	 * Hooked on: buddynext_space_tabs( array $tabs, int $space_id )
	 *
	 * @param array<string, string|array<string,string>> $tabs     Existing tab map (key → label or ['label','url']).
	 * @param int                                        $space_id BuddyNext space ID.
	 * @return array<string, string|array<string,string>>
	 */
	public function inject_space_forum_tab( array $tabs, int $space_id ): array {
		$forum_id = (int) get_option( 'bn_space_' . $space_id . '_jetonomy_forum_id', 0 );
		if ( 0 === $forum_id ) {
			return $tabs;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jt_slug = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = %d LIMIT 1",
				$forum_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $jt_slug ) {
			return $tabs;
		}

		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = isset( $settings['base_slug'] ) ? (string) $settings['base_slug'] : 'community';

		$tabs['discussions'] = array(
			'label' => __( 'Discussions', 'buddynext' ),
			'url'   => home_url( '/' . $base_slug . '/s/' . rawurlencode( (string) $jt_slug ) . '/' ),
		);

		return $tabs;
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

		$extra[] = array(
			'label' => __( 'Discussions', 'buddynext' ),
			'value' => $count,
		);

		return $extra;
	}
}
