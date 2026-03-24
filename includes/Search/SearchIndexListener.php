<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Search index event listener.
 *
 * Hooks BuddyNext lifecycle actions and schedules (or runs inline) search
 * index updates. Uses Action Scheduler when available, falls back to
 * synchronous inline indexing when it is absent.
 *
 * @package BuddyNext\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Search;

use BuddyNext\Contracts\ListenerInterface;

/**
 * Wires lifecycle hooks to search index writes via Action Scheduler or inline.
 */
class SearchIndexListener implements ListenerInterface {

	/**
	 * Register all action hooks for index maintenance.
	 *
	 * Also registers the synchronous fallback handlers that run when Action
	 * Scheduler is not available. The fallback hooks target the async action
	 * names so the same code path executes in both environments.
	 *
	 * @return void
	 */
	public function register(): void {
		// Lifecycle hooks — dispatch to async or inline.
		add_action( 'buddynext_index_user', array( $this, 'on_index_user' ), 10, 1 );
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );
		add_action( 'buddynext_post_deleted', array( $this, 'on_post_deleted' ), 10, 1 );
		add_action( 'buddynext_space_created', array( $this, 'on_space_created' ), 10, 2 );
		add_action( 'buddynext_space_updated', array( $this, 'on_space_updated' ), 10, 1 );
		add_action( 'buddynext_space_deleted', array( $this, 'on_space_deleted' ), 10, 1 );

		// Synchronous fallback handlers — run inline when Action Scheduler is absent.
		add_action( 'buddynext_async_index_user', array( $this, 'async_index_user' ), 10, 1 );
		add_action( 'buddynext_async_index_post', array( $this, 'async_index_post' ), 10, 2 );
		add_action( 'buddynext_async_deindex_post', array( $this, 'async_deindex_post' ), 10, 1 );
		add_action( 'buddynext_async_index_space', array( $this, 'async_index_space' ), 10, 1 );
		add_action( 'buddynext_async_deindex_space', array( $this, 'async_deindex_space' ), 10, 1 );

		// Batch re-index handler (triggered by activation or manual schedule).
		add_action( 'buddynext_reindex_all', array( SearchService::class, 'schedule_reindex_all' ) );
	}

	/**
	 * Handle buddynext_index_user — index or re-index a single user.
	 *
	 * @param int $user_id User ID to index.
	 * @return void
	 */
	public function on_index_user( int $user_id ): void {
		$this->dispatch( 'buddynext_async_index_user', array( $user_id ) );
	}

	/**
	 * Handle buddynext_post_created — index a newly created post.
	 *
	 * The $type parameter is received from the hook but not used here because
	 * the post type is read from the database row inside async_index_post.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author user ID.
	 * @param string $type    Post type slug (received from hook, unused here).
	 * @return void
	 */
	public function on_post_created( int $post_id, int $user_id, string $type ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->dispatch( 'buddynext_async_index_post', array( $post_id, $user_id ) );
	}

	/**
	 * Handle buddynext_post_deleted — remove a post from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function on_post_deleted( int $post_id ): void {
		$this->dispatch( 'buddynext_async_deindex_post', array( $post_id ) );
	}

	/**
	 * Handle buddynext_space_created — index a newly created space.
	 *
	 * The $user_id parameter is received from the hook but not used here because
	 * the owner is read from the database row inside async_index_space.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  Owner user ID (received from hook, unused here).
	 * @return void
	 */
	public function on_space_created( int $space_id, int $user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->dispatch( 'buddynext_async_index_space', array( $space_id ) );
	}

	/**
	 * Handle buddynext_space_updated — re-index a space after an update.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function on_space_updated( int $space_id ): void {
		$this->dispatch( 'buddynext_async_index_space', array( $space_id ) );
	}

	/**
	 * Handle buddynext_space_deleted — remove a space from the index.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function on_space_deleted( int $space_id ): void {
		$this->dispatch( 'buddynext_async_deindex_space', array( $space_id ) );
	}

	/**
	 * Synchronous fallback: index a single user.
	 *
	 * Called either by Action Scheduler or inline when AS is absent.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function async_index_user( int $user_id ): void {
		buddynext_service( 'profiles' )->index_user( $user_id );
	}

	/**
	 * Synchronous fallback: index a single post.
	 *
	 * Reads post data from bn_posts and upserts it into bn_search_index.
	 * Skips posts that are not in published status so the index stays clean.
	 *
	 * The $user_id parameter is accepted to match the hook signature (the
	 * author is read from the database row to ensure accuracy).
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Author user ID (hook arg, owner resolved from DB).
	 * @return void
	 */
	public function async_index_post( int $post_id, int $user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, user_id, content, privacy, status
				 FROM {$wpdb->prefix}bn_posts
				 WHERE id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( ! $row || 'published' !== $row['status'] ) {
			return;
		}

		$visibility = 'public' === $row['privacy'] ? 'public' : 'private';
		$author_id  = (int) $row['user_id'];
		$content    = wp_strip_all_tags( (string) $row['content'] );

		buddynext_service( 'search' )->index(
			'post',
			$post_id,
			'',
			$content,
			$author_id,
			$visibility
		);
	}

	/**
	 * Synchronous fallback: remove a post from the index.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function async_deindex_post( int $post_id ): void {
		buddynext_service( 'search' )->deindex( 'post', $post_id );
	}

	/**
	 * Synchronous fallback: index a single space.
	 *
	 * Reads space data from bn_spaces and upserts it into bn_search_index.
	 * Secret spaces are indexed as private so they do not surface in public results.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function async_index_space( int $space_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, description, type, owner_id
				 FROM {$wpdb->prefix}bn_spaces
				 WHERE id = %d",
				$space_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return;
		}

		$visibility = 'secret' === $row['type'] ? 'private' : 'public';
		$owner_id   = (int) $row['owner_id'];
		$title      = (string) $row['name'];
		$content    = wp_strip_all_tags( (string) ( $row['description'] ?? '' ) );

		buddynext_service( 'search' )->index(
			'space',
			$space_id,
			$title,
			$content,
			$owner_id,
			$visibility
		);
	}

	/**
	 * Synchronous fallback: remove a space from the index.
	 *
	 * @param int $space_id Space ID.
	 * @return void
	 */
	public function async_deindex_space( int $space_id ): void {
		buddynext_service( 'search' )->deindex( 'space', $space_id );
	}

	/**
	 * Dispatch a hook via Action Scheduler or run it inline.
	 *
	 * When Action Scheduler is available the job is queued for async execution.
	 * When it is absent the hook is fired immediately via do_action_ref_array()
	 * so the synchronous fallback handlers registered in init() handle it.
	 *
	 * @param string  $hook Hook name to dispatch.
	 * @param mixed[] $args Arguments to pass to the hook.
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
