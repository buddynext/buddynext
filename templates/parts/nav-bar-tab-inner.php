<?php
/**
 * BuddyNext template part: nav-bar-tab-inner — inner content of one .bn-tab.
 *
 * Icon (optional) + label + count badge. Shared by the three tab branches in
 * parts/nav-bar.php so the inner markup is written once. Expects, in scope:
 *   \BuddyNext\Nav\NavItem $bn_item  The item being rendered.
 *   string                 $bn_count Pre-resolved count badge text ('' = none).
 *
 * @package BuddyNext\Nav
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( isset( $bn_item ) && null !== $bn_item->icon && function_exists( 'buddynext_icon' ) ) {
	buddynext_icon( $bn_item->icon );
}
?>
<span class="bn-tab__label"><?php echo esc_html( isset( $bn_item ) ? $bn_item->label : '' ); ?></span>
<?php
// Per-tab count badges are hidden by default for a calmer nav, consistent with the
// activity feed (which has no per-tab counts). A site can restore them by returning
// true from the buddynext_nav_show_tab_count filter.
$bn_show_count = isset( $bn_count ) && '' !== $bn_count
	&& (bool) apply_filters( 'buddynext_nav_show_tab_count', false, $bn_item ?? null );
?>
<?php if ( $bn_show_count ) : ?>
	<span class="bn-tab__count"><?php echo esc_html( $bn_count ); ?></span>
<?php endif; ?>
