<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
/**
 * Reported comments are auto-hidden ("Under review") like reported posts
 * (card 10312729096).
 *
 * A comment that accrues the report threshold flips is_hidden (its reversible
 * mirror of a post's status='under_review'): it disappears for other members and
 * drops out of the post's comment_count, but its author and moderators still see
 * it (labelled), and clearing the reports brings it back. Distinct from is_deleted
 * (the moderator takedown), which is left alone throughout.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Comments\CommentService;
use BuddyNext\Core\CounterService;
use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::auto_hide_comment
 */
class CommentAutoHideTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private CommentService $comments;
	private int $admin_id;
	private int $author_id;
	private int $post_id;
	private int $comment_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->service  = new ModerationService();
		$this->comments = new CommentService();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->author_id = self::factory()->user->create();
		$this->post_id   = (int) ( new PostService() )->create(
			self::factory()->user->create(),
			array( 'content' => 'a post', 'type' => 'text' )
		);
		$this->comment_id = (int) $this->comments->create( $this->author_id, 'post', $this->post_id, 'a comment' );

		// Two distinct reports meet the threshold; the account-age gate is off so
		// fresh factory reporters count (its own test flips it back on).
		update_option( 'buddynext_auto_hide_threshold', 2 );
		add_filter( 'buddynext_auto_hide_min_account_age_days', '__return_zero' );
	}

	public function tear_down(): void {
		remove_filter( 'buddynext_auto_hide_min_account_age_days', '__return_zero' );
		delete_option( 'buddynext_auto_hide_threshold' );
		parent::tear_down();
	}

	private function is_hidden( int $comment_id ): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT is_hidden FROM {$wpdb->prefix}bn_comments WHERE id = %d", $comment_id )
		);
	}

	private function report_from_new_users( int $comment_id, int $count ): int {
		$last = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$last = (int) $this->service->report( self::factory()->user->create(), 'comment', $comment_id, 'spam' );
		}
		return $last;
	}

	/** Reaching the threshold hides the comment; one report below it does not. */
	public function test_threshold_hides_a_comment_and_below_it_does_not(): void {
		$this->service->report( self::factory()->user->create(), 'comment', $this->comment_id, 'spam' );
		$this->assertFalse( $this->is_hidden( $this->comment_id ), 'One report is below the threshold of 2.' );

		$this->service->report( self::factory()->user->create(), 'comment', $this->comment_id, 'spam' );
		$this->assertTrue( $this->is_hidden( $this->comment_id ), 'The second report reaches the threshold and hides it.' );
	}

	/** The reporter account-age filter applies: too-new reporters do not count. */
	public function test_account_age_filter_applies(): void {
		remove_filter( 'buddynext_auto_hide_min_account_age_days', '__return_zero' );
		add_filter( 'buddynext_auto_hide_min_account_age_days', static fn(): int => 7 );

		// Fresh factory users registered "now" are younger than the 7-day cutoff.
		$this->report_from_new_users( $this->comment_id, 3 );
		$this->assertFalse( $this->is_hidden( $this->comment_id ), 'Brand-new reporters must not trip auto-hide.' );

		remove_filter( 'buddynext_auto_hide_min_account_age_days', static fn(): int => 7 );
	}

	/** Hidden for another member, but shown (flagged) to the author and to an admin/moderator. */
	public function test_hidden_for_others_but_visible_to_author_and_moderator(): void {
		$this->report_from_new_users( $this->comment_id, 2 );
		$this->assertTrue( $this->is_hidden( $this->comment_id ) );

		$other = self::factory()->user->create();

		$other_ids = wp_list_pluck( $this->comments->list( 'post', $this->post_id, array( 'viewer_id' => $other ) )['items'], 'id' );
		$this->assertNotContains( $this->comment_id, $other_ids, 'A plain member must not see a hidden comment.' );

		$author_items = $this->comments->list( 'post', $this->post_id, array( 'viewer_id' => $this->author_id ) )['items'];
		$author_row   = current( array_filter( $author_items, fn( $c ) => (int) $c['id'] === $this->comment_id ) );
		$this->assertNotFalse( $author_row, 'The author must still see their own hidden comment.' );
		$this->assertTrue( (bool) $author_row['is_hidden'], 'The author sees it flagged as under review.' );

		$admin_ids = wp_list_pluck( $this->comments->list( 'post', $this->post_id, array( 'viewer_id' => $this->admin_id ) )['items'], 'id' );
		$this->assertContains( $this->comment_id, $admin_ids, 'A moderator/admin must still see a hidden comment.' );
	}

	/** The post comment_count drops on hide and comes back on restore. */
	public function test_comment_count_drops_on_hide_and_returns_on_restore(): void {
		global $wpdb;
		( new CounterService() )->recount_post_comments( $this->post_id );
		$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) );
		$this->assertSame( 1, $before );

		$report_id = $this->report_from_new_users( $this->comment_id, 2 );
		$after     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) );
		$this->assertSame( 0, $after, 'Hiding the only comment drops the post count to zero.' );

		$this->service->dismiss( $report_id, $this->admin_id );
		$this->assertFalse( $this->is_hidden( $this->comment_id ), 'Dismissing the reports unhides the comment.' );
		$restored = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) );
		$this->assertSame( 1, $restored, 'Restoring the comment brings the count back.' );
	}

	/** The hot-path recount excludes hidden (and deleted) comments. */
	public function test_recount_excludes_hidden_comments(): void {
		$this->report_from_new_users( $this->comment_id, 2 );
		( new CounterService() )->recount_post_comments( $this->post_id );

		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT comment_count FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) );
		$this->assertSame( 0, $count, 'The recount must not count a hidden comment.' );
	}

	/** Auto-hide never touches a takedown: a deleted comment is left is_deleted, not re-hidden. */
	public function test_auto_hide_leaves_a_deleted_comment_alone(): void {
		$this->comments->delete( $this->comment_id, $this->author_id );
		$this->report_from_new_users( $this->comment_id, 3 );

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT is_deleted, is_hidden FROM {$wpdb->prefix}bn_comments WHERE id = %d", $this->comment_id ), ARRAY_A );
		$this->assertSame( '1', (string) $row['is_deleted'], 'The takedown stays.' );
		$this->assertSame( '0', (string) $row['is_hidden'], 'Auto-hide must not flip a deleted comment.' );
	}
}
