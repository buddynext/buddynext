<?php
/**
 * Removing a member on a network: community data goes, the account only when safe.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

/**
 * Run with WP_MULTISITE=1. The canonical purge announces itself with
 * `buddynext_purge_user_data`, so counting that event counts purges.
 */
class RemoveMemberMultisiteTest extends \WP_UnitTestCase {

	/** @var int[] User ids the purge event fired for. */
	private array $purged = array();

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only: run with WP_MULTISITE=1.' );
		}
		$this->purged = array();
		add_action(
			'buddynext_purge_user_data',
			function ( $user_id ): void {
				$this->purged[] = (int) $user_id;
			}
		);
	}

	public function test_removing_someone_from_the_main_site_purges_nothing(): void {
		$user_id = self::factory()->user->create();

		remove_user_from_blog( $user_id, get_main_site_id() );

		$this->assertSame( array(), $this->purged, '"Remove from site" must not erase community data.' );
		$this->assertNotFalse( get_userdata( $user_id ) );
	}

	public function test_deleting_the_account_purges_once(): void {
		$user_id = self::factory()->user->create();
		require_once ABSPATH . 'wp-admin/includes/ms.php';

		wpmu_delete_user( $user_id );

		$this->assertSame( array( $user_id ), $this->purged, 'wpmu_delete_user also fires deleted_user: one purge, not two.' );
		$this->assertFalse( get_userdata( $user_id ) );
	}

	public function test_a_site_admin_rejecting_a_user_who_is_on_another_site_removes_them_from_main_only(): void {
		$user_id  = self::factory()->user->create();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$site_id  = (int) self::factory()->blog->create();
		add_user_to_blog( $site_id, $user_id, 'subscriber' );

		$this->assertTrue( buddynext_remove_member( $user_id, $admin_id ) );

		$this->assertSame( array( $user_id ), $this->purged );
		$this->assertNotFalse( get_userdata( $user_id ), 'The account stays.' );
		$this->assertFalse( is_user_member_of_blog( $user_id, get_main_site_id() ) );
		$this->assertTrue( is_user_member_of_blog( $user_id, $site_id ), 'Their other site is untouched.' );
	}

	public function test_a_member_deleting_their_own_account_when_on_no_other_site_deletes_it(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( buddynext_remove_member( $user_id, $user_id ) );

		$this->assertSame( array( $user_id ), $this->purged );
		$this->assertFalse( get_userdata( $user_id ) );
	}

	public function test_a_site_admin_cannot_delete_an_account_even_when_it_is_on_one_site_only(): void {
		$user_id  = self::factory()->user->create();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		buddynext_remove_member( $user_id, $admin_id );

		$this->assertNotFalse( get_userdata( $user_id ), 'Deleting an account is for the member, a system job or a super admin.' );
		$this->assertSame( array( $user_id ), $this->purged );
	}

	public function test_a_system_job_deletes_an_account_that_is_on_no_other_site(): void {
		$user_id = self::factory()->user->create();

		$this->assertTrue( buddynext_remove_member( $user_id ) );

		$this->assertFalse( get_userdata( $user_id ) );
	}
}
