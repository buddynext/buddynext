<?php
/**
 * Tests for automated (system-actor) moderation sanctions.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * An automatic sanction must actually apply, and be attributed to the system.
 *
 * @covers \BuddyNext\Moderation\ModerationService::suspend
 */
class AutomatedSanctionTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ModerationService
	 */
	private ModerationService $service;

	/**
	 * Install the schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
	}

	/**
	 * The strike-threshold path suspends the member instead of doing nothing.
	 *
	 * suspended_by is BIGINT UNSIGNED NOT NULL and this path wrote NULL into it,
	 * so MySQL rejected the INSERT ("Column 'suspended_by' cannot be null") and
	 * every automatic sanction was a no-op. This primitive is reached ONLY from
	 * the automated path, which always passes actor 0 — so it never worked.
	 *
	 * @return void
	 */
	public function test_system_suspension_is_actually_applied(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( $this->service->is_suspended( $user_id ), 'precondition' );

		$result = $this->service->suspend( $user_id, 'Automatic suspension: strike threshold reached.', 0, false, 0 );

		$this->assertNotWPError( $result );
		$this->assertTrue( $this->service->is_suspended( $user_id ) );
	}

	/**
	 * The system actor is stored as 0, matching the sibling column.
	 *
	 * bn_user_suspensions has two actor columns and they disagreed: lifted_by
	 * coerced a missing actor to 0, suspended_by coerced the same case to NULL.
	 * 0 is this codebase's system-actor convention.
	 *
	 * @return void
	 */
	public function test_system_actor_is_stored_as_zero(): void {
		global $wpdb;

		$user_id = self::factory()->user->create();
		$this->service->suspend( $user_id, 'system sanction', 0, false, 0 );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT suspended_by FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id = %d", $user_id ),
			ARRAY_A
		);

		$this->assertNotNull( $row, 'the suspension row must exist' );
		$this->assertSame( '0', (string) $row['suspended_by'] );
	}

	/**
	 * A system sanction is never attributed to whoever happened to trigger it.
	 *
	 * The actor fell back to get_current_user_id(), so an automatic ban was
	 * credited in the append-only audit trail to the member whose request tripped
	 * the threshold — often the offender themselves.
	 *
	 * @return void
	 */
	public function test_system_sanction_is_not_attributed_to_the_ambient_user(): void {
		global $wpdb;

		$bystander = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $bystander );

		$user_id = self::factory()->user->create();
		$this->service->suspend( $user_id, 'automatic', 0, false, 0 );

		$actor = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT actor_id FROM {$wpdb->prefix}bn_mod_log WHERE target_user_id = %d ORDER BY id DESC LIMIT 1",
				$user_id
			)
		);

		$this->assertSame( '0', (string) $actor, 'a system sanction is logged as the system, not the current user' );
		$this->assertNotSame( (string) $bystander, (string) $actor );
	}

	/**
	 * A human suspension still records the human.
	 *
	 * @return void
	 */
	public function test_a_human_actor_is_preserved(): void {
		global $wpdb;

		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user_id = self::factory()->user->create();

		$this->service->suspend( $user_id, 'manual', 0, false, $admin );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT suspended_by FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id = %d", $user_id ),
			ARRAY_A
		);

		$this->assertSame( (string) $admin, (string) $row['suspended_by'] );
	}
}
