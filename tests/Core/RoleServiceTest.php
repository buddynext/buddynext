<?php
/**
 * Tests for the community role and credits service.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;
use BuddyNext\Core\RoleService;

/**
 * Verifies role read/write, role hierarchy helpers, and credit operations.
 *
 * @covers \BuddyNext\Core\RoleService
 */
class RoleServiceTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var RoleService
	 */
	private RoleService $service;

	/**
	 * A plain subscriber user created for each test.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a fresh service and user before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new RoleService();
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	// ── Role helpers ──────────────────────────────────────────────────────────

	/**
	 * A new user has the default community role of 'member'.
	 */
	public function test_get_role_defaults_to_member(): void {
		$this->assertSame( 'member', $this->service->get_role( $this->user_id ) );
	}

	/**
	 * Calling set_role() persists the new role to usermeta.
	 */
	public function test_set_role_persists_value(): void {
		$this->service->set_role( $this->user_id, 'moderator' );
		$this->assertSame( 'moderator', $this->service->get_role( $this->user_id ) );
	}

	/**
	 * Calling set_role() fires the buddynext_role_changed action.
	 */
	public function test_set_role_fires_action(): void {
		$fired = false;
		add_action(
			'buddynext_role_changed',
			function () use ( &$fired ) {
				$fired = true;
			}
		);
		$this->service->set_role( $this->user_id, 'admin' );
		$this->assertTrue( $fired );
	}

	/**
	 * Calling is_admin() returns true only for admin role.
	 */
	public function test_is_admin_returns_true_for_admin_role(): void {
		$this->service->set_role( $this->user_id, 'admin' );
		$this->assertTrue( $this->service->is_admin( $this->user_id ) );
	}

	/**
	 * Calling is_admin() returns false for a non-admin role.
	 */
	public function test_is_admin_returns_false_for_member(): void {
		$this->assertFalse( $this->service->is_admin( $this->user_id ) );
	}

	/**
	 * Calling is_moderator() returns true for moderator role.
	 */
	public function test_is_moderator_returns_true_for_moderator(): void {
		$this->service->set_role( $this->user_id, 'moderator' );
		$this->assertTrue( $this->service->is_moderator( $this->user_id ) );
	}

	/**
	 * Calling is_moderator() returns true for admin role (admin >= moderator).
	 */
	public function test_is_moderator_returns_true_for_admin(): void {
		$this->service->set_role( $this->user_id, 'admin' );
		$this->assertTrue( $this->service->is_moderator( $this->user_id ) );
	}

	/**
	 * Calling is_moderator() returns false for plain member.
	 */
	public function test_is_moderator_returns_false_for_member(): void {
		$this->assertFalse( $this->service->is_moderator( $this->user_id ) );
	}

	// ── Credit helpers ────────────────────────────────────────────────────────

	/**
	 * A user with no credit row has a balance of 0.
	 */
	public function test_get_credits_returns_zero_for_new_user(): void {
		$this->assertSame( 0, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Calling add_credits() increases the balance.
	 */
	public function test_add_credits_increases_balance(): void {
		$this->service->add_credits( $this->user_id, 100 );
		$this->assertSame( 100, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Calling add_credits() twice accumulates both amounts.
	 */
	public function test_add_credits_accumulates(): void {
		$this->service->add_credits( $this->user_id, 50 );
		$this->service->add_credits( $this->user_id, 30 );
		$this->assertSame( 80, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Calling spend_credits() deducts from balance and returns true on success.
	 */
	public function test_spend_credits_deducts_balance(): void {
		$this->service->add_credits( $this->user_id, 100 );
		$result = $this->service->spend_credits( $this->user_id, 40, 'test' );
		$this->assertTrue( $result );
		$this->assertSame( 60, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Calling spend_credits() returns false when balance is insufficient.
	 */
	public function test_spend_credits_returns_false_when_insufficient(): void {
		$this->service->add_credits( $this->user_id, 10 );
		$result = $this->service->spend_credits( $this->user_id, 50, 'test' );
		$this->assertFalse( $result );
		$this->assertSame( 10, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Calling spend_credits() fires buddynext_credits_spent on success.
	 */
	public function test_spend_credits_fires_action(): void {
		$this->service->add_credits( $this->user_id, 100 );
		$fired = false;
		add_action(
			'buddynext_credits_spent',
			function () use ( &$fired ) {
				$fired = true;
			}
		);
		$this->service->spend_credits( $this->user_id, 10, 'test' );
		$this->assertTrue( $fired );
	}

	/**
	 * Spending the exact balance succeeds and leaves zero.
	 */
	public function test_spend_credits_exact_balance_succeeds(): void {
		$this->service->add_credits( $this->user_id, 75 );
		$result = $this->service->spend_credits( $this->user_id, 75, 'exact' );
		$this->assertTrue( $result );
		$this->assertSame( 0, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * A second spend reads the DB-updated balance (not a stale value), so it is
	 * rejected once the first spend has drawn the balance down — the behaviour
	 * the atomic conditional UPDATE guarantees under concurrency.
	 */
	public function test_sequential_spends_cannot_overdraw(): void {
		$this->service->add_credits( $this->user_id, 100 );

		$this->assertTrue( $this->service->spend_credits( $this->user_id, 60, 'first' ) );
		$this->assertFalse( $this->service->spend_credits( $this->user_id, 60, 'second' ) );
		$this->assertSame( 40, $this->service->get_credits( $this->user_id ) );
	}

	/**
	 * Deducting more than the balance floors at zero and never goes negative.
	 */
	public function test_deduct_credits_floors_at_zero(): void {
		$this->service->add_credits( $this->user_id, 30 );
		$this->service->deduct_credits( $this->user_id, 50 );
		$this->assertSame( 0, $this->service->get_credits( $this->user_id ) );
	}
}
