<?php
/**
 * One search by a member fires buddynext_search_performed once.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;

/**
 * Grouped search runs search() once per indexed type. It used to fire the
 * action from every one of those calls, so Pro analytics logged one search as
 * three or more, the empty types as zero-result searches.
 */
class SearchPerformedOnceTest extends \WP_UnitTestCase {

	public function test_grouped_search_fires_the_action_once_with_type_all(): void {
		$svc = new SearchService();
		$svc->index( 'user', 9001, 'Quillon Member', 'quillon member', 0, 'public' );
		$svc->index( 'post', 9002, 'Quillon post', 'quillon post body', 0, 'public' );
		$svc->index( 'space', 9003, 'Quillon space', 'quillon space', 0, 'public' );

		$fired = array();
		$spy   = static function ( $query, $viewer, $args, $results ) use ( &$fired ): void {
			$fired[] = array( $args['type'], (int) $results['total'] );
		};
		add_action( 'buddynext_search_performed', $spy, 10, 4 );
		wp_cache_delete( 'search_object_types', 'buddynext_search' );

		$svc->grouped_search( 'quillon', 0 );
		$svc->search( 'quillon', 'post', 10, 1, 0 );

		remove_action( 'buddynext_search_performed', $spy, 10 );

		$this->assertCount( 2, $fired, 'one grouped search + one typed search' );
		$this->assertSame( '', $fired[0][0] );
		$this->assertSame( 'post', $fired[1][0] );
	}
}
