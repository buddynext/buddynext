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
			if ( '0' === (string) get_option( 'buddynext_allow_polls', '1' ) ) {
				return new WP_Error(
					'polls_disabled',
					__( 'Polls are disabled on this community.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
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
				__( 'Only administrators can create announcements.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		// An archived space is read-only — refuse new posts targeting it.
		$target_space_id = (int) ( $data['space_id'] ?? 0 );
		if ( $target_space_id > 0 && buddynext_service( 'spaces' )->is_archived( $target_space_id ) ) {
			return new WP_Error(
				'space_archived',
				__( 'This space is archived and no longer accepts new posts.', 'buddynext' ),
				array( 'status' => 403 )
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
			(string) ( $data['link_url'] ?? '' ),
			$target_space_id
		);
		$flag_reason      = '';
		if ( is_wp_error( $safeguard_result ) ) {
			if ( 'pending_review' === $safeguard_result->get_error_code() || $this->is_flag_error( $safeguard_result ) ) {
				// Reactive moderation (FB / LinkedIn model — members post freely, no
				// pre-publish approval queue). The new-member gate and duplicate-
				// content holds (pending_review) are treated exactly like a
				// severity=flag rule (e.g. bnpro_keyword_flagged, HTTP 202): the post
				// PUBLISHES and a system report is filed below so it surfaces in the
				// moderation queue for review. Previously pending_review set the post
				// to 'pending' status, which hid it with no review surface — the post
				// was held invisibly and never reached the queue, so a moderator could
				// neither see nor approve it. A hard block (422) still rejects outright
				// via the else branch.
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

		// A post created with a future scheduled_at is scheduled, not live — flip
		// it so it is not published immediately (a held 'pending' post keeps its
		// status: moderation must clear it before it can go live/scheduled).
		if ( 'published' === $status
			&& ! empty( $data['scheduled_at'] )
			&& strtotime( (string) $data['scheduled_at'] ) > time()
		) {
			$status = 'scheduled';
		}

		// Pre-moderation: hold the post for approval when the community's rules
		// apply. Held posts get status='pending' — kept out of every feed (feeds
		// filter status='published') with no live side-effects until a moderator
		// approves. Off by default; only fires when an owner opts in.
		if ( 'published' === $status
			&& ( new \BuddyNext\Moderation\PreModerationService() )->should_hold( $user_id, $data )
		) {
			$status = 'pending';
		}

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
				'privacy'              => $data['privacy'] ?? (string) get_option( 'buddynext_default_post_privacy', 'public' ),
				'status'               => $status,
				'content_warning'      => ! empty( $data['content_warning'] ) ? 1 : 0,
				'content_warning_type' => $data['content_warning_type'] ?? null,
				'scheduled_at'         => $data['scheduled_at'] ?? null,
				'is_announcement'      => 'announcement' === $type ? 1 : 0,
				'site_pin_expires_at'  => $pin_expires,
				// Write created_at explicitly in UTC instead of relying on MySQL's
				// DEFAULT CURRENT_TIMESTAMP (server-local). The relative-time
				// renderers read it with strtotime(), which treats a bare datetime
				// as UTC — a local-time default produced a negative diff that the
				// "< 60s" branch caught, so every same-day post showed "just now".
				'created_at'           => current_time( 'mysql', true ),
				// Seed last_activity_at to the post's own time so a brand-new post
				// sorts correctly in the "Active" feed before it receives any
				// engagement. Bumped to NOW() on each reaction/comment/share.
				'last_activity_at'     => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
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
			$this->insert_poll_options( $post_id, $data['options'], (string) ( $data['poll_end_date'] ?? '' ) );
		}

		// Live side-effects fire only for a published post. A held ('pending')
		// post — like a future 'scheduled' one — stays fully dormant: no feed
		// fan-out, notifications, search indexing, hashtags, webhooks, or realtime
		// until it goes live. The hook re-fires on approval (approve_pending) /
		// scheduled publish, so the live edge is the right one.
		if ( 'published' === $status && $post_id > 0 ) {
			$this->run_post_published_effects( $post_id, $user_id, $type, $data, $flag_reason );
		} elseif ( 'scheduled' === $status && $post_id > 0 ) {
			// Arm the on-demand publisher for this post's due time. Free owns
			// scheduled-post publishing so this works with Pro absent.
			ScheduledPostsPublisher::arm();
		}

		return $post_id;
	}

	/**
	 * Run the side-effects that should happen only when a post is actually live:
	 * the buddynext_post_created fan-out, the auto-moderation flag report, and
	 *
	 * @mention notifications. Called from create() for an immediately-published
	 * post and from approve_pending() when a held post is approved.
	 *
	 * @param int                  $post_id     Post ID.
	 * @param int                  $user_id     Author user ID.
	 * @param string               $type        Post type.
	 * @param array<string, mixed> $data        Post data (content, space_id).
	 * @param string               $flag_reason Auto-moderation flag reason, or ''.
	 * @return void
	 */
	private function run_post_published_effects( int $post_id, int $user_id, string $type, array $data, string $flag_reason = '' ): void {
		/**
		 * Fires after a new post goes live.
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
		if ( '' !== $flag_reason ) {
			$this->report_flagged_post( $post_id, $flag_reason, (int) ( $data['space_id'] ?? 0 ) );
		}

		// Parse @username mentions and fire buddynext_user_mentioned for each.
		$content = (string) ( $data['content'] ?? '' );
		if ( '' !== $content ) {
			preg_match_all( '/@([a-zA-Z0-9_-]+)/u', $content, $mention_matches );
			foreach ( $mention_matches[1] as $raw_username ) {
				$username       = sanitize_user( (string) $raw_username, true );
				$mentioned_user = get_user_by( 'login', $username );
				if ( $mentioned_user instanceof \WP_User && $mentioned_user->ID !== $user_id
					&& $this->mention_allowed( $user_id, (int) $mentioned_user->ID ) ) {
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
	}

	/**
	 * Whether an actor may @mention a target, honouring the target's
	 * `bn_privacy_mention` audience preference (everyone / members /
	 * connections / nobody). Fails open if the privacy service is unavailable.
	 *
	 * @param int $actor_id  Author writing the mention.
	 * @param int $target_id Mentioned user.
	 * @return bool
	 */
	private function mention_allowed( int $actor_id, int $target_id ): bool {
		$privacy = function_exists( 'buddynext_service' ) ? buddynext_service( 'privacy' ) : null;
		if ( $privacy instanceof \BuddyNext\SocialGraph\PrivacyService ) {
			return $privacy->can_mention( $actor_id, $target_id );
		}
		return true;
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
	 * Invalidate the cached copy of a single post.
	 *
	 * Exposed so collaborators that mutate a post's denormalised state without
	 * going through PostService — notably PollService when a vote changes an
	 * option's vote_count — can drop the stale `get()` cache. Without this the
	 * feed served stale poll tallies (and, on a persistent object cache, could
	 * pin an out-of-date options set) until the entry expired.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function flush_cache( int $post_id ): void {
		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );
	}

	/**
	 * Ensure a post array carries its poll options.
	 *
	 * The feed queries select bare `bn_posts` rows and never join poll options,
	 * so every surface that renders a card from a raw feed row (home feed, hashtag
	 * feed, REST pagination) has to attach them before handing the post to
	 * `partials/post-card.php`. That hydration used to be copy-pasted into each
	 * template; one copy (the hashtag feed) was missing, so polls there rendered
	 * as plain text. This is the single shared path: type-gated, no-op when the
	 * options are already present, and instantiated directly (no container) so it
	 * works on every front-end route regardless of bootstrap order.
	 *
	 * @param array<string,mixed> $post Post array (must have 'id' + 'type').
	 * @return array<string,mixed> The post with 'poll_options' populated for polls.
	 */
	public static function attach_poll_options( array $post ): array {
		if ( 'poll' !== ( $post['type'] ?? '' ) || ! empty( $post['poll_options'] ) ) {
			return $post;
		}
		$full = ( new self() )->get( (int) ( $post['id'] ?? 0 ) );
		if ( null !== $full && ! empty( $full['poll_options'] ) ) {
			$post['poll_options'] = $full['poll_options'];
		}
		return $post;
	}

	/**
	 * Count a user's published posts (the figure profile/edit surfaces show).
	 *
	 * Deliberately uncached: the value changes across many write paths (publish,
	 * delete, moderation removal, scheduled publish) and the query is a single
	 * indexed COUNT, so a stale cache costs more than the read.
	 *
	 * @param int $user_id Author user ID.
	 * @return int
	 */
	public function user_post_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
				$user_id
			)
		);
	}

	/**
	 * List a user's own scheduled (future) posts, soonest first, hydrated through
	 * the canonical mapper. Powers the owner-only profile "Scheduled" tab.
	 *
	 * @param int $user_id Author user ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return array<int,array<string,mixed>>
	 */
	public function user_scheduled_posts( int $user_id, int $limit = 20 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d AND status = 'scheduled'
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Count a user's scheduled posts — the figure the owner-only "Scheduled"
	 * tab badge shows.
	 *
	 * @param int $user_id Author user ID.
	 * @return int
	 */
	public function user_scheduled_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'scheduled'",
				$user_id
			)
		);
	}

	/**
	 * List a user's recent replies (post comments), newest first, joined to the
	 * post they replied on. Powers the profile "Replies" tab.
	 *
	 * Deliberately uncached: the profile view runs this once per page and the
	 * query is an indexed join capped at $limit rows.
	 *
	 * @param int $user_id Comment author user ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return array<int,array<string,mixed>>
	 */
	public function user_replies( int $user_id, int $limit = 20 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.id, c.content, c.created_at, c.object_id,
				        p.content AS post_content, p.type AS post_type,
				        u.display_name AS post_author_name
				 FROM {$wpdb->prefix}bn_comments c
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id AND c.object_type = 'post'
				 INNER JOIN {$wpdb->users} u ON u.ID = p.user_id
				 WHERE c.user_id = %d
				 ORDER BY c.created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List the published posts a user has reacted to, newest reaction first,
	 * hydrated through the canonical mapper. Powers the profile "Likes" tab.
	 *
	 * @param int $user_id Reacting user ID.
	 * @param int $limit   Max rows (1-50). Default 20.
	 * @return array<int,array<string,mixed>>
	 */
	public function user_liked_posts( int $user_id, int $limit = 20 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 50, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.*
				 FROM {$wpdb->prefix}bn_reactions r
				 INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id AND r.object_type = 'post'
				 WHERE r.user_id = %d AND p.status = 'published'
				 ORDER BY r.created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Count a user's total post replies (comments on posts) — the figure the
	 * profile "Replies" tab badge shows.
	 *
	 * Uncached for the same reason as user_post_count(): the value moves across
	 * several write paths and the query is a single indexed COUNT.
	 *
	 * @param int $user_id Comment author user ID.
	 * @return int
	 */
	public function reply_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE user_id = %d AND object_type = 'post'",
				$user_id
			)
		);
	}

	/**
	 * Count the posts a user has reacted to — the figure the profile "Likes"
	 * tab badge shows.
	 *
	 * @param int $user_id Reacting user ID.
	 * @return int
	 */
	public function reaction_count( int $user_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions WHERE user_id = %d AND object_type = 'post'",
				$user_id
			)
		);
	}

	/**
	 * Filter a list of post IDs down to those the viewer is permitted to see.
	 *
	 * Single source of truth for the post-privacy gate that was previously
	 * duplicated in templates/feed/bookmarks.php and PostController::get_post().
	 * Applies, per post, in order: published-status, block list (bidirectional),
	 * secret/hidden-space membership, followers-only privacy, private privacy,
	 * and author suspension/shadow-ban. The author always sees their own posts;
	 * admins (manage_options) bypass the space-membership and author-status
	 * gates. Input order is preserved in the returned list.
	 *
	 * @param array<int,int> $post_ids Candidate post IDs.
	 * @param int            $viewer   Viewing user ID (0 = guest).
	 * @return array<int,int> The subset of $post_ids visible to the viewer.
	 */
	public function filter_visible( array $post_ids, int $viewer ): array {
		$post_ids = array_values( array_filter( array_map( 'absint', $post_ids ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$blocks        = function_exists( 'buddynext_service' )
			? buddynext_service( 'blocks' )
			: new \BuddyNext\SocialGraph\BlockService();
		$follows       = function_exists( 'buddynext_service' )
			? buddynext_service( 'follows' )
			: new \BuddyNext\SocialGraph\FollowService();
		$spaces        = new \BuddyNext\Spaces\SpaceService();
		$space_members = new \BuddyNext\Spaces\SpaceMemberService();
		$is_admin      = $viewer > 0 && user_can( $viewer, 'manage_options' );

		$visible = array();
		foreach ( $post_ids as $post_id ) {
			$post = $this->get( $post_id );
			if ( null === $post ) {
				continue;
			}
			if ( isset( $post['status'] ) && 'published' !== $post['status'] ) {
				continue;
			}

			$author_id = (int) ( $post['user_id'] ?? 0 );
			if ( $author_id <= 0 ) {
				continue;
			}
			$is_author = $author_id === $viewer;

			// Gate 1 — block list (bidirectional).
			if ( ! $is_author && $blocks->is_blocking_either( $viewer, $author_id ) ) {
				continue;
			}

			// Gate 2 — secret/hidden-space membership.
			$space_id = (int) ( $post['space_id'] ?? 0 );
			if ( $space_id > 0 ) {
				$space = $spaces->get( $space_id );
				if (
					null !== $space
					&& \BuddyNext\Spaces\SpaceTypeRegistry::instance()->is_hidden_from_non_members( (string) ( $space['type'] ?? '' ) )
				) {
					$is_member = $viewer > 0 && $space_members->is_member( $space_id, $viewer );
					if ( ! $is_member && ! $is_admin ) {
						continue;
					}
				}
			}

			// Gate 3 — followers-only privacy.
			if ( 'followers' === ( $post['privacy'] ?? '' ) && ! $is_author ) {
				if ( ! ( $viewer > 0 && $follows->is_following( $viewer, $author_id ) ) ) {
					continue;
				}
			}

			// Gate 4 — private posts (author-only).
			if ( 'private' === ( $post['privacy'] ?? '' ) && ! $is_author ) {
				continue;
			}

			// Gate 5 — author suspended / shadow-banned (admins and the author bypass).
			if ( ! $is_admin && ! $is_author ) {
				$suspended = (bool) get_user_meta( $author_id, 'bn_suspended', true );
				$shadow    = (bool) get_user_meta( $author_id, 'bn_shadow_banned', true );
				if ( $suspended || $shadow ) {
					continue;
				}
			}

			$visible[] = $post_id;
		}

		return $visible;
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

		// Enforce the post edit window (buddynext_post_edit_window, minutes): once
		// a post is older than the window a non-admin can no longer edit it.
		// 0 = unlimited.
		$edit_window = (int) get_option( 'buddynext_post_edit_window', 60 );
		if ( $edit_window > 0 && ! user_can( $user_id, 'manage_options' ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$created_at = $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) );
			if ( $created_at && ( time() - strtotime( (string) $created_at . ' UTC' ) ) > $edit_window * MINUTE_IN_SECONDS ) {
				return new WP_Error(
					'edit_window_closed',
					__( 'The time window for editing this post has passed.', 'buddynext' ),
					array( 'status' => 403 )
				);
			}
		}

		// Re-scan edited content through the content safeguards (banned words +
		// blocked links + Pro keyword hooks). create() runs the full safeguard
		// suite; update() previously ran none, so an edit could slip banned words
		// or a blocked link past moderation. Only the content checks apply here —
		// rate-limit / duplicate / new-member gates are create-time concerns.
		if ( isset( $data['content'] ) || isset( $data['link_url'] ) ) {
			// Resolve the post's space so the per-space banned-word list is enforced
			// on edits too (get() is cache-backed, so this is cheap).
			$existing      = $this->get( $post_id );
			$edit_space_id = $existing ? (int) ( $existing['space_id'] ?? 0 ) : 0;

			$edit_scan = $this->get_safeguard()->check_content(
				(string) ( $data['content'] ?? '' ),
				(string) ( $data['link_url'] ?? '' ),
				$user_id,
				$edit_space_id
			);
			if ( is_wp_error( $edit_scan ) ) {
				return $edit_scan;
			}
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
			// Owners delete their own posts; site admins and space moderators
			// may remove another member's post as a moderation action. Any
			// other error (e.g. a missing post) is returned unchanged.
			if ( 'not_post_owner' !== $ownership->get_error_code() || ! $this->can_moderate_post( $post_id, $user_id ) ) {
				return $ownership;
			}
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

		// Cascade the remaining post references so no orphan rows survive a delete.
		$wpdb->delete( $wpdb->prefix . 'bn_post_hashtags', array( 'post_id' => $post_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'bn_feed_items', array( 'post_id' => $post_id ), array( '%d' ) );
		// Announcement dismissals live in user_meta (bn_dismissed_announcements),
		// not a table, so there is nothing to cascade here. A stale post ID left
		// in a user's dismissed-array is harmless — the post is gone and can
		// never render.
		$wpdb->delete(
			$wpdb->prefix . 'bn_notifications',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		$wpdb->delete(
			$wpdb->prefix . 'bn_reports',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);
		$wpdb->delete(
			$wpdb->prefix . 'bn_mod_log',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			),
			array( '%s', '%d' )
		);

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
	 * Whether a feed card already exists for a content type + external link.
	 *
	 * Lets integration bridges stay idempotent — publish one activity per
	 * external object even if the partner hook fires more than once.
	 *
	 * @param string $type     Post type marker (e.g. 'link').
	 * @param string $link_url Canonical link the card points at.
	 * @return bool
	 */
	public function exists_by_link( string $type, string $link_url ): bool {
		if ( '' === $type || '' === $link_url ) {
			return false;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_posts WHERE type = %s AND link_url = %s LIMIT 1",
				$type,
				$link_url
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $found > 0;
	}

	/**
	 * Delete feed cards matching a content type and external link.
	 *
	 * Used by integration bridges (e.g. Career Board) to remove the feed card
	 * for an external object — a job posting, listing, etc. — when that object
	 * is removed or expires upstream. Keeps raw `bn_posts` access inside the
	 * service layer so bridges (including Pro bridges) never query Free tables
	 * directly.
	 *
	 * @param string $type     Post type marker (e.g. 'job_post').
	 * @param string $link_url Canonical link the card points at.
	 * @return int Number of rows deleted.
	 */
	public function delete_by_link( string $type, string $link_url ): int {
		if ( '' === $type || '' === $link_url ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->delete(
			$wpdb->prefix . 'bn_posts',
			array(
				'type'     => $type,
				'link_url' => $link_url,
			),
			array( '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $deleted;
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
			// Single prepare per branch — the previous code prepared the space_id
			// clause and then interpolated that already-prepared string into a
			// second prepare(), a double-prepare pattern that can mangle values
			// and masks SQLi review. Thread $space_id straight into one prepare.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( null === $space_id ) {
				$pinned_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
						 WHERE user_id = %d AND is_pinned = 1 AND space_id IS NULL",
						$user_id
					)
				);
			} else {
				$pinned_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
						 WHERE user_id = %d AND is_pinned = 1 AND space_id = %d",
						$user_id,
						$space_id
					)
				);
			}
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

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
		// A new reaction/comment/share marks the post as freshly active — bump
		// last_activity_at so the "Active" feed surfaces it. Decrement does not
		// (removing engagement is not activity).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_posts SET {$column} = {$column} + 1, last_activity_at = %s WHERE id = %d",
				current_time( 'mysql', true ),
				$post_id
			)
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
	 * Reconcile reaction_count and comment_count on bn_posts from actual rows.
	 *
	 * Authoritative drift-correction shared by the daily cron (all posts) and by
	 * GDPR erasure (scoped to affected posts). Counters are maintained
	 * incrementally on every write, so this is a safety-net reconcile only.
	 *
	 * Uses LEFT JOIN + COALESCE so a post whose counter has drifted above zero
	 * but has zero actual reactions/comments is reset to 0 — an INNER JOIN can
	 * never reach those rows. The WHERE guard limits writes to genuinely drifted
	 * rows, keeping the pass cheap on large tables; when $post_ids is given the
	 * inner aggregates are scoped too so erasure never scans the whole table.
	 *
	 * @param int[] $post_ids Optional. Limit reconcile to these posts; empty = all.
	 * @return void
	 */
	public function recount_counters( array $post_ids = array() ): void {
		global $wpdb;

		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );

		$scope_outer = '';
		$scope_inner = '';
		if ( array() !== $post_ids ) {
			$ids_in      = implode( ',', $post_ids );
			$scope_outer = " AND p.id IN ({$ids_in})";
			$scope_inner = " AND object_id IN ({$ids_in})";
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// bn_reactions uses object_type + object_id (not post_id).
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_posts p
			 LEFT JOIN (
			     SELECT object_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_reactions
			      WHERE object_type = 'post'{$scope_inner}
			      GROUP BY object_id
			 ) r ON r.object_id = p.id
			 SET p.reaction_count = COALESCE(r.cnt, 0)
			 WHERE p.reaction_count <> COALESCE(r.cnt, 0){$scope_outer}"
		);

		// bn_comments uses object_type + object_id (not post_id).
		$wpdb->query(
			"UPDATE {$wpdb->prefix}bn_posts p
			 LEFT JOIN (
			     SELECT object_id, COUNT(*) AS cnt
			       FROM {$wpdb->prefix}bn_comments
			      WHERE is_deleted = 0
			        AND object_type = 'post'{$scope_inner}
			      GROUP BY object_id
			 ) c ON c.object_id = p.id
			 SET p.comment_count = COALESCE(c.cnt, 0)
			 WHERE p.comment_count <> COALESCE(c.cnt, 0){$scope_outer}"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( $post_ids as $pid ) {
			wp_cache_delete( "post_{$pid}", self::CACHE_GROUP );
		}
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
	 * Schedule a post: set status='scheduled' and the future scheduled_at.
	 *
	 * Owns the bn_posts status/scheduled_at write so the Pro scheduled-posts
	 * feature does not reach into Free's table directly. Callers do their own
	 * ownership/validation checks first.
	 *
	 * @param int    $post_id      Post id.
	 * @param string $scheduled_at UTC datetime (Y-m-d H:i:s).
	 * @return bool
	 */
	public function set_schedule( int $post_id, string $scheduled_at ): bool {
		if ( $post_id <= 0 || '' === $scheduled_at ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'status'       => 'scheduled',
				'scheduled_at' => $scheduled_at,
			),
			array( 'id' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		// Re-point the on-demand publisher at the (possibly new) earliest due post.
		ScheduledPostsPublisher::arm();

		return false !== $updated;
	}

	/**
	 * Cancel a schedule: revert status to 'draft' and clear scheduled_at.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function clear_schedule( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'status'       => 'draft',
				'scheduled_at' => null,
			),
			array( 'id' => $post_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		// One fewer scheduled post — re-arm (or disarm when none remain).
		ScheduledPostsPublisher::arm();

		return false !== $updated;
	}

	/**
	 * Publish a (scheduled) post: set status='published'.
	 *
	 * Bumps created_at + last_activity_at to the publish time (UTC) so a post
	 * that was scheduled days ago surfaces fresh in the feed when it goes live
	 * rather than at its original, now-buried position.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function mark_published( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql', true );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'status'           => 'published',
				'created_at'       => $now,
				'last_activity_at' => $now,
			),
			array( 'id' => $post_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		return false !== $updated;
	}

	/**
	 * Approve a held ('pending') post: publish it now and run the live
	 * side-effects (feed fan-out, notifications, indexing, mentions) that were
	 * deferred at creation. The post is timestamped to the approval moment so it
	 * surfaces as fresh content rather than being buried at its authored time.
	 *
	 * @param int $post_id Post id.
	 * @return bool True when a pending post was approved.
	 */
	public function approve_pending( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, space_id, type, content, link_url, status FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || 'pending' !== ( $row['status'] ?? '' ) ) {
			return false;
		}

		$now     = current_time( 'mysql', true );
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'status'           => 'published',
				'created_at'       => $now,
				'last_activity_at' => $now,
			),
			array( 'id' => $post_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $updated ) {
			return false;
		}

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		$author = (int) $row['user_id'];
		$type   = (string) $row['type'];
		$this->run_post_published_effects(
			$post_id,
			$author,
			$type,
			array(
				'content'  => (string) ( $row['content'] ?? '' ),
				'space_id' => (int) ( $row['space_id'] ?? 0 ),
				'link_url' => $row['link_url'] ?? null,
			)
		);

		/**
		 * Fires when a held post is approved by a moderator.
		 *
		 * @param int $post_id Post id.
		 * @param int $author  Author user id.
		 */
		do_action( 'buddynext_post_approved', $post_id, $author );

		return true;
	}

	/**
	 * Reject a held ('pending') post: soft-delete it (status='deleted') so it
	 * never goes live, and announce the decision for notification + audit.
	 *
	 * @param int    $post_id Post id.
	 * @param string $reason  Optional reason shown to the author.
	 * @return bool True when a pending post was rejected.
	 */
	public function reject_pending( int $post_id, string $reason = '' ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, status FROM {$wpdb->prefix}bn_posts WHERE id = %d LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || 'pending' !== ( $row['status'] ?? '' ) ) {
			return false;
		}

		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'status' => 'deleted' ),
			array( 'id' => $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $updated ) {
			return false;
		}

		wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );

		/**
		 * Fires when a held post is rejected by a moderator.
		 *
		 * @param int    $post_id Post id.
		 * @param int    $author  Author user id.
		 * @param string $reason  Optional reason.
		 */
		do_action( 'buddynext_post_rejected', $post_id, (int) $row['user_id'], $reason );

		return true;
	}

	/**
	 * Count posts awaiting pre-moderation approval, optionally scoped to a set
	 * of spaces (for space-scoped moderators). Uses COUNT(*) — never list-then-count.
	 *
	 * @param array<int,int> $space_ids Limit to these space ids (empty = all).
	 * @return int
	 */
	public function count_pending( array $space_ids = array() ): int {
		global $wpdb;
		$space_ids = array_values( array_filter( array_map( 'absint', $space_ids ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( empty( $space_ids ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE status = 'pending'" );
		}
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE status = 'pending' AND space_id IN ($placeholders)",
				$space_ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * List posts awaiting pre-moderation approval (oldest first), hydrated with
	 * author + space for the review queue. Paginated per the scale contract.
	 *
	 * @param int            $limit     Max rows (1-100).
	 * @param int            $offset    Offset for pagination.
	 * @param array<int,int> $space_ids Limit to these space ids (empty = all).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pending_for_review( int $limit = 50, int $offset = 0, array $space_ids = array() ): array {
		$limit     = max( 1, min( 100, $limit ) );
		$offset    = max( 0, $offset );
		$space_ids = array_values( array_filter( array_map( 'absint', $space_ids ) ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space_sql = '';
		$params    = array();
		if ( ! empty( $space_ids ) ) {
			$space_sql = ' AND p.space_id IN (' . implode( ',', array_fill( 0, count( $space_ids ), '%d' ) ) . ')';
			$params    = $space_ids;
		}
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.user_id, p.space_id, p.type, p.content, p.link_url, p.created_at,
				        u.display_name AS author_name, s.name AS space_name
				 FROM {$wpdb->prefix}bn_posts p
				 LEFT JOIN {$wpdb->users} u ON u.ID = p.user_id
				 LEFT JOIN {$wpdb->prefix}bn_spaces s ON s.id = p.space_id
				 WHERE p.status = 'pending'{$space_sql}
				 ORDER BY p.created_at ASC
				 LIMIT %d OFFSET %d",
				$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List a member's OWN posts that are held for pre-moderation approval, so the
	 * author can see what is waiting (the front-end "pending review" surface).
	 * Newest first.
	 *
	 * @param int $user_id Author user id.
	 * @param int $limit   Max rows (1-100).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_pending_by_author( int $user_id, int $limit = 50 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 100, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, space_id, type, content, link_url, created_at
				 FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d AND status = 'pending'
				 ORDER BY created_at DESC
				 LIMIT %d",
				$user_id,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * List posts in a given status, oldest scheduled first (capped per scale contract).
	 *
	 * @param string $status Post status (e.g. 'scheduled').
	 * @param int    $limit  Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_posts_by_status( string $status, int $limit = 50 ): array {
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return array();
		}
		$limit = max( 1, min( 100, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, space_id, type, content, privacy, status, scheduled_at, created_at
				 FROM {$wpdb->prefix}bn_posts
				 WHERE status = %s
				 ORDER BY scheduled_at ASC
				 LIMIT %d",
				$status,
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Fetch an active announcement post, or null if the id is not an announcement.
	 *
	 * @param int $post_id Post id.
	 * @return array<string,mixed>|null
	 */
	public function get_announcement( int $post_id ): ?array {
		if ( $post_id <= 0 ) {
			return null;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d AND is_announcement = 1 AND type = 'announcement' LIMIT 1",
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $row ?: null;
	}

	/**
	 * End an announcement by expiring its site pin now. Returns true if a row changed.
	 *
	 * @param int $post_id Post id.
	 * @return bool
	 */
	public function end_announcement( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// Clear is_announcement so the post stops rendering the announcement
		// banner/End button on its post card (post-card.php gates only on
		// is_announcement). site_pin_expires_at is also stamped so any expiry-
		// based reads settle immediately; the feed-prepend queries already honour
		// both. The post itself stays in the feed as a normal post.
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array(
				'is_announcement'     => 0,
				'site_pin_expires_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'id'              => $post_id,
				'is_announcement' => 1,
			),
			array( '%d', '%s' ),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $updated ) {
			wp_cache_delete( "post_{$post_id}", self::CACHE_GROUP );
		}

		return (bool) $updated;
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

		// The post owner may manage their own post; a site admin
		// (manage_options) may manage any member's post — same admin escape
		// hatch already used for announcement creation and the edit window.
		if ( (int) $post['user_id'] !== $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'not_post_owner',
				__( 'You are not the owner of this post.', 'buddynext' )
			);
		}

		return true;
	}

	/**
	 * Whether a user may moderate (remove) a post they do not own.
	 *
	 * Site admins can remove any post; a space owner or moderator can remove
	 * posts inside the space they moderate (resolved through PermissionService,
	 * which also honours moderator scoping). Global (non-space) posts are
	 * admin-only.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id Acting user ID.
	 * @return bool
	 */
	private function can_moderate_post( int $post_id, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$post     = $this->get( $post_id );
		$space_id = $post ? (int) ( $post['space_id'] ?? 0 ) : 0;

		if ( $space_id > 0 && function_exists( 'buddynext_service' ) ) {
			return (bool) buddynext_service( 'permissions' )->can(
				$user_id,
				'buddynext-moderate-space',
				array( 'space_id' => $space_id )
			);
		}

		return false;
	}

	/**
	 * Insert poll options for a new poll post.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string[] $options  Option texts (ordered as provided).
	 * @param string   $end_date Optional UTC "Y-m-d H:i:s" deadline; stored on
	 *                           every option row (poll-level value) so vote() and
	 *                           the renderer can read it without a separate table.
	 *                           Empty string = no deadline (open indefinitely).
	 */
	private function insert_poll_options( int $post_id, array $options, string $end_date = '' ): void {
		global $wpdb;

		// Accept only a valid "Y-m-d H:i:s" deadline; anything else stores NULL.
		$end_value = ( '' !== $end_date && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $end_date ) )
			? $end_date
			: null;

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
					'end_date'      => $end_value,
				),
				array( '%d', '%s', '%d', '%d', '%s' )
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
			'type'                 => $row['type'] ?? 'text',
			'content'              => $row['content'] ?? '',
			'media_ids'            => isset( $row['media_ids'] ) ? json_decode( (string) $row['media_ids'], true ) : null,
			'link_url'             => $row['link_url'] ?? null,
			'link_meta'            => isset( $row['link_meta'] ) ? json_decode( (string) $row['link_meta'], true ) : null,
			'privacy'              => $row['privacy'] ?? 'public',
			'reaction_count'       => (int) ( $row['reaction_count'] ?? 0 ),
			'comment_count'        => (int) ( $row['comment_count'] ?? 0 ),
			'share_count'          => (int) ( $row['share_count'] ?? 0 ),
			'is_pinned'            => (int) ( $row['is_pinned'] ?? 0 ),
			'is_announcement'      => (int) ( $row['is_announcement'] ?? 0 ),
			'content_warning'      => (bool) ( $row['content_warning'] ?? false ),
			'content_warning_type' => $row['content_warning_type'] ?? null,
			// Optional columns — defaulted so hydrate() tolerates partial rows
			// (feed/hashtag SELECTs that omit them) without an undefined-key notice.
			'site_pin_expires_at'  => $row['site_pin_expires_at'] ?? null,
			'edited_at'            => $row['edited_at'] ?? null,
			'scheduled_at'         => $row['scheduled_at'] ?? null,
			'created_at'           => $row['created_at'] ?? '',
			'updated_at'           => $row['updated_at'] ?? null,
		);

		if ( 'poll' === ( $row['type'] ?? '' ) ) {
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
				"SELECT id, option_text, display_order, vote_count, end_date
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
				'end_date'      => $r['end_date'] ?? null,
			),
			(array) $rows
		);
	}

	/**
	 * Public accessor for Open Graph metadata extraction.
	 *
	 * Wraps the private fetch_og_meta() so the REST controller can build a live
	 * link-preview card while the user is composing — without exposing the
	 * private static directly. Returns the same shape (title/description/thumbnail),
	 * with empty strings when the URL is unreachable or carries no OG tags.
	 *
	 * @param string $url URL to fetch.
	 * @return array{title: string, description: string, thumbnail: string}
	 */
	public function og_meta( string $url ): array {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return array(
				'title'       => '',
				'description' => '',
				'thumbnail'   => '',
			);
		}

		return self::fetch_og_meta( $url );
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

		// SSRF guard. The URL is user-supplied (post content / link meta), so we
		// must not let it point the server at internal hosts. url_is_safe_for_fetch()
		// rejects non-http(s) schemes and any host that resolves into a private or
		// reserved range — including link-local 169.254.0.0/16, which covers cloud
		// metadata endpoints (e.g. 169.254.169.254) that wp_http_validate_url() does
		// NOT block on its own.
		if ( ! self::url_is_safe_for_fetch( $url ) ) {
			return $meta;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'user-agent'          => 'BuddyNext/1.0 (Link Preview)',
				'reject_unsafe_urls'  => true,
				'limit_response_size' => 2 * MB_IN_BYTES,
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $meta;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return $meta;
		}

		// Parse with DOMDocument/DOMXPath so extraction is robust to attribute
		// order, quote style, self-closing slashes, and extra attributes — the
		// hand-rolled regex only matched two of the many valid orderings.
		$xpath = self::html_xpath( $body );
		if ( null === $xpath ) {
			return $meta;
		}

		$title = self::xpath_first( $xpath, '//meta[@property="og:title"]/@content' );
		if ( '' === $title ) {
			$node  = $xpath->query( '//title' )->item( 0 );
			$title = $node ? $node->textContent : '';
		}
		$meta['title'] = html_entity_decode( trim( $title ), ENT_QUOTES, 'UTF-8' );

		$description = self::xpath_first( $xpath, '//meta[@property="og:description"]/@content' );
		if ( '' === $description ) {
			$description = self::xpath_first( $xpath, '//meta[@name="description"]/@content' );
		}
		$meta['description'] = html_entity_decode( trim( $description ), ENT_QUOTES, 'UTF-8' );

		$image             = self::xpath_first( $xpath, '//meta[@property="og:image"]/@content' );
		$meta['thumbnail'] = esc_url_raw( trim( $image ) );

		return $meta;
	}

	/**
	 * Decide whether a user-supplied URL is safe for the server to fetch.
	 *
	 * Blocks SSRF by requiring an http/https scheme and resolving the host to
	 * its IP(s), rejecting any that fall in a private (RFC1918) or reserved
	 * range. FILTER_FLAG_NO_RES_RANGE covers loopback (127/8, ::1), link-local
	 * (169.254/16 — cloud metadata), and other reserved blocks; NO_PRIV_RANGE
	 * covers 10/8, 172.16/12, 192.168/16 and fc00::/7. Unresolvable hosts are
	 * rejected too. (TOCTOU/DNS-rebinding is mitigated further by passing
	 * reject_unsafe_urls to wp_remote_get.)
	 *
	 * @param string $url URL to validate.
	 * @return bool True when the URL is a public http(s) destination.
	 */
	private static function url_is_safe_for_fetch( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return false;
		}
		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$host = $parts['host'];
		$ips  = array();

		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				}
				if ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
			if ( empty( $ips ) ) {
				$resolved = gethostbyname( $host );
				if ( $resolved && $resolved !== $host ) {
					$ips[] = $resolved;
				}
			}
		}

		if ( empty( $ips ) ) {
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build a DOMXPath over an HTML body, suppressing libxml parse warnings.
	 *
	 * @param string $body Raw HTML.
	 * @return \DOMXPath|null Null when DOM is unavailable or the body won't parse.
	 */
	private static function html_xpath( string $body ): ?\DOMXPath {
		if ( ! class_exists( '\DOMDocument' ) ) {
			return null;
		}
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		// The XML encoding hint forces UTF-8 so multibyte content is not mangled.
		$loaded = $dom->loadHTML( '<?xml encoding="UTF-8">' . $body, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		return $loaded ? new \DOMXPath( $dom ) : null;
	}

	/**
	 * Return the value of the first node matching an XPath query, or ''.
	 *
	 * @param \DOMXPath $xpath Document XPath.
	 * @param string    $query XPath expression.
	 * @return string
	 */
	private static function xpath_first( \DOMXPath $xpath, string $query ): string {
		$nodes = $xpath->query( $query );
		if ( false === $nodes || 0 === $nodes->length ) {
			return '';
		}
		return (string) $nodes->item( 0 )->nodeValue;
	}
}
