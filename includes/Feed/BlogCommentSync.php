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
 * the card shows up under the post. The pair then stays one comment: an edit,
 * a reply, a takedown or a moderation decision on either side reaches the other.
 * On the post a WordPress moderation status (held, spam, trash) hides the card
 * copy until it is approved again; a permanent delete removes it. On the card an
 * edit rewrites the post copy and a delete moves it to the trash.
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
	 * Run a write that must not echo back through the sync handlers, and always lift the guard.
	 *
	 * @param callable $write The cross-boundary write.
	 * @return void
	 */
	private static function quiet( callable $write ): void {
		self::$syncing = true;
		try {
			$write();
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * Register both directions.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_insert_comment', array( $this, 'sync_wp_comment_to_card' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'sync_wp_status_to_card' ), 10, 3 );
		add_action( 'edit_comment', array( $this, 'sync_wp_edit_to_card' ), 10, 1 );
		add_action( 'buddynext_comment_created', array( $this, 'sync_card_comment_to_post' ), 10, 4 );
		add_action( 'buddynext_comment_updated', array( $this, 'sync_card_edit_to_post' ), 10, 1 );
		add_action( 'buddynext_comment_deleted', array( $this, 'sync_card_delete_to_post' ), 10, 1 );
	}

	/**
	 * Is mirroring switched on for this request?
	 *
	 * Sync is right for a live conversation and wrong for a bulk replay of one.
	 * A migration importing a post's existing comments onto its card would,
	 * with sync live, mirror every one of them straight back onto the post -
	 * which already has them - and double the thread. The importer switches
	 * this off for the duration of a run, the same way it already suppresses
	 * notification sends and rate limits while replaying history.
	 *
	 * @return bool
	 */
	private function sync_enabled(): bool {
		/**
		 * Filter whether comments mirror between a post and its feed card.
		 *
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'buddynext_sync_blog_comments', true );
	}

	/**
	 * A comment on the blog post becomes a comment on its feed card.
	 *
	 * @param int         $comment_id New WP comment id.
	 * @param \WP_Comment $comment    The comment object.
	 * @return void
	 */
	public function sync_wp_comment_to_card( $comment_id, $comment ): void {
		// Switched to another site of a network: the community tables are not there.
		if ( ! buddynext_is_community_site() ) {
			return;
		}
		if ( self::$syncing || ! $this->sync_enabled() || ! $comment instanceof \WP_Comment ) {
			return;
		}

		// Only real, approved, human comments. Pingbacks and trackbacks are not
		// community conversation, and an unapproved or spam comment must never
		// appear in the feed - moderation on the post has to mean something on
		// the card too.
		if ( '1' !== (string) $comment->comment_approved || ( '' !== (string) $comment->comment_type && 'comment' !== (string) $comment->comment_type ) ) {
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
		if ( null !== $this->card_copy( (int) $comment_id, $card_id ) ) {
			return; // Already on the card (an approval after an earlier mirror).
		}

		// A reply on the post stays a reply on the card, under the parent's copy.
		$parent    = (int) $comment->comment_parent > 0 ? $this->card_copy( (int) $comment->comment_parent, $card_id ) : null;
		$parent_id = null !== $parent ? (int) $parent['id'] : null;
		self::quiet(
			function () use ( $author_id, $card_id, $comment_id, $content, $parent_id ) {
				$comments = new CommentService();
				// CommentService applies its own permission gate and sanitizes; a
				// WP_Error simply means no mirrored comment.
				$bn_comment_id = $comments->create( $author_id, 'post', $card_id, $content, $parent_id );
				if ( ! is_wp_error( $bn_comment_id ) && (int) $bn_comment_id > 0 ) {
						$comments->set_sync_reply_id( (int) $bn_comment_id, (int) $comment_id );
				}
			}
		);
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
		if ( self::$syncing || ! $this->sync_enabled() || 'post' !== (string) $object_type ) {
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

		// A reply on the card stays a reply on the post, under the parent's copy.
		$parent = ! empty( $comment['parent_id'] ) ? $this->post_copy( (int) $comment['parent_id'], $post_id ) : null;

		self::quiet(
			function () use ( $comment_id, $content, $parent, $post_id, $user, $user_id ) {
				// wp_insert_comment() unslashes what it is given, so the text is slashed
				// first or a backslash the member typed would be lost.
				$wp_comment_id = wp_insert_comment(
					wp_slash(
						array(
							'comment_post_ID'      => $post_id,
							'comment_parent'       => null !== $parent ? (int) $parent->comment_ID : 0,
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
					)
				);
				if ( $wp_comment_id ) {
					( new CommentService() )->set_sync_reply_id( (int) $comment_id, (int) $wp_comment_id );
				}
			}
		);
	}

	/**
	 * A moderation decision on the post comment reaches its card copy.
	 *
	 * Held, spam or trash hides the copy (reversible, as it is on the post);
	 * approval shows it again, or mirrors it for the first time when it was held
	 * from the start; a permanent delete takes the copy down for good.
	 *
	 * @param string      $new_status New status: approved, unapproved, spam, trash or delete.
	 * @param string      $old_status Previous status.
	 * @param \WP_Comment $comment    The comment.
	 * @return void
	 */
	public function sync_wp_status_to_card( $new_status, $old_status, $comment ): void {
		unset( $old_status );
		if ( ! buddynext_is_community_site() || self::$syncing || ! $this->sync_enabled() || ! $comment instanceof \WP_Comment ) {
			return;
		}

		$card_id = $this->card_id_for_post( (int) $comment->comment_post_ID );
		if ( $card_id <= 0 ) {
			return;
		}
		$copy = $this->card_copy( (int) $comment->comment_ID, $card_id );

		if ( 'approved' === $new_status ) {
			if ( null === $copy ) {
				$this->sync_wp_comment_to_card( (int) $comment->comment_ID, $comment );
				return;
			}
			$comments = new CommentService();
			self::quiet(
				function () use ( $comment, $comments, $copy ) {
					if ( ! empty( $copy['is_deleted'] ) ) {
							$comments->restore( (int) $copy['id'], (string) $comment->comment_content );
					} else {
						$comments->set_hidden( (int) $copy['id'], false );
					}
				}
			);
			return;
		}

		if ( null === $copy || ! empty( $copy['is_deleted'] ) ) {
			return;
		}

		$comments = new CommentService();
		self::quiet(
			function () use ( $comments, $copy, $new_status ) {
				if ( 'delete' === $new_status ) {
						$comments->soft_delete( (int) $copy['id'], 0 );
				} else {
					$comments->set_hidden( (int) $copy['id'], true );
				}
			}
		);
	}

	/**
	 * An edit to the post comment rewrites its card copy.
	 *
	 * Runs as the copy's own author, so CommentService's ownership gate passes and
	 * its content rules (banned words) still apply.
	 *
	 * @param int $comment_id Edited WP comment id.
	 * @return void
	 */
	public function sync_wp_edit_to_card( $comment_id ): void {
		if ( ! buddynext_is_community_site() || self::$syncing || ! $this->sync_enabled() ) {
			return;
		}
		$comment = get_comment( (int) $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$card_id = $this->card_id_for_post( (int) $comment->comment_post_ID );
		$copy    = $card_id > 0 ? $this->card_copy( (int) $comment->comment_ID, $card_id ) : null;
		$content = trim( (string) $comment->comment_content );
		if ( null === $copy || ! empty( $copy['is_deleted'] ) || '' === $content ) {
			return;
		}

		self::quiet(
			function () use ( $content, $copy ) {
				( new CommentService() )->update( (int) $copy['id'], (int) $copy['user_id'], $content );
			}
		);
	}

	/**
	 * An edit on the card rewrites the post copy.
	 *
	 * @param int $comment_id Edited BuddyNext comment id.
	 * @return void
	 */
	public function sync_card_edit_to_post( $comment_id ): void {
		if ( self::$syncing || ! $this->sync_enabled() ) {
			return;
		}
		$wp_comment = $this->post_copy_of( (int) $comment_id );
		$comment    = null !== $wp_comment ? ( new CommentService() )->get( (int) $comment_id ) : null;
		$content    = null !== $comment ? trim( (string) ( $comment['content'] ?? '' ) ) : '';
		if ( null === $wp_comment || '' === $content ) {
			return;
		}

		self::quiet(
			function () use ( $content, $wp_comment ) {
				wp_update_comment(
					wp_slash(
						array(
							'comment_ID'      => (int) $wp_comment->comment_ID,
							'comment_content' => $content,
						)
					)
				);
			}
		);
	}

	/**
	 * A delete on the card (by its author, an admin or a moderator) moves the post
	 * copy to the trash, so the text is no longer public and can still be restored.
	 *
	 * @param int $comment_id Deleted BuddyNext comment id.
	 * @return void
	 */
	public function sync_card_delete_to_post( $comment_id ): void {
		if ( self::$syncing || ! $this->sync_enabled() ) {
			return;
		}
		$wp_comment = $this->post_copy_of( (int) $comment_id );
		if ( null === $wp_comment || 'trash' === wp_get_comment_status( $wp_comment ) ) {
			return;
		}

		self::quiet(
			function () use ( $wp_comment ) {
				wp_trash_comment( (int) $wp_comment->comment_ID );
			}
		);
	}

	/**
	 * The card copy of a post comment: the card comment whose pair id is it.
	 *
	 * Scoped to the article's card because bn_comments.sync_reply_id also holds
	 * forum reply ids for discussion cards, and the two id ranges overlap.
	 *
	 * @param int $wp_comment_id WP comment id.
	 * @param int $card_id       The post's feed card id.
	 * @return array{id:int,user_id:int,is_deleted:int}|null
	 */
	private function card_copy( int $wp_comment_id, int $card_id ): ?array {
		global $wpdb;

		if ( $wp_comment_id <= 0 || $card_id <= 0 ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, is_deleted FROM {$wpdb->prefix}bn_comments
				  WHERE sync_reply_id = %d AND object_type = 'post' AND object_id = %d
				  LIMIT 1",
				$wp_comment_id,
				$card_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? array_map( 'intval', $row ) : null;
	}

	/**
	 * The post copy of a card comment, when the card is an article card.
	 *
	 * @param int $comment_id BuddyNext comment id.
	 * @return \WP_Comment|null
	 */
	private function post_copy_of( int $comment_id ): ?\WP_Comment {
		$comment = $comment_id > 0 ? ( new CommentService() )->get( $comment_id ) : null;
		if ( null === $comment || 'post' !== (string) $comment['object_type'] ) {
			return null;
		}
		$post_id = $this->post_id_for_card( (int) $comment['object_id'] );

		return $post_id > 0 ? $this->post_copy( $comment_id, $post_id ) : null;
	}

	/**
	 * The WP comment paired with a card comment, checked to be on the article.
	 *
	 * @param int $comment_id BuddyNext comment id.
	 * @param int $post_id    The article the card was published from.
	 * @return \WP_Comment|null
	 */
	private function post_copy( int $comment_id, int $post_id ): ?\WP_Comment {
		$wp_id      = ( new CommentService() )->get_sync_reply_id( $comment_id );
		$wp_comment = $wp_id > 0 ? get_comment( $wp_id ) : null;

		return $wp_comment instanceof \WP_Comment && (int) $wp_comment->comment_post_ID === $post_id ? $wp_comment : null;
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
