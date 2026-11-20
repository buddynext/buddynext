<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing -- concise, self-describing test methods.
/**
 * Banned words reach forum topics and replies through the Jetonomy bridge.
 *
 * @package BuddyNext\Tests\Bridges
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Bridges;

use BuddyNext\Bridges\JetonomyBridge;
use WP_UnitTestCase;

class JetonomyBannedWordsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		update_option( 'buddynext_banned_words', 'zzbadword' );
	}

	public function tear_down(): void {
		delete_option( 'buddynext_banned_words' );
		parent::tear_down();
	}

	public function test_a_banned_word_in_the_title_or_body_refuses_the_topic_with_a_general_message(): void {
		$bridge = new JetonomyBridge();
		foreach ( array( array( 'title' => 'a zzbadword', 'content' => 'fine' ), array( 'title' => 'fine', 'content' => 'has zzbadword' ) ) as $topic ) {
			$result = $bridge->check_new_topic( $topic + array( 'author_id' => self::factory()->user->create() ) );
			$this->assertWPError( $result );
			$this->assertSame( 'bn_forum_content_blocked', $result->get_error_code() );
			$this->assertStringNotContainsString( 'zzbadword', $result->get_error_message() );
		}
	}

	public function test_a_banned_word_refuses_a_reply_and_clean_text_passes(): void {
		$bridge = new JetonomyBridge();
		$author = self::factory()->user->create();
		$this->assertWPError( $bridge->check_new_reply( array( 'content' => 'zzbadword', 'author_id' => $author ) ) );
		$clean = array( 'title' => 'Hello', 'content' => 'all good', 'author_id' => $author );
		$this->assertSame( $clean, $bridge->check_new_topic( $clean ) );
		$this->assertSame( array( 'content' => 'fine' ), $bridge->check_new_reply( array( 'content' => 'fine' ) ) );
	}

	public function test_editing_a_topic_or_reply_into_a_banned_one_is_refused_and_other_routes_are_left_alone(): void {
		$bridge = new JetonomyBridge();
		wp_set_current_user( self::factory()->user->create() );
		foreach ( array( '/jetonomy/v1/posts/5', '/jetonomy/v1/replies/9' ) as $route ) {
			$edit = new \WP_REST_Request( 'PATCH', $route );
			$edit->set_param( 'content', 'now zzbadword' );
			$this->assertWPError( $bridge->check_forum_edit( null, array(), $edit ), $route );

			$edit->set_param( 'content', 'a fine edit' );
			$this->assertNull( $bridge->check_forum_edit( null, array(), $edit ), 'A clean edit passes.' );
		}
		$other = new \WP_REST_Request( 'PATCH', '/jetonomy/v1/posts/5/close' );
		$other->set_param( 'content', 'zzbadword' );
		$this->assertNull( $bridge->check_forum_edit( null, array(), $other ), 'Only the edit routes are checked.' );
		$read = new \WP_REST_Request( 'GET', '/jetonomy/v1/posts/5' );
		$read->set_param( 'content', 'zzbadword' );
		$this->assertNull( $bridge->check_forum_edit( null, array(), $read ) );
	}
}
