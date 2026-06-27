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
// Render the badge only when a count was actually resolved. The resolver decides
// which counts run (NavRegistry): lightweight people-counts like Members / Network
// stay on, while expensive per-user content COUNT(*) badges are skipped at scale
// (opt every badge back on via the buddynext_nav_show_tab_count filter).
?>
<?php if ( isset( $bn_count ) && '' !== $bn_count ) : ?>
	<span class="bn-tab__count"><?php echo esc_html( $bn_count ); ?></span>
<?php endif; ?>
