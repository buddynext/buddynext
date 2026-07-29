<?php
/**
 * Blocking must hide comments, in both directions.
 *
 * Blocking had no effect on comments at all. A member who blocked someone kept
 * seeing that person's comments - name and content - on every thread, with no
 * way to make them go away. Reactions have enforced blocks bidirectionally for a
 * while; comments never asked the question.
 *
 * The restrict gate that CommentService::list() already had is a different,
 * narrower feature - a post owner restricting a specific commenter on their own
 * posts - and it does not consult user-to-user blocks, so it never covered this.
 *
 * These tests pin the SEMANTICS rather than the query: who can see whose
 * comments, given a block. That keeps them true if the filtering moves into SQL
 * or changes shape.
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

/**
 * Block-aware comment visibility.
 *
 * @covers \BuddyNext\Comments\CommentService::list
 */
class CommentBlockVisibilityTest extends \WP_UnitTestCase {

	/**
	 * The member who blocks.
	 *
	 * @var int
	 */
	private $blocker = 0;

	/**
	 * The member who is blocked.
	 *
	 * @var int
	 */
	private $blocked = 0;

	/**
	 * An uninvolved third member.
	 *
	 * @var int
	 */
	private $bystander = 0;

	/**
	 * A post owned by the bystander, so neither party is the thread owner and the
	 * restrict gate cannot be what produces the result.
	 *
	 * @var int
	 */
	private $post_id = 0;

	/**
	 * Three members, a third-party post, and a comment from each party.
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
				'content'    => 'A thread nobody involved in the block owns',
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		$this->post_id = (int) $wpdb->insert_id;

		$comments = buddynext_service( 'comments' );
		$comments->create( $this->blocked, 'post', $this->post_id, 'Comment by the blocked member' );
		$comments->create( $this->blocker, 'post', $this->post_id, 'Comment by the blocking member' );
		$comments->create( $this->bystander, 'post', $this->post_id, 'Comment by the bystander' );

		buddynext_service( 'blocks' )->block( $this->blocker, $this->blocked );
	}

	/**
	 * How many comments by $author are visible to $viewer.
	 *
	 * @param int $viewer Viewer user id.
	 * @param int $author Comment author user id.
	 * @return int
	 */
	private function visible_count( int $viewer, int $author ): int {
		wp_set_current_user( $viewer );

		$listed = buddynext_service( 'comments' )->list(
			'post',
			$this->post_id,
			array(
				'viewer_id' => $viewer,
				'per_page'  => 50,
			)
		);

		$count = 0;
		foreach ( (array) ( $listed['items'] ?? $listed ) as $comment ) {
			if ( (int) ( $comment['user_id'] ?? 0 ) === $author ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * The regression: the blocker must not see the blocked member's comments.
	 *
	 * @return void
	 */
	public function test_the_blocker_does_not_see_the_blocked_members_comments(): void {
		$this->assertSame(
			0,
			$this->visible_count( $this->blocker, $this->blocked ),
			'A blocked member\'s comments stayed visible to the member who blocked them.'
		);
	}

	/**
	 * ...and the block cuts both ways, matching the feed and member directory.
	 *
	 * @return void
	 */
	public function test_the_blocked_member_does_not_see_the_blockers_comments(): void {
		$this->assertSame(
			0,
			$this->visible_count( $this->blocked, $this->blocker ),
			'A block hid comments in only one direction.'
		);
	}

	/**
	 * A member always sees their own comments. Hiding someone's own words from
	 * them would make the thread read as if their comment had been deleted.
	 *
	 * @return void
	 */
	public function test_each_member_still_sees_their_own_comments(): void {
		$this->assertGreaterThan( 0, $this->visible_count( $this->blocked, $this->blocked ) );
		$this->assertGreaterThan( 0, $this->visible_count( $this->blocker, $this->blocker ) );
	}

	/**
	 * A block is between two people. It must not remove either of them from
	 * everyone else's view of the thread.
	 *
	 * @return void
	 */
	public function test_a_bystander_sees_both_parties(): void {
		$this->assertGreaterThan(
			0,
			$this->visible_count( $this->bystander, $this->blocked ),
			'A block between two other members hid a comment from an uninvolved reader.'
		);
		$this->assertGreaterThan(
			0,
			$this->visible_count( $this->bystander, $this->blocker )
		);
	}

	/**
	 * The write side is deliberately unchanged: a blocked member may still
	 * comment on a THIRD party's post. Blocking is not a site-wide ban, and
	 * treating it as one would let anyone silence anyone else anywhere simply by
	 * blocking them. The case that does matter - engaging with the blocker's own
	 * content - is refused by InteractionGuard and is covered by its own tests.
	 *
	 * @return void
	 */
	public function test_a_blocked_member_may_still_comment_on_an_unrelated_thread(): void {
		wp_set_current_user( $this->blocked );

		$result = buddynext_service( 'comments' )->create(
			$this->blocked,
			'post',
			$this->post_id,
			'Still allowed to speak in a thread the blocker does not own'
		);

		$this->assertNotWPError(
			$result,
			'A block silenced a member on a post owned by someone else entirely.'
		);
	}
}
