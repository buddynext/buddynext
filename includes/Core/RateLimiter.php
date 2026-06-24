<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shared rate-limit / cooldown store for ephemeral, fail-open throttles.
 *
 * Routes throttle state to the persistent object cache when one is present
 * (Redis/Memcached) so high-frequency limiters do NOT write a row to wp_options
 * on every hit — a real cost at 100k members — and falls back to a transient
 * when no persistent cache exists. The object-cache path is also atomic for
 * counters (wp_cache_incr), closing the get-then-set race a transient counter
 * has under concurrency.
 *
 * USE ONLY for throttles where losing the counter on a cache flush is harmless
 * (anti-spam, anti-abuse, self-DoS cooldowns) — "fail open" must be acceptable.
 * Do NOT use for a security lockout whose reset would weaken a credential gate
 * (e.g. the 2FA brute-force counter): those must persist in the DB transient so
 * an object-cache flush mid-attack cannot hand an attacker more attempts.
 *
 * All keys share the object-cache group 'buddynext_rate'. For the transient
 * fallback the caller's key is used verbatim, so callers own their own
 * namespacing (e.g. 'bn_reg_rl_<hash>').
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
		return (int) get_transient( $key );
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
		$count = (int) get_transient( $key ) + 1;
		set_transient( $key, $count, $window );
		return $count;
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
		set_transient( $key, $value, $ttl );
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
		return false !== get_transient( $key );
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
		set_transient( $key, 1, $ttl );
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
		delete_transient( $key );
	}
}
