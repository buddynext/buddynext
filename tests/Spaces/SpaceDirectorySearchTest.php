<?php
/**
 * Directory search composes with category, sort and sub-spaces, and pages.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_REST_Request;
use WP_REST_Server;

/**
 * SSR request parser and REST route return one list.
 */
class SpaceDirectorySearchTest extends \WP_UnitTestCase {

	private SpaceService $spaces;
	private int $owner;
	private int $cat_a;
	private int $cat_b;
	private int $sub_id;
	private int $newest;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );
		$this->spaces = new SpaceService();
		$this->owner  = self::factory()->user->create();
		$categories   = new \BuddyNext\Spaces\SpaceCategoryService();
		$this->cat_a  = (int) $categories->create( array( 'name' => 'Alpha Cat', 'slug' => 'alpha-cat' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		$this->cat_b  = (int) $categories->create( array( 'name' => 'Beta Cat', 'slug' => 'beta-cat' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing
		global $wpdb;
		for ( $i = 1; $i <= 25; $i++ ) {
			$id = (int) $this->spaces->create(
				$this->owner,
				array(
					'name'        => "Builders {$i}",
					'slug'        => "builders-{$i}",
					'type'        => 'open',
					'category_id' => $i <= 10 ? $this->cat_a : $this->cat_b,
				)
			);
			// Popular order and creation order must disagree for the sort check to bite.
			$wpdb->update(
				$wpdb->prefix . 'bn_spaces',
				array(
					'member_count' => 100 - $i,
					'created_at'   => gmdate( 'Y-m-d H:i:s', time() - 100000 + ( $i * 100 ) ),
				),
				array( 'id' => $id )
			);
			$this->newest = $id;
		}
		$this->sub_id = (int) $this->spaces->create(
			$this->owner,
			array(
				'name'      => 'Builders Sub',
				'slug'      => 'builders-sub',
				'type'      => 'open',
				'parent_id' => $id,
			)
		);
		$wpdb->update( $wpdb->prefix . 'bn_spaces', array( 'parent_id' => $id ), array( 'id' => $this->sub_id ) );
		SpaceService::flush_space_lists();
		wp_cache_flush();
	}

	private function rest( array $params ): array {
		$request = new WP_REST_Request( 'GET', '/buddynext/v1/spaces' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return (array) rest_do_request( $request )->get_data();
	}

	private function ssr( array $input ): array {
		$parsed = $this->spaces->directory_request( 0, false, $input, (int) ( $input['page'] ?? 1 ) );
		$this->assertSame( 'search', $parsed['mode'] );
		return $this->spaces->search_with_total( $parsed['search'], $parsed['query_args'] );
	}

	private static function ids( array $rows ): array {
		return array_map( static fn( $r ) => (int) $r['id'], $rows );
	}

	public function test_search_composes_with_category_and_sort_and_ssr_matches_rest(): void {
		$rest = $this->rest(
			array(
				'search'      => 'Builders',
				'category_id' => $this->cat_a,
				'orderby'     => 'newest',
				'roots_only'  => '1',
				'paginate'    => '1',
				'per_page'    => 18,
			)
		);
		$ssr  = $this->ssr(
			array(
				'bn_search' => 'Builders',
				'bn_cat'    => 'alpha-cat',
				'bn_sort'   => 'newest',
			)
		);

		$this->assertSame( 10, $rest['total'] );
		$this->assertSame( 10, $ssr['total'] );
		$this->assertSame( 'Builders 10', $rest['items'][0]['name'], 'Newest first, not most members first.' );
		$this->assertSame( self::ids( $rest['items'] ), self::ids( $ssr['items'] ) );
	}

	public function test_more_than_one_page_has_a_real_total_and_reaches_every_match(): void {
		$first  = $this->rest(
			array(
				'search'     => 'Builders',
				'roots_only' => '1',
				'paginate'   => '1',
				'per_page'   => 18,
			)
		);
		$second = $this->ssr(
			array(
				'bn_search' => 'Builders',
				'page'      => 2,
			)
		);

		$this->assertSame( 25, $first['total'] );
		$this->assertSame( 2, $first['total_pages'] );
		$this->assertCount( 18, $first['items'] );
		$this->assertCount( 7, $second['items'] );
		$this->assertSame( array(), array_intersect( self::ids( $first['items'] ), self::ids( $second['items'] ) ) );
	}

	public function test_search_follows_the_sub_space_control_and_a_bare_search_still_finds_them(): void {
		$off = $this->ssr( array( 'bn_search' => 'Builders Sub' ) );
		$this->assertSame( 0, $off['total'] );

		$on = $this->ssr(
			array(
				'bn_search'    => 'Builders Sub',
				'bn_subspaces' => '1',
			)
		);
		$this->assertSame( array( $this->sub_id ), self::ids( $on['items'] ) );

		// A bare REST search and other search() callers keep surfacing sub-spaces, as a flat array.
		$bare = $this->rest( array( 'search' => 'Builders Sub' ) );
		$this->assertSame( array( $this->sub_id ), self::ids( $bare ) );
		$this->assertSame( array( $this->sub_id ), self::ids( $this->spaces->search( 'Builders Sub' ) ) );
	}

	public function test_search_keeps_description_matches_and_hidden_rules(): void {
		$secret = (int) $this->spaces->create(
			$this->owner,
			array(
				'name'        => 'Quiet Room',
				'slug'        => 'quiet-room',
				'type'        => 'secret',
				'description' => 'needlexyz here',
			)
		);
		$open   = (int) $this->spaces->create(
			$this->owner,
			array(
				'name'        => 'Loud Room',
				'slug'        => 'loud-room',
				'type'        => 'open',
				'description' => 'needlexyz here',
			)
		);
		SpaceService::flush_space_lists();

		$stranger = self::factory()->user->create();
		$this->assertSame( array( $open ), self::ids( $this->spaces->search( 'needlexyz', array( 'viewer' => $stranger ) ) ) );

		$owner = self::ids( $this->spaces->search( 'needlexyz', array( 'viewer' => $this->owner ) ) );
		$this->assertContains( $secret, $owner );
	}
}
