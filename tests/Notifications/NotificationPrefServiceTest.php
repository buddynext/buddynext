<?php
/**
 * Tests for NotificationPrefService.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefService;

/**
 * @covers \BuddyNext\Notifications\NotificationPrefService
 */
class NotificationPrefServiceTest extends \WP_UnitTestCase {

	private NotificationPrefService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new NotificationPrefService();
		$this->user_id = self::factory()->user->create();
	}

	public function test_get_pref_returns_defaults_when_not_set(): void {
		$pref = $this->service->get_pref( $this->user_id, 'bn.new_follower' );

		$this->assertArrayHasKey( 'on_site', $pref );
		$this->assertArrayHasKey( 'email_freq', $pref );
		$this->assertTrue( $pref['on_site'] );
		$this->assertSame( 'immediate', $pref['email_freq'] );
	}

	public function test_set_and_get_pref(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.new_follower',
			array(
				'on_site'    => false,
				'email_freq' => 'daily',
			)
		);

		$pref = $this->service->get_pref( $this->user_id, 'bn.new_follower' );

		$this->assertFalse( $pref['on_site'] );
		$this->assertSame( 'daily', $pref['email_freq'] );
	}

	/**
	 * A partial update must not corrupt the key it was not given.
	 *
	 * Turning one type off in-app without touching its email cadence is the
	 * natural call, and it used to emit "Undefined array key email_freq" and
	 * store '' - which is not a valid ENUM member, so the row read back as an
	 * empty string and the digest cron matched neither 'daily' nor 'weekly'.
	 */
	public function test_set_pref_with_only_on_site_defaults_email_freq(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.new_follower',
			array( 'on_site' => false )
		);

		$pref = $this->service->get_pref( $this->user_id, 'bn.new_follower' );

		$this->assertFalse( $pref['on_site'] );
		$this->assertSame( 'immediate', $pref['email_freq'] );
	}

	/**
	 * The mirror case, which was already correct - guard against regressing it
	 * while fixing the one above.
	 */
	public function test_set_pref_with_only_email_freq_defaults_on_site(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.post_liked',
			array( 'email_freq' => 'weekly' )
		);

		$pref = $this->service->get_pref( $this->user_id, 'bn.post_liked' );

		$this->assertTrue( $pref['on_site'] );
		$this->assertSame( 'weekly', $pref['email_freq'] );
	}

	public function test_set_pref_rejects_an_invalid_email_freq(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.mention',
			array( 'email_freq' => 'hourly' )
		);

		$this->assertSame( 'immediate', $this->service->get_pref( $this->user_id, 'bn.mention' )['email_freq'] );
	}

	public function test_set_pref_updates_existing(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.post_liked',
			array(
				'on_site'    => true,
				'email_freq' => 'weekly',
			)
		);

		$this->service->set_pref(
			$this->user_id,
			'bn.post_liked',
			array(
				'on_site'    => false,
				'email_freq' => 'off',
			)
		);

		$pref = $this->service->get_pref( $this->user_id, 'bn.post_liked' );

		$this->assertFalse( $pref['on_site'] );
		$this->assertSame( 'off', $pref['email_freq'] );
	}

	public function test_different_types_are_independent(): void {
		$this->service->set_pref(
			$this->user_id,
			'bn.new_follower',
			array(
				'on_site'    => false,
				'email_freq' => 'off',
			)
		);

		$pref_other = $this->service->get_pref( $this->user_id, 'bn.post_liked' );

		$this->assertTrue( $pref_other['on_site'] );
	}

	/**
	 * The digest email's one-click unsubscribe writes (user, 'digest', off). 'digest'
	 * is an INTERNAL suppression pseudo-type, not a member catalogue key, so the
	 * set_pref() seam guard must let it through (else the unsubscribe is a silent
	 * no-op and the member keeps receiving digests — card 10264293350). It must NOT
	 * surface in the prefs UI.
	 *
	 * @return void
	 */
	public function test_digest_unsubscribe_pseudo_type_persists_but_is_hidden_from_ui(): void {
		global $wpdb;
		$this->service->set_pref( $this->user_id, 'digest', array( 'email_freq' => 'off' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$freq = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT email_freq FROM {$wpdb->prefix}bn_notification_prefs WHERE user_id = %d AND type = 'digest'",
				$this->user_id
			)
		);
		$this->assertSame( 'off', $freq, 'The digest unsubscribe row must persist so the digest cron suppresses on it.' );
		$this->assertArrayNotHasKey( 'digest', $this->service->get_all_prefs( $this->user_id ), 'digest is internal and must not appear in the prefs UI.' );
	}

	/**
	 * A type that is neither a catalogue entry nor an internal pseudo-type must
	 * still be rejected — the junk-row hole the guard closed stays closed.
	 *
	 * @return void
	 */
	public function test_set_pref_still_rejects_a_junk_type(): void {
		global $wpdb;
		$this->service->set_pref( $this->user_id, 'totally_made_up', array( 'email_freq' => 'off' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notification_prefs WHERE user_id = %d AND type = 'totally_made_up'",
				$this->user_id
			)
		);
		$this->assertSame( 0, $count, 'A non-catalogue, non-internal type must never reach the table.' );
	}
}
