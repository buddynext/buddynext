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
use BuddyNext\Spaces\SpaceFieldRegistry;

/**
 * Wires lifecycle hooks to search index writes via Action Scheduler or inline.
 */
class SearchIndexListener implements ListenerInterface {

	/**
	 * Rows per keyset page when re-dispatching a member's own posts.
	 */
	private const REINDEX_BATCH = 200;

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
		// Re-index on edit: a post's content OR its privacy can change, and the
		// index must follow — otherwise an edited post keeps its stale text and a
		// post flipped public->private stays publicly searchable (a privacy leak).
		add_action( 'buddynext_post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		add_action( 'buddynext_post_deleted', array( $this, 'on_post_deleted' ), 10, 1 );
		// When a member flips an account-level search-visibility setting, reindex
		// their EXISTING posts so their public/private search state follows suit.
		add_action( 'buddynext_user_search_visibility_changed', array( $this, 'on_user_search_visibility_changed' ), 10, 1 );
		add_action( 'buddynext_space_created', array( $this, 'on_space_created' ), 10, 2 );
		add_action( 'buddynext_space_updated', array( $this, 'on_space_updated' ), 10, 1 );
		add_action( 'buddynext_space_deleted', array( $this, 'on_space_deleted' ), 10, 1 );

		// Synchronous fallback handlers — run inline when Action Scheduler is absent.
		add_action( 'buddynext_async_index_user', array( $this, 'async_index_user' ), 10, 1 );
		add_action( 'buddynext_async_index_post', array( $this, 'async_index_post' ), 10, 2 );
		add_action( 'buddynext_async_deindex_post', array( $this, 'async_deindex_post' ), 10, 1 );
		add_action( 'buddynext_async_index_space', array( $this, 'async_index_space' ), 10, 1 );
		add_action( 'buddynext_async_reindex_space_posts', array( $this, 'async_reindex_space_posts' ), 10, 2 );
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
		// Registry-derived, not a literal type list: content_requires_membership()
		// is true for every type whose visibility is not 'public', so a custom
		// registered type is gated without editing this method.
		return \BuddyNext\Spaces\SpaceTypeRegistry::instance()->content_requires_membership( $type )
			? 'private'
			: 'public';
	}

	/**
	 * Handle buddynext_index_user — index or re-index a single user.
	 *
	 * @param int $user_id User ID to index.
	 * @return void
	 */
	public function on_index_user( int $user_id ): void {
		// Inline, not queued: indexing ONE member is a single-row upsert, and
		// queuing it makes member searchability depend on Action Scheduler
		// actually running. On hosts with broken loopback (wp-cron/AS runner
		// unreachable - found on the 1.0.4 dist-zip QA install) every member
		// stayed unsearchable forever: directory search, DM recipient search,
		// and unified search all returned nothing. Bulk paths (reindex_all,
		// posts, spaces) stay on the queue - they are the bursty work.
		$this->async_index_user( $user_id );
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
	public function on_post_created( int $post_id, int $user_id = 0, string $type = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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
	 * Handle buddynext_post_updated — re-index an edited post.
	 *
	 * Re-runs the same async indexer as creation: async_index_post() re-reads the
	 * row and recomputes both content and visibility (post privacy AND account
	 * privacy), so an edit or a privacy flip is reflected in the index.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $user_id Author ID.
	 * @param array $fields  Changed fields (unused; the indexer re-reads the row).
	 * @return void
	 */
	public function on_post_updated( int $post_id, int $user_id = 0, array $fields = array() ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->dispatch( 'buddynext_async_index_post', array( $post_id, $user_id ) );
	}

	/**
	 * Handle buddynext_user_search_visibility_changed — reindex a member's posts.
	 *
	 * Fired when a member toggles an account-level setting that governs whether
	 * their posts appear in global search (private account / search-indexable).
	 * Each published post is re-indexed so its stored visibility matches the new
	 * setting; dispatching one async action per post lets Action Scheduler chunk
	 * the work for prolific authors.
	 *
	 * @param int $user_id The member whose search visibility changed.
	 * @return void
	 */
	public function on_user_search_visibility_changed( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;

		// Keyset-paged, not one unbounded SELECT. A member who flips their search visibility
		// used to load EVERY id they have ever published into PHP at once, then fan out a
		// dispatch per post. A prolific author with 50k posts did that inline, on a settings
		// save.
		$after = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_posts
					  WHERE user_id = %d AND status = 'published' AND id > %d
					  ORDER BY id ASC
					  LIMIT %d",
					$user_id,
					$after,
					self::REINDEX_BATCH
				)
			);

			$fetched = count( (array) $post_ids );

			foreach ( $post_ids as $post_id ) {
				$after = (int) $post_id;
				$this->dispatch( 'buddynext_async_index_post', array( (int) $post_id, $user_id ) );
			}
		} while ( self::REINDEX_BATCH === $fetched );
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

		// The space row is not the only thing indexed under this space.
		//
		// Re-indexing only the space row left every POST inside it untouched, so
		// an open space full of public posts, switched to private or secret, kept
		// all of those posts at visibility 'public'. The guest search gate is
		// `visibility = 'public'`, so anonymous visitors could still pull their
		// titles and bodies out of a space they have no access to.
		//
		// Forget any ceiling memoised earlier in THIS request before deciding
		// anything: the space's type is what just changed, and a value cached
		// before the change would clamp - or re-open - against the old type.
		\BuddyNext\Search\SearchService::flush_space_ceiling( $space_id );

		// Clamping runs SYNCHRONOUSLY and first. It is the security-critical
		// direction, so it must not wait on a queue worker that may be delayed or
		// absent, and it is a single UPDATE regardless of how many posts the
		// space holds.
		buddynext_service( 'search' )->clamp_space_visibility( $space_id );

		// The opposite direction - a private space re-opened - cannot be resolved
		// from the index alone, because a row's 'private' does not record whether
		// it came from the space ceiling, the post's own privacy, or a private
		// author account. Those posts are re-indexed so each is decided by the
		// same rule that wrote it in the first place. Async and batched: this one
		// is not a leak, and a large space should not block the request.
		$this->dispatch( 'buddynext_async_reindex_space_posts', array( $space_id, 0 ) );
	}

	/**
	 * Re-index one batch of a space's posts, then queue the next.
	 *
	 * Walks in batches so a space holding tens of thousands of posts cannot pin a
	 * request or a single queue job. Each post goes back through async_index_post,
	 * which is the same path that indexed it originally - so post privacy, private
	 * author accounts and the space ceiling are all re-evaluated by one rule
	 * rather than a second copy of it living here.
	 *
	 * @param int $space_id Space to walk.
	 * @param int $offset   Batch offset.
	 * @return void
	 */
	public function async_reindex_space_posts( int $space_id, int $offset = 0 ): void {
		global $wpdb;

		if ( $space_id <= 0 ) {
			return;
		}

		\BuddyNext\Search\SearchService::flush_space_ceiling( $space_id );

		$batch = 200;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id
				   FROM {$wpdb->prefix}bn_posts
				  WHERE space_id = %d
				    AND status = 'published'
				  ORDER BY id ASC
				  LIMIT %d OFFSET %d",
				$space_id,
				$batch,
				$offset
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$this->async_index_post( (int) $row['id'], (int) $row['user_id'] );
		}

		// Only queue another pass when this one was full; a short batch is the end.
		if ( count( $rows ) === $batch ) {
			$this->dispatch( 'buddynext_async_reindex_space_posts', array( $space_id, $offset + $batch ) );
		}
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

		$author_id = (int) $row['user_id'];
		// A private (followers-only) account's posts must never surface in global
		// search, even when the post's own privacy is 'public' — only followers
		// see their content, via the feed, not the public index.
		$visibility = ( 'public' === $row['privacy'] && ! buddynext_service( 'follows' )->is_private_account( $author_id ) ) ? 'public' : 'private';
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

		// Fold the space's searchable + public custom field values into the index
		// content so a developer field (searchable:true, visibility:public) makes
		// the space discoverable. Members-only/private values are never indexed.
		$bn_field_text = SpaceFieldRegistry::instance()->searchable_public_text( $space_id );
		if ( '' !== $bn_field_text ) {
			$content = trim( $content . ' ' . $bn_field_text );
		}

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

		// Index posts. KEYSET, not OFFSET.
		//
		// This walked the table with LIMIT/OFFSET, which MySQL satisfies by counting rows it
		// then throws away: at 1M posts the last batch is OFFSET 999,900, so the job is
		// O(n^2/batch) and gets slower the further it gets. It is a background job, so nobody
		// waits on it — it just quietly takes hours instead of minutes, and can time out before
		// the tail is ever indexed. Keyset makes every batch cost the same.
		$after_post = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, content, privacy, status, space_id
					 FROM {$wpdb->prefix}bn_posts
					 WHERE status = 'published' AND id > %d
					 ORDER BY id ASC
					 LIMIT %d",
					$after_post,
					$batch_size
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $rows as $row ) {
				$author_id  = (int) $row['user_id'];
				$visibility = ( 'public' === $row['privacy'] && ! buddynext_service( 'follows' )->is_private_account( $author_id ) ) ? 'public' : 'private';

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

			$fetched_posts = count( (array) $rows );

			if ( ! empty( $rows ) ) {
				$after_post = (int) end( $rows )['id'];
			}
		} while ( $fetched_posts === $batch_size );

		// Index users.
		$profiles_service = buddynext_service( 'profiles' );
		$after_user       = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
					$after_user,
					$batch_size
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			foreach ( (array) $user_ids as $uid ) {
				$after_user = (int) $uid;
				$profiles_service->index_user( (int) $uid );
			}
			$fetched_users = count( (array) $user_ids );
		} while ( $fetched_users === $batch_size );

		// Index spaces. Keyset, same reason.
		$after_space = 0;
		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, name, description, type, owner_id, is_archived
					 FROM {$wpdb->prefix}bn_spaces
					 WHERE id > %d
					 ORDER BY id ASC
					 LIMIT %d",
					$after_space,
					$batch_size
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

			$fetched_spaces = count( (array) $space_rows );

			if ( ! empty( $space_rows ) ) {
				$after_space = (int) end( $space_rows )['id'];
			}
		} while ( $fetched_spaces === $batch_size );

		// Record completion so the admin Tools screen can show when the index was
		// last fully rebuilt (SearchService::index_stats()).
		update_option( 'buddynext_search_last_reindex', time(), false );

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
