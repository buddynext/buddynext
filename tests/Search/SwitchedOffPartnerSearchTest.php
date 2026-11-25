<?php
/**
 * A switched-off partner's rows never reach a search result.
 *
 * With the partner plugin off, its index rows stay (they return when it does) but a
 * result row would open on a page that no longer exists. The same orphan check the
 * feed uses hides them at read time.
 *
 * @package BuddyNext\Tests\Search
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;

/**
 * @covers \BuddyNext\Search\SearchService::search
 * @covers \BuddyNext\Search\SearchService::orphaned_object_types
 */
class SwitchedOffPartnerSearchTest extends \WP_UnitTestCase {

	/**
	 * A listing row is hidden while nothing renders listings, and shows when something does.
	 */
	public function test_partner_rows_hide_until_the_partner_renders_them(): void {
		$search = new SearchService();
		$author = self::factory()->user->create();
		$search->index( 'listing', 9101, 'Zebracorn Outfitters', 'Zebracorn shop', $author, 'public', 0 );
		$search->index( 'post', 9102, 'Zebracorn photos', 'Zebracorn post', $author, 'public', 0 );

		$this->assertContains( 'listing', SearchService::orphaned_object_types() );

		$types = array_column( $search->search( 'Zebracorn', '', 20, 1, 0 )['items'], 'object_type' );
		$this->assertNotContains( 'listing', $types, 'A switched-off partner row must not be listed.' );
		$this->assertContains( 'post', $types, 'Core rows are untouched.' );

		add_filter( 'buddynext_render_post_body_listing', '__return_empty_string' );
		$this->assertNotContains( 'listing', SearchService::orphaned_object_types() );
		$types = array_column( $search->search( 'Zebracorn', '', 20, 1, 0 )['items'], 'object_type' );
		$this->assertContains( 'listing', $types, 'The row returns when the partner is back.' );
		remove_filter( 'buddynext_render_post_body_listing', '__return_empty_string' );
	}
}
