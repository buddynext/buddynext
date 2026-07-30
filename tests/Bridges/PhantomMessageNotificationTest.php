<?php
/**
 * A notification must mean a message exists.
 *
 * WPMediaVerse fired `mvs_message_sent` with message_id 0 when a conversation
 * was CREATED without an initial send — opening a compose window and picking a
 * recipient was enough — and the bridge took it at its word, so the recipient
 * was told "X sent you a message" with an empty thread behind it. Reproduced on
 * 1.1.1 before the fix: conversation created, 0 rows in mvs_messages, one
 * bn.new_message row carrying data {"message_id": 0}.
 *
 * The root-cause fire is gone from WPMediaVerse, but this guard is BuddyNext's
 * own: the bridge contract says BN validates what a partner hands it rather
 * than trusting the payload, and any other messaging engine wired to this hook
 * inherits the same protection.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge::on_message_sent
 */
class PhantomMessageNotificationTest extends \WP_UnitTestCase {

	/**
	 * Sender.
	 *
	 * @var int
	 */
	private $sender = 0;

	/**
	 * Recipient whose bell must not ring for nothing.
	 *
	 * @var int
	 */
	private $recipient = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->sender    = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();
	}

	/**
	 * Count the recipient's new-message notifications.
	 *
	 * @return int
	 */
	private function notification_count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				  WHERE recipient_id = %d AND type = %s",
				$this->recipient,
				'bn.new_message'
			)
		);
	}

	/**
	 * The bug: a fire with no message must notify nobody.
	 *
	 * @return void
	 */
	public function test_a_message_id_of_zero_notifies_nobody(): void {
		( new WPMediaVerseBridge() )->on_message_sent( 0, 4242, $this->sender, array( $this->recipient ) );

		$this->assertSame(
			0,
			$this->notification_count(),
			'A conversation created without a message told the recipient they had one.'
		);
	}

	/**
	 * ...and a real message must still notify, or the guard has simply broken
	 * direct messaging instead of fixing it.
	 *
	 * @return void
	 */
	public function test_a_real_message_still_notifies(): void {
		( new WPMediaVerseBridge() )->on_message_sent( 7, 4242, $this->sender, array( $this->recipient ) );

		$this->assertSame(
			1,
			$this->notification_count(),
			'A real message stopped notifying the recipient — the guard is too wide.'
		);
	}

	/**
	 * A negative id is the same class of nonsense as zero.
	 *
	 * @return void
	 */
	public function test_a_negative_message_id_notifies_nobody(): void {
		( new WPMediaVerseBridge() )->on_message_sent( -1, 4242, $this->sender, array( $this->recipient ) );

		$this->assertSame( 0, $this->notification_count() );
	}
}
