<?php
/**
 * A post nobody has read yet stays editable.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * The edit window exists so nobody rewrites history other members have already
 * read. PostService::update() exempted a not-yet-published post from it - but the
 * variable was named $is_pending and tested only 'scheduled', so the one state the
 * name described was the one it missed.
 *
 * The cost landed on pre-moderation. A member's held post sits in the queue until
 * a moderator reaches it; on a site where that happens once a day, the window has
 * closed long before anyone looks, and deleting the post is the author's only way
 * to change it. Nobody has read it in the meantime, which is the entire basis of
 * the exemption.
 *
 * @covers \BuddyNext\Feed\PostService::is_pre_publication
 * @covers \BuddyNext\Feed\PostService::update
 */
class EditWindowExemptsUnpublishedTest extends \WP_UnitTestCase {

	private PostService $posts;
	private int $author;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts  = new PostService();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'buddynext_post_edit_window', 1 );
	}

	/**
	 * A post of the given status, created long enough ago to be outside the window.
	 *
	 * @param string $status Post status.
	 * @return int
	 */
	private function seed_stale_post( string $status ): int {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id'    => $this->author,
				'type'       => 'text',
				'content'    => 'Original wording',
				'status'     => $status,
				'privacy'    => 'public',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	public function test_a_held_post_can_still_be_edited_after_the_window(): void {
		$post_id = $this->seed_stale_post( 'pending' );

		$result = $this->posts->update( $post_id, $this->author, array( 'content' => 'Fixed wording' ) );

		$this->assertNotWPError( $result, 'A post nobody has been allowed to read must stay editable.' );
	}

	public function test_a_scheduled_post_is_still_exempt(): void {
		$post_id = $this->seed_stale_post( 'scheduled' );

		$this->assertNotWPError( $this->posts->update( $post_id, $this->author, array( 'content' => 'Fixed wording' ) ) );
	}

	public function test_a_published_post_still_closes(): void {
		$post_id = $this->seed_stale_post( 'published' );

		$result = $this->posts->update( $post_id, $this->author, array( 'content' => 'Rewriting history' ) );

		$this->assertWPError( $result, 'The window must still close on a post members have read.' );
		$this->assertSame( 'edit_window_closed', $result->get_error_code() );
	}

	public function test_a_reported_post_is_not_exempt(): void {
		// under_review is hidden, but it was PUBLISHED before it was hidden - members
		// have read it. Exempting it would let an author quietly rewrite the very
		// content that was reported, which is the opposite of what moderation needs.
		$post_id = $this->seed_stale_post( 'under_review' );

		$this->assertWPError(
			$this->posts->update( $post_id, $this->author, array( 'content' => 'Rewriting the reported text' ) ),
			'A reported post was visible before it was hidden, so the window still applies.'
		);
	}

	public function test_the_two_halves_cannot_disagree(): void {
		// The server and the post-card template both ask this one method. They used
		// to restate the rule separately and drifted, which is the bug.
		$this->assertTrue( PostService::is_pre_publication( 'pending' ) );
		$this->assertTrue( PostService::is_pre_publication( 'scheduled' ) );
		$this->assertTrue( PostService::is_pre_publication( 'draft' ) );
		$this->assertFalse( PostService::is_pre_publication( 'published' ) );
		$this->assertFalse( PostService::is_pre_publication( 'under_review' ) );
		$this->assertFalse( PostService::is_pre_publication( 'nonsense' ) );
	}

	/**
	 * The app's can_edit follows the same window as the save check, so the app
	 * never offers an Edit that the server refuses.
	 *
	 * @return void
	 */
	public function test_rest_can_edit_follows_the_window(): void {
		$stale = $this->seed_stale_post( 'published' );
		$fresh = (int) $this->posts->create( $this->author, array( 'content' => 'Just posted' ) );
		$this->assertGreaterThan( 0, $fresh );

		$can_edit = static function ( int $post_id, int $viewer ): bool {
			$item = ( new \BuddyNext\Feed\FeedController() )->enrich_for_rest( array( ( new PostService() )->get( $post_id ) ), $viewer );
			return (bool) $item[0]['viewer_state']['can_edit'];
		};

		$this->assertTrue( $can_edit( $fresh, $this->author ), 'Inside the window.' );
		$this->assertFalse( $can_edit( $stale, $this->author ), 'Window closed: no Edit offered.' );
		$this->assertTrue( $can_edit( $stale, self::factory()->user->create( array( 'role' => 'administrator' ) ) ), 'Admins are not bound by the window.' );
		$this->assertFalse( $can_edit( $fresh, self::factory()->user->create() ), 'Never for someone else\'s post.' );

		update_option( 'buddynext_post_edit_window', 0 );
		$this->assertTrue( $can_edit( $stale, $this->author ), '0 means no window.' );
	}

	/**
	 * viewer_state.has_reported is true only for the member who reported the post.
	 *
	 * @return void
	 */
	public function test_viewer_state_carries_has_reported(): void {
		$post     = (int) $this->posts->create( $this->author, array( 'content' => 'Report me' ) );
		$reporter = self::factory()->user->create();
		( new \BuddyNext\Moderation\ModerationService() )->report( $reporter, 'post', $post, 'spam' );
		$state = static fn( int $viewer ): bool => (bool) ( new \BuddyNext\Feed\FeedController() )->enrich_for_rest( array( ( new PostService() )->get( $post ) ), $viewer )[0]['viewer_state']['has_reported'];

		$this->assertTrue( $state( $reporter ) );
		$this->assertFalse( $state( self::factory()->user->create() ), 'Someone else has not reported it.' );
	}
}
