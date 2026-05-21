<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Full-viewport hub shell.
 *
 * Rendered by PageRouter::dispatch_hub_template() between get_header()
 * and get_footer(). The .bn-app wrapper uses the burst-out technique
 * (100vw + negative margins) so the BuddyNext canvas escapes any host
 * theme container (.site-main, .container, max-width: 1200px) and
 * occupies the full viewport width.
 *
 * Layout: top bar + left rail + main content (+ optional right sidebar).
 *
 * Context variables (all required — supplied by PageRouter):
 *   $inner_template       string  Relative path of the hub template, e.g. 'feed/home.php'.
 *   $hub                  string  Current hub slug.
 *   $context              array   Original context array — re-passed to the inner template.
 *   $show_right_sidebar   bool    Optional — when true, renders the right-sidebar slot.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

if ( ! isset( $inner_template ) || '' === (string) $inner_template ) {
	return;
}
if ( ! isset( $hub ) ) {
	$hub = (string) get_query_var( 'bn_hub', '' );
}
if ( ! isset( $context ) || ! is_array( $context ) ) {
	$context = array();
}
$show_right_sidebar = ! empty( $show_right_sidebar ) || ! empty( $context['show_right_sidebar'] );

$bn_shell_classes = 'bn-app__shell';
if ( $show_right_sidebar ) {
	$bn_shell_classes .= ' bn-app__shell--with-sidebar';
}
?>
<div class="bn-app" id="bn-app" data-bn-hub="<?php echo esc_attr( $hub ); ?>">

	<?php buddynext_get_template( 'shell/topbar.php', array( 'hub' => $hub ) ); ?>

	<div class="<?php echo esc_attr( $bn_shell_classes ); ?>">

		<?php buddynext_get_template( 'shell/rail.php', array( 'hub' => $hub ) ); ?>

		<main class="bn-app__main" id="bn-main-content" tabindex="-1">
			<?php buddynext_get_template( (string) $inner_template, $context ); ?>
		</main>

		<?php if ( $show_right_sidebar ) : ?>
			<?php buddynext_get_template( 'shell/right-sidebar.php', array( 'hub' => $hub ) ); ?>
		<?php endif; ?>

	</div>
</div>
