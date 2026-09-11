<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A report actioned by an automated/system caller records the system (0) as
 * resolved_by, not the admin whose authority the action ran under.
 *
 * The AI moderation sweep runs its actions under an administrator's authority
 * (remove_content needs manage_options) but must be RECORDED as the system, or
 * the moderation queue names a real admin as the person who dismissed/removed a
 * report during a provider outage (card 10264294554). The status methods take an
 * optional $resolved_by that overrides only the recorded actor, never the
 * authorization; null keeps the historical behaviour (record the acting admin).
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::set_status
 */
class ReportSystemActorTest extends \WP_UnitTestCase {

	/** @var ModerationService */
	private $service;

	/** @var int Administrator whose authority the action runs under. */
	private $admin = 0;

	/**
	 * Fresh service + an administrator per test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->service = new ModerationService();
		$this->admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Insert a pending report against a post and return its id.
	 *
	 * @param int $object_id Reported object id.
	 * @return int Report id.
	 */
	private function seed_report( int $object_id ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'object_type' => 'post',
				'object_id'   => $object_id,
				'reporter_id' => self::factory()->user->create(),
				'reason'      => 'spam',
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Read the resolved_by column for a report id.
	 *
	 * @param int $report_id Report id.
	 * @return int|null
	 */
	private function resolved_by( int $report_id ): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT resolved_by FROM {$wpdb->prefix}bn_reports WHERE id = %d", $report_id )
		);
		return null === $value ? null : (int) $value;
	}

	/**
	 * resolved_by = 0 records the system, even though the admin supplied authority.
	 *
	 * @return void
	 */
	public function test_system_resolved_by_records_zero_not_the_admin(): void {
		$report_id = $this->seed_report( 9001 );

		$result = $this->service->escalate( $report_id, $this->admin, 0 );

		$this->assertTrue( $result, 'The admin should be authorised to escalate.' );
		$this->assertSame( 0, $this->resolved_by( $report_id ), 'The report must be stamped with the system (0), not the admin.' );
	}

	/**
	 * Passing no $resolved_by keeps the historical behaviour: the acting admin is
	 * recorded, so a human moderator's action is still attributed to them.
	 *
	 * @return void
	 */
	public function test_null_resolved_by_defaults_to_the_actor(): void {
		$report_id = $this->seed_report( 9002 );

		$this->service->dismiss( $report_id, $this->admin );

		$this->assertSame( $this->admin, $this->resolved_by( $report_id ), 'A human action must record the acting admin.' );
	}

	/**
	 * The reporter's `bn.report_resolved` notification carries sender_id = 0 for a
	 * system/AI action, so the member who reported the post is not shown an admin's
	 * avatar for what the AI did. The $resolved_by seam fixed the log row and the
	 * report row; this pins the third surface — the notification (card 10264294554).
	 *
	 * @return void
	 */
	public function test_system_action_notifies_reporter_with_sender_zero(): void {
		global $wpdb;

		$reporter  = self::factory()->user->create();
		$object_id = 9101;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_reports',
			array(
				'object_type' => 'post',
				'object_id'   => $object_id,
				'reporter_id' => $reporter,
				'reason'      => 'spam',
				'status'      => 'pending',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		$report_id = (int) $wpdb->insert_id;

		// System-attributed dismiss (the AI sweep path: admin authority, recorded 0).
		$this->assertTrue( $this->service->dismiss( $report_id, $this->admin, 0 ), 'System dismiss should succeed.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sender = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT sender_id FROM {$wpdb->prefix}bn_notifications
				 WHERE recipient_id = %d AND type = %s",
				$reporter,
				'bn.report_resolved'
			)
		);

		$this->assertNotNull( $sender, 'The reporter must receive a bn.report_resolved notification.' );
		$this->assertSame( 0, (int) $sender, 'A system/AI action must notify the reporter with sender_id 0, not an admin.' );
	}

	/**
	 * Logging is consolidated into set_status(): a report action writes exactly ONE
	 * bn_mod_log row (no caller double-log), with the canonical slug, the report's
	 * space_id, and the recorded actor — and an AI action (resolved_by 0 + an audit
	 * descriptor) records actor 0 with the ai_ slug (card 10264294456).
	 *
	 * @return void
	 */
	public function test_report_action_logs_exactly_one_row_at_the_seam(): void {
		global $wpdb;

		$human = $this->seed_report( 9401 );
		$this->assertTrue( $this->service->dismiss( $human, $this->admin ), 'Human dismiss should succeed.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$human_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT actor_id, action FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'report' AND object_id = %d", $human ),
			ARRAY_A
		);
		$this->assertCount( 1, $human_rows, 'A dismiss must write exactly one audit row (no caller double-log).' );
		$this->assertSame( 'dismiss_report', $human_rows[0]['action'] );
		$this->assertSame( $this->admin, (int) $human_rows[0]['actor_id'] );

		// AI-style: authority = admin, recorded = 0, with an ai_ audit descriptor.
		$ai = $this->seed_report( 9402 );
		$this->assertTrue(
			$this->service->dismiss( $ai, $this->admin, 0, array( 'action' => 'ai_dismiss', 'note' => 'auto' ) ),
			'System dismiss should succeed.'
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ai_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT actor_id, action FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'report' AND object_id = %d", $ai ),
			ARRAY_A
		);
		$this->assertCount( 1, $ai_rows, 'An AI dismiss must write exactly one audit row.' );
		$this->assertSame( 'ai_dismiss', $ai_rows[0]['action'], 'The AI action slug is preserved.' );
		$this->assertSame( 0, (int) $ai_rows[0]['actor_id'], 'The AI action records the system actor (0).' );
	}

	/**
	 * Re-actioning an already-actioned report returns already_resolved rather than
	 * true — the signal the AI sweep guards log_ai() on so a re-swept, already
	 * 'escalated' report writes no duplicate audit row every cadence.
	 *
	 * @return void
	 */
	public function test_reactioning_returns_already_resolved(): void {
		$report_id = $this->seed_report( 9003 );

		$first  = $this->service->escalate( $report_id, $this->admin, 0 );
		$second = $this->service->escalate( $report_id, $this->admin, 0 );

		$this->assertTrue( $first, 'First escalate should succeed.' );
		$this->assertInstanceOf( \WP_Error::class, $second, 'Re-escalating must not report a second success.' );
		$this->assertSame( 'already_resolved', $second->get_error_code(), 'The distinct signal the sweep guards on.' );
	}
}
