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
}
