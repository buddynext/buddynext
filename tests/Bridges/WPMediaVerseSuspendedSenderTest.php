<?php
/**
 * A suspended member cannot send direct messages through WPMediaVerse.
 *
 * @package BuddyNext\Tests
 */

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\WPMediaVerseBridge
 */
class WPMediaVerseSuspendedSenderTest extends \WP_UnitTestCase {

	private int $sender;
	private int $recipient;
	private WPMediaVerseBridge $bridge;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->sender    = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();
		$this->bridge    = new WPMediaVerseBridge();
	}

	private function suspend( int $user_id ): void {
		$this->assertTrue( buddynext_service( 'moderation' )->suspend( $user_id, 'spam', 7, false, 1 ) );
	}

	public function test_suspended_sender_is_refused_for_new_conversation_and_reply(): void {
		$this->assertTrue( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
		$this->suspend( $this->sender );
		// The engine asks this one filter before opening a thread and before every reply.
		$this->assertFalse( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
		$this->assertSame( 'account_suspended', $this->bridge->suspended_denial_reason( 'blocked', $this->sender ) );
		$this->assertStringContainsString( 'suspended', $this->bridge->suspended_denial_message( 'x', 'account_suspended' ) );
	}

	public function test_suspended_member_cannot_create_a_group_conversation(): void {
		$this->assertTrue( $this->bridge->check_can_create_conversation( true, $this->sender ) );
		$this->suspend( $this->sender );
		$this->assertFalse( $this->bridge->check_can_create_conversation( true, $this->sender ) );
		$this->assertFalse( $this->bridge->check_can_create_conversation( false, $this->recipient ) );
		buddynext_service( 'moderation' )->unsuspend( $this->sender );
		$this->assertTrue( $this->bridge->check_can_create_conversation( true, $this->sender ) );
	}

	public function test_suspended_sender_is_refused_for_group_send(): void {
		$this->suspend( $this->sender );
		$result = $this->bridge->moderate_dm_content( true, 'hello all', $this->sender );
		$this->assertWPError( $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertArrayHasKey( 'appeal_url', $result->get_error_data() );
	}

	public function test_member_in_good_standing_passes(): void {
		$this->assertTrue( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
		$this->assertTrue( $this->bridge->moderate_dm_content( true, 'hello', $this->sender ) );
		$this->assertSame( 'blocked', $this->bridge->suspended_denial_reason( 'blocked', $this->sender ) );
	}

	public function test_lifted_suspension_sends_again(): void {
		$this->suspend( $this->sender );
		$this->assertFalse( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
		buddynext_service( 'moderation' )->unsuspend( $this->sender );
		$this->assertTrue( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
		$this->assertTrue( $this->bridge->moderate_dm_content( true, 'hello', $this->sender ) );
	}

	public function test_suspended_recipient_does_not_block_a_sender(): void {
		// Kudos and the directory only look at the actor; a suspended recipient is not a send refusal.
		$this->suspend( $this->recipient );
		$this->assertTrue( $this->bridge->check_block( true, $this->sender, $this->recipient ) );
	}

	public function test_site_owner_is_never_blocked(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->suspend( $admin );
		$this->assertTrue( $this->bridge->check_block( true, $admin, $this->recipient ) );
		$this->assertTrue( $this->bridge->moderate_dm_content( true, 'hi', $admin ) );
	}

	public function test_refused_send_creates_no_notification_and_no_email(): void {
		global $wpdb;
		$mails = 0;
		add_filter(
			'pre_wp_mail',
			static function () use ( &$mails ) {
				++$mails;
				return true;
			}
		);
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = " . (int) $this->recipient ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->suspend( $this->sender );

		// The engine fires mvs_message_sent only after the gates pass; a refused send never reaches it.
		$allowed = apply_filters( 'mvs_can_send_message', true, $this->sender, $this->recipient );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = " . (int) $this->recipient ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertFalse( $allowed );
		$this->assertSame( $before, $after );
		$this->assertSame( 0, $mails );
	}
}
