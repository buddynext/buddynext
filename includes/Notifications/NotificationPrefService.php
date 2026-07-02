<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Notification preference service.
 *
 * Reads and writes per-user, per-type notification preferences stored in
 * bn_notification_prefs. If no row exists for a user/type pair, defaults
 * are returned: on_site=true, email_freq='immediate'.
 *
 * @package BuddyNext\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Notifications;

/**
 * Handles per-user notification preference reads and writes.
 */
class NotificationPrefService {

	/**
	 * Cache group.
	 */
	private const CACHE_GROUP = 'buddynext_notif_prefs';

	/**
	 * Cache TTL in seconds.
	 */
	private const CACHE_TTL = 600;

	/**
	 * Valid email frequency values.
	 */
	private const VALID_FREQ = array( 'immediate', 'daily', 'weekly', 'off' );

	/**
	 * Map of notification type slug → the Settings → Notifications "default"
	 * option that governs its initial on_site state. When a user has no explicit
	 * pref row for one of these types, the site owner's default applies instead
	 * of a blanket "on". Types not listed here fall back to the catalogue's own
	 * default_on_site.
	 */
	private const ADMIN_DEFAULT_OPTION = array(
		'bn.new_follower'         => 'buddynext_notif_default_follow',
		'bn.connection_requested' => 'buddynext_notif_default_connection',
		'bn.connection_accepted'  => 'buddynext_notif_default_connection',
		'bn.post_reacted'         => 'buddynext_notif_default_reaction',
		'bn.post_commented'       => 'buddynext_notif_default_comment',
		'bn.mention'              => 'buddynext_notif_default_mention',
		'bn.space_join_requested' => 'buddynext_notif_default_space_join',
	);

	/**
	 * Catalogue instance (lazy), used to source per-type default_on_site /
	 * default_email_freq when a user has no explicit pref row.
	 *
	 * @var NotificationPrefCatalogue|null
	 */
	private ?NotificationPrefCatalogue $catalogue = null;

	/**
	 * Resolve the implicit (no-row) default for a notification type.
	 *
	 * Starts from the catalogue's per-type default, then lets the site owner's
	 * Settings → Notifications default override the on_site channel for the
	 * primary social/feed/space types.
	 *
	 * @param string $type Notification type key.
	 * @return array{on_site: bool, email_freq: string}
	 */
	private function default_pref( string $type ): array {
		if ( null === $this->catalogue ) {
			$this->catalogue = new NotificationPrefCatalogue();
		}
		$catalogue = $this->catalogue->all();
		$entry     = $catalogue[ $type ] ?? array();

		$on_site    = isset( $entry['default_on_site'] ) ? (bool) $entry['default_on_site'] : true;
		$email_freq = isset( $entry['default_email_freq'] ) ? (string) $entry['default_email_freq'] : 'immediate';

		if ( isset( self::ADMIN_DEFAULT_OPTION[ $type ] ) ) {
			// Apply the site-owner default whenever the option EXISTS. Only an
			// absent option (null) means "never configured" and falls back to the
			// catalogue default — the toggle's hidden 0 field guarantees every
			// save persists a value. A stored boolean false comes back from the
			// options table as an empty string, so '' here is an explicit OFF, not
			// "unset"; rest_sanitize_boolean() maps '' / '0' / false → false and
			// '1' / true → true. The previous '' !== guard treated a saved-OFF as
			// unset, so turning a default toggle off had no effect.
			$admin_val = get_option( self::ADMIN_DEFAULT_OPTION[ $type ], null );
			if ( null !== $admin_val ) {
				$on_site = (bool) rest_sanitize_boolean( $admin_val );
			}
		}

		return array(
			'on_site'    => $on_site,
			'email_freq' => $email_freq,
		);
	}

	/**
	 * Return the notification preference for a user and type.
	 *
	 * Returns defaults if no row exists.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Notification type key (e.g. 'bn.new_follower').
	 * @return array{on_site: bool, email_freq: string}
	 */
	public function get_pref( int $user_id, string $type ): array {
		$cache_key = "pref_{$user_id}_{$type}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT on_site, email_freq FROM {$wpdb->prefix}bn_notification_prefs
				 WHERE user_id = %d AND type = %s",
				$user_id,
				sanitize_text_field( $type )
			),
			ARRAY_A
		);

		$pref = null !== $row
			? array(
				'on_site'    => (bool) $row['on_site'],
				'email_freq' => $row['email_freq'],
			)
			: $this->default_pref( sanitize_text_field( $type ) );

		wp_cache_set( $cache_key, $pref, self::CACHE_GROUP, self::CACHE_TTL );

		return $pref;
	}

	/**
	 * Batch variant of get_pref()'s on_site decision for many users at once.
	 *
	 * Returns a map of user_id => bool (whether the in-app/on_site channel is
	 * enabled for the given type). One query covers every user; users with no
	 * stored row fall back to the SAME default_pref() the single-user path uses,
	 * so the batched fan-out filters recipients identically to create(). Built
	 * for high-volume fan-out (space new-post) where a per-user get_pref() would
	 * be an N+1.
	 *
	 * @param int[]  $user_ids User IDs.
	 * @param string $type     Notification type key (e.g. 'bn.space_new_post').
	 * @return array<int,bool> user_id => on_site enabled.
	 */
	public function get_on_site_map( array $user_ids, string $type ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( empty( $user_ids ) ) {
			return array();
		}

		global $wpdb;

		$type         = sanitize_text_field( $type );
		$placeholders = implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, on_site FROM {$wpdb->prefix}bn_notification_prefs
				 WHERE type = %s AND user_id IN ( {$placeholders} )",
				array_merge( array( $type ), $user_ids )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$stored = array();
		foreach ( (array) $rows as $row ) {
			$stored[ (int) $row['user_id'] ] = (bool) $row['on_site'];
		}

		$default = (bool) $this->default_pref( $type )['on_site'];

		$map = array();
		foreach ( $user_ids as $uid ) {
			$map[ $uid ] = $stored[ $uid ] ?? $default;
		}

		return $map;
	}

	/**
	 * Set the notification preference for a user and type.
	 *
	 * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotency.
	 *
	 * @param int    $user_id User ID.
	 * @param string $type    Notification type key.
	 * @param array  $data    Keys: on_site (bool), email_freq (string).
	 */
	public function set_pref( int $user_id, string $type, array $data ): void {
		global $wpdb;

		$on_site    = isset( $data['on_site'] ) ? (int) $data['on_site'] : 1;
		$email_freq = in_array( $data['email_freq'] ?? 'immediate', self::VALID_FREQ, true )
			? $data['email_freq']
			: 'immediate';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_notification_prefs (user_id, type, on_site, email_freq)
				 VALUES (%d, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE on_site = VALUES(on_site), email_freq = VALUES(email_freq)",
				$user_id,
				sanitize_text_field( $type ),
				$on_site,
				$email_freq
			)
		);

		wp_cache_delete( "pref_{$user_id}_{$type}", self::CACHE_GROUP );
	}

	/**
	 * Return all stored notification preferences for a user.
	 *
	 * Only types that have an explicit row in bn_notification_prefs are returned.
	 * Types without a row use the implicit defaults (on_site=true, email_freq='immediate')
	 * and do not appear in this list.
	 *
	 * @param int $user_id User ID.
	 * @return array[] Keyed by notification type: type => {on_site, email_freq}.
	 */
	public function get_all_prefs( int $user_id ): array {
		$cache_key = "all_prefs_{$user_id}";
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT type, on_site, email_freq FROM {$wpdb->prefix}bn_notification_prefs WHERE user_id = %d",
				$user_id
			),
			ARRAY_A
		);

		$prefs = array();
		foreach ( (array) $rows as $row ) {
			$prefs[ $row['type'] ] = array(
				'on_site'    => (bool) $row['on_site'],
				'email_freq' => $row['email_freq'],
			);
		}

		wp_cache_set( $cache_key, $prefs, self::CACHE_GROUP, self::CACHE_TTL );

		/**
		 * Filter the resolved notification preferences for a user.
		 *
		 * Pro / bridge plugins use this to append channel-specific pref rows
		 * (e.g., push, SMS) without modifying Free. Each entry should follow
		 * the same array shape as Free's entries: at minimum
		 * { on_site: bool, email_freq: string }, plus any channel keys the
		 * extending plugin owns (e.g., push_enabled: bool).
		 *
		 * @since 1.2.0
		 *
		 * @param array[] $prefs    Resolved prefs keyed by type.
		 * @param int     $user_id  Owner.
		 */
		return (array) apply_filters( 'buddynext_notification_prefs', $prefs, $user_id );
	}

	/**
	 * Bulk-update notification preferences for a user from a map of type => data pairs.
	 *
	 * Each value in $prefs_map should be an array with optional keys:
	 *   on_site    (bool)   — whether to show in-app notification.
	 *   email_freq (string) — one of 'immediate', 'daily', 'weekly', 'off'.
	 *
	 * Unknown keys within each value are ignored. Unknown notification types
	 * are stored as-is to be forward-compatible with new types.
	 *
	 * @param int   $user_id   User ID.
	 * @param array $prefs_map Associative array of type => {on_site?, email_freq?}.
	 */
	public function set_all_prefs( int $user_id, array $prefs_map ): void {
		foreach ( $prefs_map as $type => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}

			$this->set_pref( $user_id, sanitize_text_field( (string) $type ), $data );
		}

		wp_cache_delete( "all_prefs_{$user_id}", self::CACHE_GROUP );
	}

	/**
	 * Valid per-space notification preference values.
	 */
	private const VALID_SPACE_PREFS = array( 'all', 'mentions_only', 'none' );

	/**
	 * Return the per-space notification preference for a user.
	 *
	 * Reads the notification_pref column from bn_space_members for the given
	 * user/space pair. Returns 'all' when no membership row exists.
	 *
	 * @param int $user_id  User ID.
	 * @param int $space_id Space ID.
	 * @return string One of 'all', 'mentions_only', or 'none'.
	 */
	public function get_space_pref( int $user_id, int $space_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$pref = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT notification_pref FROM {$wpdb->prefix}bn_space_members
				 WHERE user_id = %d AND space_id = %d",
				$user_id,
				$space_id
			)
		);

		if ( null === $pref || '' === $pref ) {
			return 'all';
		}

		return (string) $pref;
	}

	/**
	 * Set the per-space notification preference for a user.
	 *
	 * Only updates membership rows that already exist — a user must be a member
	 * of the space before their notification preference can be set.
	 *
	 * @param int    $user_id  User ID.
	 * @param int    $space_id Space ID.
	 * @param string $pref     One of 'all', 'mentions_only', or 'none'.
	 * @return bool True when the preference was saved, false when the user has
	 *              no active membership in the given space or $pref is invalid.
	 */
	public function set_space_pref( int $user_id, int $space_id, string $pref ): bool {
		if ( ! in_array( $pref, self::VALID_SPACE_PREFS, true ) ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->update(
			$wpdb->prefix . 'bn_space_members',
			array( 'notification_pref' => $pref ),
			array(
				'user_id'  => $user_id,
				'space_id' => $space_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);

		// $wpdb->update() returns the affected-row count, or false on a DB error.
		// 0 rows means the value already equals the user's choice (e.g. re-picking
		// the default 'all') — a successful no-op, not a failure. Treating it as
		// failure surfaced a false "Could not save" on the first click of a
		// default-valued option. Only an actual DB error (false) is a failure;
		// the controller already verified membership before calling this.
		return false !== $updated;
	}

	/**
	 * Read a user's stored notification channel toggles (raw usermeta map).
	 *
	 * Returns whatever is stored in bn_channel_prefs; callers apply presentation
	 * defaults (e.g. the push toggle depends on whether the Pro push module is
	 * loaded).
	 *
	 * @param int $user_id User id.
	 * @return array<string,mixed>
	 */
	public function get_channel_prefs( int $user_id ): array {
		$stored = get_user_meta( $user_id, 'bn_channel_prefs', true );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Update a user's notification channel toggles (partial — only provided keys change).
	 *
	 * @param int                 $user_id  User id.
	 * @param array<string,mixed> $channels Subset of in_app/email/push/sound.
	 * @return void
	 */
	public function set_channel_prefs( int $user_id, array $channels ): void {
		$current = get_user_meta( $user_id, 'bn_channel_prefs', true );
		$current = is_array( $current ) ? $current : array();

		foreach ( array( 'in_app', 'email', 'push', 'sound' ) as $key ) {
			if ( array_key_exists( $key, $channels ) ) {
				$current[ $key ] = (bool) $channels[ $key ];
			}
		}

		update_user_meta( $user_id, 'bn_channel_prefs', $current );
	}

	/**
	 * List a user's per-space notification prefs (active memberships only).
	 *
	 * @param int $user_id User id.
	 * @return array<int,array{space_id:int,name:string,slug:string,pref:string}>
	 */
	public function list_space_notification_prefs( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.id AS space_id, s.name, s.slug, s.avatar_url, COALESCE( NULLIF( sm.notification_pref, '' ), 'all' ) AS pref
				 FROM {$wpdb->prefix}bn_spaces s
				 INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active'
				 ORDER BY s.name ASC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map(
			static function ( array $row ): array {
				return array(
					'space_id'   => (int) $row['space_id'],
					'name'       => (string) $row['name'],
					'slug'       => (string) $row['slug'],
					'avatar_url' => (string) ( $row['avatar_url'] ?? '' ),
					'pref'       => (string) $row['pref'],
				);
			},
			(array) $rows
		);
	}
}
