<?php
/**
 * Space links: the service, its rules about who loses a seat, and the REST read.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Addons\AddonRegistry;
use BuddyNext\Addons\ModuleWiring;
use BuddyNext\Dev\AddonFixture\FixtureAddon;
use BuddyNext\Dev\AddonFixture\Modules\Links;
use BuddyNext\Integrations\IntegrationRegistry;
use BuddyNext\Spaces\SpaceLinkService;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Driven through the fixture addon, so the SpaceLinkSource wiring is proved too.
 */
class SpaceLinkServiceTest extends WP_UnitTestCase {

	private SpaceLinkService $links;

	private SpaceMemberService $members;

	private int $owner;

	/**
	 * Registered fixture addon and an empty roster for every test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		AddonRegistry::reset();
		ModuleWiring::reset();
		IntegrationRegistry::instance()->reset();
		FixtureAddon::$active  = true;
		FixtureAddon::$version = '2.0.0';
		FixtureAddon::$api     = 1;
		Links::$rosters        = array();

		$addon = new FixtureAddon();
		AddonRegistry::instance()->register( $addon );
		$addon->wire();

		$this->links   = new SpaceLinkService();
		$this->members = new SpaceMemberService();
		$this->owner   = self::factory()->user->create();
	}

	/**
	 * A space, optionally with a type.
	 *
	 * @param string $type Space type.
	 * @return int
	 */
	private function space( string $type = 'open' ): int {
		$id = ( new SpaceService() )->create(
			$this->owner,
			array(
				'name' => 'Space ' . wp_generate_password( 6, false ),
				'slug' => 'sp-' . strtolower( wp_generate_password( 8, false ) ),
				'type' => $type,
			)
		);
		$this->assertIsInt( $id );
		return $id;
	}

	public function test_link_refuses_what_it_cannot_act_on(): void {
		$space = $this->space();

		$this->assertFalse( $this->links->link( $space, 'nobody', 'group', 1 ), 'unknown source' );
		$this->assertFalse( $this->links->link( $space, 'fixture', 'course', 1 ), 'type the source does not offer' );
		$this->assertFalse( $this->links->link( 9999999, 'fixture', 'group', 1 ), 'missing space' );
		$this->assertFalse( $this->links->link( $space, 'fixture', 'group', 0 ), 'no object' );
		$this->assertSame( array(), $this->links->links( $space ) );
	}

	public function test_link_is_idempotent_and_an_object_feeds_one_space(): void {
		$a = $this->space();
		$b = $this->space();

		$this->assertTrue( $this->links->link( $a, 'fixture', 'group', 7 ) );
		$this->assertTrue( $this->links->link( $a, 'fixture', 'group', 7 ), 'again is a no-op' );
		$this->assertCount( 1, $this->links->links( $a ) );
		$this->assertFalse( $this->links->link( $b, 'fixture', 'group', 7 ), 'not stolen by a second space' );
		$this->assertSame( array( $a ), $this->links->spaces_for_object( 'fixture', 'group', 7 ) );
	}

	public function test_link_backfills_the_roster_quietly_and_a_hand_made_member_is_untouched(): void {
		$space  = $this->space();
		$people = self::factory()->user->create_many( 3 );
		Links::$rosters[7] = $people;

		$fired = array();
		add_action(
			'buddynext_space_member_joined',
			static function ( int $space_id, int $user_id ) use ( &$fired ): void {
				$fired[ $user_id ] = SpaceLinkService::is_system_join();
			},
			1,
			2
		);

		$this->assertTrue( $this->links->link( $space, 'fixture', 'group', 7 ) );

		foreach ( $people as $id ) {
			$this->assertTrue( $this->members->is_member( $space, $id ) );
			$this->assertTrue( $fired[ $id ], 'joined as a system join' );
		}
		$this->assertFalse( SpaceLinkService::is_system_join(), 'flag clears afterwards' );
	}

	public function test_system_join_makes_no_owner_notification(): void {
		$space = $this->space();
		$user  = self::factory()->user->create();
		global $wpdb;
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_join'", $this->owner ) );

		$this->links->join_from_source( $space, $user );
		$after_system = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_join'", $this->owner ) );
		$this->assertSame( $before, $after_system, 'a linked join is not news to the owner' );

		$member = self::factory()->user->create();
		$this->members->join( $space, $member );
		$after_human = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_notifications WHERE recipient_id = %d AND type = 'bn.space_join'", $this->owner ) );
		$this->assertSame( $before + 1, $after_human, 'a member joining on their own still is' );
	}

	public function test_backfill_runs_in_bounded_chunks_and_drains_a_large_roster(): void {
		$space = $this->space();
		$ids   = range( 700001, 702000 );
		Links::$rosters[7] = $ids;
		$runs  = 0;
		add_filter( 'buddynext_space_link_backfill_chunk_size', static fn(): int => 500 );

		$this->links->link( $space, 'fixture', 'group', 7 ); // First page runs inline: 500.
		$joined = 500;
		$this->assertTrue( $this->members->is_member( $space, 700500 ) );
		$this->assertFalse( $this->members->is_member( $space, 700501 ), 'the rest waits for the queue' );

		for ( $after = 700500; $after < 702000; $after += 500 ) {
			$this->links->run_backfill_chunk( $space, 'fixture', 'group', 7, $after );
			++$runs;
			$joined += 500;
		}
		$this->assertSame( 3, $runs );
		$this->assertSame( 2000, $joined );
		$this->assertTrue( $this->members->is_member( $space, 702000 ) );
	}

	public function test_a_chunk_for_a_link_that_was_removed_does_nothing(): void {
		$space = $this->space();
		Links::$rosters[7] = array( 800001, 800002 );
		$this->links->link( $space, 'fixture', 'group', 7 );
		$this->links->unlink( $space, 'fixture', 'group', 7 );
		Links::$rosters[7][] = 800003;

		$this->links->run_backfill_chunk( $space, 'fixture', 'group', 7, 800002 );

		$this->assertFalse( $this->members->is_member( $space, 800003 ) );
	}

	public function test_a_hand_linked_space_never_loses_a_member(): void {
		$space = $this->space();
		$user  = self::factory()->user->create();
		Links::$rosters[7] = array( $user );
		$this->links->link( $space, 'fixture', 'group', 7 );
		Links::$rosters[7] = array();

		$this->assertFalse( $this->links->is_managed( $space ) );
		$this->assertFalse( $this->links->leave_from_source( $space, $user, 'fixture', 'group', 7 ) );
		$this->assertTrue( $this->members->is_member( $space, $user ) );
	}

	public function test_a_managed_space_removes_a_member_unless_another_link_still_grants_access(): void {
		$user  = self::factory()->user->create();
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$this->assertGreaterThan( 0, $space );
		$this->assertTrue( $this->links->is_managed( $space ) );
		$this->assertSame( 'Group 7', ( new SpaceService() )->get( $space )['name'] );

		$this->links->join_from_source( $space, $user );
		$this->links->link( $space, 'fixture', 'group', 8 );

		Links::$rosters[8] = array( $user ); // The other group still grants access.
		$this->assertFalse( $this->links->leave_from_source( $space, $user, 'fixture', 'group', 7 ) );
		$this->assertTrue( $this->members->is_member( $space, $user ) );

		Links::$rosters[8] = array(); // Nothing grants it any more.
		$this->assertTrue( $this->links->leave_from_source( $space, $user, 'fixture', 'group', 7 ) );
		$this->assertFalse( $this->members->is_member( $space, $user ) );
	}

	public function test_the_owner_is_never_removed(): void {
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );

		$this->assertFalse( $this->links->leave_from_source( $space, $this->owner, 'fixture', 'group', 7 ) );
		$this->assertTrue( $this->members->is_member( $space, $this->owner ) );
	}

	public function test_a_link_whose_addon_is_off_cannot_be_asked_so_it_keeps_the_member(): void {
		$user  = self::factory()->user->create();
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$this->links->join_from_source( $space, $user );
		add_space_meta( $space, SpaceLinkService::META_PREFIX . 'gone_addon:thing:1', gmdate( 'Y-m-d H:i:s' ) );

		$this->assertFalse( $this->links->leave_from_source( $space, $user, 'fixture', 'group', 7 ) );
		$this->assertTrue( $this->members->is_member( $space, $user ) );
	}

	public function test_unlink_keeps_the_members_and_a_space_with_no_link_stops_being_managed(): void {
		$user  = self::factory()->user->create();
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$this->links->join_from_source( $space, $user );

		$this->links->unlink( $space, 'fixture', 'group', 7 );

		$this->assertSame( array(), $this->links->links( $space ) );
		$this->assertFalse( $this->links->is_managed( $space ) );
		$this->assertTrue( $this->members->is_member( $space, $user ) );
	}

	public function test_release_object_unlinks_every_space_and_leaves_them_standing(): void {
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );

		$this->assertSame( array( $space ), $this->links->release_object( 'fixture', 'group', 7 ) );
		$this->assertSame( array(), $this->links->spaces_for_object( 'fixture', 'group', 7 ) );
		$this->assertNotNull( ( new SpaceService() )->get( $space ) );
	}

	public function test_spaces_for_user_answers_in_one_pass_and_only_for_that_source(): void {
		$user = self::factory()->user->create();
		$a    = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$this->links->create_and_link( 'fixture', 'group', 8, $this->owner );
		$plain = $this->space();
		$this->links->join_from_source( $a, $user );
		$this->members->join( $plain, $user );

		$mine = $this->links->spaces_for_user( $user, 'fixture' );

		$this->assertSame( array( array( 'space_id' => $a, 'type' => 'group', 'id' => 7 ) ), $mine );
		$this->assertSame( array(), $this->links->spaces_for_user( $user, 'other' ) );
	}

	public function test_rest_lists_and_removes_links_for_a_space_manager_only(): void {
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$this->links->link( $space, 'fixture', 'group', 999 );
		$path  = "/buddynext/v1/spaces/{$space}/links";

		wp_set_current_user( self::factory()->user->create() );
		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'GET', $path ) )->get_status() );

		wp_set_current_user( $this->owner );
		$data = rest_do_request( new \WP_REST_Request( 'GET', $path ) )->get_data();
		$this->assertSame( 'Group 7', $data['links'][0]['name'] );
		$this->assertTrue( $data['links'][0]['managed'] );
		$this->assertSame( '', $data['links'][1]['name'], 'a deleted object is listed without a name' );

		$delete = new \WP_REST_Request( 'DELETE', $path );
		$delete->set_param( 'source', 'fixture' );
		$delete->set_param( 'type', 'group' );
		$delete->set_param( 'object', 7 );
		$after = rest_do_request( $delete )->get_data();
		$this->assertCount( 1, $after['links'] );
		$this->assertSame( 999, $after['links'][0]['id'] );
	}

	public function test_a_link_row_names_the_addon_not_its_slug(): void {
		// A label that differs from the capitalised slug, so a slug fallback would fail.
		add_filter(
			'buddynext_integrations',
			static function ( $all ) {
				$all['fixture']['label'] = 'Fixture Book Club';
				return $all;
			},
			99
		);
		IntegrationRegistry::instance()->reset();

		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		$rows  = \BuddyNext\Spaces\SpaceLinksController::describe( $space );

		$this->assertSame( 'Fixture Book Club', $rows[0]['source_label'] );
	}

	public function test_the_space_item_carries_linked_sources(): void {
		$space = $this->links->create_and_link( 'fixture', 'group', 7, $this->owner );
		wp_set_current_user( $this->owner );

		$item = rest_do_request( new \WP_REST_Request( 'GET', "/buddynext/v1/spaces/{$space}" ) )->get_data();

		$this->assertSame( 'fixture', $item['linked_sources'][0]['source'] );
		$this->assertSame( 7, $item['linked_sources'][0]['id'] );

		wp_set_current_user( self::factory()->user->create() );
		$item = rest_do_request( new \WP_REST_Request( 'GET', "/buddynext/v1/spaces/{$space}" ) )->get_data();
		$this->assertSame( array(), $item['linked_sources'], 'a viewer who cannot manage the space does not see what it links to' );
	}
}
