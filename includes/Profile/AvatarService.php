<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext avatar system.
 *
 * Hooks `pre_get_avatar_data` so every WordPress surface that calls
 * get_avatar() or get_avatar_url() — including the WP admin users list,
 * comments, themes, and plugins — automatically gets a BuddyNext avatar:
 *
 *   1. If the user has a locally uploaded avatar (buddynext_avatar_url usermeta),
 *      that URL is used directly.
 *   2. Otherwise a coloured SVG initials circle is generated as a data URI.
 *      No Gravatar network request, works fully offline, looks consistent.
 *
 * Site owners can set a custom avatar URL per user via the
 * `buddynext_avatar_url` usermeta key, or hook `buddynext_avatar_url` to
 * return a custom URL from any source (local upload plugins, BuddyPress, etc.).
 *
 * @package BuddyNext\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

use WP_User;

/**
 * Provides BuddyNext avatar integration for the WordPress avatar system.
 */
class AvatarService {

	/**
	 * Colour palette — cycles deterministically by user ID.
	 *
	 * @var string[]
	 */
	// Brand-safe initials-avatar tones — the same blue → green → warm sweep the
	// space covers use, with a neutral slate. Purple/violet/pink/rose are
	// deliberately excluded (BN reads those as the synthetic "AI" palette).
	// White initials read cleanly on each.
	private const COLOURS = array(
		'#1c7ed6',
		'#0c8599',
		'#099268',
		'#2f9e44',
		'#66a80f',
		'#f08c00',
		'#e8590c',
		'#495057',
	);

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
		add_filter( 'kses_allowed_protocols', array( $this, 'allow_data_protocol' ) );
	}

	/**
	 * Allow data: URIs to pass through esc_url() so SVG initials avatars render.
	 *
	 * WordPress's esc_url() strips any URL whose scheme is not in the allowed
	 * protocols list. Since our fallback avatar is a data:image/svg+xml;base64
	 * data URI, we add 'data' here so it survives the img src attribute escaping
	 * inside get_avatar().
	 *
	 * @param string[] $protocols Allowed URL protocols.
	 * @return string[]
	 */
	public function allow_data_protocol( array $protocols ): array {
		$protocols[] = 'data';
		return $protocols;
	}

	// ── Filter ────────────────────────────────────────────────────────────────

	/**
	 * Intercept avatar data before WordPress performs a Gravatar lookup.
	 *
	 * Priority:
	 *   1. User's own uploaded avatar — always wins.
	 *   2. Site avatar style setting:
	 *      - 'gravatar'       → return args unchanged (WordPress/Gravatar handles it).
	 *      - 'default_image'  → return site-wide default image URL.
	 *      - 'initials'       → return SVG initials data URI (default).
	 *
	 * @param array<string, mixed>                  $args        Avatar args array passed to get_avatar_data().
	 * @param int|string|WP_User|\WP_Post|\stdClass $id_or_email User ID, email, WP_User, WP_Post, or comment object.
	 * @return array<string, mixed>
	 */
	public function filter_avatar_data( array $args, $id_or_email ): array {
		$user = $this->resolve_user( $id_or_email );
		if ( ! $user ) {
			return $args;
		}

		// ── 1. User's own custom upload always takes precedence ────────────────
		$custom = (string) apply_filters( 'buddynext_avatar_url', '', $user->ID );
		if ( '' === $custom ) {
			$custom = (string) get_user_meta( $user->ID, 'buddynext_avatar_url', true );
		}
		if ( '' !== $custom ) {
			$args['url']          = $custom;
			$args['found_avatar'] = true;
			return $args;
		}

		// ── 2. Site-wide fallback style ────────────────────────────────────────
		$style = (string) get_option( 'bn_avatar_style', 'initials' );

		if ( 'gravatar' === $style ) {
			// Let WordPress and Gravatar handle it — return args unchanged.
			return $args;
		}

		if ( 'default_image' === $style ) {
			$default_url = (string) get_option( 'bn_default_avatar_url', '' );
			if ( '' !== $default_url ) {
				$args['url']          = $default_url;
				$args['found_avatar'] = true;
				return $args;
			}
			// No image configured — fall through to initials.
		}

		// ── 3. Initials SVG fallback ───────────────────────────────────────────
		$args['url']          = $this->build_svg_url( $user );
		$args['found_avatar'] = true;

		return $args;
	}

	/**
	 * The deterministic palette tone (hex) for a user — the same colour their
	 * initials avatar uses. Reusable for cohesive accents (e.g. a member-card
	 * cover fallback tinted to match the avatar).
	 *
	 * @param int $user_id User ID.
	 * @return string Hex colour.
	 */
	public static function tone_for( int $user_id ): string {
		return self::COLOURS[ $user_id % count( self::COLOURS ) ];
	}

	// ── Public helpers ────────────────────────────────────────────────────────

	/**
	 * Return the avatar URL for a user.
	 *
	 * Priority:
	 *   1. `buddynext_avatar_url` filter — lets any plugin or theme override.
	 *   2. `buddynext_avatar_url` usermeta — set when user uploads a photo.
	 *   3. SVG initials data URI — generated deterministically, no network request.
	 *
	 * @param WP_User $user WordPress user.
	 * @return string Absolute URL or data URI.
	 */
	public function get_avatar_url( WP_User $user ): string {
		// Allow external code to provide a URL (e.g. a local avatar plugin).
		$custom = (string) apply_filters( 'buddynext_avatar_url', '', $user->ID );
		if ( '' !== $custom ) {
			return $custom;
		}

		// Locally uploaded avatar stored as usermeta (canonical key: bn_avatar).
		$meta = (string) get_user_meta( $user->ID, 'bn_avatar', true );
		if ( '' !== $meta ) {
			return $meta;
		}

		// Fallback: inline SVG initials circle.
		return $this->build_svg_url( $user );
	}

	/**
	 * Save a custom avatar URL for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     Absolute URL to the avatar image.
	 * @return void
	 */
	public function save_avatar_url( int $user_id, string $url ): void {
		update_user_meta( $user_id, 'bn_avatar', $url );
	}

	/**
	 * Delete the custom avatar for a user, reverting to the initials SVG.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function delete_avatar( int $user_id ): void {
		delete_user_meta( $user_id, 'bn_avatar' );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Build a data-URI SVG for a user's initials avatar.
	 *
	 * The SVG is a coloured circle with two uppercase initials centred inside.
	 * Colour is chosen deterministically from COLOURS by user ID mod palette size.
	 *
	 * @param WP_User $user WordPress user.
	 * @return string data:image/svg+xml;base64,... URI.
	 */
	private function build_svg_url( WP_User $user ): string {
		$initials = $this->get_initials( $user->display_name );
		$colour   = self::COLOURS[ $user->ID % count( self::COLOURS ) ];

		$svg = sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 40 40">'
			. '<rect width="40" height="40" rx="20" fill="%s"/>'
			. '<text x="20" y="20" text-anchor="middle" dominant-baseline="central" '
			. 'font-family="Inter,-apple-system,BlinkMacSystemFont,sans-serif" '
			. 'font-size="16" font-weight="700" fill="#ffffff">%s</text>'
			. '</svg>',
			esc_attr( $colour ),
			esc_html( $initials )
		);

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Derive up to two uppercase initials from a display name.
	 *
	 * @param string $name Display name.
	 * @return string One or two uppercase characters.
	 */
	private function get_initials( string $name ): string {
		$parts = array_values( array_filter( explode( ' ', trim( $name ) ) ) );

		if ( count( $parts ) >= 2 ) {
			return mb_strtoupper(
				mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 )
			);
		}

		return '' !== $name ? mb_strtoupper( mb_substr( trim( $name ), 0, 2 ) ) : '?';
	}

	/**
	 * Resolve any WordPress avatar identifier to a WP_User object.
	 *
	 * WordPress passes get_avatar() a mix of: int user ID, email string,
	 * WP_User object, WP_Post object (author), or comment object (user_id).
	 *
	 * @param int|string|WP_User|\WP_Post|\stdClass $id_or_email Identifier.
	 * @return WP_User|null Null when the identifier cannot be resolved.
	 */
	private function resolve_user( $id_or_email ): ?WP_User {
		if ( $id_or_email instanceof WP_User ) {
			return $id_or_email;
		}

		if ( is_int( $id_or_email ) || ( is_string( $id_or_email ) && ctype_digit( $id_or_email ) ) ) {
			$user = get_userdata( (int) $id_or_email );
			return $user instanceof WP_User ? $user : null;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user instanceof WP_User ? $user : null;
		}

		// Comment objects and WP_Post objects carry a user_id property.
		if ( is_object( $id_or_email ) && isset( $id_or_email->user_id ) && (int) $id_or_email->user_id > 0 ) {
			$user = get_userdata( (int) $id_or_email->user_id );
			return $user instanceof WP_User ? $user : null;
		}

		return null;
	}
}
