<?php
/**
 * Private-account and pending-follow visibility across the feeds.
 *
 * A follow request to a private account is not a follow until it is approved,
 * and a private account's posts stay off public-discovery surfaces for anyone
 * who is not an approved follower.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceService;

/**
 * Private-account and pending-follow visibility tests.
 *
 * @covers \BuddyNext\Feed\FeedService
 * @covers \BuddyNext\SocialGraph\FollowService
 */
class PrivateAccountVisibilityTest extends \WP_UnitTestCase {

	/**
	 * Follow service.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * Post service.
	 *
	 * @var PostService
	 */
	private PostService $posts;

	/**
	 * Feed service under test.
	 *
	 * @var FeedService
	 */
	private FeedService $feed;

	/**
	 * The private-account author.
	 *
	 * @var int
	 */
	private int $author;

	/**
	 * A member who does not follow the author.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * Test setup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->follows = new FollowService();
		$this->posts   = new PostService();
		$this->feed    = new FeedService( $this->follows, $this->posts );
		$this->author  = self::factory()->user->create();
		$this->viewer  = self::factory()->user->create();
	}

	/**
	 * Create a non-space post by the author.
	 *
	 * @param string $privacy  Post audience.
	 * @param int    $space_id Optional space.
	 * @return int
	 */
	private function author_post( string $privacy, int $space_id = 0 ): int {
		$data = array(
			'type'    => 'text',
			'content' => 'Post ' . wp_rand(),
			'privacy' => $privacy,
		);
		if ( $space_id > 0 ) {
			$data['space_id'] = $space_id;
		}
		$post_id = $this->posts->create( $this->author, $data );
		$this->assertIsInt( $post_id );

		return (int) $post_id;
	}

	/**
	 * Mark the author's account private.
	 *
	 * @return void
	 */
	private function make_author_private(): void {
		update_user_meta( $this->author, FollowService::PRIVATE_META, '1' );
	}

	/**
	 * Post ids of a feed page.
	 *
	 * @param array $page Feed result.
	 * @return int[]
	 */
	private function ids( array $page ): array {
		return array_map( 'intval', array_column( $page['items'], 'id' ) );
	}

	/**
	 * Explore as a given viewer (0 = logged out).
	 *
	 * @param int $viewer_id Viewer.
	 * @return int[]
	 */
	private function explore_as( int $viewer_id ): array {
		wp_set_current_user( $viewer_id );

		return $this->ids( $this->feed->explore_feed() );
	}

	/**
	 * A pending follow request must not open Followers-only posts.
	 *
	 * @return void
	 */
	public function test_pending_request_does_not_read_followers_only_posts(): void {
		$this->make_author_private();
		$post_id = $this->author_post( 'followers' );

		$this->assertTrue( $this->follows->follow( $this->viewer, $this->author ) );
		$this->assertTrue( $this->follows->has_pending_request( $this->viewer, $this->author ) );

		$this->assertNotContains( $post_id, $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'following' ) ) );
		$this->assertNotContains( $post_id, $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'for-you' ) ) );
	}

	/**
	 * Once approved, the follower reads Followers-only posts.
	 *
	 * @return void
	 */
	public function test_approved_follower_reads_followers_only_posts(): void {
		$this->make_author_private();
		$post_id = $this->author_post( 'followers' );

		$this->follows->follow( $this->viewer, $this->author );
		$this->assertTrue( $this->follows->approve_follow_request( $this->author, $this->viewer ) );
		$this->feed->flush_home_cache( $this->viewer );

		$this->assertContains( $post_id, $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'following' ) ) );
		$this->assertContains( $post_id, $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'for-you' ) ) );
	}

	/**
	 * Waiting requests become follows when the account goes public.
	 *
	 * @return void
	 */
	public function test_pending_requests_are_approved_when_account_goes_public(): void {
		$this->make_author_private();
		$second = self::factory()->user->create();
		$this->follows->follow( $this->viewer, $this->author );
		$this->follows->follow( $second, $this->author );

		$this->assertSame( 2, $this->follows->approve_pending_requests( $this->author ) );

		$this->assertTrue( $this->follows->is_following( $this->viewer, $this->author ) );
		$this->assertTrue( $this->follows->is_following( $second, $this->author ) );
		$this->assertSame( 0, $this->follows->pending_followers_count( $this->author ) );
	}

	/**
	 * A private account's public post stays off Explore for non-followers.
	 *
	 * @return void
	 */
	public function test_private_account_post_is_hidden_on_explore_from_non_followers(): void {
		$post_id = $this->author_post( 'public' );
		$this->assertContains( $post_id, $this->explore_as( $this->viewer ), 'A public account is on Explore.' );

		$this->make_author_private();

		$this->assertNotContains( $post_id, $this->explore_as( $this->viewer ), 'Non-follower.' );
		$this->assertNotContains( $post_id, $this->explore_as( 0 ), 'Logged-out visitor.' );

		$this->follows->follow( $this->viewer, $this->author );
		$this->assertNotContains( $post_id, $this->explore_as( $this->viewer ), 'Pending request.' );
	}

	/**
	 * The author, an approved follower and an admin still see it on Explore.
	 *
	 * @return void
	 */
	public function test_private_account_post_is_shown_on_explore_to_author_follower_and_admin(): void {
		$post_id = $this->author_post( 'public' );
		$this->follows->follow( $this->viewer, $this->author );
		$this->make_author_private();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->assertContains( $post_id, $this->explore_as( $this->author ), 'Author.' );
		$this->assertContains( $post_id, $this->explore_as( $this->viewer ), 'Approved follower.' );
		$this->assertContains( $post_id, $this->explore_as( $admin ), 'Admin.' );
	}

	/**
	 * For you does not surface a private account's public post to a non-follower.
	 *
	 * @return void
	 */
	public function test_private_account_post_is_hidden_in_for_you_from_non_followers(): void {
		$this->make_author_private();
		$post_id = $this->author_post( 'public' );

		$this->assertNotContains( $post_id, $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'for-you' ) ) );
	}

	/**
	 * In an open space the space decides: the post shows there, not on Explore.
	 *
	 * @return void
	 */
	public function test_private_account_open_space_post_shows_in_the_space_only(): void {
		$space_id = ( new SpaceService() )->create(
			$this->author,
			array(
				'name' => 'Open Space',
				'slug' => 'open-space-' . wp_rand( 1000, 9999 ),
				'type' => 'open',
			)
		);
		$this->assertIsInt( $space_id );
		$this->make_author_private();
		$post_id = $this->author_post( 'public', (int) $space_id );

		$this->assertContains( $post_id, $this->ids( $this->feed->space_feed( (int) $space_id, $this->viewer ) ) );
		$this->assertNotContains( $post_id, $this->explore_as( $this->viewer ) );
	}

	/**
	 * A hashtag page lists a private account's post for approved followers only.
	 *
	 * @return void
	 */
	public function test_private_account_post_is_hidden_on_hashtag_page_from_non_followers(): void {
		$post_id = $this->author_post( 'public' );
		$tags    = new \BuddyNext\Hashtags\HashtagService();
		$tags->sync( 'post', $post_id, array( 'privatetag' ) );
		$follower = self::factory()->user->create();
		$this->follows->follow( $follower, $this->author );
		$this->make_author_private();

		$this->assertNotContains( $post_id, $this->ids( $tags->get_feed( 'privatetag', array( 'viewer_id' => $this->viewer ) ) ), 'Non-follower.' );
		$this->assertNotContains( $post_id, $this->ids( $tags->get_feed( 'privatetag', array( 'viewer_id' => 0 ) ) ), 'Logged-out visitor.' );
		$this->assertContains( $post_id, $this->ids( $tags->get_feed( 'privatetag', array( 'viewer_id' => $follower ) ) ), 'Approved follower.' );
		$this->assertContains( $post_id, $this->ids( $tags->get_feed( 'privatetag', array( 'viewer_id' => $this->author ) ) ), 'Author.' );
	}

	/**
	 * A direct link to a private account's post is refused to non-followers.
	 *
	 * @return void
	 */
	public function test_private_account_post_is_refused_by_direct_link_to_non_followers(): void {
		$post_id  = $this->author_post( 'public' );
		$follower = self::factory()->user->create();
		$this->follows->follow( $follower, $this->author );
		$this->make_author_private();

		$this->assertWPError( $this->posts->visibility_error( $post_id, $this->viewer ), 'Non-follower.' );
		$this->assertWPError( $this->posts->visibility_error( $post_id, 0 ), 'Logged-out visitor.' );
		$this->assertNull( $this->posts->visibility_error( $post_id, $follower ), 'Approved follower.' );
		$this->assertNull( $this->posts->visibility_error( $post_id, $this->author ), 'Author.' );
	}

	/**
	 * A document shared on a post is opened by whoever may read the post.
	 *
	 * MediaVerse has no document level for "followers", so the file is stored
	 * private and MediaVerse asks BuddyNext. A private account's post is for its
	 * approved followers: they get the file, a pending request, a stranger and a
	 * reader of a deleted post do not, and a file MediaVerse did not ask about
	 * (another id) is not opened either.
	 *
	 * @return void
	 */
	public function test_document_on_a_post_follows_the_post_readers(): void {
		$this->make_author_private();
		$post_id = $this->posts->create(
			$this->author,
			array(
				'type'      => 'document',
				'content'   => 'Reading list',
				'privacy'   => 'public',
				'link_meta' => array( 'doc_id' => 4401 ),
			)
		);
		$this->assertIsInt( $post_id );

		$follower = self::factory()->user->create();
		$this->follows->follow( $follower, $this->author );
		$this->follows->approve_follow_request( $this->author, $follower );
		$this->follows->follow( $this->viewer, $this->author ); // Left pending.

		if ( ! class_exists( '\\WPMediaVersePro\\Documents\\DocumentSettings' ) ) {
			// Stand-in for MediaVerse Pro's document vocabulary (four levels).
			eval( 'namespace WPMediaVersePro\\Documents; class DocumentSettings { public static function is_valid_privacy( string $p ): bool { return in_array( $p, array( "private", "space", "members", "public" ), true ); } }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- test double for an absent plugin.
		}

		$bridge = new \BuddyNext\Bridges\WPMediaVerseBridge();
		$ask    = fn( int $viewer, int $doc = 4401 ): bool => $bridge->document_readable_via_post( false, $doc, $viewer, $this->author );

		$this->assertTrue( $ask( $follower ), 'Approved follower.' );
		$this->assertFalse( $ask( $this->viewer ), 'Pending request.' );
		$this->assertFalse( $ask( self::factory()->user->create() ), 'Stranger.' );
		$this->assertFalse( $ask( $follower, 4402 ), 'A document that is on no post.' );
		$this->assertFalse( $bridge->document_readable_via_post( false, 4401, 0, $this->author ), 'Logged-out visitor.' );

		$this->posts->delete( (int) $post_id, $this->author );
		$this->assertFalse( $ask( $follower ), 'The post is gone, so is the way in.' );
	}
}
