<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Content safeguard service.
 *
 * Enforces automated content rules before a post is saved:
 *   1. Blocked IP filter     — admin IP blocklist in option buddynext_blocked_ips.
 *   2. Banned word filter    — newline-separated list in option bn_banned_words.
 *   3. Blocked domain filter — newline-separated list in option bn_blocked_domains.
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
	public function check( int $user_id, string $content, string $url = '', int $space_id = 0 ): true|WP_Error {
		// Cheapest, hardest stop first: a blocklisted IP never reaches content checks.
		$ip = $this->check_blocked_ip();
		if ( is_wp_error( $ip ) ) {
			return $ip;
		}

		$banned = $this->check_banned_words( $content, $space_id );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$domain = $this->check_blocked_domain( $url );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		$rate = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$dupe = $this->check_duplicate_content( $user_id, $content );
		if ( is_wp_error( $dupe ) ) {
			return $dupe;
		}

		$gate = $this->check_new_member_gate( $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		/**
		 * Filter the final safeguard check result.
		 *
		 * Pro keyword blocklists and ML scoring hooks stack here. Return a
		 * WP_Error to block the post, or return true to allow it through.
		 * The $result parameter will be true when all built-in checks pass.
		 *
		 * @since 1.0.0
		 *
		 * @param true|WP_Error $result   True when all built-in checks pass; WP_Error on failure.
		 * @param int           $user_id  Author user ID.
		 * @param string        $content  Post content to inspect.
		 * @param string        $link_url Optional URL attached to the post.
		 */
		return apply_filters( 'buddynext_safeguard_check', true, $user_id, $content, $url );
	}

	/**
	 * Run only the content-based safeguards: banned words + blocked domains.
	 *
	 * Used when re-scanning EDITED content. The rate-limit, duplicate-content and
	 * new-member gates in check() are create-time concerns that must not fire on
	 * an edit, but banned words and blocked links must still be caught — otherwise
	 * editing is a blind spot. The buddynext_safeguard_check filter still runs so
	 * Pro keyword/ML blocklists apply to edits too.
	 *
	 * @param string $content  Content to inspect.
	 * @param string $url      Optional attached URL.
	 * @param int    $user_id  Author user ID (passed to the filter).
	 * @param int    $space_id Target space ID (0 = site feed) for the per-space banned-word list.
	 * @return true|WP_Error
	 */
	public function check_content( string $content, string $url = '', int $user_id = 0, int $space_id = 0 ): true|WP_Error {
		$banned = $this->check_banned_words( $content, $space_id );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$domain = $this->check_blocked_domain( $url );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		return apply_filters( 'buddynext_safeguard_check', true, $user_id, $content, $url );
	}

	/**
	 * Check whether $content contains a banned word or phrase.
	 *
	 * Reads the site-wide list (option buddynext_banned_words) and, when the post
	 * targets a space, that space's own list (option bn_space_{id}_banned_words —
	 * written by templates/spaces/settings.php). Both are newline-separated;
	 * matching is case-insensitive and any substring match fails. Without the
	 * per-space read the space list was write-only.
	 *
	 * @param string $content  Post content to inspect.
	 * @param int    $space_id Target space ID (0 = site feed; skips per-space list).
	 * @return true|WP_Error
	 */
	private function check_banned_words( string $content, int $space_id = 0 ): true|WP_Error {
		$raw = (string) get_option( 'buddynext_banned_words', '' );

		if ( $space_id > 0 ) {
			$space_raw = (string) get_option( 'bn_space_' . $space_id . '_banned_words', '' );
			if ( '' !== $space_raw ) {
				$raw = '' !== $raw ? $raw . "\n" . $space_raw : $space_raw;
			}
		}

		$words = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		if ( empty( $words ) ) {
			return true;
		}

		$lower = strtolower( $content );

		foreach ( $words as $word ) {
			if ( '' !== $word && str_contains( $lower, strtolower( $word ) ) ) {
				return new WP_Error(
					'banned_word',
					__( 'Your post contains a prohibited word.', 'buddynext' ),
					array( 'status' => 422 )
				);
			}
		}

		return true;
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
	private function check_blocked_domain( string $url ): true|WP_Error {
		if ( '' === $url ) {
			return true;
		}

		$raw     = (string) get_option( 'buddynext_blocked_domains', '' );
		$domains = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );

		if ( empty( $domains ) ) {
			return true;
		}

		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return true;
		}

		foreach ( $domains as $blocked ) {
			if ( '' !== $blocked && strtolower( $host ) === strtolower( $blocked ) ) {
				return new WP_Error(
					'blocked_domain',
					__( 'This link is not allowed.', 'buddynext' ),
					array( 'status' => 422 )
				);
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
	private function check_rate_limit( int $user_id ): true|WP_Error {
		$limit = (int) get_option( 'buddynext_post_rate_limit', 10 );

		if ( $limit <= 0 ) {
			return true;
		}

		// Trusted roles are exempt — site admins (and community moderators) post
		// announcements/bulk content legitimately and should not hit the
		// member-facing flood limit. Filterable for custom exemption rules.
		$exempt = user_can( $user_id, 'manage_options' )
			|| buddynext_can( $user_id, 'buddynext-moderation/review-queue' );
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
	private function check_new_member_gate( int $user_id ): true|WP_Error {
		$threshold = (int) get_option( 'buddynext_new_member_post_threshold', 0 );

		if ( $threshold <= 0 ) {
			return true;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d",
				$user_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $total < $threshold ) {
			return new WP_Error(
				'pending_review',
				__( 'Your post has been submitted for review.', 'buddynext' ),
				array( 'status' => 202 )
			);
		}

		return true;
	}

	/**
	 * Resolve the requester's client IP, validated.
	 *
	 * Reads REMOTE_ADDR by default. Sites behind a proxy/CDN can map the real
	 * client address (e.g. from X-Forwarded-For) via the `buddynext_client_ip`
	 * filter. Returns '' when no valid IP can be resolved so callers fail open.
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

		static $list = null;
		if ( null === $list ) {
			$raw   = (string) get_option( 'buddynext_blocked_ips', '' );
			$parts = preg_split( '/[\r\n,]+/', $raw );
			$list  = array_values( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array() ) ) );
		}

		if ( empty( $list ) ) {
			return false;
		}

		return in_array( $ip, $list, true );
	}

	/**
	 * Block the request when the client IP is on the admin blocklist.
	 *
	 * @return true|WP_Error
	 */
	private function check_blocked_ip(): true|WP_Error {
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
	private function check_duplicate_content( int $user_id, string $content ): true|WP_Error {
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
				array( 'status' => 202 )
			);
		}

		return true;
	}
}
