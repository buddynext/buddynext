<?php
/**
 * Content safeguard service.
 *
 * Enforces automated content rules before a post is created:
 *   - Banned word filter   — admin-configured list, per-word or per-phrase.
 *   - Post rate limiter    — max posts per user per rolling minute window.
 *   - Link domain throttle — caps the number of distinct URLs per post.
 *   - New-member gate      — marks posts from brand-new accounts as 'pending'.
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
	 * WordPress option key — comma-separated list of banned words/phrases.
	 */
	private const OPT_BANNED_WORDS = 'buddynext_banned_words';

	/**
	 * WordPress option key — max posts a user may create per minute (int).
	 */
	private const OPT_RATE_LIMIT = 'buddynext_rate_limit_posts_per_minute';

	/**
	 * WordPress option key — max URLs allowed in a single post (int).
	 */
	private const OPT_MAX_LINKS = 'buddynext_max_links_per_post';

	/**
	 * WordPress option key — comma-separated list of blocked link domains.
	 */
	private const OPT_BLOCKED_DOMAINS = 'buddynext_blocked_link_domains';

	/**
	 * WordPress option key — account age in days before the new-member gate lifts (int).
	 */
	private const OPT_NEW_MEMBER_DAYS = 'buddynext_new_member_gate_days';

	/**
	 * Cache group for rate-limit counters.
	 */
	private const CACHE_GROUP = 'buddynext_safeguards';

	/**
	 * Run all configured safeguard checks against the given content and user.
	 *
	 * Returns WP_Error when a check fails so the caller can surface the
	 * reason to the client without persisting the post.
	 *
	 * @param int    $user_id Author user ID.
	 * @param string $content Raw post content.
	 * @param string $link_url Optional URL being shared.
	 * @return true|WP_Error True when all checks pass.
	 */
	public function check( int $user_id, string $content, string $link_url = '' ): true|WP_Error {
		$banned = $this->check_banned_words( $content );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$rate = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$links = $this->check_link_count( $content );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$domain = $this->check_blocked_domain( $content, $link_url );
		if ( is_wp_error( $domain ) ) {
			return $domain;
		}

		return true;
	}

	/**
	 * Determine whether the given user's post should be queued for approval.
	 *
	 * Returns 'pending' for brand-new accounts within the configured gate window,
	 * 'published' otherwise.
	 *
	 * @param int $user_id Author user ID.
	 * @return string Post status: 'published' or 'pending'.
	 */
	public function resolve_status( int $user_id ): string {
		$gate_days = (int) get_option( self::OPT_NEW_MEMBER_DAYS, 0 );

		if ( $gate_days <= 0 ) {
			return 'published';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'published';
		}

		$registered_ts  = strtotime( $user->user_registered );
		$gate_threshold = time() - ( $gate_days * DAY_IN_SECONDS );

		if ( $registered_ts > $gate_threshold ) {
			return 'pending';
		}

		return 'published';
	}

	/**
	 * Check whether $content contains a banned word or phrase.
	 *
	 * Comparison is case-insensitive. Skips the check when no banned words
	 * are configured.
	 *
	 * @param string $content Post content.
	 * @return true|WP_Error
	 */
	private function check_banned_words( string $content ): true|WP_Error {
		$raw = (string) get_option( self::OPT_BANNED_WORDS, '' );
		if ( '' === trim( $raw ) ) {
			return true;
		}

		$words   = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
		$content = strtolower( $content );

		foreach ( $words as $word ) {
			if ( '' !== $word && str_contains( $content, $word ) ) {
				return new WP_Error(
					'banned_word',
					__( 'Your post contains a word or phrase that is not allowed.', 'buddynext' )
				);
			}
		}

		return true;
	}

	/**
	 * Check whether the user has exceeded their per-minute post rate limit.
	 *
	 * Uses a sliding-window counter stored in the object cache. The counter
	 * expires after 60 seconds so the window resets automatically.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	private function check_rate_limit( int $user_id ): true|WP_Error {
		$max = (int) get_option( self::OPT_RATE_LIMIT, 0 );
		if ( $max <= 0 ) {
			return true;
		}

		$key = 'rate_' . $user_id;
		$current = (int) wp_cache_get( $key, self::CACHE_GROUP );

		if ( $current >= $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'You are posting too quickly. Please wait a moment before posting again.', 'buddynext' )
			);
		}

		wp_cache_set( $key, $current + 1, self::CACHE_GROUP, 60 );

		return true;
	}

	/**
	 * Check whether the post contains more URLs than the configured maximum.
	 *
	 * Counts http:// and https:// occurrences as a fast proxy for link count.
	 *
	 * @param string $content Post content.
	 * @return true|WP_Error
	 */
	private function check_link_count( string $content ): true|WP_Error {
		$max = (int) get_option( self::OPT_MAX_LINKS, 0 );
		if ( $max <= 0 ) {
			return true;
		}

		$count = substr_count( $content, 'http://' ) + substr_count( $content, 'https://' );

		if ( $count > $max ) {
			return new WP_Error(
				'too_many_links',
				/* translators: %d: maximum number of links allowed */
				sprintf( __( 'Posts may contain at most %d link(s).', 'buddynext' ), $max )
			);
		}

		return true;
	}

	/**
	 * Check whether the post or its link_url references a blocked domain.
	 *
	 * @param string $content  Post content.
	 * @param string $link_url Explicit URL from the link_url field.
	 * @return true|WP_Error
	 */
	private function check_blocked_domain( string $content, string $link_url ): true|WP_Error {
		$raw = (string) get_option( self::OPT_BLOCKED_DOMAINS, '' );
		if ( '' === trim( $raw ) ) {
			return true;
		}

		$domains = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
		$haystack = strtolower( $content . ' ' . $link_url );

		foreach ( $domains as $domain ) {
			if ( '' !== $domain && str_contains( $haystack, $domain ) ) {
				return new WP_Error(
					'blocked_domain',
					__( 'Your post contains a link to a blocked domain.', 'buddynext' )
				);
			}
		}

		return true;
	}
}
