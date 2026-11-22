<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Online presence: the one seam that writes and reads "last active".
 *
 * Writers, all event-driven (no Heartbeat API, no admin-ajax, no timer):
 *   1. template_redirect - every front-end page view by a logged-in member.
 *   2. POST /me/presence/heartbeat (RealtimeController) - called by the
 *      buddynext/presence script module after a client-side navigation and when a
 *      tab comes back into view, at most once per ~4 minutes per tab. This is
 *      also the path that works when a page cache served the HTML, because the
 *      cached page skips template_redirect.
 * Both go through stamp(), throttled to one write per member per minute.
 *
 * Readers resolve "online" as active within online_window() (default 600s,
 * filterable). Everything goes through this class, so another presence source
 * (for example a future core presence API) can feed stamp() without touching
 * any reader.
 *
 * @package BuddyNext\Realtime
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Realtime;

/**
 * Writes and reads member presence in the indexed bn_presence table.
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
	 * Kept well under the online window so presence never goes stale for an
	 * active user.
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
	 * JavaScript at all. The REST heartbeat endpoint (RealtimeController) is
	 * the event-driven top-up for client navigation and a tab coming back into
	 * view; see the class docblock.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'heartbeat' ) );
		add_action( 'wp_logout', array( $this, 'clear_on_logout' ) );
		add_filter( 'site_status_tests', array( $this, 'register_site_health' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_ping' ), 20 );
	}

	/**
	 * Load the client presence ping for a signed-in member on a BuddyNext hub.
	 *
	 * Hubs are recognised by the shell script every hub enqueues
	 * (PageRouter::enqueue_hub_assets()), so the ping rides the same pages the
	 * shell does and never loads on an unrelated theme page. Runs after the
	 * modules are registered (AssetService, priority 10).
	 *
	 * @since 1.3.0
	 *
	 * @return void
	 */
	public function enqueue_ping(): void {
		if ( is_user_logged_in() && wp_script_is( 'bn-shell-extras', 'enqueued' ) ) {
			wp_enqueue_script_module( '@buddynext/presence' );
		}
	}

	/**
	 * Add the "presence is recording" Site Health test.
	 *
	 * @since 1.3.0
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function register_site_health( array $tests ): array {
		$tests['direct']['buddynext_presence'] = array(
			'label' => __( 'BuddyNext online status', 'buddynext' ),
			'test'  => array( self::class, 'site_health_test' ),
		);
		return $tests;
	}

	/**
	 * Site Health: warn when members are active but nobody is ever recorded online.
	 *
	 * "Members are active" is read from the community itself: a post published in
	 * the last hour. Writing one means a signed-in member was on the site, which
	 * should have stamped presence (page view or the client ping). If that hour
	 * holds posts but no presence at all, the stamp is not reaching the server,
	 * almost always because a page cache serves pages to signed-in members or a
	 * firewall blocks the REST ping. Two indexed reads, no stored state.
	 *
	 * @since 1.3.0
	 *
	 * @return array<string, mixed>
	 */
	public static function site_health_test(): array {
		global $wpdb;
		$since = time() - HOUR_IN_SECONDS;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$recent_post = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->prefix}bn_posts WHERE created_at >= %s LIMIT 1 ) recent",
				gmdate( 'Y-m-d H:i:s', $since )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$badge = array(
			'label' => __( 'BuddyNext', 'buddynext' ),
			'color' => 'blue',
		);

		if ( 0 === $recent_post || self::online_count( HOUR_IN_SECONDS ) > 0 ) {
			return array(
				'label'       => __( 'BuddyNext is recording who is online', 'buddynext' ),
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html__( 'Online dots, the Online only filter and the Online now list are up to date.', 'buddynext' ) . '</p>',
				'test'        => 'buddynext_presence',
			);
		}

		return array(
			'label'       => __( 'BuddyNext is not recording who is online', 'buddynext' ),
			'status'      => 'recommended',
			'badge'       => $badge,
			'description' => '<p>' . esc_html__( 'Members posted in the last hour, but nobody was recorded as online. Online dots, the Online only filter and the Online now list will look empty.', 'buddynext' ) . '</p><p>' . esc_html__( 'The usual cause is a page cache that serves saved pages to signed-in members, or a security rule that blocks the BuddyNext REST API. Ask your host to skip page caching for signed-in visitors and to allow requests to /wp-json/buddynext/v1/. Posts made only from a mobile app can also show this, because an app does not open site pages.', 'buddynext' ) . '</p>',
			'test'        => 'buddynext_presence',
		);
	}

	/**
	 * Clear a user's presence on explicit logout so they read offline at once.
	 *
	 * Presence is a decaying heartbeat (online_window() seconds); without this an
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
	 * Default "online" window in seconds: active in the last 10 minutes.
	 *
	 * Raised from 300 in 1.3.0. Presence is event-driven (a page view, a client
	 * navigation, a tab coming back into view) with no timer, so a member who
	 * reads one long thread sends nothing; 10 minutes keeps them online through
	 * that without polling. Read the effective value through online_window(),
	 * which a site may filter; this constant is only the default.
	 *
	 * @since 1.0.0
	 * @var int
	 */
	public const ONLINE_WINDOW = 600;

	/**
	 * The effective "online" window in seconds.
	 *
	 * Every presence reader (dots, the Online only filter, the online sort, the
	 * Online now widget) resolves its window here, so one filter changes them all.
	 *
	 * @since 1.3.0
	 *
	 * @return int Seconds, never under one minute (the stamp throttle).
	 */
	public static function online_window(): int {
		/**
		 * Filter how recently a member must have been active to count as online.
		 *
		 * @since 1.3.0
		 *
		 * @param int $seconds Default PresenceService::ONLINE_WINDOW (600).
		 */
		return max( self::THROTTLE_SECONDS, (int) apply_filters( 'buddynext_presence_online_window', self::ONLINE_WINDOW ) );
	}

	/**
	 * Resolve a caller's window argument: 0 means the effective online window.
	 *
	 * @param int $within Seconds, or 0 for online_window().
	 * @return int
	 */
	private static function window( int $within ): int {
		return $within > 0 ? $within : self::online_window();
	}

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
	 * Whether presence (online dots and the "Online now" lists) may be shown to
	 * this viewer.
	 *
	 * Signed-in members always see presence. Logged-out/anonymous visitors do
	 * NOT, even on a public community — an owner decision (2026-09-15): exposing
	 * who is online right now to the open internet is a privacy surprise for a
	 * community of unknown type. Filterable for owners who want public presence.
	 *
	 * Every presence producer (dots via BlockService::is_user_online_at() and
	 * online_among(); the widget and sidebar "Online now" lists; the directory
	 * online_only filter) routes its viewer through this one predicate so the
	 * decision lives in a single place.
	 *
	 * @param int $viewer_id Current viewer (0 = anonymous).
	 * @return bool
	 */
	public static function visible_to_viewer( int $viewer_id ): bool {
		if ( $viewer_id > 0 ) {
			// A member who hides their own status stops seeing everyone else's
			// (reciprocal, like WhatsApp). Staff keep it: they need it for reports.
			return ! self::is_hidden( $viewer_id ) || self::is_staff( $viewer_id );
		}

		/**
		 * Filter whether member online presence is shown to logged-out visitors.
		 *
		 * @param bool $visible Default false (presence hidden from anonymous).
		 */
		return (bool) apply_filters( 'buddynext_presence_visible_to_anonymous', false );
	}

	/**
	 * Usermeta key BuddyNext owns for "Show when I'm online" = off.
	 */
	public const HIDE_META = 'bn_hide_online';

	/**
	 * Jetonomy's key for the same choice. While Jetonomy is active it is the one
	 * BuddyNext writes, so Jetonomy pages honour the member's choice too.
	 */
	public const JETONOMY_HIDE_META = 'jetonomy_hide_online';

	/**
	 * Whether a member chose to hide their online status ("Show when I'm online" off).
	 *
	 * Either key counts, so a member who hid it on Jetonomy before BuddyNext had
	 * the setting stays hidden without saving anything.
	 *
	 * @param int $user_id Member ID.
	 * @return bool
	 */
	public static function is_hidden( int $user_id ): bool {
		return $user_id > 0
			&& ( '1' === (string) get_user_meta( $user_id, self::HIDE_META, true ) || '1' === (string) get_user_meta( $user_id, self::JETONOMY_HIDE_META, true ) );
	}

	/**
	 * Save "Show when I'm online".
	 *
	 * Writes Jetonomy's key while Jetonomy is active, BuddyNext's own otherwise, and
	 * brings the other key along when it exists so the two can never disagree.
	 *
	 * @param int  $user_id Member ID.
	 * @param bool $hidden  True = hide (setting off).
	 * @return void
	 */
	public static function set_hidden( int $user_id, bool $hidden ): void {
		if ( $user_id <= 0 ) {
			return;
		}
		$primary = class_exists( '\\Jetonomy\\Models\\Space' ) ? self::JETONOMY_HIDE_META : self::HIDE_META;
		foreach ( array( self::HIDE_META, self::JETONOMY_HIDE_META ) as $key ) {
			if ( $key !== $primary && '' === (string) get_user_meta( $user_id, $key, true ) ) {
				continue;
			}
			if ( $hidden ) {
				update_user_meta( $user_id, $key, 1 );
			} else {
				delete_user_meta( $user_id, $key );
			}
		}
	}

	/**
	 * Whether a viewer is staff who still see hidden presence (site moderators and admins).
	 *
	 * @param int $viewer_id Viewer ID.
	 * @return bool
	 */
	public static function is_staff( int $viewer_id ): bool {
		return $viewer_id > 0 && buddynext_can( $viewer_id, 'buddynext-moderation/review-queue' );
	}

	/**
	 * Whether a viewer may see a target's presence (dot and last seen).
	 *
	 * @param int $viewer_id Viewer ID (0 = guest).
	 * @param int $target_id Member whose presence would show.
	 * @return bool
	 */
	public static function can_see( int $viewer_id, int $target_id ): bool {
		if ( ! self::visible_to_viewer( $viewer_id ) ) {
			return false;
		}
		return $viewer_id === $target_id || ! self::is_hidden( $target_id ) || self::is_staff( $viewer_id );
	}

	/**
	 * Drop members who hide their status from a list of ids, unless the viewer is staff.
	 *
	 * @param int[] $user_ids  Member IDs.
	 * @param int   $viewer_id Viewer ID.
	 * @return int[]
	 */
	public static function without_hidden( array $user_ids, int $viewer_id ): array {
		if ( array() === $user_ids || self::is_staff( $viewer_id ) ) {
			return $user_ids;
		}
		update_meta_cache( 'user', $user_ids );
		return array_values(
			array_filter(
				$user_ids,
				static fn( $id ): bool => (int) $id === $viewer_id || ! self::is_hidden( (int) $id )
			)
		);
	}

	/**
	 * SQL condition that excludes members who hide their status, for a non-staff viewer.
	 *
	 * Returns '' for staff. The usermeta meta_key index serves the subquery; the hidden
	 * set is small.
	 *
	 * @param string $user_col  Column holding the member ID, e.g. "u.ID".
	 * @param int    $viewer_id Viewer ID.
	 * @return string " AND ..." or ''.
	 */
	public static function hidden_sql( string $user_col, int $viewer_id ): string {
		if ( self::is_staff( $viewer_id ) ) {
			return '';
		}
		global $wpdb;
		return " AND {$user_col} NOT IN ( SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key IN ( '" . self::HIDE_META . "', '" . self::JETONOMY_HIDE_META . "' ) AND meta_value = '1' )";
	}

	/**
	 * IDs of users active within the given window — an indexed range scan.
	 *
	 * @since 1.0.0
	 *
	 * @param int $within Seconds. 0 (default) = online_window().
	 * @return array<int, int> User IDs.
	 */
	public static function online_ids( int $within = 0 ): array {
		global $wpdb;
		$cutoff = time() - self::window( $within );
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
	 * @param int $within Seconds. 0 (default) = online_window().
	 * @return array<int, int> User IDs, most recently active first.
	 */
	public static function recent_online_ids( int $limit, int $within = 0 ): array {
		global $wpdb;
		$limit  = max( 1, $limit );
		$cutoff = time() - self::window( $within );
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
	 * @param int $within Seconds. 0 (default) = online_window().
	 * @return int
	 */
	public static function online_count( int $within = 0 ): int {
		global $wpdb;
		$cutoff = time() - self::window( $within );
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
	 * @param int $within  Seconds. 0 (default) = online_window().
	 * @return bool
	 */
	public static function is_online( int $user_id, int $within = 0 ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		return self::last_active_at( $user_id ) > ( time() - self::window( $within ) );
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
