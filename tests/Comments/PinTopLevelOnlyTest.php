<?php
/**
 * Only a top-level comment can be pinned.
 *
 * Pinning promotes a comment to the top of its thread. Nothing restricted it to
 * top-level comments, so pinning a reply that already lived several levels down
 * promoted it into a top-level slot AND left it in its real position under its
 * parent - the same reply, and its own children, rendered twice on the page.
 *
 * The fix is the rule rather than a de-duplicating pass over the tree: a reply
 * only means anything underneath the comment it answers, so lifting one out of
 * its chain was never coherent. YouTube, Facebook and LinkedIn all restrict
 * pinning to top-level comments for the same reason.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

/**
 * Pin eligibility and thread integrity.
 *
 * @covers \BuddyNext\Comments\CommentService::pin
 */
class PinTopLevelOnlyTest extends \WP_UnitTestCase {

	/**
	 * Moderator doing the pinning.
	 *
	 * @var int
	 */
	private $moderator = 0;

	/**
	 * Post the thread hangs on.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Top-level comment id.
	 *
	 * @var int
	 */
	private $root = 0;

	/**
	 * Depth-2 reply id.
	 *
	 * @var int
	 */
	private $reply = 0;

	/**
	 * Depth-3 reply id.
	 *
	 * @var int
	 */
	private $deep_reply = 0;

	/**
	 * A post with a three-level comment chain.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->moderator );

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->moderator,
				'content'    => 'A post with a nested comment thread',
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$this->post_id = (int) $wpdb->insert_id;

		$comments         = buddynext_service( 'comments' );
		$this->root       = $this->id( $comments->create( $this->moderator, 'post', $this->post_id, 'Top-level comment' ) );
		$this->reply      = $this->id( $comments->create( $this->moderator, 'post', $this->post_id, 'A reply', $this->root ) );
		$this->deep_reply = $this->id( $comments->create( $this->moderator, 'post', $this->post_id, 'A reply to the reply', $this->reply ) );
	}

	/**
	 * Normalise a create() return value to an id.
	 *
	 * @param mixed $created Whatever create() returned.
	 * @return int
	 */
	private function id( $created ): int {
		return is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : (int) $created;
	}

	/**
	 * Top-level comment ids currently listed for the thread.
	 *
	 * @return int[]
	 */
	private function listed_ids(): array {
		$listed = buddynext_service( 'comments' )->list(
			'post',
			$this->post_id,
			array(
				'viewer_id' => $this->moderator,
				'per_page'  => 50,
			)
		);

		return array_map(
			static fn( array $c ): int => (int) $c['id'],
			(array) ( $listed['items'] ?? $listed )
		);
	}

	/**
	 * The regression: a nested reply cannot be pinned.
	 *
	 * @return void
	 */
	public function test_a_nested_reply_cannot_be_pinned(): void {
		$this->assertFalse(
			buddynext_service( 'comments' )->pin( $this->deep_reply, $this->moderator ),
			'A reply three levels down was accepted for pinning.'
		);
	}

	/**
	 * Including one only a single level down - depth is not the point, having a
	 * parent is.
	 *
	 * @return void
	 */
	public function test_a_direct_reply_cannot_be_pinned_either(): void {
		$this->assertFalse(
			buddynext_service( 'comments' )->pin( $this->reply, $this->moderator )
		);
	}

	/**
	 * Pinning a top-level comment still works and still sorts it first - the
	 * feature must survive the restriction.
	 *
	 * @return void
	 */
	public function test_a_top_level_comment_still_pins_and_sorts_first(): void {
		$this->assertTrue(
			buddynext_service( 'comments' )->pin( $this->root, $this->moderator )
		);

		$ids = $this->listed_ids();

		$this->assertNotEmpty( $ids );
		$this->assertSame( $this->root, $ids[0], 'The pinned comment did not sort to the top.' );
	}

	/**
	 * The actual symptom: no comment may appear twice in the listing.
	 *
	 * @return void
	 */
	public function test_no_comment_is_listed_twice_after_a_pin_attempt(): void {
		$comments = buddynext_service( 'comments' );
		$comments->pin( $this->deep_reply, $this->moderator );
		$comments->pin( $this->reply, $this->moderator );

		$ids = $this->listed_ids();

		$this->assertSame(
			count( $ids ),
			count( array_unique( $ids ) ),
			'A comment was rendered twice in the thread after being pinned out of its chain.'
		);
		$this->assertNotContains(
			$this->deep_reply,
			$ids,
			'A nested reply was promoted into a top-level slot.'
		);
	}
}
