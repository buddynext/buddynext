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
	 * pairs. ORDER BY RAND() is expensive at scale; the cache absorbs the
	 * worst case. P2.1 (AI signals) will replace this with a precomputed
	 * affinity-ranked candidate pool when AI Feed is enabled.
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
		// Cache key bumped to v2 to invalidate after the follow_status payload change.
		return (array) $this->cache->get(
			'suggested-v2:' . $user_id . ':' . $limit,
			WidgetCache::GROUP_USER,
			WidgetCache::TTL_USER,
			static function () use ( $user_id, $limit ): array {
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
					$candidate_ids = array_slice( array_map( 'intval', (array) $follow_svc->suggestions( $user_id ) ), 0, $limit );
				}

				if ( count( $candidate_ids ) < $limit ) {
					$need    = $limit - count( $candidate_ids );
					$exclude = array_merge( array( $user_id ), $candidate_ids );
					// $exclude_ph is a "%d,..." string from array_fill( count( $exclude ) );
					// every value (the exclude list plus the four trailing %d) is bound via
					// the merged array, so the dynamic placeholder count trips
					// ReplacementsWrongNumber even though the binding is correct.
					$exclude_ph = implode( ',', array_fill( 0, count( $exclude ), '%d' ) );
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
					$fill_ids = $wpdb->get_col(
						$wpdb->prepare(
							'SELECT u.ID
							 FROM ' . $wpdb->users . ' u
							 WHERE u.ID NOT IN (' . $exclude_ph . ')
							   AND NOT EXISTS (
								   SELECT 1 FROM ' . $wpdb->prefix . 'bn_follows f
								   WHERE f.follower_id = %d AND f.following_id = u.ID
							   )
							   AND NOT EXISTS (
								   SELECT 1 FROM ' . $wpdb->prefix . 'bn_blocks bl
								   WHERE ( bl.blocker_id = %d AND bl.blocked_id = u.ID )
									  OR ( bl.blocker_id = u.ID AND bl.blocked_id = %d )
							   )
							 ORDER BY RAND()
							 LIMIT %d',
							array_merge( $exclude, array( $user_id, $user_id, $user_id, $need ) )
						)
					);
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
					$candidate_ids = array_merge( $candidate_ids, array_map( 'intval', (array) $fill_ids ) );
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
						'SELECT u.ID, u.display_name, u.user_login FROM ' . $wpdb->users . ' u WHERE u.ID IN (' . $ids_ph . ')',
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
				if ( ! empty( $rows ) ) {
					$pending = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT recipient_id FROM {$wpdb->prefix}bn_connections
							 WHERE requester_id = %d AND status = 'pending'",
							$user_id
						)
					);
					$pending = array_map( 'intval', (array) $pending );

					$following = $wpdb->get_col(
						$wpdb->prepare(
							"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d",
							$user_id
						)
					);
					$following = array_map( 'intval', (array) $following );

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
}
