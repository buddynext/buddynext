<?php
/**
 * A hub mapped to a real page must answer is_page()/is_singular() true BEFORE
 * template_redirect, so a membership/access gate can recognise (and exempt) the
 * login hub instead of redirecting it to itself forever (card 10317628894).
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Core\PageRouter::align_hub_page_conditionals
 */
class AuthHubIsPageTest extends WP_UnitTestCase {

	private PageRouter $router;
	private int $login_page;

	public function set_up(): void {
		parent::set_up();
		$this->router     = new PageRouter();
		$this->login_page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'login',
				'post_title' => 'Login',
			)
		);
		update_option( 'buddynext_page_auth', $this->login_page );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_page_auth' );
		parent::tear_down();
	}

	/**
	 * Drive align_hub_page_conditionals() for a given hub value.
	 *
	 * @param string $hub The bn_hub query var to simulate.
	 * @return void
	 */
	private function align_for_hub( string $hub ): void {
		global $wp_query;
		// The rewrite blanks the page query and serves a synthetic post, so the
		// query starts NOT a page - exactly the state the auth hub reaches.
		$wp_query->is_page       = false;
		$wp_query->is_singular   = false;
		$wp_query->is_home       = true;
		$wp_query->queried_object    = null;
		$wp_query->queried_object_id = 0;
		set_query_var( 'bn_hub', $hub );
		$this->router->align_hub_page_conditionals();
	}

	public function test_login_hub_answers_is_page_for_its_mapped_page(): void {
		$this->align_for_hub( 'auth' );

		$this->assertTrue( is_page(), 'the login hub must report is_page() after alignment' );
		$this->assertTrue( is_page( 'login' ), 'is_page(slug) must match so a gate can exempt the login page' );
		$this->assertTrue( is_page( $this->login_page ), 'is_page(id) must match the mapped page' );
		$this->assertTrue( is_singular() );
		$this->assertFalse( is_home() );
		$this->assertSame( $this->login_page, get_queried_object_id() );
	}

	public function test_a_hub_with_no_mapped_page_is_left_untouched(): void {
		delete_option( 'buddynext_page_auth' );
		$this->align_for_hub( 'auth' );

		// No mapped page -> the method is a no-op, so the synthetic-post state stands.
		$this->assertFalse( is_page() );
	}

	public function test_a_non_hub_request_is_left_untouched(): void {
		global $wp_query;
		$wp_query->is_page = false;
		set_query_var( 'bn_hub', '' );
		$this->router->align_hub_page_conditionals();

		$this->assertFalse( is_page(), 'a request that is not a hub must not be forced into is_page()' );
	}
}
