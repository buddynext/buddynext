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
	 * Exact prefix of the initials-avatar data URI this service generates.
	 */
	private const AVATAR_DATA_PREFIX = 'data:image/svg+xml;base64,';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
		// The generated initials run LAST, separately, so a real avatar from any
		// other plugin beats a placeholder from this one. See filter_avatar_fallback().
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_fallback' ), 99, 2 );
		add_filter( 'kses_allowed_protocols', array( $this, 'allow_data_protocol' ) );
		add_filter( 'clean_url', array( $this, 'restrict_data_urls' ), 10, 2 );
	}

	/**
	 * Allow the data: scheme through esc_url()'s protocol check.
	 *
	 * Required so the SVG initials avatar (a data:image/svg+xml;base64 URI) is not
	 * stripped to '' by core get_avatar() or the many templates that esc_url() the
	 * avatar URL. esc_url() aborts to '' BEFORE the clean_url filter when a scheme
	 * is disallowed, so the scheme must be permitted here for restrict_data_urls()
	 * to then narrow it back down — without that ordering a data: URL never reaches
	 * clean_url at all.
	 *
	 * @param string[] $protocols Allowed URL protocols.
	 * @return string[]
	 */
	public function allow_data_protocol( array $protocols ): array {
		$protocols[] = 'data';
		return $protocols;
	}

	/**
	 * Permit ONLY our initials-avatar data URI; strip every other data: URL.
	 *
	 * The allow_data_protocol() filter opens the data: scheme globally, which alone would
	 * also let data:text/html (and any other data: URL) pass esc_url() site-wide —
	 * the reported issue. This runs in clean_url (which, unlike the protocol list,
	 * receives the URL) and returns '' for any data: URL that is not our exact
	 * base64 SVG-image avatar, restoring WordPress's default-deny for everything
	 * else. The avatar payload is base64 ([A-Za-z0-9+/=]) after a fixed prefix, so
	 * it cannot break out of an href/src attribute.
	 *
	 * @param string $good_url     esc_url()'s cleaned URL.
	 * @param string $original_url The URL passed into esc_url() before cleaning.
	 * @return string
	 */
	public function restrict_data_urls( string $good_url, string $original_url ): string {
		if ( 0 !== stripos( $original_url, 'data:' ) ) {
			return $good_url; // Not a data: URL — leave every other URL untouched.
		}
		if ( 0 === strpos( $original_url, self::AVATAR_DATA_PREFIX ) ) {
			$payload = substr( $original_url, strlen( self::AVATAR_DATA_PREFIX ) );
			if ( '' !== $payload && 1 === preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $payload ) ) {
				return $good_url; // Our initials avatar — allow it.
			}
		}
		return ''; // Any other data: URL (e.g. data:text/html) — block, as core does.
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
		// Canonical local-upload key is `bn_avatar` (written by ProfileService,
		// the member admin, and the demo seeder; read by get_avatar_url()).
		// Must match that key so uploaded avatars also surface through WordPress
		// core get_avatar() contexts (comments, admin user list, REST), not just
		// BuddyNext's own templates. The filter remains the external override seam.
		$custom = (string) apply_filters( 'buddynext_avatar_url', '', $user->ID );
		if ( '' === $custom ) {
			$custom = (string) get_user_meta( $user->ID, 'bn_avatar', true );
		}
		if ( '' !== $custom ) {
			$args['url']          = $this->pick_variation( $custom, $user->ID, $args );
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

		// ── 3. No real avatar from us ──────────────────────────────────────────
		// Deliberately return unchanged rather than generating initials here.
		// This filter runs at priority 10, and so do WPMediaVerse's and
		// Jetonomy's — three plugins on the same hook at the same priority, with
		// load order deciding. BuddyNext registers last and won, so a member who
		// had uploaded an avatar in WPMediaVerse got this plugin's generated
		// initials on every surface instead, and their real picture appeared
		// nowhere. A placeholder must never outrank someone's actual photograph.
		//
		// The initials are produced by filter_avatar_fallback() at priority 99,
		// after every other plugin has had its turn.
		return $args;
	}

	/**
	 * Last-resort initials avatar — runs at priority 99, after everyone else.
	 *
	 * Only fills in when no plugin, and not Gravatar, produced a URL. Generated
	 * initials are the answer to "nobody has a picture for this member", which is
	 * a question that can only be answered once every other participant on the
	 * hook has declined.
	 *
	 * The `bn_avatar_style` option still decides whether initials are wanted at
	 * all: 'gravatar' leaves the args alone so core resolves Gravatar normally,
	 * and 'default_image' is handled at priority 10 because a site-wide image the
	 * owner configured is a real choice, not a fallback.
	 *
	 * @param array $args        Avatar args.
	 * @param mixed $id_or_email User id, email, WP_User, WP_Post or WP_Comment.
	 * @return array
	 */
	public function filter_avatar_fallback( array $args, $id_or_email ): array {
		// Someone already answered — a real avatar from any source outranks ours.
		if ( ! empty( $args['url'] ) ) {
			return $args;
		}

		if ( 'initials' !== (string) get_option( 'bn_avatar_style', 'initials' )
			&& 'default_image' !== (string) get_option( 'bn_avatar_style', 'initials' ) ) {
			return $args;
		}

		$user = $this->resolve_user( $id_or_email );
		if ( ! $user ) {
			return $args;
		}

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

	/**
	 * Derive up to two uppercase initials from a display name. The shared,
	 * canonical implementation — supersedes the `bn_initials()` /
	 * `bn_connections_initials()` template copies and inline initials logic.
	 *
	 * @param string $name Display name.
	 * @return string One or two uppercase characters.
	 */
	public static function initials_for( string $name ): string {
		$parts = array_values( array_filter( explode( ' ', trim( $name ) ) ) );

		if ( count( $parts ) >= 2 ) {
			return mb_strtoupper(
				mb_substr( $parts[0], 0, 1 ) . mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 )
			);
		}

		return '' !== $name ? mb_strtoupper( mb_substr( trim( $name ), 0, 2 ) ) : '?';
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

	/**
	 * The user-meta key holding a member's cover image URL.
	 *
	 * Declared once, here, because it was previously spelled out by hand at
	 * nine call sites across admin, REST, the demo seeder and the storage
	 * service - and by the importer, which had to reach past us and write our
	 * meta directly because there was no setter to call. A storage detail
	 * copied into ten places is a storage detail that cannot be changed.
	 */
	private const COVER_META = 'buddynext_cover_url';

	/**
	 * Whether the member has a real avatar, as opposed to a generated one.
	 *
	 * "Has an avatar_url" is always true — AvatarService always answers with
	 * something, falling back to generated initials — so it cannot be used to ask
	 * whether the member has actually added a photo. This resolves the same two
	 * sources an uploaded avatar comes from, in the same order, and nothing else:
	 * the external override filter, then the stored upload.
	 *
	 * Site-wide fallbacks (Gravatar, a default image, initials) are deliberately
	 * NOT counted. They are the site's answer, not the member's, and a completion
	 * checklist that treats them as done can never ask for the one thing it most
	 * wants.
	 *
	 * @since 1.1.6
	 *
	 * @param int $user_id Member id.
	 * @return bool
	 */
	public function has_custom_avatar( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( '' !== (string) apply_filters( 'buddynext_avatar_url', '', $user_id ) ) {
			return true;
		}

		return '' !== (string) get_user_meta( $user_id, 'bn_avatar', true );
	}

	/**
	 * A member's cover image URL, or '' when they have none.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public function get_cover_url( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		return (string) get_user_meta( $user_id, self::COVER_META, true );
	}

	/**
	 * Save a member's cover image URL.
	 *
	 * Escaping lives here rather than at each caller: every existing call site
	 * applied esc_url_raw() itself, and one that forgot would have written an
	 * unescaped URL with nothing to catch it. An empty URL deletes, so callers
	 * do not need to branch between "set" and "clear".
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     Absolute URL to the cover image. '' clears it.
	 * @return void
	 */
	public function save_cover_url( int $user_id, string $url ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		$url = esc_url_raw( trim( $url ) );

		if ( '' === $url ) {
			$this->delete_cover( $user_id );
			return;
		}

		update_user_meta( $user_id, self::COVER_META, $url );
	}

	/**
	 * Remove a member's cover image URL.
	 *
	 * Only the pointer - the stored file is owned by ImageStorageService, and
	 * callers that need the bytes gone delete them there. Keeping those two
	 * separate is deliberate: dropping the meta while leaving the file is
	 * recoverable, and the REST delete path relies on that ordering.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public function delete_cover( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		delete_user_meta( $user_id, self::COVER_META );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Pick the right stored-image variation for the requested avatar size.
	 *
	 * The canonical upload URL points at the `full` variation. When a surface
	 * asks for a small avatar (≤128px — the rail, member cards, comment lists),
	 * serve the `thumb` variation instead so we don't ship a 512px file where a
	 * 128px one will do. Only applies to images in our managed per-owner storage
	 * (ImageStorageService); legacy, external, or plugin-supplied URLs return a
	 * '' from variation_url() and fall through unchanged.
	 *
	 * The thumb URL is stable (no cache-buster of its own), so we carry over the
	 * `v=` token from the canonical `full` URL — both variations are (re)written
	 * together on upload, so that token tracks the thumb too and a replacement
	 * never serves a stale cached thumbnail.
	 *
	 * @param string               $canonical Stored avatar URL (the `full` variation).
	 * @param int                  $user_id   Owner user ID.
	 * @param array<string, mixed> $args      Avatar args (carries the requested `size`).
	 * @return string
	 */
	private function pick_variation( string $canonical, int $user_id, array $args ): string {
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		if ( $size <= 0 || $size > 128 ) {
			return $canonical;
		}

		$thumb = ( new \BuddyNext\Media\ImageStorageService() )->variation_url( 'avatar', 'user', $user_id, 'thumb' );
		if ( '' === $thumb ) {
			return $canonical;
		}

		$query = (string) wp_parse_url( $canonical, PHP_URL_QUERY );
		parse_str( $query, $parsed );
		if ( ! empty( $parsed['v'] ) ) {
			$thumb = add_query_arg( 'v', $parsed['v'], $thumb );
		}

		return $thumb;
	}

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
		return self::initials_for( $name );
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
