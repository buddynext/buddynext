<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Online-presence heartbeat writer.
 *
 * The presence readers — BlockService::is_user_online() and the member
 * directory `online`/`most_active` query — both resolve presence from the
 * `bn_last_active` user_meta timestamp. Nothing in Free wrote that value, so
 * every member resolved to offline and the "Online now" directory filter,
 * online sort, member-card dots, and OnlineMembersWidget were all permanently
 * empty.
 *
 * This service is the missing producer. It stamps `bn_last_active` for the
 * logged-in user on front-end activity, throttled via a short transient so a
 * page-heavy session does not write on every request. It is the Free polling
 * equivalent of Pro's WebSocket heartbeat — same meta key, same 300s window
 * used by the reader, so Pro can later swap the producer without touching the
 * readers.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Writes the `bn_last_active` presence timestamp on authenticated activity.
 *
 * @since 1.0.0
 */
class PresenceService {

	/**
	 * Legacy presence user_meta key.
	 *
	 * No longer written or read on hot paths — every reader resolves presence
	 * from the indexed bn_presence table now. Retained only as the source for the
	 * one-time v7 backfill (Installer::maybe_backfill_presence) and the v9 cleanup
	 * that deletes it (Installer::maybe_drop_last_active_meta).
	 *
	 * @since 1.0.0
	 * @var string
	 */
	public const META_KEY = 'bn_last_active';

	/**
	 * Minimum seconds between two stamps for the same user.
	 *
	 * A transient guard keyed per user prevents a write on every page load.
	 * Kept well under the reader's 300s online window so presence never goes
	 * stale for an active user.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const THROTTLE_SECONDS = 60;

	/**
	 * Attach the front-end heartbeat hook.
	 *
	 * Called once at boot. The stamp runs on template_redirect — every
	 * front-end page view by a logged-in user — so presence works with no
	 * JavaScript at all. The REST heartbeat endpoint (RealtimeController)
	 * provides a finer-grained, JS-driven top-up on top of this baseline.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'heartbeat' ) );
		add_action( 'wp_logout', array( $this, 'clear_on_logout' ) );
	}

	/**
	 * Clear a user's presence on explicit logout so they read offline at once.
	 *
	 * Presence is a decaying heartbeat (ONLINE_WINDOW seconds); without this an
	 * explicit logout would keep showing the user "online" until the window
	 * elapsed. bn_presence is BuddyNext's own table, so this deletes the row
	 * directly; last_active_at() then resolves 0 (offline). The throttle transient
	 * is cleared too so a later re-login stamps immediately instead of being
	 * collapsed by the stale guard. A browser/tab close (no logout event) still
	 * decays naturally over the window — that is expected heartbeat behaviour.
	 *
	 * @since 1.0.4
	 *
	 * @param int $user_id Logging-out user (passed by the wp_logout action).
	 * @return void
	 */
	public function clear_on_logout( int $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'bn_presence', array( 'user_id' => $user_id ), array( '%d' ) );
		delete_transient( 'bn_presence_' . $user_id );
	}

	/**
	 * Heartbeat handler for front-end page views.
	 *
	 * Bails on admin / REST / cron / AJAX contexts and for anonymous users;
	 * those are handled elsewhere (REST) or are not real presence signals.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function heartbeat(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		$this->stamp( get_current_user_id() );
	}

	/**
	 * Stamp `bn_last_active` for a user, throttled.
	 *
	 * Safe to call from any authenticated context (template_redirect or the
	 * REST heartbeat). The transient guard collapses repeated calls within the
	 * throttle window to a single write. Returns true when a write happened,
	 * false when throttled or the user id is invalid — never throws, so an
	 * absent object cache or a guarded call site degrades gracefully.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User to stamp. Must be a real, positive user id.
	 * @return bool True when the timestamp was written, false otherwise.
	 */
	public function stamp( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Already stamped within the throttle window — nothing to do.
		if ( $this->is_throttled( $user_id ) ) {
			return false;
		}

		$now = time();
		self::write( $user_id, $now ); // Indexed bn_presence table — the only presence store now (all readers migrated; the legacy bn_last_active user_meta dual-write was dropped in schema v9).
		$this->mark_throttled( $user_id );

		/**
		 * Fires after a user's presence timestamp is refreshed.
		 *
		 * Pro's WebSocket presence layer can listen here to broadcast a
		 * real-time presence event in addition to the polled meta write.
		 *
		 * @since 1.0.0
		 *
		 * @param int $user_id User whose presence was just stamped.
		 */
		do_action( 'buddynext_presence_stamped', $user_id );

		return true;
	}

	/**
	 * Object-cache group for the per-user stamp throttle.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const THROTTLE_GROUP = 'buddynext_presence';

	/**
	 * Whether a user was stamped within the throttle window.
	 *
	 * Uses the persistent object cache when one is present (so the throttle does
	 * NOT write to wp_options on every front-end page view — a real cost at 100k),
	 * and falls back to a transient only when there is no persistent cache.
	 * Presence is ephemeral, so a cache flush losing the guard is harmless.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function is_throttled( int $user_id ): bool {
		$key = 'throttle_' . $user_id;
		if ( wp_using_ext_object_cache() ) {
			return false !== wp_cache_get( $key, self::THROTTLE_GROUP );
		}
		return false !== get_transient( 'bn_presence_' . $user_id );
	}

	/**
	 * Record that a user was just stamped (start the throttle window).
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	private function mark_throttled( int $user_id ): void {
		$key = 'throttle_' . $user_id;
		if ( wp_using_ext_object_cache() ) {
			// cache-ttl-only: this is a WRITE THROTTLE ('already recorded activity this minute'), not a read cache. The TTL IS the mechanism — there is nothing to invalidate.
			wp_cache_set( $key, 1, self::THROTTLE_GROUP, self::THROTTLE_SECONDS );
			return;
		}
		set_transient( 'bn_presence_' . $user_id, 1, self::THROTTLE_SECONDS );
	}

	/**
	 * Default "online" window in seconds (matches the legacy reader's 300s).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const ONLINE_WINDOW = 300;

	/**
	 * UPSERT a user's presence timestamp into the indexed bn_presence table.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id   User id.
	 * @param int $timestamp UNIX timestamp.
	 * @return void
	 */
	public static function write( int $user_id, int $timestamp ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_presence (user_id, last_active) VALUES (%d, %d)
				 ON DUPLICATE KEY UPDATE last_active = GREATEST(last_active, VALUES(last_active))",
				$user_id,
				$timestamp
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * IDs of users active within the given window — an indexed range scan.
	 *
	 * @since 1.0.0
	 *
	 * @param int $within Seconds. Defaults to ONLINE_WINDOW.
	 * @return array<int, int> User IDs.
	 */
	public static function online_ids( int $within = self::ONLINE_WINDOW ): array {
		global $wpdb;
		$cutoff = time() - max( 1, $within );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_presence WHERE last_active > %d",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * The most-recently-active user IDs within the window, newest first.
	 *
	 * Bounded by $limit at the SQL level (ORDER BY last_active DESC LIMIT) so a
	 * widget never loads the full online set — an indexed range scan on the
	 * last_active key. Use this instead of slicing online_ids() in PHP.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit  Max IDs to return (clamped to >= 1).
	 * @param int $within Seconds. Defaults to ONLINE_WINDOW.
	 * @return array<int, int> User IDs, most recently active first.
	 */
	public static function recent_online_ids( int $limit, int $within = self::ONLINE_WINDOW ): array {
		global $wpdb;
		$limit  = max( 1, $limit );
		$cutoff = time() - max( 1, $within );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->prefix}bn_presence WHERE last_active > %d ORDER BY last_active DESC LIMIT %d",
				$cutoff,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Count of users active within the given window.
	 *
	 * @since 1.0.0
	 *
	 * @param int $within Seconds. Defaults to ONLINE_WINDOW.
	 * @return int
	 */
	public static function online_count( int $within = self::ONLINE_WINDOW ): int {
		global $wpdb;
		$cutoff = time() - max( 1, $within );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_presence WHERE last_active > %d",
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Whether a user has been active within the window.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @param int $within  Seconds. Defaults to ONLINE_WINDOW.
	 * @return bool
	 */
	public static function is_online( int $user_id, int $within = self::ONLINE_WINDOW ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return self::last_active_at( $user_id ) > ( time() - max( 1, $within ) );
	}

	/**
	 * A user's last-active UNIX timestamp, or 0 if never seen.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User id.
	 * @return int
	 */
	public static function last_active_at( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_active FROM {$wpdb->prefix}bn_presence WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Resolve last-active timestamps for a set of users in one read.
	 *
	 * The batched sibling of last_active_at(), for callers rendering a page of
	 * people at once. The member directory avoids the per-row lookup by joining
	 * bn_presence into its own user query; a feed cannot — its authors arrive from
	 * bn_posts rows, not from a WP_User_Query — so it needs the ids resolved
	 * separately. Calling last_active_at() in that loop is a query per card.
	 *
	 * Every requested id is present in the result. A user who has never been seen
	 * has no bn_presence row, and is reported as 0 rather than omitted, so callers
	 * can read the map directly instead of guarding every lookup.
	 *
	 * @param int[] $user_ids Users to resolve.
	 * @return array<int,int> user_id => last-active UNIX timestamp (0 = never seen).
	 */
	public static function last_active_map( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		// Seed every id to 0 so an absent row reads as offline, not as a missing key.
		$map = array_fill_keys( $user_ids, 0 );

		global $wpdb;

		$placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
		$sql          = "SELECT user_id, last_active FROM {$wpdb->prefix}bn_presence WHERE user_id IN ({$placeholders})";

		// $placeholders is a run of %d built from a counted int array, so the query is
		// fully placeholdered and every value below is bound; the sniffs cannot see that.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$user_ids ), ARRAY_A );

		foreach ( (array) $rows as $row ) {
			$map[ (int) $row['user_id'] ] = (int) $row['last_active'];
		}

		return $map;
	}
}
