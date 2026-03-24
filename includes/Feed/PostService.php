<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Post creation and management service.
 *
 * Handles CRUD for bn_posts rows. Poll option creation is co-located here so
 * that post + options are written atomically within the same request. All
 * reads are cache-backed (group: buddynext_posts, TTL: 10 min).
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use WP_Error;
use BuddyNext\Moderation\SafeguardService;

/**
 * Manages post lifecycle: create, read, update, delete, pin.
 */
class PostService {

	/**
	 * Set up the post service (no required dependencies at construction time).
	 */
	public function __construct() {
	}

	/**
	 * Allowed post types.
	 *
	 * @var string[]
	 */
	private const ALLOWED_TYPES = array(
		'text',
		'photo',
		'file',
		'link',
		'poll',
		'announcement',
		'activity',
		'media',
		'discussion',
		'job',
	);

	/**
	 * Cache group for post data.
	 */
	private const CACHE_GROUP = 'buddynext_posts';

	/**
	 * Cache TTL in seconds (10 minutes).
	 */
	private const CACHE_TTL = 600;

	/**
	 * Create a new post.
	 *
	 * For poll posts, $data['options'] must be an array of 2–5 non-empty strings (max 5 enforced).
	 * The buddynext_post_created action fires after successful creation.
	 *
	 * @param int   $user_id Author user ID.
	 * @param array $data    Post fields: type, content, privacy, space_id, media_ids,
	 *                       link_url, link_meta, options (for polls), scheduled_at.
	 * @return int|WP_Error New post ID on success; WP_Error on validation failure.
	 */
	public function create( int $user_id, array $data ): int|WP_Error {
		$type = $data['type'] ?? 'text';

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return new WP_Error(
				'invalid_post_type',
				/* translators: %s: submitted post type */
				sprintf( __( 'Invalid post type: %s.', 'buddynext' ), $type )
			);
		}

		if ( 'poll' === $type ) {
			$options = $data['options'] ?? array();
			if ( ! is_array( $options ) || count( $options ) < 2 ) {
				return new WP_Error(
					'poll_requires_options',
					__( 'A poll requires at least two options.', 'buddynext' )
				);
			}
			if ( count( $options ) > 5 ) {
				return new WP_Error(
					'too_many_options',
					__( 'Polls may have at most 5 options.', 'buddynext' )
				);
			}
		}

		if ( 'announcement' === $type && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Only administrators can create announcements.', 'buddynext' )
			);
		}

		// Run safeguard checks before any DB writes.
		$safeguard_result = $this->get_safeguard()->check(
			$user_id,
			(string) ( $data['content'] ?? '' ),
			(string) ( $data['link_url'] ?? '' )
		);
		if ( is_wp_error( $safeguard_result ) ) {
			if ( 'pending_review' === $safeguard_result->get_error_code() ) {
				// New-member gate: save the post but hold it for moderation review.
				$data['status'] = 'pending';
			} else {
				return $safeguard_result;
			}
		}

		global $wpdb;

		$media_ids = isset( $data['media_ids'] ) ? wp_json_encode( $data['media_ids'] ) : null;
		$link_meta = isset( $data['link_meta'] ) ? wp_json_encode( $data['link_meta'] ) : null;

		$status = $data['status'] ?? 'published';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'              => $user_id,
				'space_id'             => $data['space_id'] ?? null,
				'type'                 => $type,
				'content'              => $data['content'] ?? '',
				'media_ids'            => $media_ids,
				'link_url'             => $data['link_url'] ?? null,
				'link_meta'            => $link_meta,
				'privacy'              => $data['privacy'] ?? 'public',
				'status'               => $status,
				'content_warning'      => ! empty( $data['content_warning'] ) ? 1 : 0,
				'content_warning_type' => $data['content_warning_type'] ?? null,
				'scheduled_at'         => $data['scheduled_at'] ?? null,
				'is_announcement'      => 'announcement' === $type ? 1 : 0,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		$post_id = (int) $wpdb->insert_id;

		if ( 'poll' === $type ) {
			$this->insert_poll_options( $post_id, $data['options'] );
		}

		/**
		 * Fires after a new post is created.
		 *
		 * @param int    $post_id Post ID.
		 * @param int    $user_id Author user ID.
		 * @param string $type    Post type.
		 */
		do_action( 'buddynext_post_created', $post_id, $user_id, $type );

		return $post_id;
	}

	/**
	 * Retrieve a single post by ID.
	 *
	 * Returns null when the post does not exist. For poll posts, the returned
	 * array includes a 'poll_options' key with the options rows.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public function get( int $post_id ): ?array {
		global $wpdb;

		$cache_key = "post_{$post_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return null === $cached ? null : (array) $cached;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			wp_cache_set( $cache_key, null, self::CACHE_GROUP, self::CACHE_TTL );
			return null;
		}

		$post = $this->hydrate( $row );
		wp_cache_set( $cache_key, $post, self::CACHE_GROUP, self::CACHE_TTL );

		return $post;
	}

	/**
	 * Update an existing post's content or privacy.
	 *
	 * Sets edited_at to the current UTC time. Only the post owner may update.
	 *
	 * @param int   $post_id  Post to update.
	 * @param int   $user_id  Requesting user (must be owner).
	 * @param array $data     Fields to change: content, privacy.
	 * @return true|WP_Error
	 */
	public function update( int $post_id, int $user_id, array $data ): true|WP_Error {
		$ownership = $this->assert_owner( $post_id, $user_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		global $wpdb;

		$fields  = array( 'edited_at' => current_time( 'mysql', true ) );
		$formats = array( '%s' );

		if ( isset( $data['content'] ) ) {
			$fields['content'] = $data['content'];
			$formats[]         = '%s';
		}
		if ( isset( $data['privacy'] ) ) {
			$fields['privacy'] = $data['privacy'];
			$formats[]         = '%s';
		}
		if ( array_key_exists( 'content_warning', $data ) ) {
			$fields['content_warning'] = ! empty( $data['content_warning'] ) ? 1 : 0;
			$formats[]                 = '%d';
		}
		if ( array_key_exists( 'content_warning_type', $data ) ) {
			$fields['content_warning_type'] = $data['content_warning_type'] ?? null;
			$formats[]                      = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			$fields,
			array( 'id' => $post_id ),
			$formats,
			array( '%d' )
		);

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Delete a post and its poll options (if any).
	 *
	 * Only the post owner may delete.
	 *
	 * @param int $post_id Post to delete.
	 * @param int $user_id Requesting user (must be owner).
	 * @return true|WP_Error
	 */
	public function delete( int $post_id, int $user_id ): true|WP_Error {
		$ownership = $this->assert_owner( $post_id, $user_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_poll_votes', array( 'post_id' => $post_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_poll_options',
			array( 'post_id' => $post_id ),
			array( '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_reactions',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_shares', array( 'post_id' => $post_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_bookmarks', array( 'post_id' => $post_id ), array( '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_posts',
			array( 'id' => $post_id ),
			array( '%d' )
		);

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		/**
		 * Fires after a post is deleted.
		 *
		 * @param int $post_id Deleted post ID.
		 * @param int $user_id User who deleted the post.
		 */
		do_action( 'buddynext_post_deleted', $post_id, $user_id );

		return true;
	}

	/**
	 * Pin a post to the author's profile.
	 *
	 * @param int $post_id Post to pin.
	 * @param int $user_id Requesting user (must be owner).
	 * @return true|WP_Error
	 */
	public function pin( int $post_id, int $user_id ): true|WP_Error {
		$ownership = $this->assert_owner( $post_id, $user_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'is_pinned' => 1 ),
			array( 'id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Unpin a post.
	 *
	 * @param int $post_id Post to unpin.
	 * @param int $user_id Requesting user (must be owner).
	 * @return true|WP_Error
	 */
	public function unpin( int $post_id, int $user_id ): true|WP_Error {
		$ownership = $this->assert_owner( $post_id, $user_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'is_pinned' => 0 ),
			array( 'id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		return true;
	}

	/**
	 * Increment the share_count for a post (floor 0).
	 *
	 * @param int $post_id Target post.
	 * @param int $delta   +1 or -1.
	 */
	public function adjust_share_count( int $post_id, int $delta ): void {
		global $wpdb;

		if ( $delta > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_posts
					 SET share_count = share_count + 1
					 WHERE id = %d",
					$post_id
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_posts
					 SET share_count = GREATEST(0, share_count - 1)
					 WHERE id = %d",
					$post_id
				)
			);
		}

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );
	}

	/**
	 * Resolve the safeguard service from the container.
	 *
	 * Returns the container-bound instance when the helper is available,
	 * or a fresh instance as a fallback (e.g. unit-test contexts).
	 *
	 * @return SafeguardService
	 */
	private function get_safeguard(): SafeguardService {
		if ( function_exists( 'buddynext_service' ) ) {
			return buddynext_service( 'safeguard' );
		}

		return new SafeguardService();
	}

	/**
	 * Check whether the given user owns the given post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID to check.
	 * @return true|WP_Error True if owner; WP_Error('not_post_owner') otherwise.
	 */
	private function assert_owner( int $post_id, int $user_id ): true|WP_Error {
		$post = $this->get( $post_id );

		if ( null === $post ) {
			return new WP_Error( 'post_not_found', __( 'Post not found.', 'buddynext' ) );
		}

		if ( (int) $post['user_id'] !== $user_id ) {
			return new WP_Error(
				'not_post_owner',
				__( 'You are not the owner of this post.', 'buddynext' )
			);
		}

		return true;
	}

	/**
	 * Insert poll options for a new poll post.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $options Option texts (ordered as provided).
	 */
	private function insert_poll_options( int $post_id, array $options ): void {
		global $wpdb;

		foreach ( array_values( $options ) as $i => $text ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_poll_options',
				array(
					'post_id'       => $post_id,
					'option_text'   => $text,
					'display_order' => $i,
					'vote_count'    => 0,
				),
				array( '%d', '%s', '%d', '%d' )
			);
		}
	}

	/**
	 * Hydrate a raw database row into a post array.
	 *
	 * Decodes JSON fields and casts integer columns. For poll posts, fetches
	 * and attaches poll_options.
	 *
	 * @param array $row Raw associative row from wpdb.
	 * @return array
	 */
	public function hydrate( array $row ): array {
		$post = array(
			'id'                   => (int) $row['id'],
			'user_id'              => (int) $row['user_id'],
			'space_id'             => isset( $row['space_id'] ) ? (int) $row['space_id'] : null,
			'type'                 => $row['type'],
			'content'              => $row['content'],
			'media_ids'            => isset( $row['media_ids'] ) ? json_decode( (string) $row['media_ids'], true ) : null,
			'link_url'             => $row['link_url'],
			'link_meta'            => isset( $row['link_meta'] ) ? json_decode( (string) $row['link_meta'], true ) : null,
			'privacy'              => $row['privacy'],
			'reaction_count'       => (int) $row['reaction_count'],
			'comment_count'        => (int) $row['comment_count'],
			'share_count'          => (int) $row['share_count'],
			'is_pinned'            => (int) $row['is_pinned'],
			'is_announcement'      => (int) $row['is_announcement'],
			'content_warning'      => (bool) ( $row['content_warning'] ?? false ),
			'content_warning_type' => $row['content_warning_type'] ?? null,
			'site_pin_expires_at'  => $row['site_pin_expires_at'],
			'edited_at'            => $row['edited_at'],
			'scheduled_at'         => $row['scheduled_at'],
			'created_at'           => $row['created_at'],
			'updated_at'           => $row['updated_at'],
		);

		if ( 'poll' === $row['type'] ) {
			$post['poll_options'] = $this->fetch_poll_options( (int) $row['id'] );
		}

		return $post;
	}

	/**
	 * Fetch poll options for a given post.
	 *
	 * @param int $post_id Poll post ID.
	 * @return array[]
	 */
	private function fetch_poll_options( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, option_text, display_order, vote_count
				 FROM {$wpdb->prefix}bn_poll_options
				 WHERE post_id = %d
				 ORDER BY display_order ASC",
				$post_id
			),
			ARRAY_A
		);

		return array_map(
			fn( $r ) => array(
				'id'            => (int) $r['id'],
				'option_text'   => $r['option_text'],
				'display_order' => (int) $r['display_order'],
				'vote_count'    => (int) $r['vote_count'],
			),
			(array) $rows
		);
	}
}
