<?php
/**
 * The wp-admin Members screen must record its suspend/unsuspend actions to the
 * moderation log, like every other moderation surface.
 *
 * Regression cover for card 10264294456: Members::suspend_member() /
 * unsuspend_member() wrote the suspension row and fired the hooks but never wrote
 * a bn_mod_log entry, so a site owner could not see what the Members screen did
 * (the REST, queue, bulk and AI paths all log; this one did not).
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\Members;
use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationLogService;
use WP_UnitTestCase;

/**
 * wp-admin Members screen writes an audit trail.
 *
 * @covers \BuddyNext\Admin\Members::suspend_member
 * @covers \BuddyNext\Admin\Members::unsuspend_member
 */
class MembersSuspendIsLoggedTest extends WP_UnitTestCase {

	/** @var Members */
	private $members;

	/** @var int */
	private $admin = 0;

	/** @var int */
	private $target = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->members = new Members();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->target  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Rows the moderation log holds for the target, oldest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function log_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT actor_id, action, space_id, note FROM {$wpdb->prefix}bn_mod_log WHERE target_user_id = %d ORDER BY id",
				$this->target
			),
			ARRAY_A
		);
	}

	/**
	 * Suspending from the Members screen writes a suspend_user row attributed to
	 * the acting admin, with the reason, at site scope.
	 *
	 * @return void
	 */
	public function test_suspend_writes_an_audit_row(): void {
		$this->members->suspend_member( $this->target, 'spam account' );

		$rows = $this->log_rows();
		$this->assertCount( 1, $rows, 'Suspending must write exactly one moderation-log row.' );
		$this->assertSame( 'suspend_user', $rows[0]['action'] );
		$this->assertSame( $this->admin, (int) $rows[0]['actor_id'], 'The acting admin must be recorded as the actor.' );
		$this->assertSame( 0, (int) $rows[0]['space_id'], 'A wp-admin suspension is site-level (space_id 0).' );
		$this->assertSame( 'spam account', (string) $rows[0]['note'], 'The reason must be recorded.' );
	}

	/**
	 * Unsuspending writes its own row, so the log shows who lifted the suspension.
	 *
	 * @return void
	 */
	public function test_unsuspend_writes_an_audit_row(): void {
		$this->members->suspend_member( $this->target, 'x' );
		$this->members->unsuspend_member( $this->target );

		$rows    = $this->log_rows();
		$actions = array_map( static fn( array $r ): string => (string) $r['action'], $rows );
		$this->assertContains( 'unsuspend_user', $actions, 'Lifting a suspension must be recorded.' );
	}
}
