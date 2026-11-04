<?php
/**
 * Redirects are decided before the page starts printing, never inside a template.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;

/**
 * Pre-output redirect tests.
 *
 * @covers \BuddyNext\Core\PageRouter::pre_output_redirect
 */
class PreOutputRedirectTest extends \WP_UnitTestCase {

	/**
	 * Verification on, a clean query string, nobody signed in.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( 'buddynext_email_verify', true );
		unset( $_GET['bn_verified'], $_GET['bn_email_changed'], $_GET['email'] );
		wp_set_current_user( 0 );
	}

	/**
	 * Leave the query string as it was found.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['bn_verified'], $_GET['bn_email_changed'], $_GET['email'] );
		parent::tear_down();
	}

	/**
	 * Everyone with nothing to see on the verify page is sent somewhere useful.
	 *
	 * @return void
	 */
	public function test_verify_page_sends_away_who_has_no_inbox_to_check(): void {
		$this->assertSame( PageRouter::login_url( '' ), PageRouter::pre_output_redirect( 'auth/verify.php' ), 'Guest with no hint.' );

		$verified = self::factory()->user->create();
		update_user_meta( $verified, 'buddynext_email_verified', 1 );
		wp_set_current_user( $verified );
		$this->assertSame( PageRouter::activity_url(), PageRouter::pre_output_redirect( 'auth/verify.php' ), 'Already verified.' );
	}

	/**
	 * Who the page is for still gets it.
	 *
	 * @return void
	 */
	public function test_verify_page_renders_for_who_it_is_for(): void {
		$_GET['email'] = 'new@example.test';
		$this->assertSame( '', PageRouter::pre_output_redirect( 'auth/verify.php' ), 'Guest who just signed up.' );
		unset( $_GET['email'] );

		$_GET['bn_verified'] = '0';
		$this->assertSame( '', PageRouter::pre_output_redirect( 'auth/verify.php' ), 'Guest on a result link.' );
		unset( $_GET['bn_verified'] );

		$unverified = self::factory()->user->create();
		delete_user_meta( $unverified, 'buddynext_email_verified' );
		wp_set_current_user( $unverified );
		$this->assertSame( '', PageRouter::pre_output_redirect( 'auth/verify.php' ), 'Unverified member.' );
	}

	/**
	 * With verification off there is no inbox to wait on, but a result link still shows.
	 *
	 * @return void
	 */
	public function test_verification_off(): void {
		update_option( 'buddynext_email_verify', false );
		$this->assertSame( PageRouter::activity_url(), PageRouter::pre_output_redirect( 'auth/verify.php' ) );

		$_GET['bn_email_changed'] = '1';
		$this->assertSame( '', PageRouter::pre_output_redirect( 'auth/verify.php' ) );
	}

	/**
	 * A guest on an edit page signs in and comes back; a member and other templates pass.
	 *
	 * @return void
	 */
	public function test_profile_edit_needs_a_session(): void {
		$this->assertNotSame( '', PageRouter::pre_output_redirect( 'profile/edit.php' ) );
		$this->assertSame( '', PageRouter::pre_output_redirect( 'profile/view.php' ) );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( '', PageRouter::pre_output_redirect( 'profile/edit.php' ) );
	}

	/**
	 * A redirect inside a template can only ever produce a blank page.
	 *
	 * @return void
	 */
	public function test_no_template_redirects(): void {
		$found = array();
		foreach ( array( BUDDYNEXT_DIR . 'templates', dirname( BUDDYNEXT_DIR ) . '/buddynext-pro/templates' ) as $root ) {
			if ( ! is_dir( $root ) ) {
				continue;
			}
			$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $files as $file ) {
				if ( 'php' === $file->getExtension() && 1 === preg_match( '/\bwp_(safe_)?redirect\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
					$found[] = $file->getPathname();
				}
			}
		}
		$this->assertSame( array(), $found );
	}
}
