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
}
