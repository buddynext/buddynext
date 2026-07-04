<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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

use BuddyNext\Contracts\ListenerInterface;
use BuddyNext\Feed\PostService;

/**
 * Wires lifecycle hooks to hashtag extraction and sync.
 */
class HashtagListener implements ListenerInterface {

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
	public function register(): void {
		// Native BuddyNext feed posts.
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );

		// Post edits — re-sync tags so removed #tags unlink and new ones register.
		add_action( 'buddynext_post_updated', array( $this, 'on_post_updated' ), 10, 3 );

		// Post deletion — drop the post's hashtag links and decrement post_count.
		add_action( 'buddynext_post_deleted', array( $this, 'on_post_deleted' ), 10, 2 );

		// Bridge entry point — fired by WPMediaVerse, Jetonomy, Career Board bridges.
		add_action( 'buddynext_index_hashtags', array( $this, 'on_index_hashtags' ), 10, 3 );

		// Async worker hooks — run inline when Action Scheduler is absent.
		add_action( 'buddynext_async_index_hashtags', array( $this, 'async_index_hashtags' ), 10, 3 );

		// One-time Unicode re-sync (schema v24 / R11) — self-chaining batch worker.
		add_action( 'buddynext_resync_hashtags', array( $this, 'resync_batch' ), 10, 1 );
	}

	/**
	 * Handle buddynext_post_created — schedule hashtag extraction for a post.
	 *
	 * The post content is fetched fresh from the DB inside the async worker so
	 * we only pass lightweight identifiers here, not the full content string.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author user ID (unused here, kept for hook arity).
	 * @param string $type    Post type slug (unused; all post types are now indexed).
	 * @return void
	 */
	public function on_post_created( int $post_id, int $user_id = 0, string $type = '' ): void {
		// Hashtags feature off: do not index. A #word in the post stays as plain
		// text (PostService stores content verbatim), it is just never processed,
		// linked, or counted while the feature is disabled.
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return;
		}

		// Index hashtags for ANY post type. The saved content is fetched in the
		// async worker (async_index_hashtags), which no-ops when there is no text,
		// so a type with no caption costs nothing. The old 4-type allowlist meant
		// poll questions, media captions, reshare notes, event/job/discussion
		// bodies etc. with #hashtags were silently never indexed (never appeared
		// in hashtag feeds or trending, and following the tag missed them).
		$this->dispatch( 'buddynext_async_index_hashtags', array( 'post', $post_id, '' ) );
	}

	/**
	 * Handle buddynext_post_updated — re-sync hashtags when a post is edited.
	 *
	 * Hashtags derive solely from post content, so this only re-processes when the
	 * content column was actually written this update; edited_at-only saves and
	 * privacy flips change no tags and are skipped. It re-uses the same async
	 * worker as creation, which fetches the fresh content and REPLACES the post's
	 * tag set — so a removed #tag is unlinked (and its post_count decremented) and
	 * a newly added #tag is registered and linked. Before this, buddynext_post_updated
	 * had no hashtag consumer: an edited post kept its original tags frozen, so
	 * deleted tags lingered on the tag page and added tags never appeared. Mirrors
	 * the search index's C4 consumer of the same event.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $user_id Editor user ID (unused; kept for hook arity).
	 * @param array $fields  Columns written this update (see PostService::update).
	 * @return void
	 */
	public function on_post_updated( int $post_id, int $user_id = 0, array $fields = array() ): void {
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return;
		}

		// Tags come only from content; a save that did not touch content cannot
		// change the tag set, so there is nothing to re-sync.
		if ( ! array_key_exists( 'content', $fields ) ) {
			return;
		}

		$this->dispatch( 'buddynext_async_index_hashtags', array( 'post', $post_id, '' ) );
	}

	/**
	 * Handle buddynext_post_deleted — remove the post's hashtag links.
	 *
	 * Runs synchronously because the post row is already gone by the time this
	 * fires, so there is no content to fetch. Syncing with an empty slug list
	 * deletes the post's bn_post_hashtags pivot rows and recomputes (decrements)
	 * post_count on each previously linked hashtag.
	 *
	 * @param int $post_id Deleted post ID.
	 * @param int $user_id Author user ID (unused, kept for hook arity).
	 * @return void
	 */
	public function on_post_deleted( int $post_id, int $user_id ): void {
		$this->service->sync( 'post', $post_id, array() );
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
		// Hashtags feature off: bridge content (media, discussions, jobs) is not
		// indexed either.
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return;
		}

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
		// Authoritative guard: covers already-queued jobs and any direct callers
		// of this worker hook, so no extraction/sync/signal happens while the
		// Hashtags feature is off.
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return;
		}

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

		// Engagement signal — fire one buddynext_hashtag_used per tag for
		// gamification plugins (badges for tag use, discoverability awards,
		// etc.). Only native posts carry an author lookup here; bridge
		// content types (mvs_media, jt_discussion, job) fire their own
		// equivalent from the originating bridge if they want this signal.
		if ( 'post' === $object_type && ! empty( $slugs ) ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d",
					$object_id
				)
			);

			if ( $user_id > 0 ) {
				foreach ( $slugs as $tag ) {
					/**
					 * Fires once per hashtag used in a native post.
					 *
					 * @param string $tag     Lowercase tag slug (no leading #).
					 * @param int    $post_id Post containing the tag.
					 * @param int    $user_id Author of the post.
					 */
					do_action( 'buddynext_hashtag_used', $tag, $object_id, $user_id );
				}
			}
		}
	}

	/**
	 * One-time Unicode hashtag re-sync (schema v24 / R11).
	 *
	 * Before HashtagService::normalize_slug(), sync() ran sanitize_key() on each
	 * tag, stripping every non-ASCII byte — so "#café" was stored as "caf" and
	 * "#日本語" was dropped entirely. This walks every published post whose content
	 * carries a '#', 100 rows at a time, re-extracting and re-syncing so the
	 * correct Unicode slugs are registered and linked. ASCII-only tags re-sync to
	 * the same slug (a harmless no-op).
	 *
	 * Self-chains the next batch through Action Scheduler so a 100k-post site never
	 * does the work inline; it is safe to interrupt and resume (each batch is
	 * independent and sync() is idempotent). After the final batch it reconciles
	 * follows orphaned by the old mangling via merge_mangled_follows().
	 *
	 * @param int $offset Row offset for this batch.
	 * @return void
	 */
	public function resync_batch( int $offset = 0 ): void {
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return;
		}

		global $wpdb;
		$batch_size = 100;
		$like       = '%' . $wpdb->esc_like( '#' ) . '%';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, content FROM {$wpdb->prefix}bn_posts
				 WHERE status = 'published' AND content LIKE %s
				 ORDER BY id
				 LIMIT %d OFFSET %d",
				$like,
				$batch_size,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $rows as $row ) {
			$slugs = $this->service->extract( (string) $row['content'] );
			$this->service->sync( 'post', (int) $row['id'], $slugs );
		}

		if ( count( (array) $rows ) === $batch_size ) {
			// A full batch means more rows remain — queue the next window.
			$this->dispatch( 'buddynext_resync_hashtags', array( $offset + $batch_size ) );
			return;
		}

		// Final batch complete — reconcile follows for unambiguously mangled tags.
		$this->service->merge_mangled_follows();
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
