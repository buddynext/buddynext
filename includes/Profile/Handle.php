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
	 * Shortest handle a member may claim.
	 *
	 * Three, which is what the shortest real handles need (`ana`, `bob`) and what
	 * LinkedIn allows. Twitter is 4 and Facebook 5; WordPress itself enforces no
	 * minimum at all, which is how a one-character handle gets in. Measured on a
	 * live install before choosing: zero members were under 3, so the floor costs
	 * nobody their existing handle.
	 *
	 * @since 1.1.6
	 * @var int
	 */
	public const MIN_LENGTH = 3;

	/**
	 * Longest handle a member may claim.
	 *
	 * Thirty — Instagram and Mastodon's limit, and comfortably inside the hard
	 * ceiling that actually matters: `wp_users.user_nicename` is varchar(50), so
	 * anything longer is truncated by MySQL rather than refused, which would leave
	 * the handle and the URL disagreeing. The longest nicename on the install this
	 * was measured against is 23.
	 *
	 * Not set to 50: a handle that long is unreadable in a mention, wraps in the
	 * directory, and makes a URL nobody can share by voice. The column is the
	 * ceiling, not the target.
	 *
	 * @since 1.1.6
	 * @var int
	 */
	public const MAX_LENGTH = 30;

	/**
	 * Whether a handle is one a member may claim — safe AND correctly sized.
	 *
	 * Charset and length in one answer. is_safe() covers the first — can the
	 * mention parsers round-trip this — and this adds the bounds, so every writer
	 * asks one question instead of two and cannot answer them differently.
	 *
	 * @since 1.1.6
	 *
	 * @param string $handle Handle without a leading `@`.
	 * @return true|\WP_Error True, or the reason it cannot be used.
	 */
	public static function validate( string $handle ): bool|\WP_Error {
		$length = strlen( $handle );

		/**
		 * Filter the handle length bounds.
		 *
		 * The CHARSET is deliberately not filterable — it is the mention parser's
		 * contract, and widening it produces handles nobody can @mention. Length
		 * is a judgement call, so it is the owner's.
		 *
		 * @since 1.1.6
		 *
		 * @param array{0:int,1:int} $bounds [ min, max ].
		 */
		list( $min, $max ) = (array) apply_filters(
			'buddynext_handle_length_bounds',
			array( self::MIN_LENGTH, self::MAX_LENGTH )
		);

		if ( $length < (int) $min ) {
			return new \WP_Error(
				'handle_too_short',
				sprintf(
					/* translators: %d: minimum number of characters. */
					_n( 'Your handle must be at least %d character.', 'Your handle must be at least %d characters.', (int) $min, 'buddynext' ),
					(int) $min
				),
				array( 'status' => 422 )
			);
		}

		if ( $length > (int) $max ) {
			return new \WP_Error(
				'handle_too_long',
				sprintf(
					/* translators: %d: maximum number of characters. */
					_n( 'Your handle can be at most %d character.', 'Your handle can be at most %d characters.', (int) $max, 'buddynext' ),
					(int) $max
				),
				array( 'status' => 422 )
			);
		}

		if ( ! self::is_safe( $handle ) ) {
			return new \WP_Error(
				'handle_unusable_characters',
				__( 'Handles can use letters, numbers, hyphens and underscores only.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		return true;
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

		// 4. A handle this member USED to hold. Renaming must not silently break
		// every mention and link written before the rename — and because
		// is_slug_available() refuses a used handle to everyone else, an old
		// mention can never start pointing at a different person.
		$previous_owner = self::previous_owner( $handle );
		if ( $previous_owner instanceof \WP_User ) {
			return $previous_owner;
		}

		// 5. user_login, so a mention that worked before this change still does.
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

	/**
	 * User meta holding every handle this member has previously used.
	 *
	 * @since 1.1.6
	 * @var string
	 */
	public const HISTORY_META = 'bn_previous_handles';

	/**
	 * Set a member's handle — the ONE place a handle is written.
	 *
	 * A handle is one value with two homes. `bn_profile_slug` is what BuddyNext
	 * reads; `user_nicename` is what WordPress reads — author archives, the REST
	 * `slug` field, the admin Users list. They used to be written independently,
	 * so a member with a custom slug had TWO live public identities and the two
	 * screens disagreed about who they were: BuddyNext showed @simmy while
	 * wp-admin showed sim_member. Writing both here is what makes them one.
	 *
	 * The old handle is recorded before it is replaced. Until now, old mentions
	 * and shared links survived a rename only because user_nicename happened to
	 * stay put — moving it removes that accident, so the history replaces it with
	 * something deliberate: resolve() reads it, and is_slug_available() refuses
	 * it to everyone else, which also closes the case where Alice renames, Bob
	 * takes @alice, and every old mention of Alice silently becomes a mention of
	 * Bob.
	 *
	 * Callers must have already checked availability — this writes, it does not
	 * adjudicate. It re-validates the handle itself because a write path that
	 * trusts its callers is one refactor away from being wrong.
	 *
	 * @since 1.1.6
	 *
	 * @param int    $user_id Member.
	 * @param string $handle  New handle, already sanitized.
	 * @return true|\WP_Error
	 */
	public static function set( int $user_id, string $handle ): bool|\WP_Error {
		$handle = sanitize_title( $handle );
		$valid  = self::validate( $handle );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return new \WP_Error( 'no_user', __( 'No account found.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$previous = self::current( $user_id );

		update_user_meta( $user_id, 'bn_profile_slug', $handle );

		// Keep WordPress's own idea of this member in step. wp_update_user()
		// de-duplicates a nicename by appending -2, which would silently hand the
		// member a handle they did not choose — availability was already checked,
		// so that path means something is wrong and the caller should hear about
		// it rather than have it papered over.
		if ( $user->user_nicename !== $handle ) {
			$updated = wp_update_user(
				array(
					'ID'            => $user_id,
					'user_nicename' => $handle,
				)
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}

			$fresh = get_userdata( $user_id );
			if ( $fresh instanceof \WP_User && $fresh->user_nicename !== $handle ) {
				return new \WP_Error(
					'handle_not_applied',
					__( 'That handle could not be applied. Please try another.', 'buddynext' ),
					array( 'status' => 409 )
				);
			}
		}

		self::remember( $user_id, $previous, $handle );
		clean_user_cache( $user_id );

		return true;
	}

	/**
	 * The handle a member is using right now.
	 *
	 * @since 1.1.6
	 *
	 * @param int $user_id Member.
	 * @return string
	 */
	public static function current( int $user_id ): string {
		$custom = (string) get_user_meta( $user_id, 'bn_profile_slug', true );
		if ( '' !== $custom ) {
			return $custom;
		}

		$user = get_userdata( $user_id );

		return $user instanceof \WP_User ? (string) $user->user_nicename : '';
	}

	/**
	 * Record a handle the member has stopped using.
	 *
	 * Never records the handle they have just taken, so a member who renames and
	 * renames back does not end up owning their current handle twice.
	 *
	 * @since 1.1.6
	 *
	 * @param int    $user_id  Member.
	 * @param string $previous Handle being left behind.
	 * @param string $current  Handle being taken.
	 * @return void
	 */
	private static function remember( int $user_id, string $previous, string $current ): void {
		if ( '' === $previous || $previous === $current ) {
			return;
		}

		$history = self::history( $user_id );

		// Taking back a handle you used before removes it from the history: it is
		// current again, and a member should not own the same handle twice. A->B->A
		// otherwise left A in both places, which reads as a bug to the next person
		// and would confuse any future "release a handle" flow.
		$history = array_values( array_diff( $history, array( $current ) ) );

		if ( in_array( $previous, $history, true ) ) {
			update_user_meta( $user_id, self::HISTORY_META, $history );
			return;
		}

		$history[] = $previous;

		/**
		 * Filter how many previous handles are kept per member.
		 *
		 * Kept forever by default: the point is that a link written years ago
		 * still reaches the right person. A site may cap it.
		 *
		 * @since 1.1.6
		 *
		 * @param int $limit 0 for unlimited.
		 */
		$limit = (int) apply_filters( 'buddynext_handle_history_limit', 0 );
		if ( $limit > 0 && count( $history ) > $limit ) {
			$history = array_slice( $history, -$limit );
		}

		update_user_meta( $user_id, self::HISTORY_META, array_values( $history ) );
	}

	/**
	 * Handles this member has previously used.
	 *
	 * @since 1.1.6
	 *
	 * @param int $user_id Member.
	 * @return string[]
	 */
	public static function history( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::HISTORY_META, true );

		return is_array( $stored ) ? array_values( array_filter( array_map( 'strval', $stored ) ) ) : array();
	}

	/**
	 * The member who used this handle before, if anyone.
	 *
	 * @since 1.1.6
	 *
	 * @param string $handle Handle without a leading `@`.
	 * @return \WP_User|null
	 */
	public static function previous_owner( string $handle ): ?\WP_User {
		if ( '' === $handle ) {
			return null;
		}

		// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$users = get_users(
			array(
				'meta_key'     => self::HISTORY_META,
				'meta_value'   => '"' . $handle . '"',
				'meta_compare' => 'LIKE',
				'number'       => 1,
			)
		);
		// phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value

		foreach ( $users as $user ) {
			if ( $user instanceof \WP_User && in_array( $handle, self::history( (int) $user->ID ), true ) ) {
				return $user;
			}
		}

		return null;
	}
}
