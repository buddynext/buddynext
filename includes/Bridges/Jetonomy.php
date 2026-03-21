<?php
/**
 * Jetonomy bridge.
 *
 * Routes Jetonomy events into BuddyNext surfaces:
 *
 * ALWAYS-ON:
 * - Discussion created/updated → bn_search_index (type: discussion)
 * - Reply created → bn_notifications (type: jt.discussion_reply) for post author
 *
 * OPT-IN (admin toggle or per-space):
 * - Discussion created → bn_posts entry (type: discussion) if feed sync enabled
 *
 * @package BuddyNext\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Bridges;

use BuddyNext\Notifications\NotificationService;
use BuddyNext\Search\SearchService;

/**
 * Jetonomy ↔ BuddyNext integration layer.
 */
class Jetonomy {

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init() via buddynext_load_bridges action.
	 */
	public function init(): void {
		// Notifications: reply → notify discussion author.
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_reply_created' ), 10, 4 );

		// Search index: discussion created.
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 10, 4 );
	}

	/**
	 * Create a jt.discussion_reply notification when a reply is posted.
	 *
	 * Hooked on: jetonomy_after_create_reply($reply_id, $post_id, $replier_id, $post_author_id)
	 *
	 * @param int $reply_id      New reply ID.
	 * @param int $post_id       Parent discussion post ID.
	 * @param int $replier_id    User who posted the reply.
	 * @param int $post_author_id Author of the parent discussion.
	 */
	public function on_reply_created( int $reply_id, int $post_id, int $replier_id, int $post_author_id ): void {
		// Never self-notify.
		if ( $replier_id === $post_author_id ) {
			return;
		}

		( new NotificationService() )->create(
			array(
				'recipient_id' => $post_author_id,
				'sender_id'    => $replier_id,
				'type'         => 'jt.discussion_reply',
				'object_type'  => 'discussion',
				'object_id'    => $post_id,
				'group_key'    => "jt_reply_{$post_id}_{$post_author_id}",
				'data'         => array( 'reply_id' => $reply_id ),
			)
		);
	}

	/**
	 * Index a Jetonomy discussion in bn_search_index.
	 *
	 * Hooked on: jetonomy_after_create_post($post_id, $author_id, $title, $content)
	 *
	 * @param int    $post_id   Discussion post ID.
	 * @param int    $author_id Author.
	 * @param string $title     Discussion title.
	 * @param string $content   Discussion body.
	 */
	public function on_post_created( int $post_id, int $author_id, string $title, string $content ): void {
		( new SearchService() )->index( 'discussion', $post_id, $title, $content, $author_id );

		/**
		 * Fires after a Jetonomy discussion is indexed in BuddyNext search.
		 *
		 * Third-party code (e.g. feed sync toggle) can hook here to push
		 * the discussion into bn_posts when the feed_sync option is enabled.
		 *
		 * @param int    $post_id   Discussion ID.
		 * @param int    $author_id Author ID.
		 * @param string $title     Discussion title.
		 * @param string $content   Discussion content excerpt.
		 */
		do_action( 'buddynext_jetonomy_post_indexed', $post_id, $author_id, $title, $content );
	}
}
