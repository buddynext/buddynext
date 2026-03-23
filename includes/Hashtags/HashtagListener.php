<?php
/**
 * Hashtag event listener.
 *
 * Hooks BuddyNext lifecycle actions and schedules asynchronous hashtag
 * extraction. Uses Action Scheduler when available; falls back to synchronous
 * inline processing when it is absent (local/test environments).
 *
 * Flow for a native feed post:
 *   buddynext_post_created → on_post_created() → dispatch buddynext_async_index_hashtags
 *   buddynext_async_index_hashtags → async_index_hashtags() → extract + sync
 *
 * External content (WPMediaVerse media, Jetonomy discussions, Career Board jobs)
 * reaches this listener via the buddynext_index_hashtags action which the
 * respective bridge fires directly — that action also runs async via this class.
 *
 * @package BuddyNext\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Hashtags;

use BuddyNext\Feed\PostService;

/**
 * Wires lifecycle hooks to hashtag extraction and sync.
 */
class HashtagListener {

	/**
	 * Hashtag service instance.
	 *
	 * @var HashtagService
	 */
	private HashtagService $service;

	/**
	 * Constructor.
	 *
	 * @param HashtagService $service Hashtag service.
	 */
	public function __construct( HashtagService $service ) {
		$this->service = $service;
	}

	/**
	 * Register all action hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Native BuddyNext feed posts.
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );

		// Bridge entry point — fired by WPMediaVerse, Jetonomy, Career Board bridges.
		add_action( 'buddynext_index_hashtags', array( $this, 'on_index_hashtags' ), 10, 3 );

		// Async worker hooks — run inline when Action Scheduler is absent.
		add_action( 'buddynext_async_index_hashtags', array( $this, 'async_index_hashtags' ), 10, 3 );
	}

	/**
	 * Handle buddynext_post_created — schedule hashtag extraction for a post.
	 *
	 * The post content is fetched fresh from the DB inside the async worker so
	 * we only pass lightweight identifiers here, not the full content string.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author user ID (unused here, kept for hook arity).
	 * @param string $type    Post type slug.
	 * @return void
	 */
	public function on_post_created( int $post_id, int $user_id, string $type ): void {
		// Only text-based post types carry hashtaggable content.
		if ( ! in_array( $type, array( 'text', 'link', 'announcement', 'activity' ), true ) ) {
			return;
		}

		$this->dispatch( 'buddynext_async_index_hashtags', array( 'post', $post_id, '' ) );
	}

	/**
	 * Handle buddynext_index_hashtags — fired by bridge code for external content.
	 *
	 * @param string $object_type Object type (e.g. 'mvs_media', 'jt_discussion', 'job').
	 * @param int    $object_id   Object ID.
	 * @param string $content     Combined text to extract hashtags from.
	 * @return void
	 */
	public function on_index_hashtags( string $object_type, int $object_id, string $content ): void {
		$this->dispatch( 'buddynext_async_index_hashtags', array( $object_type, $object_id, $content ) );
	}

	/**
	 * Async worker — extract hashtags from content and sync the pivot table.
	 *
	 * For object_type='post' with an empty content string the post row is
	 * fetched from bn_posts so the async context always has the saved content.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param string $content     Content to extract from (may be empty for 'post' — fetched below).
	 * @return void
	 */
	public function async_index_hashtags( string $object_type, int $object_id, string $content ): void {
		if ( 'post' === $object_type && '' === $content ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$content = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT content FROM {$wpdb->prefix}bn_posts WHERE id = %d",
					$object_id
				)
			);
		}

		if ( '' === trim( $content ) ) {
			return;
		}

		$slugs = $this->service->extract( $content );
		$this->service->sync( $object_type, $object_id, $slugs );
	}

	/**
	 * Dispatch a job via Action Scheduler or inline fallback.
	 *
	 * Uses `as_enqueue_async_action` when Action Scheduler is available so the
	 * extraction never blocks the post-save request. Falls back to
	 * `do_action_ref_array` so local/test environments work without AS.
	 *
	 * @param string  $hook Action hook name.
	 * @param mixed[] $args Arguments to pass.
	 * @return void
	 */
	private function dispatch( string $hook, array $args ): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( $hook, $args, 'buddynext' );
		} else {
			do_action_ref_array( $hook, $args );
		}
	}
}
