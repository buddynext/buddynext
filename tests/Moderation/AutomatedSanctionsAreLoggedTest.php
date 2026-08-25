<?php
/**
 * Automated sanctions must land in the append-only moderation log, the same way
 * manual ones do. The bare suspend() primitive (the strike-threshold escalation
 * path) previously wrote bn_user_suspensions but no bn_mod_log entry, so
 * strike-driven bans were invisible in the audit trail.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::suspend
 */
class AutomatedSanctionsAreLoggedTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $member = 0;
	private int $admin  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
		$this->member  = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin   = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * @param int $target Target user.
	 * @return array<int, array<string, mixed>>
	 */
	private function log_rows_for( int $target ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT action, actor_id, target_user_id FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'user' AND object_id = %d",
				$target
			),
			ARRAY_A
		);
	}

	public function test_strike_suspension_writes_an_audit_entry(): void {
		$this->assertSame( array(), $this->log_rows_for( $this->member ), 'Fixture started with a log row.' );

		// The strike escalator calls the bare suspend() primitive.
		$ok = $this->service->suspend( $this->member, 'Automatic suspension: strike threshold reached.', 0, false, $this->admin );
		$this->assertTrue( $ok );

		$rows = $this->log_rows_for( $this->member );
		$this->assertCount( 1, $rows, 'The automated suspension must write exactly one audit entry.' );
		$this->assertSame( 'suspend', $rows[0]['action'] );
		$this->assertSame( $this->member, (int) $rows[0]['target_user_id'] );
	}

	public function test_strike_permanent_ban_is_logged_as_perma_ban(): void {
		$this->service->suspend( $this->member, 'Automatic permanent ban.', 0, true, $this->admin );

		$rows = $this->log_rows_for( $this->member );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'perma_ban', $rows[0]['action'] );
	}
}
