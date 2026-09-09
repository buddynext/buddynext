<?php
/**
 * An engagement target (reaction / comment) resolves to its ROOT post so the
 * visibility gate can run against it — including for a nested reply.
 *
 * The REST engagement guard (BaseRestController::engagement_target_error ->
 * is_post_hidden_from_viewer -> PostService::resolve_post_id -> visibility_error)
 * hides a target on a post the viewer cannot see. resolve_post_id() mapped a
 * TOP-LEVEL comment to its post but stopped there, so a reply-to-a-reply (whose
 * own target is ('comment', parent_id)) resolved to 0 and skipped the gate — a
 * reaction could be placed on a nested reply under a post in a space the actor
 * cannot see (card 10264292715). resolve_post_id() now walks the reply chain to
 * the root post.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::resolve_post_id
 */
class EngagementTargetVisibilityTest extends \WP_UnitTestCase {

	/** @var PostService */
	private $posts;

	/** @var CommentService */
	private $comments;

	/** @var int Post author. */
	private $author = 0;

	/** @var int A member who is not the author. */
	private $outsider = 0;

	/** @var int A private post (author-only). */
	private $post_id = 0;

	/** @var int A top-level comment on the post. */
	private $top_comment = 0;

	/** @var int A reply to the top-level comment (nested). */
	private $reply = 0;

	/**
	 * Seed a private post with a top-level comment and a nested reply.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->posts    = new PostService();
		$this->comments = new CommentService();
		$this->author   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->outsider = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->post_id = (int) $this->posts->create(
			$this->author,
			array( 'content' => 'private post', 'privacy' => 'private', 'type' => 'text' )
		);

		$top               = $this->comments->create( $this->author, 'post', $this->post_id, 'top' );
		$this->top_comment = (int) ( is_array( $top ) ? ( $top['id'] ?? 0 ) : $top );

		$reply       = $this->comments->create( $this->author, 'comment', $this->top_comment, 'reply' );
		$this->reply = (int) ( is_array( $reply ) ? ( $reply['id'] ?? 0 ) : $reply );

		$this->assertGreaterThan( 0, $this->top_comment, 'Top-level comment not created.' );
		$this->assertGreaterThan( 0, $this->reply, 'Nested reply not created.' );
	}

	/**
	 * A post target resolves to itself.
	 *
	 * @return void
	 */
	public function test_post_resolves_to_itself(): void {
		$this->assertSame( $this->post_id, $this->posts->resolve_post_id( 'post', $this->post_id ) );
	}

	/**
	 * A top-level comment resolves to its post (unchanged behaviour).
	 *
	 * @return void
	 */
	public function test_top_level_comment_resolves_to_root_post(): void {
		$this->assertSame( $this->post_id, $this->posts->resolve_post_id( 'comment', $this->top_comment ) );
	}

	/**
	 * A NESTED reply resolves to the root post — the fix. Previously 0, which made
	 * the visibility gate a no-op for reactions on replies.
	 *
	 * @return void
	 */
	public function test_nested_reply_resolves_to_root_post(): void {
		$this->assertSame( $this->post_id, $this->posts->resolve_post_id( 'comment', $this->reply ) );
	}

	/**
	 * With the reply resolving to the root post, the shared visibility gate refuses
	 * an outsider on BOTH the top-level comment and the nested reply — closing the
	 * private-space leak for engagement on replies.
	 *
	 * @return void
	 */
	public function test_visibility_gate_covers_reply_targets(): void {
		foreach ( array( $this->top_comment, $this->reply ) as $comment_id ) {
			$root = $this->posts->resolve_post_id( 'comment', $comment_id );
			$this->assertSame( $this->post_id, $root );
			$this->assertInstanceOf(
				\WP_Error::class,
				$this->posts->visibility_error( $root, $this->outsider ),
				'An outsider must be refused the private root post behind the engagement target.'
			);
		}
	}
}
