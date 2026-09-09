<?php
/**
 * A hidden-category sub-space is excluded from the rail AND from its parent's
 * "N sub-spaces" count — the two must never disagree.
 *
 * Regression cover for card 10280255044 round-3: get_subspaces() gained the
 * show_in_dir=0 exclusion but count_visible_subspaces()/count_visible_subspaces_for()
 * did not, so a parent said "N sub-spaces" and the rail listed fewer — and the REST
 * pager offered a page that came back empty. The exclusion now lives in the one
 * shared visibility filter both the list and the counts build from, so they cannot
 * diverge again (the invariant the class docblock promises).
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceCategoryService;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * Sub-space list/count parity across the hidden-category exclusion.
 *
 * @covers \BuddyNext\Spaces\SpaceService::get_subspaces
 * @covers \BuddyNext\Spaces\SpaceService::count_visible_subspaces
 * @covers \BuddyNext\Spaces\SpaceService::count_visible_subspaces_for
 */
class SubspaceHiddenCategoryCountParityTest extends WP_UnitTestCase {

	/** @var SpaceService */
	private $spaces;

	/** @var int Visible parent space. */
	private $parent = 0;

	/** @var int Child in a visible category. */
	private $child_vis = 0;

	/** @var int Child in a hidden (show_in_dir=0) category. */
	private $child_hidden = 0;

	/**
	 * Seed a visible parent with one visible-category child and one hidden-category child.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		$this->spaces = new SpaceService();
		$cats         = new SpaceCategoryService();
		$admin        = 1;

		$vis = $cats->create( array( 'name' => 'Visible cat', 'show_in_dir' => true ) );
		$hid = $cats->create( array( 'name' => 'Hidden cat', 'show_in_dir' => false ) );
		$vis = (int) ( is_array( $vis ) ? ( $vis['id'] ?? 0 ) : $vis );
		$hid = (int) ( is_array( $hid ) ? ( $hid['id'] ?? 0 ) : $hid );

		$this->parent       = (int) $this->spaces->create( $admin, array( 'name' => 'Parent', 'slug' => 'parent', 'type' => 'public' ) );
		$this->child_vis    = (int) $this->spaces->create( $admin, array( 'name' => 'Child Vis', 'slug' => 'child-vis', 'type' => 'public', 'parent_id' => $this->parent, 'category_id' => $vis ) );
		$this->child_hidden = (int) $this->spaces->create( $admin, array( 'name' => 'Child Hidden', 'slug' => 'child-hidden', 'type' => 'public', 'parent_id' => $this->parent, 'category_id' => $hid ) );

		wp_cache_flush();
	}

	/**
	 * @param int  $viewer_id Viewer.
	 * @param bool $is_admin  Admin flag.
	 * @return int[] Sub-space ids the rail would render.
	 */
	private function list_ids( int $viewer_id, bool $is_admin ): array {
		return array_map(
			static fn( array $r ): int => (int) $r['id'],
			$this->spaces->get_subspaces( $this->parent, 24, 0, $viewer_id, $is_admin )
		);
	}

	/**
	 * Logged out: the hidden-category child is excluded from the rail, and both the
	 * single and batched counts equal the rendered list — no phantom pager page.
	 *
	 * @return void
	 */
	public function test_count_matches_list_for_a_guest(): void {
		$ids   = $this->list_ids( 0, false );
		$count = $this->spaces->count_visible_subspaces( $this->parent, 0, false );
		$batch = $this->spaces->count_visible_subspaces_for( array( $this->parent ), 0, false );

		$this->assertContains( $this->child_vis, $ids, 'Visible-category child must appear on the rail.' );
		$this->assertNotContains( $this->child_hidden, $ids, 'Hidden-category child must not appear on the rail.' );
		$this->assertSame( count( $ids ), $count, 'count_visible_subspaces must equal the rendered list.' );
		$this->assertSame( count( $ids ), (int) $batch[ $this->parent ], 'Batched count must equal the rendered list.' );
	}

	/**
	 * The exclusion is category curation, not privacy: an admin gets the same
	 * count/list, with the hidden-category child excluded from both.
	 *
	 * @return void
	 */
	public function test_count_matches_list_for_an_admin(): void {
		$ids   = $this->list_ids( 1, true );
		$count = $this->spaces->count_visible_subspaces( $this->parent, 1, true );

		$this->assertNotContains( $this->child_hidden, $ids, 'Hidden-category child must be excluded for admins too (curation).' );
		$this->assertSame( count( $ids ), $count, 'Admin count must equal the admin list.' );
	}
}
