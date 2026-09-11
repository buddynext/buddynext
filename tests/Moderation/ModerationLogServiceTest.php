<?php
/**
 * Tests for ModerationLogService.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationLogService;

/**
 * @covers \BuddyNext\Moderation\ModerationLogService
 */
class ModerationLogServiceTest extends \WP_UnitTestCase {

	private ModerationLogService $service;
	private int $admin_id;
	private int $target_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service   = new ModerationLogService();
		$this->admin_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->target_id = self::factory()->user->create();
	}

	public function test_log_creates_entry(): void {
		$id = $this->service->log(
			$this->admin_id,
			'dismiss_report',
			array(
				'object_type'    => 'post',
				'object_id'      => 10,
				'target_user_id' => $this->target_id,
				'note'           => 'False positive',
			)
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_log_without_optional_fields(): void {
		$id = $this->service->log( $this->admin_id, 'issue_strike' );

		$this->assertGreaterThan( 0, $id );
	}

	public function test_get_log_for_user_returns_entries(): void {
		$this->service->log( $this->admin_id, 'dismiss_report', array( 'target_user_id' => $this->target_id ) );
		$this->service->log( $this->admin_id, 'issue_strike', array( 'target_user_id' => $this->target_id ) );

		$entries = $this->service->get_log_for_user( $this->target_id );

		$this->assertCount( 2, $entries );
	}

	public function test_get_log_for_user_returns_empty_for_unknown(): void {
		$entries = $this->service->get_log_for_user( 999999 );

		$this->assertEmpty( $entries );
	}

	public function test_get_log_for_object_returns_entries(): void {
		$this->service->log(
			$this->admin_id,
			'remove_content',
			array(
				'object_type' => 'post',
				'object_id'   => 77,
			)
		);

		$entries = $this->service->get_log_for_object( 'post', 77 );

		$this->assertCount( 1, $entries );
		$this->assertSame( 'remove_content', $entries[0]['action'] );
	}

	/**
	 * Filtering by the canonical 'warn' slug must also return rows written under
	 * the legacy 'warned' slug — the single dropdown option can never hide the
	 * other's rows (card 10284912236 item 1).
	 *
	 * @return void
	 */
	public function test_get_log_action_filter_expands_aliases(): void {
		$actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->service->log( $actor, 'warn', array( 'target_user_id' => $this->target_id ) );
		$this->service->log( $actor, 'warned', array( 'target_user_id' => $this->target_id ) );
		$this->service->log( $actor, 'issue_strike', array( 'target_user_id' => $this->target_id ) );

		// Scope to this test's actor so the append-only table's prior rows do not
		// interfere; the point is that the single 'warn' filter matches BOTH slugs.
		$result  = $this->service->get_log( array( 'action' => 'warn', 'actor_id' => $actor ) );
		$actions = wp_list_pluck( $result['items'], 'action' );

		$this->assertSame( 2, $result['total'] );
		$this->assertContains( 'warn', $actions );
		$this->assertContains( 'warned', $actions );
		$this->assertNotContains( 'issue_strike', $actions );
	}

	/**
	 * A non-aliased slug still filters to exactly itself.
	 *
	 * @return void
	 */
	public function test_get_log_action_filter_without_alias_is_exact(): void {
		$actor = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->service->log( $actor, 'issue_strike', array( 'target_user_id' => $this->target_id ) );
		$this->service->log( $actor, 'warn', array( 'target_user_id' => $this->target_id ) );

		$result  = $this->service->get_log( array( 'action' => 'issue_strike', 'actor_id' => $actor ) );
		$actions = wp_list_pluck( $result['items'], 'action' );

		$this->assertSame( 1, $result['total'] );
		$this->assertSame( array( 'issue_strike' ), $actions );
	}

	/**
	 * actor_id = 0 is a VALID, explicit filter — "what did the AI/system do?" — not
	 * "no filter". The old actor_id > 0 gate read 0 as unfiltered and returned the
	 * whole table (card 10264294456).
	 *
	 * @return void
	 */
	public function test_get_log_actor_zero_returns_only_system_rows(): void {
		$this->service->log( 0, 'ai_dismiss', array( 'object_type' => 'post', 'object_id' => 1 ) );
		$this->service->log( 0, 'ai_remove_content', array( 'object_type' => 'post', 'object_id' => 2 ) );
		$this->service->log( $this->admin_id, 'dismiss_report', array( 'object_type' => 'report', 'object_id' => 3 ) );

		$system = $this->service->get_log( array( 'actor_id' => 0 ) );
		$this->assertSame( 2, $system['total'], 'actor_id 0 must return only the two system rows.' );
		foreach ( $system['items'] as $row ) {
			$this->assertSame( 0, $row['actor_id'], 'Every row must be a system (actor 0) row.' );
		}

		$this->assertSame( 1, $this->service->get_log( array( 'actor_id' => $this->admin_id ) )['total'], 'A specific actor filters to their rows.' );

		// No actor filter returns strictly MORE than the actor-0 slice — proving 0 is
		// a real filter value, not the "return everything" the old > 0 gate produced.
		$this->assertGreaterThan(
			$system['total'],
			$this->service->get_log( array() )['total'],
			'Omitting actor_id must return more than the actor-0 filter.'
		);
	}
}
