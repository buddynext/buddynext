<?php
/**
 * A visitor who signs up from a page is taken back to it, once.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\AuthController;
use BuddyNext\Core\PageRouter;

/**
 * Signup return tests.
 *
 * @covers \BuddyNext\Auth\AuthController::remember_signup_return
 * @covers \BuddyNext\Auth\AuthController::take_signup_return
 * @covers \BuddyNext\Core\PageRouter::signup_url
 */
class SignupReturnTest extends \WP_UnitTestCase {

	/**
	 * The remembered page is given back once, then the fallback.
	 *
	 * @return void
	 */
	public function test_return_page_is_given_once(): void {
		$user = self::factory()->user->create();
		$page = home_url( '/members/' );

		AuthController::remember_signup_return( $user, $page );

		$this->assertSame( $page, AuthController::take_signup_return( $user, 'fallback' ) );
		$this->assertSame( 'fallback', AuthController::take_signup_return( $user, 'fallback' ) );
	}

	/**
	 * Another site's address is never remembered.
	 *
	 * @return void
	 */
	public function test_off_site_page_is_dropped(): void {
		$user = self::factory()->user->create();

		AuthController::remember_signup_return( $user, 'https://evil.example/phish' );
		AuthController::remember_signup_return( $user, '//evil.example/x' );

		$this->assertSame( 'fallback', AuthController::take_signup_return( $user, 'fallback' ) );
	}

	/**
	 * The plain signup address stays plain; an explicit page is carried.
	 *
	 * @return void
	 */
	public function test_signup_url_carries_only_what_it_is_given(): void {
		$this->assertStringNotContainsString( 'redirect_to', PageRouter::signup_url() );
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( home_url( '/members/' ) ), PageRouter::signup_url( home_url( '/members/' ) ) );
	}
}
