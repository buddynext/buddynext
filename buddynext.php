<?php
/**
 * Plugin Name: BuddyNext
 * Plugin URI:  https://buddynext.com/
 * Description: The social layer for WordPress.
 * Version:     1.2.7
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: buddynext
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Network: true
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether this request belongs to the site that runs the community.
 *
 * A single site always does. On a network only the main site does: one community
 * lives there, and every other site is left exactly as it was. Themes and partner
 * plugins can call this safely on any site; to detect BuddyNext itself use
 * defined( 'BUDDYNEXT_VERSION' ) or class_exists().
 *
 * Kept in this file, with nothing else, on purpose: PHP defines top-level
 * functions while compiling a file, so an early return further down would still
 * leave them defined on a subsite. Everything else lives in bootstrap.php.
 *
 * @return bool
 */
function buddynext_is_community_site(): bool {
	return ! is_multisite() || is_main_site();
}

if ( buddynext_is_community_site() ) {
	require __DIR__ . '/bootstrap.php';
}
