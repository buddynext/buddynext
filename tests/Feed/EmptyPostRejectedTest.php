<?php
/**
 * A post has to actually say something.
 *
 * POST /posts with an empty body returned 201 and wrote a real, published,
 * entirely blank row. The composer's client-side check was the only thing
 * preventing it, so any direct API caller walked straight past it - a script,
 * the app mid-bug, or anyone wanting to fill the feed with nothing.
 *
 * The interesting half of this is what must STILL be allowed: a photo post, a
 * shared link and a poll are all legitimately text-empty, so a naive "content
 * is required" check would break three real composer flows. Those cases carry
 * as much weight here as the rejection does - a guard that over-rejects is a
 * worse bug than the one it fixed, and would only be found by a member who
 * could no longer post a picture.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\PostService;

/**
 * Empty-post rejection, and the text-empty posts that must survive it.
 *
 * @covers \BuddyNext\Feed\PostService::create
 */
class EmptyPostRejectedTest extends \WP_UnitTestCase {

	/**
	 * Posting member.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Service under test.
	 *
	 * @var PostService
	 */
	private $posts;

	/**
	 * An author who is allowed to post.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// Administrator so the email-verification gate cannot be what produces
		// the result - this test is about content, not permissions.
		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->author );

		$this->posts = new PostService();
	}

	/**
	 * The regression: a completely empty payload is refused.
	 *
	 * @return void
	 */
	public function test_an_empty_payload_is_rejected(): void {
		$result = $this->posts->create( $this->author, array() );

		$this->assertWPError( $result, 'An empty payload created a post.' );
		$this->assertSame( 'empty_post', $result->get_error_code() );
	}

	/**
	 * Whitespace is not content.
	 *
	 * @return void
	 */
	public function test_whitespace_only_content_is_rejected(): void {
		$result = $this->posts->create( $this->author, array( 'content' => "   \n\t  " ) );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_post', $result->get_error_code() );
	}

	/**
	 * Neither are bare tags - the check strips markup before judging, so an
	 * editor that submits "<p></p>" cannot smuggle a blank post through.
	 *
	 * @return void
	 */
	public function test_markup_with_no_text_is_rejected(): void {
		$result = $this->posts->create( $this->author, array( 'content' => '<p></p><br />' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'empty_post', $result->get_error_code() );
	}

	/**
	 * It answers 400, not 500 - this is a malformed request, and the REST layer
	 * defaults to 500 when no status is given.
	 *
	 * @return void
	 */
	public function test_the_error_carries_a_400_status(): void {
		$result = $this->posts->create( $this->author, array() );

		$this->assertWPError( $result );
		$this->assertSame( 400, $result->get_error_data()['status'] ?? 0 );
	}

	/**
	 * An ordinary text post is unaffected.
	 *
	 * @return void
	 */
	public function test_a_normal_text_post_still_works(): void {
		$this->assertIsInt( $this->posts->create( $this->author, array( 'content' => 'Hello world' ) ) );
	}

	/**
	 * A photo post carries no text and must still publish.
	 *
	 * @return void
	 */
	public function test_a_photo_post_with_no_text_still_works(): void {
		$result = $this->posts->create(
			$this->author,
			array(
				'type'      => 'photo',
				'media_ids' => array( 1 ),
			)
		);

		$this->assertNotWPError( $result, 'A text-empty photo post was rejected as empty.' );
	}

	/**
	 * So does a shared link.
	 *
	 * @return void
	 */
	public function test_a_link_post_with_no_text_still_works(): void {
		$result = $this->posts->create(
			$this->author,
			array(
				'type'     => 'link',
				'link_url' => 'https://example.test/article',
			)
		);

		$this->assertNotWPError( $result, 'A text-empty link post was rejected as empty.' );
	}

	/**
	 * And a poll, whose options ARE its content.
	 *
	 * @return void
	 */
	public function test_a_poll_with_no_text_still_works(): void {
		$result = $this->posts->create(
			$this->author,
			array(
				'type'    => 'poll',
				'options' => array( 'Tabs', 'Spaces' ),
			)
		);

		$this->assertNotWPError( $result, 'A text-empty poll was rejected as empty.' );
	}

	/**
	 * Nothing was written for a rejected post - the guard must run before the
	 * insert, not clean up after it.
	 *
	 * @return void
	 */
	public function test_a_rejected_post_writes_no_row(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts" );

		$this->posts->create( $this->author, array() );
		$this->posts->create( $this->author, array( 'content' => '   ' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts" );

		$this->assertSame( $before, $after, 'A rejected post still wrote a row to bn_posts.' );
	}
}
