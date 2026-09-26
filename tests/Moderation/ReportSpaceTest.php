<?php
/**
 * A report is filed under the space of the object it reports, whatever the
 * client sends (card 10343966478).
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::report
 */
class ReportSpaceTest extends \WP_UnitTestCase {

	private int $post_id    = 0;
	private int $comment_id = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wpdb;
		$author = self::factory()->user->create();
		$wpdb->insert( $wpdb->prefix . 'bn_posts', array( 'user_id' => $author, 'space_id' => 77, 'type' => 'text', 'content' => 'In a space', 'status' => 'published', 'privacy' => 'space_members' ) );
		$this->post_id = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'bn_comments', array( 'user_id' => $author, 'object_type' => 'post', 'object_id' => $this->post_id, 'content' => 'A reply' ) );
		$this->comment_id = (int) $wpdb->insert_id;
	}

	private function space_of_report( int $report_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT space_id FROM {$wpdb->prefix}bn_reports WHERE id = %d", $report_id ) );
	}

	public function test_comment_report_takes_its_posts_space(): void {
		$id = ( new ModerationService() )->report( self::factory()->user->create(), 'comment', $this->comment_id, 'spam' );
		$this->assertIsInt( $id );
		$this->assertSame( 77, $this->space_of_report( $id ) );
	}

	public function test_client_space_is_ignored(): void {
		$service = new ModerationService();
		$none    = $service->report( self::factory()->user->create(), 'post', $this->post_id, 'spam' );
		$wrong   = $service->report( self::factory()->user->create(), 'post', $this->post_id, 'spam', 999 );
		$this->assertSame( 77, $this->space_of_report( $none ), 'no space sent: derived' );
		$this->assertSame( 77, $this->space_of_report( $wrong ), 'wrong space sent: overridden' );
	}
}
