<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Member-facing category counts never count spaces a visitor cannot see.
 *
 * @package BuddyNext\Tests\Spaces
 * @since 1.3.0
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PageRouter;
use BuddyNext\Spaces\SpaceCategoryService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * A category holding only secret spaces read "2 spaces" to guests, then listed
 * nothing: the count told everyone the secret spaces exist. The Explore Browse
 * link also used ?category=, which the directory never reads.
 *
 * @covers \BuddyNext\Spaces\SpaceCategoryService::get_all_with_counts
 * @covers \BuddyNext\Spaces\SpaceCategoryService::get_for_members
 */
class CategoryVisibleCountTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, no categories or spaces.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_space_categories" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_spaces" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		wp_cache_flush();
	}

	/**
	 * Create a category.
	 *
	 * @param string $name Name.
	 * @return int
	 */
	private function category( string $name ): int {
		return (int) ( new SpaceCategoryService() )->create(
			array(
				'name'        => $name,
				'show_in_dir' => true,
			)
		);
	}

	/**
	 * Insert a space row straight into the table, then retire the listings, the
	 * way every SpaceService write does.
	 *
	 * @param int    $category_id Category.
	 * @param string $type        open|private|secret.
	 * @param int    $archived    1 = archived.
	 * @param int    $parent_id   0 = top level.
	 * @return void
	 */
	private function space( int $category_id, string $type, int $archived = 0, int $parent_id = 0 ): void {
		global $wpdb;
		static $n = 0;
		++$n;
		$row = array(
			'name'        => 'Space ' . $n,
			'slug'        => 'space-vc-' . $n . '-' . wp_rand(),
			'type'        => $type,
			'owner_id'    => 1,
			'category_id' => $category_id,
			'is_archived' => $archived,
			'created_at'  => current_time( 'mysql', true ),
		);
		if ( $parent_id > 0 ) {
			$row['parent_id'] = $parent_id;
		}
		$wpdb->insert( $wpdb->prefix . 'bn_spaces', $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		SpaceService::flush_space_lists();
	}

	/**
	 * Visible rows keyed by category id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function visible(): array {
		$out = array();
		foreach ( ( new SpaceCategoryService() )->get_all_with_counts( true ) as $row ) {
			$out[ (int) $row['id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Secret, archived and child spaces are not counted for members; the admin
	 * still sees the raw total.
	 *
	 * @return void
	 */
	public function test_member_count_skips_spaces_a_visitor_cannot_see(): void {
		$mixed  = $this->category( 'Mixed' );
		$secret = $this->category( 'Secret only' );

		$this->space( $mixed, 'open' );
		$this->space( $mixed, 'private' );
		$this->space( $mixed, 'secret' );
		$this->space( $mixed, 'open', 1 );
		$this->space( $secret, 'secret' );
		$this->space( $secret, 'secret' );

		$rows = $this->visible();
		$this->assertSame( 2, $rows[ $mixed ]['space_count'], 'Only the open and private top-level spaces are visible to a guest.' );
		$this->assertSame( 0, $rows[ $secret ]['space_count'], 'A secret-only category must not reveal how many secret spaces it holds.' );
		$this->assertTrue( $rows[ $secret ]['has_spaces'] );

		$admin = array();
		foreach ( ( new SpaceCategoryService() )->get_all_with_counts( false ) as $row ) {
			$admin[ (int) $row['id'] ] = (int) $row['space_count'];
		}
		$this->assertSame( 4, $admin[ $mixed ], 'The admin keeps the raw total.' );
	}

	/**
	 * A new space retires the cached count at once (the listing salt), not after a TTL.
	 *
	 * @return void
	 */
	public function test_a_space_write_refreshes_the_cached_count(): void {
		$cat = $this->category( 'Fresh' );
		$this->assertSame( 0, $this->visible()[ $cat ]['space_count'] );

		$this->space( $cat, 'open' );
		$this->assertSame( 1, $this->visible()[ $cat ]['space_count'] );
	}

	/**
	 * Interests leave out a category whose spaces are all hidden, keep an empty one.
	 *
	 * @return void
	 */
	public function test_interests_drop_secret_only_categories(): void {
		$secret = $this->category( 'Hidden club' );
		$empty  = $this->category( 'Brand new' );
		$open   = $this->category( 'Hiking' );
		$this->space( $secret, 'secret' );
		$this->space( $open, 'open' );

		$offered = array_map( static fn( array $c ): int => (int) $c['id'], ( new SpaceCategoryService() )->get_for_members() );

		$this->assertNotContains( $secret, $offered );
		$this->assertContains( $empty, $offered );
		$this->assertContains( $open, $offered );
	}

	/**
	 * Explore Browse links to the contract the directory reads (bn_cat).
	 *
	 * @return void
	 */
	public function test_explore_browse_links_with_bn_cat(): void {
		ob_start();
		buddynext_get_template(
			'parts/sidebar-explore-browse.php',
			array(
				'bn_categories' => array(
					array(
						'slug'        => 'hiking',
						'name'        => 'Hiking',
						'space_count' => 3,
					),
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( esc_url( add_query_arg( 'bn_cat', 'hiking', PageRouter::spaces_url() ) ), $html );
		$this->assertStringNotContainsString( 'category=hiking', $html );
	}
}
