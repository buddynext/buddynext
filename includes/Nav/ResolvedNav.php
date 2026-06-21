<?php
/**
 * BuddyNext — Resolved nav.
 *
 * The output of NavRegistry::resolve() for one context: items grouped by layer,
 * gated, ordered, deduped, and (for primary) nested with their sub-nav children.
 * Renderers consume this; they never re-sort or re-gate.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Immutable resolved-nav result.
 */
final class ResolvedNav {

	/**
	 * Wrap the resolved, layer-keyed item map.
	 *
	 * @param array<string,NavItem[]> $by_layer Items keyed by layer
	 *                                          (primary | metric | rail | context).
	 *                                          Primary holds top-level items only;
	 *                                          sub-nav is on each item's ->children.
	 */
	public function __construct( private array $by_layer ) {}

	/**
	 * Items for a layer (empty array when none).
	 *
	 * @param string $layer primary | metric | rail | context.
	 * @return NavItem[]
	 */
	public function layer( string $layer ): array {
		return $this->by_layer[ $layer ] ?? array();
	}

	/**
	 * Whether a layer has any items.
	 *
	 * @param string $layer Layer name.
	 */
	public function has( string $layer ): bool {
		return ! empty( $this->by_layer[ $layer ] );
	}

	/**
	 * All layers, keyed by layer name.
	 *
	 * @return array<string,NavItem[]>
	 */
	public function all(): array {
		return $this->by_layer;
	}
}
