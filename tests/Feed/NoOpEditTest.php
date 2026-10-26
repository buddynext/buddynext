<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
/**
 * "(edited)" means the words changed (card 10435749064). Saving a post or a
 * comment without changing it must not add the marker; changing the text must.
 * Changing only who can see a post is not an edit to what it says, so it moves
 * updated_at but leaves the marker alone, as Facebook does.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::update
 * @covers \BuddyNext\Comments\CommentService::update
 */
class NoOpEditTest extends \WP_UnitTestCase {

	private PostService $posts;
	private int $author;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts   = new PostService();
		$this->author  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->author );
		$this->post_id = (int) $this->posts->create(
			$this->author,
			array(
				'content' => 'Original words',
				'type'    => 'text',
				'privacy' => 'public',
			)
		);
	}

	private function edited_at(): ?string {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT edited_at FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) );
	}

	public function test_saving_a_post_unchanged_adds_no_edited_marker(): void {
		$this->assertTrue( $this->posts->update( $this->post_id, $this->author, array( 'content' => 'Original words', 'privacy' => 'public' ) ) );
		$this->assertNull( $this->edited_at() );
	}

	public function test_changing_the_text_marks_the_post_edited(): void {
		$this->assertTrue( $this->posts->update( $this->post_id, $this->author, array( 'content' => 'New words' ) ) );
		$this->assertNotNull( $this->edited_at() );
	}

	public function test_changing_only_the_audience_is_not_an_edit(): void {
		$this->assertTrue( $this->posts->update( $this->post_id, $this->author, array( 'content' => 'Original words', 'privacy' => 'followers' ) ) );
		$this->assertSame( 'followers', $this->posts->get( $this->post_id )['privacy'] );
		$this->assertNull( $this->edited_at() );
	}

	public function test_saving_a_comment_unchanged_adds_no_edited_marker(): void {
		$comments = new CommentService();
		$id       = (int) $comments->create( $this->author, 'post', $this->post_id, 'A comment' );

		$this->assertTrue( $comments->update( $id, $this->author, 'A comment' ) );
		$this->assertEmpty( $comments->get( $id )['is_edited'] );

		$this->assertTrue( $comments->update( $id, $this->author, 'A changed comment' ) );
		$this->assertNotEmpty( $comments->get( $id )['is_edited'] );
	}
}
