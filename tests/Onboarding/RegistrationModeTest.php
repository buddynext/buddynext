<?php
/**
 * One list of registration modes; the wizard's old 'approve' is honoured.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use WP_UnitTestCase;

/**
 * Card 10374880325: the setup wizard saved "Admin approval" as 'approve',
 * which nothing reads, so those sites held nobody.
 */
class RegistrationModeTest extends WP_UnitTestCase {

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_reg_mode' );
		unset( $_POST['reg_mode'] );
		parent::tear_down();
	}

	/**
	 * A stored 'approve' reads as 'approval' everywhere.
	 *
	 * @return void
	 */
	public function test_legacy_approve_reads_as_approval(): void {
		update_option( 'buddynext_reg_mode', 'approve' );
		$this->assertSame( 'approval', get_option( 'buddynext_reg_mode' ) );
		$this->assertContains( 'approval', buddynext_reg_modes() );
		$this->assertNotContains( 'approve', buddynext_reg_modes() );
	}

	/**
	 * The wizard saves only a listed mode.
	 *
	 * @return void
	 */
	public function test_wizard_saves_only_listed_modes(): void {
		$method = new \ReflectionMethod( \BuddyNext\Onboarding\SetupWizard::class, 'reg_mode_from_post' );
		$method->setAccessible( true );

		$_POST['reg_mode'] = 'approval';
		$this->assertSame( 'approval', $method->invoke( null ) );
		$_POST['reg_mode'] = 'closed';
		$this->assertSame( 'closed', $method->invoke( null ) );
		$_POST['reg_mode'] = 'approve';
		$this->assertSame( 'open', $method->invoke( null ), 'Not a mode the site reads.' );
	}
}
