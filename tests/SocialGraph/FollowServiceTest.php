<?php
/**
 * Tests for FollowService.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\FollowService;

/**
 * @covers \BuddyNext\SocialGraph\FollowService
 */
class FollowServiceTest extends \WP_UnitTestCase {

	private FollowService $service;
	private int $alice;
	private int $bob;
	private int $carol;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new FollowService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->carol   = self::factory()->user->create();
	}

	public function test_follow_creates_relationship(): void {
		$result = $this->service->follow( $this->alice, $this->bob );

		$this->assertTrue( $result );
		$this->assertTrue( $this->service->is_following( $this->alice, $this->bob ) );
	}

	public function test_follow_is_asymmetric(): void {
		$this->service->follow( $this->alice, $this->bob );

		$this->assertTrue( $this->service->is_following( $this->alice, $this->bob ) );
		$this->assertFalse( $this->service->is_following( $this->bob, $this->alice ) );
	}

	public function test_cannot_follow_self(): void {
		$result = $this->service->follow( $this->alice, $this->alice );

		$this->assertWPError( $result );
		$this->assertSame( 'cannot_follow_self', $result->get_error_code() );
	}

	public function test_follow_fires_action(): void {
		$fired = array();
		add_action(
			'buddynext_user_followed',
			function ( int $follower, int $following ) use ( &$fired ): void {
				$fired[] = array( $follower, $following );
			},
			10,
			2
		);

		$this->service->follow( $this->alice, $this->bob );

		$this->assertCount( 1, $fired );
		$this->assertSame( array( $this->alice, $this->bob ), $fired[0] );
	}

	public function test_unfollow_removes_relationship(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->unfollow( $this->alice, $this->bob );

		$this->assertFalse( $this->service->is_following( $this->alice, $this->bob ) );
	}

	public function test_unfollow_fires_action(): void {
		$fired = false;
		add_action(
			'buddynext_user_unfollowed',
			function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$this->service->follow( $this->alice, $this->bob );
		$this->service->unfollow( $this->alice, $this->bob );

		$this->assertTrue( $fired );
	}

	public function test_followers_returns_list(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->carol, $this->bob );

		$followers = $this->service->followers( $this->bob );

		$this->assertContains( $this->alice, $followers );
		$this->assertContains( $this->carol, $followers );
	}

	public function test_following_returns_list(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->carol );

		$following = $this->service->following( $this->alice );

		$this->assertContains( $this->bob, $following );
		$this->assertContains( $this->carol, $following );
	}

	public function test_follower_count(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->carol, $this->bob );

		$this->assertSame( 2, $this->service->follower_count( $this->bob ) );
	}

	public function test_following_count(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->carol );

		$this->assertSame( 2, $this->service->following_count( $this->alice ) );
	}

	public function test_duplicate_follow_is_safe(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->bob );

		$this->assertSame( 1, $this->service->following_count( $this->alice ) );
	}

	public function test_suggestions_returns_friends_of_friends(): void {
		// Alice follows Bob, Bob follows Carol — Carol should be in Alice's suggestions.
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->bob, $this->carol );

		$suggestions = $this->service->suggestions( $this->alice );

		$this->assertContains( $this->carol, $suggestions );
		$this->assertNotContains( $this->alice, $suggestions );
		$this->assertNotContains( $this->bob, $suggestions );
	}
}
