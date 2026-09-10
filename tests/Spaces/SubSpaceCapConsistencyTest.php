<?php
/**
 * The per-parent sub-space cap counts ARCHIVED children out, consistently across
 * every surface that enforces it (card 10264295263).
 *
 * count_subspaces() (the picker + the move-enforcement path) excludes archived
 * children, but create() had its own inline COUNT(*) with no is_archived = 0. So a
 * root at its cap whose children were all archived was offered by the picker and
 * accepted a move, yet refused a create — three surfaces, two answers. create()
 * now counts through count_subspaces() like everyone else.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\SpaceService::create
 * @covers \BuddyNext\Spaces\SpaceService::count_subspaces
 */
class SubSpaceCapConsistencyTest extends WP_UnitTestCase {

	/** @var SpaceService */
	private $spaces;

	/** @var int */
	private $owner = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->spaces = new SpaceService();
		$this->owner  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		update_option( 'buddynext_space_max_sub_spaces', 2 );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_space_max_sub_spaces' );
		parent::tear_down();
	}

	/**
	 * Create a sub-space under a parent and return the id (fails the test on error).
	 *
	 * @param int    $parent Parent space id.
	 * @param string $name   Name.
	 * @return int
	 */
	private function make_child( int $parent, string $name ): int {
		$id = $this->spaces->create(
			$this->owner,
			array( 'name' => $name, 'slug' => sanitize_title( $name ) . '-' . wp_rand( 1000, 9999 ), 'type' => 'open', 'parent_id' => $parent )
		);
		$this->assertIsInt( $id, 'Child creation should succeed while under the cap.' );
		return (int) $id;
	}

	/**
	 * Two LIVE children fill the cap; a third create is refused. Archiving both
	 * frees the cap, and a create is then accepted — the same answer the picker and
	 * the move path give.
	 *
	 * @return void
	 */
	public function test_archived_children_do_not_count_against_the_create_cap(): void {
		$root = $this->spaces->create(
			$this->owner,
			array( 'name' => 'Cap Root', 'slug' => 'cap-root-' . wp_rand( 1000, 9999 ), 'type' => 'open' )
		);
		$this->assertIsInt( $root );

		$c1 = $this->make_child( (int) $root, 'Child One' );
		$c2 = $this->make_child( (int) $root, 'Child Two' );
		$this->assertSame( 2, $this->spaces->count_subspaces( (int) $root ), 'Two live children at cap.' );

		// At cap with two LIVE children: refused.
		$blocked = $this->spaces->create(
			$this->owner,
			array( 'name' => 'Child Three', 'slug' => 'child-three-' . wp_rand( 1000, 9999 ), 'type' => 'open', 'parent_id' => (int) $root )
		);
		$this->assertInstanceOf( \WP_Error::class, $blocked, 'A create at the live cap must be refused.' );
		$this->assertSame( 'max_sub_spaces_exceeded', $blocked->get_error_code() );

		// Archive both: the cap count drops to zero.
		$this->spaces->archive( $c1, $this->owner );
		$this->spaces->archive( $c2, $this->owner );
		$this->assertSame( 0, $this->spaces->count_subspaces( (int) $root ), 'Archived children must not count.' );

		// A create is now accepted — matching the picker/move surfaces.
		$ok = $this->spaces->create(
			$this->owner,
			array( 'name' => 'Child Four', 'slug' => 'child-four-' . wp_rand( 1000, 9999 ), 'type' => 'open', 'parent_id' => (int) $root )
		);
		$this->assertIsInt( $ok, 'With the live children archived, a create must be accepted.' );
	}
}
