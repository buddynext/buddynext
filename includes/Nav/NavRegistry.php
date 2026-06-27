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
	 * Per-request resolved-nav cache, keyed by context signature.
	 *
	 * A surface is routinely resolved more than once in a single request — e.g.
	 * the shared `parts/space-header.php` and the space body both ask for the same
	 * space nav. Each resolve re-runs every `count` callable, some of which hit the
	 * DB, so memoizing the `ResolvedNav` for an identical context removes that
	 * duplicate work. All registrations land on the first resolve (via the one-shot
	 * `buddynext_register_nav` action), so the cache cannot go stale within a
	 * request; `reset()` clears it for tests.
	 *
	 * @var array<string,ResolvedNav>
	 */
	private array $resolved_cache = array();

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
		// Return the memoized result when this exact context was already resolved
		// this request (the header part + the body resolve the same space nav).
		$cache_key = $this->context_signature( $ctx );
		if ( isset( $this->resolved_cache[ $cache_key ] ) ) {
			return $this->resolved_cache[ $cache_key ];
		}

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

		// Build + validate. Items injected via the filter carry no `__seq`, so give
		// them a monotonic seq ABOVE the registered ones — otherwise they'd all tie
		// at 0 and the priority tiebreak (order()) would be undefined between them.
		// Duplicate (layer, id) registrations keep the FIRST and warn in debug, so a
		// careless integration reusing a core id can't silently clobber the tab.
		$items = array();
		$seen  = array();
		$next  = $this->seq;
		foreach ( $raw as $a ) {
			if ( ! is_array( $a ) ) {
				continue;
			}
			$seq  = isset( $a['__seq'] ) ? (int) $a['__seq'] : $next++;
			$item = NavItem::from_array( $a, $seq );
			if ( null === $item ) {
				continue;
			}
			$dupe_key = $item->layer . ':' . $item->id;
			if ( isset( $seen[ $dupe_key ] ) ) {
				_doing_it_wrong(
					'buddynext_register_nav',
					sprintf(
						/* translators: 1: nav item id, 2: layer. */
						esc_html__( 'Duplicate nav item "%1$s" on layer "%2$s" ignored — ids must be unique within a (surface, layer).', 'buddynext' ),
						esc_html( $item->id ),
						esc_html( $item->layer )
					),
					'0.4.0'
				);
				continue;
			}
			$seen[ $dupe_key ] = true;
			$items[]           = $item;
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

		// Resolve counts + URLs (lazy callables see the live context) + hide_empty.
		$kept = array();
		foreach ( $items as $n ) {
			$n->count_value = $n->resolve_count( $ctx );
			$n->url_value   = $n->resolve_url( $ctx );
			$n->label_value = $n->resolve_count_label( $n->count_value );
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

		$resolved                           = new ResolvedNav( $by_layer );
		$this->resolved_cache[ $cache_key ] = $resolved;
		return $resolved;
	}

	/**
	 * Build a stable per-request cache key for a resolution context. Two contexts
	 * that share a signature resolve to the same nav (surface + subject + viewer +
	 * role + active sub + any per-surface extra), so the result is interchangeable.
	 *
	 * @param NavContext $ctx Resolution context.
	 * @return string
	 */
	private function context_signature( NavContext $ctx ): string {
		return implode(
			'|',
			array(
				$ctx->surface,
				(string) $ctx->subject_id,
				(string) $ctx->viewer_id,
				$ctx->role,
				$ctx->sub,
				md5( (string) wp_json_encode( $ctx->extra ) ),
			)
		);
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
				$by_priority = $a->priority <=> $b->priority;
				return 0 !== $by_priority ? $by_priority : ( $a->seq <=> $b->seq );
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
		$this->resolved_cache  = array();
	}
}
