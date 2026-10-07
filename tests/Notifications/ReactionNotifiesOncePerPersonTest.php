<?php
/**
 * A reaction notifies the author once per person per post, for good.
 *
 * Owner rule (2026-10-04): what's done is done. Removing a reaction withdraws
 * nothing, so reacting again or switching emoji must not notify again either,
 * or toggling Like would ping the author on every round.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationListener;

/**
 * Reaction notification dedupe.
 *
 * @covers \BuddyNext\Notifications\NotificationListener::on_reaction_added
 */
class ReactionNotifiesOncePerPersonTest extends \WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Reaction notifications the author holds for this post.
	 *
	 * @param int $author  Author.
	 * @param int $post_id Post.
	 * @return array<int, array<string, mixed>>
	 */
	private function rows( int $author, int $post_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, is_read FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND group_key = %s", $author, 'post_reactions_' . $post_id ), ARRAY_A );
	}

	/**
	 * React, author reads, react again and switch emoji: still one notification;
	 * a different person still notifies.
	 *
	 * @return void
	 */
	public function test_same_person_notifies_once_another_person_still_does(): void {
		global $wpdb;
		$author = self::factory()->user->create();
		$alex   = self::factory()->user->create();
		$sam    = self::factory()->user->create();
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
		$post_id  = (int) $wpdb->insert_id;
		$listener = new NotificationListener();

		$listener->on_reaction_added( 'post', $post_id, $alex, 'like' );
		$this->assertCount( 1, $this->rows( $author, $post_id ) );

		// The author reads it, so a repeat could no longer merge into it.
		$wpdb->update( $wpdb->prefix . 'bn_notifications', array( 'is_read' => 1 ), array( 'recipient_id' => $author ) );

		$listener->on_reaction_added( 'post', $post_id, $alex, 'like' );  // React again after an undo.
		$listener->on_reaction_added( 'post', $post_id, $alex, 'haha' );  // Switch emoji.
		$this->assertCount( 1, $this->rows( $author, $post_id ), 'Alex notified once, however often they toggle.' );

		$listener->on_reaction_added( 'post', $post_id, $sam, 'love' );
		$rows = $this->rows( $author, $post_id );
		$this->assertCount( 1, array_filter( $rows, static fn( $r ) => 0 === (int) $r['is_read'] ), 'A new person still notifies.' );
	}
}
