<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shared "can this actor engage with this object" trust-and-safety guard.
 *
 * Reactions and comments are both engagement writes against another member's
 * content, so they share two non-negotiable Trust-&-Safety rules:
 *
 *   1. A suspended account is locked out of ALL interaction (spec 09-moderation:
 *      "Suspend — locked out… cannot post/comment/react"), regardless of object
 *      type.
 *   2. When either party in an interaction has blocked the other, the actor may
 *      not engage with that author's content (the same rule PostController uses
 *      to refuse a blocked viewer reading a post).
 *
 * PostService::create() already enforces (1) on the post path. This guard
 * factors the suspension + block pair out of ReactionService and CommentService
 * so the two engagement write paths share one implementation instead of each
 * re-deriving the rules. Services resolve from the container when available and
 * degrade safely (treat as "allowed") when moderation/blocks are unavailable —
 * mirroring the resolution pattern in PostService::is_author_suspended().
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

use WP_Error;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\BlockService;

/**
 * Static guard for engagement writes (reactions, comments).
 */
class InteractionGuard {

	/**
	 * Assert that an actor may engage with an object (react / comment).
	 *
	 * Refuses when the actor is suspended, or — for a post or comment target —
	 * when a block exists between the actor and the object's author. The check
	 * runs before any DB write so a refused interaction never persists.
	 *
	 * @param int    $actor_id    The user attempting the interaction.
	 * @param string $object_type Object type being engaged with ('post', 'comment', …).
	 * @param int    $object_id   Object ID being engaged with.
	 * @return true|WP_Error True when allowed; WP_Error('forbidden', …, 403) when refused.
	 */
	public static function check( int $actor_id, string $object_type, int $object_id ): bool|WP_Error {
		// (1) Suspension is object-type-agnostic: a suspended member cannot
		// react or comment on anything.
		if ( self::is_suspended( $actor_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Your account is suspended and cannot interact with content.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// (2) Block guard: resolve the target object's author and refuse when a
		// block exists in either direction. Only object types with a resolvable
		// author participate; an unknown type (author 0) skips the block check.
		$author_id = self::resolve_author( $object_type, $object_id );
		if ( $author_id > 0 && $author_id !== $actor_id && self::is_blocking_either( $actor_id, $author_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot interact with this content.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Resolve the author of the engaged object.
	 *
	 * A 'post' resolves via PostService::get_author_id(); a 'comment' resolves
	 * via CommentService::get() (the comment's user_id). Any other object type —
	 * or a missing object — yields 0, which the caller treats as "no author to
	 * gate against" and skips the block check.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int Author user ID, or 0 when unresolvable.
	 */
	private static function resolve_author( string $object_type, int $object_id ): int {
		if ( $object_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return 0;
		}

		if ( 'post' === $object_type ) {
			$posts = buddynext_service( 'post_service' );
			return $posts instanceof PostService ? $posts->get_author_id( $object_id ) : 0;
		}

		if ( 'comment' === $object_type ) {
			$comments = buddynext_service( 'comments' );
			if ( $comments instanceof \BuddyNext\Comments\CommentService ) {
				$comment = $comments->get( $object_id );
				return null !== $comment ? (int) $comment['user_id'] : 0;
			}
		}

		return 0;
	}

	/**
	 * Whether the actor currently has an active suspension.
	 *
	 * Resolves the moderation service from the container when available and
	 * falls back to a fresh instance otherwise (e.g. unit-test contexts). Any
	 * failure to resolve degrades to "not suspended" so the engagement path
	 * never fatals when moderation is unavailable — mirroring
	 * PostService::is_author_suspended().
	 *
	 * @param int $actor_id Actor user ID.
	 * @return bool True when the actor has an active, unexpired suspension.
	 */
	private static function is_suspended( int $actor_id ): bool {
		if ( $actor_id <= 0 ) {
			return false;
		}

		$moderation = function_exists( 'buddynext_service' )
			? buddynext_service( 'moderation' )
			: new ModerationService();

		if ( ! $moderation instanceof ModerationService ) {
			return false;
		}

		return $moderation->is_suspended( $actor_id );
	}

	/**
	 * Whether a block exists between the two users in either direction.
	 *
	 * Resolves BlockService from the container; degrades to "not blocking" when
	 * the service is unavailable so the engagement path never fatals.
	 *
	 * @param int $actor_id  The acting user.
	 * @param int $author_id The engaged object's author.
	 * @return bool True when either user has blocked the other.
	 */
	private static function is_blocking_either( int $actor_id, int $author_id ): bool {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		$blocks = buddynext_service( 'blocks' );

		return $blocks instanceof BlockService && $blocks->is_blocking_either( $actor_id, $author_id );
	}
}
