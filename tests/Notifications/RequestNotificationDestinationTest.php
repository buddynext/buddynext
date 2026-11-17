<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A received follow or connection request opens the RECIPIENT's own tab.
 *
 * Two faults, one destination seam (NotificationMessageService::url_for()):
 * - bn.follow_requested opened the requester's profile, which has no Accept
 *   control; the requests are approved on the recipient's Followers tab.
 * - compose() resolved "me" from the current user. Pro push composes inside the
 *   SENDER's request, so a connection-request banner linked to the requester's
 *   own Connections tab, and the cron digest (no current user) got no link.
 *
 * Each case composes with the current user set to the ACTOR, the way push runs.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Notifications\NotificationMessageService;
use WP_UnitTestCase;

/**
 * Request notifications deep-link to the recipient.
 *
 * @covers \BuddyNext\Notifications\NotificationMessageService
 */
class RequestNotificationDestinationTest extends WP_UnitTestCase {

	/**
	 * Member who asked.
	 *
	 * @var int
	 */
	private int $requester = 0;

	/**
	 * Member who was asked.
	 *
	 * @var int
	 */
	private int $recipient = 0;

	/**
	 * Two members; the request's sender is the current user.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->requester = self::factory()->user->create();
		$this->recipient = self::factory()->user->create();
		wp_set_current_user( $this->requester );
	}

	/**
	 * Row as stored in bn_notifications.
	 *
	 * @param string $type Notification type.
	 * @return array<string, mixed>
	 */
	private function row( string $type ): array {
		return array(
			'type'         => $type,
			'recipient_id' => $this->recipient,
			'sender_id'    => $this->requester,
			'object_id'    => 0,
			'data'         => array(),
		);
	}

	/**
	 * Follow request: bell, push and digest open the recipient's Followers tab.
	 *
	 * @return void
	 */
	public function test_follow_request_opens_the_recipients_followers_tab(): void {
		$expected = PageRouter::followers_url( $this->recipient );

		$this->assertSame( $expected, ( new NotificationMessageService() )->compose( $this->row( 'bn.follow_requested' ) )['url'] );
		$this->assertSame(
			$expected,
			( new NotificationMessageService() )->email_action_url( 'bn.follow_requested', $this->recipient, array( 'sender_id' => $this->requester ) )
		);
	}

	/**
	 * Connection request composed in the sender's request (Pro push) still opens
	 * the RECIPIENT's Connections tab, not the sender's.
	 *
	 * @return void
	 */
	public function test_connection_request_resolves_me_as_the_recipient(): void {
		$this->assertSame(
			PageRouter::connections_url( $this->recipient ),
			( new NotificationMessageService() )->compose( $this->row( 'bn.connection_requested' ) )['url']
		);
	}

	/**
	 * Digest email runs from cron with no current user: the line is still linked.
	 *
	 * @return void
	 */
	public function test_request_link_resolves_with_no_current_user(): void {
		wp_set_current_user( 0 );

		$this->assertSame(
			PageRouter::connections_url( $this->recipient ),
			( new NotificationMessageService() )->compose( $this->row( 'bn.connection_requested' ) )['url']
		);
	}

	/**
	 * A new follower and an accepted connection still open the actor's profile.
	 *
	 * @return void
	 */
	public function test_follower_and_accepted_still_open_the_actor_profile(): void {
		foreach ( array( 'bn.new_follower', 'bn.connection_accepted' ) as $type ) {
			$this->assertSame(
				PageRouter::profile_url( $this->requester ),
				( new NotificationMessageService() )->compose( $this->row( $type ) )['url'],
				$type
			);
		}
	}
}
