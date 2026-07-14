<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * "Publish Now" must publish NOW, not at the moment the post was composed.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * The author pressed a button called Publish Now and nobody saw the post.
 *
 * Pro's "Publish Now" hand-rolled a raw UPDATE: it set status and cleared scheduled_at, and
 * never touched created_at. Feeds order by `created_at DESC`, so the post went live at the
 * moment it was COMPOSED. Compose on the 1st, schedule for the 20th, hit Publish Now on the
 * 5th — the post appears buried under four days of feed.
 *
 * Every other publish transition already bumps the timestamp for exactly this reason: the
 * cron publisher, and moderation approval. The convention held everywhere except the one
 * button whose name promises it.
 *
 * @covers \BuddyNext\Feed\PostService::publish_scheduled_now
 */
class PublishScheduledNowTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
	}

	/**
	 * A post composed days ago and scheduled for the future.
	 *
	 * @return int
	 */
	private function seed_old_scheduled_post(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'      => self::factory()->user->create(),
				'type'         => 'text',
				'content'      => 'Composed long ago, scheduled for later.',
				'status'       => 'scheduled',
				'privacy'      => 'public',
				'created_at'   => gmdate( 'Y-m-d H:i:s', time() - ( 10 * DAY_IN_SECONDS ) ),
				'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + ( 10 * DAY_IN_SECONDS ) ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Read the row back.
	 *
	 * @param int $post_id Post.
	 * @return array<string,mixed>
	 */
	private function row( int $post_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_row(
			$wpdb->prepare( "SELECT status, created_at, scheduled_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id ),
			ARRAY_A
		);
	}

	/**
	 * The post goes live at NOW, not at the time it was written.
	 *
	 * @return void
	 */
	public function test_publishing_now_bumps_created_at_to_now(): void {
		$post_id = $this->seed_old_scheduled_post();

		$before = $this->row( $post_id );
		$this->assertSame( 'scheduled', $before['status'] );

		$this->assertTrue( ( new PostService() )->publish_scheduled_now( $post_id ) );

		$after = $this->row( $post_id );

		$this->assertSame( 'published', $after['status'] );

		$age = time() - (int) strtotime( (string) $after['created_at'] );

		$this->assertLessThan(
			120,
			$age,
			'Publish Now published the post at its ORIGINAL created_at. Feeds order by created_at DESC, so it went live buried — the author pressed Publish Now and nobody saw it.'
		);
	}

	/**
	 * The scheduled_at column is cleared, or the scheduling layer still believes the post is pending.
	 *
	 * @return void
	 */
	public function test_publishing_now_clears_the_schedule(): void {
		$post_id = $this->seed_old_scheduled_post();

		( new PostService() )->publish_scheduled_now( $post_id );

		$this->assertNull(
			$this->row( $post_id )['scheduled_at'],
			'A published post still carries a future scheduled_at — the scheduling layer thinks it is still pending.'
		);
	}

	/**
	 * It announces itself, so a listener can react to an early publish.
	 *
	 * @return void
	 */
	public function test_publishing_now_fires_its_action(): void {
		$post_id = $this->seed_old_scheduled_post();

		$fired = 0;
		add_action(
			'buddynext_scheduled_post_published',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		( new PostService() )->publish_scheduled_now( $post_id );

		$this->assertSame( 1, $fired );
	}
}
