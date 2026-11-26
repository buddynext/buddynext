<?php
/**
 * The admin offers Suspend only where it would work, and says why when it refuses.
 *
 * The Edit Member header offered "Suspend member" on the admin's own account,
 * and the refusal came back as "you do not have permission", because every
 * service refusal shares the `forbidden` code. The service now gives the reason
 * and the screens ask the same question before drawing the button.
 *
 * @package BuddyNext\Tests\Admin\Members
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin\Members;

use BuddyNext\Admin\Members;
use BuddyNext\Admin\Members\MemberEditForm;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Moderation\ModerationService::suspend_refusal
 * @covers \BuddyNext\Admin\Members::suspend_error_key
 * @covers \BuddyNext\Admin\Members\MemberEditForm
 */
class SuspendOfferMatchesRefusalTest extends \WP_UnitTestCase {

	/**
	 * Acting administrator.
	 *
	 * @var int
	 */
	private int $admin_id;

	/**
	 * Fresh install and a signed-in administrator.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
	}

	/**
	 * Leave no request state behind.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['user_id'], $_GET['view'] );
		parent::tear_down();
	}

	/**
	 * Render the Edit Member screen for a user.
	 *
	 * @param int $user_id Member being edited.
	 * @return string
	 */
	private function edit_screen( int $user_id ): string {
		$_GET['user_id'] = (string) $user_id;
		$_GET['view']    = 'edit';
		ob_start();
		( new MemberEditForm() )->render_edit_member_view();
		return (string) ob_get_clean();
	}

	public function test_self_suspension_reports_the_real_reason(): void {
		$result = buddynext_service( 'moderation' )->suspend_user( $this->admin_id, $this->admin_id, 'Spam' );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code(), 'The REST error code is unchanged.' );
		$this->assertSame( 'self', Members::suspend_error_key( $result ) );
		$this->assertStringContainsString( 'your own account', Members::suspend_error_message( Members::suspend_error_key( $result ) ) );
	}

	public function test_suspend_is_not_offered_on_your_own_account(): void {
		$this->assertStringNotContainsString( 'value="bn_suspend_member"', $this->edit_screen( $this->admin_id ) );
	}

	public function test_suspend_is_still_offered_on_a_member(): void {
		$member = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertNull( buddynext_service( 'moderation' )->suspend_refusal( $member, $this->admin_id ) );
		$this->assertStringContainsString( 'value="bn_suspend_member"', $this->edit_screen( $member ) );
	}
}
