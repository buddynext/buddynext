<?php
/**
 * GET /comments/{id} and the stored totals in the write responses.
 *
 * The realtime pill reads new comments one by one, so this route must refuse exactly what
 * the thread refuses. The totals exist because a client adding +1 to a total the socket
 * had already set counted its own comment twice.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Comments\CommentController::get_one
 * @covers \BuddyNext\Comments\CommentThread::visible_one
 */
class SingleCommentReadTest extends WP_UnitTestCase {

	private int $author;

	private int $post_id;

	private CommentService $comments;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );
		$this->author   = self::factory()->user->create();
		$this->comments = new CommentService();
		$this->post_id  = $this->post( 'public' );
	}

	private function post( string $privacy ): int {
		return (int) ( new PostService() )->create(
			$this->author,
			array(
				'content' => 'parent',
				'privacy' => $privacy,
				'type'    => 'text',
			)
		);
	}

	private function read( int $comment_id, int $viewer ): \WP_REST_Response {
		wp_set_current_user( $viewer );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/comments/' . $comment_id );
		$request->set_param( 'render', 'html' );
		return rest_do_request( $request );
	}

	public function test_a_visible_comment_and_a_deep_reply_come_back_with_html(): void {
		$root  = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'root' );
		$reply = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'deep', (int) $this->comments->create( $this->author, 'post', $this->post_id, 'mid', $root ) );

		$response = $this->read( $reply, self::factory()->user->create() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $reply, (int) $response->get_data()['id'] );
		$this->assertStringContainsString( 'data-comment-id="' . $reply . '"', $response->get_data()['html'] );
	}

	public function test_it_refuses_what_the_thread_refuses(): void {
		$viewer  = self::factory()->user->create();
		$blocked = self::factory()->user->create();
		buddynext_service( 'blocks' )->block( $viewer, $blocked );
		$from_blocked = (int) $this->comments->create( $blocked, 'post', $this->post_id, 'blocked author' );
		$this->assertSame( 404, $this->read( $from_blocked, $viewer )->get_status(), 'blocked author' );

		$reply_under_blocked = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'under blocked', $from_blocked );
		$this->assertSame( 404, $this->read( $reply_under_blocked, $viewer )->get_status(), 'reply under a hidden root' );

		global $wpdb;
		$hidden = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'under review' );
		$wpdb->update( $wpdb->prefix . 'bn_comments', array( 'is_hidden' => 1 ), array( 'id' => $hidden ) );
		wp_cache_flush();
		$this->assertSame( 404, $this->read( $hidden, $viewer )->get_status(), 'hidden, plain member' );
		$this->assertSame( 200, $this->read( $hidden, $this->author )->get_status(), 'hidden, its own author' );

		$private    = $this->post( 'private' );
		$on_private = (int) $this->comments->create( $this->author, 'post', $private, 'secret' );
		$this->assertSame( 404, $this->read( $on_private, 0 )->get_status(), 'private post, guest' );
		$this->assertSame( 404, $this->read( $on_private, $viewer )->get_status(), 'private post, member' );

		$this->assertSame( 404, $this->read( 99999999, $viewer )->get_status(), 'missing' );

		$gone = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'gone' );
		$this->comments->delete( $gone, $this->author );
		$this->assertSame( 404, $this->read( $gone, $viewer )->get_status(), 'deleted' );
	}

	public function test_create_and_delete_return_the_stored_total(): void {
		wp_set_current_user( $this->author );
		$create = new WP_REST_Request( 'POST', '/buddynext/v1/comments' );
		foreach ( array(
			'object_type' => 'post',
			'object_id'   => $this->post_id,
			'content'     => 'one',
		) as $key => $value ) {
			$create->set_param( $key, $value );
		}
		$first = rest_do_request( $create )->get_data();
		$this->assertSame( 1, $first['comment_count'] );
		$this->assertSame( 2, rest_do_request( $create )->get_data()['comment_count'] );

		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/buddynext/v1/comments/' . (int) $first['id'] ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['comment_count'] );
	}
}
