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
	 * Object-cache group for short-lived feed reads.
	 */
	private const CACHE_GROUP = 'buddynext_feed';

	/**
	 * TTL (seconds) for the new-count poll memo. Short enough that the "N new
	 * posts" hint stays near-live, long enough to collapse the repeated polls a
	 * single open tab fires on its stable after_id.
	 */
	private const NEW_COUNT_TTL = 30;

	/**
	 * TTL for the cached announcement id lists. A backstop only — every write that can
	 * add an announcement bumps the version below, so this never has to be short.
	 */
	private const ANNOUNCEMENT_TTL = HOUR_IN_SECONDS;

	/**
	 * How many recent announcements are considered per scope.
	 *
	 * Announcements are a handful per space at most, so this is not a real limit — but
	 * it is a stated one rather than an unbounded read. If a scope somehow holds more
	 * than this many LIVE announcements at once, only the newest 20 are candidates.
	 */
	private const ANNOUNCEMENT_CANDIDATES = 20;

	/**
	 * Version counter for every cached announcement id list.
	 */
	private const ANNOUNCEMENT_VERSION_KEY = 'announcement_ids_version';

	/**
	 * Version counter for the cached pinned-post lists.
	 */
	private const PINNED_VERSION_KEY = 'pinned_ids_version';

	/**
	 * Ceiling for the "N new posts" pill.
	 *
	 * Two jobs, one number.
	 *
	 * DISPLAY: nobody acts on "3,412 new posts" differently than on "99+". A raw
	 * four-digit count is noise, and it makes a healthy community read as
	 * overwhelming. Every mainstream platform caps this (X: "Show N posts";
	 * Facebook / LinkedIn: just "New posts").
	 *
	 * SCALE: the count query used to be an unbounded COUNT(*) over every post newer
	 * than the member's watermark — so the further behind a member was, the more
	 * rows it scanned, and the cost fell on exactly the members we most want back:
	 * the ones returning after time away. Counting a bounded window instead means
	 * the work is the same whether they missed 50 posts or 50,000.
	 *
	 * We count up to CAP + 1 so the caller can tell "exactly 99" from "99 or more".
	 */
	private const NEW_COUNT_CAP = 99;

	/**
	 * TTL (seconds) for the home-tab count memo. The four per-tab COUNT(*) are
	 * heavy on a large bn_posts, so collapse the repeat loads (nav re-render,
	 * poll, multiple tabs) onto one set of counts. A nav badge tolerates this much
	 * staleness; the feed itself always reflects live content.
	 */
	private const HOME_COUNTS_TTL = 60;

	/**
	 * User_meta key storing the list of announcement post IDs the user has
	 * dismissed. Value is a flat array of integer post IDs.
	 */
	public const DISMISSED_ANNOUNCEMENTS_META = 'bn_dismissed_announcements';

	/**
	 * Max dismissed-announcement IDs kept per user. Bounds the serialized usermeta
	 * array and the NOT IN() clause in active_announcement(). Only a handful of
	 * announcements are ever live, so the oldest ids (expired/deleted announcements
	 * that can no longer surface) age out safely.
	 *
	 * @var int
	 */
	private const MAX_DISMISSED_ANNOUNCEMENTS = 100;

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
		$per_page = max( 1, min( $per_page, 50 ) );

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
		// above the rest, then posts in open spaces matching the viewer's picked
		// interests (the tier-then-recency house pattern; the for-you catch-all
		// already INCLUDES all public open-space posts, so interests act on rank,
		// not inclusion — see docs/plans/interests-personalization.md §4.3), then
		// chronological. IDs are capped + absint'd, so the CASE stays index-safe
		// and carries no user data; the interest subquery is uncorrelated and
		// rides the bn_spaces category index. Blank interests / no connections
		// leave the ordering exactly as before (additive signal). Other filters
		// (following / spaces / network) keep the plain chronological order.
		// Home is strictly chronological (plus the for-you affinity tiers below).
		// Contextual pins (is_pinned) are NOT floated here: a member pinning their
		// own profile post — or any of a space's pins — used to bleed to the top of
		// every home timeline. Pins now surface only in their own context (profile
		// / space feeds); the admin announcement is the sole top-of-home surface.
		$default_order_by = 'created_at DESC, id DESC';
		if ( 'for-you' === $filter && $user_id > 0 ) {
			global $wpdb;
			$bn_conn_ids     = $this->connection_ids_capped( $user_id );
			$bn_interest_ids = array_slice(
				( new \BuddyNext\Onboarding\OnboardingService() )->get_interest_ids( $user_id ),
				0,
				10
			);

			$bn_tiers = array();
			if ( ! empty( $bn_conn_ids ) ) {
				$bn_tiers[] = 'WHEN user_id IN (' . implode( ',', $bn_conn_ids ) . ') THEN ' . count( $bn_tiers );
			}
			if ( ! empty( $bn_interest_ids ) ) {
				$bn_tiers[] = "WHEN space_id IN (SELECT id FROM {$wpdb->prefix}bn_spaces WHERE type = 'open' AND category_id IN ("
					. implode( ',', array_map( 'absint', $bn_interest_ids ) )
					. ')) THEN ' . count( $bn_tiers );
			}
			if ( ! empty( $bn_tiers ) ) {
				$default_order_by = 'CASE ' . implode( ' ', $bn_tiers )
					. ' ELSE ' . count( $bn_tiers ) . ' END ASC, created_at DESC, id DESC';
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
				// The followed-hashtag branch carries the SAME public + space-visibility
				// scope (public privacy AND non-space/open/viewer-is-member) — otherwise
				// following a tag would surface a public post inside a private/secret
				// space, or a followers-only post by a non-followed author, to a viewer
				// who is not a member/follower (the overarching guard below only blocks
				// 'private'). Member-space posts also reach the viewer via joined-spaces,
				// so the member clause here is belt-and-suspenders, not the sole path.
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
				OR (
					id IN (
						SELECT ph.post_id FROM {$wpdb->prefix}bn_post_hashtags ph
						WHERE ph.object_type = 'post'
						  AND ph.hashtag_id IN (
							SELECT hf.hashtag_id FROM {$wpdb->prefix}bn_hashtag_follows hf
							WHERE hf.user_id = %d
						)
					)
					AND privacy = 'public'
					AND (
						space_id IS NULL
						OR space_id = 0
						OR space_id IN (
							SELECT id FROM {$wpdb->prefix}bn_spaces WHERE type = 'open'
						)
						OR space_id IN (
							SELECT space_id FROM {$wpdb->prefix}bn_space_members
							WHERE user_id = %d AND status = 'active'
						)
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
				$params = array( $user_id, $user_id, $user_id, $user_id, $user_id );
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
	 * Reads the per-space push_to_feed field (default 1 = push) from bn_space_meta
	 * and returns the space IDs explicitly set to 0. Cached per request — the home
	 * feed builds its source clause once, and the opted-out set is small. The
	 * meta_key index makes the lookup a direct indexed scan.
	 *
	 * @return int[] Space IDs to exclude from the home feed.
	 */
	private function feed_excluded_space_ids(): array {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				"SELECT bn_space_id FROM {$wpdb->bn_spacemeta}
				 WHERE meta_key = 'push_to_feed' AND meta_value = '0'"
			)
		);

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

		// Short-TTL memo: the four per-tab COUNT(*) over bn_posts are heavy at
		// scale and were re-run on every nav render. Collapse them for HOME_COUNTS_TTL.
		$cache_key = "home_counts_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
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
					//
					// CAPPED at NEW_COUNT_CAP + 1. This is a nav-tab badge: nobody reads
					// "3,412" on it differently than "99+", but an uncapped COUNT(*) scans
					// the whole match set on every cache-miss, four times over. The inner
					// SELECT stops at CAP + 1, so the scan is bounded and the caller can
					// still tell an exact 99 from "99 or more".
					"SELECT COUNT(*) FROM (
					    SELECT 1 FROM {$wpdb->prefix}bn_posts
					    WHERE status = 'published'
					      AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
					      AND ({$source_where})
					      {$excluded_where}
					      {$block_mute_where}
					    LIMIT %d
					 ) _capped", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					...array_merge( $source_params, $block_mute_params, array( self::NEW_COUNT_CAP + 1 ) )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

			$counts[ $key ] = $count;
		}

		wp_cache_set( $cache_key, $counts, self::CACHE_GROUP, self::HOME_COUNTS_TTL );

		return $counts;
	}

	/**
	 * Count home-feed posts newer than a client-known id (Free new-posts pill).
	 *
	 * Powers the 60s poll behind the `/activity` "N new posts" pill. Counts only
	 * published, non-scheduled posts in the viewer's source blend (reusing
	 * {@see self::home_source_clause()}) whose id is greater than $after_id, and
	 * always excludes the viewer's own posts so the pill never fires on the
	 * member's own submission (the composer already inserts those locally).
	 *
	 * The count is CLAMPED to {@see self::NEW_COUNT_CAP} + 1 — a bound that is
	 * enforced in the SQL, not merely applied to the result. (This docblock claimed
	 * the clamp long before any code did it: the query was an unbounded COUNT(*)
	 * over the member's whole backlog, so the pill printed things like "5250 new
	 * posts" and the scan grew with every post the member missed.)
	 *
	 * Also returns the newest visible id so the poll can advance its watermark
	 * without a second query.
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

		// Short-TTL memo: an open feed polls /feed/new-count on a stable after_id
		// every cycle, so without this each poll re-ran the COUNT(*). Keyed by the
		// three inputs the result depends on; time-expiry only (no event bust —
		// busting on every post at scale would defeat the cache, and a count this
		// soft tolerates up to NEW_COUNT_TTL of lag). On a site with no persistent
		// object cache this degrades to the previous per-request behaviour.
		$cache_key = "new_count_{$user_id}_{$filter}_{$after_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$excluded_where = $this->excluded_users_where();

		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $user_id );
		[ $source_where, $source_params ]         = $this->home_source_clause( $filter, $user_id );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared
		// COUNT A BOUNDED WINDOW, NOT THE WHOLE TAIL.
		//
		// This was a plain COUNT(*) over every post newer than $after_id. The further
		// behind a member's watermark was, the more rows it examined — measured at
		// rows_examined=3025 on a 6k-post site, and it grows linearly with the gap.
		// So a member returning after a week on a busy community paid for a scan of
		// everything they missed, on a POLLING path, and the memo below cannot save
		// them: its key includes $after_id, so every returning member has their own.
		//
		// The inner LIMIT stops the scan at CAP + 1 rows. We only need to know
		// "how many, up to 99+" — so counting past that is work nobody reads.
		// MAX(id) over the same bounded window is still the true newest id, because
		// the window is ordered by id DESC.
		$limit = self::NEW_COUNT_CAP + 1;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS new_count, COALESCE(MAX(id), %d) AS newest_id
				 FROM (
				     SELECT id
				     FROM {$wpdb->prefix}bn_posts
				     WHERE status = 'published'
				       AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
				       AND id > %d
				       AND user_id <> %d
				       AND ({$source_where})
				       {$excluded_where}
				       {$block_mute_where}
				     ORDER BY id DESC
				     LIMIT %d
				 ) AS bounded", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				...array_merge( array( $after_id, $after_id, $user_id ), $source_params, $block_mute_params, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.NotPrepared

		$result = array(
			'count'     => isset( $row['new_count'] ) ? (int) $row['new_count'] : 0,
			'newest_id' => isset( $row['newest_id'] ) ? (int) $row['newest_id'] : $after_id,
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::NEW_COUNT_TTL );

		return $result;
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

		$dismissed = self::dismissed_announcement_ids( $user_id );
		$ids       = $this->announcement_ids( null );

		// Priority: if the owner has featured a specific announcement (via the
		// Announcements admin), it leads the home feed as long as it is still a live
		// site-wide announcement the viewer hasn't dismissed. This is what stops a
		// newer announcement from silently displacing the one the owner wants on top;
		// with nothing featured we fall through to "newest active wins".
		//
		// The featured id is looked for in the candidate list rather than fetched with
		// its own query: being in that list IS the "still a live site-wide announcement"
		// test the separate query was doing, and live_announcement_row() re-checks the
		// rest. One less query on the busiest page on the site.
		$featured_id = (int) get_option( 'buddynext_featured_announcement', 0 );

		if ( $featured_id > 0
			&& ! in_array( $featured_id, $dismissed, true )
			&& in_array( $featured_id, $ids, true )
		) {
			$featured = $this->live_announcement_row( $featured_id );

			if ( null !== $featured ) {
				return $this->post_service->hydrate( $featured );
			}
		}

		return $this->first_visible_announcement( $ids, $dismissed );
	}

	/**
	 * Return the active announcement for a single space (or null).
	 *
	 * Space-scoped announcements never surface in the home feed (active_announcement
	 * filters space_id IS NULL); they show at the top of their own space instead.
	 * Respects the same dismissals + expiry + feature gate.
	 *
	 * @param int $space_id Space to check.
	 * @param int $user_id  Viewing user ID.
	 * @return array|null Hydrated post array or null.
	 */
	public function space_announcement( int $space_id, int $user_id ): ?array {
		if ( $space_id <= 0 || ! buddynext_feature_enabled( 'announcements' ) ) {
			return null;
		}

		return $this->first_visible_announcement(
			$this->announcement_ids( $space_id ),
			self::dismissed_announcement_ids( $user_id )
		);
	}

	/**
	 * Current version of the announcement id lists.
	 *
	 * @return int
	 */
	private static function announcement_version(): int {
		$version = wp_cache_get( self::ANNOUNCEMENT_VERSION_KEY, self::CACHE_GROUP );

		if ( false === $version ) {
			$version = 1;
			wp_cache_set( self::ANNOUNCEMENT_VERSION_KEY, $version, self::CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Invalidate EVERY cached announcement id list, site-wide and per space, in O(1).
	 *
	 * Called from every write that can add or remove an announcement. A version bump is
	 * used rather than deleting individual keys because the writer usually does not know
	 * which scope it touched — PostService::end_announcement() has a post id and nothing
	 * else — and a bust that depends on the writer knowing the right key is a bust that
	 * eventually targets the wrong one.
	 *
	 * @return void
	 */
	public static function flush_announcement_ids(): void {
		wp_cache_set( self::ANNOUNCEMENT_VERSION_KEY, self::announcement_version() + 1, self::CACHE_GROUP );
	}

	/**
	 * Ids of the announcements that could currently show in a scope, newest first.
	 *
	 * Only IDS are cached, never the rows. Everything that decides whether an
	 * announcement is still showable — status, is_announcement, expiry, deletion — is
	 * re-read per id through PostService::get(), which has its own cache and is busted on
	 * every post write. So a stale id list can only ever MISS a brand-new announcement
	 * (which the version bump on create prevents); it can never resurrect one that was
	 * ended, expired, or deleted. Caching the rows would have been able to do exactly
	 * that, and an announcement that will not go away is worse than one that arrives a
	 * moment late.
	 *
	 * @param int|null $space_id Space scope, or null for the site-wide announcements.
	 * @return array<int, int>
	 */
	private function announcement_ids( ?int $space_id ): array {
		$scope     = null === $space_id ? 'site' : 'space_' . $space_id;
		$cache_key = 'ann_ids_v' . self::announcement_version() . '_' . $scope;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null === $space_id ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_posts
					 WHERE is_announcement = 1
					   AND type = 'announcement'
					   AND status = 'published'
					   AND space_id IS NULL
					 ORDER BY created_at DESC
					 LIMIT %d",
					self::ANNOUNCEMENT_CANDIDATES
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_posts
					 WHERE is_announcement = 1
					   AND type = 'announcement'
					   AND status = 'published'
					   AND space_id = %d
					 ORDER BY created_at DESC
					 LIMIT %d",
					$space_id,
					self::ANNOUNCEMENT_CANDIDATES
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$ids = array_map( 'intval', (array) $ids );

		wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::ANNOUNCEMENT_TTL );

		return $ids;
	}

	/**
	 * The newest announcement in the list that this viewer should actually see.
	 *
	 * The dismissal filter used to live INSIDE the SQL, under a LIMIT 1 — which means it
	 * was never "show the newest announcement unless dismissed", it was "show the newest
	 * announcement the viewer has not dismissed". Those differ: a member who dismissed
	 * the newest one is supposed to fall through to the one below it, not to nothing. So
	 * the candidates are walked here in the same order, and the first survivor wins.
	 *
	 * Expiry is re-checked here rather than in SQL, so an announcement that expires while
	 * the id list is cached disappears at the correct moment instead of at the TTL.
	 *
	 * @param array<int, int> $ids       Candidate announcement ids, newest first.
	 * @param array<int, int> $dismissed Announcement ids this viewer dismissed.
	 * @return array<string, mixed>|null
	 */
	private function first_visible_announcement( array $ids, array $dismissed ): ?array {
		foreach ( $ids as $id ) {
			if ( in_array( (int) $id, $dismissed, true ) ) {
				continue;
			}

			$row = $this->live_announcement_row( (int) $id );

			if ( null !== $row ) {
				return $this->post_service->hydrate( $row );
			}
		}

		return null;
	}

	/**
	 * Re-read one announcement and say whether it is still live.
	 *
	 * Read through PostService::get() (cached, and busted on every post write) so an
	 * ended, unpublished, deleted or expired announcement is filtered out immediately,
	 * however stale the id list is.
	 *
	 * @param int $post_id Announcement post id.
	 * @return array<string, mixed>|null Row when it is still showable, null otherwise.
	 */
	private function live_announcement_row( int $post_id ): ?array {
		$row = $this->post_service->get( $post_id );

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( 1 !== (int) ( $row['is_announcement'] ?? 0 )
			|| 'announcement' !== (string) ( $row['type'] ?? '' )
			|| 'published' !== (string) ( $row['status'] ?? '' )
			|| ! empty( $row['is_deleted'] )
		) {
			return null;
		}

		$expires = (string) ( $row['site_pin_expires_at'] ?? '' );

		if ( '' !== $expires && strtotime( $expires . ' UTC' ) <= time() ) {
			return null;
		}

		return $row;
	}

	/**
	 * List every announcement (any status) for the admin management surface.
	 *
	 * Returns raw rows (newest first) so the admin can compute active / scheduled /
	 * expired without hydrating the full post payload.
	 *
	 * @param int $limit Max rows (1-500).
	 * @return array<int,array<string,mixed>>
	 */
	public function list_all_announcements( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, space_id, content, status, created_at, site_pin_expires_at, scheduled_at
				 FROM {$wpdb->prefix}bn_posts
				 WHERE is_announcement = 1 AND type = 'announcement'
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * End an announcement now (expire it) without deleting the post.
	 *
	 * Sets site_pin_expires_at to the current time so active_announcement() /
	 * space_announcement() stop surfacing it; the post itself stays in the feed.
	 * Clears the featured pointer if it named this announcement.
	 *
	 * @param int $post_id Announcement post ID.
	 * @return bool
	 */
	public function end_announcement_now( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'site_pin_expires_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array(
				'id'              => $post_id,
				'is_announcement' => 1,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( (int) get_option( 'buddynext_featured_announcement', 0 ) === $post_id ) {
			delete_option( 'buddynext_featured_announcement' );
		}

		// Ending an announcement changes what every home feed shows, so the cached feeds
		// have to go — exactly as they do when an announcement is PUBLISHED. Without this
		// the announcement stays pinned to the top of the community's feed after the owner
		// has ended it, which is the one moment they most need it gone (they usually end
		// an announcement because it is wrong or no longer true). Busted here, at the
		// write, so both callers — the admin screen and the REST route — are covered.
		$this->flush_all_home_caches();
		self::flush_announcement_ids();

		// And the post's own cached row. This method writes bn_posts DIRECTLY, so the
		// copy PostService is holding still says the announcement has no expiry — and
		// every reader that goes through PostService::get() (which is now how the
		// announcement surfaces re-check whether one is still live) would keep being told
		// it is. The sibling end path in PostService busts this; this one never did,
		// because until now nothing read the announcement through that cache.
		PostService::flush_cache( $post_id );

		return false !== $updated;
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
		$dismissed   = array_values( array_unique( $dismissed ) );

		// Cap the list so it cannot grow without bound (post deletion cannot
		// efficiently prune every dismisser's usermeta, so keep only the most
		// recent ids — older ones are for announcements that can no longer surface).
		if ( count( $dismissed ) > self::MAX_DISMISSED_ANNOUNCEMENTS ) {
			$dismissed = array_slice( $dismissed, -self::MAX_DISMISSED_ANNOUNCEMENTS );
		}

		update_user_meta( $user_id, self::DISMISSED_ANNOUNCEMENTS_META, $dismissed );
	}

	/**
	 * Invalidate one user's first-page home feed cache.
	 *
	 * Used when a per-user change (e.g. dismissing an announcement) must reflect
	 * immediately rather than after the 30s page-1 TTL. No-op when the feed cache
	 * is disabled.
	 *
	 * @param int $user_id User whose home feed cache to bust.
	 * @return void
	 */
	public function flush_home_cache( int $user_id ): void {
		$this->cache?->invalidate_writer( $user_id );
	}

	/**
	 * Invalidate every user's first-page home feed cache at once.
	 *
	 * Used for site-wide changes (e.g. ending an announcement) so the change is
	 * visible to everyone immediately instead of after the 30s TTL.
	 *
	 * @return void
	 */
	public function flush_all_home_caches(): void {
		$this->cache?->invalidate_all_users();
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
		$per_page = max( 1, min( $per_page, 50 ) );

		// Private-account gate FIRST, and deliberately OUTSIDE the cache. The owner sees
		// themselves; admins see everything; otherwise only approved followers see posts.
		// Running it on every request -- never from a cached value -- is what makes a
		// privacy flip take effect immediately: the moment the owner goes private (or drops
		// a follower), the very next request is denied here and never reaches the cached
		// feed. A denied viewer returns the empty payload and touches no cache at all.
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

		// Page-1 cache wrap (A9). First page only; the viewer is in the key so block/mute
		// scoping never leaks across viewers. The owner's post create/delete busts this via
		// invalidate_writer (the profile version salt), so a new post shows for every
		// allowed viewer at once. Same no-live-Pro-filter caveat as the space feed.
		if ( null !== $this->cache && null === $cursor && $profile_user_id > 0 ) {
			$key   = $this->cache->profile_page_1_key( $profile_user_id, $viewer_id, $per_page );
			$cache = $this->cache;
			return (array) $cache->get(
				$key,
				FeedCache::GROUP_USER,
				FeedCache::TTL_HOME_PAGE_1,
				fn() => $this->profile_feed_uncached( $profile_user_id, $viewer_id, null, $per_page )
			);
		}

		return $this->profile_feed_uncached( $profile_user_id, $viewer_id, $cursor, $per_page );
	}

	/**
	 * Uncached profile feed query — internal callee of profile_feed().
	 *
	 * The privacy gate lives in the public wrapper, not here: this is only ever reached
	 * once the viewer is already allowed to see the profile's activity.
	 *
	 * @param int         $profile_user_id Profile owner.
	 * @param int         $viewer_id       Viewer.
	 * @param string|null $cursor          Pagination cursor.
	 * @param int         $per_page        Page size.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	private function profile_feed_uncached( int $profile_user_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page = max( 1, min( $per_page, 50 ) );

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

		$per_page = max( 1, min( (int) ( $query_args['per_page'] ?? $per_page ), 50 ) );

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

		// Apply the canonical viewer block/mute exclusion — the same gate home_feed
		// and explore_feed use — so a profile owner the viewer has blocked or muted
		// does not leak posts onto their own profile feed (self-block is impossible,
		// so the owner viewing their own profile is unaffected).
		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $viewer_id );

		// $privacy_clause is a hardcoded SQL constant — safe.
		// $cursor_where is either '' or the single hardcoded SQL constant — safe.
		// $excluded_where contains only table/column names — no user data, safe.
		// $block_mute_where is the canonical block-exclusion fragment; its params are bound below.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE user_id = %d
			   {$privacy_clause}
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
			   {$excluded_where}
			   {$block_mute_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( array( $profile_user_id ), $privacy_params, $block_mute_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
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
	 * Access control (space membership) is the caller's responsibility. Posts
	 * authored by suspended or shadow-banned users are always excluded, and the
	 * canonical viewer block/mute exclusion is applied so a blocked or muted
	 * co-member's posts never render in the space feed.
	 *
	 * @param int         $space_id  Space whose posts to show.
	 * @param int         $viewer_id Viewing user ID (reserved for future access checks).
	 * @param string|null $cursor    Pagination cursor.
	 * @param int         $per_page  Posts per page.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	public function space_feed( int $space_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		// Page-1 cache wrap (A9). Only the first page is cached (cursor null); deeper pages
		// are keyset-paginated and each cursor is a unique position, so caching them buys
		// nothing. The key carries the VIEWER, so one member's block/mute scoping never
		// leaks into another's feed. See FeedCache::space_page_1_key.
		//
		// Nothing in Pro currently hooks buddynext_feed_query_args / buddynext_feed_items,
		// so the result is a pure function of (space, viewer, page) plus content. If a
		// future Pro filter makes it depend on the viewer's ENTITLEMENTS, it must bump a
		// version on entitlement change (or accept the <=TTL lag), because the key cannot
		// see inside an arbitrary filter.
		if ( null !== $this->cache && null === $cursor && $space_id > 0 ) {
			$key   = $this->cache->space_page_1_key( $space_id, $viewer_id, $per_page );
			$cache = $this->cache;
			return (array) $cache->get(
				$key,
				FeedCache::GROUP_USER,
				FeedCache::TTL_HOME_PAGE_1,
				fn() => $this->space_feed_uncached( $space_id, $viewer_id, null, $per_page )
			);
		}

		return $this->space_feed_uncached( $space_id, $viewer_id, $cursor, $per_page );
	}

	/**
	 * Uncached space feed query — internal callee of space_feed().
	 *
	 * @param int         $space_id  Space.
	 * @param int         $viewer_id Viewer.
	 * @param string|null $cursor    Pagination cursor.
	 * @param int         $per_page  Page size.
	 * @return array{items: array[], next_cursor: string|null}
	 */
	private function space_feed_uncached( int $space_id, int $viewer_id, ?string $cursor = null, int $per_page = self::DEFAULT_LIMIT ): array {
		global $wpdb;

		$per_page       = min( $per_page, 50 );
		$cursor_where   = $this->cursor_where( $cursor );
		$excluded_where = $this->excluded_users_where();

		// Apply the canonical viewer block/mute exclusion — the same gate home_feed
		// and explore_feed use — so a co-member the viewer has blocked or muted does
		// not leak posts into a shared space feed.
		[ $block_mute_where, $block_mute_params ] = $this->viewer_block_mute_where( $viewer_id );

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

		$per_page = max( 1, min( (int) ( $query_args['per_page'] ?? $per_page ), 50 ) );

		// $cursor_where and $excluded_where contain only table/column names — no user data, safe.
		// $block_mute_where is the canonical block-exclusion fragment; its params are bound below.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE space_id = %d
			   AND status = 'published'
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
			   {$excluded_where}
			   {$block_mute_where}
			   {$cursor_where}
			 ORDER BY created_at DESC, id DESC
			 LIMIT %d",
			...array_merge( array( $space_id ), $block_mute_params, $this->cursor_params( $cursor ), array( $per_page + 1 ) )
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
	 * Shared SQL guard for the public Explore surface — used by the deck query AND
	 * the Explore pulse count so the stat always matches what the grid shows.
	 *
	 * Excludes reshares (amplification, not original discovery content; the Explore
	 * card cannot dereference shared_post_id), authorless rows (a deleted account
	 * otherwise renders as "Community member"), and rows with nothing to show (no
	 * text, media, poll, or link). Static fragment — only table/column names, no
	 * user input — safe to interpolate.
	 *
	 * @return string A leading-" AND " WHERE fragment.
	 */
	public function explore_renderable_where(): string {
		global $wpdb;
		return " AND type <> 'share'
			   AND user_id IN ( SELECT ID FROM {$wpdb->users} )
			   AND (
			       TRIM( COALESCE( content, '' ) ) <> ''
			       OR ( media_ids IS NOT NULL AND media_ids <> '' AND media_ids <> '[]' )
			       OR type = 'poll'
			       OR ( link_url IS NOT NULL AND link_url <> '' )
			   )";
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

		// Public-discovery guard (shared with the Explore pulse count so the stat
		// matches the grid) — never surface a reshare, an authorless row, or a row
		// with nothing to show. See explore_renderable_where().
		$renderable_where = $this->explore_renderable_where();

		// $cursor_where, $excluded_where, $filter_where and $renderable_where contain only table/column names — no user data, safe.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts
			 WHERE privacy = 'public'
			   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
			   {$excluded_where}
			   {$block_mute_where}
			   {$filter_where}
			   {$renderable_where}
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
	 * Warm every per-viewer cache the post-card reads, in one query per service for
	 * a whole page of feed items instead of ~3 per card.
	 *
	 * The post-card partial resolves viewer state per card — get_user_reaction() +
	 * is_bookmarked() + user_vote() + user_has_reported(); each now reads a cache
	 * this fills via a single batched query. MUST be called after a feed query and
	 * before the SSR post-card loop (home / profile / space / explore / bookmarks
	 * templates) as well as the REST render path — otherwise the first paint keeps
	 * the N+1 (the surface QA bounced 10062105921 for).
	 *
	 * @param array $items  Hydrated feed rows about to be rendered.
	 * @param int   $viewer Current user ID (0 when logged out — nothing to prime).
	 * @return void
	 */
	public function prime_viewer_state( array $items, int $viewer ): void {
		if ( $viewer <= 0 || empty( $items ) ) {
			return;
		}

		$post_ids   = array();
		$author_ids = array();
		$poll_ids   = array();
		foreach ( $items as $item ) {
			$pid = absint( $item['id'] ?? 0 );
			$aid = absint( $item['user_id'] ?? 0 );
			if ( $pid ) {
				$post_ids[] = $pid;
			}
			if ( $aid ) {
				$author_ids[] = $aid;
			}
			if ( $pid && 'poll' === ( $item['type'] ?? '' ) ) {
				$poll_ids[] = $pid;
			}
		}

		if ( empty( $post_ids ) ) {
			return;
		}

		if ( ! empty( $author_ids ) ) {
			cache_users( array_values( array_unique( $author_ids ) ) );
		}

		buddynext_service( 'reactions' )->get_user_emoji_map( $viewer, 'post', $post_ids );
		// Prime bookmark state for THIS PAGE only. The old call primed the viewer's
		// entire bookmark history (unbounded SELECT + filesort — bn_bookmarks has no
		// created_at index) on every single feed paint, and called it "one cached
		// query" — but the target deployment has no persistent object cache, so that
		// wp_cache is a no-op across requests and the query ran every time.
		buddynext_service( 'bookmarks' )->bookmarked_among( $viewer, $post_ids );
		buddynext_service( 'moderation' )->user_reported_map( $viewer, 'post', $post_ids );
		if ( ! empty( $poll_ids ) ) {
			buddynext_service( 'polls' )->user_votes_map( $viewer, $poll_ids );
		}

		/**
		 * Fires after core viewer-state is primed for a page of feed items, so add-ons
		 * (e.g. Pro member labels) can batch-prime their own per-author data in one
		 * query instead of an N+1 in the byline loop.
		 *
		 * @param array $items  Hydrated feed rows about to render.
		 * @param int   $viewer Current user ID.
		 */
		do_action( 'buddynext_feed_viewer_state_primed', $items, $viewer );
	}

	/**
	 * Return a space's pinned posts (up to the cap), newest first.
	 *
	 * The single-row space_pinned_post() left Pro's "10 pins per space" invisible —
	 * up to 9 pins were stored but never rendered. This returns the whole pinned
	 * set so the space feed can show a bounded pinned strip.
	 *
	 * @param int $space_id Space ID.
	 * @param int $limit    Max pins to return (clamped 1-20).
	 * @return array[] Hydrated pinned post rows, newest first.
	 */
	public function space_pinned_posts( int $space_id, int $limit = 10 ): array {
		if ( $space_id <= 0 ) {
			return array();
		}

		$limit = max( 1, min( $limit, 20 ) );

		// Runs on every space page paint. Unlike the feed, this one is NOT viewer-scoped —
		// the pinned strip is the same for everybody who can see the space — so a single
		// key per space is safe.
		//
		// Only the IDS are cached, and each is re-read through PostService::get() (cached,
		// and busted on every post write). So an unpinned, deleted, unpublished or
		// moderated post drops out immediately however stale the list is: the worst a
		// stale list can do is miss a NEWLY pinned post, which the bust on pin prevents.
		// Same argument as the announcements, and for the same reason — a pinned post that
		// will not go away is worse than one that arrives a moment late.
		$cache_key = 'pinned_ids_v' . self::pinned_version() . "_{$space_id}_{$limit}";
		$ids       = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( ! is_array( $ids ) ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}bn_posts
					 WHERE space_id = %d AND is_pinned = 1 AND status = 'published'
					   AND (scheduled_at IS NULL OR scheduled_at <= UTC_TIMESTAMP())
					 ORDER BY created_at DESC
					 LIMIT %d",
					$space_id,
					$limit
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			$ids = array_map( 'intval', (array) $ids );

			wp_cache_set( $cache_key, $ids, self::CACHE_GROUP, self::ANNOUNCEMENT_TTL );
		}

		$posts = array();

		foreach ( $ids as $id ) {
			$row = $this->post_service->get( (int) $id );

			if ( ! is_array( $row )
				|| empty( $row['is_pinned'] )
				|| 'published' !== (string) ( $row['status'] ?? '' )
				|| ! empty( $row['is_deleted'] )
			) {
				continue;
			}

			$posts[] = $this->post_service->hydrate( $row );
		}

		return $posts;
	}

	/**
	 * Current version of the cached pinned-post lists.
	 *
	 * @return int
	 */
	private static function pinned_version(): int {
		$version = wp_cache_get( self::PINNED_VERSION_KEY, self::CACHE_GROUP );

		if ( false === $version ) {
			$version = 1;
			wp_cache_set( self::PINNED_VERSION_KEY, $version, self::CACHE_GROUP );
		}

		return (int) $version;
	}

	/**
	 * Invalidate every cached pinned-post list.
	 *
	 * Called whenever a post is pinned or unpinned. The writer knows the post, not
	 * necessarily the space, and a pin is rare — so one bump beats guessing a key.
	 *
	 * @return void
	 */
	public static function flush_pinned_posts(): void {
		wp_cache_set( self::PINNED_VERSION_KEY, self::pinned_version() + 1, self::CACHE_GROUP );
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
