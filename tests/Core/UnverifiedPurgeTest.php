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
	 * A member who signs up on BuddyNext's own form with this data.
	 *
	 * @param string $email Address.
	 * @return int
	 */
	private function bn_signup( string $email ): int {
		delete_option( 'buddynext_terms_page_id' );
		$id = ( new \BuddyNext\Auth\RegistrationService() )->create(
			array(
				'email'        => $email,
				'password'     => wp_generate_password(),
				'terms_agreed' => true,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	/**
	 * Only BuddyNext's own sign-up is swept. Any other way in leaves the account alone.
	 *
	 * @return void
	 */
	public function test_only_buddynext_signups_are_swept(): void {
		global $wpdb;

		wp_set_current_user( 0 );
		$own_form = $this->bn_signup( 'own-form@example.test' );
		// Another plugin's public form: wp_insert_user() with nobody signed in.
		$other_plugin = self::factory()->user->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$by_member = self::factory()->user->create();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$by_admin = self::factory()->user->create();
		wp_set_current_user( 0 );

		$svc = new \BuddyNext\Auth\VerificationService();

		$this->assertNotSame( '', (string) get_user_meta( $own_form, 'buddynext_verify_pending', true ) );
		$this->assertFalse( $svc->is_verified( $own_form ) );

		$this->assertSame( '', (string) get_user_meta( $by_admin, 'buddynext_verify_pending', true ) );
		$this->assertTrue( $svc->is_verified( $by_admin ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_verify_tokens WHERE user_id = %d", $by_admin ) ) );

		foreach ( array( $other_plugin, $by_member ) as $foreign ) {
			$this->assertSame( '', (string) get_user_meta( $foreign, 'buddynext_verify_pending', true ), 'No marker, so never a candidate.' );
			// Verification itself still applies to them, and a resend adds no marker.
			$this->assertFalse( $svc->is_verified( $foreign ) );
			$svc->create_token( $foreign );
			$this->assertSame( '', (string) get_user_meta( $foreign, 'buddynext_verify_pending', true ) );
		}

		update_user_meta( $own_form, 'buddynext_verify_pending', time() - 9 * DAY_IN_SECONDS );
		( new CronService() )->handle_cleanup_tokens();
		wp_cache_flush();

		$this->assertFalse( get_userdata( $own_form ), 'The negative case: the cleanup still works.' );
		$this->assertNotFalse( get_userdata( $other_plugin ) );
		$this->assertNotFalse( get_userdata( $by_member ) );
		$this->assertNotFalse( get_userdata( $by_admin ) );
	}

	/**
	 * With verification off at sign-up, the account is never a candidate.
	 *
	 * @return void
	 */
	public function test_signup_with_verification_off_gets_no_marker(): void {
		update_option( 'buddynext_email_verify', false );
		$id = $this->bn_signup( 'off@example.test' );
		$this->assertSame( '', (string) get_user_meta( $id, 'buddynext_verify_pending', true ) );
	}

	/**
	 * Upgrading clears old markers, and nothing is deleted until that has happened.
	 *
	 * @return void
	 */
	public function test_upgrade_forgets_old_markers_and_cleanup_waits_for_it(): void {
		$old = $this->pending_member( 30 );

		update_option( 'buddynext_pending_migrations', 73 );
		( new CronService() )->handle_cleanup_tokens();
		wp_cache_flush();
		$this->assertNotFalse( get_userdata( $old ), 'The cleanup must not run ahead of the migration.' );

		delete_option( 'buddynext_pending_migrations' );
		$method = new \ReflectionMethod( Installer::class, 'maybe_migrate_purge_markers' );

		$method->invoke( null, 74 );
		wp_cache_flush();
		$this->assertNotSame( '', (string) get_user_meta( $old, 'buddynext_verify_pending', true ), 'A site already upgraded keeps its markers.' );

		$method->invoke( null, 73 );
		wp_cache_flush();
		$this->assertSame( '', (string) get_user_meta( $old, 'buddynext_verify_pending', true ) );

		( new CronService() )->handle_cleanup_tokens();
		wp_cache_flush();
		$this->assertNotFalse( get_userdata( $old ) );
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
