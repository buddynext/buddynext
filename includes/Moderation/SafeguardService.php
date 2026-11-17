<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Content safeguard service.
 *
 * Enforces automated content rules before a post is saved:
 *   1. Blocked IP filter     — admin IP blocklist in option buddynext_blocked_ips.
 *   2. Banned word filter    — newline-separated list in option buddynext_banned_words.
 *   3. Blocked domain filter — newline-separated list in option buddynext_blocked_domains.
 *   4. Post rate limit       — max posts per minute per user via DB count.
 *   5. Duplicate content     — holds repeated identical posts within a window.
 *   6. New-member gate       — holds posts for review until user reaches threshold.
 *
 * All thresholds are stored as WordPress options so admins can change them
 * without a code deploy.
 *
 * @package BuddyNext\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Moderation;

use WP_Error;

/**
 * Validates post content against configurable automated safeguards.
 */
class SafeguardService {

	/**
	 * Run all configured safeguard checks in order.
	 *
	 * Returns the first WP_Error encountered, or true when every check passes.
	 * The pending_review error code is intentionally non-fatal — callers should
	 * save the post with status='pending' rather than discarding it.
	 *
	 * @param int    $user_id  Author user ID.
	 * @param string $content  Post content to inspect.
	 * @param string $url      Optional URL attached to the post (link_url field).
	 * @param int    $space_id Target space ID (0 = site feed) for the per-space banned-word list.
	 * @return true|WP_Error True when all checks pass; WP_Error on first failure.
	 */
	public function check( int $user_id, string $content, string $url = '', int $space_id = 0 ): bool|WP_Error {
		// Cheapest, hardest stop first: a blocklisted IP never reaches content checks.
		$ip = $this->check_blocked_ip();
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		$banned = $this->check_banned_words( $content, $space_id );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$domain = $this->check_blocked_domain( $url, $content );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$rate = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$tag = $this->check_banned_hashtags( $content );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}

		/**
		 * Filter the safeguard check result.
		 *
		 * Pro keyword blocklists and ML scoring hooks stack here. Return a
		 * WP_Error to block the post, or return true to allow it through.
		 *
		 * Runs BEFORE the flag-tier gates below (duplicate content, new-member
		 * review): those return hold-for-review verdicts, and a hold must never
		 * outrank a Pro severity=block rule - previously a new member's post
		 * containing a blocked keyword was held instead of rejected because the
		 * gate returned first.
		 *
		 * @since 1.0.0
		 *
		 * @param true|WP_Error $result   True when all hard-stop checks pass; WP_Error on failure.
		 * @param int           $user_id  Author user ID.
		 * @param string        $content  Post content to inspect.
		 * @param string        $link_url Optional URL attached to the post.
		 * @param string        $context  'create' or 'edit'. Create-time-only gates (rate limit,
		 *                                duplicate content, new-member) must not fire on 'edit'.
		 */
		$pro = apply_filters( 'buddynext_safeguard_check', true, $user_id, $content, $url, 'create' );
		if ( is_wp_error( $pro ) ) {
			return $pro;
		}

		$dupe = $this->check_duplicate_content( $user_id, $content );
		if ( is_wp_error( $dupe ) ) {
			return $dupe;
		}

		return $this->check_new_member_gate( $user_id );
	}

	/**
	 * Run only the content-based safeguards: banned words + blocked domains.
	 *
	 * Used when re-scanning EDITED content. The rate-limit, duplicate-content and
	 * new-member gates in check() are create-time concerns that must not fire on
	 * an edit, but banned words and blocked links must still be caught — otherwise
	 * editing is a blind spot. The buddynext_safeguard_check filter still runs so
	 * Pro keyword/ML blocklists apply to edits too — and it is passed $context = 'edit'
	 * so an extension can honour the same create-vs-edit line Free draws here. Without
	 * that argument Pro could not tell the two apart and ran its own rate limit on every
	 * edit, so a member at the hourly cap could not edit their existing posts at all.
	 *
	 * @param string $content  Content to inspect.
	 * @param string $url      Optional attached URL.
	 * @param int    $user_id  Author user ID (passed to the filter).
	 * @param int    $space_id Target space ID (0 = site feed) for the per-space banned-word list.
	 * @param string $context  'edit' (the default - this method's original caller is a post EDIT)
	 *                         or 'create'. This method is ALSO the content-scan path for a new
	 *                         comment, a new DM and a profile save, none of which are edits. It
	 *                         used to hardcode 'edit' for all of them, telling an extension that a
	 *                         brand-new comment was an edit. Inert today, because the only
	 *                         create-time rule counts bn_posts rows - but it would silently exempt
	 *                         those three surfaces from any future create-time rule. The caller
	 *                         knows which it is, so the caller says so.
	 * @param string $object_label What is being written ('post', 'comment', 'space name',
	 *                         'poll option', 'caption', 'space field'), so a banned-word
	 *                         rejection names the object instead of always saying "post".
	 * @return true|WP_Error
	 */
	public function check_content( string $content, string $url = '', int $user_id = 0, int $space_id = 0, string $context = 'edit', string $object_label = 'post' ): bool|WP_Error {
		$banned = $this->check_banned_words( $content, $space_id, $object_label );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$domain = $this->check_blocked_domain( $url, $content );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$tag = $this->check_banned_hashtags( $content, $object_label );
		if ( is_wp_error( $tag ) ) {
			return $tag;
		}

		/** This filter is documented in includes/Moderation/SafeguardService.php */
		return apply_filters( 'buddynext_safeguard_check', true, $user_id, $content, $url, $context );
	}

	/**
	 * Check whether $content contains a banned word or phrase.
	 *
	 * Reads the site-wide list (option buddynext_banned_words) and, when the post
	 * targets a space, that space's own list (space meta `banned_words`, written
	 * by templates/spaces/settings.php). Both are newline-separated and
	 * matching is case-insensitive. Without the per-space read the space list was
	 * write-only.
	 *
	 * Entries match as WHOLE words, not as substrings. Substring matching made a
	 * three-letter entry unusable: `ass` blocked "passionate", "assertive",
	 * "class" and "pass", so an owner adding one common profanity silently
	 * censored ordinary conversation with a message naming no word. An owner who
	 * does want variants writes a `*` (see banned_word_pattern()).
	 *
	 * @param string $content      Post content to inspect.
	 * @param int    $space_id     Target space ID (0 = site feed; skips per-space list).
	 * @param string $object_label What is being written ('post', 'comment', 'space name',
	 *                             'poll option', 'caption', …), so the rejection names the
	 *                             object instead of always saying "post".
	 * @return true|WP_Error
	 */
	private function check_banned_words( string $content, int $space_id = 0, string $object_label = 'post' ): bool|WP_Error {
		$raw = (string) get_option( 'buddynext_banned_words', '' );

		if ( $space_id > 0 ) {
			$space_raw = (string) buddynext_get_space_field( $space_id, 'banned_words' );
			if ( '' !== $space_raw ) {
				$raw = '' !== $raw ? $raw . "\n" . $space_raw : $space_raw;
			}
		}

		$words = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		if ( empty( $words ) ) {
			return true;
		}

		$pattern = $this->banned_word_pattern( $words );

		if ( '' === $pattern || 1 !== preg_match( $pattern, $content ) ) {
			return true;
		}

		// Translate the object label before interpolating it, so a localized site
		// does not render a half-translated sentence — an English literal ('caption',
		// 'album', …) inside a __()'d string (card 10264294340 RFT round 4). Unknown
		// labels fall back to the raw string rather than showing nothing.
		$bn_labels = array(
			'post'              => __( 'post', 'buddynext' ),
			'comment'           => __( 'comment', 'buddynext' ),
			'caption'           => __( 'caption', 'buddynext' ),
			'album'             => __( 'album name', 'buddynext' ),
			'album name'        => __( 'album name', 'buddynext' ),
			'album description' => __( 'album description', 'buddynext' ),
			'space field'       => __( 'space field', 'buddynext' ),
			'space name'        => __( 'space name', 'buddynext' ),
			'space description' => __( 'space description', 'buddynext' ),
			'direct message'    => __( 'direct message', 'buddynext' ),
			'profile field'     => __( 'profile field', 'buddynext' ),
			'poll option'       => __( 'poll option', 'buddynext' ),
		);
		$bn_label  = $bn_labels[ $object_label ] ?? $object_label;

		return new WP_Error(
			'banned_word',
			sprintf(
				/* translators: %s: the localized thing being written — post, comment, space name, poll option, caption. */
				__( 'Your %s contains a word that is not allowed here.', 'buddynext' ),
				$bn_label
			),
			array( 'status' => 422 )
		);
	}

	/**
	 * Compile a banned-word list into one case-insensitive whole-word pattern.
	 *
	 * Word boundaries are lookarounds on word characters rather than `\b`, so an
	 * entry that begins or ends with punctuation still anchors correctly — `\b`
	 * is a transition, and a term like `f**k` has no word character on the side
	 * `\b` would be asserted against.
	 *
	 * A `*` in an entry is the owner's opt-in to the old behaviour, scoped to one
	 * term: `ass*` blocks "asshole" while leaving "passionate" alone. It expands
	 * to `\w*`, so it can only ever widen a match within a single word.
	 *
	 * The `u` modifier puts PCRE in UTF-8 mode AND turns on Unicode character
	 * properties in PHP, which is what makes `\w` cover accented and non-Latin
	 * scripts — without it a whole-word rule would be an ASCII-only rule, and
	 * every non-English community would get the substring bug back.
	 *
	 * @param array<int,string> $words Trimmed, non-empty list entries.
	 * @return string Full pattern including delimiters, or '' when nothing is matchable.
	 */
	private function banned_word_pattern( array $words ): string {
		$parts = array();

		foreach ( $words as $word ) {
			// An entry of nothing but wildcards would match every post ever
			// written. Treat it as an empty line rather than a site-wide mute.
			if ( '' === trim( str_replace( '*', '', $word ) ) ) {
				continue;
			}

			$quoted = preg_quote( $word, '/' );

			// Runs of whitespace in a phrase match any run in the content, so
			// "free  money" pasted with a double space still catches "free money".
			$quoted = (string) preg_replace( '/\s+/', '\s+', $quoted );

			$parts[] = str_replace( '\*', '\w*', $quoted );
		}

		if ( empty( $parts ) ) {
			return '';
		}

		return '/(?<!\w)(?:' . implode( '|', $parts ) . ')(?!\w)/iu';
	}

	/**
	 * Check whether the $url's hostname matches a blocked domain.
	 *
	 * Reads option buddynext_blocked_domains (newline-separated). Skipped when $url
	 * is an empty string.
	 *
	 * @param string $url URL attached to the post (may be empty).
	 * @return true|WP_Error
	 */
	/**
	 * Reject posts that carry a banned hashtag (option buddynext_banned_hashtags).
	 *
	 * The admin hint states "Posts using these tags are rejected" - this gate
	 * makes that true (previously the tag was only silently un-indexed).
	 *
	 * @param string $content      Content to inspect.
	 * @param string $object_label What is being written ('post', 'comment', …), for the message.
	 * @return true|WP_Error True when clean; WP_Error(422) when a banned tag is present.
	 */
	private function check_banned_hashtags( string $content, string $object_label = 'post' ): bool|WP_Error {
		if ( '' === trim( $content ) || ! class_exists( '\\BuddyNext\\Hashtags\\HashtagService' ) ) {
			return true;
		}
		$slug = ( new \BuddyNext\Hashtags\HashtagService() )->first_banned_in_text( $content );
		if ( '' !== $slug ) {
			return new WP_Error(
				'banned_hashtag',
				sprintf(
					/* translators: %s: the object being written, e.g. post, comment, direct message. */
					__( 'Your %s uses a hashtag that is not allowed here.', 'buddynext' ),
					$object_label
				),
				array( 'status' => 422 )
			);
		}
		return true;
	}

	/**
	 * Block posts whose attached link OR body-text URLs hit a blocked domain.
	 *
	 * Matches the exact host or any subdomain of each blocked entry.
	 *
	 * @param string $url     Attached link URL ('' when none).
	 * @param string $content Post body - scanned for pasted URLs too.
	 * @return true|WP_Error True when clean; WP_Error(422) on a blocked host.
	 */
	private function check_blocked_domain( string $url, string $content = '' ): bool|WP_Error {
		$raw     = (string) get_option( 'buddynext_blocked_domains', '' );
		$domains = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		if ( empty( $domains ) ) {
			return true;
		}

		// Collect every host the post carries: the attached link AND any URL
		// pasted into the body - previously only link_url was checked, so a
		// blocked domain in the text sailed through.
		$hosts = array();
		if ( '' !== $url ) {
			$hosts[] = (string) wp_parse_url( $url, PHP_URL_HOST );
		}
		foreach ( wp_extract_urls( $content ) as $found ) {
			$hosts[] = (string) wp_parse_url( $found, PHP_URL_HOST );
		}
		$hosts = array_unique( array_filter( array_map( 'strtolower', $hosts ) ) );

		if ( empty( $hosts ) ) {
			return true;
		}

		foreach ( $domains as $blocked ) {
			$blocked = strtolower( $blocked );
			if ( '' === $blocked ) {
				continue;
			}
			foreach ( $hosts as $host ) {
				// Exact host or any subdomain of the blocked entry.
				if ( $host === $blocked || str_ends_with( $host, '.' . $blocked ) ) {
					return new WP_Error(
						'blocked_domain',
						__( 'This link is not allowed.', 'buddynext' ),
						array( 'status' => 422 )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Check whether the user has exceeded the per-minute post rate limit.
	 *
	 * Reads option buddynext_post_rate_limit (int, default 10). A value of 0 or
	 * below disables the check. Counts rows in bn_posts authored by the user
	 * within the last 60 seconds using a DB query.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( int $user_id ): bool|WP_Error {
		$limit = (int) get_option( 'buddynext_post_rate_limit', 10 );

		if ( $limit <= 0 ) {
			return true;
		}

		// Trusted roles are exempt — site admins (and community moderators) post
		// announcements/bulk content legitimately and should not hit the
		// member-facing flood limit. Filterable for custom exemption rules.
		// Site admins pass buddynext_can() at its first layer.
		$exempt = buddynext_can( $user_id, 'buddynext-moderation/review-queue' );
		/**
		 * Filter whether a user is exempt from the post rate limit.
		 *
		 * @param bool $exempt  Whether the user is exempt.
		 * @param int  $user_id User being checked.
		 */
		if ( (bool) apply_filters( 'buddynext_rate_limit_exempt', $exempt, $user_id ) ) {
			return true;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d
				   AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $count >= $limit ) {
			return new WP_Error(
				'rate_limited',
				__( 'You are posting too quickly. Please wait a moment.', 'buddynext' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Check whether the user's total post count meets the new-member threshold.
	 *
	 * Reads option buddynext_new_member_post_threshold (int, default 0). A
	 * value of 0 disables the gate. When the user's total published post count
	 * is below the threshold a pending_review WP_Error is returned — callers
	 * must save the post with status='pending' rather than rejecting it outright.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	private function check_new_member_gate( int $user_id ): bool|WP_Error {
		$threshold = (int) get_option( 'buddynext_new_member_post_threshold', 0 );

		if ( $threshold <= 0 ) {
			return true;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND status = 'published'",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $total < $threshold ) {
			return new WP_Error(
				'pending_review',
				__( 'Your post has been submitted for review.', 'buddynext' ),
				array(
					'status'        => 202,
					'report_reason' => 'other',
				)
			);
		}

		return true;
	}

	/**
	 * Resolve the requester's client IP, validated.
	 *
	 * The ONE place BuddyNext reads the client address: the IP blocklist, the
	 * login / lost-password / reset / change-email rate limits, the OAuth callback
	 * limit and the sign-up guard (rate limit + blocklist) all call this, and
	 * bin/check-client-ip.py fails the build on any other REMOTE_ADDR read.
	 *
	 * Reads REMOTE_ADDR by default and never trusts a forwarded header on its
	 * own: X-Forwarded-For is client-supplied, so keying a limit on it would let
	 * an attacker mint unlimited buckets. The host or CDN restores the visitor
	 * address (nginx real_ip, Apache mod_remoteip, Cloudflare "restore visitor
	 * IP"), as WordPress core expects; sites that cannot do that map it via the
	 * `buddynext_client_ip` filter. Returns '' when no valid IP can be resolved
	 * so callers fail open.
	 *
	 * @return string A valid IPv4/IPv6 address, or '' when none.
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filter the resolved client IP.
		 *
		 * Proxy/CDN deployments can substitute the forwarded client address here.
		 *
		 * @since 1.0.0
		 *
		 * @param string $ip Raw REMOTE_ADDR (sanitised).
		 */
		$ip = (string) apply_filters( 'buddynext_client_ip', $ip );

		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Members sampled by the shared-address Site Health check.
	 *
	 * Bounded so the check is one indexed presence query plus one primed
	 * usermeta read at any community size.
	 */
	private const PROXY_SAMPLE = 50;

	/**
	 * Distinct members needed before the check draws a conclusion.
	 */
	private const PROXY_MIN_MEMBERS = 5;

	/**
	 * Register the Site Health test that spots an unrestored proxy address.
	 *
	 * @return void
	 */
	public static function register_site_health(): void {
		add_filter(
			'site_status_tests',
			static function ( array $tests ): array {
				$tests['direct']['buddynext_client_ip'] = array(
					'label' => __( 'BuddyNext visitor addresses', 'buddynext' ),
					'test'  => array( self::class, 'site_health_client_ip_test' ),
				);
				return $tests;
			}
		);
	}

	/**
	 * The one address every recently signed-in member shares, if they all share one.
	 *
	 * Reads what WordPress core already records: the IP on each member's login
	 * session (usermeta `session_tokens`, written from REMOTE_ADDR). When several
	 * different members all sign in from the same address, the server is seeing
	 * a proxy, load balancer or CDN, not the visitors. No new data is stored.
	 *
	 * @param int[] $user_ids Members to inspect (most recently active first).
	 * @return string The shared address, or '' when addresses differ or the sample is too small.
	 */
	public static function shared_visitor_ip( array $user_ids ): string {
		$ips = self::recent_sign_in_ips( $user_ids );
		if ( count( $ips ) < self::PROXY_MIN_MEMBERS ) {
			return '';
		}
		$distinct = array_unique( $ips );
		return 1 === count( $distinct ) ? (string) reset( $distinct ) : '';
	}

	/**
	 * The address of each member's latest sign-in, from core's session tokens.
	 *
	 * @param int[] $user_ids Members to inspect.
	 * @return array<int,string> User id => address; members with no recorded address are left out.
	 */
	private static function recent_sign_in_ips( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );
		if ( count( $user_ids ) < self::PROXY_MIN_MEMBERS ) {
			return array();
		}
		update_meta_cache( 'user', $user_ids );

		$ips = array();
		foreach ( $user_ids as $user_id ) {
			$latest = null;
			foreach ( \WP_Session_Tokens::get_instance( $user_id )->get_all() as $session ) {
				if ( empty( $session['ip'] ) ) {
					continue;
				}
				if ( null === $latest || (int) ( $session['login'] ?? 0 ) > (int) ( $latest['login'] ?? 0 ) ) {
					$latest = $session;
				}
			}
			if ( null !== $latest ) {
				$ips[ $user_id ] = (string) $latest['ip'];
			}
		}

		return $ips;
	}

	/**
	 * Site Health: warn when every recent member shows the same address.
	 *
	 * Behind an unrestored proxy every visitor looks like one person, so the
	 * sign-up limit (a few per hour) is shared by the whole site and ten wrong
	 * passwords lock a member out for everyone. The fix belongs to the host, so
	 * the result names the host-side steps in plain words.
	 *
	 * @return array<string,mixed>
	 */
	public static function site_health_client_ip_test(): array {
		$badge = array(
			'label' => __( 'BuddyNext', 'buddynext' ),
			'color' => 'blue',
		);
		// Every passing result says what was actually found: the check is skipped in
		// two cases and cannot judge in a third, and none of those saw different
		// addresses.
		$good = static function ( string $label, string $description ) use ( $badge ): array {
			return array(
				'label'       => $label,
				'status'      => 'good',
				'badge'       => $badge,
				'description' => '<p>' . esc_html( $description ) . '</p>',
				'test'        => 'buddynext_client_ip',
			);
		};

		// A site that maps the address itself has already fixed this.
		if ( has_filter( 'buddynext_client_ip' ) ) {
			return $good(
				__( 'BuddyNext uses the visitor address your site supplies', 'buddynext' ),
				__( 'Code on this site sets the visitor address through the buddynext_client_ip filter, so BuddyNext uses that address and does not check recent sign-ins.', 'buddynext' )
			);
		}

		// Local development installs legitimately see 127.0.0.1 for everyone.
		if ( 'local' === wp_get_environment_type() ) {
			return $good(
				__( 'Visitor address check skipped on a local site', 'buddynext' ),
				__( 'This is a local development site, where every visitor shares one address, so the check is skipped. It runs on your live site.', 'buddynext' )
			);
		}

		$ips = self::recent_sign_in_ips(
			\BuddyNext\Realtime\PresenceService::recent_online_ids( self::PROXY_SAMPLE, WEEK_IN_SECONDS )
		);
		if ( count( $ips ) < self::PROXY_MIN_MEMBERS ) {
			return $good(
				__( 'Not enough recent sign-ins to check visitor addresses', 'buddynext' ),
				sprintf(
					/* translators: %d: number of members needed. */
					__( 'Fewer than %d members signed in during the last week, which is too few to tell whether BuddyNext sees each visitor\'s own address. The check runs again as more members sign in.', 'buddynext' ),
					self::PROXY_MIN_MEMBERS
				)
			);
		}

		$distinct = array_unique( $ips );
		if ( 1 !== count( $distinct ) ) {
			return $good(
				__( 'BuddyNext sees each visitor\'s own address', 'buddynext' ),
				__( 'Recent members signed in from different addresses, so sign-up and sign-in limits apply to each visitor separately.', 'buddynext' )
			);
		}
		$shared = (string) reset( $distinct );

		$private = false === filter_var( $shared, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );

		return array(
			'label'       => __( 'All your visitors appear to come from one address', 'buddynext' ),
			'status'      => 'recommended',
			'badge'       => $badge,
			'description' => '<p>' . sprintf(
				/* translators: %s: the IP address every recent member signed in from. */
				esc_html__( 'Your recent members all signed in from the same address (%s). That usually means a proxy, load balancer or CDN sits in front of the site and the real visitor address is not passed on.', 'buddynext' ),
				esc_html( $shared )
			) . '</p><p>'
				. ( $private
					? esc_html__( 'It is a private network address, which is what a proxy in front of the server looks like.', 'buddynext' ) . ' '
					: '' )
				. esc_html__( 'Until it is fixed, BuddyNext treats every visitor as one person: the sign-up limit is shared by the whole site, and a few wrong passwords can lock a member out for everyone.', 'buddynext' )
				. '</p><p>' . esc_html__( 'Ask your host to pass the real visitor address to WordPress. The usual fixes are:', 'buddynext' ) . '</p><ul>'
				. '<li>' . esc_html__( 'Cloudflare: turn on "Restore visitor IP" (or install the Cloudflare plugin, or add the CF-Connecting-IP header rule on the server).', 'buddynext' ) . '</li>'
				. '<li>' . esc_html__( 'nginx: enable the real_ip module with set_real_ip_from for your proxy and real_ip_header X-Forwarded-For.', 'buddynext' ) . '</li>'
				. '<li>' . esc_html__( 'Apache: enable mod_remoteip with RemoteIPHeader X-Forwarded-For and RemoteIPTrustedProxy for your proxy.', 'buddynext' ) . '</li>'
				. '</ul>',
			'actions'     => '',
			'test'        => 'buddynext_client_ip',
		);
	}

	/**
	 * Whether an IP is on the admin blocklist (option buddynext_blocked_ips).
	 *
	 * The option is a newline/comma-separated list of exact IP addresses,
	 * matching the existing banned-words / blocked-domains safeguard pattern.
	 * Exposed publicly so comment creation can reuse the same gate without
	 * running the full post-content check chain. The parsed list is cached for
	 * the request.
	 *
	 * @param string $ip Client IP (already validated; '' is never blocked).
	 * @return bool
	 */
	public function ip_is_blocked( string $ip ): bool {
		if ( '' === $ip ) {
			return false;
		}

		// Deliberately NOT memoised in a function-level static. get_option() is
		// already object-cached, so the static bought nothing — while making the
		// setting stale for the rest of the request the moment anything read it
		// once, and making it impossible to test.
		$raw = (string) get_option( 'buddynext_blocked_ips', '' );
		if ( '' === trim( $raw ) ) {
			return false;
		}

		$parts = preg_split( '/[\r\n,]+/', $raw );
		$list  = array_values( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ) ) );

		return in_array( $ip, $list, true );
	}

	/**
	 * Block the request when the client IP is on the admin blocklist.
	 *
	 * @return true|WP_Error
	 */
	private function check_blocked_ip(): bool|WP_Error {
		if ( $this->ip_is_blocked( self::client_ip() ) ) {
			return new WP_Error(
				'blocked_ip',
				__( 'Posting from your network is not allowed.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Hold a post for review when it duplicates a recent post by the same user.
	 *
	 * Reads option buddynext_duplicate_post_window (minutes, default 0 = off).
	 * When the user has already posted identical content within the window, a
	 * non-fatal pending_review error is returned so the caller saves the post as
	 * pending (auto-flag) rather than discarding it. Scoped by user_id (indexed)
	 * and a short time window, so the compared row set is tiny.
	 *
	 * @param int    $user_id Author user ID.
	 * @param string $content Post content to inspect.
	 * @return true|WP_Error
	 */
	private function check_duplicate_content( int $user_id, string $content ): bool|WP_Error {
		$window  = (int) get_option( 'buddynext_duplicate_post_window', 0 );
		$trimmed = trim( $content );

		if ( $window <= 0 || '' === $trimmed ) {
			return true;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d
				   AND content = %s
				   AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL %d MINUTE )",
				$user_id,
				$trimmed,
				$window
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $count > 0 ) {
			return new WP_Error(
				'pending_review',
				__( 'This looks like a duplicate of a recent post and has been submitted for review.', 'buddynext' ),
				array(
					'status'        => 202,
					'report_reason' => 'spam',
				)
			);
		}

		return true;
	}

	/**
	 * The report reason a flag verdict is filed under.
	 *
	 * A duplicate-post hold reads as spam and a new-member hold as other, so a moderator
	 * triaging by reason does not meet them as "inappropriate". A rule-engine flag (Pro) keeps
	 * the default.
	 *
	 * @param mixed $verdict A safeguard verdict (WP_Error) or anything else.
	 * @return string A reason slug.
	 */
	public static function report_reason( $verdict ): string {
		$data = $verdict instanceof WP_Error ? $verdict->get_error_data() : null;
		return is_array( $data ) && ! empty( $data['report_reason'] ) ? (string) $data['report_reason'] : 'inappropriate';
	}

	/**
	 * Whether a safeguard verdict is a FLAG rather than a hard block.
	 *
	 * A flag means "let this through, but surface it for review": the content is
	 * published and a system report is filed against it. A hard block (422) is a
	 * genuine rejection. Three shapes count as a flag - an explicit HTTP 202, a
	 * `*_flagged` error code (Pro's rules engine emits `bnpro_keyword_flagged`),
	 * and the `pending_review` holds the new-member and duplicate gates raise.
	 *
	 * This predicate lives on the service that PRODUCES the verdict because every
	 * caller of check()/check_content() has to draw the same line. It was private
	 * to PostService, so post-create published a flagged item and reported it while
	 * comments, direct messages, profile saves and post EDITS returned the WP_Error
	 * verbatim - turning a severity=flag rule into a silent hard block on four of
	 * the five surfaces the rules admin advertises. A flag that blocks IS editorial
	 * pre-approval, which is the one moderation model this product does not have.
	 *
	 * @param mixed $result Verdict from check() or check_content().
	 * @return bool True when the content should publish and be reported for review.
	 */
	public static function is_flag_verdict( $result ): bool {
		if ( ! is_wp_error( $result ) ) {
			return false;
		}

		$code = (string) $result->get_error_code();

		if ( 'pending_review' === $code || ( '' !== $code && str_ends_with( $code, '_flagged' ) ) ) {
			return true;
		}

		$data = $result->get_error_data();

		return is_array( $data ) && isset( $data['status'] ) && 202 === (int) $data['status'];
	}
}
