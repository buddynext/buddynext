<?php
/**
 * BuddyNext — Nav item.
 *
 * One declarative navigation item (a tab, a stat, a rail link, a sub-nav child)
 * plus its validation and per-context resolution. The single contract every
 * surface + every integration uses, so an item can never render inconsistently.
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

namespace BuddyNext\Nav;

/**
 * Value object + tree node for a single nav item.
 *
 * Declared fields are read-only (the contract). `children` and `count_value`
 * are populated during registry resolution (nesting + lazy count).
 */
final class NavItem {

	public const LAYERS   = array( 'primary', 'metric', 'rail', 'context' );
	public const SURFACES = array( 'global', 'profile', 'space' );

	/**
	 * Resolved sub-nav children (primary items only).
	 *
	 * @var NavItem[]
	 */
	public array $children = array();

	/**
	 * Resolved count for the current context (null = no count).
	 *
	 * @var int|null
	 */
	public ?int $count_value = null;

	/**
	 * Resolved URL for the current context (null = no URL). Mirrors count_value:
	 * `url` may be a string OR a callable(NavContext):string, resolved lazily so a
	 * per-subject route (e.g. a space's ?bn_tab= link) is computed against the
	 * live context. Renderers read THIS, never the raw `url`.
	 *
	 * @var string|null
	 */
	public ?string $url_value = null;

	/**
	 * Construct an item. Use NavItem::from_array() for validated creation.
	 *
	 * @param string            $id         Unique within (surface, layer).
	 * @param string            $surface    global | profile | space.
	 * @param string            $layer      primary | metric | rail | context.
	 * @param string            $label      Display label (already translated).
	 * @param string|null       $parent     Parent primary item id (sub-nav), else null.
	 * @param string|null       $icon       Lucide icon slug.
	 * @param string|null       $tab        In-page reactive tab target.
	 * @param string|null       $url        Real route (rail/context/metric list).
	 * @param string|null       $capability Capability gate (buddynext_can), null = public.
	 * @param callable|null     $condition  callable(NavContext):bool extra visibility gate.
	 * @param bool              $hide_empty Omit when the resolved count is 0/null.
	 * @param int               $priority   Default order (lower = earlier).
	 * @param string|null       $before     Order anchor: place before this item id.
	 * @param string|null       $after      Order anchor: place after this item id.
	 * @param string|null       $delta      Metric-only week-over-week chip text.
	 * @param string|null       $trend      Metric-only: up|down|flat.
	 * @param int|callable|null $count   Badge/metric value, int or callable(NavContext):int.
	 * @param callable|null     $active     callable(NavContext):bool active-state override.
	 * @param int               $seq        Registration order (stable tiebreak).
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $surface,
		public readonly string $layer,
		public readonly string $label,
		public readonly ?string $parent = null,
		public readonly ?string $icon = null,
		public readonly ?string $tab = null,
		public readonly mixed $url = null,
		public readonly ?string $capability = null,
		public readonly mixed $condition = null,
		public readonly bool $hide_empty = false,
		public readonly int $priority = 50,
		public readonly ?string $before = null,
		public readonly ?string $after = null,
		public readonly ?string $delta = null,
		public readonly ?string $trend = null,
		public readonly mixed $count = null,
		public readonly mixed $active = null,
		public readonly int $seq = 0
	) {}

	/**
	 * Build + validate an item from a registration array. Returns null when the
	 * array is malformed (missing id/surface/layer/label, bad layer-specific
	 * requirements) — invalid items are dropped, never rendered.
	 *
	 * @param array<string,mixed> $a   Registration array.
	 * @param int                 $seq Registration order.
	 * @return self|null
	 */
	public static function from_array( array $a, int $seq = 0 ): ?self {
		$id      = isset( $a['id'] ) ? sanitize_key( (string) $a['id'] ) : '';
		$surface = isset( $a['surface'] ) ? sanitize_key( (string) $a['surface'] ) : '';
		$layer   = isset( $a['layer'] ) ? sanitize_key( (string) $a['layer'] ) : '';
		$label   = isset( $a['label'] ) ? (string) $a['label'] : '';

		if ( '' === $id || '' === $label
			|| ! in_array( $surface, self::SURFACES, true )
			|| ! in_array( $layer, self::LAYERS, true )
		) {
			return null;
		}

		$tab = isset( $a['tab'] ) && '' !== (string) $a['tab'] ? sanitize_key( (string) $a['tab'] ) : null;

		// URL may be a string (escaped now) OR a callable(NavContext):string
		// (resolved lazily at resolve time, then escaped) — see resolve_url().
		$url = null;
		if ( isset( $a['url'] ) ) {
			if ( is_callable( $a['url'] ) ) {
				$url = $a['url'];
			} elseif ( '' !== (string) $a['url'] ) {
				$url = esc_url_raw( (string) $a['url'] );
			}
		}

		$parent = isset( $a['parent'] ) && '' !== (string) $a['parent'] ? sanitize_key( (string) $a['parent'] ) : null;
		$icon   = isset( $a['icon'] ) && '' !== (string) $a['icon'] ? sanitize_key( (string) $a['icon'] ) : null;

		// Layer-specific minimums.
		switch ( $layer ) {
			case 'primary':
				// A tab must be navigable in-page (tab) or via a route (url).
				if ( null === $tab && null === $url ) {
					return null;
				}
				break;
			case 'rail':
			case 'context':
				if ( null === $url ) {
					return null; // rail/context items are real links.
				}
				break;
			case 'metric':
				// `parent` is meaningless for a metric; drop it.
				$parent = null;
				break;
		}

		$condition = ( isset( $a['condition'] ) && is_callable( $a['condition'] ) ) ? $a['condition'] : null;
		$active    = ( isset( $a['active'] ) && is_callable( $a['active'] ) ) ? $a['active'] : null;
		$count     = $a['count'] ?? null;
		if ( null !== $count && ! is_int( $count ) && ! is_callable( $count ) ) {
			$count = (int) $count;
		}

		$trend = isset( $a['trend'] ) && in_array( (string) $a['trend'], array( 'up', 'down', 'flat' ), true )
			? (string) $a['trend']
			: null;

		return new self(
			id: $id,
			surface: $surface,
			layer: $layer,
			label: $label,
			parent: $parent,
			icon: $icon,
			tab: $tab,
			url: $url,
			capability: isset( $a['capability'] ) && '' !== (string) $a['capability'] ? (string) $a['capability'] : null,
			condition: $condition,
			hide_empty: ! empty( $a['hide_empty'] ),
			priority: isset( $a['priority'] ) ? (int) $a['priority'] : 50,
			before: isset( $a['before'] ) && '' !== (string) $a['before'] ? sanitize_key( (string) $a['before'] ) : null,
			after: isset( $a['after'] ) && '' !== (string) $a['after'] ? sanitize_key( (string) $a['after'] ) : null,
			delta: isset( $a['delta'] ) && '' !== (string) $a['delta'] ? (string) $a['delta'] : null,
			trend: $trend,
			count: $count,
			active: $active,
			seq: $seq,
		);
	}

	/**
	 * Whether the item is visible in this context — BOTH the capability gate and
	 * the condition callable must pass (a missing gate passes).
	 *
	 * @param NavContext $ctx Resolution context.
	 */
	public function passes( NavContext $ctx ): bool {
		if ( null !== $this->capability ) {
			$cap_ctx = $ctx->subject_id > 0 && 'space' === $ctx->surface
				? array( 'space_id' => $ctx->subject_id )
				: array();
			if ( ! buddynext_can( $ctx->viewer_id, $this->capability, $cap_ctx ) ) {
				return false;
			}
		}
		if ( null !== $this->condition && ! (bool) call_user_func( $this->condition, $ctx ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve the count for this context (runs the callable lazily). Null when
	 * the item has no count.
	 *
	 * @param NavContext $ctx Resolution context.
	 * @return int|null
	 */
	public function resolve_count( NavContext $ctx ): ?int {
		if ( null === $this->count ) {
			return null;
		}
		return is_callable( $this->count ) ? (int) call_user_func( $this->count, $ctx ) : (int) $this->count;
	}

	/**
	 * Resolve the URL for this context (runs the callable lazily, then escapes).
	 * Null when the item has no URL. A static string was already escaped in
	 * from_array(); a callable result is escaped here.
	 *
	 * @param NavContext $ctx Resolution context.
	 * @return string|null
	 */
	public function resolve_url( NavContext $ctx ): ?string {
		if ( null === $this->url ) {
			return null;
		}
		if ( is_callable( $this->url ) ) {
			$resolved = (string) call_user_func( $this->url, $ctx );
			return '' !== $resolved ? esc_url_raw( $resolved ) : null;
		}
		return (string) $this->url;
	}

	/**
	 * Whether this item is the active one in this context. Uses the `active`
	 * override when supplied; otherwise the renderer decides from the live tab /
	 * route (so this only fires for explicit overrides).
	 *
	 * @param NavContext $ctx Resolution context.
	 */
	public function is_active( NavContext $ctx ): bool {
		return null !== $this->active && (bool) call_user_func( $this->active, $ctx );
	}
}
