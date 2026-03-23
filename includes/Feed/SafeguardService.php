<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Content safeguard service.
 *
 * Enforces automated content rules before a post is saved:
 *   1. Banned word filter    — newline-separated list in option bn_banned_words.
 *   2. Blocked domain filter — newline-separated list in option bn_blocked_domains.
 *   3. Post rate limit       — max posts per minute per user via DB count.
 *   4. New-member gate       — holds posts for review until user reaches threshold.
 *
 * All thresholds are stored as WordPress options so admins can change them
 * without a code deploy.
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

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
	 * @param int    $user_id Author user ID.
	 * @param string $content Post content to inspect.
	 * @param string $url     Optional URL attached to the post (link_url field).
	 * @return true|WP_Error True when all checks pass; WP_Error on first failure.
	 */
	public function check( int $user_id, string $content, string $url = '' ): true|WP_Error {
		$banned = $this->check_banned_words( $content );
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

		$gate = $this->check_new_member_gate( $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		return true;
	}

	/**
	 * Check whether $content contains a banned word or phrase.
	 *
	 * Reads option bn_banned_words (newline-separated). Matching is
	 * case-insensitive; any substring match causes a failure.
	 *
	 * @param string $content Post content to inspect.
	 * @return true|WP_Error
	 */
	private function check_banned_words( string $content ): true|WP_Error {
		$raw   = (string) get_option( 'bn_banned_words', '' );
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
	 * Reads option bn_blocked_domains (newline-separated). Skipped when $url
	 * is an empty string.
	 *
	 * @param string $url URL attached to the post (may be empty).
	 * @return true|WP_Error
	 */
	private function check_blocked_domain( string $url ): true|WP_Error {
		if ( '' === $url ) {
			return true;
		}

		$raw     = (string) get_option( 'bn_blocked_domains', '' );
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
	 * Reads option bn_post_rate_limit (int, default 10). A value of 0 or below
	 * disables the check. Counts rows in bn_posts authored by the user within
	 * the last 60 seconds using a DB query.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( int $user_id ): true|WP_Error {
		$limit = (int) get_option( 'bn_post_rate_limit', 10 );

		if ( $limit <= 0 ) {
			return true;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts
				 WHERE user_id = %d
				   AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
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
	 * Reads option bn_new_member_post_threshold (int, default 0). A value of 0
	 * disables the gate. When the user's total published post count is below the
	 * threshold a pending_review WP_Error is returned — callers must save the
	 * post with status='pending' rather than rejecting it outright.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	private function check_new_member_gate( int $user_id ): true|WP_Error {
		$threshold = (int) get_option( 'bn_new_member_post_threshold', 0 );

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
}
