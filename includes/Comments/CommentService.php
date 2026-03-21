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

		wp_cache_delete( "list_{$object_type}_{$object_id}", self::CACHE_GROUP );

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

		wp_cache_delete( "list_{$comment['object_type']}_{$comment['object_id']}", self::CACHE_GROUP );

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

		wp_cache_delete( "list_{$comment['object_type']}_{$comment['object_id']}", self::CACHE_GROUP );

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

		$per_page = min( $per_page, 50 );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments
				 WHERE object_type = %s AND object_id = %d AND parent_id IS NULL AND is_deleted = 0",
				sanitize_key( $object_type ),
				$object_id
			)
		);

		return array(
			'items' => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total' => $total,
		);
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
