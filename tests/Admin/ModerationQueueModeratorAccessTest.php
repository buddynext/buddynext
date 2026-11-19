<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A community moderator works the wp-admin Moderation screens without manage_options.
 *
 * The screens, their tabs and every queue action checked manage_options, so a
 * BuddyNext moderator (often a WordPress subscriber) could not reach them at
 * all. Access now follows the community ability layer: the daily tabs open on
 * review-queue, each action checks its own ability, and appeals, the log export
 * and Run now stay with site admins.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use BuddyNext\Admin\ModerationQueue;
use BuddyNext\Core\Installer;
use BuddyNext\Core\PermissionService;
use BuddyNext\Moderation\ModerationService;

/**
 * Moderator access to the admin moderation queue.
 *
 * @covers \BuddyNext\Admin\ModerationQueue
 * @covers \BuddyNext\Admin\AdminHub
 * @covers \BuddyNext\Moderation\ModerationService::unsuspend_user
 */
class ModerationQueueModeratorAccessTest extends \WP_UnitTestCase {

	/**
	 * Tab registry before the test, restored after.
	 *
	 * @var array<string, mixed>
	 */
	private array $tabs_before = array();

	/**
	 * Queue under test.
	 *
	 * @var ModerationQueue
	 */
	private ModerationQueue $queue;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->tabs_before = ( new \ReflectionProperty( AdminHub::class, 'tabs' ) )->getValue();
		$this->queue       = new ModerationQueue();
		$this->queue->register();
		// Admin-only tabs in other sections, as the real boot registers them.
		AdminHub::register_tab( 'moderation', 'rules', 'Rules', static function (): void {} );
		AdminHub::register_tab( 'settings', 'general', 'General', static function (): void {} );
	}

	public function tear_down(): void {
		( new \ReflectionProperty( AdminHub::class, 'tabs' ) )->setValue( null, $this->tabs_before );
		unset( $_REQUEST['_wpnonce'] );
		parent::tear_down();
	}

	/**
	 * A WordPress subscriber holding a community role.
	 *
	 * @param string $role Community role.
	 * @return int
	 */
	private function member( string $role ): int {
		$id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $id, 'bn_community_role', $role );
		update_user_meta( $id, 'bn_email_verified', '1' );
		return $id;
	}

	/**
	 * Call a private static AdminHub method.
	 *
	 * @param string $name Method name.
	 * @param array  $args Arguments.
	 * @return mixed
	 */
	private function hub( string $name, array $args = array() ) {
		return ( new \ReflectionMethod( AdminHub::class, $name ) )->invokeArgs( null, $args );
	}

	/**
	 * Whether the queue guard lets the current user through an action.
	 *
	 * @param string $action admin-post action.
	 * @param string $op     Operation.
	 * @return bool
	 */
	private function guard_passes( string $action, string $op = '' ): bool {
		$_REQUEST['_wpnonce'] = wp_create_nonce( $action );
		try {
			( new \ReflectionMethod( ModerationQueue::class, 'guard' ) )->invoke( $this->queue, $action, $op );
			return true;
		} catch ( \WPDieException $e ) {
			return false;
		}
	}

	/**
	 * Forget per-request answers after the role map changes.
	 *
	 * @return void
	 */
	private function forget_answers(): void {
		( new \ReflectionProperty( PermissionService::class, 'role_map_cache' ) )->setValue( null, null );
		( new \ReflectionProperty( ModerationQueue::class, 'moderate_answers' ) )->setValue( null, array() );
	}

	public function test_moderator_holds_the_moderation_cap_but_not_manage_options(): void {
		$moderator = $this->member( 'moderator' );
		$member    = $this->member( 'member' );
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertTrue( user_can( $moderator, ModerationQueue::CAP ) );
		$this->assertFalse( user_can( $moderator, 'manage_options' ) );
		$this->assertFalse( user_can( $member, ModerationQueue::CAP ) );
		$this->assertFalse( user_can( $member, 'manage_options' ) );
		$this->assertTrue( user_can( $admin, ModerationQueue::CAP ) );
		$this->assertTrue( user_can( $admin, 'manage_options' ) );
	}

	public function test_moderator_sees_the_daily_tabs_and_no_admin_tab_or_section(): void {
		wp_set_current_user( $this->member( 'moderator' ) );

		$tabs = array_keys( $this->hub( 'visible_tabs', array( 'moderation' ) ) );
		sort( $tabs );
		$this->assertSame( array( 'log', 'pending', 'reports', 'suspensions' ), $tabs );
		$this->assertSame( array(), $this->hub( 'visible_tabs', array( 'automation' ) ), 'Rules stays admin-only.' );
		$this->assertSame( array(), $this->hub( 'visible_tabs', array( 'settings' ) ) );

		$this->assertSame( ModerationQueue::CAP, $this->hub( 'section_capability', array( 'moderation' ) ) );
		$this->assertSame( 'manage_options', $this->hub( 'section_capability', array( 'settings' ) ) );
		$this->assertSame( 'manage_options', $this->hub( 'section_capability', array( 'automation' ) ) );
	}

	public function test_administrator_still_opens_every_tab(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertArrayHasKey( 'appeals', $this->hub( 'visible_tabs', array( 'moderation' ) ) );
		$this->assertArrayHasKey( 'general', $this->hub( 'visible_tabs', array( 'settings' ) ) );
		$this->assertSame( 'manage_options', $this->hub( 'section_capability', array( 'moderation' ) ) );
		foreach ( array( 'bn_mod_report_action', 'bn_mod_premod_action', 'bn_mod_appeal_action' ) as $action ) {
			$this->assertTrue( $this->guard_passes( $action ), $action );
		}
		$this->assertTrue( $this->guard_passes( 'bn_mod_user_action', 'suspend' ) );
	}

	public function test_guard_follows_each_actions_ability(): void {
		wp_set_current_user( $this->member( 'moderator' ) );
		$this->assertTrue( $this->guard_passes( 'bn_mod_report_action' ) );
		$this->assertTrue( $this->guard_passes( 'bn_mod_premod_action' ) );
		$this->assertTrue( $this->guard_passes( 'bn_mod_user_action', 'strike' ) );
		$this->assertTrue( $this->guard_passes( 'bn_mod_user_action', 'suspend' ) );
		$this->assertTrue( $this->guard_passes( 'bn_mod_user_action', 'unsuspend' ) );
		$this->assertFalse( $this->guard_passes( 'bn_mod_appeal_action' ), 'Appeals stay with admins.' );

		wp_set_current_user( $this->member( 'member' ) );
		foreach ( array( 'bn_mod_report_action', 'bn_mod_premod_action', 'bn_mod_appeal_action' ) as $action ) {
			$this->assertFalse( $this->guard_passes( $action ), $action );
		}
		$this->assertFalse( $this->guard_passes( 'bn_mod_user_action', 'strike' ) );
	}

	public function test_export_and_run_now_still_need_manage_options(): void {
		wp_set_current_user( $this->member( 'moderator' ) );
		foreach ( array( 'handle_export_log', 'handle_run_now' ) as $method ) {
			try {
				$this->queue->$method();
				$this->fail( "{$method} let a moderator through." );
			} catch ( \WPDieException $e ) {
				$this->assertStringContainsString( 'permission', $e->getMessage() );
			}
		}
	}

	public function test_regrading_review_queue_removes_the_screens(): void {
		$moderator = $this->member( 'moderator' );
		wp_set_current_user( $moderator );
		$this->assertTrue( current_user_can( ModerationQueue::CAP ) );

		update_option( 'bn_role_map_overrides', array( 'buddynext-moderation/review-queue' => 'admin' ) );
		$this->forget_answers();

		$this->assertFalse( current_user_can( ModerationQueue::CAP ) );
		$this->assertSame( array(), $this->hub( 'visible_tabs', array( 'moderation' ) ) );
		$this->assertSame( 'manage_options', $this->hub( 'section_capability', array( 'moderation' ) ) );
		$this->assertFalse( $this->guard_passes( 'bn_mod_premod_action' ) );
	}

	public function test_moderator_cannot_sanction_an_admin(): void {
		$moderator = $this->member( 'moderator' );
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$service   = new ModerationService();

		$this->assertWPError( $service->suspend_user( $admin, $moderator, 'Testing', array( 'duration_days' => 7 ) ) );
		$this->assertWPError( $service->issue_strike( $admin, $moderator, 'Testing' ) );
	}

	public function test_moderator_cannot_lift_a_suspension_an_admin_issued(): void {
		$moderator = $this->member( 'moderator' );
		$target    = $this->member( 'member' );
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$service   = new ModerationService();

		$this->assertIsInt( $service->suspend_user( $target, $admin, 'Spam', array( 'duration_days' => 7 ) ) );

		$refused = $service->unsuspend_user( $target, $moderator );
		$this->assertWPError( $refused );
		$this->assertSame( 'outranked', $refused->get_error_data()['reason'] ?? '' );
		$this->assertTrue( $service->is_suspended( $target ), 'The suspension stands.' );

		$this->assertTrue( $service->unsuspend_user( $target, $admin ) );
	}

	public function test_moderator_lifts_a_peer_suspension_and_the_system_lift_still_works(): void {
		$moderator = $this->member( 'moderator' );
		$peer      = $this->member( 'moderator' );
		$target    = $this->member( 'member' );
		$service   = new ModerationService();

		$this->assertIsInt( $service->suspend_user( $target, $peer, 'Spam', array( 'duration_days' => 7 ) ) );
		$this->assertTrue( $service->unsuspend_user( $target, $moderator ), 'Equal rank may lift.' );

		// An automated suspension (actor 0) outranks no one, so a moderator lifts it.
		$this->assertIsInt( $service->suspend_user( $target, 0, 'Auto rule', array( 'duration_days' => 1 ) ) );
		$this->assertTrue( $service->unsuspend_user( $target, $moderator ) );

		// And the system lift, as expiry and capability-gated lifts use, still works.
		$this->assertIsInt( $service->suspend_user( $target, 0, 'Auto rule', array( 'duration_days' => 1 ) ) );
		$this->assertTrue( $service->unsuspend( $target ) );
	}
}
