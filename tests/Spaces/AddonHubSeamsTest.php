<?php
/**
 * Seams that let an addon hub (Wellbee Circles) coexist with the native Spaces
 * hub without its spaces leaking into the Spaces UI (card 10276700689).
 *
 *  - buddynext_membership_rows filters the "My spaces" summary (rail flyout,
 *    profile "Member of", sidebar); rows carry category_id so the addon drops its
 *    category-flagged spaces with no extra query.
 *  - buddynext_membership_count keeps the badge consistent with that filtered list.
 *  - CoreHubs persists every registered hub's slug into buddynext_hub_slugs so the
 *    isolation mu-plugin can cover an addon hub route.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\CoreHubs;
use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService::membership_rows
 * @covers \BuddyNext\Spaces\SpaceMemberService::count_memberships
 * @covers \BuddyNext\Core\CoreHubs::register
 */
class AddonHubSeamsTest extends WP_UnitTestCase {

	/** @var SpaceMemberService */
	private $members;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->members = new SpaceMemberService();
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'buddynext_membership_rows' );
		remove_all_filters( 'buddynext_membership_count' );
		parent::tear_down();
	}

	/**
	 * Insert a space (optionally in a category) and add the user as an active member.
	 *
	 * @param int      $user     User id.
	 * @param int|null $category Category id, or null.
	 * @return int Space id.
	 */
	private function join_space( int $user, ?int $category ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array( 'name' => 'S' . wp_rand(), 'slug' => 's-' . wp_rand( 1000, 9999 ), 'owner_id' => 1, 'parent_id' => null, 'category_id' => $category, 'is_archived' => 0, 'type' => 'public' ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' )
		);
		$space = (int) $wpdb->insert_id;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array( 'space_id' => $space, 'user_id' => $user, 'role' => 'member', 'status' => 'active', 'joined_at' => current_time( 'mysql', true ) ),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		return $space;
	}

	/**
	 * membership_rows() carries category_id and honours the filter (on both the
	 * fresh and the cached path); the count filter keeps the badge consistent.
	 *
	 * @return void
	 */
	public function test_membership_rows_and_count_filters(): void {
		$user   = self::factory()->user->create();
		$circle = $this->join_space( $user, 777 );
		$this->join_space( $user, null );

		$rows = $this->members->membership_rows( $user, 10 );
		$this->assertCount( 2, $rows, 'Both memberships are listed by default.' );
		$cats = array_map( static fn( $r ): int => (int) ( $r->category_id ?? 0 ), $rows );
		$this->assertContains( 777, $cats, 'Rows must carry category_id so an addon can filter without a lookup.' );

		// The addon drops its category-flagged spaces.
		add_filter(
			'buddynext_membership_rows',
			static fn( array $rows ): array => array_values( array_filter( $rows, static fn( $r ): bool => 777 !== (int) ( $r->category_id ?? 0 ) ) )
		);
		$filtered = $this->members->membership_rows( $user, 10 );
		$this->assertCount( 1, $filtered, 'The circle row is dropped.' );
		$this->assertNotContains( $circle, array_map( static fn( $r ): int => (int) $r->id, $filtered ) );

		// The badge nets the same space out.
		$this->assertSame( 2, $this->members->count_memberships( $user ), 'Unfiltered count is two.' );
		add_filter( 'buddynext_membership_count', static fn( int $c ): int => $c - 1 );
		$this->assertSame( 1, $this->members->count_memberships( $user ), 'The count filter keeps the badge consistent with the list.' );
	}

	/**
	 * The addon filter runs BEFORE the row cap, so a member always sees up to
	 * `limit` VISIBLE spaces — not `limit` minus however many of the newest
	 * memberships were hidden circles. The old code LIMITed first, so filtering
	 * an already-truncated set left the flyout short of its own badge
	 * (card 10276700689).
	 *
	 * @return void
	 */
	public function test_membership_rows_filter_applies_before_the_cap(): void {
		$user  = self::factory()->user->create();
		$plain = array();
		for ( $i = 0; $i < 4; $i++ ) {
			$plain[] = $this->join_space( $user, null );
		}
		// The two NEWEST memberships are hidden circles.
		$this->join_space( $user, 777 );
		$this->join_space( $user, 777 );

		add_filter(
			'buddynext_membership_rows',
			static fn( array $rows ): array => array_values(
				array_filter( $rows, static fn( $r ): bool => 777 !== (int) ( $r->category_id ?? 0 ) )
			)
		);

		// Cap 5, 4 visible: all four show even though the 2 newest rows are hidden.
		$rows = $this->members->membership_rows( $user, 5 );
		$this->assertCount( 4, $rows, 'All visible spaces show; the filter ran before the cap.' );

		// Cap 1 returns the newest VISIBLE space, never an empty list because the
		// newest membership happened to be a hidden circle.
		$one = $this->members->membership_rows( $user, 1 );
		$this->assertCount( 1, $one );
		$this->assertContains( (int) $one[0]->id, $plain, 'The single row is a visible (plain) space.' );
	}

	/**
	 * A reserved-hub category (show_in_dir = 0) is fenced out of the member-scoped
	 * "my spaces" list AND its total, not just the public directory — so the rail's
	 * "See all" page agrees with the rail flyout that already hides those spaces
	 * (card 10276700689). An explicit category request still returns them.
	 *
	 * @return void
	 */
	public function test_my_spaces_list_and_total_hide_a_reserved_hub_category(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_categories',
			array( 'name' => 'Circles', 'slug' => 'circles-' . wp_rand( 1000, 9999 ), 'show_in_dir' => 0 ),
			array( '%s', '%s', '%d' )
		);
		$hidden_cat = (int) $wpdb->insert_id;
		wp_cache_flush(); // drop any warm 'hidden_ids' cache from an earlier query.

		$user     = self::factory()->user->create();
		$plain    = $this->join_space( $user, null );
		$circle   = $this->join_space( $user, $hidden_cat );
		$spaces   = new \BuddyNext\Spaces\SpaceService();

		$result = $spaces->list_spaces_with_total( array( 'member' => $user, 'per_page' => 20 ) );
		$ids    = array_map( static fn( $r ): int => (int) ( is_array( $r ) ? $r['id'] : $r->id ), $result['items'] );

		$this->assertContains( $plain, $ids, 'A plain space stays in my-spaces.' );
		$this->assertNotContains( $circle, $ids, 'A reserved-hub space is fenced out of my-spaces.' );
		$this->assertSame( 1, (int) $result['total'], 'The total nets out the hidden space too.' );

		// The hub's own page (explicit category) still reaches its spaces.
		$hub = $spaces->list_spaces_with_total( array( 'category_id' => $hidden_cat, 'per_page' => 20 ) );
		$hub_ids = array_map( static fn( $r ): int => (int) ( is_array( $r ) ? $r['id'] : $r->id ), $hub['items'] );
		$this->assertContains( $circle, $hub_ids, 'An explicit category request still returns the hub spaces.' );
	}

	/**
	 * Registering hubs persists every hub's live slug into buddynext_hub_slugs —
	 * including a hub an addon registers on buddynext_register_hubs — so the
	 * isolation mu-plugin can cover its route.
	 *
	 * @return void
	 */
	public function test_registered_hub_slugs_are_persisted_for_the_mu_plugin(): void {
		$addon = static function ( HubRegistry $reg ): void {
			$reg->register(
				new \BuddyNext\Core\HubDescriptor(
					'circles',
					'buddynext_slug_circles',
					'circles',
					'buddynext_page_circles',
					'Circles',
					'buddynext_circles'
				)
			);
		};
		add_action( 'buddynext_register_hubs', $addon );

		CoreHubs::register( new HubRegistry() );
		remove_action( 'buddynext_register_hubs', $addon );

		$slugs = json_decode( (string) get_option( 'buddynext_hub_slugs', '[]' ), true );
		$this->assertIsArray( $slugs );
		$this->assertContains( 'spaces', $slugs, 'A core hub slug is persisted.' );
		$this->assertContains( 'circles', $slugs, 'An addon hub slug is persisted so isolation can cover its route.' );
	}
}
