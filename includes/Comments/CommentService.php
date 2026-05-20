<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Comment service.
 *
 * Manages threaded comments on any object type stored in bn_comments. A
 * comment may have a parent_id to form reply threads. Only the author (or a
 * user with manage_options) may update or delete a comment.
 *
 * @package BuddyNext\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Comments;

use WP_Error;

/**
 * Handles comment CRUD and listing.
 */
class CommentService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_comments';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Default comments per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Create a comment on an object.
	 *
	 * @param int      $user_id     Commenting user.
	 * @param string   $object_type Object type (e.g. 'post').
	 * @param int      $object_id   Object ID.
	 * @param string   $content     Comment text.
	 * @param int|null $parent_id   Parent comment ID for replies, or null.
	 * @return int|WP_Error Inserted comment ID or WP_Error.
	 */
	public function create( int $user_id, string $object_type, int $object_id, string $content, ?int $parent_id = null ): int|WP_Error {
		$content = wp_kses_post( trim( $content ) );

		if ( '' === $content ) {
			return new WP_Error( 'empty_content', __( 'Comment content cannot be empty.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => $user_id,
				'object_type' => sanitize_key( $object_type ),
				'object_id'   => $object_id,
				'parent_id'   => $parent_id,
				'content'     => $content,
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);

		$comment_id = (int) $wpdb->insert_id;

		$this->bust_cache( $object_type, $object_id );

		if ( 'post' === $object_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_posts SET comment_count = comment_count + 1 WHERE id = %d",
					$object_id
				)
			);
		}

		/**
		 * Fires after a new comment is created.
		 *
		 * @param int    $comment_id  New comment ID.
		 * @param string $object_type Object type ('post', 'media', etc.).
		 * @param int    $object_id   Object ID the comment was created on.
		 * @param int    $user_id     Commenting user.
		 */
		do_action( 'buddynext_comment_created', $comment_id, $object_type, $object_id, $user_id );

		return $comment_id;
	}

	/**
	 * Return a single comment by ID.
	 *
	 * @param int $comment_id Comment ID.
	 * @return array|null Null if not found.
	 */
	public function get( int $comment_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_comments WHERE id = %d",
				$comment_id
			),
			ARRAY_A
		);

		return null !== $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Update a comment's content.
	 *
	 * @param int    $comment_id Comment to update.
	 * @param int    $user_id    User requesting the update.
	 * @param string $content    New content.
	 * @return true|WP_Error
	 */
	public function update( int $comment_id, int $user_id, string $content ): true|WP_Error {
		$comment = $this->get( $comment_id );

		if ( null === $comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'buddynext' ) );
		}

		if ( $comment['user_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this comment.', 'buddynext' ) );
		}

		$content = wp_kses_post( trim( $content ) );

		if ( '' === $content ) {
			return new WP_Error( 'empty_content', __( 'Comment content cannot be empty.', 'buddynext' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_comments',
			array(
				'content'   => $content,
				'is_edited' => 1,
			),
			array( 'id' => $comment_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);

		$this->bust_cache( $comment['object_type'], (int) $comment['object_id'] );

		/**
		 * Fires after a comment is updated.
		 *
		 * @param int $comment_id Updated comment ID.
		 * @param int $user_id    User who updated the comment.
		 */
		do_action( 'buddynext_comment_updated', $comment_id, $user_id );

		return true;
	}

	/**
	 * Delete a comment.
	 *
	 * @param int $comment_id Comment to delete.
	 * @param int $user_id    User requesting the deletion.
	 * @return true|WP_Error
	 */
	public function delete( int $comment_id, int $user_id ): true|WP_Error {
		$comment = $this->get( $comment_id );

		if ( null === $comment ) {
			return new WP_Error( 'not_found', __( 'Comment not found.', 'buddynext' ) );
		}

		if ( $comment['user_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot delete this comment.', 'buddynext' ) );
		}

		global $wpdb;

		// Soft-delete: blank the content and mark deleted so threads remain intact.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_comments',
			array(
				'is_deleted' => 1,
				'content'    => '',
			),
			array( 'id' => $comment_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		$this->bust_cache( $comment['object_type'], (int) $comment['object_id'] );

		if ( 'post' === $comment['object_type'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_posts SET comment_count = GREATEST(0, comment_count - 1) WHERE id = %d",
					(int) $comment['object_id']
				)
			);
		}

		/**
		 * Fires after a comment is deleted.
		 *
		 * @param int $comment_id Deleted comment ID.
		 * @param int $user_id    User who deleted the comment.
		 */
		do_action( 'buddynext_comment_deleted', $comment_id, $user_id );

		return true;
	}

	/**
	 * Edit a comment's content (spec-named alias for update()).
	 *
	 * @param int    $comment_id Comment to edit.
	 * @param int    $user_id    User requesting the edit.
	 * @param string $content    New content.
	 * @return bool True on success, false on failure.
	 */
	public function edit( int $comment_id, int $user_id, string $content ): bool {
		$result = $this->update( $comment_id, $user_id, $content );
		return ! is_wp_error( $result );
	}

	/**
	 * Pin a comment to the top of the thread.
	 *
	 * Only space moderators and admins may pin comments. This stores the
	 * pinned comment ID as a meta option on the parent object so it can be
	 * surfaced first in the list. The pin is stored per-object to allow
	 * unpinning by setting the value to null.
	 *
	 * @param int $comment_id Comment to pin.
	 * @param int $user_id    User requesting the pin (must be mod or admin).
	 * @return bool True if pinned, false if user lacks permission or comment not found.
	 */
	public function pin( int $comment_id, int $user_id ): bool {
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$comment = $this->get( $comment_id );

		if ( null === $comment ) {
			return false;
		}

		$meta_key = 'bn_pinned_comment_' . sanitize_key( $comment['object_type'] ) . '_' . (int) $comment['object_id'];

		update_option( $meta_key, $comment_id, false );

		return true;
	}

	/**
	 * Unpin the pinned comment for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     User requesting the unpin (must be mod or admin).
	 * @return bool True if unpinned, false if user lacks permission.
	 */
	public function unpin( string $object_type, int $object_id, int $user_id ): bool {
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return false;
		}

		$meta_key = 'bn_pinned_comment_' . sanitize_key( $object_type ) . '_' . (int) $object_id;

		delete_option( $meta_key );

		return true;
	}

	/**
	 * Return a two-level tree of comments for an object.
	 *
	 * Top-level comments are fetched first, then all replies in one query and
	 * attached to their parents. The spec caps nesting at two levels — replies
	 * to replies are flattened up to the level-2 parent. The pinned comment
	 * (if any) is prepended to the top-level list.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param array  $args        Optional: per_page (int), page (int).
	 * @return array{items: array[], total: int} Items have a 'replies' key (array of level-2 comments).
	 */
	public function list( string $object_type, int $object_id, array $args = array() ): array {
		$per_page = min( (int) ( $args['per_page'] ?? self::DEFAULT_LIMIT ), 50 );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		$result = $this->list_for_object( $object_type, $object_id, $per_page, $page );

		if ( empty( $result['items'] ) ) {
			return $result;
		}

		// Collect top-level IDs and then fetch all replies in one query.
		$parent_ids = array_column( $result['items'], 'id' );

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		$replies      = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_comments WHERE parent_id IN ({$placeholders}) AND is_deleted = 0 ORDER BY created_at ASC",
				...$parent_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$reply_map = array();
		array_walk(
			$replies,
			function ( array $reply_row ) use ( &$reply_map ): void {
				$reply_map[ (int) $reply_row['parent_id'] ][] = $this->hydrate( $reply_row );
			}
		);

		foreach ( $result['items'] as &$item ) {
			$item['replies'] = $reply_map[ $item['id'] ] ?? array();
		}
		unset( $item );

		// Prepend pinned comment if set and not already in the list.
		$meta_key  = 'bn_pinned_comment_' . sanitize_key( $object_type ) . '_' . (int) $object_id;
		$pinned_id = (int) get_option( $meta_key, 0 );

		if ( $pinned_id > 0 ) {
			$in_list = false;
			foreach ( $result['items'] as $item ) {
				if ( $item['id'] === $pinned_id ) {
					$in_list = true;
					break;
				}
			}

			if ( ! $in_list ) {
				$pinned = $this->get( $pinned_id );
				if ( null !== $pinned ) {
					$pinned['replies'] = $reply_map[ $pinned_id ] ?? array();
					$pinned['pinned']  = true;
					array_unshift( $result['items'], $pinned );
				}
			} else {
				foreach ( $result['items'] as &$item ) {
					if ( $item['id'] === $pinned_id ) {
						$item['pinned'] = true;
						break;
					}
				}
				unset( $item );
			}
		}

		return $result;
	}

	/**
	 * Return paginated comments for an object.
	 *
	 * Top-level comments only (parent_id IS NULL) are returned; reply counts
	 * are included per comment for UI expansion.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $per_page    Comments per page (max 50).
	 * @param int    $page        1-based page number.
	 * @return array{items: array[], total: int}
	 */
	public function list_for_object( string $object_type, int $object_id, int $per_page = self::DEFAULT_LIMIT, int $page = 1 ): array {
		global $wpdb;

		$per_page  = min( $per_page, 50 );
		$page      = max( 1, $page );
		$offset    = ( $page - 1 ) * $per_page;
		$gen       = $this->get_generation( $object_type, $object_id );
		$cache_key = "list_{$object_type}_{$object_id}_{$gen}_{$per_page}_{$page}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = %s AND object_id = %d AND parent_id IS NULL AND is_deleted = 0
				 ORDER BY created_at ASC
				 LIMIT %d OFFSET %d",
				sanitize_key( $object_type ),
				$object_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = %s AND object_id = %d AND parent_id IS NULL AND is_deleted = 0",
				sanitize_key( $object_type ),
				$object_id
			)
		);

		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$result = array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Return the current cache generation counter for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return int
	 */
	private function get_generation( string $object_type, int $object_id ): int {
		$gen = wp_cache_get( "gen_{$object_type}_{$object_id}", self::CACHE_GROUP );
		return is_int( $gen ) ? $gen : 0;
	}

	/**
	 * Increment the cache generation to invalidate all paginated list keys for an object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 */
	private function bust_cache( string $object_type, int $object_id ): void {
		$gen = $this->get_generation( $object_type, $object_id );
		wp_cache_set( "gen_{$object_type}_{$object_id}", $gen + 1, self::CACHE_GROUP, self::CACHE_TTL );
	}

	/**
	 * Hydrate a raw DB row into a typed comment array.
	 *
	 * @param array $row Raw ARRAY_A row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'          => (int) $row['id'],
			'user_id'     => (int) $row['user_id'],
			'object_type' => $row['object_type'],
			'object_id'   => (int) $row['object_id'],
			'parent_id'   => isset( $row['parent_id'] ) ? (int) $row['parent_id'] : null,
			'content'     => $row['content'],
			'is_edited'   => (bool) $row['is_edited'],
			'is_deleted'  => (bool) $row['is_deleted'],
			'created_at'  => $row['created_at'],
			'updated_at'  => $row['updated_at'],
		);
	}
}
