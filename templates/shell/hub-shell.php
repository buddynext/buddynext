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

// NOTE: no --with-sidebar modifier here. The sidebar grid track is driven by
// .bn-app__shell:has(.bn-app__right) so it reacts to the sidebar's presence
// INSIDE the swapped router region — a class on .bn-app__shell (outside the
// region) would go stale on a client navigation that adds/removes the sidebar.
$bn_shell_classes = 'bn-app__shell';

// "Show community navigation" toggle (Settings → default on). When off, the
// owner has opted to drive navigation entirely from the host theme menus, so
// BuddyNext renders neither the left rail nor the mobile bottom tab bar.
$bn_community_nav = buddynext_community_nav_enabled();
if ( ! $bn_community_nav ) {
	$bn_shell_classes .= ' bn-app__shell--no-nav';
}

// Client-side navigation wiring (data-wp-interactive + router region + navigate
// click handler) is emitted ONLY when client-nav is enabled. While it is off
// (the rollout default), the shell renders exactly as before — the whole .bn-app
// is NOT promoted to a single Interactivity hydration region, which otherwise
// repaints the feed on every load.
$bn_client_nav   = (bool) apply_filters( 'buddynext_client_nav_enabled', true );
$bn_app_attrs    = $bn_client_nav ? ' data-wp-interactive="buddynext" data-wp-on--click="actions.navigate"' : '';
$bn_region_attrs = $bn_client_nav ? ' data-wp-interactive="buddynext" data-wp-router-region="buddynext/main"' : '';
?>
<div class="bn-app" id="bn-app" data-bn-hub="<?php echo esc_attr( $hub ); ?>"<?php echo $bn_app_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal static attribute string, no user data ?>>

	<div class="<?php echo esc_attr( $bn_shell_classes ); ?>">

		<?php if ( buddynext_community_rail_enabled() ) : ?>
			<?php buddynext_get_template( 'shell/rail.php', array( 'hub' => $hub ) ); ?>
		<?php endif; ?>

		<?php
		// Client-nav router region wraps BOTH the main column AND the right sidebar
		// (display:contents, so the shell grid still lays out main | sidebar as
		// direct tracks). Wrapping both means a client navigation swaps the sidebar
		// together with the content — otherwise the sidebar lives outside the region,
		// and navigating from a no-sidebar page (e.g. Edit Profile) to a with-sidebar
		// page leaves no sidebar (and vice-versa leaves a stale one). The grid's
		// sidebar track is driven by :has(.bn-app__right), reacting to the sidebar's
		// presence inside the swapped region — no class lives outside the region.
		?>
		<div class="bn-app__region"<?php echo $bn_region_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- internal static attribute string, no user data ?>>
		<main class="bn-app__main" id="bn-main-content" tabindex="-1">
			<?php
			// Render a menu assigned to the "BuddyNext Community Nav" location. The
			// location was registered (Plugin::register_nav_menus) but no template
			// ever output it, so an assigned menu was invisible. Show it as a
			// community nav bar atop the hub content; only renders when a menu is
			// actually assigned (no theme fallback).
			if ( has_nav_menu( 'buddynext-community' ) ) :
				?>
				<nav class="bn-community-menu" aria-label="<?php esc_attr_e( 'Community menu', 'buddynext' ); ?>">
					<?php
					wp_nav_menu(
						array(
							'theme_location' => 'buddynext-community',
							'container'      => false,
							'menu_class'     => 'bn-community-menu__list',
							'depth'          => 1,
							'fallback_cb'    => false,
						)
					);
					?>
				</nav>
				<?php
			endif;
			// Trusted: buffered output from buddynext_get_template() — already escaped at point of emit.
			echo $bn_main_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</main>

		<?php if ( $show_right_sidebar ) : ?>
			<?php buddynext_get_template( 'shell/right-sidebar.php', array( 'hub' => $hub ) ); ?>
		<?php endif; ?>
		</div>

	</div>

	<?php if ( buddynext_community_mobile_nav_enabled() ) : ?>
		<?php buddynext_get_template( 'partials/nav.php', array( 'bn_nav_active' => '' ) ); ?>
	<?php endif; ?>

</div>
