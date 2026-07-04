<?php
/**
 * Tests the EmailSender can_email gate.
 *
 * BuddyNext aggregates partner notifications for display but must NOT email on
 * their behalf — each plugin owns its own email system. Types the catalogue
 * marks `can_email = false` must never produce an email.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Notifications\EmailSender;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationPrefCatalogue;

/**
 * @covers \BuddyNext\Notifications\EmailSender
 */
class EmailSenderGateTest extends \WP_UnitTestCase {

	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create();
		add_filter(
			'buddynext_notification_prefs_catalogue',
			static function ( array $c ): array {
				$c['x.collect_only'] = array(
					'label'              => 'Collect only',
					'group'              => 'social',
					'default_on_site'    => true,
					'default_email_freq' => 'off',
					'can_email'          => false,
				);
				return $c;
			}
		);
	}

	private function sender(): EmailSender {
		return new EmailSender( new NotificationPrefService(), new NotificationPrefCatalogue() );
	}

	public function test_collect_only_type_sends_no_email(): void {
		$mailed = false;
		add_filter(
			'pre_wp_mail',
			static function ( $short ) use ( &$mailed ) {
				$mailed = true;
				return true; // short-circuit wp_mail.
			}
		);

		$data = array( 'type' => 'x.collect_only', 'message' => 'hi' );
		$this->sender()->send( $this->user_id, 'x.collect_only', $data );
		$this->sender()->send_now( $this->user_id, 'x.collect_only', $data );

		$this->assertFalse( $mailed, 'a can_email=false type must never email' );
	}

	/**
	 * R1 guard: the composed-email exemption is keyed on an explicit type
	 * allowlist, NEVER on the mere presence of inline subject/body. A partner
	 * mirror (can_email=false) that happens to carry a subject + body_html must
	 * STILL be blocked — otherwise the exemption would become a hole through the
	 * partner-email gate.
	 */
	public function test_partner_type_with_inline_content_is_still_gated(): void {
		$mailed = false;
		add_filter(
			'pre_wp_mail',
			static function ( $short ) use ( &$mailed ) {
				$mailed = true;
				return true;
			}
		);

		$data = array(
			'type'      => 'x.collect_only',
			'subject'   => 'Sneaky subject',
			'body_html' => '<p>inline body that must not bypass the gate</p>',
		);
		$sent = $this->sender()->send_now( $this->user_id, 'x.collect_only', $data );

		$this->assertFalse( $mailed, 'inline content must NOT let a can_email=false type bypass the gate' );
		$this->assertFalse( $sent, 'send_now must report false for a gated type' );
	}

	/**
	 * Unbrick proof: an owner-authored composed type (bn.broadcast / bn.drip_step)
	 * that carries inline subject + body IS exempt from the pref gate and sends,
	 * with send_now() reporting the truthful dispatch outcome.
	 */
	public function test_composed_broadcast_type_sends_and_reports_true(): void {
		$mailed = false;
		add_filter(
			'pre_wp_mail',
			static function ( $short ) use ( &$mailed ) {
				$mailed = true;
				return true; // simulate a successful wp_mail dispatch.
			}
		);

		$data = array(
			'type'      => 'bn.broadcast',
			'subject'   => 'Campaign subject',
			'body_html' => '<p>Campaign body</p>',
		);
		$sent = $this->sender()->send_now( $this->user_id, 'bn.broadcast', $data );

		$this->assertTrue( $mailed, 'a composed bn.broadcast email must actually dispatch' );
		$this->assertTrue( $sent, 'send_now must report true when wp_mail succeeds' );
	}

	public function test_catalogue_can_email_resolves(): void {
		$catalogue = new NotificationPrefCatalogue();
		$this->assertFalse( $catalogue->can_email( 'x.collect_only' ) );
		// Unknown types are NOT emailable: BuddyNext never emails for a type it
		// does not own, so an unregistered type resolves to can_email = false.
		$this->assertFalse( $catalogue->can_email( 'totally.unknown.type' ) );
	}
}
