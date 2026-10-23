<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
/**
 * A moderator removing a reported comment takes the same path as any other
 * delete (card 10427170873): the text is blanked, a pin on it is cleared, the
 * thread list and the post's cached count refresh, and clearing the reports
 * afterwards can not bring it back.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Comments\CommentService::soft_delete
 * @covers \BuddyNext\Moderation\ModerationListener::on_content_removed
 */
class CommentRemovalTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private CommentService $comments;
	private PostService $posts;
	private int $admin_id;
	private int $post_id;
	private int $keep_id;
	private int $remove_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service  = new ModerationService();
		$this->comments = new CommentService();
		$this->posts    = new PostService();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$author          = self::factory()->user->create();
		$this->post_id   = (int) $this->posts->create( self::factory()->user->create(), array( 'content' => 'a post', 'type' => 'text' ) );
		$this->keep_id   = (int) $this->comments->create( $author, 'post', $this->post_id, 'stays' );
		$this->remove_id = (int) $this->comments->create( $author, 'post', $this->post_id, 'reported text' );
	}

	private function remove_reported(): int {
		$report = (int) $this->service->report( self::factory()->user->create(), 'comment', $this->remove_id, 'spam' );
		$this->assertTrue( $this->service->remove_content( $report, $this->admin_id ) );
		return $report;
	}

	public function test_removal_blanks_unpins_and_refreshes_counts(): void {
		$this->assertTrue( $this->comments->pin( $this->remove_id, $this->admin_id ) );
		$this->assertSame( 2, (int) $this->posts->get( $this->post_id )['comment_count'] ); // Warms the post cache.
		$this->comments->list_for_object( 'post', $this->post_id ); // Warms the thread cache.

		$this->remove_reported();

		$row = $this->comments->get( $this->remove_id );
		$this->assertSame( 1, (int) $row['is_deleted'] );
		$this->assertSame( '', $row['content'], 'The removed text must not stay in the table.' );
		$this->assertFalse( get_option( 'bn_pinned_comment_post_' . $this->post_id ), 'A removed comment must not stay pinned.' );
		$this->assertSame( 1, (int) $this->posts->get( $this->post_id )['comment_count'], 'The cached post must show the new count.' );

		$listed = wp_list_pluck( $this->comments->list_for_object( 'post', $this->post_id )['items'] ?? array(), 'content' );
		$this->assertNotContains( 'reported text', $listed, 'The thread cache must not serve the removed text.' );
	}

	public function test_clearing_reports_after_removal_of_a_hidden_comment_does_not_restore(): void {
		$restored = 0;
		$listener = static function () use ( &$restored ): void {
			++$restored;
		};
		add_action( 'buddynext_comment_restored', $listener );

		// Auto-hidden first (as a report threshold would), then taken down.
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'bn_comments', array( 'is_hidden' => 1 ), array( 'id' => $this->remove_id ) );
		$report = $this->remove_reported();
		$this->service->dismiss( $report, $this->admin_id );

		remove_action( 'buddynext_comment_restored', $listener );
		$this->assertSame( 0, $restored, 'A removed comment must not be announced as restored.' );
		$this->assertSame( 1, (int) $this->comments->get( $this->remove_id )['is_deleted'] );
	}
}
