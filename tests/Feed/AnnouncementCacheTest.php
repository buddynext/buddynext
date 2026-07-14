<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Announcements are cached — and must still behave exactly as they did.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * A3 / A4 — the announcement reads were uncached and ran on every space page.
 *
 * space_announcement() ran a bn_posts query on EVERY space page paint (SpaceNav renders
 * it for every space) and once per space in the REST payload — an N+1 across a member's
 * spaces. active_announcement() did the same on the home feed, plus a second query for
 * the featured announcement.
 *
 * The risk in caching them is behavioural, not performance:
 *
 * 1. THE DISMISSAL FILTER LIVED INSIDE THE SQL, UNDER A `LIMIT 1`. So the query never
 *    meant "the newest announcement, unless dismissed" — it meant "the newest
 *    announcement this member has NOT dismissed". A member who dismisses the newest one
 *    is supposed to fall through to the one below it. Caching the single newest row and
 *    filtering it in PHP would have shown them NOTHING instead. The candidate list is
 *    walked in order and the first survivor wins, which preserves that exactly.
 *
 * 2. AN ANNOUNCEMENT CAN GO STALE BY THE CLOCK, with no write to bust anything: the SQL
 *    filtered on site_pin_expires_at > now. Expiry is therefore re-checked on read
 *    rather than baked into the cached value.
 *
 * 3. AN ENDED ANNOUNCEMENT THAT WILL NOT GO AWAY is worse than one that arrives late.
 *    Only IDS are cached; status, is_announcement, expiry and deletion are all re-read
 *    per id through PostService::get(), which is busted on every post write. A stale id
 *    list can only miss a brand-new announcement, which the bust on create prevents.
 *
 * @covers \BuddyNext\Feed\FeedService::active_announcement
 * @covers \BuddyNext\Feed\FeedService::space_announcement
 */
class AnnouncementCacheTest extends WP_UnitTestCase {

	/**
	 * Fresh schema, clean cache.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		wp_cache_flush();
		update_option( 'buddynext_features', array( 'announcements' => 1 ) );
	}

	/**
	 * The feed service, wired the way the container wires it.
	 *
	 * @return FeedService
	 */
	private function feed(): FeedService {
		return buddynext_service( 'feed' );
	}

	/**
	 * Create a site-wide or space announcement and return its id.
	 *
	 * @param int $author   Author user id.
	 * @param int $space_id 0 = site-wide.
	 * @param string $body  Content.
	 * @return int
	 */
	private function announce( int $author, int $space_id, string $body ): int {
		$data = array(
			'content'         => $body,
			'type'            => 'announcement',
			'is_announcement' => 1,
		);

		// A site-wide announcement is one whose space_id is NULL, and PostService writes
		// `$data['space_id'] ?? null` — so the key must be OMITTED, not passed as 0.
		// Passing 0 stores 0, which matches neither the site query (space_id IS NULL) nor
		// any space query, and the announcement would be invisible everywhere.
		if ( $space_id > 0 ) {
			$data['space_id'] = $space_id;
		}

		$id = ( new PostService() )->create( $author, $data );

		$this->assertIsInt( $id, 'Could not create the announcement.' );

		return (int) $id;
	}

	/**
	 * A site announcement shows on the home feed, and a NEW one shows immediately.
	 *
	 * @return void
	 */
	public function test_a_new_announcement_appears_immediately(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$viewer = self::factory()->user->create();

		// Warm the cache with "no announcements".
		$this->assertNull( $this->feed()->active_announcement( $viewer ) );

		$this->announce( $author, 0, 'First announcement' );

		$shown = $this->feed()->active_announcement( $viewer );

		$this->assertNotNull(
			$shown,
			'A brand-new announcement did not appear. This is the one transition a cached candidate list cannot self-heal from, so the create path must rebuild it.'
		);
		$this->assertStringContainsString( 'First announcement', (string) ( $shown['content'] ?? '' ) );
	}

	/**
	 * Dismissing the newest announcement falls through to the one below it.
	 *
	 * This is the behaviour a naive cache would have broken.
	 *
	 * @return void
	 */
	public function test_dismissing_the_newest_falls_through_to_the_next(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$viewer = self::factory()->user->create();

		$older = $this->announce( $author, 0, 'Older announcement' );
		$newer = $this->announce( $author, 0, 'Newer announcement' );

		$this->assertSame( $newer, (int) ( $this->feed()->active_announcement( $viewer )['id'] ?? 0 ) );

		FeedService::dismiss_announcement( $viewer, $newer );

		$this->assertSame(
			$older,
			(int) ( $this->feed()->active_announcement( $viewer )['id'] ?? 0 ),
			'Dismissing the newest announcement showed the member NOTHING instead of falling through to the one below it. The dismissal filter used to sit inside the SQL under a LIMIT 1, and that behaviour has to be preserved.'
		);
	}

	/**
	 * One member's dismissal does not hide the announcement from everybody else.
	 *
	 * The cached value is viewer-independent by design (ids only); the dismissal is
	 * applied per viewer on read. If the two were ever conflated, the first member to
	 * dismiss an announcement would dismiss it for the whole community.
	 *
	 * @return void
	 */
	public function test_one_members_dismissal_does_not_hide_it_from_others(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$alice  = self::factory()->user->create();
		$bob    = self::factory()->user->create();

		$id = $this->announce( $author, 0, 'Everyone should see this' );

		FeedService::dismiss_announcement( $alice, $id );

		$this->assertNull(
			$this->feed()->active_announcement( $alice ),
			'Alice dismissed it and still sees it.'
		);
		$this->assertSame(
			$id,
			(int) ( $this->feed()->active_announcement( $bob )['id'] ?? 0 ),
			"Alice's dismissal hid the announcement from Bob. The cache is leaking one viewer's dismissals to another."
		);
	}

	/**
	 * Ending an announcement takes it off the feed at once, even with a warm cache.
	 *
	 * @return void
	 */
	public function test_ending_an_announcement_removes_it_immediately(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$viewer = self::factory()->user->create();

		$id = $this->announce( $author, 0, 'Temporary announcement' );

		// Warm the cache while it is live.
		$this->assertSame( $id, (int) ( $this->feed()->active_announcement( $viewer )['id'] ?? 0 ) );

		$this->feed()->end_announcement_now( $id );

		$this->assertNull(
			$this->feed()->active_announcement( $viewer ),
			'An ENDED announcement is still pinned to the feed. An announcement that will not go away is worse than one that arrives late - owners usually end one because it is wrong.'
		);
	}

	/**
	 * The other end path (PostService::end_announcement) removes it too.
	 *
	 * There are two end methods and only one of them was on the plan. Missing the other
	 * would leave the announcement pinned exactly half the time.
	 *
	 * @return void
	 */
	public function test_the_other_end_path_removes_it_too(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$viewer = self::factory()->user->create();

		$id = $this->announce( $author, 0, 'Ended the other way' );
		$this->assertSame( $id, (int) ( $this->feed()->active_announcement( $viewer )['id'] ?? 0 ) );

		( new PostService() )->end_announcement( $id );

		$this->assertNull(
			$this->feed()->active_announcement( $viewer ),
			'PostService::end_announcement() left the announcement on the feed - the second end path does not bust the cached candidate list.'
		);
	}

	/**
	 * A space announcement is scoped to its space and does not leak to the home feed.
	 *
	 * @return void
	 */
	public function test_a_space_announcement_stays_in_its_space(): void {
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$viewer = self::factory()->user->create();

		$space_id = ( new \BuddyNext\Spaces\SpaceService() )->create(
			$author,
			array(
				'name'    => 'Cache Probe Space',
				'slug'    => 'cache-probe-space',
				'privacy' => 'public',
			)
		);
		$space_id = (int) ( is_array( $space_id ) ? ( $space_id['id'] ?? 0 ) : $space_id );
		$this->assertGreaterThan( 0, $space_id );

		$id = $this->announce( $author, $space_id, 'Space-only announcement' );

		$this->assertSame(
			$id,
			(int) ( $this->feed()->space_announcement( $space_id, $viewer )['id'] ?? 0 ),
			'The space announcement did not show in its own space.'
		);
		$this->assertNull(
			$this->feed()->active_announcement( $viewer ),
			'A SPACE announcement leaked onto the site-wide home feed. The two scopes must not share a cache key.'
		);
	}
}
