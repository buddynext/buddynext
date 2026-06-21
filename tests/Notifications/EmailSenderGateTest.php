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

	public function test_catalogue_can_email_resolves(): void {
		$catalogue = new NotificationPrefCatalogue();
		$this->assertFalse( $catalogue->can_email( 'x.collect_only' ) );
		// Unknown types are NOT emailable: BuddyNext never emails for a type it
		// does not own, so an unregistered type resolves to can_email = false.
		$this->assertFalse( $catalogue->can_email( 'totally.unknown.type' ) );
	}
}
