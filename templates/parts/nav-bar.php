<?php
/**
 * BuddyNext template part: nav-bar — the shared primary tab bar.
 *
 * Renders a `.bn-tabs` bar from resolved Nav primary items (NavItem[]), plus a
 * one-level sub-nav bar for the active item when it has children. Used by BOTH
 * the member profile and the space surface (and any future surface) so the tab
 * navigation is byte-identical everywhere — one component, one active-state
 * convention (`aria-selected`), one nav model.
 *
 * Reactive vs link, decided per item by the contract:
 *   - item->tab set  → reactive tab (Interactivity `actions.setTab`), with an
 *     `<a href>` no-JS fallback when item->url is also present.
 *   - item->url only → a real link tab (full navigation), `aria-current`.
 *
 * @package BuddyNext\Nav
 *
 * @var \BuddyNext\Nav\NavItem[] $items         Required. Top-level primary items.
 * @var string                   $active        Optional. Active tab slug (drives both the
 *                                              top bar and, for a parent, which child
 *                                              sub-tab is lit — single activeTab dimension).
 * @var string                   $tablist_label Optional. aria-label for the tablist.
 * @var string                   $extra_class   Optional. Extra class on the .bn-tabs wrapper.
 * @var bool                     $is_sub        Optional. Internal: rendering the sub bar.
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
$bn_nav_is_sub     = ! empty( $is_sub );
$bn_nav_extra      = isset( $extra_class ) ? trim( (string) $extra_class ) : '';

$bn_nav_class = 'bn-tabs' . ( $bn_nav_is_sub ? ' bn-tabs--sub' : '' ) . ( '' !== $bn_nav_extra ? ' ' . $bn_nav_extra : '' );
?>
<div class="<?php echo esc_attr( $bn_nav_class ); ?>" role="tablist" aria-label="<?php echo esc_attr( $bn_nav_label ); ?>">
	<?php
	foreach ( $bn_nav_items as $bn_item ) :
		if ( ! ( $bn_item instanceof NavItem ) ) {
			continue;
		}
		$bn_target = null !== $bn_item->tab ? $bn_item->tab : $bn_item->id;
		$bn_count  = ( null !== $bn_item->count_value && $bn_item->count_value > 0 ) ? (string) $bn_item->count_value : '';
		$bn_aria   = '' !== $bn_count ? sprintf( '%s (%s)', $bn_item->label, $bn_count ) : $bn_item->label;
		$bn_reactive = null !== $bn_item->tab;

		// Branch: a parent with sub-nav stays active when ANY child is the active
		// tab, so its sub-bar shows and the parent stays lit. The branch slug list
		// rides in the per-tab context so state.isActiveBranch can resolve it.
		$bn_child_targets = array();
		foreach ( $bn_item->children as $bn_kid ) {
			if ( $bn_kid instanceof NavItem ) {
				$bn_child_targets[] = null !== $bn_kid->tab ? $bn_kid->tab : $bn_kid->id;
			}
		}
		$bn_has_children = ! empty( $bn_child_targets );
		$bn_branch       = array_merge( array( $bn_target, $bn_item->id ), $bn_child_targets );
		$bn_is_active    = '' !== $bn_nav_active && in_array( $bn_nav_active, $bn_branch, true );
		$bn_state_active = $bn_has_children ? 'state.isActiveBranch' : 'state.isActiveTab';
		$bn_ctx          = $bn_has_children
			? array(
				'tabSlug' => $bn_target,
				'branch'  => $bn_child_targets,
			)
			: array( 'tabSlug' => $bn_target );
		$bn_ctx_attr     = esc_attr( (string) wp_json_encode( $bn_ctx ) );
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
// One-level sub-nav: render the children below the bar when the active tab is
// the parent OR one of its children (single activeTab dimension — a child being
// active keeps the parent's branch open). The sub-bar's active child is driven
// by the same activeTab, so the child whose tab === activeTab lights up.
if ( ! $bn_nav_is_sub ) :
	foreach ( $bn_nav_items as $bn_item ) :
		if ( ! ( $bn_item instanceof NavItem ) || empty( $bn_item->children ) ) {
			continue;
		}
		$bn_target        = null !== $bn_item->tab ? $bn_item->tab : $bn_item->id;
		$bn_child_targets = array();
		foreach ( $bn_item->children as $bn_kid ) {
			if ( $bn_kid instanceof NavItem ) {
				$bn_child_targets[] = null !== $bn_kid->tab ? $bn_kid->tab : $bn_kid->id;
			}
		}
		$bn_branch = array_merge( array( $bn_target, $bn_item->id ), $bn_child_targets );
		if ( in_array( $bn_nav_active, $bn_branch, true ) ) :
			buddynext_get_template(
				'parts/nav-bar.php',
				array(
					'items'         => $bn_item->children,
					'active'        => $bn_nav_active,
					'tablist_label' => $bn_item->label,
					'is_sub'        => true,
				)
			);
		endif;
	endforeach;
endif;
