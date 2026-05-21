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

if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}
?>
<aside class="bn-app__right" aria-label="<?php esc_attr_e( 'Sidebar', 'buddynext' ); ?>">
	<?php
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
	?>
</aside>
