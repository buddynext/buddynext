<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * `following` must be paginated at the database, not fetched whole and sliced in PHP.
 *
 * WHY THIS EXISTS (data-volume map, card 10086769305)
 *
 * This is the "fix the sibling" card, and it is the clearest example in the codebase:
 *
 *     // ProfileNav.php:382-391
 *     if ( 'followers' === $relation ) {
 *         // Ask the DB for 60, rather than loading every follower and slicing 60 off
 *         // the front. On a popular account the old form scanned 100k+ rows to build
 *         // a list it then threw away.                      ← THE FIX, AND ITS REASON
 *         $members = $this->ids_to_users( $follow->paged_followers( $uid, 60, 0 ) );
 *
 *     } elseif ( 'following' === $relation ) {
 *         $members = $this->ids_to_users( array_slice( (array) $follow->following( $uid ), 0, 60 ) );
 *     }   //                              ↑ THE EXACT BUG THAT COMMENT DESCRIBES, THREE LINES LOWER
 *
 * Someone fixed `followers`, wrote down precisely why the old form was wrong, and did not look at
 * `following` directly beneath it. `paged_followers()` exists. `paged_following()` never got built.
 *
 * SCOPE, HONESTLY
 *
 * `following()` returns INTs (follow ids), not TEXT — so ~5,000 of them is a few hundred KB, not
 * the ~100MB of the comment-thread bug. This is a wasteful unbounded read on a hot path, not an
 * OOM. It is worth fixing because it runs on EVERY member-directory page view to answer an
 * `isset()` check for 20 rows, and because the bounded alternatives already exist and simply were
 * not used.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\FollowService;
use WP_UnitTestCase;

/**
 * Paged following reads.
 */
class FollowingPaginationTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var FollowService
	 */
	private FollowService $follows;

	/**
	 * The viewer doing the following.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * People the viewer follows, in creation order.
	 *
	 * @var array<int,int>
	 */
	private array $targets = array();

	/**
	 * Captured SQL.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	/**
	 * A viewer following eight people.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->follows = new FollowService();
		$this->viewer  = (int) $this->factory->user->create();

		for ( $i = 0; $i < 8; $i++ ) {
			$target          = (int) $this->factory->user->create();
			$this->targets[] = $target;
			$this->assertTrue(
				true === $this->follows->follow( $this->viewer, $target ),
				'the follow fixture must actually be created'
			);
		}

		$this->queries = array();
		wp_cache_flush();
	}

	/**
	 * Record every SQL statement.
	 *
	 * @return void
	 */
	private function record_queries(): void {
		$this->queries = array();
		add_filter(
			'query',
			function ( $sql ) {
				$this->queries[] = (string) $sql;

				return $sql;
			}
		);
	}

	/**
	 * Queries that read bn_follows.
	 *
	 * @return array<int,string>
	 */
	private function follow_reads(): array {
		return array_values(
			array_filter(
				$this->queries,
				static fn( string $q ): bool => false !== stripos( $q, 'bn_follows' )
					&& 0 === stripos( ltrim( $q ), 'select' )
			)
		);
	}

	/**
	 * paged_following() must exist — the sibling of paged_followers().
	 *
	 * EXPECTED TO FAIL: it was never built.
	 *
	 * @return void
	 */
	public function test_paged_following_exists(): void {
		$this->assertTrue(
			method_exists( $this->follows, 'paged_following' ),
			'paged_followers() exists and is used, with a comment explaining why loading every follower '
			. 'and slicing was wrong. paged_following() was never written, so every caller loads the whole '
			. 'follow set and slices it in PHP.'
		);
	}

	/**
	 * paged_following() returns the right window, and asks the DB for only that window.
	 *
	 * @return void
	 */
	public function test_paged_following_asks_the_database_for_only_the_window(): void {
		if ( ! method_exists( $this->follows, 'paged_following' ) ) {
			$this->fail( 'paged_following() does not exist yet' );
		}

		$this->record_queries();

		$page = $this->follows->paged_following( $this->viewer, 3, 0 );

		$this->assertCount( 3, $page, 'a per_page of 3 must return exactly 3 ids' );
		foreach ( $page as $id ) {
			$this->assertContains( (int) $id, $this->targets, 'every returned id must be someone the viewer follows' );
		}

		$reads = $this->follow_reads();
		$this->assertNotEmpty( $reads, 'it must actually read bn_follows' );

		foreach ( $reads as $sql ) {
			$this->assertMatchesRegularExpression(
				'/\bLIMIT\b/i',
				$sql,
				"The window must be bounded IN SQL, not fetched whole and sliced in PHP.\n\nSQL: "
				. preg_replace( '/\s+/', ' ', $sql )
			);
		}
	}

	/**
	 * The second page must not repeat the first.
	 *
	 * @return void
	 */
	public function test_paged_following_offsets_correctly(): void {
		if ( ! method_exists( $this->follows, 'paged_following' ) ) {
			$this->fail( 'paged_following() does not exist yet' );
		}

		$first  = array_map( 'intval', $this->follows->paged_following( $this->viewer, 3, 0 ) );
		$second = array_map( 'intval', $this->follows->paged_following( $this->viewer, 3, 3 ) );

		$this->assertCount( 3, $second, 'the second page must be full' );
		$this->assertSame(
			array(),
			array_intersect( $first, $second ),
			'page 2 must not repeat page 1 — the OFFSET must be applied in SQL'
		);
	}

	/**
	 * get_following() must page in SQL, not fetch everything and array_slice it.
	 *
	 * It currently reads:
	 *
	 *     $all = $this->following( $user_id );          // every follow, unbounded
	 *     return array_values( array_slice( $all, $offset, $per_page ) );
	 *
	 * EXPECTED TO FAIL until it uses a real LIMIT/OFFSET.
	 *
	 * @return void
	 */
	public function test_get_following_pages_in_sql_not_in_php(): void {
		$this->record_queries();

		$page = $this->follows->get_following(
			$this->viewer,
			array(
				'per_page' => 3,
				'offset'   => 0,
			)
		);

		$this->assertCount( 3, $page, 'a per_page of 3 must return 3 ids' );

		foreach ( $this->follow_reads() as $sql ) {
			$this->assertMatchesRegularExpression(
				'/\bLIMIT\b/i',
				$sql,
				"get_following() fetches the caller's ENTIRE follow set and then array_slice()s it in PHP. "
				. "Page 40 of a 5,000-follow list reads all 5,000 rows to hand back 20.\n\nSQL: "
				. preg_replace( '/\s+/', ' ', $sql )
			);
		}
	}
}
