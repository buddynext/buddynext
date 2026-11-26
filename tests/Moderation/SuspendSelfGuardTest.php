<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Nobody suspends themselves, and a site administrator cannot suspend the
 * network's super admin. One guard in ModerationService::suspend_user() covers
 * the admin row action, bulk and REST.
 *
 * Run the super-admin case with WP_MULTISITE=1; it skips on a single site.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::suspend_user
 * @covers \BuddyNext\Admin\Members::handle_suspend
 */
class SuspendSelfGuardTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function tear_down(): void {
		$_POST = array();
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * Post the admin Suspend form for a member and return where it redirected.
	 *
	 * @param int $user_id Member to suspend.
	 * @return string
	 */
	private function post_suspend( int $user_id ): string {
		$_POST                = array(
			'user_id'       => (string) $user_id,
			'reason_code'   => 'spam',
			'duration_days' => '7',
			'_wpnonce'      => wp_create_nonce( 'bn_suspend_member' ),
		);
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'];

		$location = '';
		$catch    = static function ( string $to ) {
			throw new \RuntimeException( $to ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		};
		add_filter( 'wp_redirect', $catch );
		try {
			( new Members() )->handle_suspend();
		} catch ( \RuntimeException $redirect ) {
			$location = $redirect->getMessage();
		} finally {
			remove_filter( 'wp_redirect', $catch );
		}
		return $location;
	}

	public function test_an_administrator_cannot_suspend_themselves(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$result = ( new ModerationService() )->suspend_user( $admin, $admin, 'Testing', array( 'duration_days' => 7 ) );
		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );

		$this->assertStringContainsString( 'action=suspend_failed', $this->post_suspend( $admin ) );
		$this->assertNull( ( new ModerationService() )->get_active_suspension( $admin ) );
	}

	public function test_an_administrator_still_suspends_a_member(): void {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$member = self::factory()->user->create();
		wp_set_current_user( $admin );

		$this->assertStringContainsString( 'action=suspended', $this->post_suspend( $member ) );
		$this->assertNotNull( ( new ModerationService() )->get_active_suspension( $member ) );
	}

	public function test_the_system_actor_is_not_caught_by_the_self_guard(): void {
		$member = self::factory()->user->create();

		$this->assertIsInt( ( new ModerationService() )->suspend_user( $member, 0, 'Auto rule', array( 'duration_days' => 1 ) ) );
	}

	public function test_a_site_admin_cannot_suspend_the_super_admin(): void {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		grant_super_admin( $owner );
		$site_admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $site_admin );

		$this->assertStringContainsString( 'action=suspend_failed', $this->post_suspend( $owner ) );
		$this->assertNull( ( new ModerationService() )->get_active_suspension( $owner ) );
	}
}
