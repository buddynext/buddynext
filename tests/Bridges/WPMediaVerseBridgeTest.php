<?php
/**
 * Tests for the WPMediaVerse bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge
 */
class WPMediaVerseBridgeTest extends \WP_UnitTestCase {

	private WPMediaVerseBridge $bridge;
	private int $sender_id;
	private int $recipient_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Plugin class stub is registered in tests/bootstrap.php.
		$this->bridge = new WPMediaVerseBridge();
		// Mirror Plugin::init(): the DM safety gates are wired independently of
		// the display half, so a test that only called init() would no longer see
		// mvs_can_send_message and would be asserting against the wrong wiring.
		$this->bridge->init_dm_gates();
		$this->bridge->init();
		$this->sender_id    = self::factory()->user->create();
		$this->recipient_id = self::factory()->user->create();
	}

	public function test_buddynext_active_filter_returns_true(): void {
		$result = apply_filters( 'mvs_buddynext_active', false );

		$this->assertTrue( $result );
	}

	/**
	 * The Features toggle must not be able to un-gate DM.
	 *
	 * BuddyNext's /messages/ hub reaches the engine through MessagesData ->
	 * MediaClient -> the engine's own container, never through this bridge. So
	 * when an owner switches Platform -> Features -> WPMediaVerse off, messaging
	 * carries on: the hub renders, shell-nav still emits the item, and the store
	 * still posts to mvs/v1. Wiring check_block() behind that toggle therefore
	 * disabled the check and not the messaging — a member who had blocked someone
	 * kept receiving their DMs while the Block control went on claiming otherwise.
	 *
	 * This asserts the gates hold with the display half never initialised.
	 */
	public function test_dm_gates_hold_when_the_integration_display_is_toggled_off(): void {
		// A bridge whose display half is never wired — i.e. init() is not called,
		// exactly as Plugin::init() leaves it when the Features toggle is off.
		$display_off = new WPMediaVerseBridge();
		$display_off->init_dm_gates();

		$this->assertNotFalse(
			has_filter( 'mvs_can_send_message', array( $display_off, 'check_block' ) ),
			'bn_blocks must gate DM with the integration display off; BuddyNext still serves /messages/.'
		);
		$this->assertNotFalse(
			has_filter( 'mvs_message_content_check', array( $display_off, 'moderate_dm_content' ) ),
			'DM auto-moderation must survive the display toggle.'
		);
		$this->assertNotFalse(
			has_filter( 'mvs_dm_denial_reason', array( $display_off, 'dm_denial_reason' ) ),
			'The denial reason must survive the display toggle, or a denial reads as a generic error.'
		);
	}

	/**
	 * Prove the gate actually refuses, not merely that a filter is attached.
	 *
	 * Attachment alone is a weak assertion — it would still pass if check_block()
	 * were gutted to `return $allowed`. This drives a real block all the way
	 * through the display-off wiring, and pins the regression the Features toggle
	 * used to cause: engine default allows, so only the gate can produce false.
	 */
	public function test_display_off_bridge_still_refuses_a_blocked_sender(): void {
		global $wpdb;

		// Recipient has blocked sender.
		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $this->recipient_id,
				'blocked_id' => $this->sender_id,
			),
			array( '%d', '%d' )
		);

		// The un-gated engine default: without BuddyNext's filter, the send is
		// allowed. That is precisely what the site was left with when the owner
		// switched the integration display off, and it is what makes the
		// assertion below meaningful rather than vacuous.
		$this->assertTrue(
			apply_filters( 'mvs_can_send_message_unbridged', true, $this->sender_id, $this->recipient_id ),
			'Fixture check: an unhooked filter must pass the engine default through.'
		);

		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertFalse(
			$result,
			'A blocked sender must still be refused when only init_dm_gates() has run.'
		);
	}

	public function test_can_send_message_allows_when_not_blocked(): void {
		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertTrue( $result );
	}

	public function test_can_send_message_blocks_when_recipient_blocked_sender(): void {
		global $wpdb;

		// Recipient has blocked sender.
		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $this->recipient_id,
				'blocked_id' => $this->sender_id,
			),
			array( '%d', '%d' )
		);

		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertFalse( $result );
	}

	public function test_can_send_message_does_not_affect_unrelated_pair(): void {
		global $wpdb;

		$third_user = self::factory()->user->create();

		// Block an unrelated pair — should not affect sender/recipient.
		$wpdb->insert(
			$wpdb->prefix . 'bn_blocks',
			array(
				'blocker_id' => $third_user,
				'blocked_id' => $this->sender_id,
			),
			array( '%d', '%d' )
		);

		$result = apply_filters( 'mvs_can_send_message', true, $this->sender_id, $this->recipient_id );

		$this->assertTrue( $result );
	}

	public function test_message_sent_creates_notification(): void {
		global $wpdb;

		do_action( 'mvs_message_sent', 1, 10, $this->sender_id, array( $this->recipient_id ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'bn.new_message'",
				$this->recipient_id
			)
		);

		$this->assertGreaterThan( 0, $count );
	}

	public function test_message_sent_skips_notification_for_sender(): void {
		global $wpdb;

		// Sender should not receive a notification about their own message.
		do_action( 'mvs_message_sent', 1, 10, $this->sender_id, array( $this->sender_id ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = 'bn.new_message'",
				$this->sender_id
			)
		);

		$this->assertSame( 0, $count );
	}
}
