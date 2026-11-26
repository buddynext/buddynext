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

	/**
	 * Strikes and the shared suspend() refuse the site owner too: a peer admin striking the owner to the
	 * threshold, the permanent-ban tier and an automated rule (actor 0) all leave the owner unsuspended.
	 *
	 * @return void
	 */
	public function test_strikes_and_automated_suspension_cannot_reach_the_site_owner(): void {
		$owner = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner-strikes@example.org',
			)
		);
		update_option( 'admin_email', 'owner-strikes@example.org' );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$peer  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mod   = buddynext_service( 'moderation' );

		for ( $i = 0; $i < 6; $i++ ) {
			$strike = $mod->issue_strike( $owner, $admin, 'Repeated spam links' );
			$this->assertWPError( $strike, 'A strike against the site owner was accepted.' );
			$this->assertSame( 'site_owner', $strike->get_error_data()['reason'] ?? '' );
		}

		// The permanent-ban tier and the suspend tier both end in suspend(), with a person or the system as actor.
		$this->assertWPError( $mod->suspend( $owner, 'Automatic permanent ban: strike threshold reached.', 0, true, $admin ) );
		$this->assertWPError( $mod->suspend( $owner, 'Automatic suspension by a rule.', 0, false, 0 ) );
		$this->assertFalse( $mod->is_suspended( $owner ), 'The site owner ended up suspended.' );

		// A peer admin is still reachable by the same path.
		$this->assertTrue( true === $mod->suspend( $peer, 'Automatic suspension: strike threshold reached.', 0, false, $admin ) );
	}

	/**
	 * An automated rule (actor 0, the way Pro moderation rules call suspend_user) cannot suspend the site owner,
	 * and nobody can strike themselves.
	 *
	 * @return void
	 */
	public function test_an_automated_rule_cannot_suspend_the_owner_and_nobody_strikes_themselves(): void {
		$owner = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_email' => 'owner-rule@example.org',
			)
		);
		update_option( 'admin_email', 'owner-rule@example.org' );
		$member = self::factory()->user->create();
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mod    = buddynext_service( 'moderation' );

		$rule = $mod->suspend_user( $owner, 0, 'Automatic suspension: 2 reports in 24 hours.', array( 'duration_days' => 3 ) );
		$this->assertWPError( $rule, 'An automated rule suspended the site owner.' );
		$this->assertSame( 'site_owner', $rule->get_error_data()['reason'] ?? '' );
		$this->assertFalse( $mod->is_suspended( $owner ) );

		// The same rule still reaches an ordinary member.
		$this->assertIsInt( $mod->suspend_user( $member, 0, 'Automatic suspension: 2 reports in 24 hours.', array( 'duration_days' => 3 ) ) );

		$self = $mod->issue_strike( $admin, $admin, 'Testing myself' );
		$this->assertWPError( $self, 'An admin could strike themselves.' );
		$this->assertSame( 'self', $self->get_error_data()['reason'] ?? '' );
	}
}
