<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
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

	public function test_update_by_non_owner_returns_403(): void {
		$id     = $this->service->create( $this->user_id, 'post', $this->post_id, 'Owned by user' );
		$other  = self::factory()->user->create();
		$result = $this->service->update( $id, $other, 'hijack' );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_delete_by_non_owner_returns_403(): void {
		$id     = $this->service->create( $this->user_id, 'post', $this->post_id, 'Owned by user' );
		$other  = self::factory()->user->create();
		$result = $this->service->delete( $id, $other );

		$this->assertWPError( $result );
		$this->assertSame( 'forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
	}

	public function test_update_missing_returns_404(): void {
		$result = $this->service->update( 999999, $this->user_id, 'x' );
		$this->assertWPError( $result );
		$this->assertSame( 404, $result->get_error_data()['status'] ?? null );
	}

	public function test_update_empty_content_returns_400(): void {
		$id     = $this->service->create( $this->user_id, 'post', $this->post_id, 'Owned' );
		$result = $this->service->update( $id, $this->user_id, '   ' );
		$this->assertWPError( $result );
		$this->assertSame( 'empty_content', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? null );
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
		// Soft-delete: row still exists but is_deleted = true and content is blank.
		$comment = $this->service->get( $id );
		$this->assertNotNull( $comment );
		$this->assertTrue( $comment['is_deleted'] );
		$this->assertSame( '', $comment['content'] );
	}

	public function test_update_sets_is_edited(): void {
		$id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Original' );

		$this->service->update( $id, $this->user_id, 'Updated' );

		$comment = $this->service->get( $id );
		$this->assertTrue( $comment['is_edited'] );
	}

	public function test_list_excludes_deleted_comments(): void {
		$this->service->create( $this->user_id, 'post', $this->post_id, 'Visible' );
		$del_id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Deleted' );
		$this->service->delete( $del_id, $this->user_id );

		$result = $this->service->list_for_object( 'post', $this->post_id );

		$contents = array_column( $result['items'], 'content' );
		$this->assertContains( 'Visible', $contents );
		$this->assertNotContains( 'Deleted', $contents );
		$this->assertSame( 1, $result['total'] );
	}

	public function test_comment_rate_limit_blocks_excess(): void {
		update_option( 'buddynext_comment_rate_limit', 2 );

		$this->assertIsInt( $this->service->create( $this->user_id, 'post', $this->post_id, 'one' ) );
		$this->assertIsInt( $this->service->create( $this->user_id, 'post', $this->post_id, 'two' ) );

		$result = $this->service->create( $this->user_id, 'post', $this->post_id, 'three' );
		$this->assertWPError( $result );
		$this->assertSame( 'rate_limited', $result->get_error_code() );

		delete_option( 'buddynext_comment_rate_limit' );
	}

	public function test_deleted_parent_with_reply_keeps_thread(): void {
		$parent_id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Parent' );
		$reply_id  = $this->service->create( $this->user_id, 'post', $this->post_id, 'Surviving reply', $parent_id );

		// Soft-delete the parent — its surviving reply must NOT be orphaned.
		$this->service->delete( $parent_id, $this->user_id );

		$result = $this->service->list( 'post', $this->post_id, array( 'viewer_id' => $this->user_id ) );

		// The deleted parent remains as a top-level tombstone so the reply renders.
		$top_ids = array_map( static fn( $c ): int => (int) $c['id'], $result['items'] );
		$this->assertContains( $parent_id, $top_ids );

		$parent_row = null;
		foreach ( $result['items'] as $item ) {
			if ( (int) $item['id'] === $parent_id ) {
				$parent_row = $item;
				break;
			}
		}
		$this->assertNotNull( $parent_row );
		$reply_ids = array_map( static fn( $c ): int => (int) $c['id'], (array) ( $parent_row['replies'] ?? array() ) );
		$this->assertContains( $reply_id, $reply_ids );
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

	public function test_create_fires_buddynext_comment_created(): void {
		$captured = null;
		add_action(
			'buddynext_comment_created',
			function ( int $comment_id, string $object_type, int $object_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $comment_id, $object_type, $object_id, $user_id );
			},
			10,
			4
		);

		$id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Hello' );

		$this->assertSame( array( $id, 'post', $this->post_id, $this->user_id ), $captured );
	}

	public function test_update_fires_buddynext_comment_updated(): void {
		$captured = null;
		add_action(
			'buddynext_comment_updated',
			function ( int $comment_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $comment_id, $user_id );
			},
			10,
			2
		);

		$id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Original' );
		$this->service->update( $id, $this->user_id, 'Updated' );

		$this->assertSame( array( $id, $this->user_id ), $captured );
	}

	public function test_delete_fires_buddynext_comment_deleted(): void {
		$captured = null;
		add_action(
			'buddynext_comment_deleted',
			function ( int $comment_id, int $user_id ) use ( &$captured ): void {
				$captured = array( $comment_id, $user_id );
			},
			10,
			2
		);

		$id = $this->service->create( $this->user_id, 'post', $this->post_id, 'Delete me' );
		$this->service->delete( $id, $this->user_id );

		$this->assertSame( array( $id, $this->user_id ), $captured );
	}
}
