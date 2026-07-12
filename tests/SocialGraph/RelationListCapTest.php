<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The "give me every relation" reads must be bounded — and loud when they truncate.
 *
 * WHY THIS EXISTS (card 10086819064)
 *
 * `followers()` and `following()` both do `SELECT ... FROM bn_follows WHERE ... ` with NO LIMIT,
 * cache the whole list, and return it.
 *
 * `following()` is soft-bounded by a WRITE cap (`buddynext_max_following`, default 5,000) — but
 * that cap is filterable, and a site can set it to 0 for unlimited.
 *
 * `followers()` has NO ceiling and cannot have one: <strong>you do not control who follows you</strong>.
 * A popular account genuinely has 100k+ followers. It has zero production callers today, which is
 * the only reason it is not already hurting anyone — and that is exactly what makes it a landmine.
 * It looks like a perfectly reasonable API to call.
 *
 * WHY NOT JUST DELETE followers()?
 *
 * Because it fires `apply_filters( 'buddynext_followers', $result, $user_id )` — a public extension
 * point an integrator may already be hooked on. Deleting the method silently kills the filter. So
 * the method stays, the seam stays, and the unbounded read goes.
 *
 * WHY NOT JUST ADD A LIMIT?
 *
 * Because a silent cap on a "give me everything" API is the worst outcome: the caller gets a
 * partial answer and believes it is complete. When the cap bites, we say so via `_doing_it_wrong()`
 * and point at the paged sibling.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\FollowService;
use WP_UnitTestCase;

/**
 * Bounded relation lists.
 */
class RelationListCapTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * The member at the centre of the graph.
	 *
	 * @var int
	 */
	private int $hub;

	/**
	 * Fresh service, one hub member.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->follows = new FollowService();
		$this->hub     = (int) $this->factory->user->create();

		wp_cache_flush();
	}

	/**
	 * Give the hub N followers.
	 *
	 * @param int $n How many.
	 * @return void
	 */
	private function give_followers( int $n ): void {
		for ( $i = 0; $i < $n; $i++ ) {
			$follower = (int) $this->factory->user->create();
			$this->assertTrue(
				true === $this->follows->follow( $follower, $this->hub ),
				'the follower fixture must actually be created'
			);
		}
	}

	/**
	 * The followers() read must never return more than the cap.
	 *
	 * EXPECTED TO FAIL: there is no cap, so all 5 come back.
	 *
	 * @return void
	 */
	public function test_followers_is_bounded_by_the_cap(): void {
		add_filter( 'buddynext_relation_list_cap', static fn(): int => 3 );

		$this->give_followers( 5 );

		// Truncating is EXPECTED here, and it must be announced — see
		// test_truncation_is_announced_not_silent. Declaring it is what proves the notice fires.
		$this->setExpectedIncorrectUsage( 'BuddyNext\SocialGraph\FollowService::followers' );

		$this->assertCount(
			3,
			$this->follows->followers( $this->hub ),
			'followers() has NO LIMIT. Followers are the one relation that cannot be capped on write — '
			. 'you do not control who follows you — so a popular account can have 100k+, and this read '
			. 'would load every one of them into a PHP array.'
		);
	}

	/**
	 * The following() read must never return more than the cap either.
	 *
	 * Its write cap (buddynext_max_following) is filterable to 0 = unlimited, so it is not a real
	 * ceiling on the READ.
	 *
	 * @return void
	 */
	public function test_following_is_bounded_by_the_cap(): void {
		add_filter( 'buddynext_relation_list_cap', static fn(): int => 3 );

		for ( $i = 0; $i < 5; $i++ ) {
			$target = (int) $this->factory->user->create();
			$this->assertTrue( true === $this->follows->follow( $this->hub, $target ), 'follow fixture' );
		}

		$this->setExpectedIncorrectUsage( 'BuddyNext\SocialGraph\FollowService::following' );

		$this->assertCount(
			3,
			$this->follows->following( $this->hub ),
			'following() has no read-side LIMIT, and its write cap is filterable to 0 (unlimited).'
		);
	}

	/**
	 * The cap must be LOUD, not silent.
	 *
	 * A partial answer that claims to be complete is worse than a slow query: the caller cannot
	 * tell. When the cap bites, _doing_it_wrong() fires and names the paged alternative.
	 *
	 * @return void
	 */
	public function test_truncation_is_announced_not_silent(): void {
		add_filter( 'buddynext_relation_list_cap', static fn(): int => 2 );

		$this->give_followers( 4 );

		$this->setExpectedIncorrectUsage( 'BuddyNext\SocialGraph\FollowService::followers' );

		$this->follows->followers( $this->hub );
	}

	/**
	 * Staying under the cap must NOT warn — the guard is for the pathological case only.
	 *
	 * @return void
	 */
	public function test_a_normal_sized_list_does_not_warn(): void {
		add_filter( 'buddynext_relation_list_cap', static fn(): int => 50 );

		$this->give_followers( 3 );

		$this->assertCount( 3, $this->follows->followers( $this->hub ), 'a small list comes back whole' );
	}

	/**
	 * The public filter seam must survive.
	 *
	 * This is the whole reason followers() is bounded rather than deleted: an integrator may be
	 * hooked on `buddynext_followers`, and removing the method would silently kill their filter.
	 *
	 * @return void
	 */
	public function test_the_public_filter_seam_still_fires(): void {
		$this->give_followers( 2 );

		$fired = false;
		add_filter(
			'buddynext_followers',
			static function ( array $ids ) use ( &$fired ): array {
				$fired = true;

				return $ids;
			}
		);

		$this->follows->followers( $this->hub );

		$this->assertTrue(
			$fired,
			'buddynext_followers is a public extension point. Bounding the read must not remove the seam.'
		);
	}
}
