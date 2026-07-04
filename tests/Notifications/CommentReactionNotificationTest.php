<?php
/**
 * Tests that reacting to a comment notifies the comment author.
 *
 * Regression guard for card 10062105840: on_reaction_added previously handled
 * only 'post' object types, so comment reactions never notified anyone.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationListener;

/**
 * @covers \BuddyNext\Notifications\NotificationListener::on_reaction_added
 */
class CommentReactionNotificationTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Seed a post and a comment authored by $author, then return the comment id.
	 *
	 * @param int $author Comment author user id.
	 * @param int $post_id Parent post id (out).
	 * @return int Comment id.
	 */
	private function seed_comment( int $author, int &$post_id ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $author,
				'content'    => 'body',
				'type'       => 'text',
				'status'     => 'published',
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$post_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => $author,
				'object_type' => 'post',
				'object_id'   => $post_id,
				'content'     => 'a comment',
				'created_at'  => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_comment_reaction_notifies_author(): void {
		global $wpdb;

		$author  = self::factory()->user->create();
		$reactor = self::factory()->user->create();
		$post_id = 0;
		$comment = $this->seed_comment( $author, $post_id );

		( new NotificationListener() )->on_reaction_added( 'comment', $comment, $reactor, 'like' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.comment_reacted'",
				$author
			)
		);
		$this->assertSame( 1, $count, 'reacting to a comment must notify the comment author' );
	}

	public function test_self_reaction_does_not_notify(): void {
		global $wpdb;

		$author  = self::factory()->user->create();
		$post_id = 0;
		$comment = $this->seed_comment( $author, $post_id );

		( new NotificationListener() )->on_reaction_added( 'comment', $comment, $author, 'like' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.comment_reacted'",
				$author
			)
		);
		$this->assertSame( 0, $count, 'reacting to your own comment must not notify you' );
	}
}
