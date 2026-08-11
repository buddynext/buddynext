<?php
/**
 * The single owner of BuddyNext's social + canonical head.
 *
 * Every shareable BuddyNext surface — a single post, a space, a profile, a
 * directory — describes itself as a small descriptor array and hands it here.
 * This class is the only place that renders og:*, twitter:*, canonical and
 * robots tags, so the contract (especially the image contract below) is
 * enforced once instead of being re-implemented per surface.
 *
 * Why this exists: until 1.1.3 the single-post permalink was the ONLY surface
 * in the plugin that emitted anything. A member sharing a space or a profile —
 * the links they actually share — got a bare, imageless card on every platform
 * (Basecamp 10181599620).
 *
 * THE IMAGE CONTRACT. `og:image` must be an absolute http(s) URL a third-party
 * scraper can fetch anonymously. Facebook, LinkedIn, X, Slack, Discord and
 * WhatsApp all reject anything else. BuddyNext's generated letter-avatars are
 * `data:image/svg+xml;base64,…`, so the previous fallback chain produced an
 * image no platform could ever load — and, because the string was non-empty,
 * `twitter:card` was upgraded to `summary_large_image`, promising a large image
 * and rendering blank instead of degrading honestly to `summary`. Every image
 * therefore passes {@see self::sanitize_image()}, and the card type is derived
 * from what SURVIVES that check, never from what was proposed.
 *
 * @package BuddyNext\Core
 * @since   1.1.3
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the social/canonical head for a described surface.
 */
final class HeadMeta {

	/**
	 * Maximum characters in a description before truncation.
	 */
	private const DESCRIPTION_MAX = 160;

	/**
	 * Whether a descriptor has already been emitted this request.
	 *
	 * Two surfaces must never both describe one response — a single post inside
	 * a space is still one page with one canonical. First writer wins, which
	 * matches dispatch order (the most specific surface registers first).
	 *
	 * @var bool
	 */
	private static bool $emitted = false;

	/**
	 * Has a surface already described this response?
	 *
	 * The seam any OTHER head emitter must consult before printing, so two
	 * emitters can never both claim one response. PageRouter's community
	 * meta-description fallback checks this: without it both ran at wp_head
	 * priority 1 and every BuddyNext URL shipped TWO <meta name="description">
	 * tags making two different claims.
	 *
	 * @return bool
	 */
	public static function has_emitted(): bool {
		return self::$emitted;
	}

	/**
	 * Register a surface descriptor for rendering into <head>.
	 *
	 * Accepted keys — all optional except `url`:
	 *   title       string  The BARE name of this thing ("Members", "Alice
	 *                       Chen"). Never append the community name: og:site_name
	 *                       already carries it, and WordPress appends it to the
	 *                       document title itself.
	 *   description string  Meta description + og:description (truncated).
	 *   image       string  CONTENT image only — a post's media, a space cover, a
	 *                       member's avatar. Leave empty when the surface has no
	 *                       imagery of its own; the site fallback is applied here,
	 *                       after the content test, so the Twitter card can tell
	 *                       real content from a logo.
	 *   url         string  Canonical URL for this surface. REQUIRED.
	 *   type        string  og:type. Default 'website'.
	 *   noindex     bool    Emit robots noindex,nofollow.
	 *   extra       array<string,string>  Additional og:* properties (article:*).
	 *
	 * @param array<string,mixed> $descriptor Surface descriptor.
	 * @return void
	 */
	public static function emit( array $descriptor ): void {
		if ( self::$emitted ) {
			return;
		}

		$url = trim( (string) ( $descriptor['url'] ?? '' ) );
		if ( '' === $url ) {
			return;
		}

		/**
		 * Filter a surface descriptor before BuddyNext renders its head meta.
		 *
		 * Return an empty array to suppress BuddyNext's head entirely for this
		 * request — the escape hatch for a site that wants its SEO plugin to own
		 * every tag.
		 *
		 * @since 1.1.3
		 *
		 * @param array<string,mixed> $descriptor The surface descriptor.
		 */
		$descriptor = (array) apply_filters( 'buddynext_head_meta', $descriptor );
		if ( empty( $descriptor ) ) {
			return;
		}

		self::$emitted = true;

		add_action(
			'wp_head',
			static function () use ( $descriptor ): void {
				self::print_tags( $descriptor );
			},
			1
		);

		/*
		 * Same rule as PageRouter's hub title: only claim the document title
		 * when no SEO plugin owns the head. This emitter was added by the
		 * social-card work and repeated the unconditional override, which made
		 * the reported inconsistency wider rather than narrower — two filters
		 * discarding the owner's configured title instead of one (Basecamp
		 * 10173643793). og:title is unaffected: that is BuddyNext describing
		 * its own surface, not overriding a setting the owner typed.
		 */
		$title = trim( (string) ( $descriptor['title'] ?? '' ) );
		$title = (string) apply_filters( 'buddynext_document_title', $title, 'head-meta' );
		if ( '' !== $title && ! PageRouter::seo_plugin_active() ) {
			add_filter(
				'document_title_parts',
				static function ( array $parts ) use ( $title ): array {
					$parts['title'] = $title;
					return $parts;
				}
			);
		}
	}

	/**
	 * Reduce a candidate image to one a social scraper can actually fetch.
	 *
	 * Rejects `data:` URIs (BuddyNext's generated letter-avatars), protocol-
	 * relative and relative URLs, and anything that is not http(s). Returns ''
	 * when nothing usable remains, which is the signal for the caller to fall
	 * further down its ladder — and for the Twitter card to stay `summary`.
	 *
	 * @param string $url Candidate image URL.
	 * @return string An absolute http(s) URL, or ''.
	 */
	public static function sanitize_image( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( '' === (string) wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}

		/*
		 * SVG is a valid image everywhere except the place this URL is going.
		 * Facebook, LinkedIn and X all reject image/svg+xml for og:image and
		 * render the card with no image at all — so an SVG site logo fetches
		 * 200 and still produces exactly the blank card this class exists to
		 * prevent. Reject it here so the ladder keeps walking to a raster.
		 */
		$path = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( '.svg' === substr( $path, -4 ) || '.svgz' === substr( $path, -5 ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Resolve the first usable image from a candidate ladder.
	 *
	 * @param array<int,string> $candidates Ordered candidate URLs.
	 * @return string First candidate that survives sanitisation, or ''.
	 */
	public static function first_usable_image( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			$usable = self::sanitize_image( (string) $candidate );
			if ( '' !== $usable ) {
				return $usable;
			}
		}

		return '';
	}

	/**
	 * The site-wide fallback image.
	 *
	 * Walks what a WordPress site is known to have before giving up: the site
	 * icon, then the custom logo. A blank card is the one outcome worth
	 * avoiding — the whole point of this class is that a shared BuddyNext link
	 * never renders as a bare URL — so the ladder uses everything available
	 * rather than assuming the owner set a site icon (many have not).
	 *
	 * @return string Absolute URL or ''.
	 */
	public static function site_image(): string {
		$candidates = array( (string) get_site_icon_url( 512 ) );

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$logo = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $logo ) ) {
				$candidates[] = $logo;
			}
		}

		// Last resort, and the reason no BuddyNext card is ever imageless: the
		// plugin's own 512px PWA icon. Always present, always raster, so a site
		// with no site icon and an SVG logo (a common pairing) still shares with
		// an image instead of a bare link.
		$candidates[] = BUDDYNEXT_URL . 'assets/images/pwa/icon-512.png';

		/**
		 * Filter BuddyNext's fallback social image.
		 *
		 * Used when a surface has no image of its own. Supply a branded default
		 * here so shared links never fall back to no image at all.
		 *
		 * @since 1.1.3
		 *
		 * @param string $url Fallback image URL (site icon, then custom logo).
		 */
		return self::sanitize_image( (string) apply_filters( 'buddynext_head_meta_image', self::first_usable_image( $candidates ) ) );
	}

	/**
	 * The community's display name — the last-resort title for any surface.
	 *
	 * Delegates to buddynext_site_name(), the single source of truth for the
	 * community name (owner-set Community Name, falling back to the site title),
	 * so the social-card title cannot drift from the name shown everywhere else.
	 *
	 * @return string
	 */
	public static function community_name(): string {
		return buddynext_site_name();
	}

	/**
	 * Truncate a description to the social-card budget.
	 *
	 * @param string $text Raw text (may contain HTML).
	 * @return string Plain, collapsed, truncated text.
	 */
	public static function prepare_description( string $text ): string {
		$text = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $text ) ) );
		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::DESCRIPTION_MAX ) {
			return rtrim( mb_substr( $text, 0, self::DESCRIPTION_MAX - 1 ) ) . '…';
		}

		if ( strlen( $text ) > self::DESCRIPTION_MAX ) {
			return rtrim( substr( $text, 0, self::DESCRIPTION_MAX - 1 ) ) . '…';
		}

		return $text;
	}

	/**
	 * Print the head tags for a descriptor.
	 *
	 * @param array<string,mixed> $d Surface descriptor.
	 * @return void
	 */
	private static function print_tags( array $d ): void {
		$url     = (string) $d['url'];
		$type    = (string) ( $d['type'] ?? 'website' );
		$noindex = ! empty( $d['noindex'] );

		/*
		 * Nothing ships blank. Every element below has a known site-level
		 * source, so a surface that fails to describe one still produces a
		 * complete card rather than a bare URL: the community name always
		 * exists, and the tagline is WordPress's own one-line description of
		 * the site. A half-filled card is the failure this class exists to
		 * prevent.
		 */
		$title = trim( (string) ( $d['title'] ?? '' ) );
		if ( '' === $title ) {
			$title = self::community_name();
		}

		$description = self::prepare_description( (string) ( $d['description'] ?? '' ) );
		if ( '' === $description ) {
			// The community description is the owner's public "about this
			// community" copy — the right thing to show even on a private
			// community, where it is exactly what a login-gated card should say.
			$fallback    = (string) get_option( 'buddynext_description', '' );
			$description = self::prepare_description( '' !== trim( $fallback ) ? $fallback : (string) get_bloginfo( 'description' ) );
		}

		/*
		 * The image survives only if a scraper could fetch it — see the class
		 * docblock. Everything below keys off $image, never off the raw input.
		 *
		 * A surface that proposed nothing, or proposed something unusable, falls
		 * back to the site ladder so no card ships imageless. That fallback is a
		 * logo or icon, not content, so it does NOT earn the wide card: a square
		 * logo stretched into summary_large_image looks broken, while `summary`
		 * renders it as the small thumbnail it is.
		 */
		$image      = self::sanitize_image( (string) ( $d['image'] ?? '' ) );
		$is_content = '' !== $image;
		if ( ! $is_content ) {
			$image = self::site_image();
		}

		printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $url ) );

		if ( $noindex ) {
			echo "<meta name=\"robots\" content=\"noindex, nofollow\" />\n";
		}

		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
		}

		printf( "<meta property=\"og:type\" content=\"%s\" />\n", esc_attr( $type ) );
		printf( "<meta property=\"og:url\" content=\"%s\" />\n", esc_url( $url ) );
		printf( "<meta property=\"og:site_name\" content=\"%s\" />\n", esc_attr( get_bloginfo( 'name' ) ) );

		if ( '' !== $title ) {
			printf( "<meta property=\"og:title\" content=\"%s\" />\n", esc_attr( $title ) );
		}
		if ( '' !== $description ) {
			printf( "<meta property=\"og:description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		if ( '' !== $image ) {
			printf( "<meta property=\"og:image\" content=\"%s\" />\n", esc_url( $image ) );
		}

		foreach ( (array) ( $d['extra'] ?? array() ) as $property => $content ) {
			if ( '' === (string) $content ) {
				continue;
			}
			printf(
				"<meta property=\"%s\" content=\"%s\" />\n",
				esc_attr( (string) $property ),
				esc_attr( (string) $content )
			);
		}

		// Card type follows the image that SURVIVED and where it came from, so we
		// never promise a large image we cannot deliver — nor stretch a logo.
		printf(
			"<meta name=\"twitter:card\" content=\"%s\" />\n",
			esc_attr( $is_content && '' !== $image ? 'summary_large_image' : 'summary' )
		);
		if ( '' !== $title ) {
			printf( "<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr( $title ) );
		}
		if ( '' !== $description ) {
			printf( "<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr( $description ) );
		}
		if ( '' !== $image ) {
			printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_url( $image ) );
		}
	}

	/**
	 * Reset the emitted guard. Test seam only.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$emitted = false;
	}
}
