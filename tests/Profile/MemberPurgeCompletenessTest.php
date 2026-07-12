<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A destructive path may be slow. It may never be PARTIAL.
 *
 * WHY THIS EXISTS (card 10086831294 · plan: free-internal/docs/plans/member-purge-process.md)
 *
 * `MemberCleanupService::purge_user_relations()` is the one place a member's footprint is erased —
 * shared by admin hard-delete (`deleted_user`) and the GDPR eraser. It is a compliance path: when
 * it runs, the data must be GONE.
 *
 * It ran in this order:
 *
 *   1. wp_users row ........................ ALREADY DELETED by core (`deleted_user` fires after)
 *   2. get_col() x 100k peer ids ........... UNBOUNDED — can OOM here
 *   3. DELETE FROM bn_* WHERE user_id = X .. never reached if (2) died
 *   4. foreach (10k authored posts) delete()
 *   5. do_action( 'buddynext_purge_user_data' )   ← LINE 201 OF 205
 *
 * Two failures, both compliance:
 *
 * THE HALF-ERASE. Step 2 sits between "the member is gone" and "their data is erased". A member
 * with 100k followers can exhaust memory there — leaving the WordPress account deleted, every bn_*
 * table still full, and no account left to find them by.
 *
 * THE UNREACHED SEAM. Every downstream cleanup — Spaces\SpaceSuccession promoting a new space
 * owner, Pro's UserCleanupListener purging 7 tables, media, messages — hangs off the action at step
 * 5. All of it exists and is correct. None of it runs if the monolith dies at step 2. A monolith
 * that must survive 200 lines to notify its collaborators is a single point of failure for every
 * downstream cleanup in the product.
 *
 * WHAT MAKES THE FIX CHEAP
 *
 * The peer counters do not need the ids at all. `bn_reactions` is
 * PRIMARY KEY (user_id, object_type, object_id) — one row per user per object — so every decrement
 * is exactly -1, and the whole reconciliation is one set-based UPDATE per counter, with no PHP
 * array and no loop. Verified in the schema; if a member could react twice to one object this
 * would not hold.
 *
 * @package BuddyNext\Tests\Profile
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Feed\PostService;
use BuddyNext\Profile\MemberCleanupService;
use BuddyNext\SocialGraph\FollowService;
use WP_UnitTestCase;

/**
 * The purge must complete, must not scan unbounded sets, and must always notify its collaborators.
 */
class MemberPurgeCompletenessTest extends WP_UnitTestCase {

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
	 * A peer who follows the victim, and is followed back.
	 *
	 * @var int
	 */
	private int $peer;

	/**
	 * Captured SQL.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	/**
	 * A member with relations.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cleanup = new MemberCleanupService();
		$this->victim  = (int) $this->factory->user->create();
		$this->peer    = (int) $this->factory->user->create();

		$follows = new FollowService();
		$this->assertTrue( true === $follows->follow( $this->victim, $this->peer ), 'victim follows peer' );
		$this->assertTrue( true === $follows->follow( $this->peer, $this->victim ), 'peer follows victim' );

		wp_cache_flush();
		$this->queries = array();
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
	 * Rows the victim still owns in a table.
	 *
	 * @param string $table  Unprefixed table.
	 * @param string $column Column holding the user id.
	 * @return int
	 */
	private function rows_left( string $table, string $column ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->victim
			)
		);
	}

	/**
	 * Seed one row into each of the three log tables for the victim.
	 *
	 * @return void
	 */
	private function seed_logs(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'bn_activity_log',
				array(
					'user_id' => $this->victim,
					'action'  => 'post_created',
				),
				array( '%d', '%s' )
			),
			'the activity-log fixture must insert'
		);
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'bn_email_log',
				array(
					'user_id' => $this->victim,
					'type'    => 'bn.test',
				),
				array( '%d', '%s' )
			),
			'the email-log fixture must insert'
		);
		$this->assertNotFalse(
			$wpdb->insert(
				$wpdb->prefix . 'bn_webhook_log',
				array( 'user_id' => $this->victim ),
				array( '%d' )
			),
			'the webhook-log fixture must insert'
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	// ── the half-erase ────────────────────────────────────────────────────────────

	/**
	 * The purge must never SELECT an unbounded relation set into PHP.
	 *
	 * That fetch runs BEFORE the deletes, so an OOM there leaves the member's WordPress account
	 * deleted and every one of their bn_* rows intact — with no account left to find them by.
	 *
	 * EXPECTED TO FAIL until the counter reconciliation is set-based.
	 *
	 * @return void
	 */
	public function test_the_purge_never_selects_an_unbounded_relation_set(): void {
		$this->record_queries();

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		foreach ( $this->queries as $sql ) {
			$flat = preg_replace( '/\s+/', ' ', $sql );

			if ( 0 !== stripos( ltrim( (string) $flat ), 'select' ) ) {
				continue;
			}
			if ( false === stripos( (string) $flat, 'bn_follows' ) && false === stripos( (string) $flat, 'bn_connections' ) ) {
				continue;
			}
			if ( false !== stripos( (string) $flat, 'count(' ) ) {
				continue; // an aggregate returns one row.
			}

			$this->assertMatchesRegularExpression(
				'/\bLIMIT\b/i',
				(string) $flat,
				'The purge SELECTs a relation set into PHP with no LIMIT, BEFORE the DELETEs. A member '
				. 'with 100k followers can OOM right there — after core has already removed their '
				. 'wp_users row. The account is gone, the data is not, and there is no user left to find '
				. "it by.\n\nSQL: " . $flat
			);
		}
	}

	/**
	 * Bounding the purge is not an excuse to skip the reconciliation — the peer's counters drop.
	 *
	 * @return void
	 */
	public function test_peer_counters_are_still_reconciled(): void {
		update_user_meta( $this->peer, 'bn_follower_count', 1 );
		update_user_meta( $this->peer, 'bn_following_count', 1 );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		wp_cache_delete( $this->peer, 'user_meta' );

		$this->assertSame(
			0,
			(int) get_user_meta( $this->peer, 'bn_follower_count', true ),
			'the peer lost their only follower (the erased member), so their follower count must drop'
		);
		$this->assertSame(
			0,
			(int) get_user_meta( $this->peer, 'bn_following_count', true ),
			'the peer was following the erased member, so their following count must drop'
		);
	}

	/**
	 * ...and the peer's CACHED counter must drop with it.
	 *
	 * This is the test the one above cannot be: it deletes the peer's user_meta cache before
	 * reading, so it passes whether or not the purge invalidated anything. Every read of these
	 * counters in production goes through get_user_meta(), i.e. through the persistent
	 * `user_meta` cache group.
	 *
	 * That gap is not hypothetical. Replacing the per-peer decrements with a single set-based
	 * `UPDATE {usermeta} um JOIN {bn_follows} f ...` is the obvious way to bound this purge — no
	 * ids in PHP, no loop, one statement regardless of degree — and it is silently wrong: an
	 * id-less UPDATE never learns which rows it touched, so it cannot invalidate them. The
	 * database would hold the right number while every peer kept serving the old one from cache,
	 * with nothing to correct it (the nightly recount_all_* jobs are set-based and do not bust
	 * caches either).
	 *
	 * So this test primes the cache the way a real request would, and reads it back the same
	 * way. It is what forces the purge to go through CounterService, which invalidates by id.
	 *
	 * @return void
	 */
	public function test_the_peer_counter_cache_is_invalidated(): void {
		update_user_meta( $this->peer, 'bn_follower_count', 1 );

		// Prime the cache exactly as a page view would, and prove it is warm.
		$this->assertSame( 1, (int) get_user_meta( $this->peer, 'bn_follower_count', true ), 'fixture' );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		// NOTE: no wp_cache_delete() here — that is the whole point.
		$this->assertSame(
			0,
			(int) get_user_meta( $this->peer, 'bn_follower_count', true ),
			'The peer\'s follower count is right in the database but stale in the object cache. The '
			. 'purge updated the row without invalidating it by id, so every read of this peer will '
			. 'keep returning the pre-deletion count until something unrelated happens to flush them.'
		);
	}

	// ── the residue ───────────────────────────────────────────────────────────────

	/**
	 * The three log tables must be erased with the member.
	 *
	 * They are not permanent residue — the retention crons age them out in 30–365 days. But GDPR
	 * requires erasure "without undue delay", and a year is not without undue delay. A member who
	 * asks to be forgotten should not sit in the activity log until next summer.
	 *
	 * EXPECTED TO FAIL: none of the three is in the purge today.
	 *
	 * @return void
	 */
	public function test_the_log_tables_are_erased(): void {
		$this->seed_logs();

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertSame( 0, $this->rows_left( 'bn_activity_log', 'user_id' ), 'activity log must be erased' );
		$this->assertSame( 0, $this->rows_left( 'bn_email_log', 'user_id' ), 'email log must be erased' );
		$this->assertSame( 0, $this->rows_left( 'bn_webhook_log', 'user_id' ), 'webhook log must be erased' );
	}

	/**
	 * The relational footprint must actually be gone.
	 *
	 * @return void
	 */
	public function test_the_relational_footprint_is_erased(): void {
		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertSame( 0, $this->rows_left( 'bn_follows', 'follower_id' ), 'outbound follows erased' );
		$this->assertSame( 0, $this->rows_left( 'bn_follows', 'following_id' ), 'inbound follows erased' );
		$this->assertSame( 0, $this->rows_left( 'bn_notification_prefs', 'user_id' ), 'prefs erased' );
	}

	// ── the unreached seam ────────────────────────────────────────────────────────

	/**
	 * The addon seam must fire BEFORE the unbounded content deletion, not after it.
	 *
	 * SpaceSuccession (new space owner), Pro's 7 tables, media and messages ALL hang off
	 * `buddynext_purge_user_data` — fired on line 201 of a 205-line function. Every one of them is
	 * correct, and none of them runs if the monolith dies first.
	 *
	 * ORDER IS THE ONLY PROTECTION THERE IS. A try/finally cannot help: the failure mode is an OOM
	 * on an unbounded fetch, or a timeout inside a 10k-iteration cascading-delete loop, and PHP
	 * runs no `finally` on a fatal. So the seam must fire before the work that can kill the request.
	 *
	 * None of the collaborators depend on Free's content deletion having finished — Pro purges its
	 * OWN tables, and succession only needs the space membership that still exists at that point. So
	 * there is nothing to lose by telling them first.
	 *
	 * Asserted by counting the authored-post deletions that have already happened when the action
	 * fires: it must be zero.
	 *
	 * EXPECTED TO FAIL: today the action is the last thing the function does.
	 *
	 * @return void
	 */
	public function test_the_addon_seam_fires_before_the_heavy_content_deletion(): void {
		$posts = new PostService();
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertIsInt( $posts->create( $this->victim, array( 'content' => "post {$i}" ) ), 'post fixture' );
		}

		$this->record_queries();

		$deletes_before_seam = null;
		add_action(
			'buddynext_purge_user_data',
			function () use ( &$deletes_before_seam ): void {
				$deletes_before_seam = count(
					array_filter(
						$this->queries,
						static fn( string $q ): bool => false !== stripos( $q, 'bn_posts' )
							&& 0 === stripos( ltrim( $q ), 'delete' )
					)
				);
			}
		);

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertNotNull( $deletes_before_seam, 'buddynext_purge_user_data must fire at all' );
		$this->assertSame(
			0,
			$deletes_before_seam,
			'The addon seam fires AFTER the authored-post deletion — a loop that, for a 10k-post member, '
			. 'can time out. Space-owner succession, Pro\'s 7 tables, media and messages all hang off that '
			. 'action, so a timeout in Free\'s content loop silently skips every other cleanup in the '
			. 'product. None of them depend on Free\'s deletion finishing. Tell the collaborators FIRST.'
		);
	}

	// ── completeness ──────────────────────────────────────────────────────────────

	/**
	 * Authored posts must all go.
	 *
	 * @return void
	 */
	public function test_all_authored_posts_are_erased(): void {
		$posts = new PostService();
		for ( $i = 0; $i < 5; $i++ ) {
			$id = $posts->create( $this->victim, array( 'content' => "post {$i}" ) );
			$this->assertIsInt( $id, 'the post fixture must be created' );
		}
		$this->assertSame( 5, $this->rows_left( 'bn_posts', 'user_id' ), 'five posts exist (primed)' );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		$this->assertSame(
			0,
			$this->rows_left( 'bn_posts', 'user_id' ),
			'every authored post must be erased — a partial erasure leaves the member\'s content behind '
			. 'after their account is gone'
		);
	}

	/**
	 * The purge must be idempotent — a retry after an interrupted run must be safe.
	 *
	 * Every statement is `WHERE user_id = X`, so this should hold. It is asserted because
	 * "resumable" is only true if re-running is safe.
	 *
	 * @return void
	 */
	public function test_the_purge_is_idempotent(): void {
		$this->seed_logs();
		update_user_meta( $this->peer, 'bn_follower_count', 1 );

		$this->cleanup->purge_user_relations( $this->victim, 'delete' );
		$this->cleanup->purge_user_relations( $this->victim, 'delete' );

		wp_cache_delete( $this->peer, 'user_meta' );

		$this->assertSame(
			0,
			(int) get_user_meta( $this->peer, 'bn_follower_count', true ),
			'a second purge must not drive an already-reconciled counter negative — GREATEST(0, …) guards it'
		);
		$this->assertSame( 0, $this->rows_left( 'bn_activity_log', 'user_id' ), 'still erased after a re-run' );
	}
}
