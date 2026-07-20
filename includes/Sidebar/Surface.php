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
	 * Context payload for the current surface (e.g. space_id/viewer_id/active_tab).
	 *
	 * @var array<string,mixed>
	 */
	private static array $context = array();

	/**
	 * Set the current surface slug, with an optional context payload.
	 *
	 * @param string              $slug    Surface identifier (sanitized).
	 * @param array<string,mixed> $context Optional context data a provider reads via context().
	 * @return void
	 */
	public static function set( string $slug, array $context = array() ): void {
		self::$current = sanitize_key( $slug );
		self::$context = $context;
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
	 * Get the context payload set alongside the current surface.
	 *
	 * @return array<string,mixed> Empty array when no context was set.
	 */
	public static function context(): array {
		return self::$context;
	}

	/**
	 * Reset the current surface and its context to unset.
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$current = '';
		self::$context = array();
	}
}
