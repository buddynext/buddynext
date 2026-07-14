<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * The purge must be a PROCESS: bounded, resumable, and honest about whether it finished.
 *
 * WHY THIS EXISTS (card 10086831294, step 2)
 *
 * Step 1 bounded the purge's MEMORY. It did nothing about its TIME. A member with 50k posts still
 * has every one of them deleted inside the single request that deleted the account — each through
 * PostService::delete(), which cascades that post's reactions, comments, poll votes, shares and
 * hashtag links. That request will exceed max_execution_time long before it finishes, and because
 * core removes the wp_users row FIRST, the timeout leaves the account gone and the content behind,
 * with no account left to find it by.
 *
 * And nothing notices. Which brings us to the second half:
 *
 * THE GDPR ERASER LIES ABOUT `done`
 *
 * WP core's eraser protocol is a PAGING LOOP: core calls erase( $email, $page ) over and over —
 * each call its own HTTP request, with a fresh time and memory budget — until the eraser reports
 * `done => true`. Only then does core tell the member their data has been erased.
 *
 * That is a chunking driver, handed to us for free. We were throwing it away: erase() did
 * `unset( $page )` and hard-coded `'done' => true`, doing the whole thing in one pass. So on a
 * large member the eraser either times out, or — worse — reports a completed erasure over data
 * that is still in the database. For a compliance path, that is the one lie you cannot tell.
 *
 * WHAT THIS PINS
 *
 * 1. A slice is bounded: it does not delete every post in one go.
 * 2. The eraser says `done => false` while ANY of the member remains, so core keeps paging.
 * 3. The addon seam fires exactly ONCE across the whole process, not once per slice.
 * 4. `done` is earned, not asserted: the process COUNT(*)s every user-keyed table and only reports
 *    finished when the residue is zero.
 * 5. A normal member still completes synchronously in the delete request — space succession and
 *    every other listener must not silently become "eventual".
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Privacy\PrivacyTools;
use BuddyNext\Profile\MemberCleanupService;
use WP_UnitTestCase;

/**
 * The member purge as a bounded, verifiable process.
 */
class MemberPurgeProcessTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var MemberCleanupService
	 */
	private MemberCleanupService $cleanup;

	/**
	 * The member being erased.
	 *
	 * @var int
	 */
	private int $victim;

	/**
	 * Shrink the content chunk so a "prolific" member is cheap to fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cleanup = new MemberCleanupService();
		$this->victim  = (int) $this->factory->user->create();

		add_filter( 'buddynext_purge_post_chunk', static fn(): int => 2 );

		wp_cache_flush();
	}

	/**
	 * Give the member N authored posts.
	 *
	 * @param int $n How many.
	 * @return void
	 */
	private function give_posts( int $n ): void {
		global $wpdb;

		for ( $i = 0; $i < $n; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_posts',
				array(
					'user_id' => $this->victim,
					'content' => 'post ' . $i,
					'status'  => 'published',
				)
			);
		}
	}

	/**
	 * How many posts the member still has.
	 *
	 * @return int
	 */
	private function remaining_posts(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_posts WHERE user_id = %d", $this->victim ) );
	}

	// ── the slice is bounded ───────────────────────────────────────────────────────

	/**
	 * One slice must not delete every post — that is the timeout this whole card is about.
	 *
	 * @return void
	 */
	public function test_a_slice_deletes_at_most_one_chunk_of_content(): void {
		$this->give_posts( 5 );

		$result = $this->cleanup->purge_slice( $this->victim, 'delete' );

		$this->assertFalse(
			$result['done'],
			'5 posts with a chunk of 2 cannot be finished in one slice, so the slice must say so.'
		);
		$this->assertSame(
			3,
			$this->remaining_posts(),
			'A slice must delete ONE chunk (2 of 5) and stop. Deleting all 5 is the unbounded '
			. 'cascade that times out on a 50k-post member — inside the request that already '
			. 'deleted their wp_users row.'
		);
	}

	/**
	 * Slices, repeated, must finish the job.
	 *
	 * @return void
	 */
	public function test_repeated_slices_drain_the_member_completely(): void {
		$this->give_posts( 5 );

		$guard = 0;
		do {
			$result = $this->cleanup->purge_slice( $this->victim, 'delete' );
			++$guard;
		} while ( ! $result['done'] && $guard < 50 );

		$this->assertTrue( $result['done'], 'the slices must converge on done' );
		$this->assertSame( 0, $this->remaining_posts(), 'every post is gone' );
	}

	// ── `done` is earned, not asserted ─────────────────────────────────────────────

	/**
	 * The process must verify itself: COUNT(*) every user-keyed table, and only then say done.
	 *
	 * Nothing checked that the purge worked. That is the whole reason residue sat in three tables
	 * for as long as it did — a monolith has no way to prove it covered everything, so nobody knew.
	 *
	 * @return void
	 */
	public function test_done_is_only_reported_when_the_residue_is_actually_zero(): void {
		$this->give_posts( 3 );

		$guard = 0;
		do {
			$result = $this->cleanup->purge_slice( $this->victim, 'delete' );
			++$guard;
		} while ( ! $result['done'] && $guard < 50 );

		$residue = $this->cleanup->residue( $this->victim );

		$this->assertSame(
			array(),
			array_filter( $residue ),
			"The process reported done while rows for this member are still in the database.\n"
			. 'Residue: ' . wp_json_encode( array_filter( $residue ) )
		);
	}

	/**
	 * The residue check must be able to SEE residue — otherwise it is decoration.
	 *
	 * @return void
	 */
	public function test_the_residue_check_actually_detects_a_leftover_row(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_bookmarks',
			array(
				'user_id' => $this->victim,
				'post_id' => 12345,
			)
		);

		$residue = array_filter( $this->cleanup->residue( $this->victim ) );

		$this->assertArrayHasKey(
			'bn_bookmarks',
			$residue,
			'residue() must count rows in every user-keyed table, or it cannot gate `done`'
		);
	}

	// ── the addon seam fires ONCE ──────────────────────────────────────────────────

	/**
	 * Once per purge — not once per slice.
	 *
	 * Step 1 moved this hook before the content cascade so a timeout could not skip it. Slicing the
	 * cascade must not turn that single signal into one-per-chunk: a listener that is safe to run
	 * once is not necessarily safe to run fifty times, and every listener was written against a
	 * hook that fired exactly once.
	 *
	 * @return void
	 */
	public function test_the_addon_seam_fires_exactly_once_across_every_slice(): void {
		$this->give_posts( 5 );

		$fired = 0;
		add_action(
			'buddynext_purge_user_data',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);

		$guard = 0;
		do {
			$result = $this->cleanup->purge_slice( $this->victim, 'delete' );
			++$guard;
		} while ( ! $result['done'] && $guard < 50 );

		$this->assertSame(
			1,
			$fired,
			'buddynext_purge_user_data fired once per SLICE. Every addon (SpaceSuccession, Pro\'s '
			. 'seven tables, media, messages) would run its cleanup once per chunk of the member\'s '
			. 'content.'
		);
	}

	// ── the GDPR eraser must stop lying ────────────────────────────────────────────

	/**
	 * The eraser must report done => FALSE while the member still has content.
	 *
	 * This is the compliance defect. Core calls erase() page by page until we say done, then emails
	 * the member to say their data is gone. We were saying done on the first call, having attempted
	 * the entire erasure in one request.
	 *
	 * The budget is forced to zero because that is the ONLY interesting case. Given time, the
	 * eraser now finishes the whole member inside the first call and `done => true` is perfectly
	 * honest — residue really is zero. The lie only matters when the erasure genuinely cannot
	 * finish in this request, which is exactly what a 50k-post member does to a real one.
	 *
	 * @return void
	 */
	public function test_the_eraser_does_not_claim_done_while_the_member_still_has_content(): void {
		$this->give_posts( 5 );
		add_filter( 'buddynext_purge_time_budget', static fn(): float => 0.0 );

		$user   = get_userdata( $this->victim );
		$result = ( new PrivacyTools() )->erase( (string) $user->user_email, 1 );

		$this->assertFalse(
			$result['done'],
			'The eraser reported a COMPLETED erasure while the member\'s posts are still in the '
			. 'database. Core takes `done` at its word: it stops paging and tells the member their '
			. 'data has been erased. For a compliance path this is the one lie you cannot tell.'
		);
		$this->assertTrue( $result['items_removed'], 'it did remove things — it just is not finished' );
	}

	/**
	 * ...and it must eventually say done, or core pages forever.
	 *
	 * @return void
	 */
	public function test_the_eraser_reports_done_once_the_member_is_gone(): void {
		$this->give_posts( 5 );

		$user    = get_userdata( $this->victim );
		$email   = (string) $user->user_email;
		$privacy = new PrivacyTools();

		$page   = 1;
		$result = array( 'done' => false );
		while ( ! $result['done'] && $page < 50 ) {
			$result = $privacy->erase( $email, $page );
			++$page;
		}

		$this->assertTrue( $result['done'], 'the eraser must converge, or core pages forever' );
		$this->assertSame( 0, $this->remaining_posts(), 'and the member is actually gone' );
	}

	// ── the ordinary member must not become "eventual" ─────────────────────────────

	/**
	 * A normal member is still purged synchronously, in the request that deleted them.
	 *
	 * The tempting version of this card is "make the purge async, always". That would silently turn
	 * every listener into an eventual one — SpaceSuccession included, which means a deleted owner's
	 * space keeps an owner_id pointing at a user who no longer exists until the queue runs.
	 *
	 * The budget exists so the common case (a member with a handful of posts) still finishes inline
	 * and behaves exactly as it does today. Only a member big enough to blow the time budget is
	 * handed to Action Scheduler.
	 *
	 * @return void
	 */
	public function test_an_ordinary_member_is_fully_purged_inside_the_delete_request(): void {
		$this->give_posts( 3 );

		$removed = $this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertTrue( $removed, 'rows were removed' );
		$this->assertSame(
			0,
			$this->remaining_posts(),
			'An ordinary member must be COMPLETE when the delete request returns. Deferring this '
			. 'to a queue would make space succession, and every other listener, eventual.'
		);
		$this->assertSame( array(), array_filter( $this->cleanup->residue( $this->victim ) ), 'no residue' );
	}

	/**
	 * When the budget runs out, the rest is handed to Action Scheduler — never dropped.
	 *
	 * @return void
	 */
	public function test_a_member_too_big_for_one_request_is_continued_asynchronously(): void {
		$this->give_posts( 5 );

		// A zero-second budget: the first slice exhausts it, exactly as a 50k-post member would.
		add_filter( 'buddynext_purge_time_budget', static fn(): float => 0.0 );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertGreaterThan(
			0,
			$this->remaining_posts(),
			'fixture check: the budget must actually have cut the purge short'
		);

		$this->assertNotFalse(
			as_next_scheduled_action( MemberCleanupService::CONTINUE_HOOK, array( $this->victim, 'delete' ), 'buddynext' ),
			'The purge ran out of time and simply STOPPED. The account is deleted, the content is '
			. 'still there, and nothing is scheduled to finish the job. An unfinished destructive '
			. 'process must always leave a continuation behind.'
		);
	}

	/**
	 * The continuation must not be enqueued twice for the same member.
	 *
	 * @return void
	 */
	public function test_the_continuation_is_not_enqueued_twice(): void {
		$this->give_posts( 5 );
		add_filter( 'buddynext_purge_time_budget', static fn(): float => 0.0 );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );
		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook = %s AND status = 'pending'",
				MemberCleanupService::CONTINUE_HOOK
			)
		);

		$this->assertSame( 1, $pending, 'AS actions must be guarded — one continuation per member, not one per attempt' );
	}
}
