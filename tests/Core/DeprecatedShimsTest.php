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

	public function test_members_csv_returns_the_real_escaped_export(): void {
		$id = self::factory()->user->create( array( 'display_name' => '=HYPERLINK("x")' ) );
		$this->setExpectedDeprecated( 'BuddyNext\Admin\Members::export_members_csv' );
		$csv = ( new \BuddyNext\Admin\Members() )->export_members_csv();
		$this->assertStringStartsWith( 'ID,Login,Email', $csv, 'Not an empty string: the header row comes first.' );
		$this->assertStringContainsString( (string) $id . ',', $csv, 'Members are listed.' );
		$this->assertStringContainsString( "'=HYPERLINK", $csv, 'Member-controlled cells are defused.' );
	}

	public function test_removed_methods_still_exist(): void {
		$this->assertTrue( method_exists( \BuddyNext\Admin\Members::class, 'export_members_csv' ) );
		$this->assertTrue( method_exists( \BuddyNext\Bridges\JetonomyBridge::class, 'rest_provision_permission' ) );
	}
}
