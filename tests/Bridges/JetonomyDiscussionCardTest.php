<?php
/**
 * A discussion card shows its replies, votes, state and tags and one button.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge::discussion_card
 * @covers \BuddyNext\Bridges\JetonomyBridge::live_discussion_cards
 */
class JetonomyDiscussionCardTest extends \WP_UnitTestCase {

	public function test_solved_topic_with_votes_and_tags(): void {
		$card = JetonomyBridge::discussion_card(
			array(
				'reply_count'       => 14,
				'vote_score'        => 18,
				'is_resolved'       => 1,
				'accepted_reply_id' => 9,
			),
			array( 'gear', 'shoes', 'beginner' ),
			'https://example.test/t/1/'
		);
		$text = array_column( $card['facts'], 'text' );
		$this->assertSame( array( '14 replies', '18 votes', 'Solved', 'gear, shoes' ), $text );
		$this->assertSame( 'Open discussion', $card['actions'][0]['label'] );
	}

	public function test_closed_unanswered_topic_shows_no_zero_votes(): void {
		$card = JetonomyBridge::discussion_card(
			array(
				'reply_count' => 0,
				'vote_score'  => 0,
				'is_closed'   => 1,
			),
			array(),
			''
		);
		$this->assertSame( array( '0 replies', 'Closed' ), array_column( $card['facts'], 'text' ) );
		$this->assertSame( array(), $card['actions'] );
	}

	public function test_other_card_types_and_cards_without_a_topic_id_are_left_alone(): void {
		$bridge = new JetonomyBridge();
		$this->assertSame( array(), $bridge->live_discussion_cards( array(), 'job', array( 1 => array( 'post_id' => 5 ) ) ) );
		$this->assertSame( array(), $bridge->live_discussion_cards( array(), 'discussion', array( 1 => array( 'url' => 'x' ) ) ) );
	}
}
