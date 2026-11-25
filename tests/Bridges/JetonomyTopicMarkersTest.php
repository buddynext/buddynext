<?php
/**
 * A topic row in a Space's Discussions tab names its state in a fixed order.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;

/**
 * @covers \BuddyNext\Bridges\JetonomyBridge::topic_markers
 */
class JetonomyTopicMarkersTest extends \WP_UnitTestCase {

	public function test_pinned_comes_first_and_solved_wins_over_closed(): void {
		$topic = (object) array(
			'is_sticky'         => 1,
			'is_closed'         => 1,
			'accepted_reply_id' => 9,
		);
		$this->assertSame( array( 'Pinned', 'Solved' ), JetonomyBridge::topic_markers( $topic ) );
	}

	public function test_closed_alone_and_a_plain_topic(): void {
		$this->assertSame( array( 'Closed' ), JetonomyBridge::topic_markers( (object) array( 'is_closed' => 1 ) ) );
		$this->assertSame( array(), JetonomyBridge::topic_markers( (object) array() ) );
	}

	public function test_the_panel_shows_marks_tags_and_how_many_there_are(): void {
		$rows = array(
			(object) array(
				'id'          => 1,
				'title'       => 'Autumn schedule',
				'slug'        => 'autumn-schedule',
				'reply_count' => 5,
				'vote_score'  => 0,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'author_id'   => 0,
				'author_name' => 'Diego',
				'is_sticky'   => 1,
				'tags'        => array( 'schedule' ),
			),
		);

		ob_start();
		buddynext_get_template(
			'parts/space-discussions-panel.php',
			array(
				'space'        => (object) array( 'name' => 'Trail Runners' ),
				'discussions'  => $rows,
				'forum_url'    => 'https://example.test/community/s/trail/',
				'forum_linked' => true,
				'total'        => 45,
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Pinned', $html );
		$this->assertStringContainsString( 'schedule', $html );
		$this->assertStringContainsString( 'Showing 1 of 45', $html );
		$this->assertStringContainsString( 'See all discussions', $html );
	}
}
