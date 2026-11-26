<?php
/**
 * Nobody suspends the site owner's account; admins can still suspend other admins.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

/**
 * ModerationService::suspend_refusal() site-owner guard.
 *
 * @covers \BuddyNext\Moderation\ModerationService::suspend_refusal
 */
class SiteOwnerSuspendTest extends \WP_UnitTestCase {

	/**
	 * Another admin cannot suspend the admin_email account, but can suspend a peer admin.
	 *
	 * @return void
	 */
	public function test_the_site_owner_cannot_be_suspended_but_a_peer_admin_can(): void {
		$owner = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner-guard@example.org',
			)
		);
		update_option( 'admin_email', 'owner-guard@example.org' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$peer  = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$refused = buddynext_service( 'moderation' )->suspend_user( $owner, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) );
		$this->assertWPError( $refused, 'An admin suspended the site owner.' );
		$this->assertSame( 'site_owner', $refused->get_error_data()['reason'] ?? '' );
		$this->assertFalse( buddynext_service( 'moderation' )->is_suspended( $owner ) );

		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $peer, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ), 'An admin could not suspend a peer admin.' );
	}
}
