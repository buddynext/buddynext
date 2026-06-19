<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Canonical keyset-pagination cursor codec.
 *
 * Every cursor-paginated feed (activity feed, hashtag feed, …) uses the same
 * opaque cursor format so encoding/decoding lives in exactly one place:
 *
 *   cursor = base64( "{created_at}|{id}" )
 *
 * Consolidated from the previously-duplicated FeedService::decode_cursor and
 * HashtagService::decode_feed_cursor implementations.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless encode/decode for keyset-pagination cursors.
 */
final class CursorCodec {

	/**
	 * Encode a keyset cursor.
	 *
	 * @param string $created_at Pivot row timestamp.
	 * @param int    $id         Pivot row id.
	 * @return string Opaque cursor.
	 */
	public static function encode( string $created_at, int $id ): string {
		return base64_encode( $created_at . '|' . $id ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a keyset cursor into its component parts.
	 *
	 * @param string $cursor Opaque cursor produced by encode().
	 * @return array{created_at: string, id: int}|null Null when the cursor is malformed.
	 */
	public static function decode( string $cursor ): ?array {
		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}

		return array(
			'created_at' => $parts[0],
			'id'         => (int) $parts[1],
		);
	}
}
