<?php
/**
 * Content safeguard service.
 *
 * Enforces automated content rules before a post is created:
 *   - Banned word filter   — admin-configured list, whole-word matching.
 *   - Post rate limiter    — max posts per user per rolling hour window.
 *   - Link throttle        — caps the number of distinct URLs per post.
 *   - New-member gate      — blocks brand-new accounts from posting.
 *
 * All thresholds are stored as WordPress options so admins can change them
 * without a code deploy.  Rate-limit counters are stored in the object
 * cache via CacheService (group: buddynext).
 *
 * CONTAINER BINDING NOTE:
 *   This class must be bound in Plugin.php as:
 *     $container->bind( 'safeguard', fn( $c ) => new SafeguardService( $c->get( 'cache' ) ) );
 *   The legacy 'safeguards' binding (no-arg constructor) should be replaced.
 *   PostService should receive the new binding:
 *     $container->bind( 'post_service', fn( $c ) => new PostService( $c->get( 'safeguard' ) ) );
 *
 * @package BuddyNext\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Core\CacheService;
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
	 * WordPress option key — max posts a user may create per hour (int, 0 = disabled).
	 */
	private const OPT_RATE_LIMIT = 'buddynext_post_rate_limit';

	/**
	 * WordPress option key — max URLs allowed in a single post (int, 0 = disabled).
	 */
	private const OPT_MAX_LINKS = 'buddynext_max_links_per_post';

	/**
	 * WordPress option key — account age in days before new-member gate lifts (int, 0 = disabled).
	 */
	private const OPT_NEW_MEMBER_DAYS = 'buddynext_new_member_days';

	/**
	 * Cache key for the parsed banned-words list (TTL 60 s).
	 */
	private const CACHE_KEY_BANNED_WORDS = 'bn_banned_words';

	/**
	 * Rate-limit counter TTL in seconds (1 hour rolling window).
	 */
	private const RATE_LIMIT_TTL = 3600;

	/**
	 * Object-cache wrapper.
	 *
	 * @var CacheService
	 */
	private CacheService $cache;

	/**
	 * Set up the safeguard service with its cache dependency.
	 *
	 * @param CacheService $cache Object-cache wrapper.
	 */
	public function __construct( CacheService $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Run all configured safeguard checks against the given content and user.
	 *
	 * Checks are applied in order; the first failure short-circuits and is
	 * returned to the caller so the reason can be surfaced to the client
	 * without persisting the post.
	 *
	 * @param int    $user_id Author user ID.
	 * @param string $content Raw post content.
	 * @return true|WP_Error True when all checks pass; WP_Error on first failure.
	 */
	public function check( int $user_id, string $content ): true|WP_Error {
		$banned = $this->check_banned_words( $content );
		if ( is_wp_error( $banned ) ) {
			return $banned;
		}

		$rate = $this->check_rate_limit( $user_id );
		if ( is_wp_error( $rate ) ) {
			return $rate;
		}

		$links = $this->check_link_throttle( $content );
		if ( is_wp_error( $links ) ) {
			return $links;
		}

		$gate = $this->check_new_member_gate( $user_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
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
		$days = (int) get_option( self::OPT_NEW_MEMBER_DAYS, 0 );

		if ( $days <= 0 ) {
			return 'published';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return 'published';
		}

		$registered_ts  = strtotime( $user->user_registered );
		$gate_threshold = time() - ( $days * DAY_IN_SECONDS );

		if ( $registered_ts > $gate_threshold ) {
			return 'pending';
		}

		return 'published';
	}

	/**
	 * Increment the rate-limit counter for a user after a successful post.
	 *
	 * Must be called by the consumer (e.g. PostController) after a post has
	 * been created successfully so that the counter only counts committed posts.
	 *
	 * @param int $user_id Author user ID.
	 * @return void
	 */
	public function increment_rate_limit( int $user_id ): void {
		$key     = "bn_rate_{$user_id}";
		$current = $this->cache->get( $key );
		$count   = ( null === $current ) ? 1 : ( (int) $current + 1 );
		$this->cache->set( $key, $count, self::RATE_LIMIT_TTL );
	}

	/**
	 * Check whether $content contains a banned word or phrase.
	 *
	 * Words are matched case-insensitively using whole-word boundaries (\b)
	 * so that partial matches inside longer words are not flagged.
	 * The parsed word list is cached for 60 seconds to avoid repeated option
	 * reads on high-traffic pages.
	 *
	 * @param string $content Post content to inspect.
	 * @return true|WP_Error
	 */
	public function check_banned_words( string $content ): true|WP_Error {
		$words = $this->cache->get( self::CACHE_KEY_BANNED_WORDS );

		if ( null === $words ) {
			$raw   = (string) get_option( self::OPT_BANNED_WORDS, '' );
			$words = '' === trim( $raw )
				? array()
				: array_values(
					array_filter(
						array_map(
							'trim',
							explode( ',', strtolower( $raw ) )
						)
					)
				);
			$this->cache->set( self::CACHE_KEY_BANNED_WORDS, $words, 60 );
		}

		if ( empty( $words ) ) {
			return true;
		}

		$lower = strtolower( $content );

		foreach ( $words as $word ) {
			if ( '' === $word ) {
				continue;
			}
			if ( preg_match( '/\b' . preg_quote( $word, '/' ) . '\b/u', $lower ) ) {
				return new WP_Error(
					'banned_word',
					__( 'Your post contains a prohibited word.', 'buddynext' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Check whether the user has exceeded their per-hour post rate limit.
	 *
	 * The counter is stored in the object cache under key bn_rate_{user_id}.
	 * Incrementing is deliberately separated into increment_rate_limit() so
	 * that the check-phase does not count posts that later fail validation.
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	public function check_rate_limit( int $user_id ): true|WP_Error {
		$max = (int) get_option( self::OPT_RATE_LIMIT, 10 );
		if ( $max <= 0 ) {
			return true;
		}

		$key     = "bn_rate_{$user_id}";
		$current = $this->cache->get( $key );
		$count   = ( null === $current ) ? 0 : (int) $current;

		if ( $count >= $max ) {
			return new WP_Error(
				'rate_limited',
				__( 'You are posting too quickly. Please wait a moment before posting again.', 'buddynext' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Check whether the post contains more URLs than the configured maximum.
	 *
	 * Counts http:// and https:// URLs using a regex match. The limit can be
	 * set to 0 to disable the check entirely.
	 *
	 * @param string $content Post content to inspect.
	 * @return true|WP_Error
	 */
	public function check_link_throttle( string $content ): true|WP_Error {
		$max = (int) get_option( self::OPT_MAX_LINKS, 2 );
		if ( $max <= 0 ) {
			return true;
		}

		$count = preg_match_all( '~https?://[^\s<>"]+~i', $content, $matches );

		if ( $count > $max ) {
			return new WP_Error(
				'too_many_links',
				__( 'Your post contains too many links.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Check whether the user's account is new enough to be blocked by the gate.
	 *
	 * When the gate is configured and the user registered within the threshold
	 * window, posting is blocked with a 403 error. Set the option to 0 to
	 * disable the gate entirely (default behaviour).
	 *
	 * @param int $user_id Author user ID.
	 * @return true|WP_Error
	 */
	public function check_new_member_gate( int $user_id ): true|WP_Error {
		$days = (int) get_option( self::OPT_NEW_MEMBER_DAYS, 0 );

		if ( $days <= 0 ) {
			return true;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return true;
		}

		$registered_ts = strtotime( $user->user_registered );
		$age_seconds   = time() - $registered_ts;

		if ( $age_seconds < ( $days * DAY_IN_SECONDS ) ) {
			return new WP_Error(
				'new_member_gate',
				__( 'New members must wait before posting.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
