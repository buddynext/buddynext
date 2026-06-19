<?php
/**
 * Tests for the BuddyNext Email Template Editor.
 *
 * @package BuddyNext\Tests\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Admin;

use BuddyNext\Admin\EmailEditor;

/**
 * Verifies the email editor catalogue and save/reset logic.
 *
 * @covers \BuddyNext\Admin\EmailEditor
 */
class EmailEditorTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var EmailEditor
	 */
	private EmailEditor $editor;

	/**
	 * DB table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Set up before each test.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->editor = new EmailEditor();
		$this->table  = $wpdb->prefix . 'bn_email_templates';

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"CREATE TABLE IF NOT EXISTS {$this->table} ( -- phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				type         VARCHAR(100) NOT NULL UNIQUE,
				subject      TEXT NOT NULL,
				preview_text TEXT NOT NULL,
				body_html    LONGTEXT NOT NULL,
				enabled      TINYINT(1) NOT NULL DEFAULT 1,
				PRIMARY KEY (id)
			) {$wpdb->get_charset_collate()}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Clean up table after each test.
	 */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS {$this->table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		parent::tear_down();
	}

	// ── Catalogue ─────────────────────────────────────────────────────────────

	/**
	 * get_catalogue() returns an array with eight categories.
	 */
	public function test_catalogue_has_four_categories(): void {
		$catalogue = $this->editor->get_catalogue();
		$this->assertCount( 8, $catalogue );
	}

	/**
	 * get_catalogue() includes the bn.new_follower template.
	 */
	public function test_catalogue_contains_new_follower(): void {
		$catalogue = $this->editor->get_catalogue();
		$found     = false;
		foreach ( $catalogue as $templates ) {
			if ( isset( $templates['bn.new_follower'] ) ) {
				$found = true;
				break;
			}
		}
		$this->assertTrue( $found );
	}

	/**
	 * get_catalogue() includes all 29 templates.
	 */
	public function test_catalogue_has_twelve_templates(): void {
		$catalogue = $this->editor->get_catalogue();
		$total     = 0;
		foreach ( $catalogue as $templates ) {
			$total += count( $templates );
		}
		$this->assertSame( 29, $total );
	}

	/**
	 * Each catalogue entry has the required keys.
	 */
	public function test_each_template_has_required_keys(): void {
		$catalogue = $this->editor->get_catalogue();
		foreach ( $catalogue as $templates ) {
			foreach ( $templates as $slug => $def ) {
				$this->assertArrayHasKey( 'name', $def, "Template {$slug} missing 'name'" );
				$this->assertArrayHasKey( 'trigger', $def, "Template {$slug} missing 'trigger'" );
				$this->assertArrayHasKey( 'tokens', $def, "Template {$slug} missing 'tokens'" );
				$this->assertArrayHasKey( 'subject', $def, "Template {$slug} missing 'subject'" );
				$this->assertArrayHasKey( 'body', $def, "Template {$slug} missing 'body'" );
			}
		}
	}

	// ── save() + get_saved() ──────────────────────────────────────────────────

	/**
	 * save() inserts a new row and get_saved() retrieves it.
	 */
	public function test_save_inserts_and_get_saved_retrieves(): void {
		$saved = $this->editor->save( 'new_follower', 'Subject test', 'Preview test', '<p>Body</p>', true );
		$this->assertTrue( $saved );

		$row = $this->editor->get_saved( 'new_follower' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Subject test', $row->subject );
		$this->assertSame( 'Preview test', $row->preview_text );
		$this->assertSame( '<p>Body</p>', $row->body_html );
		$this->assertSame( '1', (string) $row->enabled );
	}

	/**
	 * save() updates an existing row on second call.
	 */
	public function test_save_updates_existing_row(): void {
		$this->editor->save( 'new_follower', 'Original', 'Original preview', 'Original body', true );
		$this->editor->save( 'new_follower', 'Updated', 'Updated preview', 'Updated body', false );

		$row = $this->editor->get_saved( 'new_follower' );
		$this->assertSame( 'Updated', $row->subject );
		$this->assertSame( '0', (string) $row->enabled );
	}

	/**
	 * get_saved() returns null when no row exists for the slug.
	 */
	public function test_get_saved_returns_null_for_unknown_slug(): void {
		$result = $this->editor->get_saved( 'nonexistent_slug' );
		$this->assertNull( $result );
	}

	// ── register() ───────────────────────────────────────────────────────────

	/**
	 * register() adds the admin_menu hook.
	 */
	public function test_register_adds_admin_menu_hook(): void {
		$this->editor->register();
		// The email template editor is now an AdminHub tab (settings:templates,
		// placed in the notifications section), not a direct admin_menu hook.
		$this->assertArrayHasKey( 'templates', \BuddyNext\Admin\AdminHub::get_tabs( 'notifications' ) );
	}

	/**
	 * register() adds the admin_post_buddynext_email_save hook.
	 */
	public function test_register_adds_save_hook(): void {
		$this->editor->register();
		$this->assertNotFalse( has_action( 'admin_post_buddynext_email_save', array( $this->editor, 'handle_save' ) ) );
	}

	/**
	 * register() adds the admin_post_buddynext_email_test hook.
	 */
	public function test_register_adds_test_hook(): void {
		$this->editor->register();
		$this->assertNotFalse( has_action( 'admin_post_buddynext_email_test', array( $this->editor, 'handle_test' ) ) );
	}

	/**
	 * register() adds the admin_post_buddynext_email_reset hook.
	 */
	public function test_register_adds_reset_hook(): void {
		$this->editor->register();
		$this->assertNotFalse( has_action( 'admin_post_buddynext_email_reset', array( $this->editor, 'handle_reset' ) ) );
	}
}
