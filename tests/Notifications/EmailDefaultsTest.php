<?php
/**
 * Unedited email templates move to the current default; edited ones never change.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\EmailDefaults;

/**
 * @covers \BuddyNext\Notifications\EmailDefaults
 */
class EmailDefaultsTest extends \WP_UnitTestCase {

	/**
	 * A history entry for one test type.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function history(): array {
		return array(
			'qa.test_type' => array(
				'current'  => array(
					'subject'   => 'New subject',
					'body_html' => '<p>New body</p>',
				),
				'previous' => array(
					array(
						'subject'   => 'Old subject',
						'body_html' => '<p>Old body</p>',
					),
					array( 'body_html' => '<p>Older body</p>' ),
				),
			),
		);
	}

	/**
	 * Insert a template row.
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body HTML.
	 * @return void
	 */
	private function seed( string $subject, string $body ): void {
		global $wpdb;
		Installer::run();
		$wpdb->delete( $wpdb->prefix . 'bn_email_templates', array( 'type' => 'qa.test_type' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'bn_email_templates',
			array(
				'type'      => 'qa.test_type',
				'subject'   => $subject,
				'body_html' => $body,
			)
		);
	}

	/**
	 * Read a stored field.
	 *
	 * @param string $field Column.
	 * @return string
	 */
	private function stored( string $field ): string {
		global $wpdb;
		return (string) $wpdb->get_var( "SELECT {$field} FROM {$wpdb->prefix}bn_email_templates WHERE type = 'qa.test_type'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * An untouched template is updated in every field, from any previous default.
	 *
	 * @return void
	 */
	public function test_unedited_template_moves_to_the_current_default(): void {
		$this->seed( 'Old subject', '<p>Older body</p>' );

		$this->assertSame( 1, EmailDefaults::outdated_count( $this->history() ) );
		$this->assertSame( 2, EmailDefaults::refresh_unedited( $this->history() ) );
		$this->assertSame( 'New subject', $this->stored( 'subject' ) );
		$this->assertSame( '<p>New body</p>', $this->stored( 'body_html' ) );
		$this->assertSame( 0, EmailDefaults::outdated_count( $this->history() ) );
		$this->assertSame( 0, EmailDefaults::refresh_unedited( $this->history() ), 'A second run changes nothing.' );
	}

	/**
	 * An owner's edit is kept, field by field.
	 *
	 * @return void
	 */
	public function test_an_owners_edit_is_never_overwritten(): void {
		$this->seed( 'My own subject', '<p>Old body</p>' );

		EmailDefaults::refresh_unedited( $this->history() );

		$this->assertSame( 'My own subject', $this->stored( 'subject' ), 'The edited subject stays.' );
		$this->assertSame( '<p>New body</p>', $this->stored( 'body_html' ), 'The unedited body still updates.' );
	}

	/**
	 * Whitespace between tags is formatting, not an edit (a saved copy puts each
	 * paragraph on its own line); changed words are an edit.
	 *
	 * @return void
	 */
	public function test_formatting_whitespace_is_not_an_edit(): void {
		$this->seed( 'Old subject', "<p>Old body</p>\n" );
		$this->assertSame( 1, EmailDefaults::refresh_unedited( $this->history() ) - 1 );
		$this->assertSame( '<p>New body</p>', $this->stored( 'body_html' ) );

		$this->seed( 'Old subject', '<p>Old  body edited</p>' );
		EmailDefaults::refresh_unedited( $this->history() );
		$this->assertSame( '<p>Old  body edited</p>', $this->stored( 'body_html' ) );
	}
}
