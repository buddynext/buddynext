<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Renaming a hub keeps its old links working.
 *
 * @package BuddyNext\Tests\Core
 * @since 1.3.0
 */

declare(strict_types=1);

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\HubRegistry;
use BuddyNext\Core\PageRouter;
use WP_UnitTestCase;

/**
 * Before 1.3.0 a renamed hub only flushed rewrite rules, so every bookmark,
 * emailed link and indexed URL under the old slug answered 404. The old slug is
 * now kept on the hub's backing page and its addresses 301 to the new slug.
 *
 * @covers \BuddyNext\Core\PageRouter::remember_hub_slug
 * @covers \BuddyNext\Core\PageRouter::renamed_hub_url
 */
class HubRenameRedirectTest extends WP_UnitTestCase {

	/**
	 * Backing page of the members hub.
	 *
	 * @var int
	 */
	private int $page_id = 0;

	/**
	 * Give the members hub a backing page at its default slug.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$this->page_id = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'members',
				'post_status' => 'publish',
			)
		);
		update_option( 'buddynext_page_people', $this->page_id );
		update_option( 'buddynext_slug_people', 'members' );
		delete_post_meta( $this->page_id, PageRouter::OLD_SLUG_META );
	}

	/**
	 * Saving a new slug records the old one, and its addresses map to the new slug with the rest of the path and query.
	 */
	public function test_old_hub_addresses_map_to_the_new_slug(): void {
		$this->assertSame( '', PageRouter::renamed_hub_url( '/members/jane/' ), 'nothing recorded before a rename' );

		update_option( 'buddynext_slug_people', 'people' );

		$this->assertSame( array( 'members' ), get_post_meta( $this->page_id, PageRouter::OLD_SLUG_META ), 'the option write records the old slug' );
		$this->assertSame( home_url( '/people/' ), PageRouter::renamed_hub_url( '/members/' ) );
		$this->assertSame( home_url( '/people/' ), PageRouter::renamed_hub_url( '/members' ) );
		$this->assertSame( home_url( '/people/jane/about/' ), PageRouter::renamed_hub_url( '/members/jane/about/' ) );
		$this->assertSame( home_url( '/people/?q=ann&page=2' ), PageRouter::renamed_hub_url( '/members/?q=ann&page=2' ) );
		$this->assertSame( home_url( '/people/jane/' ), PageRouter::renamed_hub_url( '/MEMBERS/jane/' ), 'slug match ignores case' );
	}

	/**
	 * Core routes and unrelated paths are never touched.
	 */
	public function test_unrelated_and_core_paths_are_left_alone(): void {
		update_option( 'buddynext_slug_people', 'people' );

		foreach ( array( '/feed/', '/wp-json/wp/v2/posts', '/members-area/', '/about/members/', '/', '' ) as $uri ) {
			$this->assertSame( '', PageRouter::renamed_hub_url( $uri ), $uri );
		}
	}

	/**
	 * A chain of renames keeps every old slug; renaming back clears it so there is no loop.
	 */
	public function test_rename_chain_and_rename_back(): void {
		update_option( 'buddynext_slug_people', 'people' );
		update_option( 'buddynext_slug_people', 'community' );

		$this->assertSame( home_url( '/community/jane/' ), PageRouter::renamed_hub_url( '/members/jane/' ) );
		$this->assertSame( home_url( '/community/jane/' ), PageRouter::renamed_hub_url( '/people/jane/' ) );

		update_option( 'buddynext_slug_people', 'members' );
		$this->assertNotContains( 'members', get_post_meta( $this->page_id, PageRouter::OLD_SLUG_META ), 'the current slug is never an old slug' );
		$this->assertSame( '', PageRouter::renamed_hub_url( '/members/jane/' ) );
		$this->assertSame( home_url( '/members/jane/' ), PageRouter::renamed_hub_url( '/community/jane/' ) );
	}

	/**
	 * A slug another hub takes over leaves this hub's history.
	 */
	public function test_slug_taken_by_another_hub_leaves_the_history(): void {
		update_option( 'buddynext_slug_people', 'people' );
		PageRouter::remember_hub_slug( HubRegistry::instance()->get( 'spaces' ), 'spaces', 'members' );

		$this->assertSame( array(), get_post_meta( $this->page_id, PageRouter::OLD_SLUG_META ) );
		$this->assertSame( '', PageRouter::renamed_hub_url( '/members/jane/' ) );
	}
}
