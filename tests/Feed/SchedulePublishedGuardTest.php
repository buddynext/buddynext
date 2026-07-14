<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A published post cannot be "scheduled" back out of the feed.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * A LIVE bug, not a future trap.
 *
 * The set_schedule() method wrote status="scheduled" unconditionally. The route that reaches it —
 * POST /posts/{id}/schedule — is guarded only by post_owner_permission, and its caller checks
 * ownership and that the datetime is in the future. Neither checks what the post currently IS.
 *
 * So any post owner could take a post that is already LIVE, "schedule" it, and have it silently
 * pulled out of the feed. That is reachable today with a hand-crafted call, on a shipped route.
 *
 * Scheduling is a transition from not-yet-public. Draft and scheduled qualify; published does
 * not.
 *
 * @covers \BuddyNext\Feed\PostService::set_schedule
 */
class SchedulePublishedGuardTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * Seed a post in a given status.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function seed_post( string $status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => self::factory()->user->create(),
				'type'    => 'text',
				'content' => 'Already out in the world.',
				'status'  => $status,
				'privacy' => 'public',
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read the stored status.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private function status_of( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id )
		);
	}

	/**
	 * A PUBLISHED post cannot be scheduled back out of the feed.
	 *
	 * @return void
	 */
	public function test_a_published_post_cannot_be_unpublished_by_scheduling_it(): void {
		$post_id = $this->seed_post( 'published' );
		$future  = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );

		$result = ( new PostService() )->set_schedule( $post_id, $future );

		$this->assertFalse( $result, 'set_schedule() accepted a published post.' );
		$this->assertSame(
			'published',
			$this->status_of( $post_id ),
			'A LIVE post was pulled out of the feed by "scheduling" it. Any post owner can do this today with a hand-crafted call to POST /posts/{id}/schedule.'
		);
	}

	/**
	 * A draft can still be scheduled — the guard must not refuse everything.
	 *
	 * @return void
	 */
	public function test_a_draft_can_still_be_scheduled(): void {
		$post_id = $this->seed_post( 'draft' );
		$future  = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );

		$this->assertTrue( ( new PostService() )->set_schedule( $post_id, $future ) );
		$this->assertSame( 'scheduled', $this->status_of( $post_id ) );
	}

	/**
	 * An already-scheduled post can be RE-scheduled. This is the other half of the card.
	 *
	 * @return void
	 */
	public function test_a_scheduled_post_can_be_rescheduled(): void {
		$post_id = $this->seed_post( 'scheduled' );
		$later   = gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) );

		$this->assertTrue(
			( new PostService() )->set_schedule( $post_id, $later ),
			'A scheduled post cannot be moved to a new time. The author is stuck with the date they first picked.'
		);
		$this->assertSame( 'scheduled', $this->status_of( $post_id ) );
	}
}
