<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Canonical keyset-pagination cursor codec.
 *
 * Every cursor-paginated feed (activity feed, hashtag feed, …) uses the same
 * opaque cursor format so encoding/decoding lives in exactly one place:
 *
 *   cursor = base64url( "{created_at}|{id}" )   (no +/= — URL-safe)
 *
 * The encoding is URL-safe base64 (+/ -> -_, padding stripped) because cursors
 * ride in URL query args on the server-rendered prev/next surfaces, and
 * add_query_arg() silently corrupts standard-base64 '=' padding — a dropped '='
 * decoded to the wrong pivot and the page fell back to page 1 (card 10284805802).
 * decode() still accepts a standard-base64 cursor, so any cursor minted before
 * this change keeps working.
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
		// URL-safe base64: +/ -> -_ and strip '=' padding so add_query_arg() cannot
		// corrupt it (a lost '=' broke keyset pagination — card 10284805802).
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decode a keyset cursor into its component parts.
	 *
	 * @param string $cursor Opaque cursor produced by encode().
	 * @return array{created_at: string, id: int, tier: int|null}|null Null when the cursor is malformed.
	 */
	public static function decode( string $cursor ): ?array {
		// Accept URL-safe (-_ , unpadded) AND legacy standard base64: normalise the
		// alphabet, then restore '=' padding to a multiple of 4 for strict decode.
		$normalized = strtr( $cursor, '-_', '+/' );
		$remainder  = strlen( $normalized ) % 4;
		if ( 0 !== $remainder ) {
			$normalized .= str_repeat( '=', 4 - $remainder );
		}
		$raw = base64_decode( $normalized, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
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
