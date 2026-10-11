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
