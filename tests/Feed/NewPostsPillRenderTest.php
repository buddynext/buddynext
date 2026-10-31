<?php
/**
 * The new-posts pill's posts are rendered by the server, on top and once.
 *
 * The pill used to inject them in the browser, and an injected post card is never
 * hydrated: React and Save on it did nothing, with no error.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * @coversNothing Covers templates/feed/home.php.
 */
class NewPostsPillRenderTest extends WP_UnitTestCase {

	private int $viewer;

	private int $author;

	private int $older;

	private int $newest;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->viewer = self::factory()->user->create();
		$this->author = self::factory()->user->create();
		$friend       = self::factory()->user->create();
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_connections',
			array(
				'requester_id' => $this->viewer,
				'recipient_id' => $friend,
				'status'       => 'accepted',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$posts = new PostService();
		// For you ranks a connection above a stranger, so without the pill the
		// stranger's newer post sits below this one. That is the case the pill exists for.
		$this->older  = (int) $posts->create( $friend, $this->post_args( 'older' ) );
		$this->newest = (int) $posts->create( $this->author, $this->post_args( 'newest' ) );
	}

	public function tear_down(): void {
		unset( $_GET['new_after'], $_GET['cursor'] );
		parent::tear_down();
	}

	/**
	 * @return array<string,string>
	 */
	private function post_args( string $content, string $privacy = 'public' ): array {
		return array(
			'content' => $content,
			'privacy' => $privacy,
			'type'    => 'text',
		);
	}

	/**
	 * @return int[] Post ids in rendered order.
	 */
	private function rendered_ids( int $viewer ): array {
		wp_set_current_user( $viewer );
		ob_start();
		buddynext_get_template( 'feed/home.php', array() );
		$html = (string) ob_get_clean();
		preg_match_all( '/<article[^>]*data-post-id="(\d+)"/', $html, $matches );
		return array_map( 'intval', $matches[1] );
	}

	public function test_the_new_post_leads_the_list_exactly_once(): void {
		$this->assertSame( $this->older, $this->rendered_ids( $this->viewer )[0], 'fixture: ranked below without the pill' );

		$_GET['new_after'] = (string) $this->older;
		$ids               = $this->rendered_ids( $this->viewer );

		$this->assertSame( $this->newest, $ids[0] );
		$this->assertCount( 1, array_keys( $ids, $this->newest, true ), 'not also drawn at its ranked place' );
	}

	public function test_a_post_the_viewer_may_not_see_is_not_revealed(): void {
		$private           = (int) ( new PostService() )->create( $this->author, $this->post_args( 'secret', 'private' ) );
		$_GET['new_after'] = (string) $this->older;

		$this->assertNotContains( $private, $this->rendered_ids( $this->viewer ) );
	}

	public function test_no_param_and_junk_change_nothing(): void {
		$plain             = $this->rendered_ids( $this->viewer );
		$_GET['new_after'] = 'not-a-number';

		$this->assertSame( $plain, $this->rendered_ids( $this->viewer ) );
	}
}
