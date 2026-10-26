<?php
/**
 * The test suite never reaches the live store.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use WP_UnitTestCase;

/**
 * Tests/bootstrap.php refuses both licence hosts. Without that guard, CI jobs run the preset activation as
 * http://example.org and every push sends activations to the live store.
 *
 * @coversNothing
 */
class NoLiveStoreTest extends WP_UnitTestCase {

	public function test_store_hosts_are_refused(): void {
		foreach ( array( 'https://wbcomdesigns.com/', 'https://updates.wbcomdesigns.com/' ) as $url ) {
			$response = wp_remote_get( $url );
			$this->assertWPError( $response, $url );
			$this->assertSame( 'buddynext_tests_no_store', $response->get_error_code(), $url );
		}
	}

	public function test_licence_request_gets_no_reply(): void {
		$this->assertSame( 'https://updates.wbcomdesigns.com', BUDDYNEXT_LICENCE_HOST );
		$this->assertFalse(
			buddynext_licence_request(
				array(
					'edd_action' => 'check_license',
					'item_id'    => 1664401,
				)
			)
		);
	}
}
