<?php
/**
 * A space created later in the same request gets its real visibility ceiling.
 *
 * space_visibility_ceiling() memoises per request. It used to memoise "no such
 * space" as 'private' too, so indexing into an id before the space existed (an
 * import, the demo seeder, a test) left every later public post in that space
 * indexed as private and hidden from guest search.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Search;

use BuddyNext\Search\SearchService;
use BuddyNext\Spaces\SpaceService;

/**
 * @group search
 */
class SpaceCeilingMemoTest extends \WP_UnitTestCase {

	public function test_a_space_created_after_a_lookup_is_public(): void {
		global $wpdb;
		\BuddyNext\Core\Installer::run();

		// The id the next space will receive (stats refreshed: MySQL 8 caches them).
		$wpdb->query( 'SET SESSION information_schema_stats_expiry = 0' );
		$next = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $wpdb->prefix . 'bn_spaces' ) );

		$search = new SearchService();
		$search->index( 'post', 93001, 'Early', 'earlyzorp before the space exists', 1, 'public', $next );

		$space = ( new SpaceService() )->create( self::factory()->user->create(), array( 'name' => 'Late open', 'slug' => 'late-open-' . $next, 'type' => 'open' ) );
		$this->assertSame( $next, (int) $space, 'Fixture assumption: the space took the predicted id.' );

		$search->index( 'post', 93002, 'Late', 'latezorp after the space exists', 1, 'public', $next );

		$this->assertSame( 'public', (string) $wpdb->get_var( $wpdb->prepare( "SELECT visibility FROM {$wpdb->prefix}bn_search_index WHERE object_type = 'post' AND object_id = %d", 93002 ) ) );
	}
}
