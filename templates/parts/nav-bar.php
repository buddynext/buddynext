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
 * children, its sub-nav is rendered (always in the DOM, revealed reactively when
 * the branch is active) wrapped together with the bar in a `.bn-navgroup` unit.
 *
 * Reactive vs link, decided per item by the contract:
 *   - item->tab set  → reactive tab (Interactivity `actions.setTab`), with an
 *     `<a href>` no-JS fallback when item->url is also present.
 *   - item->url only → a real link tab (full navigation), `aria-current`.
 * A parent (has children) lights up while ANY child is active (isActiveBranch).
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
			$targets[] = null !== $kid->tab ? $kid->tab : $kid->id;
		}
	}
	return $targets;
};
?>
<div class="bn-navgroup">
	<div class="<?php echo esc_attr( $bn_nav_class ); ?>" role="tablist" aria-label="<?php echo esc_attr( $bn_nav_label ); ?>">
		<?php
		foreach ( $bn_nav_items as $bn_item ) :
			if ( ! ( $bn_item instanceof NavItem ) ) {
				continue;
			}
			$bn_target   = null !== $bn_item->tab ? $bn_item->tab : $bn_item->id;
			$bn_count    = ( null !== $bn_item->count_value && $bn_item->count_value > 0 ) ? (string) $bn_item->count_value : '';
			$bn_aria     = '' !== $bn_count ? sprintf( '%s (%s)', $bn_item->label, $bn_count ) : $bn_item->label;
			$bn_reactive = null !== $bn_item->tab;

			// A parent (has children) stays active while any child is the active
			// tab; its branch slugs ride in the per-tab context for isActiveBranch.
			$bn_child_targets = $bn_nav_child_targets( $bn_item );
			$bn_has_children  = ! empty( $bn_child_targets );
			$bn_branch        = array_merge( array( $bn_target, $bn_item->id ), $bn_child_targets );
			$bn_is_active     = '' !== $bn_nav_active && in_array( $bn_nav_active, $bn_branch, true );
			$bn_state_active  = $bn_has_children ? 'state.isActiveBranch' : 'state.isActiveTab';
			$bn_ctx           = $bn_has_children
				? array(
					'tabSlug' => $bn_target,
					'branch'  => $bn_child_targets,
				)
				: array( 'tabSlug' => $bn_target );
			$bn_ctx_attr      = esc_attr( (string) wp_json_encode( $bn_ctx ) );
			?>
			<?php if ( $bn_reactive && null !== $bn_item->url ) : ?>
				<a class="bn-tab" role="tab"
					data-wp-context='<?php echo $bn_ctx_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>'
					data-wp-class--active="<?php echo esc_attr( $bn_state_active ); ?>"
					data-wp-bind--aria-selected="<?php echo esc_attr( $bn_state_active ); ?>"
					aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $bn_aria ); ?>"
					data-wp-on--click="actions.setTab"
					data-tab="<?php echo esc_attr( $bn_target ); ?>"
					href="<?php echo esc_url( $bn_item->url ); ?>">
					<?php require __DIR__ . '/nav-bar-tab-inner.php'; ?>
				</a>
			<?php elseif ( $bn_reactive ) : ?>
				<button class="bn-tab" role="tab" type="button"
					data-wp-context='<?php echo $bn_ctx_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped. ?>'
					data-wp-class--active="<?php echo esc_attr( $bn_state_active ); ?>"
					data-wp-bind--aria-selected="<?php echo esc_attr( $bn_state_active ); ?>"
					aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
					aria-label="<?php echo esc_attr( $bn_aria ); ?>"
					data-wp-on--click="actions.setTab"
					data-tab="<?php echo esc_attr( $bn_target ); ?>">
					<?php require __DIR__ . '/nav-bar-tab-inner.php'; ?>
				</button>
			<?php else : ?>
				<a class="bn-tab" role="tab"
					aria-selected="<?php echo $bn_is_active ? 'true' : 'false'; ?>"
					<?php echo $bn_is_active ? 'aria-current="page"' : ''; ?>
					aria-label="<?php echo esc_attr( $bn_aria ); ?>"
					href="<?php echo esc_url( (string) $bn_item->url ); ?>">
					<?php require __DIR__ . '/nav-bar-tab-inner.php'; ?>
				</a>
			<?php endif; ?>
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
		$bn_target        = null !== $bn_item->tab ? $bn_item->tab : $bn_item->id;
		$bn_child_targets = $bn_nav_child_targets( $bn_item );
		$bn_branch        = array_merge( array( $bn_target, $bn_item->id ), $bn_child_targets );
		buddynext_get_template(
			'parts/nav-subnav.php',
			array(
				'items'         => $bn_item->children,
				'active'        => $bn_nav_active,
				'branch'        => $bn_child_targets,
				'parent_target' => $bn_target,
				'hidden'        => ! in_array( $bn_nav_active, $bn_branch, true ),
				'tablist_label' => $bn_item->label,
			)
		);
	endforeach;
	?>
</div>
