<?php
/**
 * Who gets an email digest, and what goes in it.
 *
 * A notification type whose frequency is Daily or Weekly is held back from the
 * instant email and belongs in that digest. That has to hold for a member who
 * never opened their preferences (the catalogue default applies) and has to
 * respect each type's own setting: Off never appears, Immediate was already
 * emailed.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\CronService;
use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefService;

/**
 * Digest enrolment tests.
 *
 * @covers \BuddyNext\Core\CronService
 */
class DigestEnrolmentTest extends \WP_UnitTestCase {

	/**
	 * Emails captured instead of sent: recipient => body.
	 *
	 * @var array<string, string>
	 */
	private array $sent = array();

	/**
	 * The member receiving notifications.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_digest_frequency', 'weekly' );
		$this->member = self::factory()->user->create( array( 'user_email' => 'digest-member@example.test' ) );
		$this->sent   = array();
		add_filter( 'pre_wp_mail', array( $this, 'capture' ), 10, 2 );
	}

	/**
	 * Test teardown.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'pre_wp_mail', array( $this, 'capture' ), 10 );
		parent::tear_down();
	}

	/**
	 * Record an outgoing email and report it as sent.
	 *
	 * @param mixed               $short_circuit Filter value.
	 * @param array<string,mixed> $atts          wp_mail arguments.
	 * @return bool
	 */
	public function capture( $short_circuit, array $atts ): bool {
		$to                = is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : (string) $atts['to'];
		$this->sent[ $to ] = (string) $atts['message'];

		return true;
	}

	/**
	 * Add an unread notification for the member.
	 *
	 * @param string $type Notification type.
	 * @return void
	 */
	private function notify( string $type ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => $this->member,
				'type'         => $type,
				'is_read'      => 0,
			)
		);
	}

	/**
	 * Whether the member was emailed.
	 *
	 * @return bool
	 */
	private function emailed(): bool {
		return isset( $this->sent['digest-member@example.test'] );
	}

	/**
	 * A member who never saved a preference gets the daily digest for a default-Daily type.
	 *
	 * @return void
	 */
	public function test_member_on_defaults_gets_the_daily_digest(): void {
		$this->notify( 'bn.post_shared' );

		( new CronService() )->handle_daily_digest();

		$this->assertTrue( $this->emailed() );
	}

	/**
	 * A default-Weekly type is left to the weekly digest.
	 *
	 * @return void
	 */
	public function test_default_weekly_type_waits_for_the_weekly_digest(): void {
		$this->notify( 'bn.space_join' );

		( new CronService() )->handle_daily_digest();
		$this->assertFalse( $this->emailed(), 'Not in the daily digest.' );

		( new CronService() )->handle_weekly_digest();
		$this->assertTrue( $this->emailed(), 'In the weekly digest.' );
	}

	/**
	 * A type switched Off never reaches a digest.
	 *
	 * @return void
	 */
	public function test_type_set_to_off_is_left_out(): void {
		( new NotificationPrefService() )->set_pref( $this->member, 'bn.post_shared', array( 'email_freq' => 'off' ) );
		$this->notify( 'bn.post_shared' );

		( new CronService() )->handle_daily_digest();

		$this->assertFalse( $this->emailed() );
	}

	/**
	 * A type set to Immediate was emailed at once and is not repeated.
	 *
	 * @return void
	 */
	public function test_type_set_to_immediate_is_not_repeated(): void {
		( new NotificationPrefService() )->set_pref( $this->member, 'bn.post_shared', array( 'email_freq' => 'immediate' ) );
		$this->notify( 'bn.post_shared' );

		( new CronService() )->handle_daily_digest();

		$this->assertFalse( $this->emailed() );
	}

	/**
	 * A type that defaults to Immediate does not ride along in the digest.
	 *
	 * @return void
	 */
	public function test_default_immediate_type_is_not_in_the_digest(): void {
		$this->notify( 'bn.new_follower' );

		( new CronService() )->handle_daily_digest();

		$this->assertFalse( $this->emailed() );
	}

	/**
	 * Unsubscribing from digests stops them, defaults or not.
	 *
	 * @return void
	 */
	public function test_digest_switched_off_sends_nothing(): void {
		( new NotificationPrefService() )->set_pref( $this->member, 'digest', array( 'email_freq' => 'off' ) );
		$this->notify( 'bn.post_shared' );

		( new CronService() )->handle_daily_digest();

		$this->assertFalse( $this->emailed() );
	}

	/**
	 * A member who chose Daily for a type still gets it.
	 *
	 * @return void
	 */
	public function test_type_set_to_daily_is_included(): void {
		( new NotificationPrefService() )->set_pref( $this->member, 'bn.new_follower', array( 'email_freq' => 'daily' ) );
		$this->notify( 'bn.new_follower' );

		( new CronService() )->handle_daily_digest();

		$this->assertTrue( $this->emailed() );
	}
}
