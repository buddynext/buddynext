<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Tests that the hashtag feed ships the same enriched item shape as every
 * other post-returning surface.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\REST\Router;
use WP_REST_Request;
use WP_REST_Server;

/**
 * @covers \BuddyNext\Hashtags\HashtagController
 */
class HashtagFeedEnrichmentTest extends \WP_UnitTestCase {

	private static WP_REST_Server $server;
	private int $author;
	private int $viewer;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		self::$server   = $wp_rest_server;
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$this->author = self::factory()->user->create();
		$this->viewer = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Regression: the hashtag feed returned raw bn_posts rows — no author, no
	 * content_html, no viewer_state, no media — while Home/space/Bookmarks
	 * shipped the enriched card. The app rendered its "Community Member"
	 * fallback for every tag-page post. All post-returning surfaces must go
	 * through the same enrichment.
	 */
	public function test_hashtag_feed_items_are_enriched(): void {
		$post_id = ( new PostService() )->create(
			$this->author,
			array(
				'type'    => 'text',
				'content' => 'Enrichment check #shapecheck',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $post_id );

		// The extract/sync listener is not wired in the unit bootstrap — link
		// the tag directly, exactly as HashtagServiceTest does.
		( new \BuddyNext\Hashtags\HashtagService() )->sync( 'post', $post_id, array( 'shapecheck' ) );

		wp_set_current_user( $this->viewer );
		$response = self::$server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/hashtags/shapecheck/feed' ) );
		$this->assertSame( 200, $response->get_status() );

		$items = $response->get_data()['items'];
		$this->assertNotEmpty( $items, 'The tagged post must be on its tag feed.' );

		$item = $items[0];
		$this->assertSame( $post_id, $item['id'] );
		$this->assertArrayHasKey( 'author', $item, 'Hashtag items must carry the enriched author object.' );
		$this->assertSame( $this->author, (int) $item['author']['id'] );
		$this->assertArrayHasKey( 'content_html', $item );
		$this->assertArrayHasKey( 'viewer_state', $item );
		$this->assertArrayHasKey( 'media', $item );
	}

	/**
	 * The Following tab: sort=following returns only posts by people the viewer
	 * follows, as the web hashtag page does; latest returns everyone's.
	 *
	 * @return void
	 */
	public function test_sort_following_matches_the_web_tab(): void {
		$stranger = self::factory()->user->create();
		$mine     = (int) ( new PostService() )->create( $this->author, array( 'content' => 'Followed #sorttab' ) );
		$theirs   = (int) ( new PostService() )->create( $stranger, array( 'content' => 'Not followed #sorttab' ) );
		// The extract/sync listener is not wired in the unit bootstrap (see above).
		$tags = new \BuddyNext\Hashtags\HashtagService();
		$tags->sync( 'post', $mine, array( 'sorttab' ) );
		$tags->sync( 'post', $theirs, array( 'sorttab' ) );
		buddynext_service( 'follows' )->follow( $this->viewer, $this->author );
		wp_set_current_user( $this->viewer );

		$ids = static function ( string $sort ): array {
			$request = new WP_REST_Request( 'GET', '/buddynext/v1/hashtags/sorttab/feed' );
			$request->set_param( 'sort', $sort );
			return array_map( 'intval', wp_list_pluck( self::$server->dispatch( $request )->get_data()['items'], 'id' ) );
		};

		$this->assertSame( array( $mine ), $ids( 'following' ) );
		$this->assertCount( 2, $ids( 'latest' ) );
	}
}
