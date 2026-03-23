<?php
/**
 * Tests for Jetonomy bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\Jetonomy;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\Jetonomy
 */
class JetonomyBridgeTest extends \WP_UnitTestCase {

	private Jetonomy $bridge;
	private int $user_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		// Plugin class stub is registered in tests/bootstrap.php.
		$this->bridge    = new Jetonomy();
		$this->bridge->init();
		$this->user_id   = self::factory()->user->create();
		$this->author_id = self::factory()->user->create();
	}

	public function test_discussion_indexed_with_correct_author(): void {
		global $wpdb;

		// Bridge should index discussion and store the correct author_id.
		do_action( 'jetonomy_after_create_post', 99, $this->author_id, 'Another Discussion', 'Content body.' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT author_id FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				99
			)
		);

		$this->assertNotNull( $row );
		$this->assertSame( $this->author_id, (int) $row->author_id );
	}

	public function test_post_created_indexes_in_search(): void {
		global $wpdb;

		do_action( 'jetonomy_after_create_post', 20, $this->user_id, 'Test Discussion Title', 'Body content here.' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				20
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_register_hook_is_idempotent(): void {
		global $wpdb;

		// Indexing the same object_id twice should not duplicate rows (INSERT IGNORE).
		do_action( 'jetonomy_after_create_post', 101, $this->user_id, 'Dupe Test', 'Body.' );
		do_action( 'jetonomy_after_create_post', 101, $this->user_id, 'Dupe Test', 'Body.' );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_search_index
				 WHERE object_type = 'discussion' AND object_id = %d",
				101
			)
		);

		$this->assertSame( 1, $count );
	}
}
