<?php
/**
 * Monogram helper — deterministic initials + tone for non-user entities.
 *
 * BuddyNext already renders initials avatars for users (AvatarService,
 * MemberDisplay). This is the generic counterpart for content rows (jobs,
 * listings, courses) that have no image: derive a short monogram and a stable
 * colour tone from a string, so each row reads with its own identity instead of
 * a repeated category icon.
 *
 * @package BuddyNext\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Support;

/**
 * Initials + tone derivation for content monograms.
 */
final class Monogram {

	/**
	 * Number of tones in the palette (mirrors the .bn-dm-tone-* set).
	 */
	public const TONES = 8;

	/**
	 * Up to two uppercase initials for a label (first word + last word).
	 *
	 * Matches MemberDisplay::get_initials() semantics, mb-safe. Falls back to a
	 * neutral bullet when there is nothing letter-like to show.
	 *
	 * @param string $text Source label (e.g. a company or item title).
	 * @return string One or two characters, or '•'.
	 */
	public static function initials( string $text ): string {
		$parts = array_values( array_filter( preg_split( '/\s+/', trim( $text ) ) ?: array() ) );
		if ( empty( $parts ) ) {
			return '•';
		}
		$first    = mb_substr( (string) reset( $parts ), 0, 1 );
		$last     = count( $parts ) > 1 ? mb_substr( (string) end( $parts ), 0, 1 ) : '';
		$initials = mb_strtoupper( $first . $last );

		return '' !== trim( $initials ) ? $initials : '•';
	}

	/**
	 * A stable tone number (1..TONES) for a seed string.
	 *
	 * Deterministic via crc32 so the same company/title always gets the same
	 * colour across requests and members.
	 *
	 * @param string $seed Seed string (e.g. company name).
	 * @return int 1..TONES.
	 */
	public static function tone( string $seed ): int {
		$seed = trim( $seed );
		if ( '' === $seed ) {
			return 1;
		}
		return ( (int) ( crc32( $seed ) % self::TONES ) ) + 1;
	}
}
