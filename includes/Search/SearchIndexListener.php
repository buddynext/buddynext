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
		// Index a member at registration. Profile edits fire buddynext_index_user,
		// but a member who never edits their profile would otherwise never be
		// indexed and stay unsearchable in the members directory / global search.
		add_action( 'user_register', array( $this, 'on_index_user' ), 20, 1 );
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
		add_action( 'buddynext_reindex_all', array( $this, 'handle_reindex_all' ) );
	}

	/**
	 * Map a space's `type` to a search-index `visibility`.
	 *
	 * Space type is enum('open','private','secret'); only 'open' spaces are
	 * publicly searchable. Both 'private' and 'secret' must index as private so
	 * SearchService's `visibility = 'public'` filter excludes them from guest /
	 * non-member results. Centralised here so the indexing call sites cannot
	 * drift out of sync again.
	 *
	 * @param string $type Space type.
	 * @return string 'public' or 'private'.
	 */
	private static function space_visibility( string $type ): string {
		return 'open' === $type ? 'public' : 'private';
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
				"SELECT id, user_id, content, privacy, status, space_id
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
			$visibility,
			(int) $row['space_id']
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
				"SELECT id, name, description, type, owner_id, is_archived
				 FROM {$wpdb->prefix}bn_spaces
				 WHERE id = %d",
				$space_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return;
		}

		// Archived spaces must not stay searchable — drop them from the index.
		// This path also fires on the archive action via on_space_updated.
		if ( 1 === (int) $row['is_archived'] ) {
			buddynext_service( 'search' )->deindex( 'space', $space_id );
			return;
		}

		$visibility = self::space_visibility( (string) $row['type'] );
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
	 * Perform the full batch reindex of all posts, users, and spaces.
	 *
	 * This is the handler for the `buddynext_reindex_all` action hook. It does
	 * the actual indexing work in batches of 100 rows per entity type and fires
	 * `buddynext_reindex_complete` when finished. It must never call
	 * SearchService::schedule_reindex_all() — that would re-enqueue the same
	 * action and create an infinite loop.
	 *
	 * @return void
	 */
	public function handle_reindex_all(): void {
		global $wpdb;

		$search_service = buddynext_service( 'search' );
		$batch_size     = 100;

		// Index posts.
		$offset = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, content, privacy, status, space_id
					 FROM {$wpdb->prefix}bn_posts
					 WHERE status = 'published'
					 LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$visibility = 'public' === $row['privacy'] ? 'public' : 'private';
				$search_service->index(
					'post',
					(int) $row['id'],
					'',
					wp_strip_all_tags( (string) $row['content'] ),
					(int) $row['user_id'],
					$visibility,
					(int) $row['space_id']
				);
			}

			$offset += $batch_size;
		} while ( ! empty( $rows ) );

		// Index users.
		$profiles_service = buddynext_service( 'profiles' );
		$offset           = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->users} LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $user_ids as $uid ) {
				$profiles_service->index_user( (int) $uid );
			}

			$offset += $batch_size;
		} while ( ! empty( $user_ids ) );

		// Index spaces.
		$offset = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, description, type, owner_id, is_archived
					 FROM {$wpdb->prefix}bn_spaces
					 LIMIT %d OFFSET %d",
					$batch_size,
					$offset
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $space_rows as $space_row ) {
				// Skip + drop archived spaces so a reindex purges any that were
				// archived after their last index.
				if ( 1 === (int) $space_row['is_archived'] ) {
					$search_service->deindex( 'space', (int) $space_row['id'] );
					continue;
				}
				$visibility = self::space_visibility( (string) $space_row['type'] );
				$search_service->index(
					'space',
					(int) $space_row['id'],
					(string) $space_row['name'],
					wp_strip_all_tags( (string) ( $space_row['description'] ?? '' ) ),
					(int) $space_row['owner_id'],
					$visibility
				);
			}

			$offset += $batch_size;
		} while ( ! empty( $space_rows ) );

		do_action( 'buddynext_reindex_complete' );
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
