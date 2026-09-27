<?php
/**
 * Tests for the blog article feed card.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Feed\BlogPostListener;
use BuddyNext\Feed\PostService;

/**
 * The article card follows its source post.
 *
 * @covers \BuddyNext\Feed\BlogPostListener
 */
class BlogPostListenerTest extends \WP_UnitTestCase {

	/**
	 * A featured image and title set after publishing show on the card.
	 *
	 * The order an editor and front-end post plugins use: the post is published
	 * first, the image is attached a moment later. The card was a copy taken at
	 * publish time, so it never showed the image.
	 *
	 * @return void
	 */
	public function test_card_shows_image_and_title_set_after_publish(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'First title',
				'post_content' => 'Body text.',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);

		$card_id = BlogPostListener::card_id_for_post( (int) $post_id );
		$this->assertGreaterThan( 0, $card_id, 'publishing a post creates an article card' );

		$service = new PostService();
		$before  = $service->hydrate( (array) $service->get( $card_id ) );
		$this->assertSame( '', (string) ( $before['link_meta']['thumbnail'] ?? '' ), 'no image yet' );

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', (int) $post_id );
		set_post_thumbnail( (int) $post_id, $attachment );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title',
			)
		);

		$after = $service->hydrate( (array) $service->get( $card_id ) );
		$this->assertSame( (string) get_the_post_thumbnail_url( (int) $post_id, 'medium_large' ), $after['link_meta']['thumbnail'], 'image added after publish shows on the card' );
		$this->assertNotSame( '', $after['link_meta']['thumbnail'] );
		$this->assertSame( 'Updated title', $after['link_meta']['title'], 'edited title shows on the card' );
	}

	/**
	 * Unpublish, trash or a password withdraws the card; republish brings back
	 * the same card (its comments survive); a permanent delete removes it.
	 *
	 * @return void
	 */
	public function test_card_is_withdrawn_and_restored_not_recreated(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Cycle',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
		$card    = BlogPostListener::card_id_for_post( $post_id );
		$service = new PostService();
		$status  = static fn() => (string) ( $service->get( $card )['status'] ?? '' );
		$this->assertGreaterThan( 0, $card );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		$this->assertSame( 'draft', $status(), 'unpublish withdraws the card' );

		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ), 'republish brings back the same card' );
		$this->assertSame( 'published', $status() );

		wp_update_post( array( 'ID' => $post_id, 'post_password' => 'secret' ) );
		$this->assertSame( 'draft', $status(), 'a password withdraws the card' );
		wp_update_post( array( 'ID' => $post_id, 'post_password' => '' ) );
		$this->assertSame( 'published', $status() );

		wp_trash_post( $post_id );
		$this->assertSame( 'draft', $status(), 'trash withdraws the card' );
		wp_untrash_post( $post_id );
		wp_publish_post( $post_id );
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ) );
		$this->assertSame( 'published', $status(), 'restore from trash brings it back' );

		wp_delete_post( $post_id, true );
		$this->assertNull( $service->get( $card ), 'a permanent delete removes the card' );
	}

	/**
	 * With the source post gone, the stored copy is kept.
	 *
	 * @return void
	 */
	public function test_live_meta_keeps_stored_copy_without_a_source(): void {
		$meta = array(
			'title'          => 'Stored',
			'source_post_id' => 999999,
		);
		$this->assertSame( $meta, BlogPostListener::live_link_meta( $meta ) );
	}

	/**
	 * A post published before the author verified reaches the feed once they do.
	 *
	 * @return void
	 */
	public function test_post_published_before_verification_gets_its_card_on_verify(): void {
		update_option( 'buddynext_email_verify', 1 );
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Written before verifying',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);

		$this->assertSame( 0, BlogPostListener::card_id_for_post( $post_id ), 'held while unverified' );

		( new \BuddyNext\Auth\VerificationService() )->mark_verified( $author );
		$card = BlogPostListener::card_id_for_post( $post_id );
		$this->assertGreaterThan( 0, $card, 'published once the member verifies' );

		do_action( 'buddynext_user_verified', $author );
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ), 'never a second card' );

		delete_option( 'buddynext_email_verify' );
	}
}
