<?php
/**
 * Tests for the threaded comment data shape consumed by buildCommentNode().
 *
 * Wave 2 introduced a depth-aware comment-tree UI in store.js. The DB
 * service already returned items + nested replies under the spec
 * (CommentService::list two-level tree), but the controller had to be
 * extended to ship author_name, like_count, viewer_liked, can_edit, can_
 * delete, and can_pin alongside each comment so the JS does not need a
 * second round-trip to decide which action buttons to render. These
 * tests pin the contract so future schema changes can't silently break
 * the threaded UI.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentController;
use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Comments\CommentController::list_comments
 * @covers \BuddyNext\Comments\CommentService::list
 */
class CommentTreeRenderTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_three_deep_thread_returns_two_level_tree(): void {
		$author_id = self::factory()->user->create( array( 'display_name' => 'Tree Author' ) );
		$post_id   = ( new PostService() )->create(
			$author_id,
			array( 'content' => 'parent', 'privacy' => 'public', 'type' => 'text' )
		);
		$this->assertIsInt( $post_id );

		$svc = new CommentService();

		$top_id   = $svc->create( $author_id, 'post', $post_id, 'top-level comment' );
		$reply_id = $svc->create( $author_id, 'post', $post_id, 'first reply', $top_id );
		// Third level: still attached to top_id so the two-level tree flattens it.
		$svc->create( $author_id, 'post', $post_id, 'second reply', $top_id );

		$this->assertIsInt( $top_id );
		$this->assertIsInt( $reply_id );

		$result = $svc->list( 'post', $post_id );

		$this->assertCount( 1, $result['items'], 'Only one top-level comment expected.' );
		$top = $result['items'][0];
		$this->assertSame( 'top-level comment', $top['content'] );
		$this->assertCount( 2, $top['replies'], 'Two replies should be attached under the parent.' );
	}

	public function test_list_response_carries_action_capability_flags(): void {
		$author_id = self::factory()->user->create();
		$viewer_id = self::factory()->user->create();
		wp_set_current_user( $viewer_id );

		$post_id = ( new PostService() )->create(
			$author_id,
			array( 'content' => 'parent', 'privacy' => 'public', 'type' => 'text' )
		);

		$svc        = new CommentService();
		$comment_id = $svc->create( $viewer_id, 'post', $post_id, 'my comment' );
		$this->assertIsInt( $comment_id );

		$controller = new CommentController();
		$request    = new WP_REST_Request( 'GET', '/buddynext/v1/comments' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post_id );

		$response = $controller->list_comments( $request );
		$data     = $response->get_data();

		$this->assertNotEmpty( $data['items'] );
		$first = $data['items'][0];

		$this->assertArrayHasKey( 'author_name', $first );
		$this->assertArrayHasKey( 'author_avatar_url', $first );
		$this->assertArrayHasKey( 'like_count', $first );
		$this->assertArrayHasKey( 'viewer_liked', $first );
		$this->assertArrayHasKey( 'can_edit', $first );
		$this->assertArrayHasKey( 'can_delete', $first );
		$this->assertArrayHasKey( 'can_pin', $first );

		// Viewer owns the comment, so they can edit + delete it.
		$this->assertTrue( $first['can_edit'] );
		$this->assertTrue( $first['can_delete'] );
		// Viewer is not a moderator (no manage_options), so can_pin = false.
		$this->assertFalse( $first['can_pin'] );
	}

	public function test_other_user_cannot_edit_or_pin_comment(): void {
		$author_id     = self::factory()->user->create();
		$commenter_id  = self::factory()->user->create();
		$stranger_id   = self::factory()->user->create();
		wp_set_current_user( $stranger_id );

		$post_id = ( new PostService() )->create(
			$author_id,
			array( 'content' => 'parent', 'privacy' => 'public', 'type' => 'text' )
		);

		$svc = new CommentService();
		$svc->create( $commenter_id, 'post', $post_id, 'comment by someone else' );

		$controller = new CommentController();
		$request    = new WP_REST_Request( 'GET', '/buddynext/v1/comments' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $post_id );

		$data  = $controller->list_comments( $request )->get_data();
		$first = $data['items'][0];

		$this->assertFalse( $first['can_edit'] );
		$this->assertFalse( $first['can_delete'] );
		$this->assertFalse( $first['can_pin'] );
	}
}
