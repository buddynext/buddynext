<?php
/**
 * What a viewer sees of a comment thread, and the one renderer for it.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Comments;

use BuddyNext\Profile\AvatarService;
use BuddyNext\Reactions\ReactionService;

/**
 * Turns stored comments into the viewer's view of them (author, avatar,
 * reactions, permissions, pinned / deleted / under-review state, formatted
 * content) and renders that view as HTML through templates/parts/comment.php.
 *
 * The REST list, create and update responses, and the server-rendered first
 * page of a post's comments all come from here, so the page, the API and the
 * HTML a client inserts can never describe a comment differently. Before, the
 * enrichment lived in three controller copies and the markup was built twice
 * (PHP had none; the browser built it from JSON), which is how pinned comments
 * lost their badge and edited comments lost their links.
 */
final class CommentThread {

	/**
	 * Top-level comments per page on the web (first load and each "View more").
	 * The list publishes it as data-per-page so the script asks for the same size.
	 */
	public const PAGE_SIZE = 6;

	/**
	 * Comment service.
	 *
	 * @var CommentService
	 */
	private CommentService $comments;

	/**
	 * Reaction service.
	 *
	 * @var ReactionService
	 */
	private ReactionService $reactions;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->comments  = new CommentService();
		$this->reactions = new ReactionService();
	}

	/**
	 * One page of top-level comments (with their reply trees) as the viewer sees it.
	 *
	 * @param string $object_type Object type ('post').
	 * @param int    $object_id   Object id.
	 * @param int    $viewer_id   Viewer (0 = guest).
	 * @param int    $page        1-based page.
	 * @param int    $per_page    Top-level comments per page.
	 * @return array<string, mixed> CommentService::list() result with enriched items.
	 */
	public function page( string $object_type, int $object_id, int $viewer_id, int $page, int $per_page ): array {
		$result = $this->comments->list(
			$object_type,
			$object_id,
			array(
				'per_page' => $per_page,
				'page'     => $page,
			)
		);

		// Batch-prime the whole thread so the per-comment author and reaction
		// lookups in enrich() are cache hits, not ~3 queries per comment (C9.3).
		$ids     = array();
		$authors = array();
		$collect = function ( array $comment ) use ( &$collect, &$ids, &$authors ): void {
			$ids[]     = (int) $comment['id'];
			$authors[] = (int) $comment['user_id'];
			array_map( $collect, (array) ( $comment['replies'] ?? array() ) );
		};
		array_map( $collect, (array) $result['items'] );
		if ( $authors ) {
			AvatarService::prime( array_values( array_unique( array_filter( $authors ) ) ) );
		}
		if ( $viewer_id > 0 ) {
			$this->reactions->get_user_emoji_map( $viewer_id, 'comment', $ids );
			buddynext_service( 'moderation' )->user_reported_map( $viewer_id, 'comment', $ids );
		}
		$this->reactions->count_map( 'comment', $ids );

		$scope           = $this->scope( $object_type, $object_id, $viewer_id );
		$walk            = function ( array $comment ) use ( &$walk, $viewer_id, $scope ): array {
			$comment = $this->enrich( $comment, $viewer_id, $scope );
			if ( ! empty( $comment['replies'] ) ) {
				$comment['replies'] = array_map( $walk, $comment['replies'] );
			}
			return $comment;
		};
		$result['items'] = array_map( $walk, (array) $result['items'] );

		return $result;
	}

	/**
	 * One comment as the viewer sees it (no replies), for create / update responses.
	 *
	 * @param int $comment_id Comment id.
	 * @param int $viewer_id  Viewer.
	 * @return array<string, mixed>|null
	 */
	public function one( int $comment_id, int $viewer_id ): ?array {
		$comment = $this->comments->get( $comment_id );
		if ( null === $comment ) {
			return null;
		}
		$comment['replies'] = array();
		return $this->enrich(
			$comment,
			$viewer_id,
			$this->scope( (string) ( $comment['object_type'] ?? '' ), (int) ( $comment['object_id'] ?? 0 ), $viewer_id )
		);
	}

	/**
	 * Per-object facts every comment on the object shares, resolved once.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @param int    $viewer_id   Viewer.
	 * @return array{can_pin:bool, pinned_id:int}
	 */
	private function scope( string $object_type, int $object_id, int $viewer_id ): array {
		return array(
			// Admins, plus moderators of the post's space, may pin.
			'can_pin'   => $this->comments->can_pin_comment( $viewer_id, $object_type, $object_id ),
			'pinned_id' => (int) get_option( 'bn_pinned_comment_' . sanitize_key( $object_type ) . '_' . $object_id, 0 ),
		);
	}

	/**
	 * Add the viewer-facing fields to one stored comment.
	 *
	 * @param array<string, mixed>               $comment   Stored comment row.
	 * @param int                                $viewer_id Viewer.
	 * @param array{can_pin:bool, pinned_id:int} $scope     Per-object facts.
	 * @return array<string, mixed>
	 */
	private function enrich( array $comment, int $viewer_id, array $scope ): array {
		$id                           = (int) $comment['id'];
		$is_own                       = $viewer_id > 0 && (int) $comment['user_id'] === $viewer_id;
		$comment['author_name']       = (string) get_the_author_meta( 'display_name', $comment['user_id'] );
		$comment['author_avatar_url'] = (string) get_avatar_url( $comment['user_id'], array( 'size' => 40 ) );
		$comment['like_count']        = $this->reactions->count( 'comment', $id );
		$comment['viewer_liked']      = $viewer_id > 0 && $this->reactions->has_reacted( $viewer_id, 'comment', $id );
		// The specific emoji, so the button shows the right icon, not always 'like'.
		$comment['viewer_reaction'] = $viewer_id > 0 ? $this->reactions->get_user_emoji( $viewer_id, 'comment', $id ) : null;
		// Read from the map page() primed: one query for the whole thread.
		$comment['viewer_reported'] = $viewer_id > 0 && buddynext_service( 'moderation' )->user_has_reported( $viewer_id, 'comment', $id );
		$comment['can_edit']        = $is_own || buddynext_can( $viewer_id, 'buddynext-feed/edit-any-post' );
		$comment['can_delete']      = $is_own || buddynext_can( $viewer_id, 'buddynext-feed/delete-any-post' );
		// pin() refuses a reply, so only a top-level comment offers Pin.
		$comment['can_pin']          = $scope['can_pin'] && empty( $comment['parent_id'] );
		$comment['is_pinned']        = $scope['pinned_id'] > 0 && $id === $scope['pinned_id'];
		$comment['author_meta_html'] = wp_kses_post( (string) apply_filters( 'buddynext_comment_author_meta_html', '', (int) $comment['user_id'], $id ) );

		if ( ! empty( $comment['is_deleted'] ) ) {
			// The thread keeps its shape without naming the author.
			$comment['author_name']       = __( 'Deleted member', 'buddynext' );
			$comment['author_avatar_url'] = '';
			$comment['content']           = __( '[deleted]', 'buddynext' );
			$comment['can_edit']          = false;
			$comment['can_delete']        = false;
			$comment['can_pin']           = false;
			$comment['like_count']        = 0;
			$comment['viewer_liked']      = false;
			$comment['viewer_reaction']   = null;
		}

		// Under review (auto-hidden by reports): only its author or a moderator
		// receives it at all (CommentService drops it for everyone else). It keeps
		// its content, is labelled, and freezes reply / react / report / pin.
		$comment['is_hidden'] = ! empty( $comment['is_hidden'] );
		if ( $comment['is_hidden'] ) {
			$comment['can_pin'] = false;
		}

		// Escaped text with @mentions and #hashtags linked, as post bodies render.
		// The raw `content` stays for the edit field.
		$comment['content_html'] = buddynext_format_content( (string) $comment['content'] );

		return $comment;
	}

	/**
	 * The reaction set a viewer may use on comments, for the picker and the list.
	 *
	 * Empty when the owner switched Reactions off or the viewer may not interact
	 * (suspended, or the owner raised "React, share, bookmark and vote").
	 *
	 * @param int $viewer_id Viewer.
	 * @return array<int, array{slug:string, label:string, char:string, color:string, emoji_url:string}>
	 */
	public static function reaction_set( int $viewer_id ): array {
		if ( ! buddynext_feature_enabled( 'reactions' ) ) {
			return array();
		}
		if ( $viewer_id > 0 && ! buddynext_can( $viewer_id, 'buddynext-feed/interact' ) ) {
			return array();
		}

		$set = array();
		foreach ( ReactionService::enabled_reactions() as $reaction ) {
			$slug = (string) ( $reaction['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			$set[] = array(
				'slug'      => $slug,
				'label'     => (string) ( $reaction['label'] ?? $slug ),
				'char'      => (string) ( $reaction['char'] ?? '' ),
				'color'     => (string) ( $reaction['color'] ?? '' ),
				'emoji_url' => \BuddyNext\Core\IconService::emoji_url( $slug ),
			);
		}
		return $set;
	}

	/**
	 * Render comments (each with its replies) as HTML.
	 *
	 * @param array<int, array<string, mixed>> $comments  Enriched comments.
	 * @param int                              $post_id   Post the thread belongs to.
	 * @param int                              $viewer_id Viewer.
	 * @param int                              $depth     Nesting depth of these comments.
	 * @return string
	 */
	public static function render( array $comments, int $post_id, int $viewer_id, int $depth = 0 ): string {
		$reactions = self::reaction_set( $viewer_id );
		ob_start();
		foreach ( $comments as $comment ) {
			buddynext_get_template(
				'parts/comment.php',
				array(
					'comment'   => $comment,
					'post_id'   => $post_id,
					'viewer_id' => $viewer_id,
					'depth'     => $depth,
					'reactions' => $reactions,
				)
			);
		}
		return (string) ob_get_clean();
	}
}
