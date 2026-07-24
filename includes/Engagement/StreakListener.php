<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Keeps the streak summary honest.
 *
 * StreakService caches `streak-summary:{uid}` and used to have NO invalidation at all — just a
 * 300s TTL, with a comment claiming "a short TTL keeps the strip honest as the user is active
 * mid-session." It did not. The member posted, extended their streak, refreshed, and saw the old
 * number for up to five minutes, on a surface whose entire job is immediate feedback.
 *
 * The summary is a 30-day UNION over bn_posts / bn_comments / bn_reactions, so those three
 * lifecycle signals — and only those — are what must bust it.
 *
 * ⚠ THE ARGUMENT POSITIONS BELOW ARE LOAD-BEARING. READ THIS BEFORE EDITING.
 *
 * These hooks are OBJECT-GENERIC: several of them pass a string `$object_type` and an int
 * `$object_id` BEFORE the user id. The hook table in CLAUDE.md documented them wrongly until
 * 64ed9138, and a listener wired from the old doc would have read `$object_id` as a user id —
 * busting the cache of whichever member happens to have the user id matching that post's id.
 * A random third party's streak would reset while the actual author kept seeing a stale one.
 *
 * Every `accepted_args` count and every parameter position here is taken from the real
 * `do_action()` call site. If you change one, run `bin/check-hook-docs.py`.
 *
 *   buddynext_post_created      ( $post_id, $user_id, $type )                        user = arg 2
 *   buddynext_post_deleted      ( $post_id, $user_id )                               user = arg 2
 *   buddynext_comment_created   ( $comment_id, $object_type, $object_id, $user_id )  user = arg 4
 *   buddynext_comment_deleted   ( $comment_id, $user_id )                            user = arg 2
 *   buddynext_reaction_added    ( $object_type, $object_id, $user_id, $emoji )       user = arg 3
 *   buddynext_reaction_removed  ( $object_type, $object_id, $user_id, $emoji )       user = arg 3
 *
 * @package BuddyNext\Engagement
 * @since   1.0.8
 */

declare( strict_types=1 );

namespace BuddyNext\Engagement;

use BuddyNext\Contracts\ListenerInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Busts a member's cached streak summary when they do something it counts.
 */
class StreakListener implements ListenerInterface {

	/**
	 * Wire the three signals the streak summary is built from.
	 *
	 * Deletions are hooked as well as creations: deleting your only post of the day shortens
	 * the streak, and a summary that only refreshes on the way up is still lying.
	 *
	 * @return void
	 */
	public function register(): void {
		// Both hooks fire with post id then user id; we accept 2 args and ignore the rest.
		add_action( 'buddynext_post_created', array( $this, 'bust_from_post' ), 10, 2 );
		add_action( 'buddynext_post_deleted', array( $this, 'bust_from_post' ), 10, 2 );

		// ( $comment_id, $object_type, $object_id, $user_id ) — the user is arg 4, so take all 4.
		add_action( 'buddynext_comment_created', array( $this, 'bust_from_comment_created' ), 10, 4 );

		// ( $comment_id, $user_id ) — only 2 args are fired. Asking for 3 would be an
		// ArgumentCountError under PHP 8.
		add_action( 'buddynext_comment_deleted', array( $this, 'bust_from_comment_deleted' ), 10, 2 );

		// ( $object_type, $object_id, $user_id, $emoji ) — the user is arg 3.
		add_action( 'buddynext_reaction_added', array( $this, 'bust_from_reaction' ), 10, 3 );
		add_action( 'buddynext_reaction_removed', array( $this, 'bust_from_reaction' ), 10, 3 );
	}

	/**
	 * Post created/deleted: the author's streak changed.
	 *
	 * @param int $post_id Post ID (unused — the member is what we key on).
	 * @param int $user_id Author.
	 * @return void
	 */
	public function bust_from_post( int $post_id, int $user_id ): void {
		StreakService::forget( $user_id );
	}

	/**
	 * Comment created: the commenter's streak changed.
	 *
	 * The user id is the FOURTH argument. `$object_id` is the third and is NOT a user.
	 *
	 * @param int    $comment_id  Comment ID (unused).
	 * @param string $object_type What was commented on ('post', …) (unused).
	 * @param int    $object_id   The commented-on object (unused — NOT a user id).
	 * @param int    $user_id     The commenter.
	 * @return void
	 */
	public function bust_from_comment_created( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		StreakService::forget( $user_id );
	}

	/**
	 * Comment deleted: the commenter's streak changed.
	 *
	 * Only two args are fired for this one — see the class docblock.
	 *
	 * @param int $comment_id Comment ID (unused).
	 * @param int $user_id    The commenter.
	 * @return void
	 */
	public function bust_from_comment_deleted( int $comment_id, int $user_id ): void {
		StreakService::forget( $user_id );
	}

	/**
	 * Reaction added/removed: the reactor's streak changed.
	 *
	 * The user id is the THIRD argument. `$object_id` is the second and is NOT a user.
	 *
	 * @param string $object_type What was reacted to ('post', 'comment', …) (unused).
	 * @param int    $object_id   The reacted-to object (unused — NOT a user id).
	 * @param int    $user_id     The reactor.
	 * @return void
	 */
	public function bust_from_reaction( string $object_type, int $object_id, int $user_id ): void {
		StreakService::forget( $user_id );
	}
}
