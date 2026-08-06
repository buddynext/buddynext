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
	 * A feed whose ORDER BY leads with a ranking tier (the for-you affinity
	 * CASE) must carry that tier in the cursor: paginating a tiered order with
	 * a purely chronological key re-emits every tier-floated row on later pages
	 * (the duplicate-post bug). Chronological feeds omit the tier.
	 *
	 * @param string   $created_at Pivot row timestamp.
	 * @param int      $id         Pivot row id.
	 * @param int|null $tier       Pivot row's ORDER BY tier, when the feed is tiered.
	 * @return string Opaque cursor.
	 */
	public static function encode( string $created_at, int $id, ?int $tier = null ): string {
		$raw = $created_at . '|' . $id . ( null !== $tier ? '|' . $tier : '' );
		return base64_encode( $raw ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a keyset cursor into its component parts.
	 *
	 * @param string $cursor Opaque cursor produced by encode().
	 * @return array{created_at: string, id: int, tier: int|null}|null Null when the cursor is malformed.
	 */
	public static function decode( string $cursor ): ?array {
		$raw = base64_decode( $cursor, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}

		$parts = explode( '|', $raw, 3 );
		if ( count( $parts ) < 2 ) {
			return null;
		}

		return array(
			'created_at' => $parts[0],
			'id'         => (int) $parts[1],
			// Absent on cursors from chronological feeds (and on any cursor
			// minted before tiers were encoded — those degrade gracefully to
			// the chronological WHERE).
			'tier'       => isset( $parts[2] ) && is_numeric( $parts[2] ) ? (int) $parts[2] : null,
		);
	}
}
