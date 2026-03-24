<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Share (repost) service.
 *
 * Manages the bn_shares table and keeps the denormalised share_count on
 * bn_posts in sync. A user may share a given post at most once; a second
 * share attempt returns WP_Error('already_shared').
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use WP_Error;

/**
 * Handles post sharing and unsharing.
 */
class ShareService {

	/**
	 * Share a post.
	 *
	 * @param int    $user_id User sharing the post.
	 * @param int    $post_id Original post to share.
	 * @param string $content Optional note/comment to accompany the share.
	 * @return int|WP_Error Share row ID on success; WP_Error('already_shared') on duplicate.
	 */
	public function share( int $user_id, int $post_id, string $content = '' ): int|WP_Error {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_shares
				 WHERE user_id = %d AND post_id = %d",
				$user_id,
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null !== $existing ) {
			return new WP_Error(
				'already_shared',
				__( 'You have already shared this post.', 'buddynext' )
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_shares',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
				'content' => $content,
			),
			array( '%d', '%d', '%s' )
		);

		$share_id = (int) $wpdb->insert_id;

		// Publish a feed post so the share card appears in the home feed.
		// Inherit the original post's privacy so visibility rules are respected.
		$original_privacy = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT privacy FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			)
		);
		$privacy          = in_array( $original_privacy, array( 'public', 'followers', 'connections', 'space_members', 'private' ), true )
			? $original_privacy
			: 'public';

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'        => $user_id,
				'shared_post_id' => $post_id,
				'type'           => 'share',
				'content'        => $content,
				'privacy'        => $privacy,
				'status'         => 'published',
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		$feed_post_id = (int) $wpdb->insert_id;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		( new PostService() )->adjust_share_count( $post_id, 1 );

		if ( $feed_post_id > 0 ) {
			do_action( 'buddynext_post_created', $feed_post_id, $user_id, 'share' );
		}

		return $share_id;
	}

	/**
	 * Remove a user's share of a post.
	 *
	 * Decrements the share_count (floor 0) on the original post.
	 * Silently succeeds if no share row exists.
	 *
	 * @param int $user_id User who shared the post.
	 * @param int $post_id Original post.
	 */
	public function unshare( int $user_id, int $post_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_shares',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
			),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $deleted ) {
			( new PostService() )->adjust_share_count( $post_id, -1 );
		}
	}

	/**
	 * Return the list of post IDs the user has shared (newest first).
	 *
	 * @param int $user_id User whose shares to list.
	 * @return int[]
	 */
	public function user_shares( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}bn_shares
				 WHERE user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $rows );
	}

	/**
	 * Return a paginated share history for a user.
	 *
	 * Each item includes the share ID, post ID, optional note content, and the
	 * timestamp the share was created.
	 *
	 * @param int $user_id  User whose share history to fetch.
	 * @param int $per_page Number of items per page (1–100). Default 20.
	 * @param int $page     1-based page number. Default 1.
	 * @return array{items: array[], total: int}
	 */
	public function user_shares_paginated( int $user_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$page     = max( 1, $page );
		$offset   = ( $page - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_id, content, created_at
				 FROM {$wpdb->prefix}bn_shares
				 WHERE user_id = %d
				 ORDER BY created_at DESC
				 LIMIT %d OFFSET %d",
				$user_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_shares WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$items = array_map(
			static function ( array $r ): array {
				return array(
					'id'         => (int) $r['id'],
					'post_id'    => (int) $r['post_id'],
					'content'    => $r['content'],
					'created_at' => $r['created_at'],
				);
			},
			(array) $rows
		);

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
