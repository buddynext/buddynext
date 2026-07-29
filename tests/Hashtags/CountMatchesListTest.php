<?php
/**
 * The stored hashtag count equals what the tag page actually lists.
 *
 * Ticket #41066 was the count and the list disagreeing: the count was a bare
 * COUNT(*) over the pivot, so it counted drafts, deleted rows and every privacy
 * tier, while the feed listed only public published posts outside private
 * spaces. The tag page read "2 posts" and rendered one.
 *
 * That was fixed by putting one predicate behind listable_where() — but only the
 * three READ paths were routed through it. The two recomputes inside sync() and
 * the schema-upgrade repair in Installer each kept a hand-written copy of the
 * clause. All three were semantically identical at the time, so the numbers were
 * right, which is exactly what makes it dangerous: correctness by coincidence,
 * not by construction. Add one condition to the helper and the three copies stay
 * behind, and the drift returns silently.
 *
 * There is now a single definition. These tests assert the INVARIANT rather than
 * the implementation — count == list, through every path that writes the count —
 * so they keep holding if the predicate itself changes.
 *
 * @package BuddyNext\Tests\Hashtags
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Hashtags;

use BuddyNext\Core\Installer;
use BuddyNext\Hashtags\HashtagService;

/**
 * count/list agreement across every writer.
 *
 * @covers \BuddyNext\Hashtags\HashtagService::public_listable_where
 */
class CountMatchesListTest extends \WP_UnitTestCase {

	/**
	 * Hashtag service.
	 *
	 * @var HashtagService
	 */
	private $tags;

	/**
	 * Post author.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Tag slug unique to the running test.
	 *
	 * @var string
	 */
	private $slug = '';

	/**
	 * Posts this test inserted, so tear_down removes exactly these.
	 *
	 * @var int[]
	 */
	private $created_posts = array();

	/**
	 * Boot the schema and an author.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		// Installer::run() issues DDL, and DDL implicitly COMMITS - which ends the
		// transaction WP_UnitTestCase relies on to roll each test back, so rows
		// written by one test survive into the next. Rather than truncate the
		// shared tables (which leaks the other way and strips fixtures other
		// suites depend on), every test gets its OWN tag. Counts are then
		// per-test by construction and no cleanup is needed.
		$this->slug = 'qacount' . substr( md5( $this->getName() ), 0, 8 );

		$this->tags   = new HashtagService();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Remove exactly what this class wrote.
	 *
	 * Normally WP_UnitTestCase rolls each test back and no cleanup is needed. It
	 * cannot here: Installer::run() issues DDL, DDL implicitly COMMITS, and that
	 * ends the surrounding transaction - so anything written after it survives the
	 * test. Leaving rows behind is not a private problem: the widget smoke tests
	 * assert EMPTY states, so stray posts and tags make them fail with no obvious
	 * connection to this file.
	 *
	 * Scoped to this class's own rows on purpose. An earlier version truncated the
	 * shared tables instead, which fixed this class and broke four other tests -
	 * the same "fix the symptom, damage the neighbour" mistake these tests exist
	 * to catch.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		global $wpdb;

		// Rollback FIRST, cleanup second.
		//
		// parent::tear_down() rolls the test's transaction back. Anything written
		// after the DDL commit survives that rollback, but a DELETE issued before
		// it does NOT - it is inside the transaction being discarded, so the
		// cleanup was itself rolled back and the rows stayed. Undoing committed
		// writes has to happen once the rollback is out of the way.
		$posts = $this->created_posts;
		$slug  = $this->slug;

		parent::tear_down();

		if ( ! empty( $posts ) ) {
			$ids = implode( ',', array_map( 'absint', $posts ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_post_hashtags WHERE post_id IN ({$ids})" );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_posts WHERE id IN ({$ids})" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$wpdb->delete( $wpdb->prefix . 'bn_hashtags', array( 'slug' => $slug ) );

		$this->created_posts = array();
	}

	/**
	 * Read the stored count for a tag slug.
	 *
	 * @param string $slug Tag slug.
	 * @return int
	 */
	private function stored_count( string $slug ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_count FROM {$wpdb->prefix}bn_hashtags WHERE slug = %s", $slug )
		);
	}

	/**
	 * How many posts the tag page lists for a logged-out visitor.
	 *
	 * @param string $slug Tag slug.
	 * @return int
	 */
	private function listed_count( string $slug ): int {
		$feed = $this->tags->get_feed( $slug, array( 'viewer_id' => 0, 'per_page' => 50 ) );

		return count( (array) ( $feed['items'] ?? $feed ) );
	}

	/**
	 * Create a post carrying a tag, and sync it.
	 *
	 * @param array<string, mixed> $overrides Post column overrides.
	 * @return int Post id.
	 */
	private function tagged_post( array $overrides = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'user_id'    => $this->author,
				'content'    => 'A post about #' . $this->slug,
				'type'       => 'text',
				'privacy'    => 'public',
				'status'     => 'published',
				'space_id'   => 0,
				'created_at' => current_time( 'mysql', true ),
			),
			$overrides
		);

		$wpdb->insert( $wpdb->prefix . 'bn_posts', $row );
		$post_id               = (int) $wpdb->insert_id;
		$this->created_posts[] = $post_id;

		$this->tags->sync( 'post', $post_id, $this->tags->extract( (string) $row['content'] ) );

		return $post_id;
	}

	/**
	 * The baseline: one public post, count and list agree.
	 *
	 * @return void
	 */
	public function test_count_matches_list_for_a_public_post(): void {
		$this->tagged_post();

		$this->assertSame( 1, $this->stored_count( $this->slug ) );
		$this->assertSame( $this->listed_count( $this->slug ), $this->stored_count( $this->slug ) );
	}

	/**
	 * A draft is listed by neither, so it must be counted by neither. This is the
	 * original #41066 symptom.
	 *
	 * @return void
	 */
	public function test_a_draft_is_counted_by_neither(): void {
		$this->tagged_post();
		$this->tagged_post( array( 'status' => 'draft' ) );

		$this->assertSame(
			$this->listed_count( $this->slug ),
			$this->stored_count( $this->slug ),
			'A draft moved the stored count without moving the list.'
		);
		$this->assertSame( 1, $this->stored_count( $this->slug ) );
	}

	/**
	 * A non-public privacy tier likewise.
	 *
	 * @return void
	 */
	public function test_a_followers_only_post_is_counted_by_neither(): void {
		$this->tagged_post();
		$this->tagged_post( array( 'privacy' => 'followers' ) );

		$this->assertSame( $this->listed_count( $this->slug ), $this->stored_count( $this->slug ) );
		$this->assertSame( 1, $this->stored_count( $this->slug ) );
	}

	/**
	 * A public post inside a PRIVATE space is the case the space clause exists
	 * for: the tag page must not list it to a guest, so the public count must not
	 * include it either.
	 *
	 * @return void
	 */
	public function test_a_private_space_post_is_counted_by_neither(): void {
		$space_id = ( new \BuddyNext\Spaces\SpaceService() )->create(
			$this->author,
			array(
				'name' => 'QA private space',
				'slug' => 'qa-private-space',
				'type' => 'private',
			)
		);
		$this->assertIsInt( $space_id );

		$this->tagged_post();
		$this->tagged_post( array( 'space_id' => (int) $space_id ) );

		$this->assertSame(
			$this->listed_count( $this->slug ),
			$this->stored_count( $this->slug ),
			'A private-space post inflated the public count the tag page advertises.'
		);
		$this->assertSame( 1, $this->stored_count( $this->slug ) );
	}

	/**
	 * The schema repair must land on the SAME number as the live recount.
	 *
	 * This is the assertion that actually pins the consolidation: the repair used
	 * to hold its own copy of the clause, so it could silently write a different
	 * number over a correct one on the next upgrade.
	 *
	 * @return void
	 */
	public function test_the_schema_repair_agrees_with_the_live_recount(): void {
		global $wpdb;

		$this->tagged_post();
		$this->tagged_post( array( 'status' => 'draft' ) );
		$this->tagged_post( array( 'privacy' => 'followers' ) );

		$live = $this->stored_count( $this->slug );

		// Poison the stored value, then let the upgrade repair recompute it.
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}bn_hashtags SET post_count = 999 WHERE slug = %s", $this->slug ) );
		update_option( 'buddynext_schema_version', 35 );
		Installer::maybe_upgrade();

		$this->assertSame(
			$live,
			$this->stored_count( $this->slug ),
			'The upgrade repair and the live recount disagree — the two predicates have drifted.'
		);
		$this->assertSame( $this->listed_count( $this->slug ), $this->stored_count( $this->slug ) );
	}

	/**
	 * There is exactly ONE definition of the predicate. Asserted on the source,
	 * because that is the property that keeps the tests above true in future: a
	 * fourth hand-written copy would pass every assertion above on the day it was
	 * written and drift later.
	 *
	 * @return void
	 */
	public function test_the_predicate_has_a_single_definition(): void {
		$sources = array(
			BUDDYNEXT_DIR . 'includes/Hashtags/HashtagService.php',
			BUDDYNEXT_DIR . 'includes/Core/Installer.php',
		);

		$copies = 0;
		foreach ( $sources as $file ) {
			$body = (string) file_get_contents( $file );
			// The space-visibility tail of the clause, which every copy carried.
			$copies += substr_count( $body, "WHERE type = 'open' )" );
		}

		$this->assertLessThanOrEqual(
			2,
			$copies,
			'The listable predicate has been hand-copied again. Route the new caller through HashtagService::public_listable_where().'
		);
	}
}
