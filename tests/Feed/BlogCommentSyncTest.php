<?php
/**
 * Tests for the comment pair between an article and its feed card.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Comments\CommentService;
use BuddyNext\Feed\BlogPostListener;

/**
 * A comment and its copy on the other side stay one comment.
 *
 * @covers \BuddyNext\Feed\BlogCommentSync
 */
class BlogCommentSyncTest extends \WP_UnitTestCase {

	/**
	 * Article post id.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * The article's feed card id.
	 *
	 * @var int
	 */
	private int $card_id;

	/**
	 * Commenting member.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Publish an article with a card.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		$author        = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->member  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'     => 'Sunday loop',
				'post_content'   => 'Body.',
				'post_status'    => 'publish',
				'post_author'    => $author,
				'comment_status' => 'open',
			)
		);
		$this->card_id = BlogPostListener::card_id_for_post( $this->post_id );
		$this->assertGreaterThan( 0, $this->card_id );
	}

	/**
	 * Card comments as stored.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function card_rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}bn_comments WHERE object_type = 'post' AND object_id = %d ORDER BY id", $this->card_id ), ARRAY_A );
	}

	/**
	 * A comment on the article, by the member.
	 *
	 * @param string $text     Comment text.
	 * @param int    $approved Approval state.
	 * @param int    $parent   Parent WP comment id.
	 * @return int
	 */
	private function wp_comment( string $text, int $approved = 1, int $parent = 0 ): int {
		return (int) wp_insert_comment(
			array(
				'comment_post_ID'      => $this->post_id,
				'comment_parent'       => $parent,
				'user_id'              => $this->member,
				'comment_author'       => 'Member',
				'comment_author_email' => 'member@example.test',
				'comment_content'      => $text,
				'comment_approved'     => $approved,
			)
		);
	}

	/**
	 * The card copy of a WP comment.
	 *
	 * @param int $wp_id WP comment id.
	 * @return array<string,string>|null
	 */
	private function copy_of( int $wp_id ): ?array {
		foreach ( $this->card_rows() as $row ) {
			if ( (int) $row['sync_reply_id'] === $wp_id ) {
				return $row;
			}
		}
		return null;
	}

	/**
	 * Spam, trash and a permanent delete on the article take the card copy down;
	 * approving again brings it back.
	 *
	 * @return void
	 */
	public function test_wp_moderation_reaches_the_card(): void {
		$spam  = $this->wp_comment( 'Buy cheap watches' );
		$trash = $this->wp_comment( 'Trash me' );
		$gone  = $this->wp_comment( 'Delete me' );
		$this->assertCount( 3, $this->card_rows() );

		wp_spam_comment( $spam );
		wp_trash_comment( $trash );
		wp_delete_comment( $gone, true );

		$this->assertSame( '1', $this->copy_of( $spam )['is_hidden'], 'spam hides the card copy' );
		$this->assertSame( '1', $this->copy_of( $trash )['is_hidden'], 'trash hides the card copy' );
		$this->assertSame( '1', $this->copy_of( $gone )['is_deleted'], 'a permanent delete removes the card copy' );
		$this->assertSame( '', $this->copy_of( $gone )['content'], 'and its text' );

		$listed = ( new CommentService() )->list( 'post', $this->card_id, array( 'viewer_id' => 0 ) );
		$texts  = wp_list_pluck( (array) $listed['items'], 'content' );
		$this->assertNotContains( 'Buy cheap watches', $texts, 'the card list no longer shows the spam' );

		wp_unspam_comment( $spam );
		wp_set_comment_status( $spam, 'approve' );
		$this->assertSame( '0', $this->copy_of( $spam )['is_hidden'], 'approving again shows the copy' );
	}

	/**
	 * A comment held for moderation reaches the card when it is approved, once.
	 *
	 * @return void
	 */
	public function test_held_comment_reaches_the_card_on_approval(): void {
		$held = $this->wp_comment( 'Held then approved', 0 );
		$this->assertNull( $this->copy_of( $held ), 'held comments stay off the card' );

		wp_set_comment_status( $held, 'approve' );
		$this->assertNotNull( $this->copy_of( $held ), 'approval mirrors it' );

		wp_set_comment_status( $held, 'hold' );
		wp_set_comment_status( $held, 'approve' );
		$this->assertCount( 1, $this->card_rows(), 'a second approval does not mirror it again' );
	}

	/**
	 * Edit and delete on the card reach the article copy.
	 *
	 * @return void
	 */
	public function test_card_edit_and_delete_reach_the_article(): void {
		$comments = new CommentService();
		$bn_id    = (int) $comments->create( $this->member, 'post', $this->card_id, 'Original wording' );
		$wp_id    = $comments->get_sync_reply_id( $bn_id );
		$this->assertGreaterThan( 0, $wp_id );

		$comments->update( $bn_id, $this->member, 'Edited wording' );
		$this->assertSame( 'Edited wording', get_comment( $wp_id )->comment_content, 'card edit rewrites the article copy' );

		$comments->delete( $bn_id, $this->member );
		$this->assertSame( 'trash', wp_get_comment_status( $wp_id ), 'card delete trashes the article copy' );
		$this->assertSame( '1', $this->copy_of( $wp_id )['is_deleted'], 'the card copy stays deleted, not hidden' );
	}

	/**
	 * An edit on the article rewrites the card copy.
	 *
	 * @return void
	 */
	public function test_article_edit_reaches_the_card(): void {
		$wp_id = $this->wp_comment( 'First text' );
		wp_update_comment(
			array(
				'comment_ID'      => $wp_id,
				'comment_content' => 'Second text',
			)
		);
		$this->assertSame( 'Second text', $this->copy_of( $wp_id )['content'] );
	}

	/**
	 * A backslash typed on the card survives on the article.
	 *
	 * @return void
	 */
	public function test_backslashes_survive_card_to_article(): void {
		$comments = new CommentService();
		$bn_id    = (int) $comments->create( $this->member, 'post', $this->card_id, 'Path C:\\temp\\new' );
		$this->assertSame( 'Path C:\\temp\\new', get_comment( $comments->get_sync_reply_id( $bn_id ) )->comment_content );
	}

	/**
	 * A reply stays a reply on the other side, both ways.
	 *
	 * @return void
	 */
	public function test_reply_threading_survives_both_ways(): void {
		$parent = $this->wp_comment( 'Parent on article' );
		$reply  = $this->wp_comment( 'Reply on article', 1, $parent );
		$this->assertSame( $this->copy_of( $parent )['id'], $this->copy_of( $reply )['parent_id'], 'article reply nests on the card' );

		$comments = new CommentService();
		$bn_reply = (int) $comments->create( $this->member, 'post', $this->card_id, 'Reply on card', (int) $this->copy_of( $parent )['id'] );
		$this->assertSame( $parent, (int) get_comment( $comments->get_sync_reply_id( $bn_reply ) )->comment_parent, 'card reply nests on the article' );
	}
}
