<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shareable space invite links.
 *
 * One active invite link per space, stored in bn_space_meta (no new table):
 *   - meta key `invite_link`      : the link record (token, expiry, max uses, author).
 *   - meta key `invite_link_uses` : a plain integer use counter, incremented
 *                                   atomically so two concurrent joins cannot
 *                                   exceed the cap.
 *   - meta key `joined_via_link_{user_id}` : timestamp marker set when a member
 *                                   joins through the link (owner/mod visible).
 *
 * A valid link lets anyone who opens it join the space directly, skipping the
 * space's normal approval/invite-only routing — but never bypassing bans, the
 * paid-space gate (buddynext_can_join_space), the invite-only-SITE registration
 * gate, or onboarding.
 *
 * @package BuddyNext\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Spaces;

use BuddyNext\Core\PageRouter;
use WP_Error;

/**
 * Create, validate and consume per-space shareable invite links.
 */
class SpaceInviteLinkService {

	/**
	 * Meta key holding the active link record.
	 */
	private const META_LINK = 'invite_link';

	/**
	 * Meta key holding the atomic use counter.
	 */
	private const META_USES = 'invite_link_uses';

	/**
	 * Prefix for the per-member "joined via link" marker key.
	 */
	private const JOINED_PREFIX = 'joined_via_link_';

	/**
	 * WordPress metadata object-cache group for meta_type 'bn_space'.
	 */
	private const META_CACHE_GROUP = 'bn_space_meta';

	/**
	 * Token length. 32 URL-safe characters from wp_generate_password().
	 */
	private const TOKEN_LENGTH = 32;

	/**
	 * Allowed expiry presets mapped to a day count (0 = never expires).
	 *
	 * @var array<string, int>
	 */
	private const EXPIRY_DAYS = array(
		'1d'    => 1,
		'7d'    => 7,
		'30d'   => 30,
		'never' => 0,
	);

	/**
	 * Allowed max-use presets (0 = unlimited).
	 *
	 * @var int[]
	 */
	private const MAX_USES = array( 0, 1, 10, 100 );

	/**
	 * Inspect the current front-end request for a valid ?invite= token.
	 *
	 * Hooked early on template_redirect (before the onboarding gate and the space
	 * visibility gate). When a valid token is present for the space being viewed
	 * it unlocks that space's home page for the request, and — for a signed-in
	 * member who still owes onboarding — remembers the invite so completing the
	 * wizard returns them to the space (the wizard redirect otherwise drops the
	 * URL). An invalid/expired/used-up token is ignored here; the visibility gate
	 * then shows the "no longer valid" page (200, never 404) for hidden spaces.
	 *
	 * @return void
	 */
	public static function prime_from_request(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() ) {
			return;
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) ) {
			return;
		}

		// A shareable invite link is public GET navigation, not a state change (the
		// join itself is a nonced REST POST), so there is no nonce to verify here.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = isset( $_GET['invite'] ) ? sanitize_text_field( wp_unslash( $_GET['invite'] ) ) : '';
		if ( '' === $token ) {
			return;
		}

		$slug = (string) get_query_var( 'bn_space_slug', '' );
		if ( '' === $slug ) {
			return;
		}

		$space = ( new SpaceService() )->get_by_slug( $slug );
		if ( null === $space || empty( $space['id'] ) ) {
			return;
		}
		$space_id = (int) $space['id'];

		$service = new self();
		if ( is_wp_error( $service->validate( $space_id, $token ) ) ) {
			return;
		}

		SpaceVisibility::unlock_via_invite( $space_id );

		$user_id = get_current_user_id();
		if ( $user_id > 0
			&& function_exists( 'buddynext_service' )
			&& buddynext_service( 'onboarding' )->is_required_for( $user_id )
		) {
			update_user_meta(
				$user_id,
				'bn_pending_space_invite',
				array(
					'space_id' => $space_id,
					'token'    => $token,
				)
			);
		}
	}

	/**
	 * Create (or reset) the space's single invite link with a fresh token.
	 *
	 * Any existing link is replaced immediately — a reset is just another
	 * create — and the use counter is zeroed. The new token is 32 random
	 * URL-safe characters.
	 *
	 * @param int    $space_id   Space to create the link for.
	 * @param int    $actor_id   User creating the link (recorded as author).
	 * @param string $expires    One of the EXPIRY_DAYS keys (1d|7d|30d|never).
	 * @param int    $max_uses   One of the MAX_USES presets (0 = unlimited).
	 * @return array The public link representation (see to_public()).
	 */
	public function create( int $space_id, int $actor_id, string $expires, int $max_uses ): array {
		$expires  = isset( self::EXPIRY_DAYS[ $expires ] ) ? $expires : '7d';
		$max_uses = in_array( $max_uses, self::MAX_USES, true ) ? $max_uses : 0;

		$days       = self::EXPIRY_DAYS[ $expires ];
		$expires_at = 0 === $days ? null : gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) );

		$record = array(
			'token'      => wp_generate_password( self::TOKEN_LENGTH, false ),
			'expires'    => $expires, // The preset key, so a reset can reuse the same setting.
			'expires_at' => $expires_at,
			'max_uses'   => $max_uses,
			'created_by' => $actor_id,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		// update_metadata replaces the row atomically and busts the meta cache.
		update_space_meta( $space_id, self::META_LINK, $record );
		update_space_meta( $space_id, self::META_USES, 0 );

		return $this->to_public( $space_id, $record, 0 );
	}

	/**
	 * Get the space's current invite link, or null when none exists.
	 *
	 * @param int $space_id Space to read.
	 * @return array|null Public link representation, or null.
	 */
	public function get( int $space_id ): ?array {
		$record = get_space_meta( $space_id, self::META_LINK, true );
		if ( ! is_array( $record ) || empty( $record['token'] ) ) {
			return null;
		}

		return $this->to_public( $space_id, $record, $this->uses( $space_id ) );
	}

	/**
	 * Validate a presented token against the space's active link.
	 *
	 * Checks the token matches (constant-time), the link has not expired, and
	 * the use limit has not been reached. Does NOT consume a use.
	 *
	 * @param int    $space_id Space the link belongs to.
	 * @param string $token    Token from the request.
	 * @return true|WP_Error True when valid, WP_Error('invite_link_invalid', 403) otherwise.
	 */
	public function validate( int $space_id, string $token ): bool|WP_Error {
		$record = get_space_meta( $space_id, self::META_LINK, true );

		$invalid = new WP_Error(
			'invite_link_invalid',
			__( 'This invite link is no longer valid. Ask the space for a new one.', 'buddynext' ),
			array( 'status' => 403 )
		);

		if ( ! is_array( $record ) || empty( $record['token'] ) || '' === $token ) {
			return $invalid;
		}

		if ( ! hash_equals( (string) $record['token'], $token ) ) {
			return $invalid;
		}

		if ( ! empty( $record['expires_at'] ) && strtotime( (string) $record['expires_at'] . ' UTC' ) < time() ) {
			return $invalid;
		}

		$max = (int) ( $record['max_uses'] ?? 0 );
		if ( $max > 0 && $this->uses( $space_id ) >= $max ) {
			return $invalid;
		}

		return true;
	}

	/**
	 * Atomically reserve one use of the link, enforcing the cap.
	 *
	 * The capped UPDATE is the real concurrency gate: two people opening the
	 * same last slot cannot both succeed, because only one row update matches
	 * `meta_value < max`. Call validate() first for a friendly error; call this
	 * immediately before the join to reserve the slot, and refund() if the join
	 * itself is then refused.
	 *
	 * @param int $space_id Space the link belongs to.
	 * @return true|WP_Error True when a use was reserved, WP_Error when the cap is reached.
	 */
	public function consume( int $space_id ): bool|WP_Error {
		$record = get_space_meta( $space_id, self::META_LINK, true );
		$max    = is_array( $record ) ? (int) ( $record['max_uses'] ?? 0 ) : 0;

		global $wpdb;

		if ( $max > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_space_meta SET meta_value = meta_value + 1
					 WHERE bn_space_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
					$space_id,
					self::META_USES,
					$max
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}bn_space_meta SET meta_value = meta_value + 1
					 WHERE bn_space_id = %d AND meta_key = %s",
					$space_id,
					self::META_USES
				)
			);
		}

		wp_cache_delete( $space_id, self::META_CACHE_GROUP );

		if ( ! $affected ) {
			return new WP_Error(
				'invite_link_invalid',
				__( 'This invite link is no longer valid. Ask the space for a new one.', 'buddynext' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Release a previously reserved use (floored at zero).
	 *
	 * Used when a use was consumed but the join was then refused (e.g. the
	 * paid-space gate denied it), so the slot is not wasted.
	 *
	 * @param int $space_id Space the link belongs to.
	 * @return void
	 */
	public function refund( int $space_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_space_meta SET meta_value = meta_value - 1
				 WHERE bn_space_id = %d AND meta_key = %s AND CAST(meta_value AS UNSIGNED) > 0",
				$space_id,
				self::META_USES
			)
		);

		wp_cache_delete( $space_id, self::META_CACHE_GROUP );
	}

	/**
	 * Record that a member joined through the link and fire the join-via-link hook.
	 *
	 * The normal buddynext_space_member_joined action is fired by
	 * SpaceMemberService::join(); this adds the link-specific marker + signal.
	 *
	 * @param int $space_id Space joined.
	 * @param int $user_id  Member who joined via the link.
	 * @return void
	 */
	public function mark_joined( int $space_id, int $user_id ): void {
		update_space_meta( $space_id, self::JOINED_PREFIX . $user_id, time() );

		/**
		 * Fires after a member joins a space through its shareable invite link.
		 *
		 * @param int $space_id Space joined.
		 * @param int $user_id  Member who joined via the link.
		 */
		do_action( 'buddynext_space_joined_via_link', $space_id, $user_id );
	}

	/**
	 * Map of members who joined a space via link → join timestamp.
	 *
	 * One batched query for the whole roster (no per-row meta lookups), for the
	 * member list's owner/moderator-only "Joined via invite link" marker.
	 *
	 * @param int $space_id Space to read.
	 * @return array<int, int> user_id => unix timestamp.
	 */
	public function joined_via_link_map( int $space_id ): array {
		global $wpdb;
		$like = $wpdb->esc_like( self::JOINED_PREFIX ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}bn_space_meta WHERE bn_space_id = %d AND meta_key LIKE %s",
				$space_id,
				$like
			),
			ARRAY_A
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$user_id = (int) substr( (string) $row['meta_key'], strlen( self::JOINED_PREFIX ) );
			if ( $user_id > 0 ) {
				$map[ $user_id ] = (int) $row['meta_value'];
			}
		}

		return $map;
	}

	/**
	 * Delete a member's join-via-link marker for one space (on leave).
	 *
	 * @param int $space_id Space left.
	 * @param int $user_id  Member who left.
	 * @return void
	 */
	public function forget_member( int $space_id, int $user_id ): void {
		delete_space_meta( $space_id, self::JOINED_PREFIX . $user_id );
	}

	/**
	 * Delete a member's join-via-link markers across all spaces (on purge).
	 *
	 * A single indexed delete by the exact per-user meta key — cheap even at
	 * scale, and reached by the buddynext_purge_user_data signal which carries
	 * the user id but not the member's space list.
	 *
	 * @param int $user_id Member being purged.
	 * @return void
	 */
	public function forget_member_everywhere( int $user_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_space_meta',
			array( 'meta_key' => self::JOINED_PREFIX . $user_id ),
			array( '%s' )
		);
	}

	/**
	 * Current use count for a space's link.
	 *
	 * @param int $space_id Space to read.
	 * @return int
	 */
	private function uses( int $space_id ): int {
		return (int) get_space_meta( $space_id, self::META_USES, true );
	}

	/**
	 * Build the public representation of a link record.
	 *
	 * @param int   $space_id Space the link belongs to.
	 * @param array $record   Stored link record.
	 * @param int   $uses     Current use count.
	 * @return array{url:string, token:string, expires_at:?string, max_uses:int, uses:int, status:string, created_at:?string}
	 */
	private function to_public( int $space_id, array $record, int $uses ): array {
		$token   = (string) ( $record['token'] ?? '' );
		$max     = (int) ( $record['max_uses'] ?? 0 );
		$expires = isset( $record['expires_at'] ) ? ( null === $record['expires_at'] ? null : (string) $record['expires_at'] ) : null;

		$status = 'active';
		if ( null !== $expires && strtotime( $expires . ' UTC' ) < time() ) {
			$status = 'expired';
		} elseif ( $max > 0 && $uses >= $max ) {
			$status = 'limit_reached';
		}

		return array(
			'url'        => add_query_arg( 'invite', rawurlencode( $token ), PageRouter::space_url( $space_id ) ),
			'token'      => $token,
			'expires'    => isset( $record['expires'] ) ? (string) $record['expires'] : ( null === $expires ? 'never' : '7d' ),
			'expires_at' => $expires,
			'max_uses'   => $max,
			'uses'       => $uses,
			'status'     => $status,
			'created_at' => isset( $record['created_at'] ) ? (string) $record['created_at'] : null,
		);
	}
}
