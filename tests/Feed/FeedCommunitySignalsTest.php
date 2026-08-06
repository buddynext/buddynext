<?php
/**
 * The feed's community signals — author presence and the reactor face-stack.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedController;
use BuddyNext\Feed\PostService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\Realtime\PresenceService;
use BuddyNext\REST\Router;
use BuddyNext\SocialGraph\BlockService;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Presence dots and reactor face-stacks are page-level reads, never per-card ones.
 *
 * Both signals are cheap to render and ruinous to fetch naively: a presence dot is
 * one integer per author and a face-stack is three rows per post, but asked for a
 * card at a time they become twenty extra round trips on a twenty-post page. The
 * existing enrichment already primes users, media and viewer-state for the whole
 * page before it loops; these two join that pass rather than opening a second one.
 *
 * So the assertions here are as much about query shape as payload shape. A test
 * that only read keys would pass just as happily against a per-post implementation,
 * which is the exact regression the batching exists to prevent.
 *
 * @covers \BuddyNext\Realtime\PresenceService::last_active_map
 * @covers \BuddyNext\Reactions\ReactionService::top_reactors_map
 */
class FeedCommunitySignalsTest extends \WP_UnitTestCase {

	/**
	 * Reaction service under test.
	 *
	 * @var ReactionService
	 */
	private ReactionService $reactions;

	/**
	 * Set up schema and services.
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->reactions = new ReactionService();
	}

	/**
	 * The presence map answers for every id asked, including the never-seen.
	 */
	public function test_last_active_map_returns_entry_for_every_requested_id(): void {
		$seen   = self::factory()->user->create();
		$unseen = self::factory()->user->create();
		PresenceService::write( $seen, time() );

		$map = PresenceService::last_active_map( array( $seen, $unseen ) );

		$this->assertArrayHasKey( $seen, $map );
		$this->assertArrayHasKey( $unseen, $map, 'A user with no presence row must still resolve, as 0.' );
		$this->assertGreaterThan( 0, $map[ $seen ] );
		$this->assertSame( 0, $map[ $unseen ] );
	}

	/**
	 * Presence for a whole page costs one query, not one per author.
	 */
	public function test_last_active_map_is_one_query_regardless_of_page_size(): void {
		global $wpdb;

		$ids = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$uid   = self::factory()->user->create();
			$ids[] = $uid;
			PresenceService::write( $uid, time() );
		}

		$before = $wpdb->num_queries;
		PresenceService::last_active_map( $ids );
		$spent = $wpdb->num_queries - $before;

		$this->assertLessThanOrEqual( 1, $spent, "Presence for 12 authors cost {$spent} queries; it must cost one." );
	}

	/**
	 * An empty request must not reach the database at all.
	 */
	public function test_last_active_map_short_circuits_on_empty_input(): void {
		global $wpdb;

		$before = $wpdb->num_queries;
		$map    = PresenceService::last_active_map( array() );

		$this->assertSame( array(), $map );
		$this->assertSame( $before, $wpdb->num_queries );
	}

	/**
	 * The face-stack is keyed by post and capped at the requested size.
	 */
	public function test_top_reactors_map_is_keyed_by_post_and_capped(): void {
		$owner = self::factory()->user->create();
		$post  = 501;

		$expected_first = 0;
		for ( $i = 0; $i < 6; $i++ ) {
			$uid = self::factory()->user->create();
			// Ascending timestamps, so the LAST reactor created is the most recent.
			$this->reactions->react( $uid, 'post', $post, 'like', gmdate( 'Y-m-d H:i:s', time() - ( 60 * ( 6 - $i ) ) ) );
			$expected_first = $uid;
		}

		$map = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3 );

		$this->assertArrayHasKey( $post, $map );
		$this->assertCount( 3, $map[ $post ], 'A face-stack asked for 3 must return at most 3.' );
		$this->assertSame( $expected_first, $map[ $post ][0]['user_id'], 'Most recent reactor leads the stack.' );
	}

	/**
	 * The stack carries what a face-stack renders — identity, not just an id.
	 */
	public function test_top_reactors_map_carries_renderable_identity(): void {
		$owner   = self::factory()->user->create();
		$reactor = self::factory()->user->create( array( 'display_name' => 'Amina Rahman' ) );
		$post    = 502;
		$this->reactions->react( $reactor, 'post', $post, 'like' );

		$map = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3 );

		$this->assertSame( $reactor, $map[ $post ][0]['user_id'] );
		$this->assertSame( 'Amina Rahman', $map[ $post ][0]['display_name'] );
		$this->assertNotEmpty( $map[ $post ][0]['avatar_url'] );
	}

	/**
	 * A post nobody reacted to resolves to an empty stack, never a missing key.
	 */
	public function test_top_reactors_map_returns_empty_array_for_unreacted_post(): void {
		$owner = self::factory()->user->create();
		$post  = 503;

		$map = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3 );

		$this->assertSame( array(), $map[ $post ] );
	}

	/**
	 * The restrict gate from get_reactors() survives batching.
	 *
	 * Restrict is one-way and silent: the restricted person still sees themselves
	 * reacting, and the post owner still sees everything they moderate. Only the
	 * ordinary onlooker loses the signal. Batching must not quietly widen that.
	 */
	public function test_top_reactors_map_hides_restricted_reactor_from_ordinary_viewer(): void {
		$owner      = self::factory()->user->create();
		$restricted = self::factory()->user->create();
		$onlooker   = self::factory()->user->create();
		$post       = 504;

		( new BlockService() )->restrict( $owner, $restricted );
		$this->reactions->react( $restricted, 'post', $post, 'like' );

		$hidden = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3, $onlooker );
		$this->assertSame( array(), $hidden[ $post ], 'An onlooker must not see a restricted reactor.' );

		$to_owner = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3, $owner );
		$this->assertCount( 1, $to_owner[ $post ], 'The post owner still moderates, so still sees them.' );

		$to_self = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3, $restricted );
		$this->assertCount( 1, $to_self[ $post ], 'Restrict is silent — the restricted user sees themselves.' );
	}

	/**
	 * The face-stack for a page costs a bounded number of queries.
	 *
	 * This is the assertion the whole method exists for. Reusing the per-object
	 * get_reactors() here would issue three queries per post; at twenty posts that
	 * is the N+1 the feed is explicitly built to avoid.
	 */
	public function test_top_reactors_map_does_not_scale_queries_with_post_count(): void {
		global $wpdb;

		$reactor   = self::factory()->user->create();
		$post_ids  = array();
		$owner_map = array();
		// Twenty DISTINCT authors, which is the case that actually bites: the restrict
		// list is keyed by author, so a per-author read is page-size-shaped whenever a
		// feed does what feeds do and shows twenty different people. Sharing one author
		// across the page would let a per-author implementation pass this on a cache hit.
		for ( $i = 0; $i < 20; $i++ ) {
			$pid               = 600 + $i;
			$post_ids[]        = $pid;
			$owner_map[ $pid ] = self::factory()->user->create();
			$this->reactions->react( $reactor, 'post', $pid, 'like' );
		}

		// A second page, double the size, with its own authors again.
		$big_ids = array();
		$big_map = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$pid             = 800 + $i;
			$big_ids[]       = $pid;
			$big_map[ $pid ] = self::factory()->user->create();
			$this->reactions->react( $reactor, 'post', $pid, 'like' );
		}

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$small  = $this->reactions->top_reactors_map( 'post', $post_ids, $owner_map, 3 );
		$cost20 = $wpdb->num_queries - $before;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$big    = $this->reactions->top_reactors_map( 'post', $big_ids, $big_map, 3 );
		$cost40 = $wpdb->num_queries - $before;

		$this->assertCount( 20, $small );
		$this->assertCount( 40, $big );

		// The absolute number is WordPress's business — priming users and avatars costs
		// what it costs. The contract worth pinning is the SHAPE: doubling the page, and
		// doubling the distinct authors with it, must not cost more queries. A per-post
		// or per-author implementation fails here by construction, and no cache warmth
		// can hide it, because both reads start cold.
		$this->assertSame(
			$cost20,
			$cost40,
			"20 posts cost {$cost20} queries and 40 cost {$cost40}. Face-stack reads must not "
				. 'scale with page size or author count.'
		);
	}

	/**
	 * A reactor the viewer cannot see must not consume a face-stack slot.
	 *
	 * The slice is read with a buffer above the requested size precisely so the
	 * privacy filter can drop rows without leaving a short stack behind.
	 */
	public function test_top_reactors_map_backfills_stack_past_hidden_reactors(): void {
		$owner    = self::factory()->user->create();
		$onlooker = self::factory()->user->create();
		$post     = 700;
		$blocks   = new BlockService();

		// Three restricted reactors land most recently, three visible ones before them.
		$visible = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$uid       = self::factory()->user->create();
			$visible[] = $uid;
			$this->reactions->react( $uid, 'post', $post, 'like', gmdate( 'Y-m-d H:i:s', time() - 600 + $i ) );
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$uid = self::factory()->user->create();
			$blocks->restrict( $owner, $uid );
			$this->reactions->react( $uid, 'post', $post, 'like', gmdate( 'Y-m-d H:i:s', time() - 60 + $i ) );
		}

		$map = $this->reactions->top_reactors_map( 'post', array( $post ), array( $post => $owner ), 3, $onlooker );

		$this->assertCount( 3, $map[ $post ], 'Hidden reactors must not leave the stack short.' );
		foreach ( $map[ $post ] as $entry ) {
			$this->assertContains( $entry['user_id'], $visible );
		}
	}

	/**
	 * Presence for a page of distinct authors is primed, not asked per author.
	 *
	 * is_user_online_at() consults two per-pair gates — restrict and block — and both
	 * are cache-keyed by (viewer, target). Cold, that is two queries for every distinct
	 * author on the page, which the map alone does not fix: batching last_active only
	 * removes one of the three reads. The feed primes both gates for the same reason
	 * the member directory does, and this pins that it keeps doing so.
	 */
	public function test_presence_gates_are_primed_for_the_page(): void {
		global $wpdb;

		$viewer  = self::factory()->user->create();
		$authors = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$authors[] = self::factory()->user->create();
		}
		$blocks = new BlockService();

		wp_cache_flush();
		$before = $wpdb->num_queries;
		foreach ( $authors as $aid ) {
			$blocks->is_user_online_at( $viewer, $aid, time() );
		}
		$unprimed = $wpdb->num_queries - $before;

		wp_cache_flush();
		$before = $wpdb->num_queries;
		$blocks->prime_restricted_cache( $viewer, $authors );
		$blocks->blocking_either_map( $viewer, $authors );
		foreach ( $authors as $aid ) {
			$blocks->is_user_online_at( $viewer, $aid, time() );
		}
		$primed = $wpdb->num_queries - $before;

		$this->assertGreaterThan(
			$primed,
			$unprimed,
			'Priming must cost less than asking per author, or the feed should stop priming.'
		);
		$this->assertLessThanOrEqual(
			3,
			$primed,
			"Primed presence for 12 authors cost {$primed} queries; it must be flat, not per-author."
		);
	}

	/**
	 * The feed itself carries both signals, in the shape a client renders from.
	 *
	 * The unit tests above prove the two maps; this proves they actually reach the
	 * wire. Enrichment has form before: a method existed, was correct, and had a
	 * single call site, so most feed surfaces returned rows that never saw it.
	 */
	public function test_feed_payload_carries_presence_and_face_stack(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$author = self::factory()->user->create();
		wp_set_current_user( $author );
		PresenceService::write( $author, time() );

		$posts   = new PostService();
		$post_id = (int) $posts->create(
			$author,
			array(
				'content' => 'Community signals',
				'type'    => 'text',
			)
		);

		$reactor = self::factory()->user->create( array( 'display_name' => 'Marcus Bell' ) );
		$this->reactions->react( $reactor, 'post', $post_id, 'like' );

		$response = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );
		$this->assertSame( 200, $response->get_status() );

		$items = $response->get_data()['items'] ?? $response->get_data();
		$item  = null;
		foreach ( (array) $items as $candidate ) {
			if ( (int) ( $candidate['id'] ?? 0 ) === $post_id ) {
				$item = $candidate;
				break;
			}
		}
		$this->assertNotNull( $item, 'The post under test must be in the home feed.' );

		$this->assertArrayHasKey( 'is_online', $item['author'] );
		$this->assertTrue( $item['author']['is_online'], 'An author active just now reads as online.' );

		$this->assertArrayHasKey( 'top_reactors', $item );
		$this->assertCount( 1, $item['top_reactors'] );
		$this->assertSame( 'Marcus Bell', $item['top_reactors'][0]['display_name'] );
		$this->assertLessThanOrEqual( FeedController::FACE_STACK_SIZE, count( $item['top_reactors'] ) );
	}

	/**
	 * A post with no reactions still carries the key, as an empty stack.
	 *
	 * A client that has to distinguish "absent" from "empty" ends up writing the
	 * guard on every card; the payload settles it once instead.
	 */
	public function test_feed_payload_carries_empty_stack_for_unreacted_post(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		( new Router() )->register();
		do_action( 'rest_api_init' );

		$author = self::factory()->user->create();
		wp_set_current_user( $author );
		( new PostService() )->create(
			$author,
			array(
				'content' => 'Nobody reacted to this',
				'type'    => 'text',
			)
		);

		$response = $wp_rest_server->dispatch( new WP_REST_Request( 'GET', '/buddynext/v1/feed/home' ) );
		$items    = $response->get_data()['items'] ?? $response->get_data();

		$this->assertNotEmpty( $items );
		foreach ( (array) $items as $item ) {
			$this->assertArrayHasKey( 'top_reactors', $item );
			$this->assertIsArray( $item['top_reactors'] );
			$this->assertArrayHasKey( 'is_online', $item['author'] );
		}
	}
}
