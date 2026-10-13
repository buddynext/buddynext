<?php
/**
 * A profile shows at most three pinned posts and loses none.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;

/**
 * Profile pin ceiling tests.
 *
 * @covers \BuddyNext\Feed\FeedService
 * @covers \BuddyNext\Feed\PostService::pin
 */
class ProfilePinCeilingTest extends \WP_UnitTestCase {

	/**
	 * Post service.
	 *
	 * @var PostService
	 */
	private PostService $posts;

	/**
	 * Feed service.
	 *
	 * @var FeedService
	 */
	private FeedService $feed;

	/**
	 * Profile owner.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts  = new PostService();
		$this->feed   = new FeedService( new FollowService(), $this->posts );
		$this->member = self::factory()->user->create();
	}

	/**
	 * Create a public post by the member.
	 *
	 * @return int
	 */
	private function post(): int {
		$id = $this->posts->create(
			$this->member,
			array(
				'type'    => 'text',
				'content' => 'Post ' . wp_rand(),
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $id );

		return (int) $id;
	}

	/**
	 * Nobody pins a fourth post, whatever limit a plan or filter returns.
	 *
	 * @return void
	 */
	public function test_fourth_pin_is_refused_even_with_no_plan_limit(): void {
		add_filter( 'buddynext_post_pin_limit', '__return_zero' );
		$ids = array( $this->post(), $this->post(), $this->post(), $this->post() );

		foreach ( array_slice( $ids, 0, 3 ) as $id ) {
			$this->assertTrue( $this->posts->pin( $id, $this->member ) );
		}
		$fourth = $this->posts->pin( $ids[3], $this->member );
		remove_filter( 'buddynext_post_pin_limit', '__return_zero' );

		$this->assertWPError( $fourth );
		$this->assertSame( 'pin_limit_reached', $fourth->get_error_code() );
	}

	/**
	 * Pins held from before the ceiling: three lead, the rest stay in the list.
	 *
	 * @return void
	 */
	public function test_extra_pins_from_before_the_ceiling_stay_reachable(): void {
		global $wpdb;
		$ids = array( $this->post(), $this->post(), $this->post(), $this->post(), $this->post() );
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_posts SET is_pinned = 1 WHERE id IN ( " . implode( ',', $ids ) . ' )' );
		wp_cache_flush();

		$pinned = array_map( static fn( array $p ): int => (int) $p['id'], $this->feed->profile_pinned_posts( $this->member, 0 ) );
		$dated  = array_map( 'intval', array_column( $this->feed->profile_feed( $this->member, 0 )['items'], 'id' ) );

		$this->assertCount( 3, $pinned, 'Three lead the profile.' );
		$this->assertSame( array(), array_intersect( $pinned, $dated ), 'A shown pin is not repeated below.' );
		$all = array_merge( $pinned, $dated );
		sort( $all );
		sort( $ids );
		$this->assertSame( $ids, $all, 'Every post is in one of the two lists.' );
	}
}
