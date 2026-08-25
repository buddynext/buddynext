<?php
/**
 * bn_mod_log is append-only: deleting the content it references must NOT delete
 * the audit entries. PostService::delete()/SpaceService::delete() used to cascade
 * into bn_mod_log, erasing the moderation trail for the very content most likely
 * to be under scrutiny.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationLogService;

/**
 * @covers \BuddyNext\Feed\PostService::delete
 */
class ModLogSurvivesContentDeleteTest extends \WP_UnitTestCase {

	private PostService $posts;
	private ModerationLogService $log;
	private int $author = 0;
	private int $admin  = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts  = new PostService();
		$this->log    = new ModerationLogService();
		$this->author = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->admin  = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	private function mod_log_rows_for_post( int $post_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'post' AND object_id = %d",
				$post_id
			)
		);
	}

	public function test_deleting_a_post_preserves_its_moderation_log(): void {
		$post_id = $this->posts->create(
			$this->author,
			array(
				'type'    => 'text',
				'content' => 'A post that will be moderated then deleted.',
			)
		);
		$this->assertIsInt( $post_id );

		$this->log->log( $this->admin, 'warn', array( 'object_type' => 'post', 'object_id' => $post_id ) );
		$this->assertSame( 1, $this->mod_log_rows_for_post( $post_id ), 'The audit entry was not written.' );

		$this->assertTrue( $this->posts->delete( $post_id, $this->author ), 'The post delete failed.' );

		// The post is gone; the append-only audit entry survives.
		$this->assertNull( $this->posts->get( $post_id ), 'The post row should be gone.' );
		$this->assertSame( 1, $this->mod_log_rows_for_post( $post_id ), 'The moderation log must outlive the content.' );
	}
}
