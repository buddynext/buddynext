<?php
/**
 * A badge card names the level and never the points total, new cards and old alike.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;

/**
 * @covers \BuddyNext\Bridges\GamificationBridge::drop_points_fact
 */
class BadgeCardFactsTest extends \WP_UnitTestCase {

	public function test_the_points_fact_is_dropped_from_a_badge_card_and_the_level_stays(): void {
		$facts = array(
			array(
				'icon' => 'zap',
				'text' => '1,835 points',
			),
			array(
				'icon' => 'award',
				'text' => 'Regular',
			),
		);

		$out = ( new GamificationBridge() )->drop_points_fact( $facts, 'badge' );

		$this->assertSame( array( 'Regular' ), array_column( $out, 'text' ) );
		$this->assertSame( $facts, ( new GamificationBridge() )->drop_points_fact( $facts, 'job' ), 'Other card types are left alone.' );
	}

	public function test_the_live_answer_is_skipped_for_other_types_and_for_a_card_with_no_member(): void {
		$bridge = new GamificationBridge();
		$cards  = array(
			5 => array( 'url' => 'https://example.test/m/#badge-x' ),
		);

		$this->assertSame( array(), $bridge->live_badge_cards( array(), 'job', $cards ) );
		$this->assertSame( array(), $bridge->live_badge_cards( array(), 'badge', $cards ), 'No member id, no answer: the stored card stays.' );
	}
}
