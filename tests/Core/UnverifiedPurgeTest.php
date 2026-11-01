<?php
/**
 * The cleanup of never-verified accounts: who is deleted, and who is kept.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;

/**
 * Never-verified account purge tests.
 *
 * @covers \BuddyNext\Core\CronService
 */
class UnverifiedPurgeTest extends \WP_UnitTestCase {

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_email_verify', true );
	}

	/**
	 * A member whose verification has been pending for the given number of days.
	 *
	 * @param int $days Days waiting.
	 * @return int
	 */
	private function pending_member( int $days ): int {
		$id = self::factory()->user->create();
		update_user_meta( $id, 'buddynext_verify_pending', time() - $days * DAY_IN_SECONDS );

		return $id;
	}

	/**
	 * Past the window with nothing to their name: deleted. Inside it: kept.
	 *
	 * @return void
	 */
	public function test_only_accounts_past_the_window_are_deleted(): void {
		$stale = $this->pending_member( 9 );
		$fresh = $this->pending_member( 2 );

		( new CronService() )->handle_cleanup_tokens();

		$this->assertFalse( get_userdata( $stale ) );
		$this->assertNotFalse( get_userdata( $fresh ) );
	}

	/**
	 * An account an administrator creates is verified and never swept; a sign-up is.
	 *
	 * @return void
	 */
	public function test_admin_created_account_is_verified_and_a_signup_is_not(): void {
		global $wpdb;

		wp_set_current_user( 0 );
		$signup = self::factory()->user->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$by_member = self::factory()->user->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$by_admin = self::factory()->user->create();
		wp_set_current_user( 0 );

		$this->assertSame( '', (string) get_user_meta( $by_admin, 'buddynext_verify_pending', true ) );
		$this->assertTrue( ( new \BuddyNext\Auth\VerificationService() )->is_verified( $by_admin ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_verify_tokens WHERE user_id = %d", $by_admin ) ) );

		foreach ( array( $signup, $by_member ) as $unproven ) {
			$this->assertNotSame( '', (string) get_user_meta( $unproven, 'buddynext_verify_pending', true ) );
			$this->assertFalse( ( new \BuddyNext\Auth\VerificationService() )->is_verified( $unproven ) );
			update_user_meta( $unproven, 'buddynext_verify_pending', time() - 9 * DAY_IN_SECONDS );
		}

		( new CronService() )->handle_cleanup_tokens();

		$this->assertNotFalse( get_userdata( $by_admin ) );
		$this->assertFalse( get_userdata( $signup ) );
		$this->assertFalse( get_userdata( $by_member ) );
	}

	/**
	 * A site can keep an account for its own reason (a purchase, an enrolment).
	 *
	 * @return void
	 */
	public function test_keep_filter_spares_an_account(): void {
		$buyer = $this->pending_member( 9 );
		$keep  = static fn( bool $keep, int $user_id ): bool => $keep || $user_id === $buyer;
		add_filter( 'buddynext_unverified_account_keep', $keep, 10, 2 );

		( new CronService() )->handle_cleanup_tokens();

		remove_filter( 'buddynext_unverified_account_keep', $keep, 10 );
		$this->assertNotFalse( get_userdata( $buyer ) );
		$this->assertSame( '', (string) get_user_meta( $buyer, 'buddynext_verify_pending', true ), 'A kept account leaves the scan.' );
	}

	/**
	 * The window the screens quote is the window the purge uses.
	 *
	 * @return void
	 */
	public function test_days_come_from_one_place(): void {
		$this->assertSame( 7, CronService::unverified_purge_days() );

		add_filter( 'buddynext_unverified_purge_days', '__return_zero' );
		$kept = $this->pending_member( 30 );
		( new CronService() )->handle_cleanup_tokens();
		remove_filter( 'buddynext_unverified_purge_days', '__return_zero' );

		$this->assertNotFalse( get_userdata( $kept ), 'Zero switches the purge off.' );
	}
}
