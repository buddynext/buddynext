<?php
/**
 * A scheduled post goes live only if its author could post it right now.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\ScheduledPostsPublisher;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Feed\ScheduledPostsPublisher::publish_due
 */
class ScheduledPublishAuthorTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Seed a due scheduled post.
	 *
	 * @param int $user_id  Author.
	 * @param int $space_id Space (0 for the feed).
	 * @return int Post id.
	 */
	private function due_post( int $user_id, int $space_id = 0 ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'      => $user_id,
				'space_id'     => $space_id,
				'type'         => 'text',
				'content'      => 'Queued before anything went wrong.',
				'status'       => 'scheduled',
				'privacy'      => 'public',
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read a post's status.
	 *
	 * @param int $post_id Post.
	 * @return string
	 */
	private function status_of( int $post_id ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ) );
	}

	/**
	 * A suspended author's due post goes back to drafts; a member in good standing's goes live.
	 *
	 * @return void
	 */
	public function test_a_suspended_authors_scheduled_post_does_not_go_live(): void {
		$admin     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$suspended = self::factory()->user->create();
		$fine      = self::factory()->user->create();
		$bad_post  = $this->due_post( $suspended );
		$good_post = $this->due_post( $fine );
		$this->assertIsInt( buddynext_service( 'moderation' )->suspend_user( $suspended, $admin, 'Repeated spam links', array( 'duration_days' => 7 ) ) );

		ScheduledPostsPublisher::publish_due();

		$this->assertSame( 'draft', $this->status_of( $bad_post ), 'A suspended member\'s scheduled post was published.' );
		$this->assertSame( 'published', $this->status_of( $good_post ), 'A member in good standing lost a scheduled post.' );
	}

	/**
	 * A member banned from the Space after scheduling a post into it does not get that post published there.
	 *
	 * @return void
	 */
	public function test_a_banned_members_scheduled_space_post_does_not_go_live(): void {
		$owner  = self::factory()->user->create();
		$author = self::factory()->user->create();
		$space  = (int) ( new SpaceService() )->create(
			$owner,
			array(
				'name' => 'Trail Runners',
				'slug' => 'trail-runners-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$members = new SpaceMemberService();
		$members->join( $space, $author );
		$queued = $this->due_post( $author, $space );
		$other  = $this->due_post( $owner, $space );

		$this->assertTrue( true === $members->ban_from_space( $space, $author, $owner, 'Spam' ) );

		ScheduledPostsPublisher::publish_due();

		$this->assertSame( 'draft', $this->status_of( $queued ), 'A banned member\'s scheduled post went live in the Space.' );
		$this->assertSame( 'published', $this->status_of( $other ), 'The Space owner\'s scheduled post was held back.' );
	}
}
