<?php
/**
 * Toggle + idempotency + cache invalidation tests for FollowService.
 *
 * Complements FollowServiceTest by exercising the production toggle flow
 * (follow → unfollow → follow), idempotent writes, and the cache
 * invalidation contract relied on by the sidebar People-to-Follow widget.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\FollowService;

/**
 * Toggle + idempotency + cache invalidation tests for FollowService.
 *
 * @covers \BuddyNext\SocialGraph\FollowService
 */
class FollowServiceToggleTest extends \WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var FollowService
	 */
	private FollowService $service;

	/**
	 * Alice user ID.
	 *
	 * @var int
	 */
	private int $alice;

	/**
	 * Bob user ID.
	 *
	 * @var int
	 */
	private int $bob;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new FollowService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
	}

	/**
	 * Follow → unfollow → follow returns the relationship to the expected state at each step.
	 *
	 * @return void
	 */
	public function test_toggle_cycle_follows_unfollows_and_refollows(): void {
		$this->assertTrue( $this->service->follow( $this->alice, $this->bob ) );
		$this->assertTrue( $this->service->is_following( $this->alice, $this->bob ) );
		$this->assertSame( 1, $this->service->follower_count( $this->bob ) );

		$this->assertTrue( $this->service->unfollow( $this->alice, $this->bob ) );
		$this->assertFalse( $this->service->is_following( $this->alice, $this->bob ) );
		$this->assertSame( 0, $this->service->follower_count( $this->bob ) );

		$this->assertTrue( $this->service->follow( $this->alice, $this->bob ) );
		$this->assertTrue( $this->service->is_following( $this->alice, $this->bob ) );
		$this->assertSame( 1, $this->service->follower_count( $this->bob ) );
	}

	/**
	 * Unfollowing a non-existent relationship returns false and is safe.
	 *
	 * @return void
	 */
	public function test_unfollow_when_not_following_returns_false_and_does_not_throw(): void {
		$this->assertFalse( $this->service->unfollow( $this->alice, $this->bob ) );
		$this->assertFalse( $this->service->is_following( $this->alice, $this->bob ) );
	}

	/**
	 * Re-calling follow() collapses to a single relationship row.
	 *
	 * @return void
	 */
	public function test_repeated_follow_is_idempotent(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->bob );

		$this->assertSame( 1, $this->service->following_count( $this->alice ) );
		$this->assertSame( 1, $this->service->follower_count( $this->bob ) );
	}

	/**
	 * Following count must reflect the toggle after a cached read.
	 *
	 * @return void
	 */
	public function test_follow_clears_following_count_cache_on_unfollow(): void {
		$this->service->follow( $this->alice, $this->bob );
		$first = $this->service->following_count( $this->alice );
		$this->assertSame( 1, $first );

		$this->service->unfollow( $this->alice, $this->bob );

		$second = $this->service->following_count( $this->alice );
		$this->assertSame( 0, $second );
	}

	/**
	 * Follower count must reflect the toggle after a cached read.
	 *
	 * @return void
	 */
	public function test_follow_clears_follower_count_cache_on_toggle(): void {
		$this->service->follow( $this->alice, $this->bob );
		$this->assertSame( 1, $this->service->follower_count( $this->bob ) );

		$this->service->unfollow( $this->alice, $this->bob );
		$this->assertSame( 0, $this->service->follower_count( $this->bob ) );
	}

	/**
	 * A full toggle cycle emits the right number of follow / unfollow actions.
	 *
	 * @return void
	 */
	public function test_toggle_emits_both_followed_and_unfollowed_actions(): void {
		$followed   = 0;
		$unfollowed = 0;
		add_action(
			'buddynext_user_followed',
			static function () use ( &$followed ): void {
				++$followed;
			}
		);
		add_action(
			'buddynext_user_unfollowed',
			static function () use ( &$unfollowed ): void {
				++$unfollowed;
			}
		);

		$this->service->follow( $this->alice, $this->bob );
		$this->service->unfollow( $this->alice, $this->bob );
		$this->service->follow( $this->alice, $this->bob );

		$this->assertSame( 2, $followed );
		$this->assertSame( 1, $unfollowed );
	}
}
