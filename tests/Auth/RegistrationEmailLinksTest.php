<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The account emails (approved, pending, welcome fallback) carry clickable links.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationEmailListener;
use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * The sign-in address in the approval email was plain text, not a link.
 *
 * @covers \BuddyNext\Auth\RegistrationEmailListener
 */
class RegistrationEmailLinksTest extends WP_UnitTestCase {

	/**
	 * Captured mails.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = array();

	/**
	 * Schema and a mail catcher.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		add_filter(
			'pre_wp_mail',
			function ( $short, array $atts ) {
				$this->mails[] = $atts;
				return true;
			},
			10,
			2
		);
	}

	/**
	 * The approval email links the sign-in page and still escapes the member's name.
	 *
	 * @return void
	 */
	public function test_approval_email_links_the_sign_in_page(): void {
		$uid = self::factory()->user->create(
			array(
				'user_email'   => 'approved@example.test',
				'display_name' => 'Ann <b>Bold</b>',
			)
		);

		$this->mails = array(); // Only the mail under test, not the sign-up welcome.
		( new RegistrationEmailListener() )->on_member_approved( $uid );

		$this->assertCount( 1, $this->mails );
		$body = (string) $this->mails[0]['message'];
		$this->assertStringContainsString( '<a href="' . esc_url( PageRouter::login_url( '' ) ) . '"', $body );
		$this->assertStringNotContainsString( '<b>Bold</b>', $body, 'The member name stays escaped.' );
	}

	/**
	 * The "awaiting approval" mail to the site admin links the Pending list, not the Invites list.
	 *
	 * @return void
	 */
	public function test_admin_notice_links_the_pending_members_list(): void {
		$uid = self::factory()->user->create( array( 'user_email' => 'waiting@example.test' ) );

		$this->mails = array();
		( new RegistrationEmailListener() )->on_registration_pending( $uid, 'waiting@example.test' );

		$admin = array_values( array_filter( $this->mails, static fn( $m ) => 0 === strpos( (string) $m['subject'], 'New registration awaiting approval' ) ) );
		$this->assertNotEmpty( $admin, 'The admin notice was not sent.' );
		$this->assertStringContainsString( 'tab=pending', (string) $admin[0]['message'] );
		$this->assertStringNotContainsString( 'tab=invites', (string) $admin[0]['message'] );
	}
}
