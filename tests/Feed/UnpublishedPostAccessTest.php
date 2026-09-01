<?php
/**
 * A post that was never published must not be readable, engageable or shareable.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;
use WP_REST_Request;

/**
 * PostService::visibility_error() is the single gate every post surface reads, and
 * it had four gates that all consulted `privacy` and none that consulted `status`.
 * So a post was gated as though it had been published when it never had been: any
 * logged-in member could read, react to, comment on, share and bookmark another
 * member's draft, a post held for pre-moderation, one auto-hidden by the report
 * threshold, and one its author had deleted.
 *
 * Sharing was the sharp end. The share wrapper copies the original's `privacy` but
 * not its `status`, so resharing an unpublished post produced a PUBLISHED, PUBLIC
 * post carrying its content - a member could republish someone else's draft, or
 * lift a post back out of moderation, without any capability at all.
 *
 * The engagement WRITE endpoints were the ones missing the check: every READ
 * endpoint on these controllers already called the gate. That asymmetry is the
 * reason the helper now lives on BaseRestController.
 *
 * @covers \BuddyNext\Feed\PostService::visibility_error
 * @covers \BuddyNext\REST\BaseRestController::engagement_target_error
 * @covers \BuddyNext\Feed\FeedService::explore_feed
 */
class UnpublishedPostAccessTest extends \WP_UnitTestCase {

	/**
	 * Every status a post can hold that is not "published".
	 *
	 * @var string[]
	 */
	private const UNPUBLISHED = array( 'pending', 'draft', 'scheduled', 'under_review', 'deleted' );

	private int $author;
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author = self::factory()->user->create();
		$this->viewer = self::factory()->user->create();
	}

	/**
	 * @param string $status Post status.
	 * @return int
	 */
	private function seed_post( string $status ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->author,
				'type'       => 'text',
				'content'    => 'Content of a ' . $status . ' post',
				'status'     => $status,
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Status codes for every engagement surface, as the viewer.
	 *
	 * @param int $post_id Post.
	 * @return array<string,int>
	 */
	private function surface_statuses( int $post_id ): array {
		wp_set_current_user( $this->viewer );

		$react = new WP_REST_Request( 'POST', '/buddynext/v1/reactions/toggle' );
		$react->set_param( 'object_type', 'post' );
		$react->set_param( 'object_id', $post_id );
		$react->set_param( 'emoji', 'like' );

		$comment = new WP_REST_Request( 'POST', '/buddynext/v1/comments' );
		$comment->set_param( 'object_type', 'post' );
		$comment->set_param( 'object_id', $post_id );
		$comment->set_param( 'content', 'a comment' );

		return array(
			'get'      => rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/posts/' . $post_id ) )->get_status(),
			'react'    => rest_do_request( $react )->get_status(),
			'comment'  => rest_do_request( $comment )->get_status(),
			'share'    => rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/share' ) )->get_status(),
			'bookmark' => rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $post_id . '/bookmark' ) )->get_status(),
		);
	}

	public function test_no_surface_serves_an_unpublished_post_to_another_member(): void {
		foreach ( self::UNPUBLISHED as $status ) {
			$post_id = $this->seed_post( $status );

			foreach ( $this->surface_statuses( $post_id ) as $surface => $code ) {
				// 404, not 403: a draft's existence is the author's business, and 403
				// confirms what the read gate is refusing to disclose.
				$this->assertSame(
					404,
					$code,
					sprintf( 'A %s post must not be reachable via %s.', $status, $surface )
				);
			}
		}
	}

	public function test_the_author_still_reaches_their_own_unpublished_post(): void {
		foreach ( self::UNPUBLISHED as $status ) {
			$post_id = $this->seed_post( $status );
			wp_set_current_user( $this->author );

			$this->assertSame(
				200,
				rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/posts/' . $post_id ) )->get_status(),
				sprintf( 'The author must still be able to open their own %s post.', $status )
			);
		}
	}

	public function test_a_published_post_is_unaffected(): void {
		$post_id = $this->seed_post( 'published' );

		$expected = array(
			'get'      => 200,
			'react'    => 200,
			'comment'  => 201,
			'share'    => 200,
			'bookmark' => 200,
		);

		$this->assertSame(
			$expected,
			$this->surface_statuses( $post_id ),
			'The gate must not cost a published post any of its engagement surfaces.'
		);
	}

	public function test_sharing_cannot_republish_an_unpublished_post(): void {
		// The wrapper copies privacy and not status, so this was a laundering route
		// out of moderation and out of the drafts table alike.
		$held = $this->seed_post( 'pending' );
		wp_set_current_user( $this->viewer );

		rest_do_request( new WP_REST_Request( 'POST', '/buddynext/v1/posts/' . $held . '/share' ) );

		global $wpdb;
		$this->assertSame(
			'0',
			(string) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE shared_post_id = %d", $held )
			),
			'No share row may be created for a post that was never published.'
		);
	}

	public function test_explore_lists_only_published_posts(): void {
		$hidden    = array();
		$published = $this->seed_post( 'published' );

		foreach ( self::UNPUBLISHED as $status ) {
			$hidden[] = $this->seed_post( $status );
		}

		$feed = ( new FeedService( new FollowService(), new PostService() ) )->explore_feed( null, 50 );
		$ids  = array_map( 'intval', wp_list_pluck( (array) ( $feed['items'] ?? array() ), 'id' ) );

		$this->assertContains( $published, $ids );

		foreach ( $hidden as $post_id ) {
			// Explore is the one feed a logged-out visitor can reach, and it carried
			// the scheduled_at window but no status filter - which reads as covered.
			$this->assertNotContains( $post_id, $ids, 'Explore must list published posts only.' );
		}
	}
}
