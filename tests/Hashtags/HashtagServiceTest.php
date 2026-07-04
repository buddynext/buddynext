<?php
/**
 * Tests for HashtagService.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Hashtags\HashtagService;

/**
 * @covers \BuddyNext\Hashtags\HashtagService
 */
class HashtagServiceTest extends \WP_UnitTestCase {

	private HashtagService $service;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new HashtagService();
		$this->user_id = self::factory()->user->create();
	}

	public function test_extract_returns_hashtags_from_content(): void {
		$tags = $this->service->extract( 'Hello #world and #php are great' );

		$this->assertContains( 'world', $tags );
		$this->assertContains( 'php', $tags );
	}

	public function test_extract_deduplicates(): void {
		$tags = $this->service->extract( '#foo and #foo again' );

		$this->assertCount( 1, $tags );
	}

	public function test_extract_returns_empty_when_no_hashtags(): void {
		$tags = $this->service->extract( 'No hashtags here' );

		$this->assertEmpty( $tags );
	}

	public function test_sync_creates_hashtag_rows(): void {
		global $wpdb;

		$this->service->sync( 'post', 1, array( 'wordpress', 'php' ) );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_hashtags WHERE slug IN ('wordpress', 'php')"
		);

		$this->assertSame( 2, $count );
	}

	public function test_sync_creates_post_hashtag_links(): void {
		global $wpdb;

		$this->service->sync( 'post', 5, array( 'test' ) );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id = %d AND object_type = 'post'",
				5
			)
		);

		$this->assertSame( 1, $count );
	}

	public function test_sync_is_idempotent(): void {
		global $wpdb;

		$this->service->sync( 'post', 10, array( 'idempotent' ) );
		$this->service->sync( 'post', 10, array( 'idempotent' ) );

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_hashtags WHERE slug = 'idempotent'"
		);

		$this->assertSame( 1, $count );
	}

	public function test_get_by_slug_returns_hashtag(): void {
		$this->service->sync( 'post', 20, array( 'buddynext' ) );

		$tag = $this->service->get_by_slug( 'buddynext' );

		$this->assertNotNull( $tag );
		$this->assertSame( 'buddynext', $tag['slug'] );
	}

	public function test_get_by_slug_returns_null_for_missing(): void {
		$this->assertNull( $this->service->get_by_slug( 'nonexistentxyz' ) );
	}

	public function test_get_trending_returns_array(): void {
		$this->service->sync( 'post', 50, array( 'trending1' ) );
		$this->service->sync( 'post', 51, array( 'trending2' ) );

		$results = $this->service->get_trending( 10 );

		$this->assertIsArray( $results );
		$slugs = array_column( $results, 'slug' );
		$this->assertContains( 'trending1', $slugs );
		$this->assertContains( 'trending2', $slugs );
	}

	public function test_get_trending_respects_limit(): void {
		foreach ( range( 1, 5 ) as $i ) {
			$this->service->sync( 'post', $i + 100, array( "limittag{$i}" ) );
		}

		$results = $this->service->get_trending( 2 );

		$this->assertLessThanOrEqual( 2, count( $results ) );
	}

	public function test_sync_removes_old_links_on_update(): void {
		global $wpdb;

		// Post originally tagged with 'old', now updated to 'new'.
		$this->service->sync( 'post', 30, array( 'old' ) );
		$this->service->sync( 'post', 30, array( 'new' ) );

		$old_link = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}bn_hashtags
				 JOIN {$wpdb->prefix}bn_post_hashtags ON {$wpdb->prefix}bn_hashtags.id = {$wpdb->prefix}bn_post_hashtags.hashtag_id
				 WHERE {$wpdb->prefix}bn_post_hashtags.post_id = %d AND {$wpdb->prefix}bn_hashtags.slug = 'old'",
				30
			)
		);

		$this->assertNull( $old_link );
	}

	public function test_sync_writes_created_at_on_both_tables(): void {
		global $wpdb;

		$this->service->sync( 'post', 777, array( 'utctag' ) );

		// bn_hashtags row + bn_post_hashtags link must both carry a populated,
		// UTC-aligned created_at. Previously the inserts omitted the column and
		// relied on DEFAULT CURRENT_TIMESTAMP (server-local), so the trending
		// 24-hour window (now compared against UTC_TIMESTAMP()) could miss fresh
		// tags by the server's UTC offset.
		$tag_created  = (string) $wpdb->get_var(
			"SELECT created_at FROM {$wpdb->prefix}bn_hashtags WHERE slug = 'utctag' LIMIT 1"
		);
		$link_created = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT created_at FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id = %d LIMIT 1",
				777
			)
		);

		$this->assertNotEmpty( $tag_created );
		$this->assertNotEmpty( $link_created );

		// The link timestamp must sit inside the trending window — i.e. a tag
		// synced "now" is discoverable as trending.
		$trending = $this->service->get_trending( 50 );
		$slugs    = array_map( static fn( $t ): string => (string) ( $t['slug'] ?? '' ), $trending );
		$this->assertContains( 'utctag', $slugs );
	}

	/**
	 * A Unicode hashtag must survive the full round-trip: extraction keeps it,
	 * sync stores it verbatim (not sanitize_key-mangled), and get_by_slug finds
	 * it. Regression guard for card 10062124971 (sanitize_key stripped "#café" to
	 * "caf" and dropped "#日本語" entirely). ASCII tags are unaffected.
	 *
	 * @covers \BuddyNext\Hashtags\HashtagService::normalize_slug
	 * @covers \BuddyNext\Hashtags\HashtagService::extract
	 * @covers \BuddyNext\Hashtags\HashtagService::sync
	 */
	public function test_unicode_hashtags_round_trip_intact(): void {
		// Normalizer: Unicode-safe case fold, ASCII lowercase, junk to ''.
		$this->assertSame( 'café', HashtagService::normalize_slug( '#CAFÉ' ) );
		$this->assertSame( '日本語', HashtagService::normalize_slug( '#日本語' ) );
		$this->assertSame( 'wordpress', HashtagService::normalize_slug( '#WordPress' ) );
		$this->assertSame( '', HashtagService::normalize_slug( '#---' ) );

		$slugs = $this->service->extract( 'Loving #café and #日本語 plus #wordpress' );
		$this->assertContains( 'café', $slugs, 'Unicode accent survives extraction' );
		$this->assertContains( '日本語', $slugs, 'CJK tag survives extraction' );
		$this->assertContains( 'wordpress', $slugs );

		$this->service->sync( 'post', 4242, $slugs );

		// Stored verbatim, and resolvable by the exact Unicode slug.
		$this->assertSame( 'café', $this->service->get_by_slug( 'café' )['slug'] ?? null );
		$this->assertSame( '日本語', $this->service->get_by_slug( '日本語' )['slug'] ?? null );
		// The old mangling stored "caf"; that row must NOT exist now.
		$this->assertNull( $this->service->get_by_slug( 'caf' ), 'no truncated "caf" row is created' );
	}

	/**
	 * list_followed() returns the user's followed tags (newest first) with
	 * has_more paging, and follow()/unfollow() keep get_by_slug()'s follower_count
	 * fresh (the row cache is busted on the counter change). Guards C5.5's
	 * GET /me/hashtags + the follow-response follower_count.
	 *
	 * @covers \BuddyNext\Hashtags\HashtagService::list_followed
	 * @covers \BuddyNext\Hashtags\HashtagService::follow
	 * @covers \BuddyNext\Hashtags\HashtagService::unfollow
	 */
	public function test_list_followed_and_fresh_follower_count(): void {
		$a = $this->service->register( 'follow_a' );
		$b = $this->service->register( 'follow_b' );

		// Prime the row cache with the pre-follow count (0), as a reader would.
		$this->assertSame( 0, $this->service->get_by_slug( 'follow_a' )['follower_count'] );

		$this->service->follow( $this->user_id, $a );
		$this->service->follow( $this->user_id, $b );

		// Cache must reflect the bump, not the primed 0.
		$this->assertSame( 1, $this->service->get_by_slug( 'follow_a' )['follower_count'], 'follow busts the stale row cache' );

		$followed = $this->service->list_followed( $this->user_id );
		$slugs    = array_column( $followed['items'], 'slug' );
		$this->assertContains( 'follow_a', $slugs );
		$this->assertContains( 'follow_b', $slugs );
		$this->assertFalse( $followed['has_more'] );

		$this->service->unfollow( $this->user_id, $a );
		$this->assertSame( 0, $this->service->get_by_slug( 'follow_a' )['follower_count'], 'unfollow busts the stale row cache' );
		$this->assertNotContains( 'follow_a', array_column( $this->service->list_followed( $this->user_id )['items'], 'slug' ) );
	}

	/**
	 * A public post inside a private/secret space must NOT appear on the public
	 * tag page (guest), but MUST appear for a member of that space. Regression
	 * guard for card 10062124931 (tag pages leaked private-space posts).
	 *
	 * @covers \BuddyNext\Hashtags\HashtagService::get_feed
	 */
	public function test_tag_feed_hides_private_space_posts_from_guests(): void {
		global $wpdb;
		$author = self::factory()->user->create();
		$member = self::factory()->user->create();

		$wpdb->insert( $wpdb->prefix . 'bn_hashtags', array( 'slug' => 'spacetag', 'post_count' => 0, 'follower_count' => 0 ) );
		$hid = (int) $wpdb->insert_id;

		$wpdb->insert( $wpdb->prefix . 'bn_spaces', array( 'name' => 'Secret', 'slug' => 'secret-s', 'type' => 'secret', 'owner_id' => $author, 'created_at' => current_time( 'mysql', true ) ) );
		$secret = (int) $wpdb->insert_id;
		$wpdb->insert( $wpdb->prefix . 'bn_space_members', array( 'space_id' => $secret, 'user_id' => $member, 'status' => 'active' ) );

		$make = function ( int $space ) use ( $wpdb, $author, $hid ): int {
			$wpdb->insert( $wpdb->prefix . 'bn_posts', array( 'user_id' => $author, 'content' => '#spacetag', 'type' => 'text', 'privacy' => 'public', 'status' => 'published', 'space_id' => $space, 'created_at' => current_time( 'mysql', true ) ) );
			$pid = (int) $wpdb->insert_id;
			$wpdb->insert( $wpdb->prefix . 'bn_post_hashtags', array( 'post_id' => $pid, 'object_type' => 'post', 'hashtag_id' => $hid, 'created_at' => current_time( 'mysql', true ) ) );
			return $pid;
		};
		$open_post   = $make( 0 );
		$secret_post = $make( $secret );

		$guest_ids  = array_map( static fn( $r ): int => (int) $r['id'], $this->service->get_feed( '#spacetag', array( 'viewer_id' => 0 ) )['items'] );
		$member_ids = array_map( static fn( $r ): int => (int) $r['id'], $this->service->get_feed( '#spacetag', array( 'viewer_id' => $member ) )['items'] );

		$this->assertContains( $open_post, $guest_ids, 'non-space post is public' );
		$this->assertNotContains( $secret_post, $guest_ids, 'secret-space post must NOT leak to guests' );
		$this->assertContains( $secret_post, $member_ids, 'a space member still sees the post' );
	}
}
