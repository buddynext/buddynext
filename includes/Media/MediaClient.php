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
		if ( ! self::available() ) {
			return null;
		}
		try {
			$container = \WPMediaVerse\Core\Plugin::container();
			return $container ? $container->get( $key ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
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
}
