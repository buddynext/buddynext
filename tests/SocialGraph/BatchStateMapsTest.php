<?php
/**
 * Batched follow/block state for list renders.
 *
 * partials/follow-button.php asks three questions per render - is either party
 * blocking, am I following, did I request - so any surface drawing it in a loop
 * multiplied all three. The leaderboard measured 312 queries for a 50-row page,
 * 147 of them from that partial alone.
 *
 * Two gaps caused it. following_map() existed but pending_map() did not, so a
 * caller could batch one of the three and still pay an N+1 for the other. And
 * blocking_either_map() answered the caller without priming the memo that
 * is_blocking_either() reads, so an INDIRECT caller - PrivacyService::can_follow()
 * on the same row - queried again anyway.
 *
 * These tests pin behaviour, not query counts: the maps must agree with the
 * single-value methods they replace, and priming must not change any answer.
 * A batch that is fast but disagrees with the per-row check is worse than the
 * N+1 it replaced.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\FollowService;

/**
 * Batched state maps agree with their per-row equivalents.
 *
 * @covers \BuddyNext\SocialGraph\FollowService::pending_map
 * @covers \BuddyNext\SocialGraph\BlockService::blocking_either_map
 */
class BatchStateMapsTest extends \WP_UnitTestCase {

	/**
	 * Viewer whose list is being rendered.
	 *
	 * @var int
	 */
	private $viewer = 0;

	/**
	 * Peers appearing in the list.
	 *
	 * @var int[]
	 */
	private $peers = array();

	/**
	 * A viewer and six peers.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		for ( $i = 0; $i < 6; $i++ ) {
			$this->peers[] = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		}
	}

	/**
	 * A pending request must appear in the map, and a non-request must not.
	 *
	 * @return void
	 */
	public function test_pending_map_matches_has_pending_request(): void {
		$follows = new FollowService();

		// Make one peer private so a follow becomes a REQUEST rather than a follow.
		update_user_meta( $this->peers[0], 'buddynext_private_account', '1' );
		$follows->follow( $this->viewer, $this->peers[0] );

		$map = $follows->pending_map( $this->viewer, $this->peers );

		foreach ( $this->peers as $peer ) {
			$this->assertSame(
				$follows->has_pending_request( $this->viewer, $peer ),
				(bool) ( $map[ $peer ] ?? false ),
				"pending_map disagreed with has_pending_request for peer {$peer}."
			);
		}
	}

	/**
	 * Every requested id is present, so a caller can read the map directly
	 * without distinguishing "false" from "absent".
	 *
	 * @return void
	 */
	public function test_pending_map_returns_an_entry_for_every_target(): void {
		$map = ( new FollowService() )->pending_map( $this->viewer, $this->peers );

		foreach ( $this->peers as $peer ) {
			$this->assertArrayHasKey( $peer, $map );
		}
	}

	/**
	 * Degenerate input must not blow up or invent entries.
	 *
	 * @return void
	 */
	public function test_pending_map_handles_empty_input(): void {
		$follows = new FollowService();

		$this->assertSame( array(), $follows->pending_map( $this->viewer, array() ) );
		$this->assertSame( array( $this->peers[0] => false ), $follows->pending_map( 0, array( $this->peers[0] ) ) );
	}

	/**
	 * The regression: after the batch call, a per-pair check must return the
	 * SAME answer for every peer - including the un-blocked ones, which is the
	 * half that was not being primed and so kept querying.
	 *
	 * @return void
	 */
	public function test_blocking_either_map_primes_the_pair_check_for_all_peers(): void {
		$blocks = new BlockService();
		$blocks->block( $this->viewer, $this->peers[1] );
		$blocks->block( $this->peers[2], $this->viewer );

		$map = $blocks->blocking_either_map( $this->viewer, $this->peers );

		foreach ( $this->peers as $peer ) {
			$this->assertSame(
				(bool) ( $map[ $peer ] ?? false ),
				$blocks->is_blocking_either( $this->viewer, $peer ),
				"is_blocking_either disagreed with the primed map for peer {$peer}."
			);
		}
	}

	/**
	 * Priming must reflect BOTH directions - the map is symmetric, and a block
	 * received counts exactly like a block made.
	 *
	 * @return void
	 */
	public function test_a_block_in_either_direction_is_reported(): void {
		$blocks = new BlockService();
		$blocks->block( $this->viewer, $this->peers[1] );  // Viewer blocked them.
		$blocks->block( $this->peers[2], $this->viewer );  // They blocked viewer.

		$map = $blocks->blocking_either_map( $this->viewer, $this->peers );

		$this->assertTrue( (bool) ( $map[ $this->peers[1] ] ?? false ), 'Outgoing block missing from the map.' );
		$this->assertTrue( (bool) ( $map[ $this->peers[2] ] ?? false ), 'Incoming block missing from the map.' );
		$this->assertFalse( (bool) ( $map[ $this->peers[3] ] ?? false ), 'An unrelated peer was reported as blocked.' );
	}

	/**
	 * The priming itself, which agreement alone cannot prove: without it
	 * is_blocking_either() still returns the right answer, it just pays a query
	 * for it, so every assertion above passes either way. This one fails if the
	 * priming is removed.
	 *
	 * Counts queries rather than asserting a total, because the map's own query
	 * count is not this test's business - only that the per-pair checks after it
	 * are free.
	 *
	 * @return void
	 */
	public function test_the_pair_checks_after_a_batch_call_cost_no_queries(): void {
		global $wpdb;

		$blocks = new BlockService();
		$blocks->block( $this->viewer, $this->peers[1] );

		$blocks->blocking_either_map( $this->viewer, $this->peers );

		$before = $wpdb->num_queries;
		foreach ( $this->peers as $peer ) {
			$blocks->is_blocking_either( $this->viewer, $peer );
		}
		$spent = $wpdb->num_queries - $before;

		$this->assertSame(
			0,
			$spent,
			"Each pair check after blocking_either_map() should be a memo hit; {$spent} queries were run for " . count( $this->peers ) . ' peers.'
		);
	}

	/**
	 * A block created AFTER the map was built must not be masked by the primed
	 * memo. Invalidation already clears the pair on write; this guards against
	 * the priming turning a live change into a stale "not blocked" for the rest
	 * of the request.
	 *
	 * @return void
	 */
	public function test_priming_does_not_mask_a_later_block(): void {
		$blocks = new BlockService();

		$blocks->blocking_either_map( $this->viewer, $this->peers );
		$this->assertFalse( $blocks->is_blocking_either( $this->viewer, $this->peers[4] ) );

		$blocks->block( $this->viewer, $this->peers[4] );

		$this->assertTrue(
			$blocks->is_blocking_either( $this->viewer, $this->peers[4] ),
			'A block made after the batch call was hidden by the primed memo.'
		);
	}
}
