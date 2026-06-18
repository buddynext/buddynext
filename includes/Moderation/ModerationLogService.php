<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Moderation log service.
 *
 * Writes immutable log entries to bn_mod_log. No update or delete operations
 * are provided — moderation logs are append-only by design.
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

/**
 * Handles writing and reading the immutable moderation action log.
 */
class ModerationLogService {

	/**
	 * Record a moderation action.
	 *
	 * @param int    $actor_id Actor performing the action (admin or mod).
	 * @param string $action   Action slug (e.g. 'dismiss_report', 'issue_strike').
	 * @param array  $context  Optional context:
	 *                         - object_type string.
	 *                         - object_id   int.
	 *                         - target_user_id int.
	 *                         - note string.
	 * @return int Inserted log entry ID.
	 */
	public function log( int $actor_id, string $action, array $context = array() ): int {
		global $wpdb;

		$object_type    = isset( $context['object_type'] ) ? sanitize_key( (string) $context['object_type'] ) : null;
		$object_id      = isset( $context['object_id'] ) ? (int) $context['object_id'] : null;
		$target_user_id = isset( $context['target_user_id'] ) ? (int) $context['target_user_id'] : null;
		$note           = isset( $context['note'] ) ? sanitize_textarea_field( (string) $context['note'] ) : null;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_mod_log',
			array(
				'actor_id'       => $actor_id,
				'action'         => sanitize_key( $action ),
				'object_type'    => $object_type,
				'object_id'      => $object_id,
				'target_user_id' => $target_user_id,
				'note'           => $note,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Return all log entries targeting a specific user.
	 *
	 * @param int $user_id Target user ID.
	 * @return array[]
	 */
	public function get_log_for_user( int $user_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_mod_log
				 WHERE target_user_id = %d
				 ORDER BY created_at DESC",
				$user_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return all log entries for a specific object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return array[]
	 */
	public function get_log_for_object( string $object_type, int $object_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_mod_log
				 WHERE object_type = %s AND object_id = %d
				 ORDER BY created_at DESC",
				sanitize_key( $object_type ),
				$object_id
			),
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/**
	 * Return a paginated slice of the moderation log, newest first, with
	 * optional filters. Powers the admin-only GET /moderation/log REST route.
	 *
	 * @param array $args {
	 *     Optional query args.
	 *     @type int    $user_id  Filter to entries targeting this user (0 = all).
	 *     @type string $action   Filter to a single action slug ('' = all).
	 *     @type int    $space_id Filter to entries scoped to this space (0 = all).
	 *     @type string $since    Only entries at/after this datetime ('' = all).
	 *                            Accepts any strtotime-parsable value; normalised
	 *                            to MySQL 'Y-m-d H:i:s'.
	 *     @type int    $per_page Rows per page (1-100, default 20).
	 *     @type int    $page     1-based page number (default 1).
	 * }
	 * @return array{items: array[], total: int, page: int, per_page: int}
	 */
	public function get_log( array $args = array() ): array {
		global $wpdb;

		$user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$action   = isset( $args['action'] ) ? sanitize_key( (string) $args['action'] ) : '';
		$space_id = isset( $args['space_id'] ) ? (int) $args['space_id'] : 0;
		$since     = '';
		if ( ! empty( $args['since'] ) ) {
			$ts = strtotime( (string) $args['since'] );
			if ( false !== $ts ) {
				$since = gmdate( 'Y-m-d H:i:s', $ts );
			}
		}
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( '1=1' );
		$params = array();
		if ( $user_id > 0 ) {
			$where[]  = 'target_user_id = %d';
			$params[] = $user_id;
		}
		if ( '' !== $action ) {
			$where[]  = 'action = %s';
			$params[] = $action;
		}
		if ( $space_id > 0 ) {
			$where[]  = 'space_id = %d';
			$params[] = $space_id;
		}
		if ( '' !== $since ) {
			$where[]  = 'created_at >= %s';
			$params[] = $since;
		}
		$where_sql = implode( ' AND ', $where );

		// The WHERE fragments are built from literal column conditions only; every
		// runtime value is passed through $wpdb->prepare(). The placeholder count
		// is dynamic (depends on which filters are active), which the static sniff
		// cannot verify — hence the placeholder-sniff suppressions below.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$total = (int) $wpdb->get_var(
			$params
				? $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE {$where_sql}", $params )
				: "SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE {$where_sql}"
		);

		$query_params = array_merge( $params, array( $per_page, $offset ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_mod_log WHERE {$where_sql} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
				$query_params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		return array(
			'items'    => array_map( array( $this, 'hydrate' ), (array) $rows ),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Hydrate a raw log row.
	 *
	 * @param array $row ARRAY_A row.
	 * @return array
	 */
	private function hydrate( array $row ): array {
		return array(
			'id'             => (int) $row['id'],
			'actor_id'       => (int) $row['actor_id'],
			'action'         => $row['action'],
			'object_type'    => $row['object_type'],
			'object_id'      => isset( $row['object_id'] ) ? (int) $row['object_id'] : null,
			'target_user_id' => isset( $row['target_user_id'] ) ? (int) $row['target_user_id'] : null,
			'note'           => $row['note'],
			'created_at'     => $row['created_at'],
		);
	}
}
