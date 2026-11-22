<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Public methods that shipped in 1.2.9 and were removed in 1.3.0 still answer,
 * with a deprecation notice.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use WP_UnitTestCase;

class DeprecatedShimsTest extends WP_UnitTestCase {

	public function test_auth_client_ip_delegates_to_the_safeguard_reader(): void {
		$this->setExpectedDeprecated( 'BuddyNext\Auth\AuthController::client_ip' );
		$this->assertSame( \BuddyNext\Moderation\SafeguardService::client_ip(), \BuddyNext\Auth\AuthController::client_ip() );
	}

	public function test_removed_methods_still_exist(): void {
		$this->assertTrue( method_exists( \BuddyNext\Admin\Members::class, 'export_members_csv' ) );
		$this->assertTrue( method_exists( \BuddyNext\Bridges\JetonomyBridge::class, 'rest_provision_permission' ) );
	}
}
