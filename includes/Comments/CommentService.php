<?php
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
		 * @param string $object_type Object type.
		 * @param int    $object_id   Object ID.
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = %s AND object_id = %d AND parent_id IS NULL AND is_deleted = 0",
				sanitize_key( $object_type ),
				$object_id
			)
		);

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
