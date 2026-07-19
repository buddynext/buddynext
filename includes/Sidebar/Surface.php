<?php
/**
 * Request-scoped "current surface" for the sidebar registry. Each surface
 * template declares its fine-grained slug (bn_hub is too coarse) before the
 * shell renders the right column; the registry reads it here.
 *
 * @package BuddyNext\Sidebar
 */

declare( strict_types=1 );
namespace BuddyNext\Sidebar;

/**
 * Request-scoped surface holder for fine-grained sidebar context.
 */
final class Surface {
	/**
	 * Current surface slug.
	 *
	 * @var string
	 */
	private static string $current = '';

	/**
	 * Set the current surface slug.
	 *
	 * @param string $slug Surface identifier (sanitized).
	 * @return void
	 */
	public static function set( string $slug ): void {
		self::$current = sanitize_key( $slug );
	}

	/**
	 * Get the current surface, or the hub fallback if not set.
	 *
	 * @param string $hub_fallback Fallback surface slug if current is unset (default: '').
	 * @return string The current surface or fallback (sanitized).
	 */
	public static function current( string $hub_fallback = '' ): string {
		return '' !== self::$current ? self::$current : sanitize_key( $hub_fallback );
	}

	/**
	 * Reset the current surface to unset.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$current = '';
	}
}
