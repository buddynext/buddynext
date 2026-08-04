<?php
/**
 * The single point of contact with the WPMediaVerse engine.
 *
 * BuddyNext consumes WPMediaVerse at the API level ONLY — never loading its JS
 * or CSS. Every engine call funnels through this client so the coupling lives in
 * one place; if a file outside includes/Media/ references the engine directly,
 * that is a leak to fix.
 *
 * All accessors degrade gracefully (return null/empty) when the engine is
 * absent or not yet booted, so BuddyNext never fatals without WPMediaVerse.
 *
 * @package BuddyNext\Media
 */

declare( strict_types=1 );

namespace BuddyNext\Media;

/**
 * Resolves WPMediaVerse container services for BuddyNext.
 */
class MediaClient {

	/**
	 * Whether the WPMediaVerse engine + its DI container are available.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return class_exists( '\WPMediaVerse\Core\Plugin' )
			&& method_exists( '\WPMediaVerse\Core\Plugin', 'container' );
	}

	/**
	 * Resolve a container service by key, guarded.
	 *
	 * @param string $key Container key.
	 * @return object|null
	 */
	private static function service( string $key ) {
		$resolved = null;

		if ( self::available() ) {
			try {
				$container = \WPMediaVerse\Core\Plugin::container();
				$resolved  = $container ? $container->get( $key ) : null;
			} catch ( \Throwable $e ) {
				$resolved = null;
			}
		}

		/**
		 * Filter a resolved WPMediaVerse container service.
		 *
		 * The single seam into the media boundary. Returning an object here
		 * substitutes an engine service, which is how the test suite covers media
		 * authorisation without booting WPMediaVerse, and how an alternative media
		 * engine can supply a compatible implementation.
		 *
		 * @since 1.1.1
		 *
		 * @param object|null $resolved Service resolved from the engine container, or null when absent.
		 * @param string      $key      Container key ('media_repository', 'upload', 'object_media', …).
		 */
		$filtered = apply_filters( 'buddynext_media_service', $resolved, $key );

		return is_object( $filtered ) ? $filtered : null;
	}

	/**
	 * The media repository (read API: signed URLs, media meta).
	 *
	 * @return object|null
	 */
	public static function repo() {
		return self::service( 'media_repository' );
	}

	/**
	 * The upload service (write API: ingest a file → media_id). This is the same
	 * engine path the media REST endpoint uses, so server-side callers (e.g. the
	 * demo seeder) attach media exactly like a real upload. Null when absent.
	 *
	 * @return object|null
	 */
	public static function upload() {
		return self::service( 'upload' );
	}

	/**
	 * The provider-neutral object↔media linkage service (engine 1.6.0).
	 *
	 * @return object|null
	 */
	public static function object_link() {
		return self::service( 'object_media' );
	}

	/**
	 * The messaging engine (conversations/messages).
	 *
	 * @return object|null
	 */
	public static function messaging() {
		return self::service( 'messaging' );
	}

	/**
	 * The album service (create/list/items/cover for mvs_album collections).
	 *
	 * @return object|null
	 */
	public static function albums() {
		return self::service( 'albums' );
	}

	/**
	 * The privacy service (per-viewer can_view checks on media/albums).
	 *
	 * @return object|null
	 */
	public static function privacy() {
		return self::service( 'privacy' );
	}

	/**
	 * The engine's bundled default video poster URL.
	 *
	 * Used as a fallback when a video has no server-generated poster frame so a
	 * BuddyNext media tile shows the same placeholder the engine's own grids use
	 * (Explore / My Media), instead of a black poster-less tile. Degrades to an
	 * empty string when the engine (or the helper) is absent, like every other
	 * accessor here.
	 *
	 * @return string Default poster URL, or '' when unavailable.
	 */
	public static function default_video_poster(): string {
		if ( is_callable( array( '\WPMediaVerse\Core\TemplateHelpers', 'default_video_poster_url' ) ) ) {
			return (string) \WPMediaVerse\Core\TemplateHelpers::default_video_poster_url();
		}
		return '';
	}

	/**
	 * Apply a client-captured poster frame to a video that has no server poster.
	 *
	 * The engine extracts a poster from a video's EMBEDDED frame; a video without
	 * one (many screen recordings / re-encodes) otherwise falls back to the bundled
	 * default poster, so the feed shows a generic film strip while the composer
	 * already showed the real frame. The composer captures that frame client-side
	 * and posts it as a `thumbnail` file; this stages it through the engine's poster
	 * pipeline so the feed and Explore use the REAL frame — the exact mechanism
	 * WPMediaVerse's own upload endpoint uses (MediaController::stage_client_frame).
	 *
	 * A genuine engine-generated poster (a valid image thumb_large) always wins;
	 * the client frame is only staged when none exists. Best-effort and self-gated:
	 * returns false when the engine, the poster service, or the temp file is absent.
	 *
	 * @param int    $media_id Engine media id just created.
	 * @param string $tmp_path Uploaded thumbnail temp-file path ($_FILES tmp_name).
	 * @return bool True when the client frame was staged as the poster.
	 */
	public static function apply_client_poster( int $media_id, string $tmp_path ): bool {
		if ( $media_id <= 0 || '' === $tmp_path || ! is_file( $tmp_path ) ) {
			return false;
		}

		$poster = self::service( 'poster' );
		if ( ! $poster || ! is_callable( array( $poster, 'stage_client_frame' ) ) ) {
			return false;
		}

		// A real engine poster (a valid image URL in thumb_large) wins — only stage
		// the client frame when the video has no server-generated poster yet.
		$repo = self::repo();
		if ( $repo && is_callable( array( $repo, 'get_raw' ) ) ) {
			$existing = (string) $repo->get_raw( $media_id, 'thumb_large' );
			if ( '' !== $existing && preg_match( '#\.(jpe?g|png|gif|webp|avif)(?:[?\#].*)?$#i', $existing ) ) {
				return false;
			}
		}

		$staged_path = $poster->stage_client_frame( $media_id, $tmp_path );
		if ( ! $staged_path ) {
			return false;
		}

		$upload = self::upload();
		if ( $upload && is_callable( array( $upload, 'generate_thumbnails' ) ) ) {
			$upload->generate_thumbnails( $media_id, $staged_path, 'image/jpeg' );
		}
		return true;
	}
}
