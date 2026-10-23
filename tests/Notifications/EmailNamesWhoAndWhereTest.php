<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * The default emails say who and where, as the bell does (card 10435757270), and
 * the welcome button opens the community, not the site home (card 10435746911).
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\EmailDefaults;

/**
 * @covers \BuddyNext\Core\Installer::email_template_seeds
 * @covers \BuddyNext\Notifications\EmailSender
 */
class EmailNamesWhoAndWhereTest extends \WP_UnitTestCase {

	/**
	 * Mail captured by pre_wp_mail.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mail = array();

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_email_templates" );
		Installer::reseed_email_templates();

		add_filter( 'buddynext_notification_force_on_site', '__return_true' );
		add_filter(
			'pre_wp_mail',
			function ( $short, array $atts ) {
				$this->mail[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	private function last_mail(): string {
		$this->assertNotEmpty( $this->mail, 'No email was sent.' );
		$atts = end( $this->mail );
		return quoted_printable_decode( (string) $atts['subject'] . "\n" . (string) $atts['message'] );
	}

	private function send( int $to, string $type, array $data ): string {
		$this->mail = array();
		buddynext_service( 'email_sender' )->send_now( $to, $type, $data + array( 'type' => $type ) );
		return $this->last_mail();
	}

	private function member( string $name ): int {
		return self::factory()->user->create( array( 'display_name' => $name ) );
	}

	public function test_connection_accepted_names_the_member(): void {
		$mail = $this->send( $this->member( 'Quinn Reader' ), 'bn.connection_accepted', array( 'sender_id' => $this->member( 'Mara Okafor' ) ) );
		$this->assertStringContainsString( 'Mara Okafor accepted your connection request', $mail );
		$this->assertStringContainsString( 'View their profile', $mail );
	}

	public function test_new_message_names_the_sender(): void {
		// A DM email needs WPMediaVerse, absent here; the accepted test above proves
		// {{actor_name}} is filled from sender_id, so check the default names it.
		$seed = array_values( wp_list_filter( Installer::email_template_seeds(), array( 'type' => 'bn.new_message' ) ) )[0];
		$this->assertStringContainsString( '{{actor_name}} sent you', $seed['subject'] );
		$this->assertStringContainsString( '{{actor_name}}</strong> sent you', $seed['body_html'] );
	}

	public function test_space_decisions_name_the_space(): void {
		$owner    = $this->member( 'Space Owner' );
		$space_id = buddynext_service( 'spaces' )->create( $owner, array( 'name' => 'Data and AI Circle', 'slug' => 'data-and-ai-circle', 'privacy' => 'private' ) );
		$this->assertIsInt( $space_id, is_wp_error( $space_id ) ? $space_id->get_error_message() : '' );

		$listener = new \BuddyNext\Notifications\NotificationListener();
		$member   = $this->member( 'Quinn Reader' );

		$captured = array();
		$grab     = static function ( int $id, int $recipient, array $data ) use ( &$captured ): void {
			$captured[ (string) $data['type'] ] = $data;
		};
		add_action( 'buddynext_notification_created', $grab, 1, 3 );
		$listener->on_space_join_approved( $space_id, $member, $owner );
		$listener->on_space_join_declined( $space_id, $member, $owner );
		remove_action( 'buddynext_notification_created', $grab, 1 );

		foreach ( array( 'bn.space_request_approved', 'bn.space_join_declined' ) as $type ) {
			$this->assertStringContainsString( 'Data and AI Circle', $this->send( $member, $type, $captured[ $type ] ), $type );
		}

		// The new-owner email shipped {{space_name}} and {{space_url}} as text: no caller passed them.
		$mail = $this->send(
			$member,
			'bn.space_ownership_received',
			array(
				'object_type' => 'space',
				'object_id'   => $space_id,
			)
		);
		$this->assertStringContainsString( 'owner of Data and AI Circle', $mail );
		$this->assertStringNotContainsString( '{{', $mail );
	}

	/**
	 * Every email about something a person did says who, in the subject a member
	 * decides on, and every space email says which space.
	 */
	public function test_social_emails_name_the_person_and_the_space(): void {
		$seeds = array();
		foreach ( Installer::email_template_seeds() as $seed ) {
			$seeds[ $seed['type'] ] = $seed;
		}

		$person = array( 'bn.new_follower', 'bn.connection_requested', 'bn.connection_accepted', 'bn.mention', 'bn.post_commented', 'bn.post_shared', 'bn.space_invite', 'bn.space_join_requested', 'bn.new_message', 'bn.media_commented', 'bn.media_favorited' );
		foreach ( $person as $type ) {
			$this->assertStringContainsString( '{{actor_name}}', $seeds[ $type ]['subject'], $type );
			$this->assertStringContainsString( '{{actor_name}}', $seeds[ $type ]['body_html'], $type );
		}

		foreach ( array( 'bn.space_invite', 'bn.space_join_requested', 'bn.space_request_approved', 'bn.space_join_declined' ) as $type ) {
			$this->assertStringContainsString( '{{space_name}}', $seeds[ $type ]['subject'], $type );
		}
	}

	public function test_welcome_button_opens_the_community(): void {
		$welcome = array_values( wp_list_filter( Installer::email_template_seeds(), array( 'type' => 'welcome' ) ) )[0];
		$this->assertStringContainsString( 'href="{{action_url}}"', $welcome['body_html'] );
		$this->assertStringNotContainsString( '{{site_url}}', $welcome['body_html'] );
	}

	public function test_an_unedited_old_default_is_repaired_and_an_edit_is_kept(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_email_templates';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $table, array( 'subject' => 'New message on {{site_name}}' ), array( 'type' => 'bn.new_message' ) );
		$wpdb->update( $table, array( 'subject' => 'Our own words' ), array( 'type' => 'bn.connection_accepted' ) );

		EmailDefaults::refresh_unedited();

		$this->assertSame( '{{actor_name}} sent you a message on {{site_name}}', $wpdb->get_var( "SELECT subject FROM {$table} WHERE type = 'bn.new_message'" ) );
		$this->assertSame( 'Our own words', $wpdb->get_var( "SELECT subject FROM {$table} WHERE type = 'bn.connection_accepted'" ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
