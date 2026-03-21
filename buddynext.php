<?php
/**
 * Plugin Name: BuddyNext
 * Plugin URI:  https://wbcomdesigns.com/buddynext
 * Description: The social layer for WordPress.
 * Version:     0.1.0
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com
 * Text Domain: buddynext
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

define( 'BUDDYNEXT_VERSION', '0.1.0' );
define( 'BUDDYNEXT_FILE',    __FILE__ );
define( 'BUDDYNEXT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BUDDYNEXT_URL',     plugin_dir_url( __FILE__ ) );
define( 'BUDDYNEXT_BASENAME', plugin_basename( __FILE__ ) );

require_once BUDDYNEXT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \BuddyNext\Core\Installer::class, 'run' ) );

add_action( 'plugins_loaded', array( \BuddyNext\Core\Plugin::class, 'init' ), 15 );

/**
 * Check whether a user holds a BuddyNext capability.
 *
 * Acts as the single entry point for every permission check in the plugin.
 * Prefer this over direct capability checks so that all gates flow through
 * the 4-layer model in PermissionService.
 *
 * @param int    $user_id    WordPress user ID.
 * @param string $capability Dot-namespaced capability, e.g. 'buddynext-feed/create-post'.
 * @param array  $context    Optional key/value context, e.g. ['space_id' => 42].
 * @return bool
 */
function buddynext_can( int $user_id, string $capability, array $context = array() ): bool {
	return \BuddyNext\Core\Container::instance()
		->get( 'permissions' )
		->can( $user_id, $capability, $context );
}

/**
 * Retrieve a service from the BuddyNext container.
 *
 * @param string $key Service identifier as registered in Plugin::register_services().
 * @return mixed
 */
function buddynext_service( string $key ): mixed {
	return \BuddyNext\Core\Container::instance()->get( $key );
}
