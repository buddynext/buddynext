<?php
/**
 * Tests for BlockService.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\BlockService;

/**
 * @covers \BuddyNext\SocialGraph\BlockService
 */
class BlockServiceTest extends \WP_UnitTestCase {

	private BlockService $service;
	private int $alice;
	private int $bob;
	private int $carol;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new BlockService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->carol   = self::factory()->user->create();
	}

	public function test_block_creates_relationship(): void {
		$result = $this->service->block( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertTrue( $this->service->is_blocked( $this->alice, $this->bob ) );
	}

	public function test_block_is_asymmetric(): void {
		$this->service->block( $this->alice, $this->bob );

		$this->assertTrue( $this->service->is_blocked( $this->alice, $this->bob ) );
		$this->assertFalse( $this->service->is_blocked( $this->bob, $this->alice ) );
	}

	public function test_cannot_block_self(): void {
		$result = $this->service->block( $this->alice, $this->alice );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_block_self', $result->get_error_code() );
	}

	public function test_unblock_removes_relationship(): void {
		$this->service->block( $this->alice, $this->bob );
		$this->service->unblock( $this->alice, $this->bob );

		$this->assertFalse( $this->service->is_blocked( $this->alice, $this->bob ) );
	}

	public function test_mute_creates_mute_relationship(): void {
		$result = $this->service->mute( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertTrue( $this->service->is_muted( $this->alice, $this->bob ) );
	}

	public function test_mute_does_not_count_as_block(): void {
		$this->service->mute( $this->alice, $this->bob );

		$this->assertFalse( $this->service->is_blocked( $this->alice, $this->bob ) );
	}

	public function test_unmute_removes_mute(): void {
		$this->service->mute( $this->alice, $this->bob );
		$this->service->unmute( $this->alice, $this->bob );

		$this->assertFalse( $this->service->is_muted( $this->alice, $this->bob ) );
	}

	public function test_block_fires_buddynext_block(): void {
		$captured = null;
		add_action(
			'buddynext_block',
			function ( int $blocker_id, int $blocked_id ) use ( &$captured ): void {
				$captured = array( $blocker_id, $blocked_id );
			},
			10,
			2
		);

		$this->service->block( $this->alice, $this->bob );

		$this->assertSame( array( $this->alice, $this->bob ), $captured );
	}

	public function test_unblock_fires_buddynext_unblock(): void {
		$captured = null;
		add_action(
			'buddynext_unblock',
			function ( int $blocker_id, int $blocked_id ) use ( &$captured ): void {
				$captured = array( $blocker_id, $blocked_id );
			},
			10,
			2
		);

		$this->service->block( $this->alice, $this->bob );
		$this->service->unblock( $this->alice, $this->bob );

		$this->assertSame( array( $this->alice, $this->bob ), $captured );
	}

	public function test_is_blocking_either_direction(): void {
		$this->service->block( $this->alice, $this->bob );

		$this->assertTrue( $this->service->is_blocking_either( $this->alice, $this->bob ) );
		$this->assertTrue( $this->service->is_blocking_either( $this->bob, $this->alice ) );
		$this->assertFalse( $this->service->is_blocking_either( $this->alice, $this->carol ) );
	}

	public function test_blocked_users_returns_list(): void {
		$this->service->block( $this->alice, $this->bob );
		$this->service->block( $this->alice, $this->carol );

		$blocked = $this->service->blocked_users( $this->alice );

		$this->assertContains( $this->bob, $blocked );
		$this->assertContains( $this->carol, $blocked );
	}

	public function test_duplicate_block_is_safe(): void {
		$this->service->block( $this->alice, $this->bob );
		$result = $this->service->block( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->service->blocked_users( $this->alice ) );
	}
}
