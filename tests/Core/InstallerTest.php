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
 * @covers \BuddyNext\Core\Installer
 */
class InstallerTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	/**
	 * @dataProvider table_provider
	 */
	public function test_table_exists( string $table ): void {
		global $wpdb;

		$full = $wpdb->prefix . $table;

		// WP test suite converts CREATE TABLE → CREATE TEMPORARY TABLE so
		// SHOW TABLES LIKE cannot find them. Use a SELECT to verify instead.
		$wpdb->suppress_errors( true );
		$wpdb->get_results( "SELECT 1 FROM `{$full}` LIMIT 1" );
		$last_error = $wpdb->last_error;
		$wpdb->suppress_errors( false );

		$this->assertEmpty( $last_error, "Table {$table} should exist after Installer::run(). DB error: {$last_error}" );
	}

	/**
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
			)
		);
	}
}
