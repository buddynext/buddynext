<?php
/**
 * BuddyNext — Nav registry.
 *
 * The single collection point for every navigation item across every surface.
 * Core providers + integrations register declaratively; the registry resolves a
 * context into gated, ordered, deduped, nested items for the renderer. This is
 * the one seam that makes inconsistent navigation structurally impossible.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Process-wide nav item registry.
 */
final class NavRegistry {

	/**
	 * Shared singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Raw registration arrays, in registration order.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $items = array();

	/**
	 * Monotonic registration sequence (stable order tiebreak).
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Whether the one-time `buddynext_register_nav` action has fired.
	 *
	 * @var bool
	 */
	private bool $providers_fired = false;

	/**
	 * Shared instance. The registry must accumulate registrations across the
	 * request, so it is a singleton rather than a per-resolve factory.
	 */
	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/**
	 * Register one nav item (validated later, at resolve time).
	 *
	 * @param array<string,mixed> $item Registration array (see NavItem contract).
	 */
	public function register( array $item ): void {
		$item['__seq'] = $this->seq++;
		$this->items[] = $item;
	}

	/**
	 * Resolve the navigation for a context.
	 *
	 * Pipeline: fire the registration action once → collect this surface's items →
	 * `buddynext_nav_items` filter (public mutation point) → validate → capability
	 * + condition gate → drop metric duplicates of a primary tab → resolve counts
	 * (+ hide_empty) → order per layer → nest primary sub-nav.
	 *
	 * @param NavContext $ctx Resolution context.
	 * @return ResolvedNav
	 */
	public function resolve( NavContext $ctx ): ResolvedNav {
		// Let providers/bridges register on first use, then resolve lazily so
		// count/condition callables see the live request.
		if ( ! $this->providers_fired ) {
			$this->providers_fired = true;
			do_action( 'buddynext_register_nav', $this );
		}

		// Only this surface's items.
		$raw = array_values(
			array_filter(
				$this->items,
				static fn( array $i ): bool => ( $i['surface'] ?? '' ) === $ctx->surface
			)
		);

		/**
		 * Public mutation point — add / reposition / edit / remove items.
		 *
		 * @param array<int,array<string,mixed>> $raw Registration arrays for this surface.
		 * @param NavContext                     $ctx Resolution context.
		 */
		$raw = (array) apply_filters( 'buddynext_nav_items', $raw, $ctx );

		// Build + validate.
		$items = array();
		foreach ( $raw as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$item = NavItem::from_array( $a, (int) ( $a['__seq'] ?? 0 ) );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		// Capability + condition gate.
		$items = array_values( array_filter( $items, static fn( NavItem $n ): bool => $n->passes( $ctx ) ) );

		// Dedupe: a metric that shares an id with a top-level primary tab is
		// dropped — the badge on the tab is the count's home (kills double-nav).
		$primary_ids = array();
		foreach ( $items as $n ) {
			if ( 'primary' === $n->layer && null === $n->parent ) {
				$primary_ids[ $n->id ] = true;
			}
		}
		$items = array_values(
			array_filter(
				$items,
				static fn( NavItem $n ): bool => ! ( 'metric' === $n->layer && isset( $primary_ids[ $n->id ] ) )
			)
		);

		// Resolve counts + hide_empty.
		$kept = array();
		foreach ( $items as $n ) {
			$n->count_value = $n->resolve_count( $ctx );
			if ( $n->hide_empty && ( null === $n->count_value || 0 === $n->count_value ) ) {
				continue;
			}
			$kept[] = $n;
		}

		// Group by layer.
		$by_layer = array(
			'primary' => array(),
			'metric'  => array(),
			'rail'    => array(),
			'context' => array(),
		);
		foreach ( $kept as $n ) {
			$by_layer[ $n->layer ][] = $n;
		}

		// Order every layer, then nest primary sub-nav.
		foreach ( $by_layer as $layer => $list ) {
			$by_layer[ $layer ] = $this->order( $list );
		}
		$by_layer['primary'] = $this->nest( $by_layer['primary'] );

		return new ResolvedNav( $by_layer );
	}

	/**
	 * Deterministic order: base sort by (priority, registration seq), then apply
	 * before/after anchors by reinsertion (handles chains over passes); an item
	 * whose anchor is absent falls back to its priority position.
	 *
	 * @param NavItem[] $items Items in one layer (or one parent's children).
	 * @return NavItem[]
	 */
	private function order( array $items ): array {
		usort(
			$items,
			static function ( NavItem $a, NavItem $b ): int {
				return ( $a->priority <=> $b->priority ) ?: ( $a->seq <=> $b->seq );
			}
		);

		$pending = array();
		$result  = array();
		foreach ( $items as $it ) {
			if ( null !== $it->before || null !== $it->after ) {
				$pending[] = $it;
			} else {
				$result[] = $it;
			}
		}
		if ( empty( $pending ) ) {
			return $result;
		}

		$max_pass = count( $pending ) + 1;
		while ( ! empty( $pending ) && $max_pass-- > 0 ) {
			$progress = false;
			foreach ( $pending as $k => $it ) {
				$anchor_id = $it->after ?? $it->before;
				$idx       = null;
				foreach ( $result as $ri => $r ) {
					if ( $r->id === $anchor_id ) {
						$idx = $ri;
						break;
					}
				}
				if ( null === $idx ) {
					continue; // anchor not placed yet — try a later pass.
				}
				$pos = ( null !== $it->after ) ? $idx + 1 : $idx;
				array_splice( $result, $pos, 0, array( $it ) );
				unset( $pending[ $k ] );
				$progress = true;
			}
			if ( ! $progress ) {
				break; // remaining anchors are unresolvable.
			}
		}
		// Unresolved anchors → append by their already-sorted order.
		foreach ( $pending as $it ) {
			$result[] = $it;
		}

		return $result;
	}

	/**
	 * Nest primary children (parent set) under their parent; drop orphans whose
	 * parent isn't a top-level primary item. Children keep their own order.
	 *
	 * @param NavItem[] $primary Ordered primary items (top-level + children mixed).
	 * @return NavItem[] Top-level primary items, each with ->children populated.
	 */
	private function nest( array $primary ): array {
		$top      = array();
		$children = array();
		foreach ( $primary as $n ) {
			if ( null === $n->parent ) {
				$top[ $n->id ] = $n;
			} else {
				$children[ $n->parent ][] = $n;
			}
		}
		foreach ( $children as $parent_id => $kids ) {
			if ( isset( $top[ $parent_id ] ) ) {
				$top[ $parent_id ]->children = $this->order( $kids );
			}
			// else: orphan (no such parent) — dropped.
		}
		return array_values( $top );
	}

	/**
	 * Reset state. Test-only convenience.
	 */
	public function reset(): void {
		$this->items           = array();
		$this->seq             = 0;
		$this->providers_fired = false;
	}
}
