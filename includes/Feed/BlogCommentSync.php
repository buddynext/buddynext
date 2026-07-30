<?php
/**
 * Two-way comment sync between a published post and its feed card.
 *
 * A member publishes an article, it appears in the feed, and the conversation
 * then splits in two: replies under the blog post and replies under the feed
 * card, neither aware of the other. On a platform people came to for blogging
 * that is the wrong outcome - it is one article and it should have one
 * discussion, wherever a member happens to be standing.
 *
 * So a comment left on the post shows up under the card, and a comment left on
 * the card shows up under the post.
 *
 * The mechanism is the one the Jetonomy forum bridge already uses for
 * discussion cards: `bn_comments.sync_reply_id` holds the paired id, resolved
 * forward with get_sync_reply_id() and backward with find_by_sync_reply_id(),
 * so no second mapping table is needed and either direction can find its twin.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Contracts\ListenerInterface;

/**
 * Mirrors comments between a WordPress post and its BuddyNext feed card.
 */
class BlogCommentSync implements ListenerInterface {

	/**
	 * Re-entrancy guard.
	 *
	 * Each direction of the sync fires the hook the OTHER direction listens on:
	 * inserting a WP comment fires wp_insert_comment, creating a BN comment
	 * fires buddynext_comment_created. Without this flag the first comment
	 * would bounce between the two forever.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	/**
	 * Register both directions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_insert_comment', array( $this, 'sync_wp_comment_to_card' ), 10, 2 );
		add_action( 'buddynext_comment_created', array( $this, 'sync_card_comment_to_post' ), 10, 4 );
	}

	/**
	 * A comment on the blog post becomes a comment on its feed card.
	 *
	 * @param int         $comment_id New WP comment id.
	 * @param \WP_Comment $comment    The comment object.
	 * @return void
	 */
	public function sync_wp_comment_to_card( $comment_id, $comment ): void {
		if ( self::$syncing || ! $comment instanceof \WP_Comment ) {
			return;
		}

		// Only real, approved, human comments. Pingbacks and trackbacks are not
		// community conversation, and an unapproved or spam comment must never
		// appear in the feed - moderation on the post has to mean something on
		// the card too.
		if ( '1' !== (string) $comment->comment_approved || '' !== (string) $comment->comment_type && 'comment' !== (string) $comment->comment_type ) {
			return;
		}

		$author_id = (int) $comment->user_id;
		$content   = trim( (string) $comment->comment_content );
		if ( $author_id <= 0 || '' === $content ) {
			return; // A logged-out commenter has no member account to attribute this to.
		}

		$card_id = $this->card_id_for_post( (int) $comment->comment_post_ID );
		if ( $card_id <= 0 ) {
			return; // This post has no feed card - nothing to mirror onto.
		}

		self::$syncing = true;
		$comments      = new CommentService();
		// CommentService applies its own permission gate and sanitizes; a
		// WP_Error simply means no mirrored comment.
		$bn_comment_id = $comments->create( $author_id, 'post', $card_id, $content );
		if ( ! is_wp_error( $bn_comment_id ) && (int) $bn_comment_id > 0 ) {
			$comments->set_sync_reply_id( (int) $bn_comment_id, (int) $comment_id );
		}
		self::$syncing = false;
	}

	/**
	 * A comment on the feed card becomes a comment on the blog post.
	 *
	 * @param int    $comment_id  New BuddyNext comment id.
	 * @param string $object_type Commented object type.
	 * @param int    $object_id   Commented object id (the feed card).
	 * @param int    $user_id     Comment author.
	 * @return void
	 */
	public function sync_card_comment_to_post( $comment_id, $object_type, $object_id, $user_id ): void {
		if ( self::$syncing || 'post' !== (string) $object_type ) {
			return;
		}

		$card_id = (int) $object_id;
		$post_id = $this->post_id_for_card( $card_id );
		if ( $post_id <= 0 ) {
			return; // Not an article card.
		}

		$comment = ( new CommentService() )->get( (int) $comment_id );
		$content = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( '' === $content || (int) $user_id <= 0 ) {
			return;
		}

		// Respect the post's own comment status. If the author closed comments
		// on the article, the feed must not reopen them by the back door.
		if ( ! comments_open( $post_id ) ) {
			return;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return;
		}

		self::$syncing = true;
		$wp_comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'user_id'              => (int) $user_id,
				'comment_author'       => $user->display_name,
				'comment_author_email' => $user->user_email,
				'comment_content'      => $content,
				'comment_type'         => 'comment',
				// Mirrored from a member who already passed the community's own
				// permission gate, so it enters approved rather than sitting in
				// a queue the article author may never look at.
				'comment_approved'     => 1,
			)
		);
		if ( $wp_comment_id ) {
			( new CommentService() )->set_sync_reply_id( (int) $comment_id, (int) $wp_comment_id );
		}
		self::$syncing = false;
	}

	/**
	 * The feed card for a post, if it has one.
	 *
	 * @param int $post_id WordPress post id.
	 * @return int Feed card id, or 0.
	 */
	private function card_id_for_post( int $post_id ): int {
		// One implementation of the post -> card pairing, owned by the listener
		// that creates it. Two copies of this lookup would be two opinions about
		// which card belongs to which article.
		return BlogPostListener::card_id_for_post( $post_id );
	}

	/**
	 * The post a feed card was published from, if any.
	 *
	 * @param int $card_id Feed card id.
	 * @return int WordPress post id, or 0.
	 */
	private function post_id_for_card( int $card_id ): int {
		global $wpdb;

		if ( $card_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT type, link_meta FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$card_id
			),
			ARRAY_A
		);

		if ( null === $row || BlogPostListener::TYPE !== (string) $row['type'] ) {
			return 0;
		}

		$meta = json_decode( (string) $row['link_meta'], true );

		return is_array( $meta ) ? (int) ( $meta[ BlogPostListener::META_POST_ID ] ?? 0 ) : 0;
	}
}
