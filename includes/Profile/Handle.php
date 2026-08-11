<?php
/**
 * BuddyNext — the public member handle.
 *
 * The handle is the string members type after an `@` to mention someone. It is
 * NOT a BuddyNext concept: it is WordPress's `user_nicename`, the field core
 * designates as a user's public slug. This class does not invent an alphabet — it
 * states the one `sanitize_title()` already produces, so that a handle is
 * mentionable exactly when WordPress itself would have written it.
 *
 * That matters because the contract is shared. The feed parser, the comment
 * parser, the composer typeahead, and every partner that renders mentions
 * (Jetonomy, Learnomy, Eventonomy) must agree on where a handle ends, or the
 * client offers a mention the server cannot resolve. Standardising on core's own
 * nicename rules means a partner needs nothing from BuddyNext to agree: it reads
 * `user_nicename` like everyone else and is correct by construction.
 *
 * The set is deliberately NARROW — no `@`, no `.`, no `+` — and that is core's
 * choice, not ours. Widening it to admit an `@` would make every email address in
 * a post a mention attempt ("email me at name@example.com"), a site-wide failure
 * far worse than the imported-data problem such a change would try to solve.
 *
 * A nicename outside this set cannot be produced by WordPress or by BuddyNext;
 * it only arrives by a direct database write, which is what a migration from
 * another platform does. Such a member is silently unmentionable until their
 * nicename is normalised — see the `handles` WP-CLI command.
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

/**
 * The handle character contract, shared by every producer and consumer.
 */
final class Handle {

	/**
	 * Characters a handle may contain, as a regex character-class body.
	 *
	 * The output alphabet of `sanitize_title()` minus `%`: core percent-encodes
	 * some input, and a `%` in a nicename stops every mention parser just as an
	 * `@` does. Keeping `%` out of the set is what makes "safe" mean "mentionable"
	 * rather than merely "core-produced".
	 *
	 * @var string
	 */
	public const CHARSET = 'a-zA-Z0-9_-';

	/**
	 * The mention-matching pattern: an `@` followed by a handle.
	 *
	 * The single definition. Every PHP parser calls this; the composer typeahead
	 * receives {@see self::CHARSET} from PHP rather than repeating it in JS.
	 *
	 * The leading look-behind is what separates a mention from an email address.
	 * A mention's `@` always opens a token, so it is preceded by the start of the
	 * string or by something that is not part of a word; an email address's `@`
	 * is always preceded by its local part. Without this, `support@example.com`
	 * was read as a mention of `example` — which broke in BOTH directions at
	 * once, because this pattern has four consumers:
	 *
	 *   - the renderer, which sliced the address into three pieces and linked the
	 *     middle one to a member profile that does not exist (a 404 in the most
	 *     -read posts on a site, since addresses live in welcome/FAQ copy);
	 *   - the post, comment and Jetonomy mention parsers, which NOTIFIED whoever
	 *     owns that local-part that they had been mentioned in a post that never
	 *     mentioned them.
	 *
	 * The second half is the one nobody reported and the reason this fix belongs
	 * here rather than in the renderer: one pattern, one place, all four callers.
	 *
	 * A `.` is excluded alongside word characters so a dotted local part
	 * (`first.last@example.com`) is not mistaken for a mention either.
	 *
	 * @return string A PCRE pattern with the unicode flag.
	 */
	public static function mention_regex(): string {
		return '/(?<![\w.])@([' . self::CHARSET . ']+)/u';
	}

	/**
	 * Whether a handle can survive a round trip through the mention parsers.
	 *
	 * An empty handle is NOT safe: there is nothing to type after the `@`.
	 *
	 * @param string $handle Handle to test, without a leading `@`.
	 * @return bool
	 */
	public static function is_safe( string $handle ): bool {
		if ( '' === $handle ) {
			return false;
		}

		return 1 === preg_match( '/\A[' . self::CHARSET . ']+\z/', $handle );
	}

	/**
	 * The mentionable form of a handle.
	 *
	 * `sanitize_title()` is the same function core runs on every nicename it
	 * writes, so a repaired handle is indistinguishable from one WordPress would
	 * have made itself — which is the point: the repair returns the row to the
	 * standard rather than layering a BuddyNext-specific override on top of it.
	 *
	 * Returns an empty string when nothing usable survives (a handle of only
	 * foreign characters) — callers must treat that as "cannot repair
	 * automatically" rather than writing an empty nicename, which would break the
	 * member's profile URL entirely.
	 *
	 * @param string $handle Handle to repair, without a leading `@`.
	 * @return string A safe handle, or '' when none can be derived.
	 */
	/**
	 * The member a handle refers to, or null.
	 *
	 * The exact inverse of {@see \BuddyNext\Core\PageRouter::member_handle()}, and
	 * it has to be: that method decides the handle every surface DISPLAYS and the
	 * typeahead OFFERS, so anything resolving a typed handle must ask the same
	 * question in reverse. The three mention parsers each used
	 * `get_user_by( 'login', ... )` instead, which silently disagrees whenever a
	 * login differs from a nicename — a space, a capital, a dot, an email, or any
	 * member with a custom slug. WordPress permits all of those in a login.
	 *
	 * The failure was invisible: the linkifier resolves through PageRouter and so
	 * produced a WORKING profile link, while the notification lookup found nobody
	 * and the mentioned member was simply never told.
	 *
	 * Order mirrors PageRouter::resolve_user() — custom slug, reserved user-{id},
	 * then nicename — with a final user_login attempt so any mention that resolved
	 * before this change still resolves.
	 *
	 * @param string $handle Handle as typed, without a leading `@`.
	 * @return \WP_User|null
	 */
	public static function resolve( string $handle ): ?\WP_User {
		if ( '' === $handle ) {
			return null;
		}

		// 1. Custom slug set by the member — takes precedence exactly as it does
		// when the handle is rendered.
		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$by_meta = get_users(
			array(
				'meta_key'   => 'bn_profile_slug',
				'meta_value' => $handle,
				'number'     => 1,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		if ( ! empty( $by_meta ) && $by_meta[0] instanceof \WP_User ) {
			return $by_meta[0];
		}

		// 2. Reserved "user-{id}" pattern.
		if ( 1 === preg_match( '/\Auser-(\d+)\z/', $handle, $m ) ) {
			$by_id = get_user_by( 'ID', (int) $m[1] );
			if ( $by_id instanceof \WP_User ) {
				return $by_id;
			}
		}

		// 3. user_nicename — what member_handle() falls back to.
		$by_slug = get_user_by( 'slug', $handle );
		if ( $by_slug instanceof \WP_User ) {
			return $by_slug;
		}

		// 4. user_login, so a mention that worked before this change still does.
		// Never reached for a handle any BuddyNext surface displayed.
		$login = sanitize_user( $handle, true );
		if ( '' === $login ) {
			return null;
		}

		$by_login = get_user_by( 'login', $login );

		return $by_login instanceof \WP_User ? $by_login : null;
	}

	/**
	 * The mentionable form of a handle.
	 *
	 * `sanitize_title()` is the same function core runs on every nicename it
	 * writes, so a repaired handle is indistinguishable from one WordPress would
	 * have made itself — which is the point: the repair returns the row to the
	 * standard rather than layering a BuddyNext-specific override on top of it.
	 *
	 * Returns an empty string when nothing usable survives (a handle of only
	 * foreign characters) — callers must treat that as "cannot repair
	 * automatically" rather than writing an empty nicename, which would break the
	 * member's profile URL entirely.
	 *
	 * @param string $handle Handle to repair, without a leading `@`.
	 * @return string A safe handle, or '' when none can be derived.
	 */
	public static function make_safe( string $handle ): string {
		$safe = (string) preg_replace(
			'/[^' . self::CHARSET . ']/',
			'',
			sanitize_title( $handle )
		);

		return self::is_safe( $safe ) ? $safe : '';
	}
}
