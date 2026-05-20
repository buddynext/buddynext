<?php
/**
 * Tests for Jetonomy bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Core\Installer;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge
 */
class JetonomyBridgeTest extends \WP_UnitTestCase {

	private JetonomyBridge $bridge;
	private int $user_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Bridge reads discussion title/content/author from jt_posts —
		// Jetonomy's table that this plugin doesn't ship. Create a minimal
		// shadow table for the duration of these tests.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_posts (
				id BIGINT UNSIGNED NOT NULL,
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				title TEXT NULL,
				content_plain LONGTEXT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);

		// Plugin class stub is registered in tests/bootstrap.php.
		$this->bridge    = new JetonomyBridge();
		$this->bridge->init();
		$this->user_id   = self::factory()->user->create();
		$this->author_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_posts" );
		parent::tear_down();
	}

	/**
	 * Insert a fake Jetonomy discussion row so the bridge can read author/title/body.
	 */
	private function seed_jt_post( int $post_id, int $author_id, string $title = 'Hi', string $body = 'Body' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'jt_posts',
			array(
				'id'            => $post_id,
				'author_id'     => $author_id,
				'title'         => $title,
				'content_plain' => $body,
			),
			array( '%d', '%d', '%s', '%s' )
		);
	}

	public function test_discussion_indexed_with_correct_author(): void {
		global $wpdb;

		$this->seed_jt_post( 99, $this->author_id, 'Another Discussion', 'Content body.' );

		// jetonomy_after_create_post fires ($post_id, $space_id) — 2 args only.
		do_action( 'jetonomy_after_create_post', 99, 0 );

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

		$this->seed_jt_post( 20, $this->user_id, 'Test Discussion Title', 'Body content here.' );

		do_action( 'jetonomy_after_create_post', 20, 0 );

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

		$this->seed_jt_post( 101, $this->user_id, 'Dupe Test', 'Body.' );

		// Indexing the same object_id twice should not duplicate rows (INSERT IGNORE).
		do_action( 'jetonomy_after_create_post', 101, 0 );
		do_action( 'jetonomy_after_create_post', 101, 0 );

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
