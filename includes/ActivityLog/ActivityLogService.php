<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Site-wide activity-log reader.
 *
 * Owns reads of the bn_activity_log table so templates never touch it directly.
 * The log is written elsewhere (feature listeners) and pruned by CronService;
 * this service is the single read accessor the community-admin surface and any
 * future activity feed call.
 *
 * @package BuddyNext\ActivityLog
 */

declare( strict_types=1 );

namespace BuddyNext\ActivityLog;

/**
 * Reads the site-wide activity log.
 */
class ActivityLogService {

	/**
	 * Return the most recent activity-log entries, newest first, enriched with
	 * the actor's display name in one batched lookup (never one query per row).
	 *
	 * @param int $limit Max rows to return. Capped at 100.
	 * @return array<int, array{action:string, object_type:string, object_id:int, created_at:string, user_id:int, actor_name:string}>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 100, $limit ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, object_type, object_id, created_at, user_id
				 FROM {$wpdb->prefix}bn_activity_log
				 ORDER BY created_at DESC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = (array) $rows;
		if ( empty( $rows ) ) {
			return array();
		}

		// Batch-resolve actor display names so the surface shows full context in
		// one lookup, never one query per row.
		$user_ids = array();
		foreach ( $rows as $row ) {
			$uid = (int) ( $row['user_id'] ?? 0 );
			if ( $uid > 0 ) {
				$user_ids[ $uid ] = true;
			}
		}

		$names = array();
		if ( ! empty( $user_ids ) ) {
			foreach ( get_users(
				array(
					'include' => array_map( 'intval', array_keys( $user_ids ) ),
					'fields'  => array( 'ID', 'display_name' ),
				)
			) as $user ) {
				$names[ (int) $user->ID ] = (string) $user->display_name;
			}
		}

		return array_map(
			static function ( array $row ) use ( $names ): array {
				$uid = (int) ( $row['user_id'] ?? 0 );
				return array(
					'action'      => (string) ( $row['action'] ?? '' ),
					'object_type' => (string) ( $row['object_type'] ?? '' ),
					'object_id'   => (int) ( $row['object_id'] ?? 0 ),
					'created_at'  => (string) ( $row['created_at'] ?? '' ),
					'user_id'     => $uid,
					'actor_name'  => $names[ $uid ] ?? '',
				);
			},
			$rows
		);
	}
}
