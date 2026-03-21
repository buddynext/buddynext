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
}
