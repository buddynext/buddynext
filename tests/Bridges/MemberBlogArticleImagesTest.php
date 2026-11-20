<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * GET /members/{id}/blog items: the article image fields are additive.
 *
 * `cover` stays a string (the 16:9 cover URL) or null for the mobile app;
 * `cover_image` / `thumb_image` add {url,width,height} or null. Their images
 * are primed in one query, not one per row.
 *
 * Runs in separate processes: the tab is live only while Member Blog's version
 * constant is defined, and a constant cannot be undefined again.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\MemberBlogBridge;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\ArticleImage;

/**
 * @covers \BuddyNext\Bridges\MemberBlogBridge
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class MemberBlogArticleImagesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'BUDDYPRESS_MEMBER_BLOG_VERSION' ) ) {
			define( 'BUDDYPRESS_MEMBER_BLOG_VERSION', 'test' );
		}
		Installer::run();
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function test_items_keep_cover_and_add_the_image_objects(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$with   = self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_date'   => '2026-11-02 10:00:00',
			)
		);
		$att    = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $with );
		set_post_thumbnail( $with, $att );
		self::factory()->post->create(
			array(
				'post_author' => $author,
				'post_status' => 'publish',
				'post_date'   => '2026-11-01 10:00:00',
			)
		);

		$items = ( new MemberBlogBridge() )->articles_data( $author, 0 )['items'];
		$this->assertCount( 2, $items );

		$cover = ArticleImage::for_post( $with, 'cover' );
		$this->assertNotNull( $cover );
		$this->assertSame( $cover['url'], $items[0]['cover'], 'cover stays a plain string' );
		$this->assertSame( $cover, $items[0]['cover_image'] );
		$this->assertSame( ArticleImage::for_post( $with, 'thumb' ), $items[0]['thumb_image'] );
		$this->assertSame( 192, $items[0]['thumb_image']['width'] );

		$this->assertNull( $items[1]['cover'], 'no image: cover is null, as before' );
		$this->assertNull( $items[1]['cover_image'] );
		$this->assertNull( $items[1]['thumb_image'] );
	}

	public function test_the_list_reads_images_without_a_query_per_row(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$source = DIR_TESTDATA . '/images/canola.jpg';
		for ( $i = 0; $i < 6; $i++ ) {
			$post = self::factory()->post->create(
				array(
					'post_author' => $author,
					'post_status' => 'publish',
				)
			);
			set_post_thumbnail( $post, (int) self::factory()->attachment->create_upload_object( $source, $post ) );
		}
		wp_cache_flush();

		$bridge = new MemberBlogBridge();
		global $wpdb;
		$before = $wpdb->num_queries;
		$bridge->articles_data( $author, 0, 1, 3 );
		$three = $wpdb->num_queries - $before;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$bridge->articles_data( $author, 0, 1, 6 );
		$six = $wpdb->num_queries - $before;

		$this->assertSame( $three, $six, 'doubling the rows adds no queries' );
	}
}
