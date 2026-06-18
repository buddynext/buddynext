<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Activity-streak data service.
 *
 * Owns the "current streak / best-this-month / 7-day strip" computation
 * the greeting-streak sidebar card renders. Keeps the heavy 30-day UNION
 * over bn_posts / bn_comments / bn_reactions plus the consecutive-day
 * streak math out of the template, behind a cached per-user summary.
 *
 * The gamification-bridge seams stay here: wb-gamification (or any
 * equivalent plugin) can supply the canonical active-date set and the
 * streak / best-month values by hooking the buddynext_user_active_dates,
 * buddynext_user_activity_streak and buddynext_user_activity_best_month_streak
 * filters — BN's inline computation runs only as the fallback.
 *
 * @package BuddyNext\Engagement
 */

declare( strict_types=1 );

namespace BuddyNext\Engagement;

defined( 'ABSPATH' ) || exit;

/**
 * Computes a viewer's activity-streak summary for the sidebar card.
 */
class StreakService {

	/**
	 * Cache group for per-user streak summaries.
	 */
	private const CACHE_GROUP = 'buddynext_user_meta';

	/**
	 * Summary TTL in seconds. Streaks turn over at most once per day, but a
	 * short TTL keeps the strip honest as the user is active mid-session.
	 */
	private const CACHE_TTL = 300;

	/**
	 * Trailing window (days) scanned for activity.
	 */
	private const WINDOW_DAYS = 30;

	/**
	 * Streak summary for a viewer.
	 *
	 * @param int $uid Viewing user ID. 0 returns an empty summary.
	 * @return array{streak:int,best:int,strip:array<int,array<string,mixed>>,active_map:array<string,bool>}
	 */
	public function summary( int $uid ): array {
		$uid = max( 0, $uid );
		if ( 0 === $uid ) {
			return array(
				'streak'     => 0,
				'best'       => 0,
				'strip'      => array(),
				'active_map' => array(),
			);
		}

		$cache_key = 'streak-summary:' . $uid;
		$found     = false;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP, false, $found );
		if ( true === $found ) {
			return (array) $cached;
		}

		$active_map = $this->active_map( $uid );

		$summary = array(
			'streak'     => $this->current_streak( $uid, $active_map ),
			'best'       => $this->best_month_streak( $uid, $active_map ),
			'strip'      => $this->strip( $active_map ),
			'active_map' => $active_map,
		);

		wp_cache_set( $cache_key, $summary, self::CACHE_GROUP, self::CACHE_TTL );

		return $summary;
	}

	/**
	 * Build the active-date lookup map (`'2026-05-25' => true`).
	 *
	 * Sources the date set from the buddynext_user_active_dates filter when a
	 * plugin overrides it; otherwise runs the inline 30-day UNION over
	 * bn_posts / bn_comments / bn_reactions (the simplest "did anything
	 * social" definition).
	 *
	 * @param int $uid User ID.
	 * @return array<string,bool>
	 */
	private function active_map( int $uid ): array {
		$dates_filter = apply_filters( 'buddynext_user_active_dates', null, $uid, self::WINDOW_DAYS );

		if ( is_array( $dates_filter ) ) {
			// Filter took over — trust the returned date list.
			$dates = $dates_filter;
		} else {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$dates = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT activity_date FROM (
					   SELECT DATE(created_at) AS activity_date
					     FROM {$wpdb->prefix}bn_posts
					    WHERE user_id = %d AND status = 'published' AND created_at >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )
					   UNION
					   SELECT DATE(created_at) AS activity_date
					     FROM {$wpdb->prefix}bn_comments
					    WHERE user_id = %d AND created_at >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )
					   UNION
					   SELECT DATE(created_at) AS activity_date
					     FROM {$wpdb->prefix}bn_reactions
					    WHERE user_id = %d AND created_at >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )
					 ) AS d
					 ORDER BY activity_date DESC",
					$uid,
					$uid,
					$uid
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$map = array();
		foreach ( $dates as $d ) {
			$map[ (string) $d ] = true;
		}

		return $map;
	}

	/**
	 * Current streak = consecutive trailing days ending today (or yesterday
	 * if the user hasn't been active yet today — being inactive on a still-
	 * in-progress current day shouldn't break the streak).
	 *
	 * @param int                $uid        User ID.
	 * @param array<string,bool> $active_map Active-date lookup.
	 * @return int
	 */
	private function current_streak( int $uid, array $active_map ): int {
		$today_str     = current_time( 'Y-m-d' );
		$yesterday_str = gmdate( 'Y-m-d', strtotime( $today_str . ' -1 day' ) );
		$start         = isset( $active_map[ $today_str ] )
			? $today_str
			: ( isset( $active_map[ $yesterday_str ] ) ? $yesterday_str : '' );

		$streak = 0;
		if ( '' !== $start ) {
			$cursor = $start;
			while ( isset( $active_map[ $cursor ] ) ) {
				++$streak;
				$cursor = gmdate( 'Y-m-d', strtotime( $cursor . ' -1 day' ) );
			}
		}

		/**
		 * Filter the current streak (consecutive trailing active days) for a
		 * user. Default = inline-computed from the date map. Plugins like
		 * wb-gamification that maintain their own canonical streak counter
		 * should hook this and return their value so BN's widget stays in sync
		 * with the source of truth.
		 *
		 * @param int $streak  Default-computed streak (0+ days).
		 * @param int $user_id User whose streak to compute.
		 */
		return (int) apply_filters( 'buddynext_user_activity_streak', $streak, $uid );
	}

	/**
	 * Best streak this month = longest consecutive-day run in the active set
	 * restricted to the current calendar month.
	 *
	 * @param int                $uid        User ID.
	 * @param array<string,bool> $active_map Active-date lookup.
	 * @return int
	 */
	private function best_month_streak( int $uid, array $active_map ): int {
		$month_prefix = current_time( 'Y-m' );
		$month_days   = array();
		foreach ( array_keys( $active_map ) as $d ) {
			if ( 0 === strpos( (string) $d, $month_prefix ) ) {
				$month_days[] = (string) $d;
			}
		}
		sort( $month_days );

		$best    = 0;
		$running = 0;
		$prev_d  = '';
		foreach ( $month_days as $d ) {
			$expected_prev = gmdate( 'Y-m-d', strtotime( $d . ' -1 day' ) );
			$running       = ( '' !== $prev_d && $prev_d === $expected_prev ) ? ( $running + 1 ) : 1;
			$best          = max( $best, $running );
			$prev_d        = $d;
		}

		/**
		 * Filter the best streak achieved this calendar month.
		 *
		 * @param int $best    Default-computed best (0+ days).
		 * @param int $user_id User whose best-streak to compute.
		 */
		return (int) apply_filters( 'buddynext_user_activity_best_month_streak', $best, $uid );
	}

	/**
	 * 7-day strip cells, oldest -> newest left -> right.
	 *
	 * @param array<string,bool> $active_map Active-date lookup.
	 * @return array<int,array{date:string,letter:string,is_today:bool,active:bool}>
	 */
	private function strip( array $active_map ): array {
		$today_str = current_time( 'Y-m-d' );
		$strip     = array();
		for ( $i = 6; $i >= 0; $i-- ) {
			$date    = gmdate( 'Y-m-d', strtotime( $today_str . ' -' . $i . ' day' ) );
			$strip[] = array(
				'date'     => $date,
				'letter'   => date_i18n( 'D', strtotime( $date ) )[0], // First letter of localized day name.
				'is_today' => $date === $today_str,
				'active'   => isset( $active_map[ $date ] ),
			);
		}

		return $strip;
	}
}
