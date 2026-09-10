<?php
/**
 * SpaceMemberService::list_members_across_spaces() lists DISTINCT members across a
 * set of spaces, paginated — the cross-space companion to count_distinct_members()
 * that an addon hub (Wellbee Circles) needs for its platform-wide member screen
 * without touching bn_space_members directly (card 10276234622).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService::list_members_across_spaces
 */
class CrossSpaceMemberListTest extends WP_UnitTestCase {

	/** @var SpaceMemberService */
	private $members;

	/** @var int */
	private $s1 = 0;

	/** @var int */
	private $s2 = 0;

	/**
	 * Seed two spaces and members, one of whom belongs to both with different roles.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->members = new SpaceMemberService();

		global $wpdb;
		$this->s1 = $this->make_space( 'Circle One' );
		$this->s2 = $this->make_space( 'Circle Two' );

		$alice = self::factory()->user->create( array( 'display_name' => 'Alice Zephyr' ) );
		$bob   = self::factory()->user->create( array( 'display_name' => 'Bob' ) );
		$cara  = self::factory()->user->create( array( 'display_name' => 'Cara' ) );

		// Alice in both (member in s1, moderator in s2); Bob in s1; Cara in s2.
		$this->join( $this->s1, $alice, 'member' );
		$this->join( $this->s2, $alice, 'moderator' );
		$this->join( $this->s1, $bob, 'member' );
		$this->join( $this->s2, $cara, 'member' );
	}

	/**
	 * Insert a space row and return its id.
	 *
	 * @param string $name Space name.
	 * @return int
	 */
	private function make_space( string $name ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array( 'name' => $name, 'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 9999 ), 'owner_id' => 1, 'parent_id' => null, 'is_archived' => 0, 'type' => 'public' ),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add an active membership row.
	 *
	 * @param int    $space Space id.
	 * @param int    $user  User id.
	 * @param string $role  Membership role.
	 * @return void
	 */
	private function join( int $space, int $user, string $role ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array( 'space_id' => $space, 'user_id' => $user, 'role' => $role, 'status' => 'active', 'joined_at' => current_time( 'mysql', true ) ),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * A member in several of the spaces is ONE row, carrying all their space ids and
	 * their highest role, and total matches count_distinct_members().
	 *
	 * @return void
	 */
	public function test_lists_distinct_members_with_their_spaces_and_highest_role(): void {
		$result = $this->members->list_members_across_spaces( array( 'space_ids' => array( $this->s1, $this->s2 ) ) );

		$this->assertSame(
			$this->members->count_distinct_members( array( $this->s1, $this->s2 ) ),
			$result['total'],
			'Total must match the distinct-member count.'
		);
		$this->assertCount( $result['total'], $result['items'], 'One page holds every member here.' );

		$by_name = array();
		foreach ( $result['items'] as $item ) {
			$by_name[ $item['display_name'] ] = $item;
		}

		$this->assertArrayHasKey( 'Alice Zephyr', $by_name, 'Alice must appear.' );
		$this->assertSame( 'moderator', $by_name['Alice Zephyr']['role'], 'Alice highest role across her spaces is moderator.' );
		$this->assertEqualsCanonicalizing(
			array( $this->s1, $this->s2 ),
			$by_name['Alice Zephyr']['space_ids'],
			'Alice belongs to both spaces and is listed once.'
		);
		$this->assertSame( 'Bob', $by_name['Bob']['display_name'] );
		$this->assertSame( array( $this->s1 ), $by_name['Bob']['space_ids'] );
	}

	/**
	 * The role filter and total agree, and a paged read is bounded.
	 *
	 * @return void
	 */
	public function test_role_filter_and_pagination(): void {
		$mods = $this->members->list_members_across_spaces( array( 'space_ids' => array( $this->s1, $this->s2 ), 'role' => 'moderator' ) );
		$this->assertSame( 1, $mods['total'], 'Only Alice is a moderator across the set.' );
		$this->assertSame( 'Alice Zephyr', $mods['items'][0]['display_name'] );

		$page1 = $this->members->list_members_across_spaces( array( 'space_ids' => array( $this->s1, $this->s2 ), 'per_page' => 2, 'page' => 1 ) );
		$this->assertCount( 2, $page1['items'], 'Page size is honoured.' );
		$this->assertGreaterThanOrEqual( 3, $page1['total'], 'Total is the full distinct count, not the page size.' );
	}

	/**
	 * An empty space set returns an empty, well-formed result — no query, no error.
	 *
	 * @return void
	 */
	public function test_empty_space_set_is_safe(): void {
		$result = $this->members->list_members_across_spaces( array( 'space_ids' => array() ) );
		$this->assertSame( array( 'items' => array(), 'total' => 0 ), $result );
	}
}
