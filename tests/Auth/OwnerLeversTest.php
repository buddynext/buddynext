<?php
/**
 * Tests for the owner levers: verification enforcement + required 2FA.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Auth\VerificationListener;
use BuddyNext\Core\Installer;

/**
 * A lever that is read and then ignored is worse than no lever at all — the owner
 * believes they configured something they did not. These two both were.
 *
 * @covers \BuddyNext\Auth\VerificationListener
 * @covers \BuddyNext\Auth\TwoFactorService
 */
class OwnerLeversTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema per case.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		wp_set_current_user( 0 );
		delete_option( 'buddynext_2fa_required' );
		delete_option( 'buddynext_verify_enforcement' );
		delete_option( 'buddynext_email_verify' );
	}

	/**
	 * Verification off means off.
	 */
	public function test_enforcement_is_off_when_verification_is_not_required(): void {
		$this->assertSame( 'off', VerificationListener::enforcement() );
	}

	/**
	 * The default is the middle level — which is what the code has always actually
	 * done, while the setting text promised a full access gate.
	 */
	public function test_enforcement_defaults_to_restricted(): void {
		update_option( 'buddynext_email_verify', '1' );

		$this->assertSame( 'restricted', VerificationListener::enforcement() );
	}

	/**
	 * And the owner can choose the strict posture the old copy promised.
	 */
	public function test_owner_can_choose_the_full_gate(): void {
		update_option( 'buddynext_email_verify', '1' );
		update_option( 'buddynext_verify_enforcement', 'full' );

		$this->assertSame( 'full', VerificationListener::enforcement() );
	}

	/**
	 * A junk value degrades to the safe middle rather than to "no enforcement".
	 */
	public function test_an_unknown_enforcement_level_degrades_to_restricted(): void {
		update_option( 'buddynext_email_verify', '1' );
		update_option( 'buddynext_verify_enforcement', 'nonsense' );

		$this->assertSame( 'restricted', VerificationListener::enforcement() );
	}

	/**
	 * 2FA stays opt-in by default. Forcing an authenticator app on every owner
	 * during an update would be a lockout event across the fleet.
	 */
	public function test_2fa_is_required_of_nobody_by_default(): void {
		$user = new \WP_User( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( array(), TwoFactorService::required_roles() );
		$this->assertFalse( TwoFactorService::is_required_for( $user ) );
	}

	/**
	 * THE DEAD LEVER: the owner sets "require 2FA for administrators" and it must
	 * now actually mean something. It used to be read, handed to a REST payload as
	 * a display hint, and enforced precisely nowhere.
	 */
	public function test_requiring_2fa_for_admins_binds_on_admins_only(): void {
		update_option( 'buddynext_2fa_required', 'admins' );

		$admin  = new \WP_User( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$member = new \WP_User( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( TwoFactorService::is_required_for( $admin ) );
		$this->assertFalse( TwoFactorService::is_required_for( $member ) );
	}

	/**
	 * "Everyone" means everyone, whatever their role.
	 */
	public function test_requiring_2fa_for_everyone_binds_on_a_plain_member(): void {
		update_option( 'buddynext_2fa_required', 'all' );

		$member = new \WP_User( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertTrue( TwoFactorService::is_required_for( $member ) );
	}

	/**
	 * The developer filter still has the last word over the admin setting.
	 */
	public function test_the_filter_still_overrides_the_setting(): void {
		update_option( 'buddynext_2fa_required', 'none' );

		add_filter( 'buddynext_2fa_required_roles', static fn(): array => array( 'subscriber' ) );

		$member = new \WP_User( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertTrue( TwoFactorService::is_required_for( $member ) );

		remove_all_filters( 'buddynext_2fa_required_roles' );
	}
}
