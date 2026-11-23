<?php
/**
 * Every email reads in its recipient's language, not the language of whoever
 * triggered it (an admin approving, a member inviting, a cron run).
 *
 * @package BuddyNext\Tests\Notifications
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Notifications;

use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\RegistrationEmailListener;
use BuddyNext\Auth\TwoFactorService;
use BuddyNext\Auth\VerificationListener;
use BuddyNext\Auth\VerificationService;
use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\InviteService;

/**
 * A stand-in "German" marks every BuddyNext string translated while de_DE is the
 * active locale, so a test can tell which language an email was built in.
 *
 * @covers \BuddyNext\Notifications\EmailSender::in_recipient_locale
 */
class RecipientLocaleTest extends \WP_UnitTestCase {

	/**
	 * Captured mails.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * The locale switcher in place before the test.
	 *
	 * @var \WP_Locale_Switcher|null
	 */
	private $switcher;

	/**
	 * A member whose profile language is de_DE.
	 *
	 * @var int
	 */
	private int $german;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		add_filter( 'get_available_languages', static fn( $langs ) => array_merge( (array) $langs, array( 'de_DE' ) ) );
		$this->switcher                 = $GLOBALS['wp_locale_switcher'] ?? null;
		$GLOBALS['wp_locale_switcher'] = new \WP_Locale_Switcher();
		$GLOBALS['wp_locale_switcher']->init();

		add_filter(
			'gettext',
			static fn( $translation, $text, $domain ) => ( 'buddynext' === $domain && 'de_DE' === determine_locale() ) ? '[de] ' . $translation : $translation,
			10,
			3
		);
		add_filter(
			'pre_wp_mail',
			function ( $short, array $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);

		$this->german = self::factory()->user->create( array( 'user_email' => 'german@example.test' ) );
		update_user_meta( $this->german, 'locale', 'de_DE' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->mails = array(); // Only the mail under test.
	}

	public function tear_down(): void {
		$GLOBALS['wp_locale_switcher'] = $this->switcher;
		parent::tear_down();
	}

	/**
	 * The one mail sent, as subject + body.
	 *
	 * @return array{0:string,1:string}
	 */
	private function sent(): array {
		$this->assertCount( 1, $this->mails );
		return array( (string) $this->mails[0]['subject'], (string) $this->mails[0]['message'] );
	}

	/**
	 * Assert the mail was built in German, subject and body.
	 *
	 * @return void
	 */
	private function assert_german(): void {
		list( $subject, $body ) = $this->sent();
		$this->assertStringStartsWith( '[de] ', $subject );
		$this->assertStringContainsString( '[de] ', $body );
	}

	public function test_sign_in_code_reads_in_the_members_language(): void {
		TwoFactorService::send_email_code( $this->german );
		$this->assert_german();
	}

	public function test_email_change_confirmation_reads_in_the_members_language(): void {
		( new VerificationListener( new VerificationService() ) )->on_email_change_requested( $this->german, 'new@example.test' );
		$this->assert_german();
	}

	public function test_rejection_reads_in_the_members_language_not_the_admins(): void {
		( new RegistrationEmailListener() )->on_member_rejected( $this->german );
		$this->assert_german();
	}

	public function test_set_password_welcome_reads_in_the_members_language(): void {
		AuthController::send_set_password_email( get_userdata( $this->german ) );
		$this->assert_german();
	}

	public function test_an_invite_reads_in_the_site_language_not_the_inviters(): void {
		update_user_meta( get_current_user_id(), 'locale', 'de_DE' );
		set_current_screen( 'dashboard' ); // The inviter's admin request runs in their language.

		$send = new \ReflectionMethod( InviteService::class, 'send_invite_email' );
		$send->invoke(
			new InviteService(),
			array(
				'email'      => 'invitee@example.test',
				'first_name' => 'Ana',
				'token'      => 'abc',
			)
		);

		list( $subject, $body ) = $this->sent();
		$this->assertStringNotContainsString( '[de]', $subject . $body );
	}
}
