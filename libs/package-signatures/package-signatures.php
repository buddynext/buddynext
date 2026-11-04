<?php
/**
 * Package Signatures: checks the signature of plugin and theme updates from your own store before WordPress installs
 * them. Bundle this folder in each plugin or theme and call package_signatures_register(); when several products
 * bundle it, the newest copy runs and only that copy loads its classes.
 *
 * Version: 1.0.1
 * License: GPL-2.0-or-later
 *
 * @package PackageSignatures
 */

defined( 'ABSPATH' ) || exit;

// Every copy adds itself; the newest is chosen once all plugins and the theme have loaded.
$GLOBALS['package_signatures_copies']['1.0.1'] = __DIR__;

if ( ! function_exists( 'package_signatures_register' ) ) {
	/**
	 * Registers a product. Keep this function's contract stable: older bundled copies may define it.
	 *
	 * @param array $args {
	 *     @type string $product  Product slug, as written in the signed manifest.
	 *     @type string $plugin   Plugin basename (folder/file.php); or $theme: theme stylesheet (folder).
	 *     @type array  $hosts    Hosts the product's update packages come from.
	 *     @type array  $keys     key_id => base64 Ed25519 public key (current and next, for rotation).
	 *     @type string $mode     'warn' (default: log and install) or 'enforce' (refuse unsigned or invalid).
	 *     @type string $fallback URL with {product} and {version}, used when the download carries no signature header.
	 * }
	 */
	function package_signatures_register( array $args ) {
		$GLOBALS['package_signatures_products'][] = $args;
	}
}

if ( ! function_exists( 'package_signatures_boot' ) ) {
	/**
	 * Loads and boots the newest bundled copy. Runs once.
	 */
	function package_signatures_boot() {
		if ( class_exists( 'Package_Signatures_Guard', false ) ) {
			return;
		}
		$copies = (array) ( $GLOBALS['package_signatures_copies'] ?? array() );
		uksort( $copies, 'version_compare' );
		// Newest complete copy wins; an incomplete one (half-extracted update) is skipped, never a fatal error.
		foreach ( array_reverse( $copies ) as $dir ) {
			if ( is_readable( $dir . '/src/class-verifier.php' ) && is_readable( $dir . '/src/class-guard.php' ) ) {
				require_once $dir . '/src/class-verifier.php';
				require_once $dir . '/src/class-guard.php';
				Package_Signatures_Guard::boot();
				return;
			}
		}
	}
	add_action( 'after_setup_theme', 'package_signatures_boot', 1 );
}
