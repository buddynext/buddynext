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
	 * The closed enum of object types engagement (reactions/comments) is allowed
	 * against, extensible by a partner via the buddynext_engagement_object_types
	 * filter. ONE definition of a security-relevant default — the guard and the
	 * comment-reply path both read it here, so the next type added cannot land in
	 * one copy and not the other (card 10264292715).
	 *
	 * 'media' is deliberately NOT default: BuddyNext core owns no media objects
	 * (WPMediaVerse does), and buddynext_object_exists() returns null for a type it
	 * cannot verify, which the target validator treats as "cannot prove gone =
	 * allow" — so a default 'media' let a nonexistent media id write a junk row on
	 * a site without the partner. WPMediaVerse re-registers it via the filter with
	 * its own resolver.
	 *
	 * @return array<int,string> Allowed object types.
	 */
	public static function allowed_object_types(): array {
		return array_values( (array) apply_filters( 'buddynext_engagement_object_types', array( 'post', 'comment' ) ) );
	}

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
		// (0) The target must be a real object of a type engagement is allowed
		// against (see allowed_object_types()). Without this, a bogus object_type or
		// a nonexistent id wrote a junk reaction/comment row (with phantom counters
		// and notifications) and slipped past the block check below, which resolves
		// author 0 for an unknown type and then waves it through.
		$allowed = self::allowed_object_types();
		$valid   = buddynext_validate_object_target( $object_type, $object_id, $allowed );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// (0b) State, not existence. buddynext_validate_object_target() only proves
		// the ROW is present; a comment soft-deleted via CommentService::delete()
		// keeps its row (is_deleted = 1) so the thread stays intact, and reacting to
		// or replying under that tombstone produced a phantom counter on a "[deleted]"
		// comment (card 10264292715). A removed POST is caught by the visibility gate
		// below (its status is no longer readable), so only the comment case needs an
		// explicit check here.
		if ( 'comment' === $object_type && self::comment_is_deleted( $object_id ) ) {
			return new WP_Error(
				'object_deleted',
				__( 'This content has been deleted and can no longer be reacted to or replied to.', 'buddynext' ),
				array( 'status' => 410 )
			);
		}

		// (0c) Visibility. This gate used to live ONLY in the REST controllers, so a
		// non-REST writer (WP-CLI, a bridge, an admin bulk action) reacting or
		// commenting bypassed space-privacy and post-visibility entirely
		// (card 10264292715). Enforcing it here — the one seam every engagement write
		// funnels through — closes that. The REST controllers keep their own 404 check
		// in front of the service, so the member-facing response is unchanged; this is
		// the backstop for every other caller.
		$hidden = self::target_hidden_from( $actor_id, $object_type, $object_id );
		if ( $hidden instanceof WP_Error ) {
			return $hidden;
		}

		// (1) Suspension is object-type-agnostic: a suspended member cannot
		// react or comment on anything.
		if ( self::is_suspended( $actor_id ) ) {
			return ModerationService::suspension_error(
				__( 'Your account is suspended and cannot interact with content.', 'buddynext' )
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
	 * Whether a comment target has been soft-deleted (tombstoned).
	 *
	 * CommentService::delete() sets is_deleted = 1 rather than removing the row, so
	 * the thread stays intact; get() returns the tombstone with its is_deleted flag.
	 * Degrades to "not deleted" (allow) when comments are unavailable, matching the
	 * fail-open pattern of the other resolvers here.
	 *
	 * @param int $object_id Comment ID.
	 * @return bool True when the comment exists and is soft-deleted.
	 */
	private static function comment_is_deleted( int $object_id ): bool {
		if ( $object_id <= 0 || ! function_exists( 'buddynext_service' ) ) {
			return false;
		}

		$comments = buddynext_service( 'comments' );
		if ( ! $comments instanceof \BuddyNext\Comments\CommentService ) {
			return false;
		}

		$comment = $comments->get( $object_id );

		return null !== $comment && ! empty( $comment['is_deleted'] );
	}

	/**
	 * Whether the engaged object's root post is hidden from the acting user.
	 *
	 * Resolves the target to its owning post (a comment walks up its reply chain
	 * via PostService::resolve_post_id) and runs the same visibility_error() the
	 * post read gates use, against the ACTOR as the viewer. Returns a 404-style
	 * error — mirroring the REST controllers' existence-hiding choice — so a member
	 * cannot confirm the existence of content in a space they cannot see. Degrades
	 * to null (allow) when the post service is unavailable or the target has no
	 * gateable post.
	 *
	 * @param int    $actor_id    The user attempting the interaction (the viewer).
	 * @param string $object_type Object type being engaged with.
	 * @param int    $object_id   Object ID being engaged with.
	 * @return WP_Error|null Error when hidden; null when visible or unresolvable.
	 */
	private static function target_hidden_from( int $actor_id, string $object_type, int $object_id ): ?WP_Error {
		if ( ! function_exists( 'buddynext_service' ) ) {
			return null;
		}

		$posts = buddynext_service( 'post_service' );
		if ( ! $posts instanceof PostService ) {
			return null;
		}

		$post_id = $posts->resolve_post_id( $object_type, $object_id );
		if ( $post_id <= 0 ) {
			return null;
		}

		if ( ! $posts->visibility_error( $post_id, $actor_id ) instanceof WP_Error ) {
			return null;
		}

		return new WP_Error(
			'object_not_found',
			__( 'That content is not available.', 'buddynext' ),
			array( 'status' => 404 )
		);
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
