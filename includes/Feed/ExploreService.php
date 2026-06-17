<?php
/**
 * Explore discovery service — the community "heartbeat" deck.
 *
 * Explore is not a post feed: it is a single "what's going on" surface that
 * blends everything new across the community — new members, new spaces, hot
 * discussions, popular posts and shared media — into one masonry grid, with a
 * filter row that narrows the same grid by entity type.
 *
 * This service is the single source of truth for that deck. Both the SSR
 * template (templates/feed/explore.php) and the REST pagination endpoint
 * (FeedController::explore_feed_page) call deck(), so the first paint and every
 * appended page render from identical data and ordering.
 *
 * Post querying (privacy, suspension/shadow-ban exclusion, viewer block/mute,
 * cursor pagination, impression events) is delegated to FeedService so there is
 * one post-feed implementation; ExploreService adds the member + space queries
 * and the blended ordering on top.
 *
 * @package BuddyNext
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\SocialGraph\FollowService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the unified Explore discovery deck and its per-type filtered views.
 */
class ExploreService {

	/**
	 * Valid filter facets for the Explore grid.
	 *
	 * @var string[]
	 */
	public const FILTERS = array( 'all', 'members', 'spaces', 'posts', 'discussions', 'media' );

	/**
	 * How many new members to weave into the blended "all" first page.
	 */
	private const DISCOVERY_MEMBERS = 4;

	/**
	 * How many new spaces to weave into the blended "all" first page.
	 */
	private const DISCOVERY_SPACES = 2;

	/**
	 * Post-feed engine (delegated for all post querying).
	 *
	 * @var FeedService
	 */
	private FeedService $feed;

	/**
	 * Constructor.
	 *
	 * @param FeedService|null $feed Optional feed service; resolved from the
	 *                               container when omitted so any Pro rebind
	 *                               (AI-ranked feed) is honoured.
	 */
	public function __construct( ?FeedService $feed = null ) {
		if ( $feed instanceof FeedService ) {
			$this->feed = $feed;
			return;
		}

		if ( function_exists( 'buddynext_service' ) ) {
			$resolved = buddynext_service( 'feed' );
			if ( $resolved instanceof FeedService ) {
				$this->feed = $resolved;
				return;
			}
		}

		$this->feed = new FeedService( new FollowService(), new PostService() );
	}

	/**
	 * Build the discovery deck for a filter facet.
	 *
	 * @param string      $filter   One of self::FILTERS.
	 * @param string|null $cursor   Opaque pagination cursor.
	 * @param int         $per_page Cards per page.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null, filter: string}
	 */
	public function deck( string $filter, ?string $cursor = null, int $per_page = 12 ): array {
		$filter   = in_array( $filter, self::FILTERS, true ) ? $filter : 'all';
		$per_page = max( 1, min( $per_page, 50 ) );

		switch ( $filter ) {
			case 'members':
				$result = $this->members_deck( $cursor, $per_page );
				break;
			case 'spaces':
				$result = $this->spaces_deck( $cursor, $per_page );
				break;
			case 'posts':
			case 'discussions':
			case 'media':
				$result = $this->posts_deck( $filter, $cursor, $per_page );
				break;
			default:
				$result = $this->all_deck( $cursor, $per_page );
				break;
		}

		$result['filter'] = $filter;
		return $result;
	}

	/**
	 * Community "pulse" counts for the Explore hero (members, spaces, posts).
	 *
	 * Cached for five minutes so the hero never adds query load on a busy
	 * discovery surface. count_users() is itself WP-cached; the space + post
	 * COUNTs are bundled into the same transient.
	 *
	 * @return array{members:int, spaces:int, posts:int}
	 */
	public function pulse(): array {
		$cached = get_transient( 'buddynext_explore_pulse' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spaces = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE type IN ('open','private') AND is_archived = 0"
		);
		$posts  = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE privacy = 'public' AND status = 'published'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$user_counts = count_users();
		$pulse       = array(
			'members' => (int) ( $user_counts['total_users'] ?? 0 ),
			'spaces'  => $spaces,
			'posts'   => $posts,
		);

		set_transient( 'buddynext_explore_pulse', $pulse, 5 * MINUTE_IN_SECONDS );
		return $pulse;
	}

	/**
	 * Newest member IDs for the "people to discover" aside.
	 *
	 * Reuses the same exclusion rules as the grid (suspended / shadow-banned /
	 * blocked / self) so the aside never surfaces someone the grid hides.
	 *
	 * @param int $limit Maximum IDs.
	 * @return array<int,int>
	 */
	public function suggested_member_ids( int $limit = 3 ): array {
		$ids = array();
		foreach ( $this->newest_members( max( 1, $limit ), null )['items'] as $card ) {
			if ( ! empty( $card['user_id'] ) ) {
				$ids[] = (int) $card['user_id'];
			}
		}
		return $ids;
	}

	/**
	 * Blended "all" deck — the heartbeat view.
	 *
	 * Page one weaves a handful of the newest members and spaces into the post
	 * stream so the grid reads as "what's happening" rather than "latest posts".
	 * Subsequent pages (cursor present) return posts only, so cursor pagination
	 * stays anchored to a single backbone.
	 *
	 * @param string|null $cursor   Post cursor (null on the first page).
	 * @param int         $per_page Posts per page.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function all_deck( ?string $cursor, int $per_page ): array {
		$post_result = $this->feed->explore_feed( $cursor, $per_page, 'all' );
		$post_cards  = $this->wrap_posts( (array) ( $post_result['items'] ?? array() ) );

		// Discovery garnish only on the first page.
		if ( null !== $cursor && '' !== $cursor ) {
			return array(
				'items'       => $post_cards,
				'next_cursor' => $post_result['next_cursor'] ?? null,
			);
		}

		$discovery = array();
		foreach ( $this->newest_spaces( self::DISCOVERY_SPACES, null )['items'] as $space_card ) {
			$discovery[] = $space_card;
		}
		foreach ( $this->newest_members( self::DISCOVERY_MEMBERS, null )['items'] as $member_card ) {
			$discovery[] = $member_card;
		}

		$items = $this->interleave( $post_cards, $discovery );

		/**
		 * Filter the blended Explore first-page deck.
		 *
		 * Pro can inject community-pulse / AI-digest cards here (the wireframe's
		 * speculative card types) without the free build fabricating data.
		 *
		 * @since 1.6.0
		 *
		 * @param array<int,array<string,mixed>> $items     Ordered card payloads.
		 * @param array<int,array<string,mixed>> $post_cards Post cards only.
		 */
		$items = (array) apply_filters( 'buddynext_explore_all_deck', $items, $post_cards );

		return array(
			'items'       => $items,
			'next_cursor' => $post_result['next_cursor'] ?? null,
		);
	}

	/**
	 * Homogeneous post deck for the posts / discussions / media facets.
	 *
	 * @param string      $filter   posts|discussions|media.
	 * @param string|null $cursor   Post cursor.
	 * @param int         $per_page Posts per page.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function posts_deck( string $filter, ?string $cursor, int $per_page ): array {
		$post_filter = 'all';
		if ( 'discussions' === $filter ) {
			$post_filter = 'discussions';
		} elseif ( 'media' === $filter ) {
			$post_filter = 'media';
		} elseif ( 'posts' === $filter ) {
			$post_filter = 'posts';
		}

		$post_result = $this->feed->explore_feed( $cursor, $per_page, $post_filter );

		return array(
			'items'       => $this->wrap_posts( (array) ( $post_result['items'] ?? array() ) ),
			'next_cursor' => $post_result['next_cursor'] ?? null,
		);
	}

	/**
	 * Newest-members deck (offset paginated).
	 *
	 * @param string|null $cursor   Offset cursor ("off:N").
	 * @param int         $per_page Members per page.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function members_deck( ?string $cursor, int $per_page ): array {
		return $this->newest_members( $per_page, $cursor );
	}

	/**
	 * Newest-spaces deck (offset paginated).
	 *
	 * @param string|null $cursor   Offset cursor ("off:N").
	 * @param int         $per_page Spaces per page.
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function spaces_deck( ?string $cursor, int $per_page ): array {
		return $this->newest_spaces( $per_page, $cursor );
	}

	/**
	 * Resolve the newest members as card payloads.
	 *
	 * @param int         $limit  Maximum members.
	 * @param string|null $cursor Offset cursor (null = first page).
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function newest_members( int $limit, ?string $cursor ): array {
		$offset = $this->decode_offset( $cursor );

		$query = new \WP_User_Query(
			array(
				'number'      => $limit + 1,
				'offset'      => $offset,
				'orderby'     => 'registered',
				'order'       => 'DESC',
				'fields'      => 'all',
				'exclude'     => $this->excluded_member_ids(),
				'count_total' => false,
			)
		);

		$users    = (array) $query->get_results();
		$has_more = count( $users ) > $limit;
		if ( $has_more ) {
			array_pop( $users );
		}

		$items = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}
			$items[] = array(
				'kind'       => 'member',
				'user_id'    => (int) $user->ID,
				'registered' => (string) $user->user_registered,
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $has_more ? $this->encode_offset( $offset + $limit ) : null,
		);
	}

	/**
	 * Resolve the newest discoverable spaces as card payloads.
	 *
	 * Open + private spaces are discoverable (secret never appears); archived
	 * spaces are excluded.
	 *
	 * @param int         $limit  Maximum spaces.
	 * @param string|null $cursor Offset cursor (null = first page).
	 * @return array{items: array<int,array<string,mixed>>, next_cursor: string|null}
	 */
	private function newest_spaces( int $limit, ?string $cursor ): array {
		global $wpdb;

		$offset = $this->decode_offset( $cursor );

		// Static columns + bound LIMIT/OFFSET — no user data interpolated.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, slug, description, avatar_url, member_count, type, created_at
				   FROM {$wpdb->prefix}bn_spaces
				  WHERE type IN ('open','private')
				    AND is_archived = 0
				  ORDER BY created_at DESC, id DESC
				  LIMIT %d OFFSET %d",
				$limit + 1,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$rows     = (array) $rows;
		$has_more = count( $rows ) > $limit;
		if ( $has_more ) {
			array_pop( $rows );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'kind'  => 'space',
				'space' => array(
					'id'           => (int) $row['id'],
					'name'         => (string) $row['name'],
					'slug'         => (string) $row['slug'],
					'description'  => (string) ( $row['description'] ?? '' ),
					'avatar_url'   => (string) ( $row['avatar_url'] ?? '' ),
					'member_count' => (int) ( $row['member_count'] ?? 0 ),
					'type'         => (string) ( $row['type'] ?? 'open' ),
					'created_at'   => (string) ( $row['created_at'] ?? '' ),
				),
			);
		}

		return array(
			'items'       => $items,
			'next_cursor' => $has_more ? $this->encode_offset( $offset + $limit ) : null,
		);
	}

	/**
	 * Wrap raw post rows as classified card payloads, batching the hashtag
	 * lookup so the grid never runs a per-card query.
	 *
	 * @param array<int,array<string,mixed>> $posts Post rows from FeedService.
	 * @return array<int,array<string,mixed>>
	 */
	private function wrap_posts( array $posts ): array {
		if ( empty( $posts ) ) {
			return array();
		}

		$ids = array();
		foreach ( $posts as $post ) {
			$pid = (int) ( $post['id'] ?? 0 );
			if ( $pid > 0 ) {
				$ids[] = $pid;
			}
		}
		$hashtags = $this->first_hashtags_map( $ids );

		$cards = array();
		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) ) {
				continue;
			}
			$pid     = (int) ( $post['id'] ?? 0 );
			$cards[] = array(
				'kind'    => function_exists( 'buddynext_explore_card_kind' )
					? buddynext_explore_card_kind( $post )
					: 'post-text',
				'post'    => $post,
				'hashtag' => $hashtags[ $pid ] ?? '',
			);
		}

		return $cards;
	}

	/**
	 * Map post IDs to their first hashtag slug in one query (no N+1).
	 *
	 * @param array<int,int> $post_ids Post IDs.
	 * @return array<int,string> post_id => slug.
	 */
	private function first_hashtags_map( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'intval', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		// $placeholders is built from a counted %d list — safe to interpolate.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ph.post_id, h.slug
				   FROM {$wpdb->prefix}bn_post_hashtags ph
				   JOIN {$wpdb->prefix}bn_hashtags h ON h.id = ph.hashtag_id
				  WHERE ph.post_id IN ({$placeholders})
				  ORDER BY ph.post_id ASC, ph.created_at ASC, ph.hashtag_id ASC",
				...$post_ids
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$map = array();
		foreach ( (array) $rows as $row ) {
			$pid = (int) $row['post_id'];
			if ( ! isset( $map[ $pid ] ) ) {
				$map[ $pid ] = (string) $row['slug'];
			}
		}
		return $map;
	}

	/**
	 * Interleave discovery cards (members/spaces) into the post stream.
	 *
	 * Inserts one discovery card after roughly every third post so the mix reads
	 * naturally without burying posts. Any leftover discovery cards are appended.
	 *
	 * @param array<int,array<string,mixed>> $posts     Post cards.
	 * @param array<int,array<string,mixed>> $discovery Member/space cards.
	 * @return array<int,array<string,mixed>>
	 */
	private function interleave( array $posts, array $discovery ): array {
		if ( empty( $discovery ) ) {
			return $posts;
		}
		if ( empty( $posts ) ) {
			return $discovery;
		}

		$out   = array();
		$queue = array_values( $discovery );
		$slot  = 2; // First discovery card lands after the 2nd post.

		foreach ( $posts as $i => $post ) {
			$out[] = $post;
			if ( ( $i + 1 ) >= $slot && ! empty( $queue ) ) {
				$out[] = array_shift( $queue );
				$slot += 3;
			}
		}

		// Append any discovery cards that didn't fit.
		while ( ! empty( $queue ) ) {
			$out[] = array_shift( $queue );
		}

		return $out;
	}

	/**
	 * User IDs to exclude from member discovery: active suspensions,
	 * shadow-banned users, and (for a logged-in viewer) blocked users.
	 *
	 * @return array<int,int>
	 */
	private function excluded_member_ids(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$suspended = $wpdb->get_col(
			"SELECT user_id FROM {$wpdb->prefix}bn_user_suspensions
			 WHERE lifted_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())"
		);
		$shadow    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = '1'",
				'bn_shadow_banned'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$viewer  = get_current_user_id();
		$blocked = array();
		if ( $viewer > 0 && function_exists( 'buddynext_service' ) ) {
			$blocks = buddynext_service( 'blocks' );
			if ( $blocks && method_exists( $blocks, 'blocked_users' ) ) {
				$blocked = (array) $blocks->blocked_users( $viewer );
			}
		}

		return array_values(
			array_unique(
				array_map(
					'intval',
					array_merge(
						(array) $suspended,
						(array) $shadow,
						$blocked,
						$viewer > 0 ? array( $viewer ) : array()
					)
				)
			)
		);
	}

	/**
	 * Encode an offset cursor.
	 *
	 * @param int $offset Row offset.
	 * @return string Opaque cursor.
	 */
	private function encode_offset( int $offset ): string {
		return base64_encode( 'off:' . $offset ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode an offset cursor.
	 *
	 * @param string|null $cursor Opaque cursor.
	 * @return int Row offset (0 when absent/invalid).
	 */
	private function decode_offset( ?string $cursor ): int {
		if ( null === $cursor || '' === $cursor ) {
			return 0;
		}
		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || 0 !== strpos( $raw, 'off:' ) ) {
			return 0;
		}
		return max( 0, (int) substr( $raw, 4 ) );
	}
}
