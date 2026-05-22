<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Feed aggregation and pagination service.
 *
 * Builds the home, profile, explore, and space feeds using cursor-based pagination.
 * The cursor encodes the created_at datetime and post id of the last seen item
 * so that new posts inserted between pages do not cause duplicates or gaps.
 *
 * Home-feed sources:
 *   - The viewer's own posts (any privacy) are always included.
 *   - Public or followers posts from followed users are shown.
 *   - Posts from spaces the viewer has joined (status = 'active' in bn_space_members).
 *   - Posts from posts that contain a hashtag the viewer follows (bn_hashtag_follows).
 *   - Scheduled posts (scheduled_at in the future) are excluded.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;

/**
 * Aggregates posts into paginated feed responses.
 */
class FeedService {

	/**
	 * Default posts per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * Follow graph service — used to resolve the home-feed author list.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * Post service — used to hydrate raw database rows.
	 *
	 * @var PostService
	 */
	private PostService $post_service;

	/**
	 * Optional cache layer for first-page reads.
	 *
	 * Per docs/specs/SCALE-CONTRACT.md the home feed page 1 is the
	 * single highest-traffic query in the plugin. Cache wraps it via
	 * FeedCache. Null when the feature is disabled — service falls
	 * through to direct queries.
	 *
	 * @var FeedCache|null
	 */
	private ?FeedCache $cache;

	/**
	 * Inject dependencies.
	 *
	 * @param FollowService  $follows      Follow service instance.
	 * @param PostService    $post_service Post service instance.
	 * @param FeedCache|null $cache        Optional cache layer.
	 */
	public function __construct( FollowService $follows, PostService $post_service, ?FeedCache $cache = null ) {
		$this->follows      = $follows;
		$this->post_service = $post_service;
		$this->cache        = $cache;
	}

	/**
	 * Build a SQL fragment that excludes suspended and shadow-banned users.
	 *
	 * The fragment is always prefixed with AND so it can be appended directly
	 * to an existing WHERE clause. It uses two NOT IN subqueries:
	 *  1. Active suspension rows in bn_user_suspensions.
	 *  2. Users whose bn_shadow_banned usermeta = '1'.
	 *
	 * @return string Raw SQL fragment — no user-supplied data, safe to embed.
	 */
	private function excluded_users_where(): string {
		global $wpdb;
		return "AND user_id NOT IN (
			    SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			    WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())
			  )
			  AND user_id NOT IN (
			    SELECT user_id FROM {$wpdb->usermeta}
			    WHERE meta_key = 'bn_shadow_banned' AND meta_value = '1'
			  )";
	}

	/**
	 * Allowed home-feed filter slugs.
	 *
	 * @var string[]
	 */
	public const HOME_FILTERS = array( 'for-you', 'following', 'spaces', 'network' );

	/**
	 * Return the home feed for the given user.
	 *
	 * @param int         $user_id  Viewing user ID.
	 * @param string|null $cursor   Opaque pagination cursor from a previous response.
	 * @param int         $per_page Number of posts to return (max 50).
	 * @param string      $filter   Filter slug: for-you | following | spaces | network.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function home_feed( int $user_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, string $filter = 'for-you' ): array {
		if ( ! in_array( $filter, self::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}
		// Page-1 cache wrap. Only first-page reads are cached (cursor is null);
		// subsequent pages bypass since the cursor encodes a unique position.
		if ( null !== $this->cache && null === $cursor && $user_id > 0 && 'for-you' === $filter ) {
			$key   = $this->cache->home_page_1_key( $user_id, $per_page );
			$cache = $this->cache;
			return (array) $cache->get(
				$key,
				FeedCache::GROUP_USER,
				FeedCache::TTL_HOME_PAGE_1,
				fn() => $this->home_feed_uncached( $user_id, null, $per_page, 'for-you' )
			);
		}
		return $this->home_feed_uncached( $user_id, $cursor, $per_page, $filter );
	}

	/**
	 * Uncached home feed query — internal callee of home_feed().
	 *
	 * @param int         $user_id  Viewing user ID.
	 * @param string|null $cursor   Pagination cursor.
	 * @param int         $per_page Page size.
	 * @param string      $filter   Filter slug: for-you | following | spaces | network.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	private function home_feed_uncached( int $user_id, ?string $cursor, int $per_page, string $filter = 'for-you' ): array {
		global $wpdb;

		if ( ! in_array( $filter, self::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$per_page       = min( $per_page, 50 );
		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();

		/**
		 * Filter the query args before SQL is built for the home feed.
		 *
		 * Use this filter to modify pagination or inject scope-specific IDs before
		 * the database query executes. Pro can use it for tier-based filtering.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args      Query args: per_page, cursor, user_id.
		 * @param string $scope     Feed scope — always 'home' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 */
		$query_args = apply_filters(
			'buddynext_feed_query_args',
			array(
				'per_page' => $per_page,
				'cursor'   => $cursor,
				'user_id'  => $user_id,
			),
			'home',
			$user_id
		);

		$per_page = (int) ( $query_args['per_page'] ?? $per_page );
		$per_page = min( $per_page, 50 );

		/**
		 * Filter the ORDER BY clause used by the home feed SQL.
		 *
		 * Allows Pro to swap the chronological ORDER BY for an affinity-weighted
		 * ordering (AI Feed ranking). The returned fragment is embedded directly
		 * into the SQL — it MUST contain only safe column references and
		 * direction keywords, never user data.
		 *
		 * @since 1.1.0
		 *
		 * @param string $order_by   Default ORDER BY clause (without the keyword).
		 * @param int    $user_id    Viewing user ID.
		 * @param array  $query_args Resolved query args after buddynext_feed_query_args.
		 */
		$order_by = (string) apply_filters( 'buddynext_feed_order_by', 'created_at DESC, id DESC', $user_id, $query_args );
		if ( '' === $order_by ) {
			$order_by = 'created_at DESC, id DESC';
		}

		// Source-blend WHERE built per filter. All branches use subqueries — no
		// PHP-side ID arrays, no interpolation. $cursor_where, $excluded_where,
		// $source_where contain only hardcoded SQL with %d placeholders — safe.
		// $order_by is filter-supplied; callers are contractually required to
		// return only hardcoded SQL column references + direction keywords.
		[ $source_where, $source_params ] = $this->home_source_clause( $filter, $user_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE status = 'published'
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   AND ({$source_where})
			   {$excluded_where}
			   {$cursor_where}
			 ORDER BY {$order_by}
			 LIMIT %d",
			...array_merge( $source_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// $sql was fully prepared by $wpdb->prepare() in the block above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->paginate( (array) $rows, $per_page );

		// Prepend the active site-wide announcement on the first page only.
		if ( null === $cursor ) {
			$announcement = $this->active_announcement( $user_id );
			if ( null !== $announcement ) {
				array_unshift( $result['items'], $announcement );
			}
		}

		/**
		 * Fire an impression event for each post shown in the home feed.
		 *
		 * Only fires when the viewer is a logged-in user (viewer_id > 0).
		 * Use: Pro post-reach analytics.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id   Post ID.
		 * @param int    $viewer_id Viewing user ID.
		 * @param string $surface   Feed surface — always 'home_feed' here.
		 */
		if ( $user_id > 0 ) {
			foreach ( $result['items'] as $item ) {
				do_action( 'buddynext_post_impression', (int) $item['id'], $user_id, 'home_feed' );
			}
		}

		/**
		 * Filter the home feed items immediately before they are returned.
		 *
		 * Allows Pro to rerank, inject sponsored posts, or remove items.
		 * The default value preserves the existing SQL-ordered result set.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $items     Paginated item array (hydrated post arrays).
		 * @param string $scope     Feed scope — always 'home' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 * @param array  $args      Original query args passed to home_feed().
		 */
		$result['items'] = apply_filters(
			'buddynext_feed_items',
			$result['items'],
			'home',
			$user_id,
			array(
				'per_page' => $per_page,
				'cursor'   => $cursor,
				'user_id'  => $user_id,
				'filter'   => $filter,
			)
		);

		return $result;
	}

	/**
	 * Build the source-blend WHERE clause + bound params for a home-feed filter.
	 *
	 * Returns a pair of [SQL fragment with %d placeholders, ordered params].
	 * SQL fragments only contain hardcoded table/column names — never user data.
	 *
	 * @param string $filter  One of for-you | following | spaces | network.
	 * @param int    $user_id Viewer user ID.
	 * @return array{0:string,1:array<int>} SQL fragment + ordered params.
	 */
	private function home_source_clause( string $filter, int $user_id ): array {
		global $wpdb;

		switch ( $filter ) {
			case 'following':
				$sql    = "user_id IN (
					SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d
				) AND privacy IN ('public','followers')";
				$params = array( $user_id );
				break;

			case 'spaces':
				$sql    = "space_id IN (
					SELECT space_id FROM {$wpdb->prefix}bn_space_members
					WHERE user_id = %d AND status = 'active'
				)";
				$params = array( $user_id );
				break;

			case 'network':
				$sql    = "user_id IN (
					SELECT CASE
					    WHEN requester_id = %d THEN recipient_id
					    ELSE requester_id
					 END
					 FROM {$wpdb->prefix}bn_connections
					 WHERE ( requester_id = %d OR recipient_id = %d )
					   AND status = 'accepted'
				) AND privacy IN ('public','followers','connections')";
				$params = array( $user_id, $user_id, $user_id );
				break;

			case 'for-you':
			default:
				$sql    = "(
					user_id IN (
						SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d
					)
					AND privacy IN ('public','followers')
				)
				OR user_id = %d
				OR space_id IN (
					SELECT space_id FROM {$wpdb->prefix}bn_space_members
					WHERE user_id = %d AND status = 'active'
				)
				OR id IN (
					SELECT ph.post_id FROM {$wpdb->prefix}bn_post_hashtags ph
					WHERE ph.object_type = 'post'
					  AND ph.hashtag_id IN (
						SELECT hf.hashtag_id FROM {$wpdb->prefix}bn_hashtag_follows hf
						WHERE hf.user_id = %d
					)
				)";
				$params = array( $user_id, $user_id, $user_id, $user_id );
				break;
		}

		return array( $sql, $params );
	}

	/**
	 * Return per-tab post counts for the home-feed filter strip.
	 *
	 * Numbers are clamped to a 24-hour window so the badge reflects "new" rather
	 * than total backlog. Each filter reuses home_source_clause() so totals stay
	 * consistent with what each tab actually renders.
	 *
	 * @param int $user_id Viewer user ID.
	 * @return array{for_you:int,following:int,spaces:int,network:int}
	 */
	public function home_feed_counts( int $user_id ): array {
		global $wpdb;

		$counts = array(
			'for_you'   => 0,
			'following' => 0,
			'spaces'    => 0,
			'network'   => 0,
		);

		if ( $user_id <= 0 ) {
			return $counts;
		}

		$excluded_where = $this->excluded_users_where();
		$map            = array(
			'for_you'   => 'for-you',
			'following' => 'following',
			'spaces'    => 'spaces',
			'network'   => 'network',
		);

		foreach ( $map as $key => $filter ) {
			[ $source_where, $source_params ] = $this->home_source_clause( $filter, $user_id );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
					 WHERE status = 'published'
					   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
					   AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
					   AND ({$source_where})
					   {$excluded_where}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...$source_params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

			$counts[ $key ] = $count;
		}

		return $counts;
	}

	/**
	 * Return the active site-wide announcement for a user, or null.
	 *
	 * Returns null when:
	 *  - No published announcement exists.
	 *  - The announcement has expired (site_pin_expires_at < NOW()).
	 *  - The user has already dismissed it (row in bn_announcement_dismissals).
	 *
	 * @param int $user_id Viewing user ID.
	 * @return array|null Hydrated post array or null.
	 */
	public function active_announcement( int $user_id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.* FROM {$wpdb->prefix}bn_posts p
				 WHERE p.is_announcement = 1
				   AND p.type = 'announcement'
				   AND p.status = 'published'
				   AND (p.site_pin_expires_at IS NULL OR p.site_pin_expires_at > NOW())
				   AND NOT EXISTS (
				         SELECT 1 FROM {$wpdb->prefix}bn_announcement_dismissals d
				         WHERE d.post_id = p.id AND d.user_id = %d
				       )
				 ORDER BY p.created_at DESC
				 LIMIT 1",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( null === $row ) {
			return null;
		}

		return $this->post_service->hydrate( $row );
	}

	/**
	 * Return the profile feed for a given user as seen by a viewer.
	 *
	 * Suspended and shadow-banned users' posts are hidden from all viewers,
	 * including the profile owner themselves, so that moderation is transparent
	 * at the data layer rather than relying on template-level checks.
	 *
	 * @param int         $profile_user_id  User whose posts to show.
	 * @param int         $viewer_id        Viewing user ID (0 = anonymous).
	 * @param string|null $cursor           Pagination cursor.
	 * @param int         $per_page         Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function profile_feed( int $profile_user_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page = min( $per_page, 50 );

		/**
		 * Filter the query args before SQL is built for the profile feed.
		 *
		 * Use this filter to modify pagination or inject scope-specific IDs before
		 * the database query executes. Pro can use it for tier-based filtering.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args      Query args: per_page, cursor, profile_user_id.
		 * @param string $scope     Feed scope — always 'profile' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 */
		$query_args = apply_filters(
			'buddynext_feed_query_args',
			array(
				'per_page'        => $per_page,
				'cursor'          => $cursor,
				'profile_user_id' => $profile_user_id,
			),
			'profile',
			$viewer_id
		);

		$per_page = min( (int) ( $query_args['per_page'] ?? $per_page ), 50 );

		if ( $viewer_id === $profile_user_id ) {
			// Owner sees everything — but suspended/shadow-banned posts are still hidden.
			$privacy_clause = '';
			$privacy_params = array();
		} elseif ( $viewer_id > 0 && $this->follows->is_following( $viewer_id, $profile_user_id ) ) {
			// Followers see public and followers-only posts.
			$privacy_clause = "AND privacy IN ('public','followers')";
			$privacy_params = array();
		} else {
			// Anonymous visitors and non-followers see only public posts.
			$privacy_clause = "AND privacy = 'public'";
			$privacy_params = array();
		}

		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();

		// $privacy_clause is a hardcoded SQL constant — safe.
		// $cursor_where is either '' or the single hardcoded SQL constant — safe.
		// $excluded_where contains only table/column names — no user data, safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE user_id = %d
			   {$privacy_clause}
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$excluded_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( array( $profile_user_id ), $privacy_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// $sql was fully prepared by $wpdb->prepare() in the block above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->paginate( (array) $rows, $per_page );

		/**
		 * Fire an impression event for each post shown in the profile feed.
		 *
		 * Only fires when the viewer is a logged-in user (viewer_id > 0).
		 * Use: Pro post-reach analytics.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id   Post ID.
		 * @param int    $viewer_id Viewing user ID.
		 * @param string $surface   Feed surface — always 'profile_feed' here.
		 */
		if ( $viewer_id > 0 ) {
			foreach ( $result['items'] as $item ) {
				do_action( 'buddynext_post_impression', (int) $item['id'], $viewer_id, 'profile_feed' );
			}
		}

		/**
		 * Filter the profile feed items immediately before they are returned.
		 *
		 * Allows Pro to rerank or remove items. The default value preserves the
		 * existing SQL-ordered result set.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $items     Paginated item array (hydrated post arrays).
		 * @param string $scope     Feed scope — always 'profile' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 * @param array  $args      Original query args passed to profile_feed().
		 */
		$result['items'] = apply_filters(
			'buddynext_feed_items',
			$result['items'],
			'profile',
			$viewer_id,
			array(
				'per_page'        => $per_page,
				'cursor'          => $cursor,
				'profile_user_id' => $profile_user_id,
			)
		);

		return $result;
	}

	/**
	 * Return the feed for a given space (published, non-scheduled posts only).
	 *
	 * Access control is the caller's responsibility — this method returns all
	 * published posts in the space without additional viewer-side filtering.
	 * Posts authored by suspended or shadow-banned users are always excluded.
	 *
	 * @param int         $space_id  Space whose posts to show.
	 * @param int         $viewer_id Viewing user ID (reserved for future access checks).
	 * @param string|null $cursor    Pagination cursor.
	 * @param int         $per_page  Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function space_feed( int $space_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page       = min( $per_page, 50 );
		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();

		/**
		 * Filter the query args before SQL is built for the space feed.
		 *
		 * Use this filter to modify pagination or inject scope-specific IDs before
		 * the database query executes. Pro can use it for tier-based filtering.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $args      Query args: per_page, cursor, space_id.
		 * @param string $scope     Feed scope — always 'space' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 */
		$query_args = apply_filters(
			'buddynext_feed_query_args',
			array(
				'per_page' => $per_page,
				'cursor'   => $cursor,
				'space_id' => $space_id,
			),
			'space',
			$viewer_id
		);

		$per_page = min( (int) ( $query_args['per_page'] ?? $per_page ), 50 );

		// $cursor_where and $excluded_where contain only table/column names — no user data, safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE space_id = %d
			   AND status = 'published'
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$excluded_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( array( $space_id ), $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// $sql was fully prepared by $wpdb->prepare() in the block above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->paginate( (array) $rows, $per_page );

		/**
		 * Fire an impression event for each post shown in the space feed.
		 *
		 * Only fires when the viewer is a logged-in user (viewer_id > 0).
		 * Use: Pro post-reach analytics.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id   Post ID.
		 * @param int    $viewer_id Viewing user ID.
		 * @param string $surface   Feed surface — always 'space_feed' here.
		 */
		if ( $viewer_id > 0 ) {
			foreach ( $result['items'] as $item ) {
				do_action( 'buddynext_post_impression', (int) $item['id'], $viewer_id, 'space_feed' );
			}
		}

		/**
		 * Filter the space feed items immediately before they are returned.
		 *
		 * Allows Pro to rerank or remove items. The default value preserves the
		 * existing SQL-ordered result set.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $items     Paginated item array (hydrated post arrays).
		 * @param string $scope     Feed scope — always 'space' for this method.
		 * @param int    $viewer_id Viewing user ID.
		 * @param array  $args      Original query args passed to space_feed().
		 */
		$result['items'] = apply_filters(
			'buddynext_feed_items',
			$result['items'],
			'space',
			$viewer_id,
			array(
				'per_page' => $per_page,
				'cursor'   => $cursor,
				'space_id' => $space_id,
			)
		);

		return $result;
	}

	/**
	 * Return the public explore feed (all public posts, newest first).
	 *
	 * @param string|null $cursor   Pagination cursor.
	 * @param int         $per_page Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function explore_feed( ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page       = min( $per_page, 50 );
		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();

		// $cursor_where and $excluded_where contain only table/column names — no user data, safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE privacy = 'public'
			   AND (scheduled_at IS NULL OR scheduled_at <= NOW())
			   {$excluded_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// $sql was fully prepared by $wpdb->prepare() in the block above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$result    = $this->paginate( (array) $rows, $per_page );
		$viewer_id = get_current_user_id();

		/**
		 * Fire an impression event for each post shown in the explore feed.
		 *
		 * Only fires when the viewer is a logged-in user (viewer_id > 0).
		 * Use: Pro post-reach analytics.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $post_id   Post ID.
		 * @param int    $viewer_id Viewing user ID.
		 * @param string $surface   Feed surface — always 'explore_feed' here.
		 */
		if ( $viewer_id > 0 ) {
			foreach ( $result['items'] as $item ) {
				do_action( 'buddynext_post_impression', (int) $item['id'], $viewer_id, 'explore_feed' );
			}
		}

		return $result;
	}

	/**
	 * Encode a cursor from the last item in a page.
	 *
	 * Cursor format: base64( "{created_at}|{id}" ).
	 *
	 * @param array $row A hydrated post row.
	 * @return string Opaque cursor string.
	 */
	public function encode_cursor( array $row ): string {
		return base64_encode( $row['created_at'] . '|' . $row['id'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a cursor into its component parts.
	 *
	 * @param string $cursor Opaque cursor.
	 * @return array{created_at: string, id: int}|null Null if cursor is invalid.
	 */
	private function decode_cursor( string $cursor ): ?array {
		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		return array(
			'created_at' => $parts[0],
			'id'         => (int) $parts[1],
		);
	}

	/**
	 * Build the WHERE fragment for cursor-based pagination.
	 *
	 * Returns an empty string when no cursor is given (first page).
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return string SQL fragment (already safe to embed — placeholders handled separately).
	 */
	private function cursor_where( ?string $cursor ): string {
		if ( null === $cursor ) {
			return '';
		}

		$decoded = $this->decode_cursor( $cursor );
		if ( null === $decoded ) {
			return '';
		}

		return 'AND (created_at < %s OR (created_at = %s AND id < %d))';
	}

	/**
	 * Return the ordered parameter values for cursor_where placeholders.
	 *
	 * @param string|null $cursor Opaque cursor or null.
	 * @return array
	 */
	private function cursor_params( ?string $cursor ): array {
		if ( null === $cursor ) {
			return array();
		}

		$decoded = $this->decode_cursor( $cursor );
		if ( null === $decoded ) {
			return array();
		}

		return array( $decoded['created_at'], $decoded['created_at'], $decoded['id'] );
	}

	/**
	 * Slice the result set and build a paginated response.
	 *
	 * Fetches $per_page + 1 rows to detect whether a next page exists, then
	 * trims to $per_page and encodes the cursor from the last item.
	 *
	 * @param array $rows     Raw rows from wpdb (ARRAY_A).
	 * @param int   $per_page Page size.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	private function paginate( array $rows, int $per_page ): array {
		$has_more = count( $rows ) > $per_page;

		if ( $has_more ) {
			$rows = array_slice( $rows, 0, $per_page );
		}

		$items = array_map(
			fn( $row ) => $this->post_service->hydrate( $row ),
			$rows
		);

		$next_cursor = null;
		if ( $has_more && ! empty( $rows ) ) {
			$last        = end( $rows );
			$next_cursor = $this->encode_cursor( $last );
		}

		return array(
			'items'       => $items,
			'next_cursor' => $next_cursor,
		);
	}
}
