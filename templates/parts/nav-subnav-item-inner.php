<?php
/**
 * BuddyNext template part: nav-subnav-item-inner — inner markup of one sub-nav
 * item (label + optional count), shared by the reactive-link, reactive-button,
 * and plain-link branches in parts/nav-subnav.php so the three never drift.
 * Expects $bn_sub (NavItem) and $bn_s_count (string) in scope.
 *
 * @package BuddyNext\Nav
 */

defined( 'ABSPATH' ) || exit;
?>
<span class="bn-subnav__label"><?php echo esc_html( $bn_sub->label ); ?></span>
<?php if ( '' !== $bn_s_count ) : ?>
	<span class="bn-subnav__count"><?php echo esc_html( $bn_s_count ); ?></span>
<?php endif; ?>
