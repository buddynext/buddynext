<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A comment thread must not load the whole object into memory.
 *
 * WHY THIS EXISTS (data-volume map, card 10086769290)
 *
 * `CommentService::list()` paginates the TOP-LEVEL comments (20/page, max 50) — and then fetches
 * EVERY descendant of the entire object in one query, with `SELECT *`, and no `LIMIT`:
 *
 *     SELECT * FROM bn_comments
 *      WHERE object_type = %s AND object_id = %d AND parent_id IS NOT NULL
 *      ORDER BY created_at ASC
 *
 * `content` is a TEXT column, so every reply's full body is pulled into one PHP array — even the
 * replies belonging to top-level comments on OTHER pages, which are then discarded.
 *
 * The code already knew:
 *
 *     "Memory cost is O(thread length); for normal community sizes this is negligible. Viral
 *      threads (>1000 comments) would need per-branch pagination instead — that's a separate
 *      sprint."
 *
 * A separate sprint that never happened. A 50k-reply thread is roughly 75-100MB in a single PHP
 * array, against a typical 256MB memory_limit, on a MEMBER-FACING page, with concurrent hits on
 * the same hot thread. That is an OOM, not a slow query.
 *
 * HOW THIS FILE IS BUILT
 *
 * The first four are CHARACTERIZATION tests: they pin the tree behaviour that must survive the
 * change (nesting, the fold-back at MAX_REPLY_DEPTH, the restrict gate, the pinned comment).
 * They PASS TODAY. If the rewrite breaks the thread rendering, they go red.
 *
 * The last two are the fix, and they FAIL today:
 *   - the descendant fetch must be SCOPED to the current page's roots, not the whole object
 *   - a pathological thread must be BOUNDED, and must say so rather than silently truncating
 *
 * @package BuddyNext\Tests\Comments
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Comments;

use BuddyNext\Comments\CommentService;
use BuddyNext\Feed\PostService;
use WP_UnitTestCase;

/**
 * Comment-thread memory bound + tree characterization.
 */
class CommentThreadMemoryTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var CommentService
	 */
	private CommentService $comments;

	/**
	 * Thread author.
	 *
	 * @var int
	 */
	private int $user;

	/**
	 * The post the comments hang off.
	 *
	 * @var int
	 */
	private int $post;

	/**
	 * Captured SQL for the current test.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	/**
	 * Fresh service, a member, and a post to comment on.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->comments = new CommentService();
		$this->user     = (int) $this->factory->user->create();

		$post = ( new PostService() )->create( $this->user, array( 'content' => 'thread host' ) );
		$this->assertIsInt( $post, 'the host-post fixture must be created' );
		$this->post = (int) $post;

		$this->queries = array();
		wp_cache_flush();
	}

	/**
	 * Start recording every SQL statement the code runs.
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
	 * Every recorded query that touched bn_comments.
	 *
	 * @return array<int,string>
	 */
	private function comment_queries(): array {
		return array_values(
			array_filter(
				$this->queries,
				static fn( string $q ): bool => false !== stripos( $q, 'bn_comments' ) && 0 === stripos( ltrim( $q ), 'select' )
			)
		);
	}

	/**
	 * Add a comment (or reply) and return its id.
	 *
	 * @param string|null $parent_of Parent comment id, or null for top-level.
	 * @param string      $body      Content.
	 * @return int
	 */
	private function comment( ?int $parent_of, string $body ): int {
		$id = $this->comments->create( $this->user, 'post', $this->post, $body, $parent_of );
		$this->assertIsInt( $id, "the comment fixture '{$body}' must be created" );

		return (int) $id;
	}

	/**
	 * Find a node by id anywhere in the returned tree.
	 *
	 * @param array<int,array<string,mixed>> $items Tree.
	 * @param int                            $id    Comment id.
	 * @return array<string,mixed>|null
	 */
	private function find( array $items, int $id ): ?array {
		foreach ( $items as $item ) {
			if ( (int) $item['id'] === $id ) {
				return $item;
			}
			$hit = $this->find( (array) ( $item['replies'] ?? array() ), $id );
			if ( null !== $hit ) {
				return $hit;
			}
		}

		return null;
	}

	// ── characterization: the tree must keep working ──────────────────────────────

	/**
	 * A nested thread still renders as a tree.
	 *
	 * @return void
	 */
	public function test_nested_replies_are_attached_to_their_parent(): void {
		$root  = $this->comment( null, 'root' );
		$kid   = $this->comment( $root, 'kid' );
		$grand = $this->comment( $kid, 'grandkid' );

		$result = $this->comments->list( 'post', $this->post );

		$root_node = $this->find( $result['items'], $root );
		$this->assertNotNull( $root_node, 'the root comment must be in the page' );

		$kid_node = $this->find( $result['items'], $kid );
		$this->assertNotNull( $kid_node, 'a reply must be attached somewhere in the tree' );

		$grand_node = $this->find( $result['items'], $grand );
		$this->assertNotNull( $grand_node, 'a nested reply must be attached too' );
	}

	/**
	 * Replies past MAX_REPLY_DEPTH are folded back, not lost.
	 *
	 * `create()` REFUSES to make a reply past the cap (`reply_too_deep`, 400), so the fold-back in
	 * `list()` exists for rows the service can no longer produce: threads imported, demo-seeded, or
	 * created before the cap landed. It is legacy-data handling, and the rewrite must not lose it —
	 * dropping it would make those replies invisible rather than un-indented.
	 *
	 * So the over-deep row is inserted directly. That is deliberate: it reproduces a state the
	 * public API is specifically designed to reject, which is the only way to exercise this path.
	 *
	 * @return void
	 */
	public function test_legacy_replies_past_the_depth_cap_are_folded_back_not_lost(): void {
		global $wpdb;

		// Build a chain right up to the cap through the real API.
		$id    = $this->comment( null, 'depth-1' );
		$chain = array( $id );
		for ( $d = 2; $d <= CommentService::MAX_REPLY_DEPTH; $d++ ) {
			$id      = $this->comment( $id, "depth-{$d}" );
			$chain[] = $id;
		}

		// One row PAST the cap, which create() would reject. This is the legacy shape.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_comments',
			array(
				'user_id'     => $this->user,
				'object_type' => 'post',
				'object_id'   => $this->post,
				'parent_id'   => $id,
				'content'     => 'too deep for create(), but it exists in the table',
			),
			array( '%d', '%s', '%d', '%d', '%s' )
		);
		$this->assertNotFalse( $inserted, 'the legacy over-deep row must actually insert — a silent failure would make this test pass for the wrong reason' );
		$chain[] = (int) $wpdb->insert_id;

		$result = $this->comments->list( 'post', $this->post );

		foreach ( $chain as $cid ) {
			$this->assertNotNull(
				$this->find( $result['items'], $cid ),
				"comment {$cid} must still be reachable — the fold-back keeps every reply visible, it just "
				. 'stops indenting. Losing it would hide legacy replies entirely.'
			);
		}
	}

	/**
	 * A pinned comment is prepended even when it is not on the current page.
	 *
	 * @return void
	 */
	public function test_pinned_comment_is_prepended(): void {
		$first = $this->comment( null, 'first' );
		$this->comment( null, 'second' );

		update_option( 'bn_pinned_comment_post_' . $this->post, $first );

		$result = $this->comments->list( 'post', $this->post );

		$this->assertNotEmpty( $result['items'], 'the page must have items' );
		$this->assertSame( $first, (int) $result['items'][0]['id'], 'the pinned comment must come first' );
		$this->assertTrue( (bool) ( $result['items'][0]['pinned'] ?? false ), 'and be flagged as pinned' );
	}

	/**
	 * Replies belonging to OTHER pages' roots must not appear on this page.
	 *
	 * @return void
	 */
	public function test_replies_of_other_pages_roots_do_not_leak_into_this_page(): void {
		$page1_root = $this->comment( null, 'page-1 root' );
		$page1_kid  = $this->comment( $page1_root, 'page-1 kid' );

		$page2_root = $this->comment( null, 'page-2 root' );
		$page2_kid  = $this->comment( $page2_root, 'page-2 kid' );

		$page1 = $this->comments->list(
			'post',
			$this->post,
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);

		$this->assertNotNull( $this->find( $page1['items'], $page1_kid ), 'this page\'s reply must be attached' );
		$this->assertNull(
			$this->find( $page1['items'], $page2_kid ),
			'a reply under a root on page 2 must not appear on page 1'
		);
		$this->assertNull( $this->find( $page1['items'], $page2_root ), 'page 2\'s root is not on page 1' );
	}

	// ── the fix: memory ───────────────────────────────────────────────────────────

	/**
	 * THE MEMORY BUG: the descendant fetch must be scoped to the CURRENT PAGE's roots.
	 *
	 * Today the query is object-wide — `parent_id IS NOT NULL` for the whole object — so viewing
	 * page 1 of a 50,000-reply thread pulls all 50,000 replies (with their TEXT bodies) into one
	 * PHP array, then throws away everything that does not hang off the 20 roots being rendered.
	 *
	 * EXPECTED TO FAIL until the fetch is scoped.
	 *
	 * @return void
	 */
	public function test_descendants_are_fetched_only_for_the_current_pages_roots(): void {
		$page1_root = $this->comment( null, 'page-1 root' );
		$this->comment( $page1_root, 'page-1 kid' );

		$page2_root = $this->comment( null, 'page-2 root' );
		$this->comment( $page2_root, 'page-2 kid' );

		$this->record_queries();

		$this->comments->list(
			'post',
			$this->post,
			array(
				'per_page' => 1,
				'page'     => 1,
			)
		);

		foreach ( $this->comment_queries() as $sql ) {
			$this->assertDoesNotMatchRegularExpression(
				'/parent_id\s+IS\s+NOT\s+NULL/i',
				$sql,
				'The descendant fetch is OBJECT-WIDE: it selects every reply on the thread regardless of '
				. 'which page is being rendered, and `content` is a TEXT column. On a 50k-reply thread that '
				. 'is ~100MB in one PHP array, on a member-facing page. Scope the fetch to the roots '
				. "actually being rendered.\n\nOffending SQL: " . preg_replace( '/\s+/', ' ', $sql )
			);
		}
	}

	/**
	 * A pathological thread must be BOUNDED — and must say so, not truncate in silence.
	 *
	 * Even scoped to one page, a single root can carry an unbounded reply chain. There has to be a
	 * ceiling, and when we hit it the caller must be told, so the UI can offer "load more" instead
	 * of quietly pretending the thread ends there.
	 *
	 * EXPECTED TO FAIL until the cap exists.
	 *
	 * @return void
	 */
	public function test_a_runaway_thread_is_capped_and_says_so(): void {
		// Lower the ceiling so the test does not need to build thousands of rows.
		add_filter( 'buddynext_comment_descendant_cap', static fn(): int => 3 );

		$root = $this->comment( null, 'root' );
		for ( $i = 0; $i < 8; $i++ ) {
			$this->comment( $root, "reply {$i}" );
		}

		$result = $this->comments->list( 'post', $this->post );

		$this->assertTrue(
			(bool) ( $result['replies_truncated'] ?? false ),
			'The thread exceeded the descendant cap, so the result must SAY it was truncated. A silent cap '
			. 'reads as "this is the whole thread" when it is not — the member cannot tell replies are '
			. 'missing, and neither can the UI.'
		);
	}
}
