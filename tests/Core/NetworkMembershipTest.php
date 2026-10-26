<?php
/**
 * A network user joins the community on their first action, never by browsing.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * Run with WP_MULTISITE=1. Separate processes because a REST write is simulated
 * with the REST_REQUEST constant, which cannot be undefined again.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NetworkMembershipTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		update_option( 'buddynext_reg_mode', 'open' );
	}

	/**
	 * A user who exists on the network but has no role on the main site.
	 *
	 * @return int
	 */
	private function network_user(): int {
		$user_id = self::factory()->user->create();
		remove_user_from_blog( $user_id, get_main_site_id() );
		return $user_id;
	}

	private function as_rest_write( int $acting_user = 0 ): void {
		wp_set_current_user( $acting_user );
		define( 'REST_REQUEST', true );
		$_SERVER['REQUEST_METHOD'] = 'POST';
	}

	public function test_a_check_about_someone_else_never_adds_them(): void {
		$user_id = $this->network_user();
		$this->as_rest_write( self::factory()->user->create() );

		buddynext_can( $user_id, 'buddynext-feed/create-post' );

		$this->assertFalse( is_user_member_of_blog( $user_id, get_main_site_id() ) );
	}

	public function test_browsing_does_not_add_anyone(): void {
		$user_id = $this->network_user();

		buddynext_can( $user_id, 'buddynext-feed/create-post' );

		$this->assertFalse( is_user_member_of_blog( $user_id, get_main_site_id() ) );
	}

	public function test_the_first_write_joins_the_main_site_with_its_default_role(): void {
		update_option( 'default_role', 'contributor' );
		$user_id = $this->network_user();
		$this->as_rest_write( $user_id );

		$this->assertTrue( buddynext_can( $user_id, 'buddynext-feed/create-post' ) );

		$this->assertTrue( is_user_member_of_blog( $user_id, get_main_site_id() ) );
		$this->assertContains( 'contributor', get_userdata( $user_id )->roles );
	}

	public function test_approval_mode_puts_the_first_write_in_the_pending_queue(): void {
		update_option( 'buddynext_reg_mode', 'approval' );
		$user_id = $this->network_user();
		$this->as_rest_write( $user_id );

		$this->assertFalse( buddynext_can( $user_id, 'buddynext-feed/create-post' ) );

		$this->assertTrue( is_user_member_of_blog( $user_id, get_main_site_id() ) );
		$this->assertSame( '1', get_user_meta( $user_id, 'bn_pending_approval', true ) );
	}

	public function test_a_closed_community_refuses_and_adds_nobody(): void {
		update_option( 'buddynext_reg_mode', 'closed' );
		$user_id = $this->network_user();
		$this->as_rest_write( $user_id );

		$this->assertFalse( buddynext_can( $user_id, 'buddynext-feed/create-post' ) );

		$this->assertFalse( is_user_member_of_blog( $user_id, get_main_site_id() ) );
	}
}
