<?php
/**
 * Multibyte-safe text helpers that never need mbstring or ctype.
 *
 * WordPress polyfills only mb_strlen(), mb_substr() and mb_str_split(). A host
 * without mbstring (or ctype) used to fatal on every profile page because an
 * initials avatar called mb_strtoupper(). These use the extension when it is
 * there and a byte-safe fallback when it is not, so BuddyNext degrades (ASCII
 * case rules) instead of failing. file_mime() does the same for fileinfo.
 *
 * @package BuddyNext\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Support;

/**
 * Text helpers with extension fallbacks.
 */
final class Text {

	/**
	 * Upper-case a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function upper( string $text ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text ) : strtoupper( $text );
	}

	/**
	 * Lower-case a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}

	/**
	 * Upper-cased first character (an initial for a letter avatar).
	 *
	 * @param string $text   Text.
	 * @param int    $length Characters to take. Default 1.
	 * @return string
	 */
	public static function initial( string $text, int $length = 1 ): string {
		return self::upper( mb_substr( $text, 0, $length ) );
	}

	/**
	 * Case-insensitive position of a needle, in characters.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @return int|false
	 */
	public static function ipos( string $haystack, string $needle ): int|false {
		if ( function_exists( 'mb_stripos' ) ) {
			return mb_stripos( $haystack, $needle );
		}
		$pos = stripos( $haystack, $needle );
		return false === $pos ? false : mb_strlen( substr( $haystack, 0, $pos ) );
	}

	/**
	 * Position of the last occurrence of a needle, in characters.
	 *
	 * @param string $haystack Text to search.
	 * @param string $needle   Text to find.
	 * @return int|false
	 */
	public static function last_pos( string $haystack, string $needle ): int|false {
		if ( function_exists( 'mb_strrpos' ) ) {
			return mb_strrpos( $haystack, $needle );
		}
		$pos = strrpos( $haystack, $needle );
		return false === $pos ? false : mb_strlen( substr( $haystack, 0, $pos ) );
	}

	/**
	 * Trim to a width in characters, appending a marker when cut.
	 *
	 * @param string $text   Text.
	 * @param int    $width  Maximum characters including the marker.
	 * @param string $marker Appended when the text was cut.
	 * @return string
	 */
	public static function trim_width( string $text, int $width, string $marker = '…' ): string {
		if ( function_exists( 'mb_strimwidth' ) ) {
			return (string) mb_strimwidth( $text, 0, $width, $marker );
		}
		if ( mb_strlen( $text ) <= $width ) {
			return $text;
		}
		return mb_substr( $text, 0, max( 0, $width - mb_strlen( $marker ) ) ) . $marker;
	}

	/**
	 * Whether a string is only ASCII digits (ctype_digit() without ctype).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_digits( string $text ): bool {
		return 1 === preg_match( '/^[0-9]+$/D', $text );
	}

	/**
	 * PHP extensions BuddyNext needs for every feature but runs without.
	 *
	 * @return string[] Names of the missing extensions.
	 */
	public static function missing_extensions(): array {
		return array_values( array_filter( array( 'mbstring', 'ctype', 'dom', 'fileinfo' ), static fn( string $ext ): bool => ! extension_loaded( $ext ) ) );
	}

	/**
	 * MIME type of a file read from its own bytes, or null when the server cannot tell.
	 *
	 * Null means fileinfo is missing (or disabled): callers refuse the upload with a
	 * clear message instead of trusting the browser-sent type. '' means fileinfo ran
	 * but could not classify the file.
	 *
	 * @param string $path File path.
	 * @return string|null
	 */
	public static function file_mime( string $path ): ?string {
		if ( ! function_exists( 'finfo_open' ) || ! function_exists( 'finfo_file' ) ) {
			return null;
		}
		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		// No finfo_close(): the resource became an object in PHP 8.1 and is freed by refcount (deprecated no-op on 8.5).
		return $finfo ? (string) finfo_file( $finfo, $path ) : null;
	}
}
