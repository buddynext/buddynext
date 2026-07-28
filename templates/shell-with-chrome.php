<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext — wrapper template for the WordPress template pipeline.
 *
 * WordPress includes this file when a BuddyNext hub route is active.
 * It renders the full page chrome (theme header → BN shell → theme footer)
 * inside the normal WordPress lifecycle, so output buffers (such as the
 * template enhancement buffer) fire correctly at shutdown.
 *
 * BuddyNext sits *inside* the active theme's chrome. The theme owns
 * the document — DOCTYPE / <html> / <head> / wp_head() / <body> /
 * wp_body_open() / wp_footer() / </html> all come from the theme via
 * get_header() and get_footer(). BuddyNext only renders the .bn-app
 * canvas in between. The canvas bursts to 100vw in CSS so it stays
 * edge-to-edge regardless of whatever container the theme wraps
 * content in. There is no opt-out filter; the host theme's header +
 * footer always render on BN-mapped slugs.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

$bn_state = \BuddyNext\Core\PageRouter::get_render_state();

if ( null === $bn_state ) {
	return;
}

// Delegate to the canonical render method so the template and unit tests
// share one implementation.
\BuddyNext\Core\PageRouter::render_shell_with_theme_chrome( $bn_state );
