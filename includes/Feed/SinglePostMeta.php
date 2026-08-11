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

use BuddyNext\Core\HeadMeta;
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
	 * Describe a single post and hand it to the shared head emitter.
	 *
	 * This class used to print its own canonical/OG/Twitter block, which meant
	 * BuddyNext had TWO head emitters with two sets of rules — they drifted
	 * (different tag order, no empty-value guards, its own Twitter-card logic)
	 * and a post could ship empty og: tags. It now only DESCRIBES the post;
	 * {@see \BuddyNext\Core\HeadMeta} owns every rule, including the image
	 * contract and the honest-card decision.
	 *
	 * @param array<string,mixed> $post Hydrated post record (from PostService::get()).
	 * @return void
	 */
	public static function emit_for_post( array $post ): void {
		$post_id   = (int) ( $post['id'] ?? 0 );
		$author_id = (int) ( $post['user_id'] ?? 0 );
		$author    = $author_id > 0 ? get_userdata( $author_id ) : null;

		$extra = array(
			'article:author' => $author ? $author->display_name : __( 'Community member', 'buddynext' ),
		);
		if ( ! empty( $post['created_at'] ) ) {
			$extra['article:published_time'] = mysql2date( 'c', (string) $post['created_at'], false );
		}
		if ( ! empty( $post['edited_at'] ) ) {
			$extra['article:modified_time'] = mysql2date( 'c', (string) $post['edited_at'], false );
		}

		HeadMeta::emit(
			array(
				'url'         => PageRouter::post_url( $post_id ),
				'title'       => self::build_document_title( $post ),
				'description' => self::build_description( $post ),
				// CONTENT imagery only — attached media, then a link thumbnail,
				// then the author's real (uploaded) avatar. No site fallback rung
				// here: HeadMeta adds that AFTER deciding whether this post has
				// content imagery, which is what keeps twitter:card honest.
				'image'       => self::resolve_image_url( $post, $author_id ),
				'type'        => 'article',
				'noindex'     => self::is_search_excluded( $post ),
				'extra'       => $extra,
			)
		);
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
		/*
		 * One ordered ladder, every rung filtered by HeadMeta's image contract:
		 * og:image must be an absolute http(s) URL a scraper can fetch
		 * anonymously.
		 *
		 * The bug this closes lived at the avatar rung. BuddyNext's generated
		 * letter-avatars are `data:image/svg+xml;base64,…`, which no platform
		 * accepts — and returning one still counted as "has an image", so a
		 * text-only post shipped an unloadable og:image AND was upgraded to a
		 * summary_large_image card that rendered blank rather than degrading to
		 * summary. sanitize_image() drops the data URI, so an UPLOADED avatar is
		 * still used and a generated one falls through to the site image
		 * (Basecamp 10181599620).
		 *
		 * Attached media stays the top rung and is unaffected: it resolves to a
		 * render-stable public MediaVerse URL that a scraper fetches fine
		 * (verified 200 image/jpeg anonymously).
		 */
		$candidates = array();

		$media_ids = $post['media_ids'] ?? null;
		if ( is_array( $media_ids ) && ! empty( $media_ids ) ) {
			$first = (int) $media_ids[0];
			if ( $first > 0 ) {
				// Engine-resolved signed URL — media lives in mvs_media_index,
				// never as a WP attachment. Full image for photos, poster
				// thumbnail for video; skip audio (no meaningful OG image).
				$desc = \BuddyNext\Media\MediaUrlResolver::descriptor( $first );
				if ( $desc ) {
					if ( 'image' === $desc['type'] ) {
						$candidates[] = (string) $desc['url'];
					}
					$candidates[] = (string) $desc['thumb'];
				}
			}
		}

		$link_meta = $post['link_meta'] ?? null;
		if ( is_array( $link_meta ) && ! empty( $link_meta['thumbnail'] ) ) {
			$candidates[] = (string) $link_meta['thumbnail'];
		}

		if ( $author_id > 0 ) {
			$candidates[] = (string) get_user_meta( $author_id, 'bn_avatar', true );
			$avatar       = get_avatar_url( $author_id, array( 'size' => 512 ) );
			$candidates[] = false !== $avatar ? (string) $avatar : '';
		}

		// NO site-image rung here. Returning '' when a post has no imagery of its
		// own is the signal HeadMeta needs to emit an honest `summary` card; it
		// applies the site fallback itself, after that decision.
		return HeadMeta::first_usable_image( $candidates );
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
