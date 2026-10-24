<?php
/**
 * On a network, sign-up follows the rules set in Network Admin.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\RegistrationGuard;
use BuddyNext\Auth\RegistrationService;

/**
 * Banned names, banned email domains and limited email domains. Run with
 * WP_MULTISITE=1.
 */
class NetworkSignupRulesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
	}

	public function tear_down(): void {
		delete_site_option( 'banned_email_domains' );
		delete_site_option( 'limited_email_domains' );
		update_site_option( 'illegal_names', array( 'www', 'web', 'root', 'admin', 'main', 'invite', 'administrator' ) );
		parent::tear_down();
	}

	public function test_a_banned_email_domain_is_refused(): void {
		update_site_option( 'banned_email_domains', array( 'blocked.test' ) );

		$this->assertWPError( ( new RegistrationGuard() )->check_domain( 'someone@blocked.test' ) );
		$this->assertTrue( ( new RegistrationGuard() )->check_domain( 'someone@fine.test' ) );
	}

	public function test_limited_email_domains_refuse_every_other_domain(): void {
		update_site_option( 'limited_email_domains', array( 'allowed.test' ) );

		$this->assertTrue( ( new RegistrationGuard() )->check_domain( 'someone@allowed.test' ) );
		$this->assertWPError( ( new RegistrationGuard() )->check_domain( 'someone@other.test' ) );
	}

	public function test_a_banned_name_is_refused_at_the_door(): void {
		update_site_option( 'illegal_names', array( 'forbidden' ) );

		$result = ( new RegistrationService() )->create(
			array(
				'email'      => 'forbidden@fine.test',
				'password'   => 'Passw0rd!long-enough',
				'user_login' => 'forbidden',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'bn_reg_name', $result->get_error_code() );
		$this->assertFalse( username_exists( 'forbidden' ) );
	}
}
