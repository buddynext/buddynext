<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * buddynext_sample_ranked() rotates the display set without throwing the
 * ranking away (card 10435746686). Two interest-matched spaces in a pool of 24
 * showed up in only about 1 of 4 loads because the shuffle window was the whole
 * pool; a scored caller now always shows its clear matches.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

/**
 * @covers ::buddynext_sample_ranked
 */
class SampleRankedTest extends \WP_UnitTestCase {

	/**
	 * 24 candidates: two clear matches (2.07), the rest weak (0.07).
	 *
	 * @return array<int,array{id:int,score:float}>
	 */
	private function pool(): array {
		$pool = array();
		for ( $i = 1; $i <= 24; $i++ ) {
			$pool[] = array(
				'id'    => $i,
				'score' => $i <= 2 ? 2.07 : 0.07,
			);
		}
		return $pool;
	}

	private function ids( array $rows ): array {
		return array_map( static fn( $r ) => $r['id'], $rows );
	}

	public function test_clear_matches_are_shown_on_every_load(): void {
		$score = static fn( array $r ): float => $r['score'];
		$seen  = array();
		for ( $n = 0; $n < 200; $n++ ) {
			$ids = $this->ids( buddynext_sample_ranked( $this->pool(), 6, 4, $score ) );
			$this->assertCount( 6, $ids );
			$this->assertEqualsCanonicalizing( array( 1, 2 ), array_slice( $ids, 0, 2 ), 'Both clear matches lead every load.' );
			$seen = array_merge( $seen, array_slice( $ids, 2 ) );
		}
		$this->assertGreaterThan( 4, count( array_unique( $seen ) ), 'The remaining slots still rotate.' );
	}

	public function test_more_clear_matches_than_slots_rotate_among_themselves(): void {
		$pool  = array_map( static fn( $r ) => array( 'id' => $r['id'], 'score' => $r['id'] <= 10 ? 2.0 : 0.1 ), $this->pool() );
		$score = static fn( array $r ): float => $r['score'];
		$seen  = array();
		for ( $n = 0; $n < 100; $n++ ) {
			$ids = $this->ids( buddynext_sample_ranked( $pool, 6, 4, $score ) );
			$this->assertEmpty( array_diff( $ids, range( 1, 10 ) ), 'Only clear matches are shown when they fill the display.' );
			$seen = array_merge( $seen, $ids );
		}
		$this->assertCount( 10, array_unique( $seen ) );
	}

	public function test_without_a_score_or_a_standout_it_rotates_as_before(): void {
		$flat = array_map( static fn( $r ) => array( 'id' => $r['id'], 'score' => 1.0 ), $this->pool() );
		$seen = array();
		for ( $n = 0; $n < 100; $n++ ) {
			$seen = array_merge(
				$seen,
				$this->ids( buddynext_sample_ranked( $this->pool(), 6 ) ),
				$this->ids( buddynext_sample_ranked( $flat, 6, 4, static fn( array $r ): float => $r['score'] ) )
			);
		}
		$this->assertGreaterThan( 12, count( array_unique( $seen ) ) );
	}
}
