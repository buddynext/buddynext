<?php
/**
 * BuddyNext template part: nav-bar — the shared PRIMARY tab bar.
 *
 * Renders the `.bn-tabs` / `.bn-tab` underline tab bar from resolved Nav primary
 * items (NavItem[]). Used by BOTH the member profile and the space surface (and
 * any future surface / integration) so the primary navigation is byte-identical
 * everywhere — one component, one active convention (`aria-selected`).
 *
 * Sub-navigation is a SEPARATE component (parts/nav-subnav.php, `.bn-subnav`) so
 * it never inherits or fights `.bn-tab` rules. For each primary item that has
 * children, its sub-nav is rendered alongside the bar in a `.bn-navgroup` unit.
 *
 * Every tab is a clean-URL `<a href>` (item->url) that server-renders its panel
 * (the registry `render` seam); active state is server-rendered `aria-current`.
 * The client-nav transport (when enabled) intercepts the click and swaps the
 * region without a reload, re-syncing active state generically. A parent (has
 * children) lights up while it OR any of its children is the active tab.
 *
 * @package BuddyNext\Nav
 *
 * @var \BuddyNext\Nav\NavItem[] $items         Required. Top-level primary items.
 * @var string                   $active        Optional. Active tab slug (drives the bar
 *                                              and which sub-nav child is lit).
 * @var string                   $tablist_label Optional. aria-label for the tablist.
 * @var string                   $extra_class   Optional. Extra class on the .bn-tabs bar.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

use BuddyNext\Nav\NavItem;

$bn_nav_items = isset( $items ) && is_array( $items ) ? $items : array();
if ( empty( $bn_nav_items ) ) {
	return;
}

$bn_nav_active = isset( $active ) ? (string) $active : '';
$bn_nav_label  = isset( $tablist_label ) && '' !== (string) $tablist_label ? (string) $tablist_label : __( 'Sections', 'buddynext' );
$bn_nav_extra  = isset( $extra_class ) ? trim( (string) $extra_class ) : '';

$bn_nav_class = 'bn-tabs' . ( '' !== $bn_nav_extra ? ' ' . $bn_nav_extra : '' );

/**
 * Child target slugs for a parent item (tab ?? id).
 *
 * @param NavItem $item Parent nav item.
 * @return string[]
 */
$bn_nav_child_targets = static function ( NavItem $item ): array {
	$targets = array();
	foreach ( $item->children as $kid ) {
		if ( $kid instanceof NavItem ) {
			$targets[] = $kid->id;
		}
	}
	return $targets;
};
?>
<div class="bn-navgroup">
	<div class="<?php echo esc_attr( $bn_nav_class ); ?>" data-bn-nav role="tablist" aria-label="<?php echo esc_attr( $bn_nav_label ); ?>">
		<?php
		foreach ( $bn_nav_items as $bn_item ) :
			if ( ! ( $bn_item instanceof NavItem ) ) {
				continue;
			}
			$bn_count = ( null !== $bn_item->count_value && $bn_item->count_value > 0 ) ? (string) $bn_item->count_value : '';
			$bn_aria  = '' !== $bn_count ? sprintf( '%s (%s)', $bn_item->label, $bn_count ) : $bn_item->label;

			// A parent (has children) stays active while ANY of its children is the
			// active tab; a leaf is active when it matches. Active state is server-
			// rendered as aria-current="page" — every tab is a clean-URL link, and the
			// client-nav transport re-syncs active state generically after a swap.
			$bn_child_targets = $bn_nav_child_targets( $bn_item );
			$bn_branch        = array_merge( array( $bn_item->id ), $bn_child_targets );
			$bn_is_active     = '' !== $bn_nav_active && in_array( $bn_nav_active, $bn_branch, true );

			// A tab declared full_load (a drill-in page / its own router region) tells
			// the client-nav transport to full-load it instead of swapping — emitted as
			// data-bn-full-load so the transport reads the nav API, not a route regex.
			$bn_full_load_attr = $bn_item->full_load ? ' data-bn-full-load' : '';
			?>
			<a class="bn-tab" role="tab"
				aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
				<?php echo $bn_is_active ? 'aria-current="page"' : ''; ?>
				aria-label="<?php echo esc_attr( $bn_aria ); ?>"
				<?php echo $bn_full_load_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static attribute literal. ?>
				href="<?php echo esc_url( (string) $bn_item->url_value ); ?>">
				<?php require __DIR__ . '/nav-bar-tab-inner.php'; ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
	// Sub-nav: render each parent's children via the dedicated .bn-subnav part —
	// always in the DOM, revealed reactively when its branch is active (a parent
	// clicked client-side never reloads, so the sub-nav can't be PHP-conditional).
	foreach ( $bn_nav_items as $bn_item ) :
		if ( ! ( $bn_item instanceof NavItem ) || empty( $bn_item->children ) ) {
			continue;
		}
		$bn_branch = array_merge( array( $bn_item->id ), $bn_nav_child_targets( $bn_item ) );
		buddynext_get_template(
			'parts/nav-subnav.php',
			array(
				'items'         => $bn_item->children,
				'active'        => $bn_nav_active,
				'hidden'        => ! in_array( $bn_nav_active, $bn_branch, true ),
				'tablist_label' => $bn_item->label,
			)
		);
	endforeach;
	?>
</div>
