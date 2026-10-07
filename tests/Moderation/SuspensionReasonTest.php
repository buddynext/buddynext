<?php
/**
 * Every suspension a person makes carries a reason the member is shown.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_REST_Request;

/**
 * Card 10240365173: the moderation queues suspended with a fixed internal string,
 * so a member never learned why. A person must now give a reason (chosen from one
 * list, with a note), it reaches REST, and old internal strings read as "no reason".
 *
 * @covers \BuddyNext\Moderation\ModerationService::suspend_user
 * @covers \BuddyNext\Moderation\ModerationService::compose_suspension_reason
 * @covers \BuddyNext\Moderation\ModerationService::member_facing_reason
 * @covers \BuddyNext\Moderation\ModerationController::suspend_user
 */
class SuspensionReasonTest extends \WP_UnitTestCase {

	/**
	 * Site admin.
	 *
	 * @var int
	 */
	private int $admin = 0;

	/**
	 * Member being suspended.
	 *
	 * @var int
	 */
	private int $member = 0;

	/**
	 * Schema, an admin and a member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create();
	}

	/**
	 * A person cannot suspend without a reason; the system (rules) still can.
	 *
	 * @return void
	 */
	public function test_a_person_must_give_a_reason(): void {
		$service = new ModerationService();

		$refused = $service->suspend_user( $this->member, $this->admin, '   ' );
		$this->assertWPError( $refused );
		$this->assertSame( 'reason_required', $refused->get_error_code() );
		$this->assertFalse( $service->is_suspended( $this->member ) );

		$this->assertWPError( $service->suspend( $this->member, '', 7, false, $this->admin ), 'The bare primitive applies the same rule.' );

		$this->assertIsInt( $service->suspend_user( $this->member, 0, 'Automatic suspension: strike threshold reached.' ) );
	}

	/**
	 * Reason code + note compose into the text the member reads.
	 *
	 * @return void
	 */
	public function test_reason_codes_compose_the_member_facing_text(): void {
		$this->assertSame( 'Spam or scams', ModerationService::compose_suspension_reason( 'spam' ) );
		$this->assertSame( 'Spam or scams: posted the same link everywhere', ModerationService::compose_suspension_reason( 'spam', ' posted the same link everywhere ' ) );
		$this->assertSame( 'Ignored three warnings', ModerationService::compose_suspension_reason( 'other', 'Ignored three warnings' ) );
		$this->assertSame( 'reason_required', ModerationService::compose_suspension_reason( 'other', '' )->get_error_code() );
		$this->assertSame( 'invalid_reason', ModerationService::compose_suspension_reason( 'nope' )->get_error_code() );
		$this->assertSame( 'invalid_reason', ModerationService::compose_suspension_reason( '' )->get_error_code() );
		$this->assertSame( ModerationService::SUSPENSION_NOTE_MAX, mb_strlen( (string) ModerationService::compose_suspension_reason( 'other', str_repeat( 'x', 500 ) ) ) );
	}

	/**
	 * REST: reason_code + note are stored as the member-facing reason; a missing reason is a 400.
	 *
	 * @return void
	 */
	public function test_rest_suspend_stores_the_composed_reason(): void {
		wp_set_current_user( $this->admin );

		$bad = new WP_REST_Request( 'POST', '/buddynext/v1/users/' . $this->member . '/suspend' );
		$this->assertSame( 400, rest_get_server()->dispatch( $bad )->get_status() );

		$req = new WP_REST_Request( 'POST', '/buddynext/v1/users/' . $this->member . '/suspend' );
		$req->set_param( 'reason_code', 'harassment' );
		$req->set_param( 'note', 'repeated insults in DMs' );
		$req->set_param( 'duration_days', 7 );
		$this->assertSame( 201, rest_get_server()->dispatch( $req )->get_status() );

		$row = ( new ModerationService() )->get_active_suspension( $this->member );
		$this->assertSame( 'Harassment or abuse: repeated insults in DMs', $row['reason'] );
	}

	/**
	 * The reasons route lists the same codes the dialogs offer.
	 *
	 * @return void
	 */
	public function test_reasons_route_lists_the_shared_codes(): void {
		wp_set_current_user( $this->admin );
		$data = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/moderation/suspension-reasons' ) )->get_data();

		$this->assertSame( array_keys( ModerationService::suspension_reasons() ), wp_list_pluck( $data['items'], 'code' ) );
		$this->assertSame( ModerationService::SUSPENSION_NOTE_MAX, $data['note_max'] );

		wp_set_current_user( $this->member );
		$this->assertSame( 403, rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/moderation/suspension-reasons' ) )->get_status() );
	}

	/**
	 * Old internal strings read as "no reason" so the member sees the neutral line.
	 *
	 * @return void
	 */
	public function test_legacy_internal_strings_read_as_no_reason(): void {
		$this->assertSame( '', ModerationService::member_facing_reason( 'Moderation action' ) );
		$this->assertSame( '', ModerationService::member_facing_reason( 'Suspended from the moderation queue.' ) );
		$this->assertSame( 'Spam or scams', ModerationService::member_facing_reason( ' Spam or scams ' ) );
	}

	/**
	 * The upgrade moves an unedited suspension email to the wording with {{reason}}.
	 *
	 * @return void
	 */
	public function test_unedited_suspension_email_gains_the_reason(): void {
		global $wpdb;
		$history = Installer::email_default_history();
		$old     = (string) $history['bn.member_suspended']['previous'][0]['body_html'];
		$wpdb->update( $wpdb->prefix . 'bn_email_templates', array( 'body_html' => $old ), array( 'type' => 'bn.member_suspended' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		\BuddyNext\Notifications\EmailDefaults::refresh_unedited();

		$body = (string) $wpdb->get_var( "SELECT body_html FROM {$wpdb->prefix}bn_email_templates WHERE type = 'bn.member_suspended'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertStringContainsString( '{{reason}}', $body );
		$this->assertStringContainsString( '{{expires_at}}', $body );
	}
}
