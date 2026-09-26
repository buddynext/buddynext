<?php
/**
 * Writes stamp UTC even when MySQL runs in another time zone (card 10343133937).
 *
 * DEFAULT CURRENT_TIMESTAMP, ON UPDATE CURRENT_TIMESTAMP and NOW() use the MySQL
 * session zone, not UTC. On a host whose MySQL runs in, say, IST those columns
 * came out 5h30 ahead of every other BuddyNext timestamp. Each write now supplies
 * its own UTC value, so the session zone must not matter.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\SocialGraph\FollowService;

/**
 * @coversNothing Cross-service contract: timestamps are UTC regardless of the MySQL session zone.
 */
class UtcTimestampsTest extends \WP_UnitTestCase {

	private string $zone = 'SYSTEM';
	private int $author  = 0;
	private int $member  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$this->zone = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
		$wpdb->query( "SET time_zone = '+05:30'" );
		$this->author = self::factory()->user->create();
		$this->member = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SET time_zone = %s', $this->zone ) );
		parent::tear_down();
	}

	/**
	 * Assert a stored DATETIME is "now" in UTC (IST would be 19800s off).
	 */
	private function assertUtcNow( ?string $stored, string $what ): void {
		$this->assertNotEmpty( $stored, "$what was not stamped" );
		$drift = abs( strtotime( $stored . ' UTC' ) - time() );
		$this->assertLessThan( 120, $drift, "$what is {$drift}s off UTC: $stored" );
	}

	private function col( string $table, string $col, string $where ): ?string {
		global $wpdb;
		return $wpdb->get_var( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE {$where} ORDER BY 1 DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function seed_post(): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->author,
				'type'       => 'text',
				'content'    => 'Clock check',
				'status'     => 'published',
				'privacy'    => 'public',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => '2020-01-01 00:00:00',
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_the_session_zone_really_is_not_utc(): void {
		global $wpdb;
		$drift = abs( strtotime( (string) $wpdb->get_var( 'SELECT NOW()' ) . ' UTC' ) - time() );
		$this->assertGreaterThan( 19000, $drift, 'Guard: without the IST session this test proves nothing.' );
	}

	public function test_inserts_stamp_utc(): void {
		( new FollowService() )->follow( $this->member, $this->author );
		$this->assertUtcNow( $this->col( 'bn_follows', 'created_at', "follower_id = {$this->member}" ), 'bn_follows.created_at' );

		$post = $this->seed_post();
		( new BookmarkService() )->bookmark( $this->member, $post );
		$this->assertUtcNow( $this->col( 'bn_bookmarks', 'created_at', "user_id = {$this->member}" ), 'bn_bookmarks.created_at' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertIsInt( ( new ModerationService() )->issue_strike( $this->member, $admin, 'clock' ) );
		$this->assertUtcNow( $this->col( 'bn_user_strikes', 'created_at', "user_id = {$this->member}" ), 'bn_user_strikes.created_at' );
	}

	public function test_updates_bump_updated_at_in_utc(): void {
		$post = $this->seed_post();
		$this->assertNotWPError( ( new PostService() )->update( $post, $this->author, array( 'content' => 'Edited' ) ) );
		$this->assertUtcNow( $this->col( 'bn_posts', 'updated_at', "id = {$post}" ), 'bn_posts.updated_at' );

		$comments = new CommentService();
		$comment  = $comments->create( $this->author, 'post', $post, 'First' );
		$this->assertIsInt( $comment );
		$this->assertUtcNow( $this->col( 'bn_comments', 'created_at', "id = {$comment}" ), 'bn_comments.created_at' );

		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bn_comments', array( 'updated_at' => '2020-01-01 00:00:00' ), array( 'id' => $comment ) );
		$this->assertNotWPError( $comments->update( $comment, $this->author, 'Second' ) );
		$this->assertUtcNow( $this->col( 'bn_comments', 'updated_at', "id = {$comment}" ), 'bn_comments.updated_at' );
	}
}
