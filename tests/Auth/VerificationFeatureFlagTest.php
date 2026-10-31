<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The Email Verification feature switches off every verification surface.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\VerificationListener;
use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The "Require email verification" setting stays stored when the feature goes off. Readers
 * that asked only the setting told new members to check an inbox nothing was mailed to,
 * withheld the welcome email and blocked them from posting.
 *
 * @covers \BuddyNext\Auth\VerificationService::is_required
 */
class VerificationFeatureFlagTest extends WP_UnitTestCase {

	/**
	 * Setting on, enforcement restricted, enabled in the past so a new account is not grandfathered.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		update_option( 'buddynext_email_verify', 1 );
		update_option( 'buddynext_email_verify_enabled_at', time() - DAY_IN_SECONDS, false );
	}

	/**
	 * Clean options.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_email_verify' );
		delete_option( 'buddynext_email_verify_enabled_at' );
		remove_all_filters( 'buddynext_feature_verification' );
		parent::tear_down();
	}

	/**
	 * Turn the feature off.
	 *
	 * @return void
	 */
	private function feature_off(): void {
		add_filter( 'buddynext_feature_verification', '__return_false' );
	}

	/**
	 * Verification tokens issued to a member.
	 *
	 * @param int $user Member.
	 * @return int
	 */
	private function token_count( int $user ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_verify_tokens WHERE user_id = %d", $user ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Signing up with the feature off goes straight on.
	 *
	 * @return void
	 */
	public function test_feature_off_stands_down_every_surface(): void {
		$this->feature_off();

		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( buddynext_service( 'verification' )->is_verified( $user ) );
		$this->assertSame( 'off', VerificationListener::enforcement() );
		$this->assertStringNotContainsString( '/verify/', AuthController::post_register_redirect( $user ) );
		$this->assertNotSame( '', (string) get_user_meta( $user, 'bn_welcome_sent', true ), 'The welcome email goes out.' );
		$this->assertSame( 0, $this->token_count( $user ), 'No token is issued.' );

		wp_set_current_user( $user );
		rest_get_server();
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' ) )->get_status() );

		$post = buddynext_service( 'post_service' )->create(
			$user,
			array(
				'content'    => 'Posted while verification is off',
				'visibility' => 'public',
			)
		);
		$this->assertNotWPError( $post );
		$this->assertFalse( VerificationService::is_required() );
	}

	/**
	 * The feature on with the setting off is the same answer, by the same method.
	 *
	 * @return void
	 */
	public function test_setting_off_is_not_required_either(): void {
		update_option( 'buddynext_email_verify', 0 );
		$this->assertFalse( VerificationService::is_required() );
	}

	/**
	 * Negative: feature and setting on, everything still enforces.
	 *
	 * @return void
	 */
	public function test_feature_on_still_enforces(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( VerificationService::is_required() );
		$this->assertSame( 1, $this->token_count( $user ) );
		$this->assertFalse( buddynext_service( 'verification' )->is_verified( $user ) );
		$this->assertStringContainsString( '/verify/', AuthController::post_register_redirect( $user ) );
		$this->assertSame( '', (string) get_user_meta( $user, 'bn_welcome_sent', true ), 'The welcome email waits for the click.' );

		wp_set_current_user( $user );
		rest_get_server();
		$this->assertNotSame( 404, rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/auth/verify/resend' ) )->get_status() );

		$this->assertWPError(
			buddynext_service( 'post_service' )->create(
				$user,
				array(
					'content'    => 'Blocked until verified',
					'visibility' => 'public',
				)
			)
		);
	}

	/**
	 * The cleanup job must not delete accounts that were never asked to verify.
	 *
	 * @return void
	 */
	public function test_cleanup_job_does_not_purge_while_the_feature_is_off(): void {
		$this->feature_off();
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $user, 'buddynext_verify_pending', time() - YEAR_IN_SECONDS );

		( new \ReflectionMethod( CronService::class, 'purge_stale_unverified' ) )->invoke( new CronService() );

		wp_cache_flush();
		$this->assertInstanceOf( \WP_User::class, get_userdata( $user ) );

		// Negative: with the feature on, the same stale account is purged.
		remove_all_filters( 'buddynext_feature_verification' );
		( new \ReflectionMethod( CronService::class, 'purge_stale_unverified' ) )->invoke( new CronService() );
		wp_cache_flush();
		$this->assertFalse( get_userdata( $user ) );
	}
}
