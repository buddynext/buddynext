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
		$before  = (array) $service->get( $card_id ); // Primes get()'s cache, as a page view does.
		$this->assertSame( '', (string) ( $before['link_meta']['thumbnail'] ?? '' ), 'no image yet' );

		$attachment = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', (int) $post_id );
		set_post_thumbnail( (int) $post_id, $attachment );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Updated title',
			)
		);

		// What every reader receives: get() itself, served from its cache this time.
		$after = (array) $service->get( $card_id );
		$this->assertSame( (string) ( \BuddyNext\Feed\ArticleImage::for_post( (int) $post_id, 'cover' )['url'] ?? '' ), $after['link_meta']['thumbnail'], 'image added after publish shows on the card' );
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

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);
		$this->assertSame( 'draft', $status(), 'unpublish withdraws the card' );

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( $card, BlogPostListener::card_id_for_post( $post_id ), 'republish brings back the same card' );
		$this->assertSame( 'published', $status() );

		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => 'secret',
			)
		);
		$this->assertSame( 'draft', $status(), 'a password withdraws the card' );
		wp_update_post(
			array(
				'ID'            => $post_id,
				'post_password' => '',
			)
		);
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
	 * A private or password-protected source yields no title, excerpt or cover,
	 * even from the copy stored when the card was published (a reshare or a cached
	 * copy can still reach a withdrawn card).
	 *
	 * @return void
	 */
	public function test_live_meta_hides_a_private_or_protected_source(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$meta   = array(
			'title'       => 'Stored secret title',
			'description' => 'Stored secret excerpt',
			'image'       => 'https://example.com/secret-cover.jpg',
			'thumbnail'   => 'https://example.com/secret-cover.jpg',
			'url'         => 'https://example.com/?p=1',
		);

		foreach ( array(
			array( 'post_status' => 'private' ),
			array(
				'post_status'   => 'publish',
				'post_password' => 'pw',
			),
		) as $shape ) {
			$post_id = self::factory()->post->create(
				array_merge(
					array(
						'post_author' => $author,
						'post_title'  => 'Live secret title',
					),
					$shape
				)
			);
			$live    = BlogPostListener::live_link_meta( array_merge( $meta, array( BlogPostListener::META_POST_ID => $post_id ) ) );

			$encoded = (string) wp_json_encode( $live );
			$this->assertStringNotContainsString( 'secret', $encoded, wp_json_encode( $shape ) . ' leaked: ' . $encoded );
		}
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

	/**
	 * Editing an article that was public without a card never posts it as new.
	 *
	 * @return void
	 */
	public function test_editing_an_old_article_does_not_post_it_as_new(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		update_option( 'buddynext_integration_blog_feed', '0' );
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Written in 2024',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
				'post_author'  => $author,
				'post_date'    => '2024-01-10 09:00:00',
			)
		);
		delete_option( 'buddynext_integration_blog_feed' );
		$this->assertSame( 0, BlogPostListener::card_id_for_post( $post_id ), 'no card while the feed was off' );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'Body, typo fixed.',
			)
		);
		$this->assertSame( 0, BlogPostListener::card_id_for_post( $post_id ), 'a typo fix is not news' );
	}

	/**
	 * A card an admin deleted does not come back when the article is edited.
	 *
	 * @return void
	 */
	public function test_a_deleted_card_does_not_return_on_edit(): void {
		$author  = self::factory()->user->create( array( 'role' => 'author' ) );
		$admin   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = (int) wp_insert_post(
			array(
				'post_title'   => 'Card to delete',
				'post_content' => 'Body.',
				'post_status'  => 'publish',
				'post_author'  => $author,
			)
		);
		$card = BlogPostListener::card_id_for_post( $post_id );
		$this->assertGreaterThan( 0, $card );

		$this->assertTrue( ( new PostService() )->delete( $card, $admin ) );
		$this->assertSame( 0, BlogPostListener::card_id_for_post( $post_id ), 'card deleted' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Card to delete, edited',
			)
		);
		$this->assertSame( 0, BlogPostListener::card_id_for_post( $post_id ), 'the edit does not bring it back' );
	}

	/**
	 * On verifying, the member's most recent articles get their cards, not the oldest.
	 *
	 * @return void
	 */
	public function test_verify_publishes_the_most_recent_articles(): void {
		update_option( 'buddynext_email_verify', 1 );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$ids    = array();
		for ( $week = 1; $week <= 25; $week++ ) {
			$ids[ $week ] = (int) wp_insert_post(
				array(
					'post_title'   => 'Week ' . $week,
					'post_content' => 'Body.',
					'post_status'  => 'publish',
					'post_author'  => $author,
					'post_date'    => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-01 +' . $week . ' weeks' ) ),
				)
			);
		}

		( new \BuddyNext\Auth\VerificationService() )->mark_verified( $author );
		delete_option( 'buddynext_email_verify' );

		$this->assertGreaterThan( 0, BlogPostListener::card_id_for_post( $ids[25] ), 'the newest article has a card' );
		$this->assertGreaterThan( 0, BlogPostListener::card_id_for_post( $ids[6] ), 'the 20th newest has a card' );
		$this->assertSame( 0, BlogPostListener::card_id_for_post( $ids[5] ), 'older ones stay off the feed' );
		$this->assertGreaterThan( BlogPostListener::card_id_for_post( $ids[24] ), BlogPostListener::card_id_for_post( $ids[25] ), 'the newest article lands on top' );
	}

	/**
	 * Queueing the same crop or re-sync batch twice leaves one pending job each, and
	 * both hooks are swept by the upgrade's duplicate cleanup.
	 *
	 * @return void
	 */
	public function test_queueing_a_batch_twice_leaves_one_pending_job(): void {
		$resync = new \ReflectionMethod( BlogPostListener::class, 'queue_resync' );
		$resync->setAccessible( true );

		BlogPostListener::queue_crop_batch( 77, 1 );
		BlogPostListener::queue_crop_batch( 77, 1 );
		$resync->invoke( null, 0, 5 );
		$resync->invoke( null, 0, 5 );

		$pending = static fn( string $hook ): int => count( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending' ), 'ids' ) );
		$this->assertSame( 1, $pending( \BuddyNext\Feed\ArticleImage::REGEN_HOOK ), 'crop batch queued twice' );
		$this->assertSame( 1, $pending( BlogPostListener::RESYNC_HOOK ), 're-sync queued twice' );

		$this->assertContains( \BuddyNext\Feed\ArticleImage::REGEN_HOOK, \BuddyNext\Core\CronScheduler::DEDUPE_HOOKS );
		$this->assertContains( BlogPostListener::RESYNC_HOOK, \BuddyNext\Core\CronScheduler::DEDUPE_HOOKS );
	}
}
