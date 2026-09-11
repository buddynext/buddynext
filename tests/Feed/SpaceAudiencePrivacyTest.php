<?php
/**
 * A narrowed-audience post made INTO a space respects its own audience.
 *
 * A member of a space could read every post in it — including a co-member's
 * 'connections' / 'followers' post — through the space feed, the home 'spaces'
 * blend, and search, even when they were not that author's connection / follower.
 * The single-post gate 403'd them but the feed card and the search row printed the
 * body (card 10264292078). The feed queries now carry the per-viewer audience
 * predicate (FeedService::post_audience_clause) and search re-checks post rows
 * against the canonical read gate (SearchService::drop_hidden_posts), so all three
 * surfaces agree with visibility_error().
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use BuddyNext\Search\SearchIndexListener;
use BuddyNext\Search\SearchService;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\Spaces\SpaceService;

/**
 * Post-audience gating on the space feed, home 'spaces' blend, and search.
 *
 * @covers \BuddyNext\Feed\FeedService::post_audience_clause
 * @covers \BuddyNext\Search\SearchService::drop_hidden_posts
 */
class SpaceAudiencePrivacyTest extends \WP_UnitTestCase {

	/** @var PostService */
	private $posts;

	/** @var FeedService */
	private $feed;

	/** @var SearchService */
	private $search;

	/** @var int Author + space owner. */
	private $author = 0;

	/** @var int A space-mate who is NOT connected to / following the author. */
	private $viewer = 0;

	/** @var int Space id. */
	private $space = 0;

	/** @var array<string,int> Post ids keyed by privacy. */
	private $post_ids = array();

	/** @var string Unique search needle. */
	private $token = '';

	/**
	 * Seed a public space, two active members, and a post of each audience.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->posts  = new PostService();
		$this->feed   = new FeedService( new FollowService(), $this->posts );
		$this->search = new SearchService();

		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$spaces      = new SpaceService();
		$this->space = $spaces->create(
			$this->author,
			array(
				'name' => 'Audience Space',
				'slug' => 'audience-space',
				'type' => 'public',
			)
		);
		$this->assertIsInt( $this->space, 'Could not create the space.' );

		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $this->space,
				'user_id'  => $this->viewer,
				'role'     => 'member',
				'status'   => 'active',
			)
		);

		$this->token = 'zqx' . wp_rand();

		// First media id per privacy (a second, +100, is attached alongside it).
		$media_seed = array(
			'public'      => 11,
			'connections' => 12,
			'followers'   => 13,
		);

		foreach ( array( 'public', 'connections', 'followers' ) as $privacy ) {
			$post_id = $this->posts->create(
				$this->author,
				array(
					'content'  => $privacy . ' body ' . $this->token,
					'space_id' => $this->space,
					'privacy'  => $privacy,
					'type'     => 'text',
				)
			);
			$this->assertIsInt( $post_id, 'Could not create the ' . $privacy . ' post.' );
			$this->post_ids[ $privacy ] = $post_id;

			// Attach media directly on the column (create() strips media the author
			// does not own in WPMediaVerse; the space media readers only key off this
			// JSON + the post audience, not ownership). Distinct ids per privacy so a
			// leak is identifiable. Mirrors how SpaceMediaEndpointTest seeds.
			$wpdb->update(
				$wpdb->prefix . 'bn_posts',
				array( 'media_ids' => wp_json_encode( array( $media_seed[ $privacy ], $media_seed[ $privacy ] + 100 ) ) ),
				array( 'id' => $post_id )
			);

			// Warm the search index synchronously (the listener is async in prod).
			( new SearchIndexListener() )->async_index_post( $post_id, $this->author );
		}
	}

	/**
	 * Collect the post ids a read result exposes to the viewer.
	 *
	 * @param array $result Feed or search result.
	 * @return int[] Post ids.
	 */
	private function ids( array $result ): array {
		$items = $result['items'] ?? $result;
		return array_map(
			static fn( $row ): int => (int) ( $row['id'] ?? $row['object_id'] ?? 0 ),
			(array) $items
		);
	}

	/**
	 * The three surfaces every assertion walks, as label => id list.
	 *
	 * @return array<string,int[]>
	 */
	private function surfaces(): array {
		wp_cache_flush();
		return array(
			'space feed'   => $this->ids( $this->feed->space_feed( $this->space, $this->viewer, null, 20 ) ),
			'home spaces'  => $this->ids( $this->feed->home_feed( $this->viewer, null, 20, 'spaces' ) ),
			'search'       => $this->ids( $this->search->search( $this->token, 'post', 20, 1, $this->viewer ) ),
		);
	}

	/**
	 * A public post in a joined space is visible on every surface.
	 *
	 * @return void
	 */
	public function test_public_post_is_visible_everywhere(): void {
		foreach ( $this->surfaces() as $label => $ids ) {
			$this->assertContains( $this->post_ids['public'], $ids, "Public post missing from {$label}." );
		}
	}

	/**
	 * A non-connection space-mate is denied a 'connections' post on every surface.
	 *
	 * @return void
	 */
	public function test_connections_post_hidden_from_non_connection(): void {
		foreach ( $this->surfaces() as $label => $ids ) {
			$this->assertNotContains( $this->post_ids['connections'], $ids, "Connections post LEAKED into {$label}." );
		}
	}

	/**
	 * A non-follower space-mate is denied a 'followers' post on every surface.
	 *
	 * @return void
	 */
	public function test_followers_post_hidden_from_non_follower(): void {
		foreach ( $this->surfaces() as $label => $ids ) {
			$this->assertNotContains( $this->post_ids['followers'], $ids, "Followers post LEAKED into {$label}." );
		}
	}

	/**
	 * No over-restriction: once connected, the 'connections' post appears on every
	 * surface — the gate widens correctly rather than hiding legitimate content.
	 *
	 * @return void
	 */
	public function test_connections_post_visible_after_connecting(): void {
		$connections = new ConnectionService();
		$connections->send_request( $this->author, $this->viewer );
		$connections->accept_request( $this->viewer, $this->author );

		foreach ( $this->surfaces() as $label => $ids ) {
			$this->assertContains( $this->post_ids['connections'], $ids, "Connections post missing from {$label} after connecting." );
			$this->assertNotContains( $this->post_ids['followers'], $ids, "Followers post LEAKED into {$label} (viewer is not a follower)." );
		}
	}

	/**
	 * The space Media readers (rows + flat ids) are sibling readers of bn_posts and
	 * were viewer-independent, so a connections/followers post's images rendered on
	 * the Media tab to every space member even though the feed hid the post (card
	 * 10264292078 round 4). They now carry the same audience clause: a non-connection
	 * space-mate sees only the public post's row and its media ids.
	 *
	 * @return void
	 */
	public function test_space_media_readers_hide_non_visible_posts(): void {
		$row_post_ids = array_map(
			static fn( array $r ): int => (int) $r['post_id'],
			$this->feed->space_media_rows( $this->space, $this->viewer, 50, 0 )
		);
		$this->assertContains( $this->post_ids['public'], $row_post_ids, 'Public media row missing.' );
		$this->assertNotContains( $this->post_ids['connections'], $row_post_ids, 'Connections media row LEAKED.' );
		$this->assertNotContains( $this->post_ids['followers'], $row_post_ids, 'Followers media row LEAKED.' );

		$ids = $this->feed->space_media_ids( $this->space, $this->viewer, 60 );
		$this->assertContains( 11, $ids, 'Public media id missing.' );
		$this->assertContains( 111, $ids, 'Public media id (second) missing.' );
		$this->assertNotContains( 12, $ids, 'Connections media id LEAKED.' );
		$this->assertNotContains( 13, $ids, 'Followers media id LEAKED.' );
	}

	/**
	 * The count/list drift: space_post_count() and space_media_post_count() drove the
	 * space header stat and the Media tab total viewer-independently, so the header
	 * said 3 while the feed showed 1. Both counts now match the audience-gated list.
	 *
	 * @return void
	 */
	public function test_space_counts_match_the_visible_list(): void {
		// Non-connected space-mate: only the public post (and its media) is visible.
		$this->assertSame( 1, $this->feed->space_post_count( $this->space, $this->viewer ), 'Post count over-counts hidden posts.' );
		$this->assertSame( 1, $this->feed->space_media_post_count( $this->space, $this->viewer ), 'Media post count over-counts hidden posts.' );

		// Author sees all three.
		$this->assertSame( 3, $this->feed->space_post_count( $this->space, $this->author ), 'Author should see all posts.' );
		$this->assertSame( 3, $this->feed->space_media_post_count( $this->space, $this->author ), 'Author should see all media posts.' );
	}

	/**
	 * No over-restriction on the media readers/counts either: once connected, the
	 * connections post's media + count appear, followers stays hidden.
	 *
	 * @return void
	 */
	public function test_space_media_and_counts_widen_after_connecting(): void {
		$connections = new ConnectionService();
		$connections->send_request( $this->author, $this->viewer );
		$connections->accept_request( $this->viewer, $this->author );

		$ids = $this->feed->space_media_ids( $this->space, $this->viewer, 60 );
		$this->assertContains( 12, $ids, 'Connections media id missing after connecting.' );
		$this->assertNotContains( 13, $ids, 'Followers media id LEAKED (viewer is not a follower).' );

		$this->assertSame( 2, $this->feed->space_post_count( $this->space, $this->viewer ), 'Count should widen to public + connections.' );
		$this->assertSame( 2, $this->feed->space_media_post_count( $this->space, $this->viewer ), 'Media count should widen to public + connections.' );
	}

	/**
	 * The audience clause alone was not enough: the space feed also drops posts by
	 * authors this viewer BLOCKED and by suspended authors, but the media/count
	 * readers applied only the audience clause — so a blocked member's tiles still
	 * rendered on the Media tab and the header over-counted (card 10264292078, the
	 * fix-first leak). The readers now AND in excluded_users_where() +
	 * viewer_hidden_where(), the same two fragments the feed uses.
	 *
	 * @return void
	 */
	public function test_media_and_counts_exclude_blocked_and_hidden_authors(): void {
		global $wpdb;

		// A third space member whose PUBLIC post + media the viewer sees by default.
		$other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$wpdb->insert(
			$wpdb->prefix . 'bn_space_members',
			array(
				'space_id' => $this->space,
				'user_id'  => $other,
				'role'     => 'member',
				'status'   => 'active',
			)
		);
		$other_post = $this->posts->create(
			$other,
			array(
				'content'  => 'other body ' . $this->token,
				'space_id' => $this->space,
				'privacy'  => 'public',
				'type'     => 'text',
			)
		);
		$this->assertIsInt( $other_post, 'Could not create the co-member post.' );
		$wpdb->update(
			$wpdb->prefix . 'bn_posts',
			array( 'media_ids' => wp_json_encode( array( 20, 120 ) ) ),
			array( 'id' => $other_post )
		);

		// Baseline: a public post by a co-member the viewer has NOT blocked is visible
		// (author-public + other-public = two visible media posts).
		wp_cache_flush();
		$rows = array_map(
			static fn( array $r ): int => (int) $r['post_id'],
			$this->feed->space_media_rows( $this->space, $this->viewer, 50, 0 )
		);
		$this->assertContains( $other_post, $rows, 'Co-member public media should be visible before blocking.' );
		$this->assertSame( 2, $this->feed->space_media_post_count( $this->space, $this->viewer ), 'Two public media posts before blocking.' );

		// Viewer blocks the author: the Media tab must not render their tiles and the
		// counts must drop. This is the fix-first leak — not a count mismatch, a
		// blocked member's content appearing.
		( new BlockService() )->block( $this->viewer, $other );
		wp_cache_flush();

		$rows = array_map(
			static fn( array $r ): int => (int) $r['post_id'],
			$this->feed->space_media_rows( $this->space, $this->viewer, 50, 0 )
		);
		$this->assertNotContains( $other_post, $rows, 'Blocked author media tile LEAKED into the Media tab.' );
		$this->assertNotContains( 20, $this->feed->space_media_ids( $this->space, $this->viewer, 60 ), 'Blocked author media id LEAKED.' );
		$this->assertSame( 1, $this->feed->space_post_count( $this->space, $this->viewer ), 'Post count still includes a blocked author.' );
		$this->assertSame( 1, $this->feed->space_media_post_count( $this->space, $this->viewer ), 'Media count still includes a blocked author.' );

		// A suspended author (hide_posts) is dropped too, exactly as the feed drops
		// them — the viewer's last visible post was the author's public one.
		buddynext_service( 'moderation' )->suspend_user( $this->author, 1, 'phpunit', array( 'hide_posts' => 1 ) );
		wp_cache_flush();
		$this->assertSame( 0, $this->feed->space_post_count( $this->space, $this->viewer ), 'Suspended author still counted.' );
		$this->assertSame( 0, $this->feed->space_media_post_count( $this->space, $this->viewer ), 'Suspended author media still counted.' );
	}
}
