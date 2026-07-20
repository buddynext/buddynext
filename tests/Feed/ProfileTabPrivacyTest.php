<?php
/**
 * The profile Replies and Likes tabs must not publish content the viewer cannot see.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * Both tabs render the PARENT post's content as context — the Replies card shows
 * the post you replied to, the Likes card shows the post you liked. Neither query
 * filtered on the parent's privacy, so replying to (or liking) a private post
 * published its opening words to anyone who opened your profile, including a
 * logged-out visitor.
 *
 * Verified against a real logged-out read before the filter existed: a private
 * post's content came back in full.
 *
 * @covers \BuddyNext\Feed\PostService
 */
class ProfileTabPrivacyTest extends \WP_UnitTestCase {

	/**
	 * Author of the private post.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * The member whose profile tab is being read.
	 *
	 * @var int
	 */
	private int $replier;

	/**
	 * The private post.
	 *
	 * @var int
	 */
	private int $private_post;

	/**
	 * The public post.
	 *
	 * @var int
	 */
	private int $public_post;

	/**
	 * A private post and a public one, each with a reply and a like from the
	 * same member.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->author  = self::factory()->user->create();
		$this->replier = self::factory()->user->create();

		$posts = new PostService();

		$this->private_post = (int) $posts->create(
			$this->author,
			array(
				'content' => 'SECRET private plans',
				'type'    => 'text',
				'privacy' => 'private',
			)
		);
		$this->public_post  = (int) $posts->create(
			$this->author,
			array(
				'content' => 'PUBLIC everyone may read this',
				'type'    => 'text',
				'privacy' => 'public',
			)
		);

		$comments = new CommentService();
		$comments->create( $this->replier, 'post', $this->private_post, 'reply on private' );
		$comments->create( $this->replier, 'post', $this->public_post, 'reply on public' );

		$reactions = buddynext_service( 'reactions' );
		$reactions->react( $this->replier, 'post', $this->private_post, 'like' );
		$reactions->react( $this->replier, 'post', $this->public_post, 'like' );
	}

	/**
	 * A logged-out visitor must not read a private post through someone's Replies tab.
	 *
	 * @return void
	 */
	public function test_replies_tab_hides_a_private_parent_from_a_logged_out_viewer(): void {
		$rows = ( new PostService() )->user_replies( $this->replier, 20, 0 );

		$contexts = wp_list_pluck( $rows, 'post_content' );

		$this->assertNotContains(
			'SECRET private plans',
			$contexts,
			'A private post\'s content must not reach a logged-out viewer through a Replies tab.'
		);
		$this->assertContains( 'PUBLIC everyone may read this', $contexts, 'Public replies must still show — the filter must not empty the tab.' );
	}

	/**
	 * Nor a stranger who happens to be logged in.
	 *
	 * @return void
	 */
	public function test_replies_tab_hides_a_private_parent_from_an_unrelated_member(): void {
		$stranger = self::factory()->user->create();

		$contexts = wp_list_pluck( ( new PostService() )->user_replies( $this->replier, 20, $stranger ), 'post_content' );

		$this->assertNotContains( 'SECRET private plans', $contexts );
	}

	/**
	 * The post's own author still sees their post as context.
	 *
	 * Proves the filter is viewer-aware rather than a blanket "hide everything
	 * private", which would be the lazy fix.
	 *
	 * @return void
	 */
	public function test_replies_tab_shows_the_parent_to_its_own_author(): void {
		$contexts = wp_list_pluck( ( new PostService() )->user_replies( $this->replier, 20, $this->author ), 'post_content' );

		$this->assertContains(
			'SECRET private plans',
			$contexts,
			'The author of the parent post may see their own content as context.'
		);
	}

	/**
	 * The Likes tab has the same hole and the same fix — it renders the LIKED
	 * post's own content, so liking a private post published its text.
	 *
	 * @return void
	 */
	public function test_likes_tab_hides_a_private_post_from_a_logged_out_viewer(): void {
		$rows = ( new PostService() )->user_liked_posts( $this->replier, 20, 0 );

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'id' ) );

		$this->assertNotContains(
			$this->private_post,
			$ids,
			'A private post must not appear in someone else\'s Likes tab.'
		);
		$this->assertContains( $this->public_post, $ids, 'Public likes must still show.' );
	}

	/**
	 * The liked post's author still sees it.
	 *
	 * @return void
	 */
	public function test_likes_tab_shows_the_post_to_its_own_author(): void {
		$ids = array_map( 'intval', wp_list_pluck( ( new PostService() )->user_liked_posts( $this->replier, 20, $this->author ), 'id' ) );

		$this->assertContains( $this->private_post, $ids );
	}
}
