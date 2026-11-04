<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext and BuddyNext Pro update packages are checked for the release
 * signature (signed-updates checklist step 6). Free registers both, because
 * WordPress loads buddynext-pro/ before buddynext/.
 *
 * @package BuddyNext\Tests\Core
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use WP_UnitTestCase;

/**
 * @coversNothing
 */
class PackageSignaturesRegistrationTest extends WP_UnitTestCase {

	public function test_both_products_are_registered_in_warn_mode_on_both_hosts(): void {
		$this->assertTrue( class_exists( 'Package_Signatures_Guard' ), 'libs/package-signatures did not load.' );

		$products = \Package_Signatures_Guard::products();

		// Free's key comes from plugin_basename(), which is the checkout path when the
		// tests run outside wp-content/plugins; Pro's is the fixed basename.
		$keys = array(
			'buddynext'     => 'plugin:' . plugin_basename( BUDDYNEXT_FILE ),
			'buddynext-pro' => 'plugin:buddynext-pro/buddynext-pro.php',
		);

		foreach ( $keys as $slug => $key ) {
			$this->assertArrayHasKey( $key, $products, "{$slug} is not registered for signature checks." );
			$this->assertSame( $slug, $products[ $key ]['product'] );
			$this->assertSame( 'warn', $products[ $key ]['mode'] );
			$this->assertSame( array( 'wbcomdesigns.com', 'updates.wbcomdesigns.com' ), $products[ $key ]['hosts'] );
			$this->assertArrayHasKey( 'wbcom-2026-1', $products[ $key ]['keys'] );
		}
	}
}
