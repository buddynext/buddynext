<?php
/**
 * Space-gate priming for card lists.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\Installer;

/**
 * A page of space cards (directory) or posts in spaces (profile, hashtag feeds)
 * asked "is the viewer banned here?" once per card: a COUNT on bn_space_bans
 * plus a status read on bn_space_members. The page is now answered up front.
 *
 * @covers \BuddyNext\Core\PermissionService::prime_space_bans
 * @covers \BuddyNext\Spaces\SpaceMemberService::membership_map
 * @covers \BuddyNext\Spaces\SpaceService::prime
 */
class SpaceGatePrimingTest extends \WP_UnitTestCase {

	/**
	 * Space ids created for the test.
	 *
	 * @var int[]
	 */
	private array $space_ids = array();

	/**
	 * Five spaces, one of which bans the viewer.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;
		parent::set_up();
		Installer::install_schema();
		for ( $i = 0; $i < 5; $i++ ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'bn_spaces',
				array(
					'name'       => 'Gate space ' . $i,
					'slug'       => 'gate-space-' . $i . '-' . wp_rand(),
					'type'       => 'open',
					'owner_id'   => 1,
					'created_at' => current_time( 'mysql', true ),
				)
			);
			$this->space_ids[] = (int) $wpdb->insert_id;
		}
	}

	public function test_a_page_of_ban_checks_costs_two_queries_not_two_per_card(): void {
		global $wpdb;
		$viewer = self::factory()->user->create();
		$wpdb->insert( $wpdb->prefix . 'bn_space_bans', array( 'space_id' => $this->space_ids[2], 'user_id' => $viewer, 'banned_by' => 1, 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		wp_cache_flush();

		buddynext_service( 'space_members' )->membership_map( $viewer, $this->space_ids );
		buddynext_service( 'permissions' )->prime_space_bans( $viewer, $this->space_ids );

		$before = $wpdb->num_queries;
		$banned = array();
		foreach ( $this->space_ids as $space_id ) {
			$banned[ $space_id ] = buddynext_service( 'permissions' )->is_space_banned( $viewer, $space_id );
		}

		$this->assertSame( 0, $wpdb->num_queries - $before, 'Every per-card ban check is answered from the primed page.' );
		$this->assertTrue( $banned[ $this->space_ids[2] ], 'The primed answer is the real one: the banned space still reads banned.' );
		$this->assertFalse( $banned[ $this->space_ids[0] ] );
	}

	public function test_a_page_of_spaces_loads_in_one_query(): void {
		global $wpdb;
		wp_cache_flush();

		buddynext_service( 'spaces' )->prime( $this->space_ids );
		$before = $wpdb->num_queries;
		foreach ( $this->space_ids as $space_id ) {
			$this->assertSame( $space_id, (int) buddynext_service( 'spaces' )->get( $space_id )['id'] );
		}

		$this->assertSame( 0, $wpdb->num_queries - $before );
	}
}
