<?php
/**
 * Deleting a post leaves NO orphaned child rows in any table.
 *
 * Regression cover for card 10264292876: the card's own evidence was orphaned
 * comment reactions and notifications found in normal use after a post delete.
 * cascade_post_children() sweeps every child table, but nothing asserted the sweep
 * is complete — this walks it: seed a row in each child table (keyed to the post
 * and to a comment on it), delete the post, and assert every table is empty for
 * those ids. The delete is also transactional now, so a mid-cascade death cannot
 * half-sweep.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Full orphan scan across every post-child table after delete().
 *
 * @covers \BuddyNext\Feed\PostService::delete
 * @covers \BuddyNext\Feed\PostService::cascade_post_children
 */
class PostDeleteLeavesNoOrphansTest extends WP_UnitTestCase {

	/** @var PostService */
	private $posts;

	/** @var int */
	private $author = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts  = new PostService();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @return void
	 */
	public function test_delete_removes_every_child_row(): void {
		global $wpdb;

		$post_id = (int) $this->posts->create(
			$this->author,
			array( 'type' => 'poll', 'content' => 'Q #tagged', 'privacy' => 'public', 'options' => array( 'a', 'b' ) )
		);
		$this->assertIsInt( $post_id );

		// A comment on the post, so comment-keyed child rows exist too.
		$wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array( 'object_type' => 'post', 'object_id' => $post_id, 'user_id' => $this->author, 'content' => 'c' ),
			array( '%s', '%d', '%d', '%s' )
		);
		$comment_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $comment_id, 'Comment fixture must insert.' );

		// One row in every child table cascade_post_children() sweeps.
		$other = self::factory()->user->create();
		$wpdb->insert( $wpdb->prefix . 'bn_reactions', array( 'object_type' => 'post', 'object_id' => $post_id, 'user_id' => $other, 'emoji' => 'like' ), array( '%s', '%d', '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'bn_reactions', array( 'object_type' => 'comment', 'object_id' => $comment_id, 'user_id' => $other, 'emoji' => 'like' ), array( '%s', '%d', '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'bn_notifications', array( 'recipient_id' => $this->author, 'sender_id' => $other, 'type' => 'bn.post_reacted', 'object_type' => 'post', 'object_id' => $post_id, 'is_read' => 0, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%d', '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'bn_notifications', array( 'recipient_id' => $this->author, 'sender_id' => $other, 'type' => 'bn.comment_reacted', 'object_type' => 'comment', 'object_id' => $comment_id, 'is_read' => 0, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s', '%s', '%d', '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'bn_shares', array( 'post_id' => $post_id, 'user_id' => $other, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s' ) );
		$wpdb->insert( $wpdb->prefix . 'bn_bookmarks', array( 'post_id' => $post_id, 'user_id' => $other, 'created_at' => current_time( 'mysql', true ) ), array( '%d', '%d', '%s' ) );

		// A poll vote on one of the post's options (create() already made the options).
		$opt = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_poll_options WHERE post_id = %d LIMIT 1", $post_id ) );
		if ( $opt > 0 ) {
			$wpdb->insert( $wpdb->prefix . 'bn_poll_votes', array( 'post_id' => $post_id, 'option_id' => $opt, 'user_id' => $other ), array( '%d', '%d', '%d' ) );
		}

		$this->assertTrue( $this->posts->delete( $post_id, $this->author ), 'delete() should report success.' );

		// Every child table must be empty for this post / its comment.
		$counts = array(
			'reactions(post)'       => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions WHERE object_type='post' AND object_id={$post_id}",
			'reactions(comment)'    => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_reactions WHERE object_type='comment' AND object_id={$comment_id}",
			'comments'              => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_comments WHERE object_type='post' AND object_id={$post_id}",
			'notifications(post)'   => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE object_type='post' AND object_id={$post_id}",
			'notifications(comment)' => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE object_type='comment' AND object_id={$comment_id}",
			'shares'                => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_shares WHERE post_id={$post_id}",
			'bookmarks'             => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_bookmarks WHERE post_id={$post_id}",
			'poll_options'          => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_options WHERE post_id={$post_id}",
			'poll_votes'            => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_votes WHERE post_id={$post_id}",
			'post'                  => "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE id={$post_id}",
		);
		foreach ( $counts as $label => $sql ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->assertSame( 0, (int) $wpdb->get_var( $sql ), "Orphaned rows left in {$label} after post delete." );
		}
	}
}
