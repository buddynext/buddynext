<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Jetonomy bridge.
 *
 * Routes Jetonomy events into BuddyNext surfaces:
 *
 * ALWAYS-ON:
 * - Discussion created → bn_search_index (type: discussion)
 * - Discussion created → @mention parsing → buddynext_user_mentioned action
 * - Reply notifications are handled by JetonomyBridgeListener (jetonomy_after_create_reply)
 *
 * OPT-IN (admin toggle or per-space):
 * - Discussion created → bn_posts entry (type: forum_post) if feed sync enabled
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
	 */
	public function init(): void {
		if ( ! class_exists( 'Jetonomy\Core\Plugin' ) ) {
			return;
		}

		// Search index + mention parsing: discussion created.
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 10, 4 );
	}

	/**
	 * Index a Jetonomy discussion in bn_search_index, parse @mentions, and
	 * optionally push a feed entry when the feed sync option is enabled.
	 *
	 * Hooked on: jetonomy_after_create_post($post_id, $author_id, $title, $content)
	 *
	 * @param int    $post_id   Discussion post ID.
	 * @param int    $author_id Author user ID.
	 * @param string $title     Discussion title.
	 * @param string $content   Discussion body.
	 */
	public function on_post_created( int $post_id, int $author_id, string $title, string $content ): void {
		( new SearchService() )->index( 'discussion', $post_id, $title, $content, $author_id );

		// Parse @username mentions from post content and fire a BuddyNext mention action
		// for each mentioned user so the notification layer can handle delivery.
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

		// Feed sync: push a forum_post card into bn_posts when the site-wide
		// feed sync option is enabled. Opt-in; default off per spec.
		if ( get_option( 'buddynext_jetonomy_feed_sync', false ) ) {
			global $wpdb;

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id'  => $author_id,
					'type'     => 'forum_post',
					'content'  => wp_trim_words( $content, 55, '...' ),
					'link_url' => get_permalink( $post_id ) ? get_permalink( $post_id ) : null,
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
		 * @param int    $author_id Author ID.
		 * @param string $title     Discussion title.
		 * @param string $content   Discussion content excerpt.
		 */
		do_action( 'buddynext_jetonomy_post_indexed', $post_id, $author_id, $title, $content );
	}
}
