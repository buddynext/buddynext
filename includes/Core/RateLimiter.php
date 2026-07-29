<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shared rate-limit / cooldown store for ephemeral, fail-open throttles.
 *
 * Routes throttle state to the persistent object cache when one is present
 * (Redis/Memcached) so high-frequency limiters do NOT write a row to wp_options
 * on every hit — a real cost at 100k members. Without one it uses the dedicated
 * bn_rate_limits table.
 *
 * BOTH paths are atomic, which is the point. The object cache uses
 * wp_cache_incr(); the table uses a single INSERT ... ON DUPLICATE KEY UPDATE so
 * concurrent hits serialise on the row lock. The fallback used to be
 * get_transient() + 1 then set_transient() — three steps that a genuinely
 * concurrent burst raced straight past, because every request read the same
 * count before any of them wrote. Fifteen simultaneous wrong-password logins all
 * recorded themselves as attempt one and none tripped a ten-attempt cap, so
 * brute-force protection was effectively absent on any site without Redis or
 * Memcached — which is most WordPress installs. Rate limiting must not depend on
 * optional infrastructure to work.
 *
 * USE ONLY for throttles where losing the counter on a cache flush is harmless
 * (anti-spam, anti-abuse, self-DoS cooldowns) — "fail open" must be acceptable.
 * Do NOT use for a security lockout whose reset would weaken a credential gate
 * (e.g. the 2FA brute-force counter): those must persist in the DB so an
 * object-cache flush mid-attack cannot hand an attacker more attempts.
 *
 * All keys share the object-cache group 'buddynext_rate'. In the table the
 * caller's key is stored verbatim in rl_key, so callers own their own
 * namespacing (e.g. 'bn_reg_rl_<hash>') and must stay within 191 characters.
 *
 * @package BuddyNext\Core
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Object-cache-first store for ephemeral rate-limit counters and cooldowns.
 *
 * @since 1.0.0
 */
final class RateLimiter {

	/**
	 * Object-cache group for every rate-limit / cooldown key.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private const GROUP = 'buddynext_rate';

	/**
	 * Current integer count for a key. Returns 0 when absent.
	 *
	 * Mirrors `(int) get_transient( $key )`, but reads the object cache when a
	 * persistent one is present.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Throttle key (caller-namespaced).
	 * @return int
	 */
	public static function count( string $key ): int {
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, self::GROUP );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT hits FROM {$wpdb->prefix}bn_rate_limits
				  WHERE rl_key = %s AND expires_at > UTC_TIMESTAMP()",
				$key
			)
		);
	}

	/**
	 * Table holding counters when no persistent object cache is available.
	 *
	 * @since 1.1.1
	 *
	 * @return string Fully-prefixed table name.
	 */
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'bn_rate_limits';
	}

	/**
	 * Atomically increment a fixed-window counter and return the new count.
	 *
	 * Seeds the key with the given TTL on first hit. Under a persistent object
	 * cache the increment is atomic (wp_cache_add + wp_cache_incr), so a burst
	 * of concurrent hits cannot each read the same pre-increment value and slip
	 * past the cap. Without one, a best-effort transient counter is used.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key    Throttle key (caller-namespaced).
	 * @param int    $window Window length in seconds (the key's TTL).
	 * @return int New count after this hit (>= 1).
	 */
	public static function hit( string $key, int $window ): int {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, self::GROUP, $window );
			return (int) wp_cache_incr( $key, 1, self::GROUP );
		}

		global $wpdb;

		/*
		 * One statement, so concurrent hits serialise on the row lock instead of
		 * racing. The previous fallback read the count, added one, and wrote it
		 * back as three separate steps - a burst of simultaneous requests all read
		 * the same value before any of them wrote, so fifteen concurrent login
		 * attempts each recorded themselves as attempt number one.
		 *
		 * LAST_INSERT_ID( expr ) is the standard idiom for reading back the value
		 * an upsert just computed: it sets the connection's insert id as a side
		 * effect, so $wpdb->insert_id carries the post-increment count without a
		 * second SELECT that could itself race.
		 *
		 * The IF() resets rather than continues an EXPIRED window. Without it a
		 * key whose window lapsed would keep counting from its old total and lock
		 * a member out on their first attempt of a fresh window.
		 */
		$expires = gmdate( 'Y-m-d H:i:s', time() + max( 1, $window ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_rate_limits ( rl_key, hits, expires_at )
				 VALUES ( %s, 1, %s )
				 ON DUPLICATE KEY UPDATE
					hits = LAST_INSERT_ID(
						IF( expires_at <= UTC_TIMESTAMP(), 1, hits + 1 )
					),
					expires_at = IF( expires_at <= UTC_TIMESTAMP(), VALUES( expires_at ), expires_at )",
				$key,
				$expires
			)
		);

		// On a fresh INSERT there is no LAST_INSERT_ID() expression to carry the
		// value (rl_key is the primary key, so the table has no AUTO_INCREMENT and
		// insert_id stays 0) - that path is unambiguously the first hit.
		$count = (int) $wpdb->insert_id;

		return $count > 0 ? $count : 1;
	}

	/**
	 * Set a counter to an explicit value with a TTL (non-atomic).
	 *
	 * Mirrors `set_transient( $key, $value, $ttl )`. Use `hit()` instead when a
	 * simple "increment then check" is all you need — it is atomic.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Throttle key (caller-namespaced).
	 * @param int    $value Value to store.
	 * @param int    $ttl   Time-to-live in seconds.
	 * @return void
	 */
	public static function set( string $key, int $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}

		global $wpdb;

		// REPLACE rather than a get-then-set: this is a deliberate overwrite, so
		// last write wins is the intended semantic. It must go to the same table
		// hit() and count() use, or the two stores would disagree about a key.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$wpdb->prefix}bn_rate_limits ( rl_key, hits, expires_at ) VALUES ( %s, %d, %s )",
				$key,
				max( 0, $value ),
				gmdate( 'Y-m-d H:i:s', time() + max( 1, $ttl ) )
			)
		);
	}

	/**
	 * Whether a cooldown marker is currently set for a key.
	 *
	 * Distinguishes "absent" from "present" (unlike count(), which returns 0 in
	 * both cases) so a boolean cooldown — "has a send/export happened recently?"
	 * — reads correctly.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cooldown key (caller-namespaced).
	 * @return bool True when the cooldown is active.
	 */
	public static function is_marked( string $key ): bool {
		if ( wp_using_ext_object_cache() ) {
			$found = false;
			wp_cache_get( $key, self::GROUP, false, $found );
			return (bool) $found;
		}

		global $wpdb;

		// Presence, not value — a cooldown marker of 0 is still an armed cooldown,
		// which is the whole reason this method exists separately from count().
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}bn_rate_limits WHERE rl_key = %s AND expires_at > UTC_TIMESTAMP()",
				$key
			)
		);
	}

	/**
	 * Arm a cooldown marker for a key for the given TTL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Cooldown key (caller-namespaced).
	 * @param int    $ttl Cooldown length in seconds.
	 * @return void
	 */
	public static function mark( string $key, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, 1, self::GROUP, $ttl );
			return;
		}
		self::set( $key, 1, $ttl );
	}

	/**
	 * Clear a key (e.g. on a successful verify that should reset the counter).
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Throttle / cooldown key (caller-namespaced).
	 * @return void
	 */
	public static function clear( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::table(), array( 'rl_key' => $key ), array( '%s' ) );
	}

	/**
	 * Delete expired counter rows.
	 *
	 * The table is written on throttled routes only, but a login page under
	 * attack writes one row per attacker key, and nothing else would ever remove
	 * them once their window lapsed. Expired rows are already ignored by every
	 * read (each filters on expires_at), so this is housekeeping rather than
	 * correctness - it keeps the table from growing without bound on a site that
	 * gets probed for months.
	 *
	 * @since 1.1.1
	 *
	 * @return int Rows removed.
	 */
	public static function purge_expired(): int {
		if ( wp_using_ext_object_cache() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			"DELETE FROM {$wpdb->prefix}bn_rate_limits WHERE expires_at <= UTC_TIMESTAMP()"
		);
	}
}
