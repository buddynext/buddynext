<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Tests the per-integration "Include in search" toggle.
 *
 * @package BuddyNext\Tests\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Integrations;

use BuddyNext\Core\Installer;
use BuddyNext\Search\SearchService;
use WP_UnitTestCase;

/**
 * Disabling an integration must stop its content entering search — and remove what is
 * already there.
 *
 * Both halves matter and the card only named the first. Gating the write path alone stops
 * NEW content, but everything indexed while the integration was enabled keeps surfacing
 * forever, and the owner has no way to reach it. That is the half members actually see.
 *
 * @covers \BuddyNext\Search\SearchService::deindex_type
 */
class IntegrationSearchToggleTest extends WP_UnitTestCase {

	/**
	 * Fresh schema + a clean index.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_search_index" );
		wp_cache_delete( 'search_object_types', 'buddynext' );

		delete_option( 'buddynext_integration_acme_search' );
		delete_option( 'buddynext_integration_acme_nav' );
	}

	/**
	 * An unknown aspect must NOT silently resolve to the nav option.
	 *
	 * This is the trap that would have made the whole fix worthless. The resolver used to be
	 * `if ( 'feed' === $aspect ) … else { read the NAV option }` — a catch-all. So passing a
	 * brand-new 'search' aspect returned the NAV toggle, and the fix would have looked
	 * correct in manual testing while being wired to the wrong control: switch nav off and
	 * search rows stop; switch search off and nothing happens.
	 *
	 * @return void
	 */
	public function test_an_unknown_aspect_does_not_fall_through_to_the_nav_toggle(): void {
		update_option( 'buddynext_integration_acme_nav', '0' );

		$this->setExpectedIncorrectUsage( 'buddynext_integration_enabled' );

		$this->assertTrue(
			buddynext_integration_enabled( 'acme', 'not_a_real_aspect' ),
			'An unrecognised aspect fell through to the nav option. Any new aspect would silently inherit the nav toggle.'
		);
	}

	/**
	 * The search aspect reads its OWN option, not nav and not feed.
	 *
	 * @return void
	 */
	public function test_the_search_aspect_reads_its_own_option(): void {
		update_option( 'buddynext_integration_acme_nav', '1' );
		update_option( 'buddynext_integration_acme_search', '0' );

		$this->assertFalse( buddynext_integration_enabled( 'acme', 'search' ), 'search must read buddynext_integration_acme_search.' );
		$this->assertTrue( buddynext_integration_enabled( 'acme', 'nav' ), 'nav must be unaffected by the search toggle.' );
	}

	/**
	 * Absent option means ON, like every other integration aspect.
	 *
	 * @return void
	 */
	public function test_search_defaults_to_on(): void {
		$this->assertTrue( buddynext_integration_enabled( 'acme', 'search' ) );
	}

	/**
	 * The deindex_type() call removes every row of a type and leaves the others alone.
	 *
	 * @return void
	 */
	public function test_deindex_type_removes_only_that_type(): void {
		$search = new SearchService();

		$search->index( 'job', 1, 'Senior PHP Engineer', 'We are hiring', 1 );
		$search->index( 'job', 2, 'Junior PHP Engineer', 'Also hiring', 1 );
		$search->index( 'post', 3, 'A normal post', 'Body', 1 );

		$removed = $search->deindex_type( 'job' );

		$this->assertSame( 2, $removed, 'Both job rows should have been removed.' );
		$this->assertSame(
			array( 'post' ),
			$search->available_types(),
			'The disabled integration still has rows in the index — its content keeps surfacing in search, under its own tab.'
		);
	}

	/**
	 * The available-types cache must be flushed, or the ghost tab survives the purge.
	 *
	 * The available_types() method is a 5-minute-cached `SELECT DISTINCT object_type`, and the results
	 * template builds its type tabs from it. Purge the rows without flushing and the
	 * disabled integration keeps its own — now empty — search tab for up to five minutes.
	 *
	 * @return void
	 */
	public function test_deindex_type_flushes_the_available_types_cache(): void {
		$search = new SearchService();
		$search->index( 'job', 1, 'Senior PHP Engineer', 'We are hiring', 1 );

		// Warm the cache, the way a page render would.
		$this->assertContains( 'job', $search->available_types() );

		$search->deindex_type( 'job' );

		$this->assertNotContains(
			'job',
			$search->available_types(),
			'available_types() still reports the purged type — the results page keeps rendering an empty tab for the disabled integration.'
		);
	}

	/**
	 * A type with nothing indexed is a no-op, not an error.
	 *
	 * @return void
	 */
	public function test_deindex_type_on_an_empty_type_is_a_no_op(): void {
		$this->assertSame( 0, ( new SearchService() )->deindex_type( 'nothing_here' ) );
	}
}
