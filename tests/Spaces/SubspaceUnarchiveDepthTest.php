<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Un-archiving a sub-space must not resurrect a three-level tree.
 *
 * The archived-children edge from card 10264295263: count_subspaces() and
 * validate_parent_move()'s has_children check both count is_archived = 0, so a
 * root whose children are all archived reads as childless and can be moved under
 * another root — leaving its archived children two levels down. set_archived()
 * then only flipped a flag, so un-archiving such a child rebuilt the exact
 * three-level tree the depth cap prevents. SpaceService::set_archived() now
 * refuses an un-archive whose parent is itself a sub-space.
 *
 * @package BuddyNext\Tests\Spaces
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceService;
use WP_Error;
use WP_UnitTestCase;

/**
 * @covers \BuddyNext\Spaces\SpaceService::set_archived
 */
class SubspaceUnarchiveDepthTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var SpaceService
	 */
	private SpaceService $spaces;

	/**
	 * Site admin actor (passes buddynext-own-space unconditionally).
	 *
	 * @var int
	 */
	private int $actor;

	/**
	 * Fresh, empty space graph.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		global $wpdb;
		// bn_* tables carry no ENGINE clause, so they survive the test rollback and
		// leak rows between tests. Start clean.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}bn_spaces" );

		$this->spaces = new SpaceService();
		$this->actor  = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Insert a space row directly and return its id.
	 *
	 * @param int|null $parent_id Parent space id, or null for a root.
	 * @param bool     $archived  Whether the row starts archived.
	 * @param string   $name      Space name.
	 * @return int
	 */
	private function make_space( ?int $parent_id, bool $archived, string $name ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_spaces',
			array(
				'name'        => $name,
				'slug'        => sanitize_title( $name ) . '-' . wp_generate_password( 6, false ),
				'owner_id'    => $this->actor,
				'parent_id'   => $parent_id,
				'is_archived' => $archived ? 1 : 0,
				'type'        => 'public',
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * A child whose parent is itself a sub-space cannot be un-archived: doing so
	 * would nest spaces three levels deep.
	 *
	 * @return void
	 */
	public function test_unarchive_that_would_nest_three_levels_is_refused(): void {
		$grandparent = $this->make_space( null, false, 'Grandparent' );
		$parent      = $this->make_space( $grandparent, false, 'Parent (a sub-space)' );
		$child       = $this->make_space( $parent, true, 'Archived child (level three)' );

		$result = $this->spaces->unarchive( $child, $this->actor );

		$this->assertInstanceOf( WP_Error::class, $result, 'Un-archiving a level-three child must be refused.' );
		$this->assertSame(
			'max_depth_exceeded',
			$result->get_error_code(),
			'The refusal must be the depth guard, not a permission or not-found error.'
		);

		// The row must stay archived — the guard runs before any write.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$still_archived = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT is_archived FROM {$wpdb->prefix}bn_spaces WHERE id = %d", $child )
		);
		$this->assertSame( 1, $still_archived, 'A refused un-archive must not flip the flag.' );
	}

	/**
	 * A normal second-level child (its parent is a root) un-archives fine — the
	 * guard must not over-block legitimate restores.
	 *
	 * @return void
	 */
	public function test_unarchive_of_a_normal_second_level_child_is_allowed(): void {
		$root  = $this->make_space( null, false, 'Root' );
		$child = $this->make_space( $root, true, 'Archived second-level child' );

		$result = $this->spaces->unarchive( $child, $this->actor );

		$this->assertTrue( $result, 'A level-two child must un-archive without hitting the depth guard.' );
	}
}
