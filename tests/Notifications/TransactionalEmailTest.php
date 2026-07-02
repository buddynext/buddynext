<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests for the transactional account-email path (verification + welcome).
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Core\Installer;
use BuddyNext\Notifications\EmailSender;
use BuddyNext\Notifications\NotificationPrefCatalogue;
use BuddyNext\Notifications\NotificationPrefService;

/**
 * Transactional emails bypass the notification-preference machinery
 * (Basecamp 10056257192) and the welcome email renders from its
 * owner-editable template row (Basecamp 10056336232).
 *
 * @covers \BuddyNext\Notifications\EmailSender
 */
class TransactionalEmailTest extends \WP_UnitTestCase {

	/**
	 * System under test.
	 *
	 * @var EmailSender
	 */
	private EmailSender $sender;

	/**
	 * Test recipient.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Captured wp_mail() calls.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = array();

	/**
	 * Create schema, sender, recipient, and a wp_mail capture.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->sender  = new EmailSender( new NotificationPrefService(), new NotificationPrefCatalogue() );
		$this->user_id = self::factory()->user->create();
		$this->sent    = array();
		add_filter(
			'pre_wp_mail',
			function ( $short, array $atts ) {
				$this->sent[] = $atts;
				return true; // Short-circuit the real send.
			},
			10,
			2
		);
	}

	/**
	 * The email_verify type is not in the pref catalogue but MUST still send —
	 * the transactional bypass (previously it was silently dropped).
	 */
	public function test_email_verify_sends_despite_absent_catalogue_entry(): void {
		$this->assertFalse( ( new NotificationPrefCatalogue() )->can_email( 'email_verify' ) );

		$this->sender->send( $this->user_id, 'email_verify', array( 'verify_url' => 'https://example.test/verify' ) );

		$this->assertCount( 1, $this->sent, 'Verification email must dispatch through the transactional path.' );
		$this->assertStringContainsString( 'Verify your email address', (string) $this->sent[0]['subject'] );
	}

	/**
	 * Transactional emails ignore the member's master email-channel opt-out —
	 * an account email is not a notification preference.
	 */
	public function test_transactional_ignores_master_email_optout(): void {
		( new NotificationPrefService() )->set_channel_prefs( $this->user_id, array( 'email' => false ) );

		$this->sender->send( $this->user_id, 'email_verify', array( 'verify_url' => 'https://example.test/verify' ) );

		$this->assertCount( 1, $this->sent );
	}

	/**
	 * The welcome email renders the owner-editable template row: editing the
	 * subject in bn_email_templates changes what the member receives.
	 */
	public function test_welcome_renders_owner_edited_template(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_email_templates SET subject = 'Owner edited welcome for {{site_name}}' WHERE type = 'welcome'" );

		$this->sender->send( $this->user_id, 'welcome', array() );

		$this->assertCount( 1, $this->sent );
		$this->assertStringContainsString( 'Owner edited welcome', (string) $this->sent[0]['subject'] );
	}

	/**
	 * A disabled welcome template suppresses the send (owner turned it off).
	 */
	public function test_disabled_welcome_template_suppresses_send(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_email_templates SET enabled = 0 WHERE type = 'welcome'" );

		$this->sender->send( $this->user_id, 'welcome', array() );

		$this->assertCount( 0, $this->sent );
	}

	/**
	 * The v19 subject migration rewrites former seeded subjects but never an
	 * owner-customized one.
	 */
	public function test_subject_migration_skips_owner_customized_rows(): void {
		global $wpdb;
		$em = "\xE2\x80\x94";
		// Simulate one pre-v19 seeded row and one owner-customized row.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_email_templates SET subject = %s WHERE type = 'bn.post_commented'", "New comment on your post {$em} {{site_name}}" ) );
		$wpdb->query( "UPDATE {$wpdb->prefix}bn_email_templates SET subject = 'My custom badge subject' WHERE type = 'bn.badge_awarded'" );

		Installer::maybe_upgrade();
		update_option( 'buddynext_schema_version', 0 ); // Force the runner.
		Installer::maybe_upgrade();

		$commented = (string) $wpdb->get_var( "SELECT subject FROM {$wpdb->prefix}bn_email_templates WHERE type = 'bn.post_commented'" );
		$badge     = (string) $wpdb->get_var( "SELECT subject FROM {$wpdb->prefix}bn_email_templates WHERE type = 'bn.badge_awarded'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( 'New comment on your {{site_name}} post', $commented, 'Former seeded subject converges.' );
		$this->assertSame( 'My custom badge subject', $badge, 'Owner-customized subject is never overwritten.' );
	}
}
