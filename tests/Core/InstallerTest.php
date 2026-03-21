<?php
/**
 * Tests that all bn_* tables are created by the Installer.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * Installer test suite.
 *
 * @covers \BuddyNext\Core\Installer
 */
class InstallerTest extends \WP_UnitTestCase {

	/**
	 * Run the installer before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * Verify each bn_* table exists after Installer::run().
	 *
	 * WP test suite converts CREATE TABLE → CREATE TEMPORARY TABLE so
	 * SHOW TABLES LIKE cannot find them. SELECT 1 produces a DB error
	 * only when the table is absent, which is what we assert on.
	 *
	 * @dataProvider table_provider
	 * @param string $table Unprefixed table name.
	 */
	public function test_table_exists( string $table ): void {
		global $wpdb;

		$full = $wpdb->prefix . $table;

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->get_results( "SELECT 1 FROM `{$full}` LIMIT 1" );
		$last_error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		$this->assertEmpty( $last_error, "Table {$table} should exist after Installer::run(). DB error: {$last_error}" );
	}

	/**
	 * Verify that bn_posts has the content_warning and content_warning_type columns.
	 */
	public function test_posts_has_content_warning_columns(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'bn_posts';

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->get_results( "SELECT content_warning, content_warning_type FROM `{$table}` LIMIT 1" );
		$last_error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		$this->assertEmpty( $last_error, "bn_posts must have content_warning and content_warning_type columns. DB error: {$last_error}" );
	}

	/**
	 * Provides unprefixed table names to test_table_exists().
	 *
	 * @return array<int, array{string}>
	 */
	public static function table_provider(): array {
		return array_map(
			fn( string $t ) => array( $t ),
			array(
				'bn_follows',
				'bn_connections',
				'bn_blocks',
				'bn_posts',
				'bn_bookmarks',
				'bn_shares',
				'bn_spaces',
				'bn_space_members',
				'bn_space_categories',
				'bn_notifications',
				'bn_notification_prefs',
				'bn_email_templates',
				'bn_email_log',
				'bn_verify_tokens',
				'bn_reactions',
				'bn_comments',
				'bn_hashtags',
				'bn_post_hashtags',
				'bn_hashtag_follows',
				'bn_search_index',
				'bn_user_abilities',
				'bn_user_credits',
				'bn_webhook_log',
				'bn_conversations',
				'bn_conversation_participants',
				'bn_messages',
				'bn_message_reactions',
				'bn_activity_log',
				// BLOCK 1 additions.
				'bn_user_suspensions',
				'bn_appeals',
				'bn_space_bans',
				'bn_outbound_webhooks',
				'bn_outbound_webhook_log',
			)
		);
	}
}
