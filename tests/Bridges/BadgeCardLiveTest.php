<?php
/**
 * A badge card keeps its facts and adds a See badges button.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\GamificationBridge;

/**
 * @covers \BuddyNext\Bridges\GamificationBridge::badge_card
 * @covers \BuddyNext\Bridges\GamificationBridge::live_badge_cards
 */
class BadgeCardLiveTest extends \WP_UnitTestCase {

	public function test_the_facts_are_kept_and_the_button_goes_to_the_achievements(): void {
		$facts = array(
			array(
				'icon' => 'zap',
				'text' => '1,835 points',
			),
		);
		$card = GamificationBridge::badge_card( $facts, 0, 'https://example.test/members/a/achievements/' );

		$this->assertSame( '1,835 points', $card['facts'][0]['text'] );
		$this->assertSame( 'See badges', $card['actions'][0]['label'] );
		$this->assertSame( array(), GamificationBridge::badge_card( $facts, 0, '' )['actions'] );
	}

	public function test_other_types_and_cards_without_an_author_answer_nothing(): void {
		$bridge = new GamificationBridge();
		$this->assertSame( array(), $bridge->live_badge_cards( array(), 'job', array( 1 => array( 'user_id' => 5 ) ) ) );
		$this->assertSame( array(), $bridge->live_badge_cards( array(), 'badge', array( 1 => array( 'url' => 'x' ) ) ) );
	}
}
