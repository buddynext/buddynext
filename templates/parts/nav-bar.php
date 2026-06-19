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
$bn_nav_is_sub = ! empty( $is_sub );
$bn_nav_extra  = isset( $extra_class ) ? trim( (string) $extra_class ) : '';

// Sub-bar reactive wiring: a sub-bar is ALWAYS in the DOM (so a reactive parent
// click can reveal it without a reload) and toggles its own visibility from the
// branch state. These args are set only by the recursive sub-bar render below.
$bn_subnav_branch = isset( $subnav_branch ) && is_array( $subnav_branch ) ? $subnav_branch : array();
$bn_subnav_target = isset( $subnav_parent_target ) ? (string) $subnav_parent_target : '';
$bn_subnav_hidden = ! empty( $subnav_hidden );
$bn_is_subnav_box = $bn_nav_is_sub && ! empty( $bn_subnav_branch );

// Pre-escaped sub-bar context attribute (the branch slugs that keep this sub-bar
// shown while one of them is the active tab). Built here to keep the markup flat.
$bn_subnav_ctx_attr = $bn_is_subnav_box
	? esc_attr(
		(string) wp_json_encode(
			array(
				'tabSlug' => $bn_subnav_target,
				'branch'  => $bn_subnav_branch,
			)
		)
	)
	: '';

$bn_nav_class = 'bn-tabs' . ( $bn_nav_is_sub ? ' bn-tabs--sub' : '' ) . ( '' !== $bn_nav_extra ? ' ' . $bn_nav_extra : '' );
?>
<div class="<?php echo esc_attr( $bn_nav_class ); ?>" role="tablist" aria-label="<?php echo esc_attr( $bn_nav_label ); ?>"
	<?php if ( $bn_is_subnav_box ) : ?>
		data-wp-context='<?php echo $bn_subnav_ctx_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above. ?>'
		data-wp-bind--hidden="!state.isActiveBranch"
		<?php echo $bn_subnav_hidden ? 'hidden' : ''; ?>
	<?php endif; ?>
>
	<?php
	foreach ( $bn_nav_items as $bn_item ) :
		if ( ! ( $bn_item instanceof NavItem ) ) {
			continue;
		}
		$bn_target   = null !== $bn_item->tab ? $bn_item->tab : $bn_item->id;
		$bn_count    = ( null !== $bn_item->count_value && $bn_item->count_value > 0 ) ? (string) $bn_item->count_value : '';
		$bn_aria     = '' !== $bn_count ? sprintf( '%s (%s)', $bn_item->label, $bn_count ) : $bn_item->label;
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
// One-level sub-nav: render EVERY parent's children below the bar (always in the
// DOM) and let each sub-bar reveal itself reactively when its branch is active.
// Conditional PHP rendering would break the reactive case — a parent clicked
// client-side never reloads, so a sub-bar that wasn't server-rendered could
// never appear. The sub-bar's active child is driven by the same activeTab.
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
		$bn_branch        = array_merge( array( $bn_target, $bn_item->id ), $bn_child_targets );
		$bn_branch_active = in_array( $bn_nav_active, $bn_branch, true );
		buddynext_get_template(
			'parts/nav-bar.php',
			array(
				'items'                => $bn_item->children,
				'active'               => $bn_nav_active,
				'tablist_label'        => $bn_item->label,
				'is_sub'               => true,
				'subnav_branch'        => $bn_child_targets,
				'subnav_parent_target' => $bn_target,
				'subnav_hidden'        => ! $bn_branch_active,
			)
		);
	endforeach;
endif;
