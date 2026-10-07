<?php
/**
 * A feed page answers follow / pending / degree for all its authors in a few
 * queries, with the same answers as the one-by-one lookups.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;

/**
 * @covers \BuddyNext\SocialGraph\ConnectionService::degrees_for
 * @covers \BuddyNext\SocialGraph\FollowService::following_map
 */
class FeedBylineBatchTest extends \WP_UnitTestCase {

	/**
	 * Make two members connected.
	 *
	 * @param ConnectionService $c Service.
	 * @param int               $a Member.
	 * @param int               $b Member.
	 * @return void
	 */
	private function connect( ConnectionService $c, int $a, int $b ): void {
		$c->send_request( $a, $b );
		$c->accept_request( $b, $a );
	}

	/**
	 * Degrees 1, 2 and 3, including two authors connected to each other.
	 *
	 * @return void
	 */
	public function test_batch_degrees_match_single_lookups(): void {
		$c = new ConnectionService();
		list( $viewer, $friend, $fof, $a1, $a2, $stranger ) = self::factory()->user->create_many( 6 );
		$this->connect( $c, $viewer, $friend ); // degree 1.
		$this->connect( $c, $friend, $fof );    // fof: degree 2 via friend.
		// a2 is only ever the RECIPIENT of its rows and a1 the REQUESTER, and a1-a2 is
		// a row between two page authors: both directions of the lookup are exercised.
		$this->connect( $c, $friend, $a2 );     // a2: degree 2 via friend (a2 recipient).
		$this->connect( $c, $a2, $a1 );         // author-author row.
		$this->connect( $c, $a1, $friend );     // a1: degree 2 via friend (a1 requester).

		$subjects = array( $friend, $fof, $a1, $a2, $stranger );
		wp_cache_flush();
		$batch = $c->degrees_for( $viewer, $subjects );
		$this->assertSame( array( 1, 2, 2, 2, 3 ), array_map( static fn( $s ) => $batch[ $s ], $subjects ) );

		foreach ( $subjects as $s ) {
			wp_cache_flush();
			( new \ReflectionProperty( ConnectionService::class, 'degree_memo' ) )->setValue( null, array() );
			$this->assertSame( $batch[ $s ], $c->connection_degree( $viewer, $s ), "Degree for member $s matches the single lookup." );
		}
	}

	/**
	 * Primed follow/pending answers are cache hits, and a follow written after
	 * priming is seen at once.
	 *
	 * @return void
	 */
	public function test_follow_maps_prime_single_lookups_and_writes_invalidate(): void {
		$f = new FollowService();
		list( $viewer, $followed, $other ) = self::factory()->user->create_many( 3 );
		$f->follow( $viewer, $followed );
		wp_cache_flush();

		$f->following_map( $viewer, array( $followed, $other ) );
		$f->pending_map( $viewer, array( $followed, $other ) );
		global $wpdb;
		$before = $wpdb->num_queries;
		$this->assertTrue( $f->is_following( $viewer, $followed ) );
		$this->assertFalse( $f->is_following( $viewer, $other ) );
		$this->assertFalse( $f->has_pending_request( $viewer, $other ) );
		$this->assertSame( $before, $wpdb->num_queries, 'Primed answers cost no queries.' );

		$f->follow( $viewer, $other );
		$this->assertTrue( $f->is_following( $viewer, $other ) || $f->has_pending_request( $viewer, $other ), 'A follow after priming is seen.' );
	}
}
