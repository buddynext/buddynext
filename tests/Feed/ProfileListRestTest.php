<?php
/**
 * REST for the profile lists: /users/{id}/likes, /users/{id}/replies, /me/scheduled-posts.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_REST_Request;

/**
 * The app reads the same lists the profile tabs show, paged the same way, and
 * behind the same gate as the profile itself.
 *
 * @covers \BuddyNext\Feed\FeedController::profile_likes
 * @covers \BuddyNext\Feed\FeedController::profile_replies
 * @covers \BuddyNext\Feed\PostController::my_scheduled_posts
 */
class ProfileListRestTest extends \WP_UnitTestCase {

	/**
	 * Member whose lists are read.
	 *
	 * @var int
	 */
	private int $member = 0;

	/**
	 * Unrelated logged-in viewer.
	 *
	 * @var int
	 */
	private int $stranger = 0;

	/**
	 * Three public posts the member replied to and liked.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		do_action( 'rest_api_init' );

		$author         = self::factory()->user->create();
		$this->member   = self::factory()->user->create();
		$this->stranger = self::factory()->user->create();

		$posts     = new PostService();
		$comments  = new CommentService();
		$reactions = buddynext_service( 'reactions' );
		for ( $i = 0; $i < 3; $i++ ) {
			wp_set_current_user( $author );
			$post_id = (int) $posts->create(
				$author,
				array(
					'type'    => 'text',
					'content' => 'Rest fixture ' . $i . ' with enough words to need trimming in the reply context excerpt',
					'privacy' => 'public',
				)
			);
			wp_set_current_user( $this->member );
			$comments->create( $this->member, 'post', $post_id, 'Reply ' . $i );
			$reactions->react( $this->member, 'post', $post_id, 'like' );
		}
	}

	/**
	 * GET a route as a viewer.
	 *
	 * @param string               $route  Route under buddynext/v1.
	 * @param int                  $viewer Viewer (0 = logged out).
	 * @param array<string, mixed> $params Query params.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function get_as( string $route, int $viewer, array $params = array() ): array {
		wp_set_current_user( $viewer );
		$request = new WP_REST_Request( 'GET', '/buddynext/v1' . $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = rest_get_server()->dispatch( $request );
		return array( (int) $response->get_status(), (array) $response->get_data() );
	}

	/**
	 * Likes and replies page by cursor to the end.
	 *
	 * @return void
	 */
	public function test_likes_and_replies_page_by_cursor(): void {
		foreach ( array( 'likes', 'replies' ) as $list ) {
			list( $status, $first ) = $this->get_as( "/users/{$this->member}/{$list}", 0, array( 'per_page' => 2 ) );
			$this->assertSame( 200, $status, $list );
			$this->assertCount( 2, $first['items'], $list );
			$this->assertIsString( $first['next_cursor'], $list );

			list( , $second ) = $this->get_as(
				"/users/{$this->member}/{$list}",
				0,
				array(
					'per_page' => 2,
					'cursor'   => $first['next_cursor'],
				)
			);
			$this->assertCount( 1, $second['items'], $list );
			$this->assertNull( $second['next_cursor'], $list );
			$this->assertEmpty( array_intersect( wp_list_pluck( $first['items'], 'id' ), wp_list_pluck( $second['items'], 'id' ) ), $list );
		}
	}

	/**
	 * A reply carries the context the web tab shows, never the whole parent post.
	 *
	 * @return void
	 */
	public function test_reply_rows_carry_a_short_post_context(): void {
		list( , $data ) = $this->get_as( "/users/{$this->member}/replies", 0 );
		$row            = $data['items'][0];

		foreach ( array( 'id', 'content', 'content_html', 'created_at', 'post_id', 'post_type', 'post_author_name', 'post_excerpt', 'post_url' ) as $key ) {
			$this->assertArrayHasKey( $key, $row );
		}
		$this->assertLessThanOrEqual( 16, str_word_count( $row['post_excerpt'] ), 'Excerpt is trimmed to 15 words.' );
		$this->assertArrayNotHasKey( 'post_content', $row );
	}

	/**
	 * A profile the viewer may not see answers 404 on its lists, as the profile route does.
	 *
	 * @return void
	 */
	public function test_private_profile_lists_are_not_found_for_strangers(): void {
		buddynext_service( 'privacy' )->set_preference( $this->member, 'profile_visibility', 'private' );

		foreach ( array( 'likes', 'replies' ) as $list ) {
			list( $status ) = $this->get_as( "/users/{$this->member}/{$list}", $this->stranger );
			$this->assertSame( 404, $status, $list . ' for a stranger' );

			list( $own ) = $this->get_as( "/users/{$this->member}/{$list}", $this->member );
			$this->assertSame( 200, $own, $list . ' for the owner' );
		}

		list( $missing ) = $this->get_as( '/users/999999/likes', 0 );
		$this->assertSame( 404, $missing );
	}

	/**
	 * Scheduled posts are the caller's own and need a login.
	 *
	 * @return void
	 */
	public function test_scheduled_posts_need_auth_and_return_a_page(): void {
		list( $guest ) = $this->get_as( '/me/scheduled-posts', 0 );
		$this->assertSame( 401, $guest );

		list( $status, $data ) = $this->get_as( '/me/scheduled-posts', $this->member );
		$this->assertSame( 200, $status );
		$this->assertSame( array(), $data['items'] );
		$this->assertArrayHasKey( 'next_cursor', $data );
	}
}
