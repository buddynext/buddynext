<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Hub shell right sidebar slot.
 *
 * Optional column rendered to the right of .bn-app__main when a hub
 * template sets $show_right_sidebar = true in its context.
 *
 * Bridge plugins and hub templates inject content into this slot via the
 * buddynext_right_sidebar action. The slot only renders when at least one
 * callback has hooked into the action AND $show_right_sidebar is truthy.
 *
 * Context variables:
 *   $hub  string  Current hub slug.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}
// Buffer the slot output so an empty result renders NO <aside> at all. A callback
// can be hooked (has_action() true, so the shell opts the column in) yet emit
// nothing — e.g. a visitor viewing a profile with no social links / work / spaces,
// where every profile-right-sidebar block gates itself off. An empty but present
// .bn-app__right still trips the grid's :has(.bn-app__right) rule and reserves a
// dead ~320px column. The action still fires here in the same position, so any
// widget side effects (asset enqueues, etc.) are preserved.
ob_start();
/**
 * Fires inside the right-sidebar column.
 *
 * Bridge plugins use this to inject contextual widgets (trending tags,
 * suggested members, etc.) on every hub that opts in via
 * $show_right_sidebar = true.
 *
 * @param string $hub Current hub slug.
 */
do_action( 'buddynext_right_sidebar', $hub );
$bn_sidebar_html = trim( (string) ob_get_clean() );

if ( '' === $bn_sidebar_html ) {
	return;
}
?>
<aside class="bn-app__right" aria-label="<?php esc_attr_e( 'Sidebar', 'buddynext' ); ?>">
	<?php
	// Trusted: buffered output from the buddynext_right_sidebar callbacks — each
	// escaped at its own point of emit (same output as echoing the action inline).
	echo $bn_sidebar_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	?>
</aside>
