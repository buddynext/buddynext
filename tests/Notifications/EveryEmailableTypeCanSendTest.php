<?php
/**
 * A type the catalogue says can email must actually be able to.
 *
 * Fourteen of them could not. `EmailSender::send_now()` looks up a row in
 * `bn_email_templates` and returns false when it is absent - silently: no log line,
 * no admin notice, and the in-app notification has already been written, so the
 * bell looks right and the mail simply never arrives.
 *
 * Every moderation message was in that state. A member who was warned, had content
 * removed, had a post rejected, or was reinstated got nothing, and the moderator
 * had no way to know. `bn.announcement` too - an owner broadcasting to a space
 * reasonably assumes mail goes out.
 *
 * ## Why it happened, which is what this test really guards
 *
 * Two hand-maintained lists that nothing kept in step: `Installer::seed_email_templates()`
 * writes the rows, and `EmailEditor::get_catalogue()` decides what an owner can edit.
 * A type could sit in either one alone and look fine from that side.
 * `bn.space_ownership_received` had drifted exactly that way - present on the Email
 * Templates screen, editable, and unable to send.
 *
 * The seeder now backstops from the catalogue, so adding a template in one place is
 * enough. This test is what proves the two stay reconciled, and it is deliberately
 * written against the CATALOGUE rather than a hardcoded list of types, so a new
 * emailable notification added next year is covered without anyone remembering to
 * come back here.
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use WP_UnitTestCase;

/**
 * Emailable types versus the templates that let them send.
 *
 * @covers \BuddyNext\Core\Installer::seed_email_templates
 */
class EveryEmailableTypeCanSendTest extends WP_UnitTestCase {

	/**
	 * WP core options `Installer::run()` legitimately writes, restored after.
	 *
	 * run() mirrors BuddyNext's open-registration default onto core's
	 * users_can_register on a fresh install - deliberate, documented, and a side
	 * effect these tests must not leak. Calling run() repeatedly here flipped it and
	 * broke CoreRegistrationTest, which passed alone and failed in the suite.
	 *
	 * @var array<string,mixed>
	 */
	private array $core_options = array();

	/**
	 * Start every test from an empty templates table.
	 *
	 * Without this the suite proves nothing: rows seeded by an earlier run persist in
	 * the test database, so `Installer::run()` INSERT IGNOREs over data that is
	 * already correct and the assertions pass with the fix removed. Confirmed by
	 * mutation - the first version of this file stayed green with the backstop
	 * deleted.
	 *
	 * DELETE rather than DROP on purpose: the WP harness rewrites DROP TABLE into
	 * DROP TEMPORARY TABLE, which silently does nothing to a real table.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		foreach ( array( 'users_can_register', 'buddynext_reg_mode' ) as $option ) {
			$this->core_options[ $option ] = get_option( $option );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_email_templates" );
	}

	/**
	 * Put back the core options run() writes.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		foreach ( $this->core_options as $option => $value ) {
			if ( false === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}

		parent::tear_down();
	}

	/**
	 * Types the catalogue advertises as emailable.
	 *
	 * @return array<int,string>
	 */
	private function emailable_types(): array {
		$out = array();

		foreach ( (array) ( new NotificationPrefCatalogue() )->all() as $type => $meta ) {
			if ( ! empty( $meta['can_email'] ) ) {
				$out[] = (string) $type;
			}
		}

		return $out;
	}

	/**
	 * Template types present in the database.
	 *
	 * @return array<int,string>
	 */
	private function seeded_types(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT type FROM {$wpdb->prefix}bn_email_templates" ) );
	}

	/**
	 * Every emailable type has a template, so every one of them can send.
	 *
	 * @return void
	 */
	public function test_every_emailable_type_has_a_template(): void {
		Installer::run();

		$missing = array_values( array_diff( $this->emailable_types(), $this->seeded_types() ) );
		sort( $missing );

		$this->assertSame(
			array(),
			$missing,
			"These types are advertised as emailable and have no template, so send_now() returns false and the member gets nothing:\n  "
				. implode( "\n  ", $missing )
		);
	}

	/**
	 * Anything the owner can edit is something that can actually send.
	 *
	 * The other direction, and the one `bn.space_ownership_received` failed: a
	 * template on the Email Templates screen with no row behind it is a control that
	 * looks configured and delivers nothing.
	 *
	 * @return void
	 */
	public function test_every_editable_template_exists_in_the_database(): void {
		Installer::run();

		$editable = array();

		foreach ( ( new \BuddyNext\Admin\EmailEditor() )->get_catalogue() as $group ) {
			foreach ( (array) $group as $type => $unused ) {
				$editable[] = (string) $type;
			}
		}

		$missing = array_values( array_diff( $editable, $this->seeded_types() ) );
		sort( $missing );

		$this->assertSame( array(), $missing, 'Editable on screen, absent from the database: ' . implode( ', ', $missing ) );
	}

	/**
	 * No template exists for a notification type that can never fire.
	 *
	 * `bn.unsuspension_confirmation` was exactly that - seeded, editable, and dead,
	 * while the type that DOES fire when a suspension is lifted
	 * (`bn.user_unsuspended`) had no template at all. One message under two names,
	 * each missing the other half.
	 *
	 * The three auth emails are excluded by name because they are genuinely not
	 * notification types: they are sent directly at registration, verification and
	 * email-change, and never pass through the preference catalogue.
	 *
	 * @return void
	 */
	public function test_no_template_is_stranded_on_a_type_that_never_fires(): void {
		Installer::run();

		// Excluded by name, and each for a stated reason rather than to make the
		// assertion pass.
		//
		// The three auth emails are genuinely not notification types: they are sent
		// directly at registration, verification and email-change, and never pass
		// through the preference catalogue.
		//
		// bn.new_message IS a real type, but it is registered by the messaging
		// integration (WPMediaVerse), which is not loaded in this suite - so it reads
		// as stranded here and is not stranded on a real site. Verified against the
		// dev site, where WPMediaVerse is active and the type is present. Dropping the
		// template instead would break DM mail on every install that has messaging.
		$sent_directly = array( 'welcome', 'email_verify', 'email_change_confirm', 'bn.new_message' );
		$real_types    = array_map( 'strval', array_keys( (array) ( new NotificationPrefCatalogue() )->all() ) );

		$stranded = array_values( array_diff( $this->seeded_types(), $real_types, $sent_directly ) );
		sort( $stranded );

		$this->assertSame(
			array(),
			$stranded,
			'Template rows nothing can ever send: ' . implode( ', ', $stranded )
		);
	}

	/**
	 * Re-running the installer does not double up.
	 *
	 * Load-bearing here: the seeder now merges a second source, and the rename runs
	 * on every pass. The old slug was left in the literal list at first, so each run
	 * re-inserted it and undid the rename - both rows in the table, found by reading
	 * them after a second run rather than trusting the UPDATE.
	 *
	 * @return void
	 */
	public function test_reseeding_creates_no_duplicates(): void {
		Installer::run();
		Installer::run();

		$types = $this->seeded_types();

		$this->assertSame(
			count( array_unique( $types ) ),
			count( $types ),
			'A second run duplicated template rows: ' . implode( ', ', array_diff_assoc( $types, array_unique( $types ) ) )
		);
	}
}
