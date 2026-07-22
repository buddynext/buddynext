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
	 * @return string A PCRE pattern with the unicode flag.
	 */
	public static function mention_regex(): string {
		return '/@([' . self::CHARSET . ']+)/u';
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
	public static function make_safe( string $handle ): string {
		$safe = (string) preg_replace(
			'/[^' . self::CHARSET . ']/',
			'',
			sanitize_title( $handle )
		);

		return self::is_safe( $safe ) ? $safe : '';
	}
}
