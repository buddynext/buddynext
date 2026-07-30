<?php
/**
 * A block must hide a member's comments at EVERY position in the thread.
 *
 * The first fix built the block map from the top-level page only, while applying
 * the hide predicate to descendants as well. So a blocked member whose only
 * comment on the page was a REPLY never entered the map, the predicate returned
 * false for them, and the reply rendered. The original card's reproduction was a
 * threaded reply, and my regression test passed anyway because it asserted on
 * top-level ids — the one shape that was already working.
 *
 * Separately the pinned comment was fetched directly and prepended without going
 * through the predicate at all, so a blocked author's pinned comment was
 * force-shown in the most prominent position on the surface.
 *
 * These tests assert on the FLATTENED thread — every author id that renders at
 * any depth, plus the pinned slot — because "is it in items[]" is exactly the
 * question that hid this the first time.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

/**
 * Block visibility across nesting depth and the pinned slot.
 *
 * @covers \BuddyNext\Comments\CommentService::list
 */
class BlockedRepliesAndPinnedTest extends \WP_UnitTestCase {

	/**
	 * Member who blocks.
	 *
	 * @var int
	 */
	private $blocker = 0;

	/**
	 * Member who is blocked.
	 *
	 * @var int
	 */
	private $blocked = 0;

	/**
	 * Uninvolved third member who owns the post.
	 *
	 * @var int
	 */
	private $bystander = 0;

	/**
	 * Post the thread hangs on.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Three members and a third-party post.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wpdb;

		parent::set_up();

		$this->blocker   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->blocked   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->bystander = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->bystander,
				'content'    => 'A thread owned by nobody involved in the block',
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	/**
	 * Normalise a create() return to an id.
	 *
	 * @param mixed $created Whatever create() returned.
	 * @return int
	 */
	private function id( $created ): int {
		return is_array( $created ) ? (int) ( $created['id'] ?? 0 ) : (int) $created;
	}

	/**
	 * Every author id rendered anywhere in the thread, at any depth.
	 *
	 * @return int[]
	 */
	private function rendered_author_ids( int $viewer = 0 ): array {
		$listed = buddynext_service( 'comments' )->list(
			'post',
			$this->post_id,
			array(
				'viewer_id' => $viewer > 0 ? $viewer : $this->blocker,
				'per_page'  => 50,
			)
		);

		$ids     = array();
		$walk    = static function ( array $rows ) use ( &$walk, &$ids ): void {
			foreach ( $rows as $row ) {
				$ids[] = (int) ( $row['user_id'] ?? 0 );
				if ( ! empty( $row['replies'] ) && is_array( $row['replies'] ) ) {
					$walk( $row['replies'] );
				}
				if ( ! empty( $row['children'] ) && is_array( $row['children'] ) ) {
					$walk( $row['children'] );
				}
			}
		};
		$walk( (array) ( $listed['items'] ?? $listed ) );

		return $ids;
	}

	/**
	 * The regression, and the card's own reproduction: the blocked member's only
	 * comment is a REPLY under someone else's root.
	 *
	 * @return void
	 */
	public function test_a_blocked_members_reply_is_hidden(): void {
		$comments = buddynext_service( 'comments' );

		$root = $this->id( $comments->create( $this->bystander, 'post', $this->post_id, 'Root by the bystander' ) );
		$comments->create( $this->blocked, 'post', $this->post_id, 'Reply by the blocked member', $root );

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );

		$this->assertNotContains(
			$this->blocked,
			$this->rendered_author_ids(),
			'A blocked member\'s REPLY still rendered — the block map did not cover descendants.'
		);
	}

	/**
	 * ...including several levels down, where the author appears nowhere at top
	 * level so cannot be resolved by accident.
	 *
	 * @return void
	 */
	public function test_a_blocked_members_deep_reply_is_hidden(): void {
		$comments = buddynext_service( 'comments' );

		$root  = $this->id( $comments->create( $this->bystander, 'post', $this->post_id, 'Root' ) );
		$mid   = $this->id( $comments->create( $this->bystander, 'post', $this->post_id, 'Mid reply', $root ) );
		$comments->create( $this->blocked, 'post', $this->post_id, 'Deep reply by the blocked member', $mid );

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );

		$this->assertNotContains( $this->blocked, $this->rendered_author_ids() );
	}

	/**
	 * The second leak: a pinned comment is fetched directly and prepended, so it
	 * skipped the predicate entirely and appeared in the most prominent slot.
	 *
	 * @return void
	 */
	public function test_a_blocked_members_pinned_comment_is_hidden(): void {
		$comments = buddynext_service( 'comments' );

		// Give the page a root by someone else so the thread is not empty once the
		// blocked author is hidden.
		$comments->create( $this->bystander, 'post', $this->post_id, 'Root by the bystander' );

		$pinned_id = $this->id( $comments->create( $this->blocked, 'post', $this->post_id, 'Pinned comment by the blocked member' ) );

		// Pinning needs manage_options (or space moderator) — can_pin_comment().
		// The first version of this test pinned as a subscriber, so pin() returned
		// false, nothing was ever pinned, and the assertion below passed against a
		// thread with no pinned comment at all. Asserting the pin succeeded is what
		// stops it being vacuous: without it, mutating the fix back still passed.
		$moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertTrue(
			$comments->pin( $pinned_id, $moderator ),
			'Fixture failed: the comment was never pinned, so this test would prove nothing.'
		);

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );

		$this->assertNotContains(
			$this->blocked,
			$this->rendered_author_ids(),
			'A blocked member\'s PINNED comment was force-shown at the top of the thread.'
		);
	}

	/**
	 * Scope guard: hiding must not empty the thread of everyone else. Without this
	 * a fix that hid every reply would pass all three assertions above.
	 *
	 * @return void
	 */
	public function test_unrelated_members_replies_still_render(): void {
		$comments = buddynext_service( 'comments' );

		$root = $this->id( $comments->create( $this->bystander, 'post', $this->post_id, 'Root' ) );
		$comments->create( $this->bystander, 'post', $this->post_id, 'Reply by the bystander', $root );
		$comments->create( $this->blocked, 'post', $this->post_id, 'Reply by the blocked member', $root );

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );

		$rendered = $this->rendered_author_ids();

		$this->assertNotContains( $this->blocked, $rendered );
		$this->assertContains( $this->bystander, $rendered, 'Hiding the blocked author also removed an uninvolved member\'s reply.' );
	}

	/**
	 * The blocked member still sees their own reply — hiding someone's own words
	 * from them reads as the comment having been deleted.
	 *
	 * @return void
	 */
	public function test_the_blocked_member_still_sees_their_own_reply(): void {
		$comments = buddynext_service( 'comments' );

		$root = $this->id( $comments->create( $this->bystander, 'post', $this->post_id, 'Root' ) );
		$comments->create( $this->blocked, 'post', $this->post_id, 'My own reply', $root );

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );

		$this->assertContains(
			$this->blocked,
			$this->rendered_author_ids( $this->blocked ),
			'The blocked member could not see their own reply.'
		);
	}
}
