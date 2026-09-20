<?php
/**
 * Featured-spaces resolver + option + REST tests (card "Done when" list).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\FeaturedSpaces;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Spaces\SpaceMemberService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Spaces\FeaturedSpaces
 * @covers \BuddyNext\Spaces\SpaceService::featured_spaces
 */
class FeaturedSpacesTest extends \WP_Test_REST_TestCase {

	private int $owner_id;
	private SpaceService $spaces;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->spaces   = new SpaceService();
		$this->owner_id = self::factory()->user->create();
	}

	public function tear_down(): void {
		delete_option( FeaturedSpaces::OPTION );
		remove_all_filters( 'buddynext_featured_spaces_limit' );
		remove_all_filters( 'buddynext_featured_spaces' );
		parent::tear_down();
	}

	private function make_space( string $slug, string $type = 'open' ): int {
		return (int) $this->spaces->create(
			$this->owner_id,
			array( 'name' => ucfirst( $slug ), 'slug' => $slug, 'type' => $type )
		);
	}

	private function ids_of( array $rows ): array {
		return array_map( static fn( $r ) => (int) $r['id'], $rows );
	}

	/** @covers Plan item: the owner list is returned in order. */
	public function test_owner_list_returned_in_order(): void {
		$a = $this->make_space( 'feat-a' );
		$b = $this->make_space( 'feat-b' );
		$c = $this->make_space( 'feat-c' );

		FeaturedSpaces::set_ids( array( $c, $a, $b ) );

		$rows = $this->spaces->featured_spaces( $this->owner_id );
		$this->assertSame( array( $c, $a, $b ), $this->ids_of( $rows ) );
	}

	/** @covers Plan item: empty option falls back to auto-join signup spaces (member_count DESC). */
	public function test_empty_option_falls_back_to_auto_join(): void {
		$low  = $this->make_space( 'aj-low' );
		$high = $this->make_space( 'aj-high' );
		update_space_meta( $low, 'auto_join_on_signup', '1' );
		update_space_meta( $high, 'auto_join_on_signup', '1' );
		// Give $high the larger member_count so it sorts first.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_spaces SET member_count = %d WHERE id = %d", 50, $high ) );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_spaces SET member_count = %d WHERE id = %d", 5, $low ) );

		$rows = $this->spaces->featured_spaces( $this->owner_id );
		$ids  = $this->ids_of( $rows );
		$this->assertContains( $high, $ids );
		$this->assertContains( $low, $ids );
		$this->assertSame( $high, $ids[0], 'auto-join fallback should order by member_count DESC' );
	}

	/** @covers Plan item: both empty returns an empty array. */
	public function test_both_empty_returns_empty(): void {
		$this->make_space( 'plain' ); // exists but not featured, not auto-join
		$this->assertSame( array(), $this->spaces->featured_spaces( $this->owner_id ) );
	}

	/** @covers Plan item: archived and deleted spaces are removed. */
	public function test_archived_and_deleted_removed(): void {
		$live     = $this->make_space( 'live' );
		$archived = $this->make_space( 'archived' );
		$deleted  = $this->make_space( 'deleted' );
		FeaturedSpaces::set_ids( array( $live, $archived, $deleted ) );

		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_spaces SET is_archived = 1 WHERE id = %d", $archived ) );
		$this->spaces->delete( $deleted, $this->owner_id );

		$ids = $this->ids_of( $this->spaces->featured_spaces( $this->owner_id ) );
		$this->assertSame( array( $live ), $ids );
	}

	/** @covers Plan item: a private/secret space is hidden from a viewer who cannot see it. */
	public function test_secret_hidden_from_outsider_visible_to_admin(): void {
		$secret = $this->make_space( 'secret-feat', 'secret' );
		FeaturedSpaces::set_ids( array( $secret ) );

		$outsider = self::factory()->user->create();
		$admin    = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertSame( array(), $this->ids_of( $this->spaces->featured_spaces( $outsider ) ) );
		$this->assertSame( array( $secret ), $this->ids_of( $this->spaces->featured_spaces( $admin ) ) );
	}

	/** @covers Plan item: the cap follows buddynext_featured_spaces_limit (default 6, clamped 1-12). */
	public function test_limit_filter_and_clamp(): void {
		$this->assertSame( 6, FeaturedSpaces::limit() );

		add_filter( 'buddynext_featured_spaces_limit', static fn() => 20 );
		$this->assertSame( 12, FeaturedSpaces::limit(), 'clamped to 12' );
		remove_all_filters( 'buddynext_featured_spaces_limit' );

		add_filter( 'buddynext_featured_spaces_limit', static fn() => 0 );
		$this->assertSame( 1, FeaturedSpaces::limit(), 'clamped to 1' );
		remove_all_filters( 'buddynext_featured_spaces_limit' );

		// set_ids caps to the limit.
		add_filter( 'buddynext_featured_spaces_limit', static fn() => 2 );
		$ids = array();
		foreach ( range( 1, 4 ) as $n ) {
			$ids[] = $this->make_space( 'cap-' . $n );
		}
		$stored = FeaturedSpaces::set_ids( $ids );
		$this->assertCount( 2, $stored );
	}

	/** @covers Plan item: the buddynext_featured_spaces filter cannot add a space the viewer cannot see. */
	public function test_filter_cannot_inject_hidden_space(): void {
		$open   = $this->make_space( 'open-feat' );
		$secret = $this->make_space( 'secret-inject', 'secret' );
		FeaturedSpaces::set_ids( array( $open ) );
		$outsider = self::factory()->user->create();

		add_filter(
			'buddynext_featured_spaces',
			function ( $rows ) use ( $secret ) {
				$rows[] = array( 'id' => $secret, 'type' => 'secret' );
				return $rows;
			}
		);

		$ids = $this->ids_of( $this->spaces->featured_spaces( $outsider ) );
		$this->assertNotContains( $secret, $ids, 'a filter must not surface a hidden space' );
	}

	/** @covers Plan item: set_ids validates (drops missing/archived, dedupes, preserves order). */
	public function test_set_ids_validates_and_dedupes(): void {
		$a = $this->make_space( 'v-a' );
		$b = $this->make_space( 'v-b' );
		$stored = FeaturedSpaces::set_ids( array( $b, $b, 999999, $a ) );
		$this->assertSame( array( $b, $a ), $stored );
	}

	/** @covers boost_suggestions: featured (not joined) injected behind top-2, joined excluded. */
	public function test_boost_suggestions(): void {
		$s = array();
		foreach ( range( 1, 5 ) as $n ) {
			$s[ $n ] = $this->make_space( 'bs-' . $n );
		}
		// Rank = personal matches s1,s2,s3; feature s4 (not joined) + s5 (joined).
		$member = self::factory()->user->create();
		( new SpaceMemberService() )->join( $s[5], $member );
		FeaturedSpaces::set_ids( array( $s[4], $s[5] ) );

		$out = FeaturedSpaces::boost_suggestions( array( $s[1], $s[2], $s[3] ), $member );

		// s4 injected after the top two; s5 (joined) not injected.
		$this->assertSame( array( $s[1], $s[2], $s[4], $s[3] ), $out );
		$this->assertNotContains( $s[5], $out );

		// No featured / logged-out → unchanged.
		$this->assertSame( array( $s[1], $s[2] ), FeaturedSpaces::boost_suggestions( array( $s[1], $s[2] ), 0 ) );
	}

	/** @covers Plan item: a non-admin gets 403 on the REST route. */
	public function test_rest_requires_admin(): void {
		$member = self::factory()->user->create();
		wp_set_current_user( $member );

		$get = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/settings/featured-spaces' ) );
		$this->assertSame( 403, $get->get_status() );

		$post = new WP_REST_Request( 'POST', '/buddynext/v1/settings/featured-spaces' );
		$post->set_body_params( array( 'ids' => array( 1 ) ) );
		$this->assertSame( 403, rest_do_request( $post )->get_status() );
	}

	/** @covers REST round-trip: admin POST persists + GET returns ordered ids. */
	public function test_rest_admin_round_trip(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$a = $this->make_space( 'r-a' );
		$b = $this->make_space( 'r-b' );

		$post = new WP_REST_Request( 'POST', '/buddynext/v1/settings/featured-spaces' );
		$post->set_body_params( array( 'ids' => array( $b, $a ) ) );
		$res = rest_do_request( $post );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( $b, $a ), $res->get_data()['ids'] );

		$get = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/settings/featured-spaces' ) );
		$this->assertSame( array( $b, $a ), $get->get_data()['ids'] );
	}
}
