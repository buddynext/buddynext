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
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_posts (
				id BIGINT UNSIGNED NOT NULL,
				author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				slug VARCHAR(200) NOT NULL DEFAULT '',
				title TEXT NULL,
				content_plain LONGTEXT NULL,
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$wpdb->prefix}jt_spaces (
				id BIGINT UNSIGNED NOT NULL,
				slug VARCHAR(200) NOT NULL DEFAULT '',
				PRIMARY KEY (id)
			) DEFAULT CHARSET=utf8mb4"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Plugin class stub is registered in tests/bootstrap.php.
		$this->bridge    = new JetonomyBridge();
		$this->bridge->init();
		$this->user_id   = self::factory()->user->create();
		$this->author_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_posts" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}jt_spaces" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		parent::tear_down();
	}

	/**
	 * Insert a fake Jetonomy discussion row so the bridge can read author/title/body.
	 */
	private function seed_jt_post( int $post_id, int $author_id, string $title = 'Hi', string $body = 'Body', string $slug = 'topic' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'jt_posts',
			array(
				'id'            => $post_id,
				'author_id'     => $author_id,
				'slug'          => $slug,
				'title'         => $title,
				'content_plain' => $body,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Insert a fake Jetonomy space row so the bridge can build a discussion URL.
	 */
	private function seed_jt_space( int $space_id, string $slug = 'general' ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'jt_spaces',
			array(
				'id'   => $space_id,
				'slug' => $slug,
			),
			array( '%d', '%s' )
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

	public function test_discussion_create_publishes_feed_activity(): void {
		global $wpdb;

		$this->seed_jt_space( 5, 'general' );
		$this->seed_jt_post( 30, $this->author_id, 'Welcome', 'Body', 'welcome-thread' );

		do_action( 'jetonomy_after_create_post', 30, 5 );

		$expected_url = home_url( '/community' ) . '/s/general/t/welcome-thread/';
		$activity     = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link' AND link_url = %s",
				$this->author_id,
				$expected_url
			)
		);
		$this->assertSame( 1, $activity );

		// Soft-delete removes it (jt_posts/jt_spaces rows still present).
		do_action( 'jetonomy_post_deleted', 30, 5, $this->author_id );
		$after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE link_url = %s", $expected_url )
		);
		$this->assertSame( 0, $after );
	}

	public function test_discussion_activity_can_be_filtered_off(): void {
		global $wpdb;

		add_filter( 'buddynext_jetonomy_discussion_activity', '__return_false' );
		$this->seed_jt_space( 6, 'team' );
		$this->seed_jt_post( 31, $this->author_id, 'Quiet', 'Body', 'quiet-thread' );

		do_action( 'jetonomy_after_create_post', 31, 6 );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d AND type = 'link'", $this->author_id )
		);
		$this->assertSame( 0, $count );
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
