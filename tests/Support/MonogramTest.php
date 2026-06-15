<?php
/**
 * Tests for the Monogram helper.
 *
 * @package BuddyNext\Tests\Support
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Support;

use BuddyNext\Support\Monogram;

/**
 * @covers \BuddyNext\Support\Monogram
 */
class MonogramTest extends \WP_UnitTestCase {

	public function test_two_word_initials(): void {
		$this->assertSame( 'AC', Monogram::initials( 'Acme Corp' ) );
		$this->assertSame( 'SL', Monogram::initials( 'Starter Labs' ) );
	}

	public function test_single_word_initial(): void {
		$this->assertSame( 'P', Monogram::initials( 'PayFlow' ) );
	}

	public function test_first_and_last_word_only(): void {
		// First word + last word: "Backend" + "Payments".
		$this->assertSame( 'BP', Monogram::initials( 'Backend Engineer — Payments' ) );
	}

	public function test_empty_falls_back_to_bullet(): void {
		$this->assertSame( '•', Monogram::initials( '   ' ) );
		$this->assertSame( '•', Monogram::initials( '' ) );
	}

	public function test_tone_is_deterministic_and_in_range(): void {
		$a = Monogram::tone( 'Acme Corp' );
		$b = Monogram::tone( 'Acme Corp' );
		$this->assertSame( $a, $b, 'same seed → same tone' );
		$this->assertGreaterThanOrEqual( 1, $a );
		$this->assertLessThanOrEqual( Monogram::TONES, $a );
	}

	public function test_different_seeds_can_differ(): void {
		$tones = array_map(
			static fn( string $s ): int => Monogram::tone( $s ),
			array( 'Acme Corp', 'Starter Labs', 'PayFlow', 'Rivera Labs' )
		);
		$this->assertGreaterThan( 1, count( array_unique( $tones ) ), 'palette spreads across seeds' );
	}

	public function test_empty_seed_tone_is_stable(): void {
		$this->assertSame( 1, Monogram::tone( '' ) );
	}
}
