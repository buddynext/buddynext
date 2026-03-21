<?php
/**
 * Tests for CommentService.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Comments\CommentService
 */
class CommentServiceTest extends \WP_UnitTestCase {

	private CommentService $service;
	private int $user_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new CommentService();
		$this->user_id = self::factory()->user->create();
		$this->post_id = 1;
	}

	public function test_create_returns_id(): void {
		$id = $this->service->create(
			$this->user_id,
			'post',
			$this->post_id,
			'Hello world'
		);

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_create_empty_content_returns_error(): void {
		$result = $this->service->create(
			$this->user_id,
			'post',
			$this->post_id,
			'   '
		);

		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
	}

	public function test_get_returns_comment(): void {
		$id      = $this->service->create( $this->user_id, 'post', $this->post_id, 'Test comment' );
		$comment = $this->service->get( $id );

		$this->assertNotNull( $comment );
		$this->assertSame( $id, $comment['id'] );
		$this->assertSame( 'Test comment', $comment['content'] );
	}

	public function test_get_returns_null_for_missing(): void {
		$this->assertNull( $this->service->get( 999999 ) );
	}

	public function test_reply_creates_threaded_comment(): void {
		$parent_id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Parent' );
		$reply_id  = $this->service->create( $this->user_id, 'post', $this->post_id, 'Reply', $parent_id );

		$reply = $this->service->get( $reply_id );

		$this->assertSame( $parent_id, $reply['parent_id'] );
	}

	public function test_list_for_object_returns_comments(): void {
		$this->service->create( $this->user_id, 'post', $this->post_id, 'Comment 1' );
		$this->service->create( $this->user_id, 'post', $this->post_id, 'Comment 2' );

		$result = $this->service->list_for_object( 'post', $this->post_id );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertCount( 2, $result['items'] );
	}

	public function test_delete_by_owner(): void {
		$id     = $this->service->create( $this->user_id, 'post', $this->post_id, 'Delete me' );
		$result = $this->service->delete( $id, $this->user_id );

		$this->assertTrue( $result );
		$this->assertNull( $this->service->get( $id ) );
	}

	public function test_delete_by_non_owner_returns_error(): void {
		$id         = $this->service->create( $this->user_id, 'post', $this->post_id, 'Protected' );
		$other_user = self::factory()->user->create();

		$result = $this->service->delete( $id, $other_user );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
	}

	public function test_update_content_by_owner(): void {
		$id     = $this->service->create( $this->user_id, 'post', $this->post_id, 'Original' );
		$result = $this->service->update( $id, $this->user_id, 'Updated' );

		$this->assertTrue( $result );

		$comment = $this->service->get( $id );
		$this->assertSame( 'Updated', $comment['content'] );
	}
}
