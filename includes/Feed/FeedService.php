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
use BuddyNext\Core\CursorCodec;

/**
 * Aggregates posts into paginated feed responses.
 */
class FeedService {

	/**
	 * Default posts per page.
	 */
	private const DEFAULT_LIMIT = 20;

	/**
	 * user_meta key storing the list of announcement post IDs the user has
	 * dismissed. Value is a flat array of integer post IDs.
	 */
	public const DISMISSED_ANNOUNCEMENTS_META = 'bn_dismissed_announcements';

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
	 *  1. Active suspension rows in bn_user_suspensions (hide_posts = 1).
	 *  2. Users whose bn_shadow_banned usermeta = '1'.
	 *
	 * Moderators (manage_options) get no exclusion — they must see suspended and
	 * shadow-banned authors' posts in the feed to review them; hiding that
	 * content from a moderator defeats moderation.
	 *
	 * @return string Raw SQL fragment — no user-supplied data, safe to embed.
	 */
	private function excluded_users_where(): string {
		if ( current_user_can( 'manage_options' ) ) {
			return '';
		}
		// Delegate to the one canonical moderation-exclusion builder so the feed
		// and follow suggestions exclude the same suspended/shadow-banned set.
		return buddynext_service( 'moderation' )->moderation_exclude_sql( 'user_id' );
	}

	/**
	 * Build a viewer-scoped SQL fragment excluding blocked + muted authors.
	 *
	 * Per docs/specs/features/01-social-graph.md the home and explore feeds must
	 * suppress posts from authors the viewer has a block or mute relationship with:
	 *  - Block: bidirectional hard stop — exclude authors the viewer blocked AND
	 *    authors who blocked the viewer (mirrors MemberDirectoryService bidirectional
	 *    block exclusion).
	 *  - Mute:  one-directional soft hide — exclude only authors the viewer muted;
	 *    the muted user is unaffected and never told.
	 *
	 * Returns a [SQL, params] pair. The fragment is prefixed with AND so it can be
	 * appended directly to an existing WHERE clause, and uses a single NOT IN
	 * subquery against bn_blocks (no PHP-side ID array, no N+1). Returns an empty
	 * fragment for logged-out viewers (no relationship to resolve) and when the
	 * bn_blocks table is absent, so the feed degrades gracefully.
	 *
	 * @param int $viewer_id Viewing user ID (0 = anonymous).
	 * @return array{0:string,1:array<int>} SQL fragment + ordered params.
	 */
	private function viewer_block_mute_where( int $viewer_id ): array {
		// Delegate to the one canonical block-exclusion builder. Feed semantics:
		// exclude authors the viewer block|muted (forward) and authors who
		// blocked the viewer (reverse). Mute is a feed-only soft hide, so it
		// appears forward here but on no other surface.
		[ $predicate, $params ] = buddynext_service( 'privacy' )->block_exclude_sql(
			$viewer_id,
			'user_id',
			array( 'block', 'mute' ),
			array( 'block' )
		);

		return array( '' === $predicate ? '' : 'AND ' . $predicate, $params );
	}

	/**
	 * Up to $cap of the viewer's accepted-connection user IDs, for connections-
	 * first feed weighting (the spec's free "connections-first" ordering).
	 *
	 * Deliberately bounded: one indexed query capped at $cap, so the home-feed
	 * ORDER BY never builds an unbounded IN-list. Beyond the cap the weighting is
	 * simply incomplete (those connections rank chronologically) — the feed never
	 * does per-row work. Returns ints only, safe to embed in SQL.
	 *
	 * @param int $user_id Viewer ID.
	 * @param int $cap     Maximum IDs to return.
	 * @return int[]
	 */
	private function connection_ids_capped( int $user_id, int $cap = 500 ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END
				 FROM {$wpdb->prefix}bn_connections
				 WHERE status = 'accepted' AND ( requester_id = %d OR recipient_id = %d )
				 LIMIT %d",
				$user_id,
				$user_id,
				$user_id,
				$cap
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
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

		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $user_id );

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
		// Connections-first weighting on the blended "For you" feed (the free
		// ordering the spec calls for): rank posts from the viewer's connections
		// above the rest, then chronological. IDs are capped + absint'd, so the
		// CASE stays index-safe and carries no user data. Other filters
		// (following / spaces / network) keep the plain chronological order.
		$default_order_by = 'is_pinned DESC, created_at DESC, id DESC';
		if ( 'for-you' === $filter && $user_id > 0 ) {
			$bn_conn_ids = $this->connection_ids_capped( $user_id );
			if ( ! empty( $bn_conn_ids ) ) {
				$default_order_by = 'is_pinned DESC, CASE WHEN user_id IN ('
					. implode( ',', $bn_conn_ids )
					. ') THEN 0 ELSE 1 END ASC, created_at DESC, id DESC';
			}
		}

		$order_by = (string) apply_filters( 'buddynext_feed_order_by', $default_order_by, $user_id, $query_args );
		if ( '' === $order_by ) {
			$order_by = $default_order_by;
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
			   AND type <> 'announcement'
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
			   AND ({$source_where})
			   {$excluded_where}
			   {$block_mute_where}
			   {$cursor_where}
			 ORDER BY {$order_by}
			 LIMIT %d",
			...array_merge( $source_params, $block_mute_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
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
				// "For You" is the blended discovery feed (vs. the strict "Following"
				// tab): the viewer's follows, own posts, joined spaces and followed
				// hashtags, PLUS public community activity so the feed isn't empty for
				// users who follow no one. The public catch-all is scoped to non-space
				// posts and posts in open spaces only — private/secret space posts are
				// reached solely through the joined-spaces branch, never leaked here.
				// Block/mute/excluded filtering is applied by the caller on top.
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
				)
				OR (
					privacy = 'public'
					AND (
						space_id IS NULL
						OR space_id = 0
						OR space_id IN (
							SELECT id FROM {$wpdb->prefix}bn_spaces WHERE type = 'open'
						)
					)
				)";
				$params = array( $user_id, $user_id, $user_id, $user_id );
				break;
		}

		// Overarching privacy guard. A 'private' ("Only Me") post is visible only
		// to its author. Branches like joined-spaces and followed-hashtags match
		// posts with no per-branch privacy filter, so without this an Only-Me post
		// would leak into other members' feeds through a shared space or a hashtag
		// they both follow. Scoped to 'private' only — it must not tighten the
		// public/followers/connections visibility the other branches already set,
		// and the author still sees their own Only-Me posts (user_id = %d).
		$sql      = "( {$sql} ) AND ( privacy <> 'private' OR user_id = %d )";
		$params[] = $user_id;

		// Honour each space's "Push space posts to activity feed" toggle: a space
		// the owner opted out of (bn_space_{id}_push_to_feed = 0) must not surface
		// in the home feed through ANY branch. Non-space posts are unaffected.
		$excluded_spaces = $this->feed_excluded_space_ids();
		if ( ! empty( $excluded_spaces ) ) {
			$id_list = implode( ',', array_map( 'absint', $excluded_spaces ) );
			$sql     = "( {$sql} ) AND ( space_id IS NULL OR space_id = 0 OR space_id NOT IN ( {$id_list} ) )";
		}

		return array( $sql, $params );
	}

	/**
	 * Space IDs whose owner disabled "Push space posts to activity feed".
	 *
	 * Reads the per-space bn_space_{id}_push_to_feed options (default 1 = push)
	 * and returns the IDs explicitly set to 0. Cached per request — the home feed
	 * builds its source clause once, and the result set (opted-out spaces) is
	 * small. The option_name LIKE is anchored on the bn_space_ prefix so the
	 * options index is usable.
	 *
	 * @return int[] Space IDs to exclude from the home feed.
	 */
	private function feed_excluded_space_ids(): array {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'bn\\_space\\_%\\_push\\_to\\_feed'
			   AND option_value = '0'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = array();
		foreach ( (array) $names as $name ) {
			if ( preg_match( '/^bn_space_(\d+)_push_to_feed$/', (string) $name, $m ) ) {
				$ids[] = (int) $m[1];
			}
		}

		return $ids;
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

		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $user_id );

		$map = array(
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
					// Count the content actually available in each tab. The 24-hour
					// window that was here only counted last-day posts, so tabs
					// with no recent-but-existing content (Following / Spaces /
					// Network) showed no badge while the tab still rendered older
					// posts — the count must match what the feed shows.
					"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
					 WHERE status = 'published'
					   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
					   AND ({$source_where})
					   {$excluded_where}
					   {$block_mute_where}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...array_merge( $source_params, $block_mute_params )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

			$counts[ $key ] = $count;
		}

		return $counts;
	}

	/**
	 * Count home-feed posts newer than a client-known id (Free new-posts pill).
	 *
	 * Powers the 60s poll behind the `/activity` "N new posts" pill. Counts only
	 * published, non-scheduled posts in the viewer's source blend (reusing
	 * {@see self::home_source_clause()}) whose id is greater than $after_id, and
	 * always excludes the viewer's own posts so the pill never fires on the
	 * member's own submission (the composer already inserts those locally). The
	 * count is clamped so the pill copy stays meaningful. Also returns the newest
	 * visible id so the poll can advance its watermark without a second query.
	 *
	 * Degrades to a zero count for logged-out callers (no source blend).
	 *
	 * @param int    $user_id  Viewing user ID.
	 * @param int    $after_id Highest post id the client has already rendered.
	 * @param string $filter   Filter slug: for-you | following | spaces | network.
	 * @return array{count:int,newest_id:int}
	 */
	public function home_feed_new_count( int $user_id, int $after_id, string $filter = 'for-you' ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array(
				'count'     => 0,
				'newest_id' => $after_id,
			);
		}

		if ( ! in_array( $filter, self::HOME_FILTERS, true ) ) {
			$filter = 'for-you';
		}

		$excluded_where = $this->excluded_users_where();

		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $user_id );
		[ $source_where, $source_params ]         = $this->home_source_clause( $filter, $user_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS new_count, COALESCE(MAX(id), %d) AS newest_id
				 FROM {$wpdb->prefix}bn_posts
				 WHERE status = 'published'
				   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
				   AND id > %d
				   AND user_id <> %d
				   AND ({$source_where})
				   {$excluded_where}
				   {$block_mute_where}", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( array( $after_id, $after_id, $user_id ), $source_params, $block_mute_params )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'count'     => isset( $row['new_count'] ) ? (int) $row['new_count'] : 0,
			'newest_id' => isset( $row['newest_id'] ) ? (int) $row['newest_id'] : $after_id,
		);
	}

	/**
	 * Return the active site-wide announcement for a user, or null.
	 *
	 * Returns null when no published, unexpired announcement remains after
	 * filtering out the user's dismissals (stored in user_meta).
	 *
	 * @param int $user_id Viewing user ID.
	 * @return array|null Hydrated post array or null.
	 */
	public function active_announcement( int $user_id ): ?array {
		// Single enforcement point for the Announcements feature. Every consumer
		// (home-feed prepend, REST feed payload) flows through here, so gating it
		// once means no announcement surfaces anywhere when the site owner turns
		// the feature off — no per-template guards needed.
		if ( ! buddynext_feature_enabled( 'announcements' ) ) {
			return null;
		}

		global $wpdb;

		$dismissed = self::dismissed_announcement_ids( $user_id );

		$exclude_sql = '';
		$params      = array();
		if ( ! empty( $dismissed ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $dismissed ), '%d' ) );
			$exclude_sql  = " AND p.id NOT IN ({$placeholders})";
			$params       = $dismissed;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			empty( $params )
				? "SELECT p.* FROM {$wpdb->prefix}bn_posts p
				 WHERE p.is_announcement = 1
				   AND p.type = 'announcement'
				   AND p.status = 'published'
				   AND (p.site_pin_expires_at IS NULL OR p.site_pin_expires_at > UTC_TIMESTAMP())
				 ORDER BY p.created_at DESC
				 LIMIT 1"
				: $wpdb->prepare(
					"SELECT p.* FROM {$wpdb->prefix}bn_posts p
				 WHERE p.is_announcement = 1
				   AND p.type = 'announcement'
				   AND p.status = 'published'
				   AND (p.site_pin_expires_at IS NULL OR p.site_pin_expires_at > UTC_TIMESTAMP()){$exclude_sql}
				 ORDER BY p.created_at DESC
				 LIMIT 1",
					$params
				),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( null === $row ) {
			return null;
		}

		return $this->post_service->hydrate( $row );
	}

	/**
	 * Return the array of announcement post IDs this user has dismissed.
	 *
	 * @param int $user_id Viewing user ID.
	 * @return int[]
	 */
	public static function dismissed_announcement_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$raw = get_user_meta( $user_id, self::DISMISSED_ANNOUNCEMENTS_META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $raw ) ) );
	}

	/**
	 * Mark an announcement as dismissed for a user (idempotent).
	 *
	 * @param int $user_id Viewing user ID.
	 * @param int $post_id Announcement post ID.
	 * @return void
	 */
	public static function dismiss_announcement( int $user_id, int $post_id ): void {
		if ( $user_id <= 0 || $post_id <= 0 ) {
			return;
		}
		$dismissed   = self::dismissed_announcement_ids( $user_id );
		$dismissed[] = $post_id;
		update_user_meta( $user_id, self::DISMISSED_ANNOUNCEMENTS_META, array_values( array_unique( $dismissed ) ) );
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

		// Private-account gate. The owner sees themselves; admins see
		// everything; otherwise only approved followers see posts. Returns
		// an empty payload so the profile activity tab shows its existing
		// empty-state copy without leaking that the account has any posts.
		if ( $viewer_id !== $profile_user_id
			&& ! user_can( $viewer_id, 'manage_options' )
			&& ! buddynext_service( 'privacy' )->can_view_activity( $viewer_id, $profile_user_id )
		) {
			return array(
				'items'       => array(),
				'next_cursor' => null,
				'private'     => true,
			);
		}

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
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
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
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
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
	 * @param string|null $cursor      Pagination cursor.
	 * @param int         $per_page    Posts per page.
	 * @param string      $post_filter Sub-type facet: 'all' (default), 'media'
	 *                                 (posts with attachments), 'discussions'
	 *                                 (forum posts), or 'posts' (text/link, no
	 *                                 media, no forum). Unknown values fall back
	 *                                 to 'all'.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function explore_feed( ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT, string $post_filter = 'all' ): array {
		global $wpdb;

		$per_page       = min( $per_page, 50 );
		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();
		$viewer_id      = get_current_user_id();

		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $viewer_id );

		// Sub-type facet. Each clause is a static fragment selected by a
		// validated key — no user input is interpolated.
		$filter_where = '';
		switch ( $post_filter ) {
			case 'media':
				$filter_where = " AND media_ids IS NOT NULL AND media_ids <> '' AND media_ids <> '[]'";
				break;
			case 'discussions':
				$filter_where = " AND type IN ('discussion','forum_post','forum')";
				break;
			case 'posts':
				$filter_where = " AND type NOT IN ('discussion','forum_post','forum') AND ( media_ids IS NULL OR media_ids = '' OR media_ids = '[]' )";
				break;
		}

		// $cursor_where, $excluded_where and $filter_where contain only table/column names — no user data, safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE privacy = 'public'
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
			   {$excluded_where}
			   {$block_mute_where}
			   {$filter_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( $block_mute_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		// $sql was fully prepared by $wpdb->prepare() in the block above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$result = $this->paginate( (array) $rows, $per_page );

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
		return CursorCodec::encode( (string) $row['created_at'], (int) $row['id'] );
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

		$decoded = CursorCodec::decode( $cursor );
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

		$decoded = CursorCodec::decode( $cursor );
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

	/**
	 * Return the pinned post for a space, hydrated, or null when none is pinned.
	 *
	 * Most recently pinned wins when more than one is flagged. Honours the
	 * published-status + scheduled-window guards the space feed uses, and maps
	 * the row through PostService::hydrate() so callers get the canonical shape
	 * rather than a hand-built row.
	 *
	 * @param int $space_id Space ID.
	 * @return array<string,mixed>|null
	 */
	public function space_pinned_post( int $space_id ): ?array {
		if ( $space_id <= 0 ) {
			return null;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_posts
				 WHERE space_id = %d AND is_pinned = 1 AND status = 'published'
				   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
				 ORDER BY created_at DESC
				 LIMIT 1",
				$space_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $row ) ? $this->post_service->hydrate( $row ) : null;
	}

	/**
	 * Count published, live (non-future) posts in a space.
	 *
	 * @param int $space_id Space ID.
	 * @return int
	 */
	public function space_post_count( int $space_id ): int {
		if ( $space_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE space_id = %d AND status = 'published'
				   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())",
				$space_id
			)
		);
	}

	/**
	 * Count published, live posts in a space that carry at least one media
	 * attachment — the figure the space "Media" tab badge shows.
	 *
	 * @param int $space_id Space ID.
	 * @return int
	 */
	public function space_media_post_count( int $space_id ): int {
		if ( $space_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE space_id = %d AND status = 'published'
				   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
				   AND media_ids IS NOT NULL AND media_ids != '[]' AND media_ids != ''",
				$space_id
			)
		);
	}

	/**
	 * Return a flat, de-duplicated list of media IDs from a space's recent
	 * published posts, newest post first, capped at $limit. Powers the space
	 * "Media" gallery without the template touching bn_posts directly.
	 *
	 * Scans up to 60 recent media-bearing posts (the source rows can each carry
	 * several attachments) and flattens their media_ids JSON arrays before
	 * trimming to $limit unique IDs.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max media IDs to return (1-100). Default 24.
	 * @return array<int,int>
	 */
	public function space_media_ids( int $space_id, int $limit = 24 ): array {
		if ( $space_id <= 0 ) {
			return array();
		}
		$limit = max( 1, min( 100, $limit ) );

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT media_ids FROM {$wpdb->prefix}bn_posts
				 WHERE space_id = %d AND status = 'published'
				   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
				   AND media_ids IS NOT NULL AND media_ids != '[]' AND media_ids != ''
				 ORDER BY created_at DESC
				 LIMIT 60",
				$space_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$media_ids = array();
		foreach ( (array) $rows as $json ) {
			$decoded = json_decode( (string) $json, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			foreach ( $decoded as $mid ) {
				$mid = absint( $mid );
				if ( $mid > 0 ) {
					$media_ids[] = $mid;
				}
			}
		}

		return array_slice( array_values( array_unique( $media_ids ) ), 0, $limit );
	}
}
