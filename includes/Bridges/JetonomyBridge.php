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
	 * @param int $post_id  Jetonomy discussion ID.
	 * @param int $space_id Jetonomy space ID (unused — kept for hook signature).
	 * @param int $user_id  User who deleted the discussion (unused — kept for hook signature).
	 */
	public function on_post_deleted( int $post_id, int $space_id, int $user_id ): void {
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
}
