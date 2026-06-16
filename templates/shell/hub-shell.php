<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — Hub shell (inside theme chrome).
 *
 * Rendered between the active theme's get_header() and get_footer(). The
 * shell emits the .bn-app canvas: left rail + main content + optional
 * right sidebar. The inner hub template renders inside the main column
 * and may register sidebar content by hooking the
 * buddynext_right_sidebar action — when anything is hooked, the shell
 * auto-renders the right column.
 *
 * The active theme's get_header() IS the top navigation; BuddyNext no
 * longer renders its own topbar inside .bn-app. On mobile (<= 640px) the
 * .bn-mobile-nav bottom tab bar from templates/partials/nav.php is the
 * primary navigation surface and is rendered here so it appears on every
 * BN hub without each hub template needing to remember to include it.
 *
 * No DOCTYPE / <html> / <head> / <body> emission lives here. The host
 * theme owns the document; this template owns only the .bn-app subtree.
 * The .bn-app element bursts to 100vw via bn-shell.css so it stays
 * edge-to-edge regardless of the theme's content container.
 *
 * Context variables (supplied by PageRouter):
 *   $inner_template       string  Relative path of the hub template (e.g. 'feed/home.php').
 *   $hub                  string  Current hub slug.
 *   $context              array   Original context array — re-passed to the inner template.
 *   $show_right_sidebar   bool    Optional explicit override; otherwise detected from has_action.
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

// Render the inner template into a buffer. The inner template may hook
// buddynext_right_sidebar to register sidebar content; after this call
// we know whether the right column is needed.
ob_start();
buddynext_get_template( (string) $inner_template, $context );
$bn_main_html = (string) ob_get_clean();

// The right sidebar belongs to the toggleable Sidebar feature (FeatureRegistry
// 'sidebar', default-on). When the owner turns it off the whole right column is
// suppressed here — without this gate has_action() stays true (widgets register
// unconditionally) and the column renders empty cards + empty-state messages.
$show_right_sidebar = buddynext_feature_enabled( 'sidebar' )
	&& (
		! empty( $show_right_sidebar )
		|| ! empty( $context['show_right_sidebar'] )
		|| has_action( 'buddynext_right_sidebar' )
	);

$bn_shell_classes = 'bn-app__shell';
if ( $show_right_sidebar ) {
	$bn_shell_classes .= ' bn-app__shell--with-sidebar';
}

// "Show community navigation" toggle (Settings → default on). When off, the
// owner has opted to drive navigation entirely from the host theme menus, so
// BuddyNext renders neither the left rail nor the mobile bottom tab bar.
$bn_community_nav = buddynext_community_nav_enabled();
if ( ! $bn_community_nav ) {
	$bn_shell_classes .= ' bn-app__shell--no-nav';
}
?>
<div class="bn-app" id="bn-app" data-bn-hub="<?php echo esc_attr( $hub ); ?>">

	<div class="<?php echo esc_attr( $bn_shell_classes ); ?>">

		<?php if ( $bn_community_nav ) : ?>
			<?php buddynext_get_template( 'shell/rail.php', array( 'hub' => $hub ) ); ?>
		<?php endif; ?>

		<main class="bn-app__main" id="bn-main-content" tabindex="-1">
			<?php
			// Trusted: buffered output from buddynext_get_template() — already escaped at point of emit.
			echo $bn_main_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</main>

		<?php if ( $show_right_sidebar ) : ?>
			<?php buddynext_get_template( 'shell/right-sidebar.php', array( 'hub' => $hub ) ); ?>
		<?php endif; ?>

	</div>

	<?php if ( $bn_community_nav ) : ?>
		<?php buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => '' ) ); ?>
	<?php endif; ?>

</div>
