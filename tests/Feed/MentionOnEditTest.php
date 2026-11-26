<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Editing a post or comment to add an @mention notifies the new member.
 *
 * Before 1.3.0 only create() parsed mentions, so a member tagged in an edit was
 * linked but never told. Now PostService::notify_mentions() is the one seam for
 * create and edit, posts and comments; an edit tells only members it newly adds.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Mentions on edit.
 *
 * @covers \BuddyNext\Feed\PostService::notify_mentions
 * @covers \BuddyNext\Feed\PostService::update
 * @covers \BuddyNext\Comments\CommentService::update
 */
class MentionOnEditTest extends WP_UnitTestCase {

	/**
	 * Author of the content.
	 *
	 * @var int
	 */
	private int $author = 0;

	/**
	 * Member mentioned from the start.
	 *
	 * @var int
	 */
	private int $early = 0;

	/**
	 * Member added by the edit.
	 *
	 * @var int
	 */
	private int $added = 0;

	/**
	 * Recorded buddynext_user_mentioned calls: [ mentioned, mentioner, context ].
	 *
	 * @var array<int, array<int, int>>
	 */
	private array $fired = array();

	/**
	 * Three members with known handles, and a recorder on the mention action.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->author = self::factory()->user->create( array( 'user_login' => 'mention_author' ) );
		$this->early  = self::factory()->user->create( array( 'user_login' => 'mention_early' ) );
		$this->added  = self::factory()->user->create( array( 'user_login' => 'mention_added' ) );

		$this->fired = array();
		add_action(
			'buddynext_user_mentioned',
			function ( int $mentioned, int $mentioner, int $context ): void {
				$this->fired[] = array( $mentioned, $mentioner, $context );
			},
			5,
			3
		);
	}

	/**
	 * User ids that were told, in order.
	 *
	 * @return int[]
	 */
	private function told(): array {
		return array_map( static fn( array $call ): int => $call[0], $this->fired );
	}

	/**
	 * Post edit adding a mention tells only the newly mentioned member.
	 * Fails on 1.2.9: update() never parsed mentions.
	 *
	 * @return void
	 */
	public function test_post_edit_notifies_only_the_newly_mentioned_member(): void {
		$posts   = new PostService();
		$post_id = $posts->create( $this->author, array( 'content' => 'Hello @mention_early' ) );
		$this->assertIsInt( $post_id );
		$this->assertSame( array( $this->early ), $this->told(), 'Create notifies the first mention.' );

		$this->fired = array();
		$this->assertTrue( $posts->update( $post_id, $this->author, array( 'content' => 'Hello @mention_early and @mention_added' ) ) );

		$this->assertSame( array( array( $this->added, $this->author, $post_id ) ), $this->fired );
	}

	/**
	 * Re-saving with the same mentions tells nobody again.
	 *
	 * @return void
	 */
	public function test_post_edit_without_new_mentions_is_silent(): void {
		$posts   = new PostService();
		$post_id = $posts->create( $this->author, array( 'content' => 'Ping @mention_early' ) );

		$this->fired = array();
		$posts->update( $post_id, $this->author, array( 'content' => 'Ping @mention_early, typo fixed' ) );

		$this->assertSame( array(), $this->fired );
	}

	/**
	 * The member's mention audience still decides: "nobody" is not told.
	 *
	 * @return void
	 */
	public function test_post_edit_respects_the_mention_privacy_setting(): void {
		update_user_meta( $this->added, 'bn_privacy_mention', 'nobody' );
		$posts   = new PostService();
		$post_id = $posts->create( $this->author, array( 'content' => 'Plain text' ) );

		$this->fired = array();
		$posts->update( $post_id, $this->author, array( 'content' => 'Plain text @mention_added' ) );

		$this->assertSame( array(), $this->fired );
	}

	/**
	 * Comment edit adding a mention tells that member, deep-linked to the post.
	 * Fails on 1.2.9: CommentService::update() never parsed mentions.
	 *
	 * @return void
	 */
	public function test_comment_edit_notifies_the_newly_mentioned_member(): void {
		$post_id    = ( new PostService() )->create( $this->author, array( 'content' => 'A post to reply to' ) );
		$comments   = new CommentService();
		$comment_id = $comments->create( $this->author, 'post', $post_id, 'First take @mention_early' );
		$this->assertIsInt( $comment_id );
		$this->assertSame( array( $this->early ), $this->told(), 'Create notifies the first mention.' );

		$this->fired = array();
		$this->assertTrue( $comments->update( $comment_id, $this->author, 'First take @mention_early, also @mention_added' ) );

		$this->assertSame( array( array( $this->added, $this->author, $post_id ) ), $this->fired );
	}
}
