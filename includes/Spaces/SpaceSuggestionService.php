<?php
/**
 * BuddyNext — Suggested spaces (discovery ranking).
 *
 * Ranks spaces a member might want to join from three cheap, index-backed signals,
 * merged and re-scored in PHP:
 *   1. Social proof (weight 3) — spaces that people the viewer follows are active
 *      members of (bn_follows -> bn_space_members).
 *   2. Category affinity (weight 2) — spaces in the categories of the spaces the
 *      viewer already belongs to, unioned with the viewer's explicit interest
 *      picks (system 'interests' profile field) so the signal works before
 *      they have joined anything.
 *   3. Popularity (weight ~1) — member_count, the cold-start fallback.
 *
 * Candidates are hydrated through SpaceService::list_spaces() with the viewer scope,
 * so secret/unseeable spaces are filtered exactly as the directory filters them; the
 * viewer's own spaces are excluded. Results are cached per viewer (short TTL +
 * explicit bust on the viewer's join/leave/follow, see SpaceSuggestionListener).
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

/**
 * Suggested-spaces ranking engine.
 */
final class SpaceSuggestionService {

	private const CACHE_GROUP    = 'buddynext_space_suggestions';
	private const CACHE_TTL      = 300; // 5 minutes — backstop behind explicit busts.
	private const CANDIDATE_POOL = 25;  // per-signal candidate cap before merge.
	private const FOLLOW_SCAN    = 200; // bound the followed-set scan.

	private const W_SOCIAL   = 3;
	private const W_CATEGORY = 2;

	/**
	 * Ranked, hydrated suggested-space rows for a viewer (empty when nothing fits).
	 *
	 * @param int $user_id Viewer user ID.
	 * @param int $limit   Maximum suggestions.
	 * @return array<int,array<string,mixed>>
	 */
	public function suggest( int $user_id, int $limit = 6 ): array {
		$user_id = (int) $user_id;
		$limit   = max( 1, min( 24, $limit ) );
		if ( $user_id <= 0 ) {
			return array();
		}

		$cache_key = $user_id . ':' . $limit . ':' . $this->cache_version( $user_id );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $this->apply_suggestions_filter( $cached, $user_id, $limit );
		}

		$rows = $this->rank( $user_id, $limit );
		wp_cache_set( $cache_key, $rows, self::CACHE_GROUP, self::CACHE_TTL );
		return $this->apply_suggestions_filter( $rows, $user_id, $limit );
	}

	/**
	 * Run the buddynext_space_suggestions seam over the final ranked id list.
	 *
	 * Applied outside the cache on every call (parity with the follow engine's
	 * buddynext_follow_suggestions seam) so a Pro reranker is never frozen
	 * into Free's cache. Rows are reordered to the filtered id order; ids the
	 * filter injects that were not in the ranked set are hydrated
	 * visibility-safe in one batched list_spaces() call.
	 *
	 * @param array<int,array<string,mixed>> $rows    Ranked, hydrated rows.
	 * @param int                            $user_id Viewer user ID.
	 * @param int                            $limit   Maximum suggestions.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply_suggestions_filter( array $rows, int $user_id, int $limit ): array {
		$ranked_ids = array_map( static fn( $r ) => (int) $r['id'], $rows );

		/**
		 * Filter the suggested-spaces ranked id list.
		 *
		 * @param int[] $ranked_ids Suggested space IDs in rank order.
		 * @param int   $user_id    The user the suggestions are for.
		 */
		$filtered = (array) apply_filters( 'buddynext_space_suggestions', $ranked_ids, $user_id );
		$filtered = array_values( array_unique( array_map( 'intval', $filtered ) ) );

		if ( $filtered === $ranked_ids ) {
			return $rows;
		}

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['id'] ] = $row;
		}

		// Hydrate filter-injected ids through the same viewer-scoped path as
		// the ranked candidates, so a reranker can never surface a space the
		// viewer must not see.
		$missing = array_values( array_diff( $filtered, array_keys( $by_id ) ) );
		if ( ! empty( $missing ) ) {
			$extra = ( new SpaceService() )->list_spaces(
				array(
					'include_space_ids' => $missing,
					'viewer'            => $user_id,
					'roots_only'        => true,
					'per_page'          => count( $missing ),
				)
			);
			foreach ( (array) $extra as $row ) {
				$by_id[ (int) $row['id'] ] = $row;
			}
		}

		$out = array();
		foreach ( $filtered as $sid ) {
			if ( isset( $by_id[ $sid ] ) ) {
				$out[] = $by_id[ $sid ];
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Ranked suggested-space IDs only.
	 *
	 * @param int $user_id Viewer user ID.
	 * @param int $limit   Maximum suggestions.
	 * @return int[]
	 */
	public function suggest_ids( int $user_id, int $limit = 6 ): array {
		return array_map( static fn( $r ) => (int) $r['id'], $this->suggest( $user_id, $limit ) );
	}

	/**
	 * Flush a viewer's cached suggestions (called on their join/leave/follow).
	 *
	 * @param int $user_id Viewer user ID.
	 * @return void
	 */
	public function flush_for_user( int $user_id ): void {
		// Bumping a per-user version salt (embedded in the cache key) is the cheapest
		// portable invalidation without an object-cache group flush.
		wp_cache_set( 'ver:' . (int) $user_id, (string) $this->cache_version( $user_id ) . '.' . wp_rand( 1, 99999 ), self::CACHE_GROUP );
	}

	/**
	 * Current per-user cache-version salt (seeded on first read).
	 *
	 * @param int $user_id Viewer user ID.
	 * @return string
	 */
	private function cache_version( int $user_id ): string {
		$ver = wp_cache_get( 'ver:' . $user_id, self::CACHE_GROUP );
		if ( ! is_string( $ver ) || '' === $ver ) {
			$ver = '1';
			wp_cache_set( 'ver:' . $user_id, $ver, self::CACHE_GROUP );
		}
		return $ver;
	}

	/**
	 * Build the ranked candidate set.
	 *
	 * @param int $user_id Viewer user ID.
	 * @param int $limit   Maximum suggestions.
	 * @return array<int,array<string,mixed>>
	 */
	private function rank( int $user_id, int $limit ): array {
		$mine = ( new SpaceMemberService() )->spaces_for_user( $user_id );
		$mine = array_map( 'intval', (array) $mine );

		$my_categories = $this->my_categories( $user_id, $mine );
		$social        = $this->social_proof_counts( $user_id, $mine );

		$candidate_ids = array_values(
			array_unique(
				array_merge(
					array_keys( $social ),
					$this->popular_ids( $mine ),
					// Category affinity contributes CANDIDATES too, not just a
					// re-score: an interest-matched space that is not already
					// top-N popular could otherwise never surface — exactly the
					// cold-start case the interest picks exist to solve.
					$this->category_candidate_ids( $my_categories, $mine )
				)
			)
		);
		if ( empty( $candidate_ids ) ) {
			return array();
		}

		// Hydrate visibility-safe (viewer scope filters secret/unseeable spaces); root
		// spaces only — sub-spaces are discovered from their parent, not suggested.
		$rows = ( new SpaceService() )->list_spaces(
			array(
				'include_space_ids' => $candidate_ids,
				// Defense-in-depth: candidates are already built excluding $mine, but
				// excluding again at the hydration guarantees a joined space can never
				// surface as a suggestion even if a signal query changes.
				'exclude_space_ids' => $mine,
				'viewer'            => $user_id,
				'roots_only'        => true,
				'per_page'          => count( $candidate_ids ),
			)
		);
		if ( empty( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$sid              = (int) $row['id'];
			$social_score     = self::W_SOCIAL * (int) ( $social[ $sid ] ?? 0 );
			$category_score   = ( ! empty( $row['category_id'] ) && in_array( (int) $row['category_id'], $my_categories, true ) )
				? self::W_CATEGORY : 0;
			$popularity       = min( 100, (int) ( $row['member_count'] ?? 0 ) ) / 100; // 0..1 tiebreaker.
			$row['_bn_score'] = $social_score + $category_score + $popularity;
		}
		unset( $row );

		usort(
			$rows,
			static fn( $a, $b ) => ( $b['_bn_score'] <=> $a['_bn_score'] )
		);

		return $this->diversify( $rows, $limit );
	}

	/**
	 * Category ids the viewer has an affinity for: the union of the categories
	 * of their joined spaces (implicit interest from behavior) and their
	 * explicit interest picks (the system 'interests' profile field).
	 *
	 * The picks make the category-affinity signal work from minute zero —
	 * before the member has joined anything — so onboarding's "spaces to
	 * join" step ranks with the picks made one step earlier. Blank picks
	 * leave this exactly as the joined-space categories (additive signal).
	 *
	 * @param int   $user_id Viewer user ID.
	 * @param int[] $mine    Joined space ids.
	 * @return int[]
	 */
	private function my_categories( int $user_id, array $mine ): array {
		$cats = array();

		if ( ! empty( $mine ) ) {
			global $wpdb;
			$in = implode( ',', array_map( 'absint', $mine ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- ids are absint-cast; result is cached by the caller.
			$cats = (array) $wpdb->get_col( "SELECT DISTINCT category_id FROM {$wpdb->prefix}bn_spaces WHERE id IN ({$in}) AND category_id IS NOT NULL" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$picks = ( new \BuddyNext\Onboarding\OnboardingService() )->get_interest_ids( $user_id );

		return array_values( array_unique( array_merge( array_map( 'intval', $cats ), $picks ) ) );
	}

	/**
	 * Space id => follower-count among the people the viewer follows.
	 *
	 * @param int   $user_id Viewer user ID.
	 * @param int[] $mine    Joined space ids to exclude.
	 * @return array<int,int>
	 */
	private function social_proof_counts( int $user_id, array $mine ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$followed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT following_id FROM {$wpdb->prefix}bn_follows WHERE follower_id = %d AND status = 'approved' LIMIT %d",
				$user_id,
				self::FOLLOW_SCAN
			)
		);
		$followed = array_map( 'intval', (array) $followed );
		if ( empty( $followed ) ) {
			return array();
		}

		$in_followed = implode( ',', $followed );
		$not_mine    = empty( $mine ) ? '' : ' AND sm.space_id NOT IN (' . implode( ',', array_map( 'absint', $mine ) ) . ')';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- followed/own ids are int-cast; LIMIT is a class const int; result is cached by the caller.
		$rows = $wpdb->get_results(
			"SELECT sm.space_id, COUNT(*) AS c FROM {$wpdb->prefix}bn_space_members sm
			 WHERE sm.user_id IN ({$in_followed}) AND sm.status = 'active'{$not_mine}
			 GROUP BY sm.space_id ORDER BY c DESC LIMIT " . self::CANDIDATE_POOL,
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (int) $r['space_id'] ] = (int) $r['c'];
		}
		return $out;
	}

	/**
	 * Top spaces (by member_count) inside the viewer's affinity categories,
	 * excluding the viewer's own — the category signal's candidate pool.
	 *
	 * Rides the bn_spaces category index; bounded by CANDIDATE_POOL; result
	 * is cached by the caller like the other signal pools.
	 *
	 * @param int[] $categories Affinity category ids (joined + interest picks).
	 * @param int[] $mine       Joined space ids to exclude.
	 * @return int[]
	 */
	private function category_candidate_ids( array $categories, array $mine ): array {
		$categories = array_values( array_filter( array_map( 'absint', $categories ) ) );
		if ( empty( $categories ) ) {
			return array();
		}

		global $wpdb;
		$in_cats  = implode( ',', $categories );
		$not_mine = empty( $mine ) ? '' : ' AND id NOT IN (' . implode( ',', array_map( 'absint', $mine ) ) . ')';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- category/own ids are absint-cast; LIMIT is a class const int; result is cached by the caller.
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}bn_spaces
			 WHERE is_archived = 0 AND parent_id IS NULL AND category_id IN ({$in_cats}){$not_mine}
			 ORDER BY member_count DESC LIMIT " . self::CANDIDATE_POOL
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Top spaces by member_count, excluding the viewer's own.
	 *
	 * @param int[] $mine Joined space ids to exclude.
	 * @return int[]
	 */
	private function popular_ids( array $mine ): array {
		global $wpdb;
		$not_mine = empty( $mine ) ? '' : ' AND id NOT IN (' . implode( ',', array_map( 'absint', $mine ) ) . ')';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- own ids are absint-cast; LIMIT is a class const int; result is cached by the caller.
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}bn_spaces
			 WHERE is_archived = 0 AND parent_id IS NULL{$not_mine}
			 ORDER BY member_count DESC LIMIT " . self::CANDIDATE_POOL
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Take the top $limit rows, capping any single category at two so one category
	 * can't fill the whole rail; backfill from the remainder once the caps are hit.
	 *
	 * @param array<int,array<string,mixed>> $rows  Score-sorted rows.
	 * @param int                            $limit Maximum to return.
	 * @return array<int,array<string,mixed>>
	 */
	private function diversify( array $rows, int $limit ): array {
		$picked   = array();
		$deferred = array();
		$per_cat  = array();
		foreach ( $rows as $row ) {
			$cat   = (int) ( $row['category_id'] ?? 0 );
			$count = $per_cat[ $cat ] ?? 0;
			if ( $cat > 0 && $count >= 2 ) {
				$deferred[] = $row;
				continue;
			}
			$per_cat[ $cat ] = $count + 1;
			$picked[]        = $row;
			if ( count( $picked ) >= $limit ) {
				return $picked;
			}
		}
		foreach ( $deferred as $row ) {
			$picked[] = $row;
			if ( count( $picked ) >= $limit ) {
				break;
			}
		}
		return array_slice( $picked, 0, $limit );
	}
}
