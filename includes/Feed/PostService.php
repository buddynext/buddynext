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
use BuddyNext\Moderation\ModerationService;

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
		'event',
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

		// Suspended users are locked out of all content creation (spec 09-moderation:
		// "Suspend — locked out… cannot post/comment/react"). Gate before any DB write.
		if ( $this->is_author_suspended( $user_id ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Your account is suspended and cannot create posts.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// Run safeguard checks before any DB writes.
		$safeguard_result = $this->get_safeguard()->check(
			$user_id,
			(string) ( $data['content'] ?? '' ),
			(string) ( $data['link_url'] ?? '' )
		);
		$flag_reason = '';
		if ( is_wp_error( $safeguard_result ) ) {
			if ( 'pending_review' === $safeguard_result->get_error_code() ) {
				// New-member gate: save the post but hold it for moderation review.
				$data['status'] = 'pending';
			} elseif ( $this->is_flag_error( $safeguard_result ) ) {
				// severity=flag rule (e.g. bnpro_keyword_flagged / bnpro_link_flagged,
				// HTTP 202): the post is allowed through and published, but a system
				// report is filed below so reviewers can act on it. Distinct from a
				// hard block (422), which still rejects via the else branch.
				$flag_reason = (string) $safeguard_result->get_error_message();
			} else {
				// Hard block (422), suspension, rate limit, etc. — reject outright.
				return $safeguard_result;
			}
		}

		/**
		 * Filter post data before it is written on create.
		 *
		 * Return a modified $data array to transform the post, or a WP_Error to
		 * reject it. Runs after the built-in safeguard checks. Only the known
		 * post columns are persisted; extra keys are ignored.
		 *
		 * @param array $data    Post data (content, privacy, media_ids, link_url, etc.).
		 * @param int   $user_id Author user ID.
		 * @param int|null $post_id Null on create.
		 */
		$filtered = apply_filters( 'buddynext_post_before_save', $data, $user_id, null );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$data = (array) $filtered;

		global $wpdb;

		$media_ids = isset( $data['media_ids'] ) ? wp_json_encode( $data['media_ids'] ) : null;

		// Auto-fetch OG metadata when link_url is set but link_meta is empty.
		if ( ! empty( $data['link_url'] ) && empty( $data['link_meta'] ) ) {
			$data['link_meta'] = self::fetch_og_meta( (string) $data['link_url'] );
		}

		$link_meta = isset( $data['link_meta'] ) ? wp_json_encode( $data['link_meta'] ) : null;

		$status = $data['status'] ?? 'published';

		// Announcements may carry an optional expiry (UTC). active_announcement()
		// honours it: NULL = pinned until dismissed, a past time = no longer pinned.
		$pin_expires = null;
		if ( 'announcement' === $type && ! empty( $data['announcement_expires_at'] ) ) {
			$ts          = strtotime( (string) $data['announcement_expires_at'] );
			$pin_expires = $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
				'site_pin_expires_at'  => $pin_expires,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$post_id = (int) $wpdb->insert_id;

		// Mirror attached media into the engine's provider-neutral link store
		// (object_type='bn_post') so the link is migration-safe and the engine
		// can resolve a post's media. The bn_posts.media_ids JSON stays the
		// canonical read for now; this is an additive, best-effort mirror.
		if ( $post_id > 0 && ! empty( $data['media_ids'] ) && is_array( $data['media_ids'] ) ) {
			\BuddyNext\Media\ObjectMediaLink::set(
				\BuddyNext\Media\ObjectMediaLink::POST,
				$post_id,
				array_map( 'absint', $data['media_ids'] )
			);
		}

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

		// An auto-moderation flag (severity=flag) lets the post publish but files a
		// system report so the content surfaces in the moderation queue. reporter_id
		// 0 marks it as system-generated; the flag message is preserved as notes.
		// ModerationService::report() also fans out buddynext_moderation_auto_actions,
		// so threshold-based auto-actions can still fire on the auto-flag.
		if ( '' !== $flag_reason && $post_id > 0 ) {
			$this->report_flagged_post( $post_id, $flag_reason, (int) ( $data['space_id'] ?? 0 ) );
		}

		// Parse @username mentions and fire buddynext_user_mentioned for each.
		$content = (string) ( $data['content'] ?? '' );
		if ( '' !== $content ) {
			preg_match_all( '/@([a-zA-Z0-9_-]+)/u', $content, $mention_matches );
			foreach ( $mention_matches[1] as $raw_username ) {
				$username       = sanitize_user( (string) $raw_username, true );
				$mentioned_user = get_user_by( 'login', $username );
				if ( $mentioned_user instanceof \WP_User && $mentioned_user->ID !== $user_id ) {
					/**
					 * Fires when a user is @mentioned in a BuddyNext post.
					 *
					 * @param int $mentioned_user_id ID of the user who was mentioned.
					 * @param int $mentioner_id      ID of the user who wrote the post.
					 * @param int $post_id           Post ID containing the mention.
					 */
					do_action( 'buddynext_user_mentioned', $mentioned_user->ID, $user_id, $post_id );
				}
			}
		}

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		/**
		 * Filter post data before it is written on update.
		 *
		 * Return a modified $data array to transform the edit, or a WP_Error to
		 * reject it. Only the known editable columns are persisted.
		 *
		 * @param array    $data    Edit data (content, privacy, content_warning, etc.).
		 * @param int      $user_id User performing the update.
		 * @param int|null $post_id Post being updated.
		 */
		$filtered = apply_filters( 'buddynext_post_before_save', $data, $user_id, $post_id );
		if ( is_wp_error( $filtered ) ) {
			return $filtered;
		}
		$data = (array) $filtered;

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			$fields,
			array( 'id' => $post_id ),
			$formats,
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		/**
		 * Fires after a post is updated.
		 *
		 * @param int   $post_id Post ID.
		 * @param int   $user_id User who updated the post.
		 * @param array $fields  Columns written this update (edited_at plus any of content, privacy, content_warning, content_warning_type).
		 */
		do_action( 'buddynext_post_updated', $post_id, $user_id, $fields );

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

		// Cascade-delete all child rows before removing the post.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_poll_votes', array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bn_poll_options', array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete(
			$wpdb->prefix . 'bn_reactions',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		$wpdb->delete(
			$wpdb->prefix . 'bn_comments',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		$wpdb->delete( $wpdb->prefix . 'bn_shares', array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bn_bookmarks', array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bn_posts', array( 'id' => $post_id ), array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
	 * @param int      $post_id  Post to pin.
	 * @param int      $user_id  Requesting user (must be owner).
	 * @param int|null $space_id Optional space context for space-pinning (null = profile pin).
	 * @return true|WP_Error
	 */
	public function pin( int $post_id, int $user_id, ?int $space_id = null ): true|WP_Error {
		$ownership = $this->assert_owner( $post_id, $user_id );
		if ( is_wp_error( $ownership ) ) {
			return $ownership;
		}

		/**
		 * Filter the maximum number of posts a user may pin per scope.
		 *
		 * Free behaviour: 1 pin per scope (profile or space). Pro can raise this
		 * limit for premium members by returning a higher integer.
		 *
		 * @since 1.0.0
		 *
		 * @param int      $limit    Maximum pinned posts allowed. Default 1.
		 * @param int|null $space_id Space ID when pinning inside a space, null for profile pins.
		 * @param int      $user_id  The user performing the pin action.
		 */
		$pin_limit = (int) apply_filters( 'buddynext_post_pin_limit', 1, $space_id, $user_id );

		global $wpdb;

		if ( $pin_limit > 0 ) {
			$pin_where = null === $space_id ? 'AND space_id IS NULL' : $wpdb->prepare( 'AND space_id = %d', $space_id );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$pinned_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
					 WHERE user_id = %d AND is_pinned = 1 {$pin_where}",
					$user_id
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $pinned_count >= $pin_limit ) {
				return new WP_Error(
					'pin_limit_reached',
					__( 'You have reached the maximum number of pinned posts.', 'buddynext' )
				);
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'is_pinned' => 1 ),
			array( 'id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'is_pinned' => 0 ),
			array( 'id' => $post_id ),
			array( '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		if ( $delta > 0 ) {
			$this->increment_counter( $post_id, 'share_count' );
		} else {
			$this->decrement_counter( $post_id, 'share_count' );
		}
	}

	/**
	 * Counter columns on bn_posts that may be incremented/decremented.
	 *
	 * @var string[]
	 */
	private const COUNTER_COLUMNS = array( 'comment_count', 'reaction_count', 'share_count' );

	/**
	 * Increment a bn_posts counter column by 1 and bust the post cache.
	 *
	 * Single home for the denormalised engagement counters — callers in
	 * CommentService, ReactionService and the WPMediaVerse bridge route here
	 * instead of writing bn_posts directly.
	 *
	 * @param int    $post_id Post id.
	 * @param string $column  One of self::COUNTER_COLUMNS.
	 */
	public function increment_counter( int $post_id, string $column ): void {
		if ( $post_id <= 0 || ! in_array( $column, self::COUNTER_COLUMNS, true ) ) {
			return;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET {$column} = {$column} + 1 WHERE id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );
	}

	/**
	 * Decrement a bn_posts counter column by 1 (never below zero) and bust cache.
	 *
	 * @param int    $post_id Post id.
	 * @param string $column  One of self::COUNTER_COLUMNS.
	 */
	public function decrement_counter( int $post_id, string $column ): void {
		if ( $post_id <= 0 || ! in_array( $column, self::COUNTER_COLUMNS, true ) ) {
			return;
		}

		global $wpdb;
		// GREATEST(1, col) - 1 floors at zero WITHOUT underflowing the UNSIGNED
		// column (col - 1 when col is 0 wraps to a huge value on unsigned).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET {$column} = GREATEST(1, {$column}) - 1 WHERE id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );
	}

	/**
	 * Return a post's author id, or 0 if the post does not exist.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	public function get_author_id( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Whether a safeguard WP_Error represents a non-blocking "flag" outcome.
	 *
	 * A flag means the post is allowed through but must be reported for review.
	 * Recognised by either the conventional error code suffix `_flagged`
	 * (e.g. bnpro_keyword_flagged, bnpro_link_flagged) or an HTTP 202 status in
	 * the error data — the same "accepted, held for review" semantics the
	 * new-member gate uses. Hard blocks (status 422) are deliberately excluded.
	 *
	 * @param WP_Error $error Safeguard result.
	 * @return bool
	 */
	private function is_flag_error( WP_Error $error ): bool {
		$code = (string) $error->get_error_code();
		if ( '' !== $code && str_ends_with( $code, '_flagged' ) ) {
			return true;
		}

		$data = $error->get_error_data();
		return is_array( $data ) && isset( $data['status'] ) && 202 === (int) $data['status'];
	}

	/**
	 * File a system-generated report against an auto-flagged post.
	 *
	 * Resolves the moderation service from the container when available and
	 * falls back to a fresh instance otherwise (e.g. unit-test contexts). Any
	 * failure to resolve degrades silently so the post path never fatals when
	 * moderation is unavailable.
	 *
	 * @param int    $post_id  The post that was flagged.
	 * @param int    $space_id Space context (0 = none).
	 * @param string $reason   Human-readable flag message (stored as report notes).
	 * @return void
	 */
	private function report_flagged_post( int $post_id, string $reason, int $space_id = 0 ): void {
		$moderation = function_exists( 'buddynext_service' )
			? buddynext_service( 'moderation' )
			: new ModerationService();

		if ( ! $moderation instanceof ModerationService ) {
			return;
		}

		// reporter_id 0 = system; 'inappropriate' is the closest valid report
		// reason for an automated content flag, with the rule's message kept as
		// free-text notes so reviewers see why it was flagged.
		$moderation->report( 0, 'post', $post_id, 'inappropriate', $space_id, $reason );
	}

	/**
	 * Whether the author currently has an active suspension.
	 *
	 * Resolves the moderation service from the container when available and
	 * falls back to a fresh instance otherwise (e.g. unit-test contexts). Any
	 * failure to resolve the service degrades to "not suspended" so the post
	 * path never fatals when moderation is unavailable.
	 *
	 * @param int $user_id Author user ID.
	 * @return bool True when the user has an active, unexpired suspension.
	 */
	private function is_author_suspended( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$moderation = function_exists( 'buddynext_service' )
			? buddynext_service( 'moderation' )
			: new ModerationService();

		if ( ! $moderation instanceof ModerationService ) {
			return false;
		}

		return $moderation->is_suspended( $user_id );
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( array_values( $options ) as $i => $raw ) {
			// Accept both flat string values and associative arrays with label/text keys.
			if ( is_array( $raw ) ) {
				$text = (string) ( $raw['label'] ?? $raw['text'] ?? $raw['option_text'] ?? '' );
			} else {
				$text = (string) $raw;
			}
			if ( '' === trim( $text ) ) {
				continue;
			}
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
			'shared_post_id'       => isset( $row['shared_post_id'] ) ? (int) $row['shared_post_id'] : null,
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

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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

	/**
	 * Fetch Open Graph metadata from a URL.
	 *
	 * Uses wp_remote_get with a 5s timeout. Extracts og:title, og:description,
	 * og:image from <meta> tags. Falls back to <title> tag.
	 *
	 * @param string $url URL to fetch.
	 * @return array{title: string, description: string, thumbnail: string}
	 */
	private static function fetch_og_meta( string $url ): array {
		$meta = array(
			'title'       => '',
			'description' => '',
			'thumbnail'   => '',
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 5,
				'user-agent' => 'BuddyNext/1.0 (Link Preview)',
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $meta;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $meta;
		}

		// Extract og:title.
		if ( preg_match( '/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:title["\']/', $body, $m ) ) {
			$meta['title'] = html_entity_decode( trim( $m[1] ? $m[1] : $m[2] ), ENT_QUOTES, 'UTF-8' );
		} elseif ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $body, $m ) ) {
			$meta['title'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract og:description.
		if ( preg_match( '/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:description["\']/', $body, $m ) ) {
			$meta['description'] = html_entity_decode( trim( $m[1] ? $m[1] : $m[2] ), ENT_QUOTES, 'UTF-8' );
		} elseif ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $body, $m ) ) {
			$meta['description'] = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
		}

		// Extract og:image.
		if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']|<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/', $body, $m ) ) {
			$meta['thumbnail'] = esc_url_raw( trim( $m[1] ? $m[1] : $m[2] ) );
		}

		return $meta;
	}
}
