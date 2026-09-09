<?php
/**
 * A notification whose target object is deleted must leave the table — so the
 * bell count and the pager stop counting a row the read filter already hides.
 *
 * Regression cover for card 10264293036 round-2: the read-side filter_resolvable()
 * hid orphaned rows from a page, but every COUNT(*) (unread badge, pager total)
 * still counted them, so a "Page 1 of 7" pager rendered mostly-empty pages. The
 * fix removes the rows at the deletion seam (PostService cascade + the
 * comment-deleted listener) and sweeps legacy/un-hooked orphans daily in
 * LogRetentionService.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Core\LogRetentionService;
use BuddyNext\Feed\PostService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Reactions\ReactionService;
use WP_UnitTestCase;

/**
 * Orphaned-notification cleanup at delete time and in the daily sweep.
 *
 * @covers \BuddyNext\Feed\PostService::cascade_post_children
 * @covers \BuddyNext\Notifications\NotificationService::delete_for_object
 * @covers \BuddyNext\Core\LogRetentionService::purge
 */
class OrphanNotificationCleanupTest extends WP_UnitTestCase {

	/** @var PostService */
	private $posts;

	/** @var ReactionService */
	private $reactions;

	/** @var NotificationService */
	private $notifications;

	/** @var int */
	private $author = 0;

	/** @var int */
	private $actor = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		$this->posts         = new PostService();
		$this->reactions     = new ReactionService();
		$this->notifications = new NotificationService();
		$this->author        = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->actor         = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Count the author's notification rows that point at one post.
	 *
	 * @param int $post_id Post id.
	 * @return int
	 */
	private function rows_for_post( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);
	}

	/**
	 * Deleting a post removes the notifications that pointed at it, and the author's
	 * total count drops to match the (now empty) list — no counted-but-hidden row.
	 *
	 * @return void
	 */
	public function test_deleting_a_post_removes_its_notifications(): void {
		$post_id = $this->posts->create(
			$this->author,
			array(
				'type'    => 'text',
				'content' => 'Body worth reacting to.',
				'privacy' => 'public',
			)
		);
		$this->assertIsInt( $post_id );

		// A reaction from another member notifies the author (object_type='post').
		$this->reactions->react( $this->actor, 'post', $post_id, 'like' );
		$this->assertGreaterThan( 0, $this->rows_for_post( $post_id ), 'The reaction should create a post notification.' );

		$before = $this->notifications->count_for_user( $this->author, 'all' );

		$this->posts->delete( $post_id, $this->author );
		wp_cache_flush();

		$this->assertSame( 0, $this->rows_for_post( $post_id ), 'The post notification must be gone with the post.' );
		$this->assertSame( $before - 1, $this->notifications->count_for_user( $this->author, 'all' ), 'The counted total must drop with the deleted row.' );
	}

	/**
	 * A legacy orphan — a notification pointing at a post that no longer exists —
	 * is removed by the daily retention sweep, so the count self-corrects even for
	 * rows created by a path the delete-time hooks never saw.
	 *
	 * @return void
	 */
	public function test_daily_sweep_removes_a_legacy_orphan(): void {
		global $wpdb;

		$ghost = 987654321; // No such post row.
		$wpdb->insert(
			$wpdb->prefix . 'bn_notifications',
			array(
				'recipient_id' => $this->author,
				'sender_id'    => $this->actor,
				'type'         => 'bn.reaction',
				'object_type'  => 'post',
				'object_id'    => $ghost,
				'is_read'      => 0,
				'created_at'   => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%d', '%d', '%s' )
		);
		$this->assertSame( 1, $this->rows_for_post( $ghost ), 'Precondition: the orphan row exists.' );

		( new LogRetentionService() )->purge();

		$this->assertSame( 0, $this->rows_for_post( $ghost ), 'The daily sweep must remove a notification whose post is gone.' );
	}
}
