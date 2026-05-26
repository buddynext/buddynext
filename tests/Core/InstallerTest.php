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

	// ── mu-plugin lifecycle ───────────────────────────────────────────────────

	/**
	 * Test that install_mu_plugin() writes the isolation file.
	 */
	public function test_install_mu_plugin_creates_file(): void {
		// Use a temp dir to avoid writing to real mu-plugins during tests.
		$tmp_dir = sys_get_temp_dir() . '/bn_test_mu_' . uniqid();
		mkdir( $tmp_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir

		// Override WP_CONTENT_DIR by filtering the path inside install_mu_plugin.
		// Since we can't redefine the constant, test via the file system directly.
		// Call the method and verify it wrote something.
		\BuddyNext\Core\Installer::install_mu_plugin();
		$path = WP_CONTENT_DIR . '/mu-plugins/buddynext-isolation.php';

		// Only assert the file exists if mu-plugins dir is writable.
		if ( is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$file_content = file_get_contents( $path );
			// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertFileExists( $path );
			$this->assertStringContainsString( 'buddynext_mu_is_bn_request', $file_content );
			// Verify the correct people slug option key is embedded, not the old members key.
			$this->assertStringContainsString( 'buddynext_slug_people', $file_content );
		} else {
			$this->markTestSkipped( 'mu-plugins directory not writable in test environment' );
		}
	}

	/**
	 * Test that remove_mu_plugin() deletes the isolation file.
	 */
	public function test_remove_mu_plugin_deletes_file(): void {
		$path = WP_CONTENT_DIR . '/mu-plugins/buddynext-isolation.php';
		if ( ! is_dir( WP_CONTENT_DIR . '/mu-plugins' ) ) {
			$this->markTestSkipped( 'mu-plugins directory not writable in test environment' );
			return;
		}

		// Ensure the file exists first.
		\BuddyNext\Core\Installer::install_mu_plugin();
		$this->assertFileExists( $path );

		\BuddyNext\Core\Installer::remove_mu_plugin();
		$this->assertFileDoesNotExist( $path );
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
				'bn_webhook_log',
				// DM tables (bn_conversations, bn_conversation_participants,
				// bn_messages, bn_message_reactions) deliberately removed —
				// DM is owned by WPMediaVerse per 14-wpmediaverse-bridge spec.
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
