<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Shared resolver for optional backdated timestamps.
 *
 * Importers and seeders write through the service APIs (never raw bn_* SQL),
 * so every content service needs one uniform way to accept a historical
 * created_at. PostService grew this logic first (see PostService::create());
 * this helper is that exact contract extracted so the comment / space /
 * connection / follow / reaction seams cannot drift from it:
 *
 *   - input is a UTC "Y-m-d H:i:s" string (BuddyPress-style date_recorded
 *     values are already UTC);
 *   - a future value is clamped to now — these seams BACKDATE only,
 *     scheduling has its own mechanisms (e.g. bn_posts.scheduled_at);
 *   - null / empty / unparseable input falls back to now-UTC, i.e. exactly
 *     what the services stamped before the seam existed.
 *
 * @package BuddyNext\Core
 * @since 1.1.0
 */

declare(strict_types=1);

namespace BuddyNext\Core;

/**
 * Resolves an optional caller-supplied timestamp to a safe UTC value.
 */
final class Backdate {

	/**
	 * Resolve an optional backdated timestamp to a UTC "Y-m-d H:i:s" string.
	 *
	 * @param string|null $value Caller-supplied UTC datetime, or null.
	 * @return string UTC mysql datetime: the supplied value when valid and not
	 *                in the future, otherwise now.
	 */
	public static function resolve( ?string $value ): string {
		if ( null === $value || '' === $value ) {
			return current_time( 'mysql', true );
		}

		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp || $timestamp > time() ) {
			return current_time( 'mysql', true );
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
