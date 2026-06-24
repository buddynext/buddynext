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
	 * @return int|WP_Error The new share ACTIVITY post id (bn_posts) on success;
	 *                      WP_Error('already_shared') on duplicate.
	 */
	public function share( int $user_id, int $post_id, string $content = '' ): int|WP_Error {
		if ( '0' === (string) get_option( 'buddynext_allow_shares', '1' ) ) {
			return new WP_Error(
				'shares_disabled',
				__( 'Sharing is disabled on this community.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		global $wpdb;

		// If the target is itself a reshare, amplify the ORIGINAL post instead
		// (flatten the chain). "Sharing a share" shares the root — matching what
		// members expect and avoiding share-of-a-share nesting. All downstream
		// logic (duplicate check, privacy inheritance, share_count, feed card)
		// then operates on the original post.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$root_post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT shared_post_id FROM {$wpdb->prefix}bn_posts WHERE id = %d AND type = 'share' LIMIT 1",
				$post_id
			)
		);
		if ( $root_post_id > 0 ) {
			$post_id = $root_post_id;
		}

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

		/**
		 * Filter share data before it is written.
		 *
		 * Return modified data to transform the share caption, or a WP_Error to reject it.
		 *
		 * @param array $data    Share data (user_id, post_id, content).
		 * @param int   $user_id Sharing user ID.
		 */
		$filtered = apply_filters(
			'buddynext_share_before_save',
			array(
				'user_id' => $user_id,
				'post_id' => $post_id,
				'content' => $content,
			),
			$user_id
		);
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$content = (string) ( $filtered['content'] ?? $content );

		// INSERT IGNORE so a concurrent request that slipped past the existence
		// check above is rejected by the user_post UNIQUE key instead of creating a
		// duplicate. UTC write so share-history relative times are timezone-correct.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}bn_shares (user_id, post_id, content, created_at)
				 VALUES (%d, %d, %s, %s)",
				$user_id,
				$post_id,
				$content,
				current_time( 'mysql', true )
			)
		);

		// rows_affected 0 = the duplicate was ignored (a racing request already
		// shared). Bail WITHOUT publishing a second feed card or double-incrementing
		// share_count.
		if ( $wpdb->rows_affected < 1 ) {
			return new WP_Error(
				'already_shared',
				__( 'You have already shared this post.', 'buddynext' )
			);
		}

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
				// UTC write so the share card's feed timestamp is correct (mirrors
				// PostService::create()); the local-time default rendered "just now".
				'created_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		$feed_post_id = (int) $wpdb->insert_id;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		( new PostService() )->adjust_share_count( $post_id, 1 );

		if ( $feed_post_id > 0 ) {
			do_action( 'buddynext_post_created', $feed_post_id, $user_id, 'share' );
		}

		/**
		 * Fires after a post is shared.
		 *
		 * Distinct from `buddynext_post_created` (which fires for every new
		 * post regardless of type): this event is specific to the share
		 * action and carries the original post ID so listeners do not have
		 * to re-query for the shared_post_id.
		 *
		 * Argument order matches the consumer convention (`$share_id` →
		 * `$original_post_id` → `$user_id`).
		 *
		 * @param int $share_id         Row ID in bn_shares.
		 * @param int $original_post_id Original post that was shared.
		 * @param int $user_id          User who shared the post.
		 */
		do_action( 'buddynext_post_shared', $share_id, $post_id, $user_id );

		// Return the new share ACTIVITY post id (bn_posts), NOT the bn_shares
		// relationship row id: callers need the post id to render the repost card
		// (ShareController hydration), and to track/engage it (the demo seeder).
		// The bn_shares row id is still passed to buddynext_post_shared above for
		// listeners that key off the relationship.
		return $feed_post_id;
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
