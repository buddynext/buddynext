<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The member approval queue stays reachable, and approving is once only.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\AdminHub;
use BuddyNext\Admin\Members\ApprovalManager;
use BuddyNext\Core\Installer;
use RuntimeException;
use WP_UnitTestCase;

/**
 * Members > Pending forwarded to Moderation > Pending, the tab vanished when the mode left
 * Approval while the hold kept locking people out, and Approve re-fired on active accounts.
 *
 * @covers \BuddyNext\Admin\Members\ApprovalManager
 * @covers \BuddyNext\Admin\AdminHub::redirect_relocated_tabs
 */
class MemberApprovalQueueTest extends WP_UnitTestCase {

	/**
	 * Admin, schema, and redirects that stop instead of exiting.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'wp_redirect',
			static function ( string $location ): void {
				throw new RuntimeException( esc_url_raw( $location ) );
			}
		);
	}

	/**
	 * Reset globals.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'wp_redirect' );
		delete_option( 'buddynext_reg_mode' );
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Where a request goes; '' when it stays.
	 *
	 * @param callable $call Handler.
	 * @return string
	 */
	private function redirect_of( callable $call ): string {
		try {
			$call();
		} catch ( RuntimeException $redirect ) {
			return $redirect->getMessage();
		}
		return '';
	}

	/**
	 * A pending account.
	 *
	 * @return int
	 */
	private function pending_member(): int {
		$uid = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_user_meta( $uid, 'bn_pending_approval', '1' );
		return $uid;
	}

	/**
	 * The nested tab stays on Members; an unrelated guest tab still forwards.
	 *
	 * @return void
	 */
	public function test_members_pending_is_not_forwarded_to_moderation(): void {
		$tabs = new \ReflectionProperty( AdminHub::class, 'tabs' );
		$saved = $tabs->getValue();

		$noop = static function (): void {};
		AdminHub::register_tab( 'members', 'directory', 'Directory', $noop, array( 'subtabs' => array( 'pending' ) ) );
		AdminHub::register_tab( 'moderation', 'pending', 'Pending', $noop );
		AdminHub::register_tab( 'moderation', 'qafix-guest', 'Guest', $noop );

		$hub = ( new \ReflectionClass( AdminHub::class ) )->newInstanceWithoutConstructor();

		$_GET = array(
			'page' => 'buddynext-members',
			'tab'  => 'pending',
		);
		$stay = $this->redirect_of( array( $hub, 'redirect_relocated_tabs' ) );

		$_GET['tab'] = 'qafix-guest';
		$move        = $this->redirect_of( array( $hub, 'redirect_relocated_tabs' ) );

		$tabs->setValue( null, $saved );

		$this->assertSame( '', $stay );
		$this->assertStringContainsString( 'page=buddynext-moderation', $move, 'The guest-tab forward still works.' );
	}

	/**
	 * The queue lives while anyone is waiting, whatever the mode, and only then warns.
	 *
	 * @return void
	 */
	public function test_queue_and_warning_follow_the_pending_accounts(): void {
		update_option( 'buddynext_reg_mode', 'open' );
		$this->assertFalse( ApprovalManager::queue_is_needed(), 'Open, nobody waiting: no tab.' );
		$this->assertSame( '', $this->notice() );

		$uid = $this->pending_member();
		$this->assertTrue( ApprovalManager::queue_is_needed(), 'Open, someone waiting: the tab stays.' );
		$this->assertStringContainsString( 'tab=pending', $this->notice() );
		$this->assertStringContainsString( 'does not approve anyone', $this->notice() );

		update_option( 'buddynext_reg_mode', 'approval' );
		$this->assertTrue( ApprovalManager::queue_is_needed() );
		$this->assertSame( '', $this->notice(), 'No warning while the mode is Approval.' );

		update_option( 'buddynext_reg_mode', 'open' );
		delete_user_meta( $uid, 'bn_pending_approval' );
		$this->assertFalse( ApprovalManager::queue_is_needed() );
	}

	/**
	 * The stranded notice.
	 *
	 * @return string
	 */
	private function notice(): string {
		ob_start();
		ApprovalManager::render_stranded_notice();
		return (string) ob_get_clean();
	}

	/**
	 * Approving a pending member fires once; again, and for an active account, never.
	 *
	 * @return void
	 */
	public function test_approve_fires_only_for_a_pending_account(): void {
		$fired = 0;
		add_action(
			'buddynext_member_approved',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$pending = $this->pending_member();
		$active  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$manager = new ApprovalManager();

		$approve = function ( int $uid ) use ( $manager ): string {
			$_REQUEST = array(
				'user_id'  => $uid,
				'_wpnonce' => wp_create_nonce( 'bn_approve_member_' . $uid ),
			);
			return $this->redirect_of( array( $manager, 'handle_approve' ) );
		};

		$this->assertStringContainsString( 'bn_notice=approved', $approve( $pending ) );
		$this->assertSame( 1, $fired );
		$this->assertSame( '', (string) get_user_meta( $pending, 'bn_pending_approval', true ) );

		$this->assertStringContainsString( 'bn_notice=bad_user', $approve( $pending ), 'A second click.' );
		$this->assertStringContainsString( 'bn_notice=bad_user', $approve( $active ), 'An already-active account.' );
		$this->assertSame( 1, $fired );

		remove_all_actions( 'buddynext_member_approved' );
	}
}
