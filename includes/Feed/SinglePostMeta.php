<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Single-post permalink head meta emitter.
 *
 * Emits Open Graph, Twitter Card, and canonical link tags on the /p/{id}/
 * single-post page so the URL deep-links cleanly into chat clients
 * (Slack, Discord, iMessage), social networks (Facebook, LinkedIn,
 * Twitter / X, Mastodon), and search engines.
 *
 * Hooked once per request from the single-post template via
 * {@see self::emit_for_post()}. Private / followers-only posts are tagged
 * noindex so they never leak into search-engine indices.
 *
 * @package BuddyNext\Feed
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use BuddyNext\Core\PageRouter;

/**
 * Builds and emits head-meta tags for the single-post permalink page.
 */
class SinglePostMeta {

	/**
	 * Maximum characters in the meta description / OG description.
	 */
	private const DESCRIPTION_MAX = 160;

	/**
	 * Maximum characters in the document <title> excerpt portion.
	 */
	private const TITLE_EXCERPT_MAX = 60;

	/**
	 * Emit head meta tags for a hydrated post record.
	 *
	 * Registers two callbacks:
	 *   - wp_head priority 1: prints the OG / Twitter / canonical tags.
	 *   - document_title_parts: replaces the page title with a richer excerpt.
	 *
	 * @param array<string,mixed> $post Hydrated post record (from PostService::get()).
	 * @return void
	 */
	public static function emit_for_post( array $post ): void {
		add_action(
			'wp_head',
			static function () use ( $post ): void {
				self::print_meta_tags( $post );
			},
			1
		);

		add_filter(
			'document_title_parts',
			static function ( array $parts ) use ( $post ): array {
				$parts['title'] = self::build_document_title( $post );
				return $parts;
			}
		);
	}

	/**
	 * Print OG, Twitter, canonical, and robots tags for the given post.
	 *
	 * @param array<string,mixed> $post Hydrated post record.
	 * @return void
	 */
	private static function print_meta_tags( array $post ): void {
		$post_id     = (int) ( $post['id'] ?? 0 );
		$author_id   = (int) ( $post['user_id'] ?? 0 );
		$author      = $author_id > 0 ? get_userdata( $author_id ) : null;
		$author_name = $author ? $author->display_name : __( 'Community member', 'buddynext' );

		$excerpt    = self::build_description( $post );
		$title      = self::build_document_title( $post );
		$canonical  = PageRouter::post_url( $post_id );
		$image_url  = self::resolve_image_url( $post, $author_id );
		$is_private = self::is_search_excluded( $post );

		printf(
			"<link rel=\"canonical\" href=\"%s\" />\n",
			esc_url( $canonical )
		);

		if ( $is_private ) {
			echo "<meta name=\"robots\" content=\"noindex, nofollow\" />\n";
		}

		printf(
			"<meta name=\"description\" content=\"%s\" />\n",
			esc_attr( $excerpt )
		);

		// Open Graph.
		printf(
			"<meta property=\"og:type\" content=\"article\" />\n<meta property=\"og:url\" content=\"%s\" />\n",
			esc_url( $canonical )
		);
		printf(
			"<meta property=\"og:title\" content=\"%s\" />\n",
			esc_attr( $title )
		);
		printf(
			"<meta property=\"og:description\" content=\"%s\" />\n",
			esc_attr( $excerpt )
		);
		printf(
			"<meta property=\"og:site_name\" content=\"%s\" />\n",
			esc_attr( get_bloginfo( 'name' ) )
		);
		if ( '' !== $image_url ) {
			printf(
				"<meta property=\"og:image\" content=\"%s\" />\n",
				esc_url( $image_url )
			);
		}
		printf(
			"<meta property=\"article:author\" content=\"%s\" />\n",
			esc_attr( $author_name )
		);
		if ( ! empty( $post['created_at'] ) ) {
			printf(
				"<meta property=\"article:published_time\" content=\"%s\" />\n",
				esc_attr( mysql2date( 'c', (string) $post['created_at'], false ) )
			);
		}
		if ( ! empty( $post['edited_at'] ) ) {
			printf(
				"<meta property=\"article:modified_time\" content=\"%s\" />\n",
				esc_attr( mysql2date( 'c', (string) $post['edited_at'], false ) )
			);
		}

		// Twitter / X card.
		$twitter_card = '' !== $image_url ? 'summary_large_image' : 'summary';
		printf(
			"<meta name=\"twitter:card\" content=\"%s\" />\n",
			esc_attr( $twitter_card )
		);
		printf(
			"<meta name=\"twitter:title\" content=\"%s\" />\n",
			esc_attr( $title )
		);
		printf(
			"<meta name=\"twitter:description\" content=\"%s\" />\n",
			esc_attr( $excerpt )
		);
		if ( '' !== $image_url ) {
			printf(
				"<meta name=\"twitter:image\" content=\"%s\" />\n",
				esc_url( $image_url )
			);
		}
	}

	/**
	 * Build the document <title> string for a post.
	 *
	 * Format: `{author display name}: "{excerpt up to 60 chars}…"`
	 *
	 * @param array<string,mixed> $post Hydrated post record.
	 * @return string Plain-text title (not HTML-escaped — caller escapes).
	 */
	public static function build_document_title( array $post ): string {
		$author_id   = (int) ( $post['user_id'] ?? 0 );
		$author      = $author_id > 0 ? get_userdata( $author_id ) : null;
		$author_name = $author ? $author->display_name : __( 'Community member', 'buddynext' );

		$excerpt = self::strip_to_plain( (string) ( $post['content'] ?? '' ) );
		if ( '' === $excerpt ) {
			/* translators: %s: post author display name */
			return sprintf( __( 'Post by %s', 'buddynext' ), $author_name );
		}

		$short = self::truncate( $excerpt, self::TITLE_EXCERPT_MAX );
		/* translators: 1: author display name, 2: post excerpt */
		return sprintf( __( '%1$s: "%2$s"', 'buddynext' ), $author_name, $short );
	}

	/**
	 * Build the meta-description / OG-description string for a post.
	 *
	 * @param array<string,mixed> $post Hydrated post record.
	 * @return string Plain-text description, truncated to 160 chars.
	 */
	public static function build_description( array $post ): string {
		$excerpt = self::strip_to_plain( (string) ( $post['content'] ?? '' ) );
		if ( '' === $excerpt ) {
			return (string) get_bloginfo( 'description' );
		}
		return self::truncate( $excerpt, self::DESCRIPTION_MAX );
	}

	/**
	 * Resolve the OG image URL for a post.
	 *
	 * Priority:
	 *   1. First attachment in media_ids (if it resolves to a real image).
	 *   2. link_meta.thumbnail (when the post is a shared link with OG image).
	 *   3. Author avatar (96px).
	 *   4. Site icon, then empty string.
	 *
	 * @param array<string,mixed> $post      Hydrated post record.
	 * @param int                 $author_id Post author ID (0 when unknown).
	 * @return string URL or empty string.
	 */
	private static function resolve_image_url( array $post, int $author_id ): string {
		$media_ids = $post['media_ids'] ?? null;
		if ( is_array( $media_ids ) && ! empty( $media_ids ) ) {
			$first = (int) $media_ids[0];
			if ( $first > 0 ) {
				// Engine-resolved signed URL — media lives in mvs_media_index,
				// never as a WP attachment. Full image for photos, poster
				// thumbnail for video; skip audio (no meaningful OG image).
				$desc = \BuddyNext\Media\MediaUrlResolver::descriptor( $first );
				if ( $desc ) {
					if ( 'image' === $desc['type'] && '' !== $desc['url'] ) {
						return (string) $desc['url'];
					}
					if ( '' !== $desc['thumb'] ) {
						return (string) $desc['thumb'];
					}
				}
			}
		}

		$link_meta = $post['link_meta'] ?? null;
		if ( is_array( $link_meta ) && ! empty( $link_meta['thumbnail'] ) ) {
			return (string) $link_meta['thumbnail'];
		}

		if ( $author_id > 0 ) {
			$avatar = get_avatar_url( $author_id, array( 'size' => 256 ) );
			if ( false !== $avatar && '' !== (string) $avatar ) {
				return (string) $avatar;
			}
		}

		$site_icon = get_site_icon_url( 512 );
		if ( '' !== (string) $site_icon ) {
			return (string) $site_icon;
		}

		return '';
	}

	/**
	 * Return true when the post should be tagged noindex (private or restricted).
	 *
	 * @param array<string,mixed> $post Hydrated post record.
	 * @return bool
	 */
	private static function is_search_excluded( array $post ): bool {
		$privacy = (string) ( $post['privacy'] ?? 'public' );
		if ( in_array( $privacy, array( 'private', 'followers', 'connections', 'space_members' ), true ) ) {
			return true;
		}

		$space_id = (int) ( $post['space_id'] ?? 0 );
		if ( $space_id > 0 ) {
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$type = (string) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT type FROM {$wpdb->prefix}bn_spaces WHERE id = %d LIMIT 1",
					$space_id
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( in_array( $type, array( 'secret', 'private' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip HTML, collapse whitespace, and decode entities for meta-text use.
	 *
	 * @param string $raw Raw post content (may contain HTML, mentions, hashtags).
	 * @return string Plain text safe to embed in a meta-tag content attribute.
	 */
	private static function strip_to_plain( string $raw ): string {
		$plain = wp_strip_all_tags( $raw, true );
		$plain = html_entity_decode( $plain, ENT_QUOTES, 'UTF-8' );
		$plain = (string) preg_replace( '/\s+/u', ' ', $plain );
		return trim( $plain );
	}

	/**
	 * Truncate a string to a max character length on a word boundary.
	 *
	 * Appends a U+2026 horizontal ellipsis when truncation occurs.
	 *
	 * @param string $text Source text.
	 * @param int    $max  Maximum characters (excluding ellipsis).
	 * @return string
	 */
	private static function truncate( string $text, int $max ): string {
		if ( '' === $text || mb_strlen( $text ) <= $max ) {
			return $text;
		}

		$cut = mb_substr( $text, 0, $max );
		// Snap to last space when one exists in the second half of the cut.
		$last_space = mb_strrpos( $cut, ' ' );
		if ( false !== $last_space && $last_space > (int) ( $max * 0.5 ) ) {
			$cut = mb_substr( $cut, 0, (int) $last_space );
		}
		return $cut . '…';
	}
}
