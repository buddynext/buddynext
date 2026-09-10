<?php
/**
 * Cross-space activity read — a paginated, newest-first stream across a set of
 * spaces (or a space category), in the shape the Wellbee Circles Studio block and
 * a Circle Admin audit log consume (card 10276234812).
 *
 * The activity data lives in BuddyNext and the wider suite, so an addon must NOT
 * read bn_mod_log / bn_space_members directly. This service is the single read:
 * it merges the two BuddyNext sources it owns — moderation-log actions and
 * membership joins — and exposes a filter seam so other domains (e.g. the Pro
 * payments subsystem) contribute their own rows (payments, renewals) without this
 * service knowing about them. Every row is normalised to the documented shape:
 *   { id, icon, avatar, text, occurred_at_utc } — occurred_at_utc is UTC "Y-m-d H:i:s".
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Profile\AvatarService;
use WP_User_Query;

/**
 * Reads a merged, newest-first activity stream across spaces.
 */
class CrossSpaceActivityService {

	/**
	 * Per-source fetch ceiling. The merge is bounded lookback for a recent-activity
	 * feed: each source contributes at most this many recent rows before the merge
	 * and page slice, so deep pagination degrades gracefully rather than scanning
	 * years of rows. Keyset per source would be the upgrade if an audit screen ever
	 * needs to page arbitrarily deep.
	 */
	private const MAX_PER_SOURCE = 500;

	/**
	 * A merged, newest-first page of activity across the given spaces.
	 *
	 * @param array<string,mixed> $args Keys: space_ids (int[], the spaces to read
	 *                                  across, takes precedence); category_id (int,
	 *                                  resolve space_ids from this non-archived space
	 *                                  category when space_ids is empty); page (int,
	 *                                  1-based, default 1); per_page (int, 1-50,
	 *                                  default 20).
	 * @return array{items: array<int, array{id:string, icon:string, avatar:string, text:string, occurred_at_utc:string}>, total: int}
	 */
	public function recent( array $args ): array {
		global $wpdb;

		$space_ids = isset( $args['space_ids'] ) ? (array) $args['space_ids'] : array();
		$space_ids = array_values( array_unique( array_filter( array_map( 'absint', $space_ids ) ) ) );

		$category_id = isset( $args['category_id'] ) ? absint( $args['category_id'] ) : 0;
		if ( empty( $space_ids ) && $category_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$space_ids = array_map(
				'intval',
				(array) $wpdb->get_col(
					$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE category_id = %d AND is_archived = 0", $category_id )
				)
			);
		}

		if ( empty( $space_ids ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$per_page = max( 1, min( 50, isset( $args['per_page'] ) ? (int) $args['per_page'] : 20 ) );
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$offset   = ( $page - 1 ) * $per_page;

		// Fetch enough recent rows from each source to cover the requested page after
		// the merge, capped so no source scans without bound.
		$fetch = min( self::MAX_PER_SOURCE, $offset + $per_page );

		$rows  = $this->moderation_rows( $space_ids, $fetch );
		$rows  = array_merge( $rows, $this->join_rows( $space_ids, $fetch ) );
		$total = $this->moderation_count( $space_ids ) + $this->join_count( $space_ids );

		/**
		 * Contribute additional cross-space activity rows from another domain (e.g.
		 * the Pro payments subsystem adds payment/renewal rows). Return the SAME
		 * normalised shape { id, icon, avatar, text, occurred_at_utc }; this service
		 * merges and sorts them with its own. Return at most $fetch recent rows so
		 * the merge stays bounded.
		 *
		 * @since 1.2.0
		 *
		 * @param array<int,array<string,mixed>> $extra     Rows to contribute (default none).
		 * @param int[]                          $space_ids Spaces in scope.
		 * @param int                            $fetch     Per-source recent-row ceiling.
		 */
		$extra = (array) apply_filters( 'buddynext_cross_space_activity_rows', array(), $space_ids, $fetch );
		if ( ! empty( $extra ) ) {
			$rows = array_merge( $rows, $this->normalise_extra( $extra ) );

			/**
			 * Add the contributed rows' total so the pager count stays honest.
			 *
			 * @since 1.2.0
			 *
			 * @param int   $extra_total Additional total from contributed rows (default 0).
			 * @param int[] $space_ids   Spaces in scope.
			 */
			$total += (int) apply_filters( 'buddynext_cross_space_activity_total', 0, $space_ids );
		}

		// Newest first, id as the stable tie-break, then the requested page.
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$cmp = strcmp( (string) $b['occurred_at_utc'], (string) $a['occurred_at_utc'] );
				return 0 !== $cmp ? $cmp : strcmp( (string) $b['id'], (string) $a['id'] );
			}
		);

		return array(
			'items' => array_slice( $rows, $offset, $per_page ),
			'total' => $total,
		);
	}

	/**
	 * Recent moderation-log rows for the spaces, normalised to activity rows.
	 *
	 * @param int[] $space_ids Spaces in scope.
	 * @param int   $limit     Row ceiling.
	 * @return array<int,array<string,mixed>>
	 */
	private function moderation_rows( array $space_ids, int $limit ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$log = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, actor_id, action, created_at
				 FROM {$wpdb->prefix}bn_mod_log
				 WHERE space_id IN ($placeholders)
				 ORDER BY created_at DESC, id DESC
				 LIMIT %d",
				array_merge( $space_ids, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$actor_ids = array_values( array_unique( array_map( static fn( $r ): int => (int) $r['actor_id'], $log ) ) );
		$names     = $this->name_map( $actor_ids );
		$avatars   = $this->avatar_map( $actor_ids );

		$out = array();
		foreach ( $log as $r ) {
			$actor = (int) $r['actor_id'];
			$who   = 0 === $actor ? __( 'System', 'buddynext' ) : ( $names[ $actor ] ?? __( 'A moderator', 'buddynext' ) );
			$out[] = array(
				'id'              => 'mod-' . (int) $r['id'],
				'icon'            => 'shield',
				'avatar'          => $avatars[ $actor ] ?? '',
				/* translators: 1: actor name, 2: humanised moderation action. */
				'text'            => sprintf( __( '%1$s: %2$s', 'buddynext' ), $who, $this->humanise_action( (string) $r['action'] ) ),
				'occurred_at_utc' => (string) $r['created_at'],
			);
		}
		return $out;
	}

	/**
	 * Recent membership joins for the spaces, normalised to activity rows.
	 *
	 * @param int[] $space_ids Spaces in scope.
	 * @param int   $limit     Row ceiling.
	 * @return array<int,array<string,mixed>>
	 */
	private function join_rows( array $space_ids, int $limit ): array {
		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$joins = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sm.user_id, sm.space_id, sm.joined_at, s.name AS space_name
				 FROM {$wpdb->prefix}bn_space_members sm
				 JOIN {$wpdb->prefix}bn_spaces s ON s.id = sm.space_id
				 WHERE sm.space_id IN ($placeholders) AND sm.status = 'active'
				 ORDER BY sm.joined_at DESC, sm.user_id DESC
				 LIMIT %d",
				array_merge( $space_ids, array( $limit ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$user_ids = array_values( array_unique( array_map( static fn( $r ): int => (int) $r['user_id'], $joins ) ) );
		$names    = $this->name_map( $user_ids );
		$avatars  = $this->avatar_map( $user_ids );

		$out = array();
		foreach ( $joins as $r ) {
			$uid   = (int) $r['user_id'];
			$who   = $names[ $uid ] ?? __( 'A member', 'buddynext' );
			$out[] = array(
				'id'              => 'join-' . (int) $r['space_id'] . '-' . $uid,
				'icon'            => 'user-plus',
				'avatar'          => $avatars[ $uid ] ?? '',
				/* translators: 1: member name, 2: space name. */
				'text'            => sprintf( __( '%1$s joined %2$s', 'buddynext' ), $who, (string) $r['space_name'] ),
				'occurred_at_utc' => (string) $r['joined_at'],
			);
		}
		return $out;
	}

	/**
	 * Total moderation-log rows for the spaces.
	 *
	 * @param int[] $space_ids Spaces in scope.
	 * @return int
	 */
	private function moderation_count( array $space_ids ): int {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE space_id IN ($placeholders)", $space_ids )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Total active memberships (joins) for the spaces.
	 *
	 * @param int[] $space_ids Spaces in scope.
	 * @return int
	 */
	private function join_count( array $space_ids ): int {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $space_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members WHERE space_id IN ($placeholders) AND status = 'active'", $space_ids )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/**
	 * Coerce contributed rows to the documented shape, dropping malformed ones.
	 *
	 * @param array<int,mixed> $extra Contributed rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalise_extra( array $extra ): array {
		$out = array();
		foreach ( $extra as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) || empty( $row['occurred_at_utc'] ) ) {
				continue;
			}
			$out[] = array(
				'id'              => (string) $row['id'],
				'icon'            => (string) ( $row['icon'] ?? 'activity' ),
				'avatar'          => (string) ( $row['avatar'] ?? '' ),
				'text'            => (string) ( $row['text'] ?? '' ),
				'occurred_at_utc' => (string) $row['occurred_at_utc'],
			);
		}
		return $out;
	}

	/**
	 * Map user_id => display_name for a set of users, in one query (no per-row lookup).
	 *
	 * @param int[] $ids User ids (0 is ignored — the system actor).
	 * @return array<int,string>
	 */
	private function name_map( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$users = ( new WP_User_Query(
			array(
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
				'number'  => count( $ids ),
			)
		) )->get_results();
		$map   = array();
		foreach ( $users as $u ) {
			$map[ (int) $u->ID ] = (string) $u->display_name;
		}
		return $map;
	}

	/**
	 * Map user_id => avatar URL for a set of users.
	 *
	 * @param int[] $ids User ids.
	 * @return array<int,string>
	 */
	private function avatar_map( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}
		$avatars = new AvatarService();
		$map     = array();
		foreach ( $ids as $id ) {
			$user = get_userdata( $id );
			if ( $user ) {
				$map[ $id ] = $avatars->get_avatar_url( $user );
			}
		}
		return $map;
	}

	/**
	 * Turn a mod-log action slug into a human phrase, falling back to the slug.
	 *
	 * @param string $action Action slug (e.g. 'ai_remove_content').
	 * @return string
	 */
	private function humanise_action( string $action ): string {
		$map    = array(
			'remove_content' => __( 'removed content', 'buddynext' ),
			'dismiss'        => __( 'dismissed a report', 'buddynext' ),
			'escalate'       => __( 'escalated a report', 'buddynext' ),
			'suspend_user'   => __( 'suspended a member', 'buddynext' ),
			'unsuspend_user' => __( 'lifted a suspension', 'buddynext' ),
			'shadow_ban'     => __( 'shadow-banned a member', 'buddynext' ),
			'space_ban'      => __( 'banned a member from a space', 'buddynext' ),
			'warn'           => __( 'warned a member', 'buddynext' ),
			'issue_strike'   => __( 'issued a strike', 'buddynext' ),
		);
		$key    = preg_replace( '/^ai_/', '', $action );
		$phrase = $map[ $key ] ?? ucfirst( str_replace( '_', ' ', $action ) );
		return 0 === strpos( $action, 'ai_' )
			/* translators: %s: humanised moderation action performed automatically. */
			? sprintf( __( 'automatically %s', 'buddynext' ), $phrase )
			: $phrase;
	}
}
