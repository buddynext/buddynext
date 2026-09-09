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
