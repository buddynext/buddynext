<?php
/**
 * Tests for ModerationService.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService
 */
class ModerationServiceTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $admin_id;
	private int $user_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service  = new ModerationService();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_id  = self::factory()->user->create();
		$this->post_id  = 42;
	}

	public function test_report_creates_record(): void {
		$id = $this->service->report(
			$this->user_id,
			'post',
			$this->post_id,
			'spam'
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_duplicate_report_from_same_user_returns_error(): void {
		$this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$result = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );

		$this->assertWPError( $result );
		$this->assertSame( 'already_reported', $result->get_error_code() );
	}

	public function test_dismiss_changes_status(): void {
		global $wpdb;
		$report_id = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );

		$this->service->dismiss( $report_id, $this->admin_id );

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_reports WHERE id = %d",
				$report_id
			)
		);

		$this->assertSame( 'dismissed', $status );
	}

	public function test_dismiss_by_non_admin_returns_error(): void {
		$report_id = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );

		$other = self::factory()->user->create();
		$result = $this->service->dismiss( $report_id, $other );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_escalate_changes_status(): void {
		global $wpdb;
		$report_id = $this->service->report( $this->user_id, 'post', $this->post_id, 'harassment' );

		$this->service->escalate( $report_id, $this->admin_id );

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_reports WHERE id = %d",
				$report_id
			)
		);

		$this->assertSame( 'escalated', $status );
	}

	public function test_resolve_changes_status(): void {
		global $wpdb;
		$report_id = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );

		$this->service->resolve( $report_id, $this->admin_id );

		$status = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT status FROM {$wpdb->prefix}bn_reports WHERE id = %d",
				$report_id
			)
		);

		$this->assertSame( 'resolved', $status );
	}

	public function test_get_reports_for_object(): void {
		$other_user = self::factory()->user->create();
		$this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$this->service->report( $other_user, 'post', $this->post_id, 'harassment' );

		$reports = $this->service->get_reports_for_object( 'post', $this->post_id );

		$this->assertCount( 2, $reports );
	}

	public function test_issue_strike_creates_record(): void {
		$id = $this->service->issue_strike( $this->user_id, $this->admin_id, 'Violation of community guidelines' );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_issue_strike_by_non_admin_returns_error(): void {
		$other = self::factory()->user->create();
		$result = $this->service->issue_strike( $this->user_id, $other, 'test' );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_reverse_strike(): void {
		global $wpdb;
		$strike_id = $this->service->issue_strike( $this->user_id, $this->admin_id, 'test' );

		$this->service->reverse_strike( $strike_id, $this->admin_id );

		$reversed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_reversed FROM {$wpdb->prefix}bn_user_strikes WHERE id = %d",
				$strike_id
			)
		);

		$this->assertSame( 1, $reversed );
	}

	public function test_get_user_strike_count(): void {
		$this->service->issue_strike( $this->user_id, $this->admin_id, 'first' );
		$this->service->issue_strike( $this->user_id, $this->admin_id, 'second' );

		$count = $this->service->get_active_strike_count( $this->user_id );

		$this->assertSame( 2, $count );
	}

	public function test_reversed_strike_not_counted(): void {
		$strike_id = $this->service->issue_strike( $this->user_id, $this->admin_id, 'test' );
		$this->service->reverse_strike( $strike_id, $this->admin_id );

		$this->assertSame( 0, $this->service->get_active_strike_count( $this->user_id ) );
	}

	public function test_get_queue_returns_pending_reports(): void {
		$other = self::factory()->user->create();
		$this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$this->service->report( $other, 'post', $this->post_id, 'harassment' );

		$result = $this->service->get_queue();

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 2, $result['total'] );
	}

	public function test_get_queue_excludes_non_pending(): void {
		$other      = self::factory()->user->create();
		$report_id  = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$this->service->report( $other, 'post', $this->post_id, 'harassment' );

		// Dismiss one — should not appear in queue.
		$this->service->dismiss( $report_id, $this->admin_id );

		$result = $this->service->get_queue();

		$this->assertSame( 1, $result['total'] );
	}

	public function test_get_queue_filters_by_object_type(): void {
		$this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$this->service->report( $this->user_id, 'user', 99, 'harassment' );

		$result = $this->service->get_queue( array( 'object_type' => 'post' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'post', $result['items'][0]['object_type'] );
	}

	public function test_get_queue_filters_by_reason(): void {
		$other = self::factory()->user->create();
		$this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );
		$this->service->report( $other, 'post', $this->post_id + 1, 'harassment' );

		$result = $this->service->get_queue( array( 'reason' => 'spam' ) );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( 'spam', $result['items'][0]['reason'] );
	}

	public function test_get_queue_paginates(): void {
		$users = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$users[] = self::factory()->user->create();
			$this->service->report( $users[ $i ], 'post', $this->post_id + $i, 'spam' );
		}

		$result = $this->service->get_queue( array( 'per_page' => 2, 'page' => 1 ) );

		$this->assertSame( 5, $result['total'] );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_report_fires_buddynext_report_created(): void {
		$captured = null;
		add_action(
			'buddynext_report_created',
			function ( int $report_id, string $object_type, int $object_id, int $reporter_id ) use ( &$captured ): void {
				$captured = array( $report_id, $object_type, $object_id, $reporter_id );
			},
			10,
			4
		);

		$id = $this->service->report( $this->user_id, 'post', $this->post_id, 'spam' );

		$this->assertSame( array( $id, 'post', $this->post_id, $this->user_id ), $captured );
	}

	// ── BLOCK 2: suspend / unsuspend / appeal ─────────────────────────────

	/**
	 * suspend_user() creates a row in bn_user_suspensions and returns its ID.
	 */
	public function test_suspend_user_creates_suspension_record(): void {
		global $wpdb;

		$suspension_id = $this->service->suspend_user(
			$this->user_id,
			$this->admin_id,
			'Repeated violations',
			array( 'duration_days' => 7 )
		);

		$this->assertIsInt( $suspension_id );
		$this->assertGreaterThan( 0, $suspension_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_user_suspensions WHERE id = %d",
				$suspension_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( (string) $this->user_id, $row['user_id'] );
		$this->assertSame( (string) $this->admin_id, $row['suspended_by'] );
		$this->assertSame( 'Repeated violations', $row['reason'] );
		$this->assertSame( '7', $row['duration_days'] );
	}

	/**
	 * suspend_user() by non-admin returns WP_Error.
	 */
	public function test_suspend_user_by_non_admin_returns_error(): void {
		$other  = self::factory()->user->create();
		$result = $this->service->suspend_user( $this->user_id, $other, 'test', array() );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * suspend_user() fires buddynext_member_suspended hook.
	 */
	public function test_suspend_user_fires_hook(): void {
		$captured = null;
		add_action(
			'buddynext_member_suspended',
			function ( int $user_id, int $by_user_id ) use ( &$captured ): void {
				$captured = array( $user_id, $by_user_id );
			},
			10,
			2
		);

		$this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );

		$this->assertSame( array( $this->user_id, $this->admin_id ), $captured );
	}

	/**
	 * unsuspend_user() sets lifted_at on the active suspension.
	 */
	public function test_unsuspend_user_lifts_active_suspension(): void {
		global $wpdb;

		$suspension_id = $this->service->suspend_user(
			$this->user_id,
			$this->admin_id,
			'test',
			array()
		);

		$result = $this->service->unsuspend_user( $this->user_id, $this->admin_id );

		$this->assertTrue( $result );

		$lifted_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT lifted_at FROM {$wpdb->prefix}bn_user_suspensions WHERE id = %d",
				$suspension_id
			)
		);

		$this->assertNotNull( $lifted_at );
	}

	/**
	 * unsuspend_user() by non-admin returns WP_Error.
	 */
	public function test_unsuspend_user_by_non_admin_returns_error(): void {
		$other  = self::factory()->user->create();
		$result = $this->service->unsuspend_user( $this->user_id, $other );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * unsuspend_user() fires buddynext_member_unsuspended hook.
	 */
	public function test_unsuspend_user_fires_hook(): void {
		$captured = null;
		add_action(
			'buddynext_member_unsuspended',
			function ( int $user_id, int $by_user_id ) use ( &$captured ): void {
				$captured = array( $user_id, $by_user_id );
			},
			10,
			2
		);

		$this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$this->service->unsuspend_user( $this->user_id, $this->admin_id );

		$this->assertSame( array( $this->user_id, $this->admin_id ), $captured );
	}

	/**
	 * submit_appeal() creates a row in bn_appeals linked to the suspension.
	 */
	public function test_submit_appeal_creates_record(): void {
		global $wpdb;

		$suspension_id = $this->service->suspend_user(
			$this->user_id,
			$this->admin_id,
			'test',
			array()
		);

		$appeal_id = $this->service->submit_appeal( $this->user_id, $suspension_id, 'I did nothing wrong.' );

		$this->assertIsInt( $appeal_id );
		$this->assertGreaterThan( 0, $appeal_id );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}bn_appeals WHERE id = %d",
				$appeal_id
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( (string) $this->user_id, $row['user_id'] );
		$this->assertSame( (string) $suspension_id, $row['suspension_id'] );
		$this->assertSame( 'I did nothing wrong.', $row['message'] );
		$this->assertSame( 'pending', $row['status'] );
	}

	/**
	 * submit_appeal() by a user who is not suspended returns WP_Error.
	 */
	public function test_submit_appeal_for_non_existent_suspension_returns_error(): void {
		$result = $this->service->submit_appeal( $this->user_id, 9999, 'test' );

		$this->assertWPError( $result );
		$this->assertSame( 'not_suspended', $result->get_error_code() );
	}

	/**
	 * submit_appeal() fires buddynext_appeal_submitted hook.
	 */
	public function test_submit_appeal_fires_hook(): void {
		$captured = null;
		add_action(
			'buddynext_appeal_submitted',
			function ( int $appeal_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $appeal_id, $user_id );
			},
			10,
			2
		);

		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'Please reconsider.' );

		$this->assertSame( array( $appeal_id, $this->user_id ), $captured );
	}

	/**
	 * resolve_appeal() updates the appeal status and reviewer fields.
	 */
	public function test_resolve_appeal_updates_record(): void {
		global $wpdb;

		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );

		$result = $this->service->resolve_appeal( $appeal_id, $this->admin_id, 'approved', 'Accepted.' );

		$this->assertTrue( $result );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT status, reviewed_by, reviewer_note FROM {$wpdb->prefix}bn_appeals WHERE id = %d",
				$appeal_id
			),
			ARRAY_A
		);

		$this->assertSame( 'approved', $row['status'] );
		$this->assertSame( (string) $this->admin_id, $row['reviewed_by'] );
		$this->assertSame( 'Accepted.', $row['reviewer_note'] );
	}

	/**
	 * resolve_appeal() by non-admin returns WP_Error.
	 */
	public function test_resolve_appeal_by_non_admin_returns_error(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );
		$other         = self::factory()->user->create();

		$result = $this->service->resolve_appeal( $appeal_id, $other, 'approved', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	/**
	 * resolve_appeal() rejects unknown decision values.
	 */
	public function test_resolve_appeal_with_invalid_decision_returns_error(): void {
		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );

		$result = $this->service->resolve_appeal( $appeal_id, $this->admin_id, 'banana', '' );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_decision', $result->get_error_code() );
	}

	/**
	 * resolve_appeal() fires buddynext_appeal_resolved hook.
	 */
	public function test_resolve_appeal_fires_hook(): void {
		$captured = null;
		add_action(
			'buddynext_appeal_resolved',
			function ( int $appeal_id, int $user_id, string $decision ) use ( &$captured ): void {
				$captured = array( $appeal_id, $user_id, $decision );
			},
			10,
			3
		);

		$suspension_id = $this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$appeal_id     = $this->service->submit_appeal( $this->user_id, $suspension_id, 'test' );
		$this->service->resolve_appeal( $appeal_id, $this->admin_id, 'denied', 'Upheld.' );

		$this->assertSame( array( $appeal_id, $this->user_id, 'denied' ), $captured );
	}

	/**
	 * is_suspended() returns true for a currently suspended user.
	 */
	public function test_is_suspended_returns_true_when_suspended(): void {
		$this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );

		$this->assertTrue( $this->service->is_suspended( $this->user_id ) );
	}

	/**
	 * is_suspended() returns false after the user is unsuspended.
	 */
	public function test_is_suspended_returns_false_after_unsuspend(): void {
		$this->service->suspend_user( $this->user_id, $this->admin_id, 'test', array() );
		$this->service->unsuspend_user( $this->user_id, $this->admin_id );

		$this->assertFalse( $this->service->is_suspended( $this->user_id ) );
	}
}
