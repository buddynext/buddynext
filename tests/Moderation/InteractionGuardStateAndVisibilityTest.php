<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The shared engagement guard refuses a write against a soft-deleted target and
 * against a target the actor cannot see — at the SEAM, not only on the REST path.
 *
 * Card 10264292715 left two residuals after the enum/existence fix:
 *   (a) a soft-deleted (tombstoned) comment still accepted reactions — existence
 *       is row-existence only, and a deleted comment keeps its row (is_deleted=1);
 *   (b) the visibility gate lived only in the REST controllers, so a non-REST
 *       writer (CLI, a bridge, admin bulk) skipped it.
 * Both are now enforced inside InteractionGuard::check(), the one seam every
 * reaction and comment write funnels through. These exercise the SERVICE, not the
 * REST controller.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\InteractionGuard;
use BuddyNext\Reactions\ReactionService;

/**
 * @covers \BuddyNext\Moderation\InteractionGuard::check
 */
class InteractionGuardStateAndVisibilityTest extends \WP_UnitTestCase {

	/** @var PostService */
	private $posts;

	/** @var CommentService */
	private $comments;

	/** @var int Post author. */
	private $author = 0;

	/** @var int A member who is not the author. */
	private $member = 0;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->posts    = new PostService();
		$this->comments = new CommentService();
		$this->author   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Create a comment and return its id.
	 *
	 * @param int    $post_id Root post.
	 * @param string $body    Comment text.
	 * @return int
	 */
	private function comment_on( int $post_id, string $body ): int {
		$created = $this->comments->create( $this->author, 'post', $post_id, $body );
		return (int) ( is_array( $created ) ? ( $created['id'] ?? 0 ) : $created );
	}

	/**
	 * (a) A reaction targeting a soft-deleted comment on a visible post is refused
	 * via the shared guard with a clear error — not written as a phantom counter.
	 *
	 * @return void
	 */
	public function test_reaction_on_soft_deleted_comment_is_refused_at_the_seam(): void {
		$post_id    = (int) $this->posts->create( $this->author, array( 'content' => 'p', 'privacy' => 'public', 'type' => 'text' ) );
		$comment_id = $this->comment_on( $post_id, 'doomed' );
		$this->assertGreaterThan( 0, $comment_id, 'Comment not created.' );

		// Sanity: a reaction on the LIVE comment is allowed.
		$this->assertTrue( ( new ReactionService() )->react( $this->member, 'comment', $comment_id, 'like' ), 'A live comment should accept a reaction.' );

		// Author soft-deletes the comment (row kept, is_deleted = 1).
		$this->assertTrue( $this->comments->delete( $comment_id, $this->author ), 'Soft delete should succeed.' );

		$guard = InteractionGuard::check( $this->member, 'comment', $comment_id );
		$this->assertInstanceOf( \WP_Error::class, $guard, 'A tombstoned comment must be refused by the shared guard.' );
		$this->assertSame( 'object_deleted', $guard->get_error_code(), 'The refusal must name the state, not fall through as existence.' );
	}

	/**
	 * (b) A reaction targeting a comment on a post the actor cannot see is refused
	 * by the shared guard — the visibility gate now runs at the seam, so a non-REST
	 * writer no longer bypasses space-privacy.
	 *
	 * @return void
	 */
	public function test_reaction_on_comment_under_hidden_post_is_refused_at_the_seam(): void {
		$private_id = (int) $this->posts->create( $this->author, array( 'content' => 'secret', 'privacy' => 'private', 'type' => 'text' ) );
		$comment_id = $this->comment_on( $private_id, 'only author sees this' );
		$this->assertGreaterThan( 0, $comment_id, 'Comment not created.' );

		$guard = InteractionGuard::check( $this->member, 'comment', $comment_id );
		$this->assertInstanceOf( \WP_Error::class, $guard, 'An outsider must be refused engagement on a comment under a private post — at the seam.' );

		// The author (who can see the post) is still allowed.
		$this->assertTrue( InteractionGuard::check( $this->author, 'comment', $comment_id ), 'The author may engage with their own comment.' );
	}
}
