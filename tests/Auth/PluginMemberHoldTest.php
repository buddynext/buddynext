<?php
/**
 * A plugin-held sign-up is neither welcomed nor listed.
 *
 * @package BuddyNext\Tests\Auth
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Auth;

use BuddyNext\Auth\ApprovalGuard;
use BuddyNext\Core\Installer;

/**
 * One hold check, read by the welcome email and the member directory.
 *
 * @covers \BuddyNext\Auth\ApprovalGuard::hold_meta_keys
 * @covers \BuddyNext\Auth\ApprovalGuard::is_held
 * @covers \BuddyNext\Auth\RegistrationEmailListener::send_welcome
 * @covers \BuddyNext\Profile\MemberDirectoryService::exclusion_clauses
 */
class PluginMemberHoldTest extends \WP_UnitTestCase {

	/**
	 * Install tables and make the filter-set key a hold, as a partner plugin would.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		delete_option( 'buddynext_reg_mode' );
		update_option( 'buddynext_email_verify', false );
		add_filter(
			'buddynext_member_hold_meta_keys',
			static function ( $keys ) {
				$keys[] = '_partner_email_unverified';
				return $keys;
			}
		);
	}

	/**
	 * Sign up a member the partner holds from the first moment (before user_register).
	 *
	 * @return int User ID.
	 */
	private function held_signup(): int {
		$hold = static function ( $meta ) {
			$meta['_partner_email_unverified'] = '1';
			return $meta;
		};
		add_filter( 'insert_user_meta', $hold );
		$id = self::factory()->user->create();
		remove_filter( 'insert_user_meta', $hold );
		return $id;
	}

	public function test_a_held_signup_gets_no_welcome_and_is_not_listed(): void {
		$held  = $this->held_signup();
		$clear = self::factory()->user->create();

		$this->assertTrue( ApprovalGuard::is_held( $held ) );
		$this->assertFalse( ApprovalGuard::is_held( $clear ) );
		$this->assertSame( '', (string) get_user_meta( $held, 'bn_welcome_sent', true ), 'Held member was welcomed.' );
		$this->assertNotSame( '', (string) get_user_meta( $clear, 'bn_welcome_sent', true ), 'Control: an ordinary sign-up is welcomed.' );

		global $wpdb;
		$where = implode( ' AND ', \BuddyNext\Profile\MemberDirectoryService::exclusion_clauses( 'u.ID' ) );
		$ids   = array_map( 'intval', $wpdb->get_col( "SELECT u.ID FROM {$wpdb->users} u WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertNotContains( $held, $ids, 'Held member is listed.' );
		$this->assertContains( $clear, $ids );
	}

	public function test_releasing_the_hold_lists_and_welcomes_the_member(): void {
		$held = $this->held_signup();
		delete_user_meta( $held, '_partner_email_unverified' );
		do_action( 'buddynext_user_verified', $held );

		$this->assertFalse( ApprovalGuard::is_held( $held ) );
		$this->assertNotSame( '', (string) get_user_meta( $held, 'bn_welcome_sent', true ) );
	}

	public function test_a_filter_that_empties_the_list_keeps_the_approval_hold(): void {
		remove_all_filters( 'buddynext_member_hold_meta_keys' );
		add_filter( 'buddynext_member_hold_meta_keys', '__return_empty_array' );

		$this->assertSame( array( 'bn_pending_approval' ), ApprovalGuard::hold_meta_keys() );
	}

	public function test_accounts_from_other_registration_paths_are_never_held(): void {
		// wp_insert_user, a shop customer (WooCommerce, EDD) and a plain admin-created user: no registered key, so nothing is held.
		$plain    = wp_insert_user( array( 'user_login' => 'plain_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'plain' . wp_generate_password( 6, false ) . '@example.test' ) );
		$customer = wp_insert_user( array( 'user_login' => 'cust_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'cust' . wp_generate_password( 6, false ) . '@example.test', 'role' => 'subscriber' ) );
		update_user_meta( $customer, 'billing_email', 'shop@example.test' ); // Shop plugins add their own meta: unknown keys never hold.

		foreach ( array( $plain, $customer ) as $id ) {
			$this->assertFalse( ApprovalGuard::is_held( (int) $id ), 'An account from another path was held.' );
		}
		global $wpdb;
		$where = implode( ' AND ', \BuddyNext\Profile\MemberDirectoryService::exclusion_clauses( 'u.ID' ) );
		$ids   = array_map( 'intval', $wpdb->get_col( "SELECT u.ID FROM {$wpdb->users} u WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$this->assertContains( (int) $plain, $ids );
		$this->assertContains( (int) $customer, $ids );
	}
}
