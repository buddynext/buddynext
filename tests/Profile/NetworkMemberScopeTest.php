<?php
/**
 * Member lists and counts agree with "a member has a role on the main site".
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Profile\MemberDirectoryService;

/**
 * A network user who never joined the community must not show in its directory
 * or inflate its member counts. Run with WP_MULTISITE=1.
 */
class NetworkMemberScopeTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		wp_cache_flush();
	}

	public function test_the_directory_list_leaves_out_network_users_with_no_main_site_role(): void {
		$member  = self::factory()->user->create();
		$outside = self::factory()->user->create();
		remove_user_from_blog( $outside, get_main_site_id() );
		wp_cache_flush();

		$service = new MemberDirectoryService();
		$ids     = array_map( static fn( $m ) => (int) ( $m['id'] ?? $m['user_id'] ?? $m['ID'] ?? 0 ), $service->list_members( 0, null, 50 )['items'] );

		$this->assertContains( $member, $ids );
		$this->assertNotContains( $outside, $ids );
	}

	public function test_the_member_scope_predicate_counts_only_main_site_users(): void {
		global $wpdb;
		$member  = self::factory()->user->create();
		$outside = self::factory()->user->create();
		remove_user_from_blog( $outside, get_main_site_id() );

		$in = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" . buddynext_member_scope_sql( $wpdb->users . '.ID', ' WHERE ' ) );
		$all = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );

		$this->assertSame( 1, $all - $in, 'Exactly the outsider is left out.' );
		$this->assertNotSame( '', buddynext_member_scope_sql( 'x' ) );
		$this->assertNotSame( 0, $member );
	}
}
