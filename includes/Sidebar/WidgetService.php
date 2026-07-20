<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Sidebar widget data service.
 *
 * Owns the three core sidebar widgets that render in the shell's right
 * column on every BN hub: trending hashtags, suggested follows, joined
 * spaces. Each method is cached via WidgetCache.
 *
 * Layer 2 — Feature module per docs/specs/MODULAR-ARCHITECTURE.md.
 *
 * @package BuddyNext\Sidebar
 * @since 1.2.0
 */

declare( strict_types=1 );

namespace BuddyNext\Sidebar;

/**
 * Returns the data sets each sidebar widget renders.
 */
class WidgetService {

	/**
	 * Cache layer.
	 *
	 * @var WidgetCache
	 */
	private WidgetCache $cache;

	/**
	 * Inject dependencies.
	 *
	 * @param WidgetCache $cache Cache layer.
	 */
	public function __construct( WidgetCache $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Top N trending hashtags by post count.
	 *
	 * @param int $limit Max rows.
	 * @return array<int,object>
	 */
	public function trending_hashtags( int $limit = 5 ): array {
		// Respect the Hashtags feature: when the owner turns it off the trending
		// widget has no data and its renderers (which hide on an empty list) drop
		// out, so the sidebar carries no hashtag surface in a community that
		// disabled hashtags.
		if ( ! buddynext_feature_enabled( 'hashtags' ) ) {
			return array();
		}
		$limit = max( 1, min( $limit, 20 ) );
		return (array) $this->cache->get(
			'trending:' . $limit,
			WidgetCache::GROUP_GLOBAL,
			WidgetCache::TTL_TRENDING,
			static function () use ( $limit ): array {
				global $wpdb;
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT slug, post_count FROM ' . $wpdb->prefix . 'bn_hashtags ORDER BY post_count DESC LIMIT %d',
						$limit
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				return is_array( $rows ) ? $rows : array();
			}
		);
	}

	/**
	 * Up to N suggested users to follow for a given viewer.
	 *
	 * Excludes the viewer + already-followed users + blocked-either-direction
	 * pairs. The discovery backfill samples the primary key from a random entry point
	 * (see random_discovery_fill()) rather than sorting the community by RAND().
	 *
	 * This docblock used to say "ORDER BY RAND() is expensive at scale; the cache absorbs
	 * the worst case", and that sentence is why the query survived as long as it did. The
	 * cache does not absorb it: a cold cache is the normal state for the first viewer of
	 * every TTL window, and the project's own standard is that everything must hold up
	 * with the object cache OFF. A cache is not a fix for a full scan, it is a place for
	 * the full scan to hide.
	 *
	 * P2.1 (AI signals) will replace this with a precomputed affinity-ranked candidate
	 * pool when AI Feed is enabled.
	 *
	 * @param int $user_id Viewer user ID. 0 returns empty.
	 * @param int $limit   Max rows.
	 * @return array<int,object>
	 */
	public function suggested_follows( int $user_id, int $limit = 3 ): array {
		$user_id = max( 0, $user_id );
		$limit   = max( 1, min( $limit, 20 ) );
		if ( 0 === $user_id ) {
			return array();
		}
		// Over-fetch a ranked, hydrated candidate POOL and cache THAT — then draw the
		// per-load display sample from the cached pool OUTSIDE the cache below. A
		// sample taken inside the cache closure would be frozen for the whole TTL on
		// a persistent-object-cache site (Redis/Memcached), showing the same picks on
		// every reload; sampling on each render keeps the widget rotating regardless
		// of the object cache — matching the uncached space-suggestion sidebar.
		$pool_size = max( $limit * 4, $limit + 6 );
		// v3 key: caches the POOL (not the display sample).
		$pool = (array) $this->cache->get(
			'suggested-pool-v3:' . $user_id . ':' . $pool_size,
			WidgetCache::GROUP_USER,
			WidgetCache::TTL_USER,
			static function () use ( $user_id, $pool_size ): array {
				global $wpdb;

				// Prefer the friends-of-friends suggestions — the same algorithm the
				// GET /follow-suggestions REST endpoint serves (FollowService::
				// suggestions(), which already excludes self, current follows and
				// suspended/shadow-banned users) — so the web widget and the app
				// share one source. Backfill with a random discovery pool when the
				// graph is too sparse to fill the slots.
				$candidate_ids = array();
				$follow_svc    = buddynext_service( 'follows' );
				if ( is_object( $follow_svc ) && method_exists( $follow_svc, 'suggestions' ) ) {
					// Take the TOP of the ranked pool (deterministic here) — the
					// per-load rotation happens when suggested_follows() samples this
					// cached pool on each render, not inside the cache.
					$candidate_ids = array_slice( array_map( 'intval', (array) $follow_svc->suggestions( $user_id ) ), 0, $pool_size );
				}

				if ( count( $candidate_ids ) < $pool_size ) {
					$need          = $pool_size - count( $candidate_ids );
					$exclude       = array_merge( array( $user_id ), $candidate_ids );
					$candidate_ids = array_merge(
						$candidate_ids,
						self::random_discovery_fill( $user_id, $need, $exclude )
					);
				}

				if ( empty( $candidate_ids ) ) {
					return array();
				}

				// Hydrate display fields, preserving candidate order (FoF first).
				// $ids_ph is a "%d,..." string from array_fill( count( $candidate_ids ) ),
				// bound through the $candidate_ids array; the literal placeholders live
				// inside it, so the analyser reports UnfinishedPrepare.
				$ids_ph = implode( ',', array_fill( 0, count( $candidate_ids ), '%d' ) );
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$hydrated = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT u.ID, u.display_name, u.user_login, u.user_nicename FROM ' . $wpdb->users . ' u WHERE u.ID IN (' . $ids_ph . ')',
						$candidate_ids
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

				$by_id = array();
				foreach ( (array) $hydrated as $h ) {
					$by_id[ (int) $h->ID ] = $h;
				}
				$rows = array();
				foreach ( $candidate_ids as $cid ) {
					if ( isset( $by_id[ $cid ] ) ) {
						$rows[] = $by_id[ $cid ];
					}
				}

				// Hydrate follow_status per row — unfollowed | requested | following.
				// Since we just filtered out current follows, "following" can still
				// occur if the cache populated mid-cycle. "requested" reads any
				// pending connection request the viewer sent to that user.
				// Both reads are constrained to the handful of candidate rows —
				// the unconstrained versions loaded the viewer's ENTIRE
				// following / pending sets on every logged-in sidebar render,
				// growing with their follow count, only to in_array() them
				// against these few candidates.
				if ( ! empty( $rows ) ) {
					$row_ids = array_map( static fn( $r ) => (int) ( $r->ID ?? 0 ), $rows );
					$row_ph  = implode( ',', array_fill( 0, count( $row_ids ), '%d' ) );

					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $row_ph is a counted "%d,..." list; every value is bound.
					$pending = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT recipient_id FROM {$wpdb->prefix}bn_connections
							 WHERE requester_id = %d AND status = 'pending'
							   AND recipient_id IN ( {$row_ph} )",
							array_merge( array( $user_id ), $row_ids )
						)
					);
					$pending = array_map( 'intval', (array) $pending );

					$following = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT following_id FROM {$wpdb->prefix}bn_follows
							 WHERE follower_id = %d
							   AND following_id IN ( {$row_ph} )",
							array_merge( array( $user_id ), $row_ids )
						)
					);
					$following = array_map( 'intval', (array) $following );
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

					foreach ( $rows as &$row ) {
						$row_id             = (int) ( $row->ID ?? 0 );
						$row->follow_status = in_array( $row_id, $following, true )
							? 'following'
							: ( in_array( $row_id, $pending, true ) ? 'requested' : 'unfollowed' );
					}
					unset( $row );
				}

				return $rows;
			}
		);

		// Per-load rotation: sample the display set from the top of the cached POOL
		// on every render, so the widget varies each load even when the pool is
		// served from a persistent object cache (the sample is never itself cached).
		return buddynext_sample_ranked( $pool, $limit );
	}

	/**
	 * "This week" engagement stat block for the notifications sidebar.
	 *
	 * Returns the four week-over-week metrics the sidebar-this-week-stats
	 * part renders, plus the derived WoW delta + read-rate labels, so the
	 * template stays SQL-free. Each metric still passes through its
	 * buddynext_user_weekly_* filter first, letting a gamification plugin
	 * (wb-gamification) supply the canonical value before BN's inline COUNT
	 * runs as a fallback.
	 *
	 * @param int $user_id Viewer user ID. 0 returns an empty block.
	 * @return array{
	 *     notifications:int,
	 *     read:int,
	 *     new_followers:int,
	 *     engagement:int,
	 *     wow_delta_label:string,
	 *     wow_trend:string,
	 *     read_rate_label:string
	 * }
	 */
	public function weekly_stats( int $user_id ): array {
		$user_id = max( 0, $user_id );
		if ( 0 === $user_id ) {
			return array(
				'notifications'   => 0,
				'read'            => 0,
				'new_followers'   => 0,
				'engagement'      => 0,
				'wow_delta_label' => '',
				'wow_trend'       => 'flat',
				'read_rate_label' => '',
			);
		}

		return (array) $this->cache->get(
			'weekly-stats:' . $user_id,
			WidgetCache::GROUP_USER,
			WidgetCache::TTL_USER,
			static function () use ( $user_id ): array {
				global $wpdb;

				// Notifications received this week + the prior week (for the WoW
				// delta). Each metric goes through a buddynext_user_weekly_*
				// filter so a gamification plugin can replace BN's inline COUNT
				// with its canonical value. The default branch runs only when the
				// filter returns null (meaning: nobody overrode it).
				$notifs_7d = apply_filters( 'buddynext_user_weekly_notifications_count', null, $user_id );
				if ( null === $notifs_7d ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$notifs_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				$notifs_7d = (int) $notifs_7d;

				$notifs_prev_7d = apply_filters( 'buddynext_user_weekly_notifications_prev_count', null, $user_id );
				if ( null === $notifs_prev_7d ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$notifs_prev_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 14 DAY ) AND created_at <  DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				$notifs_prev_7d = (int) $notifs_prev_7d;

				$read_7d = apply_filters( 'buddynext_user_weekly_notifications_read_count', null, $user_id );
				if ( null === $read_7d ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$read_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND is_read = 1 AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				$read_7d = (int) $read_7d;

				$new_followers_7d = apply_filters( 'buddynext_user_weekly_followers_gained', null, $user_id );
				if ( null === $new_followers_7d ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$new_followers_7d = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_follows WHERE following_id = %d AND created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id ) );
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				$new_followers_7d = (int) $new_followers_7d;

				$engagement_in_7d = apply_filters( 'buddynext_user_weekly_engagement_received', null, $user_id );
				if ( null === $engagement_in_7d ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$reactions_in_7d  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions r INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = r.object_id WHERE r.object_type = 'post' AND p.user_id = %d AND r.user_id != %d AND r.created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id, $user_id ) );
					$comments_in_7d   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments c INNER JOIN {$wpdb->prefix}bn_posts p ON p.id = c.object_id WHERE c.object_type = 'post' AND p.user_id = %d AND c.user_id != %d AND c.created_at >= DATE_SUB( NOW(), INTERVAL 7 DAY )", $user_id, $user_id ) );
					$engagement_in_7d = $reactions_in_7d + $comments_in_7d;
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				$engagement_in_7d = (int) $engagement_in_7d;

				// Week-over-week delta percent. Suppressed when prior-week is 0
				// (can't divide by zero, and "first week" deltas are misleading).
				$wow_delta_label = '';
				$wow_trend       = 'flat';
				if ( $notifs_prev_7d > 0 ) {
					$pct = (int) round( ( ( $notifs_7d - $notifs_prev_7d ) / $notifs_prev_7d ) * 100 );
					if ( 0 !== $pct ) {
						$wow_delta_label = ( $pct > 0 ? '+' : '' ) . $pct . '%';
						$wow_trend       = $pct > 0 ? 'up' : 'down';
					}
				}

				// Read-rate as a percent label. Empty when no notifs to read.
				$read_rate_label = '';
				if ( $notifs_7d > 0 ) {
					$read_rate_label = (int) round( ( $read_7d / $notifs_7d ) * 100 ) . '%';
				}

				return array(
					'notifications'   => $notifs_7d,
					'read'            => $read_7d,
					'new_followers'   => $new_followers_7d,
					'engagement'      => $engagement_in_7d,
					'wow_delta_label' => $wow_delta_label,
					'wow_trend'       => $wow_trend,
					'read_rate_label' => $read_rate_label,
				);
			}
		);
	}

	/**
	 * Up to N spaces relevant to the viewer.
	 *
	 * Logged-in viewers see joined spaces sorted by member count. Guests
	 * see top open spaces.
	 *
	 * @param int $user_id Viewer user ID. 0 = guest.
	 * @param int $limit   Max rows.
	 * @return array<int,object>
	 */
	public function joined_spaces( int $user_id, int $limit = 4 ): array {
		$user_id = max( 0, $user_id );
		$limit   = max( 1, min( $limit, 20 ) );
		return (array) $this->cache->get(
			'spaces:' . $user_id . ':' . $limit,
			WidgetCache::GROUP_USER,
			WidgetCache::TTL_USER,
			static function () use ( $user_id, $limit ): array {
				global $wpdb;
				if ( $user_id > 0 ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					// Detect optional unread_count column on bn_space_members so the
					// sidebar can render an unread dot without breaking on schemas
					// that have not yet added the column. SHOW COLUMNS is cheap and
					// doesn't trip InnoDB transaction-DDL deadlocks the way
					// INFORMATION_SCHEMA can on shared test runners.
					$columns_cache_key = 'unread_count_col_exists';
					$has_unread        = wp_cache_get( $columns_cache_key, WidgetCache::GROUP_GLOBAL );
					if ( false === $has_unread ) {
						$row        = $wpdb->get_row(
							"SHOW COLUMNS FROM {$wpdb->prefix}bn_space_members LIKE 'unread_count'"
						);
						$has_unread = null !== $row ? 1 : 0;
						// cache-ttl-only: caches whether a COLUMN exists (SHOW COLUMNS). Schema introspection, correctly global, and it changes only on upgrade.
						wp_cache_set( $columns_cache_key, $has_unread, WidgetCache::GROUP_GLOBAL, HOUR_IN_SECONDS );
					}
					$has_unread = (bool) $has_unread;

					if ( $has_unread ) {
						$rows = $wpdb->get_results(
							$wpdb->prepare(
								'SELECT s.id, s.name, s.slug, s.member_count, s.avatar_url, sm.unread_count
								 FROM ' . $wpdb->prefix . 'bn_spaces s
								 INNER JOIN ' . $wpdb->prefix . 'bn_space_members sm
								   ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = %s
								 ORDER BY s.member_count DESC
								 LIMIT %d',
								$user_id,
								'active',
								$limit
							)
						);
					} else {
						$rows = $wpdb->get_results(
							$wpdb->prepare(
								'SELECT s.id, s.name, s.slug, s.member_count, s.avatar_url
								 FROM ' . $wpdb->prefix . 'bn_spaces s
								 INNER JOIN ' . $wpdb->prefix . 'bn_space_members sm
								   ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = %s
								 ORDER BY s.member_count DESC
								 LIMIT %d',
								$user_id,
								'active',
								$limit
							)
						);
					}
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				} else {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$rows = $wpdb->get_results(
						$wpdb->prepare(
							'SELECT id, name, slug, member_count, avatar_url
							 FROM ' . $wpdb->prefix . 'bn_spaces
							 WHERE type = %s
							 ORDER BY member_count DESC
							 LIMIT %d',
							'open',
							$limit
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				return is_array( $rows ) ? $rows : array();
			}
		);
	}

	/**
	 * Fill the "people to follow" slots with a random-ish sample of members.
	 *
	 * This used to be `ORDER BY RAND()` over wp_users with two correlated NOT EXISTS
	 * subqueries — on the sidebar, which renders on EVERY logged-in page. RAND() cannot use
	 * an index: MySQL materialises the whole filtered set, stamps a random number on every
	 * row and filesorts the lot, in order to keep three of them. On a 100k-member community
	 * that is a full scan plus a filesort on the hottest path on the site, and it was masked
	 * only by a wp_cache the project explicitly forbids depending on — the standard is that
	 * everything must hold up with the object cache OFF.
	 *
	 * Instead: jump to a random point in the PRIMARY KEY and read forward. Both the range
	 * and the order come from the PK, so there is no filesort and the rows examined are
	 * bounded by the LIMIT rather than by the size of the community. When the random entry
	 * point lands near the end of the table — or in a run of members who are all excluded —
	 * it wraps to the start, so a member does not get an empty widget because of where the
	 * dice fell.
	 *
	 * This is a SAMPLE, not a shuffle. Two members can be offered the same faces, and the
	 * spread is only as even as the id space is dense. That is the right trade for a
	 * discovery widget: nobody can tell, and nobody should pay for a filesort of the whole
	 * community to make "people you might like" statistically pure.
	 *
	 * @param int             $user_id Viewer.
	 * @param int             $need    How many ids are still needed.
	 * @param array<int, int> $exclude Ids already spoken for (self + the FoF suggestions).
	 * @return array<int, int>
	 */
	private static function random_discovery_fill( int $user_id, int $need, array $exclude ): array {
		global $wpdb;

		if ( $need < 1 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->users}" );

		if ( $max_id < 1 ) {
			return array();
		}

		$start = wp_rand( 1, $max_id );
		$found = array();

		// Pass 1 reads forward from the random point; pass 2 wraps around to the start and
		// picks up whatever is still missing.
		foreach ( array( array( '>=', $start ), array( '<', $start ) ) as $pass ) {
			$still_need = $need - count( $found );

			if ( $still_need < 1 ) {
				break;
			}

			list( $op, $boundary ) = $pass;

			$skip    = array_values( array_unique( array_merge( $exclude, $found ) ) );
			$skip_ph = implode( ',', array_fill( 0, count( $skip ), '%d' ) );

			// $skip_ph is a counted "%d,..." list and $op is one of two literals chosen
			// here, never user input; every value is bound through the merged array.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT u.ID
					 FROM ' . $wpdb->users . ' u
					 WHERE u.ID ' . $op . ' %d
					   AND u.ID NOT IN (' . $skip_ph . ')
					   AND NOT EXISTS (
						   SELECT 1 FROM ' . $wpdb->prefix . 'bn_follows f
						   WHERE f.follower_id = %d AND f.following_id = u.ID
					   )
					   AND NOT EXISTS (
						   SELECT 1 FROM ' . $wpdb->prefix . 'bn_blocks bl
						   WHERE ( bl.blocker_id = %d AND bl.blocked_id = u.ID )
							  OR ( bl.blocker_id = u.ID AND bl.blocked_id = %d )
					   )
					   AND NOT EXISTS (
						   SELECT 1 FROM ' . $wpdb->prefix . 'bn_user_suspensions s_ex
						   WHERE s_ex.user_id = u.ID
							 AND s_ex.lifted_at IS NULL
							 AND ( s_ex.expires_at IS NULL OR s_ex.expires_at > UTC_TIMESTAMP() )
					   )
					   AND NOT EXISTS (
						   SELECT 1 FROM ' . $wpdb->usermeta . " um_ban
						   WHERE um_ban.user_id = u.ID
							 AND um_ban.meta_key = 'bn_shadow_banned'
							 AND um_ban.meta_value = '1'
					   )
					 ORDER BY u.ID ASC
					 LIMIT %d",
					array_merge(
						array( $boundary ),
						$skip,
						array( $user_id, $user_id, $user_id, $still_need )
					)
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			$found = array_merge( $found, array_map( 'intval', (array) $ids ) );
		}

		return $found;
	}
}
