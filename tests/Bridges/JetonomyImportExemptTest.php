<?php
/**
 * A forum import run in this request is exempt from the post rate limits, and nothing else is.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;

/**
 * Import exemption.
 *
 * @covers \BuddyNext\Bridges\JetonomyBridge::exempt_import_from_rate_limit
 */
class JetonomyImportExemptTest extends \WP_UnitTestCase {

	/**
	 * Without an import running, an author is exempt only if something else already exempted them.
	 *
	 * @return void
	 */
	public function test_not_exempt_unless_an_import_runs_or_already_exempt(): void {
		$bridge = new JetonomyBridge();

		$this->assertFalse( $bridge->exempt_import_from_rate_limit( false ) );
		$this->assertTrue( $bridge->exempt_import_from_rate_limit( true ), 'An existing exemption is kept.' );
	}

	/**
	 * A request cannot turn the exemption on.
	 *
	 * @return void
	 */
	public function test_request_values_do_not_exempt(): void {
		$_GET['bulk']                    = '1';
		$_SERVER['HTTP_X_BULK_IMPORT']   = '1';
		$bridge                          = new JetonomyBridge();

		$this->assertFalse( $bridge->exempt_import_from_rate_limit( false ) );

		unset( $_GET['bulk'], $_SERVER['HTTP_X_BULK_IMPORT'] );
	}
}
