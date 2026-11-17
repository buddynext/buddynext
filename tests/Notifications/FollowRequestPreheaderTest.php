<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The follow-request email preheader uses the request screen's own word.
 *
 * It said "Approve or ignore" while the button says Decline. An unedited
 * stored template moves to the new wording; an edited one is left alone.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\EmailDefaults;
use WP_UnitTestCase;

/**
 * Follow-request preheader wording.
 *
 * @covers \BuddyNext\Notifications\EmailDefaults::refresh_unedited
 */
class FollowRequestPreheaderTest extends WP_UnitTestCase {

	/**
	 * Read the stored preheader.
	 *
	 * @return string
	 */
	private function stored(): string {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT preview_text FROM {$wpdb->prefix}bn_email_templates WHERE type = %s", 'bn.follow_requested' ) );
	}

	/**
	 * Set the stored preheader.
	 *
	 * @param string $text Preheader.
	 * @return void
	 */
	private function store( string $text ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'bn_email_templates', array( 'preview_text' => $text ), array( 'type' => 'bn.follow_requested' ) );
	}

	/**
	 * Unedited old wording is refreshed; an owner's edit survives.
	 *
	 * @return void
	 */
	public function test_unedited_preheader_moves_to_decline(): void {
		Installer::run();

		$this->store( 'Approve or ignore the request' );
		EmailDefaults::refresh_unedited();
		$this->assertSame( 'Approve or decline the request', $this->stored() );

		$this->store( 'Our own wording' );
		EmailDefaults::refresh_unedited();
		$this->assertSame( 'Our own wording', $this->stored() );
	}
}
