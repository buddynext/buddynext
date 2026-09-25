<?php
/**
 * An add-on can file a space under its own search type (card 10340165459).
 *
 * `buddynext_search_space_object_type` moves a space out of the Spaces section
 * into the add-on's own, the row never lingers under the old type, removal finds
 * it after the space is gone, and a member still finds their private space.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchIndexListener;
use BuddyNext\Search\SearchService;
use BuddyNext\Spaces\SpaceService;

/**
 * @covers \BuddyNext\Search\SearchIndexListener::async_index_space
 * @covers \BuddyNext\Search\SearchIndexListener::async_deindex_space
 * @covers \BuddyNext\Search\SearchService::space_object_types
 */
class SpaceSearchObjectTypeTest extends \WP_UnitTestCase {

	private int $owner    = 0;
	private int $space_id = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		delete_option( 'buddynext_search_space_types' );
		$this->owner    = self::factory()->user->create();
		$this->space_id = (int) ( new SpaceService() )->create(
			$this->owner,
			array( 'name' => 'Morning Yoga', 'slug' => 'morning-yoga-' . wp_generate_password( 5, false ), 'type' => 'private' )
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'buddynext_search_space_object_type' );
		delete_option( 'buddynext_search_space_types' );
		parent::tear_down();
	}

	private function types_for_space(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( "SELECT object_type FROM {$wpdb->prefix}bn_search_index WHERE object_id = %d AND object_type IN ('space','circle')", $this->space_id ) );
	}

	private function circle( bool $on ): void {
		remove_all_filters( 'buddynext_search_space_object_type' );
		if ( $on ) {
			add_filter( 'buddynext_search_space_object_type', static fn( $type, $row ) => 'Morning Yoga' === $row['name'] ? 'circle' : $type, 10, 2 );
		}
		( new SearchIndexListener() )->async_index_space( $this->space_id );
	}

	public function test_filtered_space_moves_to_its_own_type_and_back(): void {
		$this->circle( false );
		$this->assertSame( array( 'space' ), $this->types_for_space() );

		$this->circle( true );
		$this->assertSame( array( 'circle' ), $this->types_for_space(), 'listed once, under its own type only' );
		$this->assertContains( 'circle', SearchService::space_object_types() );

		$this->circle( false );
		$this->assertSame( array( 'space' ), $this->types_for_space(), 'no stale circle row after it stops being one' );
	}

	public function test_member_still_finds_their_private_space_under_the_custom_type(): void {
		$this->circle( true );

		// The owner is a member of their own private space; a guest is not.
		$as_member = ( new SearchService() )->search( 'Yoga', 'circle', 10, 1, $this->owner );
		$as_guest  = ( new SearchService() )->search( 'Yoga', 'circle', 10, 1, 0 );

		$this->assertContains( $this->space_id, array_map( 'intval', array_column( $as_member['items'], 'object_id' ) ) );
		$this->assertNotContains( $this->space_id, array_map( 'intval', array_column( $as_guest['items'], 'object_id' ) ) );
	}

	public function test_removal_finds_the_row_after_the_space_is_gone(): void {
		$this->circle( true );
		( new SearchIndexListener() )->async_deindex_space( $this->space_id );
		$this->assertSame( array(), $this->types_for_space() );
	}
}
