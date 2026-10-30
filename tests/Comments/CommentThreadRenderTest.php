<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * One renderer for a comment: the page, the list API and the create / update
 * responses all produce the same HTML from templates/parts/comment.php.
 *
 * Before, the browser built every comment from JSON while PHP rendered none, so
 * the two drifted: a pinned comment lost its badge (the script read `pinned`,
 * the API sent `is_pinned`) and an edited comment lost its @mention and #hashtag
 * links (the script wrote the raw text back).
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentService;
use BuddyNext\Comments\CommentThread;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Comments\CommentThread
 * @covers \BuddyNext\Comments\CommentController
 */
class CommentThreadRenderTest extends WP_UnitTestCase {

	private int $author;

	private int $post_id;

	private CommentService $comments;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author   = self::factory()->user->create( array( 'display_name' => 'Thread Author' ) );
		$this->comments = new CommentService();
		$this->post_id  = (int) ( new PostService() )->create(
			$this->author,
			array(
				'content' => 'parent',
				'privacy' => 'public',
				'type'    => 'text',
			)
		);
	}

	private function html_for( int $comment_id, int $viewer ): string {
		$page = ( new CommentThread() )->page( 'post', $this->post_id, $viewer, 1, CommentThread::PAGE_SIZE );
		foreach ( $page['items'] as $item ) {
			if ( (int) $item['id'] === $comment_id ) {
				return CommentThread::render( array( $item ), $this->post_id, $viewer );
			}
		}
		$this->fail( 'comment not on the first page' );
	}

	private function list_html( array $vars ): string {
		ob_start();
		buddynext_get_template( 'parts/post-comments-list.php', $vars );
		return (string) ob_get_clean();
	}

	public function test_pinned_comment_renders_badge_and_unpin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$id    = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'pin me' );
		update_option( 'bn_pinned_comment_post_' . $this->post_id, $id );

		$html = $this->html_for( $id, $admin );

		$this->assertStringContainsString( 'bn-comment-card--pinned', $html );
		$this->assertStringContainsString( 'bn-comment__pinned-badge', $html );
		$this->assertMatchesRegularExpression( '/bn-comment__pin-btn[^>]*aria-label="Unpin"/', $html );
	}

	public function test_mentions_and_hashtags_are_links(): void {
		self::factory()->user->create( array( 'user_login' => 'thread_friend' ) );
		$id = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'Hi @thread_friend #books' );

		$html = $this->html_for( $id, $this->author );

		$this->assertMatchesRegularExpression( '/<a [^>]*>@thread_friend<\/a>/', $html );
		$this->assertMatchesRegularExpression( '/<a [^>]*>#books<\/a>/', $html );
	}

	/**
	 * Card 10458840874: the update response's HTML keeps the links the edit added.
	 */
	public function test_update_with_render_html_returns_linked_content(): void {
		self::factory()->user->create( array( 'user_login' => 'edit_friend' ) );
		$id = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'plain' );
		wp_set_current_user( $this->author );

		$request = new WP_REST_Request( 'PUT', '/buddynext/v1/comments/' . $id );
		$request->set_param( 'content', 'now @edit_friend #books' );
		$request->set_param( 'render', 'html' );
		$data = rest_do_request( $request )->get_data();

		$this->assertMatchesRegularExpression( '/<a [^>]*>@edit_friend<\/a>/', (string) ( $data['content_html'] ?? '' ) );
		$this->assertStringContainsString( 'data-comment-id="' . $id . '"', (string) ( $data['html'] ?? '' ) );
		$this->assertMatchesRegularExpression( '/<a [^>]*>#books<\/a>/', (string) $data['html'] );
	}

	public function test_list_adds_html_only_when_asked(): void {
		$this->comments->create( $this->author, 'post', $this->post_id, 'one' );

		$request = new WP_REST_Request( 'GET', '/buddynext/v1/comments' );
		$request->set_param( 'object_type', 'post' );
		$request->set_param( 'object_id', $this->post_id );
		$this->assertArrayNotHasKey( 'html', rest_do_request( $request )->get_data()['items'][0], 'apps get JSON only' );

		$request->set_param( 'render', 'html' );
		$this->assertStringContainsString( 'bn-comment-card', rest_do_request( $request )->get_data()['items'][0]['html'] );
	}

	public function test_a_comment_the_viewer_reported_reads_reported_after_reload(): void {
		$viewer = self::factory()->user->create();
		$id     = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'report me' );
		buddynext_service( 'moderation' )->report( $viewer, 'comment', $id, 'spam' );

		$this->assertMatchesRegularExpression( '/bn-comment__report-btn"\s+disabled[^>]*aria-label="Reported"/', $this->html_for( $id, $viewer ) );
		$this->assertStringContainsString( 'aria-label="Report this comment"', $this->html_for( $id, self::factory()->user->create() ), 'others can still report it' );
	}

	public function test_guest_gets_no_member_actions(): void {
		$id   = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'hello' );
		$html = $this->html_for( $id, 0 );

		foreach ( array( 'reply', 'edit', 'delete', 'pin', 'report' ) as $action ) {
			$this->assertStringNotContainsString( 'bn-comment__' . $action . '-btn', $html, $action );
		}
	}

	public function test_deleted_comment_keeps_its_place_without_author_or_actions(): void {
		$id = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'secret words' );
		$this->comments->create( $this->author, 'post', $this->post_id, 'a reply', $id );
		$this->comments->delete( $id, $this->author );

		$html = $this->html_for( $id, $this->author );

		$this->assertStringContainsString( 'data-placeholder="1"', $html );
		$this->assertStringNotContainsString( 'secret words', $html );
		$this->assertStringNotContainsString( 'Thread Author</span>', explode( 'bn-comment__replies', $html )[0] );
		$this->assertStringNotContainsString( 'bn-comment__actions', explode( 'bn-comment__replies', $html )[0] );
	}

	public function test_render_data_filter_reaches_the_markup(): void {
		$id = (int) $this->comments->create( $this->author, 'post', $this->post_id, 'original' );
		add_filter(
			'buddynext_comment_render_data',
			static function ( array $comment ): array {
				$comment['content_html'] = 'filtered body';
				return $comment;
			}
		);

		$this->assertStringContainsString( 'filtered body', $this->html_for( $id, 0 ) );
	}

	public function test_post_page_renders_first_page_and_load_more(): void {
		for ( $i = 1; $i <= CommentThread::PAGE_SIZE + 2; $i++ ) {
			$this->comments->create( $this->author, 'post', $this->post_id, 'c' . $i );
		}

		$html = $this->list_html(
			array(
				'bn_post_id' => $this->post_id,
				'viewer_id'  => 0,
				'first_page' => true,
			)
		);

		$this->assertSame( CommentThread::PAGE_SIZE, substr_count( $html, 'data-depth="0"' ) );
		$this->assertStringContainsString( 'bn-comment-loadmore', $html );
		$this->assertStringContainsString( 'data-loaded="1"', $html );

		$closed = $this->list_html( array( 'bn_post_id' => $this->post_id ) );
		$this->assertStringNotContainsString( 'bn-comment-card', $closed, 'the feed renders no comments until opened' );
	}
}
