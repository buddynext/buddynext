<?php
/**
 * Canonical member-cleanup service.
 *
 * ONE source of truth for purging a member's BuddyNext footprint, shared by the
 * hard-delete path (SocialGraph\UserCleanupListener on `deleted_user`) and the
 * GDPR eraser (Privacy\PrivacyTools). Both used to carry their own overlapping —
 * and disagreeing — purge lists, each missing tables the other cleaned. They now
 * both call purge_user_relations(), so a member can never linger half-deleted in
 * search / presence / counts.
 *
 * WHAT IS ERASED, AND WHAT IS DELIBERATELY KEPT
 *
 * Erasure is not "delete every row with this user_id in it". Two of our tables record
 * what was done ABOUT a member, by someone else, and erasing those would let a member
 * delete the record of being actioned simply by deleting their account:
 *
 *   ERASED   Everything the member authored, chose, or is the subject of as a person:
 *            posts, comments, reactions, follows, connections, blocks, presence,
 *            profile values, search index, notifications, bn_* usermeta, and their own
 *            activity / email / webhook log rows.
 *
 *   RETAINED bn_reports  — a report is a case FILED BY someone else about content.
 *            bn_mod_log  — a moderation log is a record of what a MODERATOR did.
 *
 * Both are retained under GDPR Art. 17(3)(e) (establishment/exercise/defence of legal
 * claims): they are the site owner's records of a case, not the member's own content.
 * bn_mod_log is append-only by design (ModerationLogService); closed bn_reports are
 * pruned on their own retention schedule by CronService::handle_cleanup_reports.
 *
 * KNOWN, DELIBERATE RESIDUE: a report the erased member FILED keeps their reporter_id
 * until that report is closed and pruned. Anonymising it to 0 in place is NOT safe —
 * bn_reports carries UNIQUE KEY one_per_reporter (reporter_id, object_type, object_id),
 * so two erased members who both reported the same post would collide on 0 and the
 * second write would be silently rejected. Fixing that needs a dedup step, so it is
 * tracked separately rather than bolted on here.
 *
 * @package BuddyNext
 */

declare( strict_types=1 );

namespace BuddyNext\Profile;

defined( 'ABSPATH' ) || exit;

/**
 * Purges every user-keyed BuddyNext row for a removed/erased member.
 */
class MemberCleanupService {

	/**
	 * How many relational edges to reconcile + delete per pass.
	 *
	 * Caps peak memory during the purge: a member with 100k followers is processed 500 ids at
	 * a time instead of 100k in one array. Small enough to stay cheap, large enough that the
	 * ordinary member (tens of edges) finishes in a single pass.
	 *
	 * @var int
	 */
	private const RELATION_CHUNK = 500;

	/**
	 * How many authored posts to cascade per slice.
	 *
	 * Deliberately smaller than RELATION_CHUNK: deleting a follow row is one statement, while
	 * deleting a post cascades its reactions, comments, poll votes, shares and hashtag links.
	 *
	 * @var int
	 */
	private const POST_CHUNK = 100;

	/**
	 * Seconds the purge may spend in one request before deferring the rest to a queue.
	 *
	 * Sized so an ordinary member — the overwhelming majority — finishes inline and nothing about
	 * their deletion becomes "eventual". Only a member big enough to blow this is handed off.
	 *
	 * @var float
	 */
	private const TIME_BUDGET = 10.0;

	/**
	 * How many times to re-attempt a purge that keeps leaving residue before giving up.
	 *
	 * Refusing to spin forever is not the same as pretending to have succeeded: on the last attempt
	 * the residue is logged, table by table, so the cause (an unregistered table, or an addon
	 * writing rows back) is named rather than hidden.
	 *
	 * @var int
	 */
	private const MAX_STUCK_SLICES = 3;

	/**
	 * Option prefix holding one member's in-flight purge state.
	 *
	 * @var string
	 */
	private const STATE_PREFIX = 'bn_purge_';

	/**
	 * Action Scheduler hook that finishes a purge too big for one request.
	 *
	 * @var string
	 */
	public const CONTINUE_HOOK = 'buddynext_purge_member_continue';

	/**
	 * Action Scheduler group — one per plugin, so everything is observable together.
	 *
	 * @var string
	 */
	private const AS_GROUP = 'buddynext';

	/**
	 * THE ERASE REGISTRY — every table that must be empty of a member before they are "erased".
	 *
	 * One list, two consumers: the sweep in purge_user_relations() DELETEs from it, and residue()
	 * COUNTs from it to decide whether the purge may report `done`. That is the whole point. The
	 * delete list and the verify list were going to be two hand-maintained lists, and two
	 * hand-maintained lists drift — which is exactly the bug: bn_activity_log, bn_email_log and
	 * bn_webhook_log were never purged because someone added a table and nothing forced them to
	 * add it here. Now a registered table is deleted AND verified, and an unregistered one is
	 * invisible to both — so the rule to enforce is simply "every user-keyed table is registered".
	 *
	 * `where`  — the predicate, identical for the DELETE and the COUNT so they cannot disagree.
	 *            Two-sided relations match either end; a member is as erased when they are the
	 *            recipient as when they are the sender.
	 * `sweep`  — false when a plain DELETE would be wrong:
	 *              bn_posts        cascades child rows via PostService::delete()
	 *              bn_comments     needs the commented-on posts recounted afterwards
	 *              bn_profile_values is owned by ProfileService (mirror + cache)
	 *            Those still appear here, because they still have to be VERIFIED.
	 *
	 * NOT on this list, deliberately — see retain_map() for the reasons.
	 *
	 * @return array<string, array{where: string, sweep: bool}>
	 */
	public static function erase_map(): array {
		$map = array(
			// Relations. Both directions: erasure is not one-sided.
			'bn_follows'                 => array(
				'where' => 'follower_id = %d OR following_id = %d',
				'sweep' => true,
			),
			'bn_connections'             => array(
				'where' => 'requester_id = %d OR recipient_id = %d',
				'sweep' => true,
			),
			'bn_blocks'                  => array(
				'where' => 'blocker_id = %d OR blocked_id = %d',
				'sweep' => true,
			),

			// Content + engagement.
			'bn_posts'                   => array(
				'where' => 'user_id = %d',
				'sweep' => false,
			),
			'bn_comments'                => array(
				'where' => 'user_id = %d',
				'sweep' => false,
			),
			'bn_reactions'               => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_poll_votes'              => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_shares'                  => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_bookmarks'               => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_hashtag_follows'         => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),

			// Membership + identity.
			'bn_space_members'           => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_space_bans'              => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_member_type_assignments' => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_profile_values'          => array(
				'where' => 'user_id = %d',
				'sweep' => false,
			),
			'bn_presence'                => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_verify_tokens'           => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),

			// Moderation rows that are ABOUT the member as a subject (not the moderator's record
			// of acting — that is retained; see retain_map()).
			'bn_user_strikes'            => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_user_suspensions'        => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_appeals'                 => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),

			// Notifications: theirs, and the ones they caused.
			'bn_notifications'           => array(
				'where' => 'recipient_id = %d OR sender_id = %d',
				'sweep' => true,
			),
			'bn_notification_prefs'      => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),

			// The member's own row plus everything they authored.
			'bn_search_index'            => array(
				'where' => "author_id = %d OR ( object_type = 'member' AND object_id = %d )",
				'sweep' => true,
			),

			// The logs. Missing from the purge entirely until this card. The retention crons do age
			// them out (activity 365d, email 60-365d, webhook 30d) — but GDPR says "without undue
			// delay", and a year is not without undue delay.
			'bn_activity_log'            => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_email_log'               => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
			'bn_webhook_log'             => array(
				'where' => 'user_id = %d',
				'sweep' => true,
			),
		);

		/**
		 * Register a user-keyed table with the member purge.
		 *
		 * An addon with its own per-user table can either clean it on `buddynext_purge_user_data`
		 * (the usual way) or register it here, in which case BuddyNext both deletes it and — more
		 * importantly — refuses to report the member erased while rows remain in it.
		 *
		 * @param array<string, array{where: string, sweep: bool}> $map Table (unprefixed) => spec.
		 */
		return (array) apply_filters( 'buddynext_member_erase_map', $map );
	}

	/**
	 * What is deliberately KEPT when a member is erased, and the reason.
	 *
	 * Erasure is not "delete every row with this user's id in it". Some rows are the site's record
	 * of what was done ABOUT the member by someone else; erasing those would let anyone delete the
	 * record of being actioned simply by deleting their account. Retained under GDPR Art. 17(3)(e)
	 * (establishment/exercise/defence of legal claims).
	 *
	 * This exists so a deliberate retention can be told apart from a bug — for a compliance path
	 * that distinction is the entire game, and the old code kept five tables while saying nothing.
	 *
	 * @return array<string, string> Table (unprefixed) => why it is kept.
	 */
	public static function retain_map(): array {
		return array(
			'bn_reports' => 'A report is a case FILED BY someone else about content. Deleting it would let a member erase the complaints against them. (Closed reports age out on their own retention schedule; a report the erased member FILED keeps their reporter_id until then — bn_reports has UNIQUE KEY one_per_reporter, so anonymising to 0 would collide the moment two erased members had reported the same post.)',
			'bn_mod_log' => 'The moderator audit trail — a record of what a MODERATOR did. Append-only by design (ModerationLogService).',
			'bn_spaces'  => 'A space is a community asset, not the member\'s data. SpaceSuccession reassigns owner_id to the longest-serving moderator (or a site admin) on the same purge signal, so the space outlives its founder rather than being deleted with them.',
		);
	}

	/**
	 * Count what is STILL in the database for this member, per table.
	 *
	 * This is what makes `done` mean something. Nothing verified the purge before, which is exactly
	 * why residue sat in three tables unnoticed: a monolithic function has no way to prove it
	 * covered everything, so nobody knew it had not.
	 *
	 * @param int $user_id Member.
	 * @return array<string, int> Table => rows remaining (zero-valued entries included).
	 */
	public function residue( int $user_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names and predicates come from the registry above, never from input; the id IS bound via prepare().
		$out = array();
		foreach ( self::erase_map() as $table => $spec ) {
			$where = (string) $spec['where'];

			$out[ $table ] = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE " . $where,
					...self::where_args( $user_id, $where )
				)
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// The bn_* usermeta footprint. A no-op on hard delete (core already removed the user's
		// meta) but the eraser keeps an anonymised user row, so this is real there.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$out['usermeta(bn_*)'] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			)
		);

		return $out;
	}

	/**
	 * Repeat the user id once per `%d` in a registry predicate.
	 *
	 * The two-sided predicates ("follower_id = %d OR following_id = %d") take the same id twice.
	 * Counting the placeholders keeps prepare()'s argument list correct without every caller
	 * having to know each predicate's shape.
	 *
	 * @param int    $user_id Member.
	 * @param string $where   Predicate from the registry.
	 * @return array<int,int>
	 */
	private static function where_args( int $user_id, string $where ): array {
		return array_fill( 0, max( 1, substr_count( $where, '%d' ) ), $user_id );
	}

	/**
	 * Render already-validated ids as a safe SQL `IN (...)` list.
	 *
	 * $wpdb->prepare() cannot expand an array placeholder, and these values never come from
	 * user input: every one is an id this class just SELECTed and cast with intval(), so the
	 * list can only ever contain integers.
	 *
	 * @param array<int,int> $ids Integer ids.
	 * @return string Comma-separated integers, e.g. "12,44,71".
	 */
	private static function id_list( array $ids ): string {
		return implode( ',', array_map( 'intval', $ids ) );
	}

	/**
	 * Apply a -1 counter delta to each peer through the canonical, cache-busting writer.
	 *
	 * Each edge is one row, so each peer loses exactly one. CounterService clamps at zero and
	 * invalidates the peer's user_meta cache — which is precisely why this is done per id
	 * rather than as one id-less `UPDATE ... JOIN`.
	 *
	 * @param mixed          $counters CounterService, when the container resolved one.
	 * @param array<int,int> $peers    Peer user ids.
	 * @param string         $meta_key Counter to decrement.
	 * @return void
	 */
	private function adjust_peers( $counters, array $peers, string $meta_key ): void {
		if ( ! is_object( $counters ) || ! method_exists( $counters, 'adjust_user_counter' ) ) {
			return;
		}

		foreach ( $peers as $peer ) {
			$counters->adjust_user_counter( (int) $peer, $meta_key, -1 );
		}
	}

	/**
	 * Reconcile and delete one relation in bounded chunks until none are left.
	 *
	 * $select must return a prepared SELECT that carries its own LIMIT; $handle reconciles the
	 * chunk's peers and DELETEs those same rows, returning how many it removed. Because each
	 * chunk is deleted as it is handled, the next SELECT simply returns the next survivors —
	 * no OFFSET to drift, and an interrupted purge resumes instead of double-counting.
	 *
	 * A chunk that reconciles peers but deletes nothing would re-select the same rows forever,
	 * so a zero-row delete ends the drain rather than spinning: better to leave a row behind
	 * for the retry (and the nightly recount) than to hang the request that is deleting a
	 * member.
	 *
	 * @param callable $select  Returns the prepared, LIMITed SELECT: fn(): string.
	 * @param callable $handle  Reconciles + deletes one chunk: fn(int[] $ids): int rows deleted.
	 * @param bool     $removed Set to true when anything was deleted.
	 * @return void
	 */
	private function drain_relation_chunks( callable $select, callable $handle, bool &$removed ): void {
		global $wpdb;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$ids     = array_map( 'intval', (array) $wpdb->get_col( $select() ) );
			$fetched = count( $ids );
			if ( 0 === $fetched ) {
				return;
			}

			$deleted = (int) $handle( $ids );
			if ( $deleted > 0 ) {
				$removed = true;
			}
		} while ( $deleted > 0 && self::RELATION_CHUNK === $fetched );
	}

	/**
	 * Purge a member completely — the public API, unchanged, and still synchronous for real people.
	 *
	 * Runs bounded slices until the member is gone or the time budget is spent. An ordinary member
	 * (a handful of posts) finishes in the first slice, exactly as before: the account-delete
	 * request returns with the member fully erased, and every listener — SpaceSuccession included —
	 * has already run. That matters. The tempting version of this change is "make the purge async,
	 * always", and it would quietly turn every listener into an eventual one, leaving a deleted
	 * owner's space pointing at a ghost until a queue happens to run.
	 *
	 * Only a member too big to finish in one request hands the remainder to Action Scheduler. An
	 * unfinished destructive process must never simply stop: core deleted the wp_users row before
	 * we were even called, so a purge that gives up halfway leaves data behind with no account left
	 * to find it by.
	 *
	 * @param int    $user_id Member being removed.
	 * @param string $context 'delete' (admin delete) | 'gdpr-erase' (privacy eraser).
	 *                        Both hard-delete; the value is passed through to the
	 *                        buddynext_purge_user_data event for addon context only.
	 * @return bool True when at least one row was removed.
	 */
	public function purge_user_relations( int $user_id, string $context = 'delete' ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		/**
		 * How long the purge may spend inside one request before deferring the rest.
		 *
		 * @param float  $seconds Default 10.
		 * @param int    $user_id Member being purged.
		 * @param string $context 'delete' | 'gdpr-erase'.
		 */
		$budget  = (float) apply_filters( 'buddynext_purge_time_budget', self::TIME_BUDGET, $user_id, $context );
		$started = microtime( true );
		$removed = false;

		do {
			$slice   = $this->purge_slice( $user_id, $context );
			$removed = $removed || $slice['removed'];

			if ( $slice['done'] ) {
				return $removed;
			}
		} while ( ( microtime( true ) - $started ) < $budget );

		// Out of time with the member still half-here. Hand the rest to a queue.
		$this->schedule_continuation( $user_id, $context );

		return $removed;
	}

	/**
	 * One bounded slice of the purge. Safe to call again; safe to call after a crash.
	 *
	 * Phase order is the contract, not a preference:
	 *
	 *   1. relations  — bounded, cheap, and everything the addons need to be true. Once.
	 *   2. addons     — the ONE signal every collaborator hangs off. Once, and BEFORE any content
	 *                   is touched, because the content cascade is the part that can die.
	 *   3. content    — one chunk of authored posts/comments. The unbounded part, now sliced.
	 *   4. finalize   — usermeta + profile values, once the content is actually gone.
	 *   5. verify     — COUNT(*) the erase registry. `done` is earned here, never asserted.
	 *
	 * @param int    $user_id Member being removed.
	 * @param string $context 'delete' | 'gdpr-erase'.
	 * @return array{done: bool, removed: bool} done=false means "call me again".
	 */
	public function purge_slice( int $user_id, string $context = 'delete' ): array {
		if ( $user_id <= 0 ) {
			return array(
				'done'    => true,
				'removed' => false,
			);
		}

		$state   = $this->state( $user_id );
		$removed = false;

		// 1. Relations. Bounded already, and idempotent — but re-running the whole sweep on every
		// slice of a 50k-post member would be ~25 no-op DELETEs per slice for nothing, so it is
		// marked done. If the verifier later finds residue it clears this flag and we try again.
		if ( empty( $state['relations_done'] ) ) {
			$removed                 = $this->purge_relations( $user_id );
			$state['relations_done'] = true;
			$this->save_state( $user_id, $state );
		}

		// 2. The addon seam. Exactly once per purge — not once per slice.
		//
		// The flag is written BEFORE the hook fires, deliberately. If a third-party listener
		// fatals, the retry skips it and the purge still completes; the alternative is a broken
		// addon holding a member's erasure hostage forever, retry after retry. Our completeness
		// must not depend on their correctness. A listener that fatals is a bug in that listener
		// and it will be in the error log.
		if ( empty( $state['addons_fired'] ) ) {
			$state['addons_fired'] = true;
			$this->save_state( $user_id, $state );

			/**
			 * Fires after BuddyNext has purged a removed member's relational rows.
			 *
			 * The canonical member-cleanup contract: fires on BOTH the hard-delete and the
			 * GDPR-erase path, so an addon cleans its own per-user tables on ONE signal
			 * regardless of how the member was removed.
			 *
			 * ORDER IS THE CONTRACT. It fires BEFORE the authored-content cascade, and before any
			 * slicing, and that is not a stylistic choice. Everything ahead of it is bounded;
			 * everything after it walks every post the member ever wrote. When this hook sat at
			 * the END of the old monolith, a timeout in that walk took every listener with it —
			 * SpaceSuccession never reassigned the spaces, Pro never cleaned its seven tables,
			 * media and messages stayed — on an account core had already deleted. A retry is not
			 * available to a listener that was never called.
			 *
			 * A try/finally cannot substitute for this: the failure mode is a fatal (OOM, or the
			 * execution-time limit), and PHP runs no finally block on a fatal. Order is the only
			 * protection there is.
			 *
			 * @param int    $user_id The removed member's id.
			 * @param string $context 'delete' | 'gdpr-erase'.
			 */
			do_action( 'buddynext_purge_user_data', $user_id, $context );
		}

		// 3. One chunk of authored content.
		$content = $this->purge_content_chunk( $user_id );
		$removed = $removed || $content['removed'];

		if ( ! $content['done'] ) {
			return array(
				'done'    => false,
				'removed' => $removed,
			);
		}

		// 4. Finalize — only now that the content is actually gone.
		$removed = $this->finalize( $user_id ) || $removed;

		// 5. Verify. This is the step that did not exist, and its absence is why residue sat in
		// three tables unnoticed: a monolith cannot prove it covered everything, so nobody knew.
		$residue = array_filter( $this->residue( $user_id ) );
		if ( array() !== $residue ) {
			$state['stuck'] = (int) ( $state['stuck'] ?? 0 ) + 1;

			if ( $state['stuck'] < self::MAX_STUCK_SLICES ) {
				// Genuinely retry: re-run the relational sweep rather than spin on the same state.
				$state['relations_done'] = false;
				$this->save_state( $user_id, $state );

				return array(
					'done'    => false,
					'removed' => $removed,
				);
			}

			// Refusing to loop forever is not the same as pretending we succeeded. Stop, and say
			// exactly what is left — an unregistered table, or an addon writing rows back.
			$this->clear_state( $user_id );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'BuddyNext member purge: gave up with residue for user %d after %d attempts: %s',
					$user_id,
					self::MAX_STUCK_SLICES,
					(string) wp_json_encode( $residue )
				)
			);

			return array(
				'done'    => true,
				'removed' => $removed,
			);
		}

		$this->clear_state( $user_id );

		return array(
			'done'    => true,
			'removed' => $removed,
		);
	}

	/**
	 * Every bounded, user-keyed row: counters reconciled, edges drained, registry swept.
	 *
	 * @param int $user_id Member being removed.
	 * @return bool True when at least one row was removed.
	 */
	private function purge_relations( int $user_id ): bool {
		global $wpdb;
		$p       = $wpdb->prefix;
		$removed = false;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Decrement member_count for the spaces the member actively belonged to,
		// BEFORE the membership rows are deleted, so directory totals stay honest.
		//
		// This one KEEPS the fetch-then-loop that the follow/connection counters below shed,
		// on purpose. Each space has a cache entry that must be individually invalidated, and
		// the ids are the only way to name it — a set-based UPDATE ... JOIN would leave every
		// one of those entries stale. The trade only works because the fan-out is small: a
		// member belongs to tens of spaces, not to 100k of them the way they can be followed
		// by 100k people. Judge the shape by the fan-out, not by symmetry with its neighbour.
		$active_space_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT space_id FROM {$p}bn_space_members WHERE user_id = %d AND status = 'active'",
				$user_id
			)
		);
		foreach ( $active_space_ids as $space_id ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$p}bn_spaces SET member_count = GREATEST(1, member_count) - 1 WHERE id = %d",
					(int) $space_id
				)
			);
			wp_cache_delete( 'space_' . (int) $space_id, 'bn_spaces' );
		}

		/*
		 * Relational edges: reconciled and deleted TOGETHER, in bounded chunks.
		 *
		 * This used to load every affected id into one PHP array first:
		 *
		 *     $reaction_post_ids     = get_col( SELECT DISTINCT object_id ... )   // unbounded
		 *     $peers_lose_follower   = get_col( SELECT following_id ... )         // unbounded
		 *     $peers_lose_following  = get_col( SELECT follower_id ... )          // unbounded
		 *     $peers_lose_connection = get_col( SELECT CASE ... )                 // unbounded
		 *
		 * ...then deleted the rows, then looped the arrays to fix the peers' counters. Those
		 * fetches sit between "core has already deleted the wp_users row" and "we delete their
		 * data". A member with 100k followers can exhaust memory right there: the account is
		 * gone, every bn_* table is still full, and there is no longer an account to find the
		 * residue by. A destructive path may be slow. It may never be partial.
		 *
		 * WHY NOT ONE SET-BASED `UPDATE ... JOIN` PER COUNTER, WITH NO IDS AT ALL?
		 *
		 * It looks like the obvious fix, and it is wrong. The counters are read through
		 * get_user_meta() and PostService's post cache, and the canonical writers —
		 * CounterService::adjust_user_counter() and PostService::recount_counters() — each
		 * invalidate those caches BY ID. A bare UPDATE ... JOIN cannot: it never learns which
		 * ids it touched. On any site with a persistent object cache the numbers in the
		 * database would be right and every peer would keep reading the old value, with no
		 * later write to correct them (the nightly recount_all_* jobs are set-based and do not
		 * bust caches either). Trading an OOM for a permanently stale counter is not a fix.
		 *
		 * So the ids are genuinely needed — just never all at once. Each pass takes a chunk,
		 * reconciles the peers in it through the canonical (cache-busting) writer, deletes that
		 * chunk, and repeats. Memory is bounded by RELATION_CHUNK, not by the member's degree.
		 *
		 * Deleting each chunk as it is reconciled also makes the loop naturally resumable: an
		 * edge is counted exactly once and then it is gone, so a re-run after an interruption
		 * picks up where it stopped instead of double-decrementing. That is the shape the
		 * scheduled purge process needs, and it is why the delete is here rather than batched
		 * into the set-based sweep below.
		 */
		$counters     = buddynext_service( 'counters' );
		$post_service = buddynext_service( 'post_service' );

		// Every member this member followed loses one follower.
		$this->drain_relation_chunks(
			static fn(): string => $wpdb->prepare(
				"SELECT following_id FROM {$p}bn_follows WHERE follower_id = %d AND status = 'approved' LIMIT %d",
				$user_id,
				self::RELATION_CHUNK
			),
			function ( array $peers ) use ( $wpdb, $p, $user_id, $counters ): int {
				$this->adjust_peers( $counters, $peers, 'bn_follower_count' );

				return (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$p}bn_follows WHERE follower_id = %d AND following_id IN ( " . self::id_list( $peers ) . ' )',
						$user_id
					)
				);
			},
			$removed
		);

		// Every member who followed this member is now following one fewer.
		$this->drain_relation_chunks(
			static fn(): string => $wpdb->prepare(
				"SELECT follower_id FROM {$p}bn_follows WHERE following_id = %d AND status = 'approved' LIMIT %d",
				$user_id,
				self::RELATION_CHUNK
			),
			function ( array $peers ) use ( $wpdb, $p, $user_id, $counters ): int {
				$this->adjust_peers( $counters, $peers, 'bn_following_count' );

				return (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$p}bn_follows WHERE following_id = %d AND follower_id IN ( " . self::id_list( $peers ) . ' )',
						$user_id
					)
				);
			},
			$removed
		);

		// Every accepted connection peer loses one connection. A connection is a single row
		// shared by both sides, so the peer is whichever end of it is not the erased member.
		$this->drain_relation_chunks(
			static fn(): string => $wpdb->prepare(
				"SELECT id FROM {$p}bn_connections WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted' LIMIT %d",
				$user_id,
				$user_id,
				self::RELATION_CHUNK
			),
			function ( array $connection_ids ) use ( $wpdb, $p, $user_id, $counters ): int {
				$peers = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END
						   FROM {$p}bn_connections WHERE id IN ( " . self::id_list( $connection_ids ) . ' )',
						$user_id
					)
				);
				$this->adjust_peers( $counters, array_map( 'intval', (array) $peers ), 'bn_connection_count' );

				return (int) $wpdb->query( "DELETE FROM {$p}bn_connections WHERE id IN ( " . self::id_list( $connection_ids ) . ' )' );
			},
			$removed
		);

		// Posts this member reacted to lose that reaction. recount_counters() recomputes each
		// post's totals from the rows that remain, so the chunk is deleted FIRST and recounted
		// after — and it invalidates each post's cache entry by id, which is the whole reason
		// this pass needs the ids at all.
		$this->drain_relation_chunks(
			static fn(): string => $wpdb->prepare(
				"SELECT DISTINCT object_id FROM {$p}bn_reactions WHERE user_id = %d AND object_type = 'post' LIMIT %d",
				$user_id,
				self::RELATION_CHUNK
			),
			function ( array $post_ids ) use ( $wpdb, $p, $user_id, $post_service ): int {
				$affected = (int) $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$p}bn_reactions WHERE user_id = %d AND object_type = 'post' AND object_id IN ( " . self::id_list( $post_ids ) . ' )',
						$user_id
					)
				);

				if ( is_object( $post_service ) && method_exists( $post_service, 'recount_counters' ) ) {
					$post_service->recount_counters( $post_ids );
				}

				return $affected;
			},
			$removed
		);

		// Sweep every table on the ERASE registry that can be cleared with a plain DELETE.
		//
		// This list used to be written out here by hand, and the verifier — the thing that proves
		// the purge worked — would have been a SECOND hand-written list. That is precisely how
		// bn_activity_log, bn_email_log and bn_webhook_log went unpurged: a table was added, and
		// nothing forced anyone to add it here. Two hand-maintained lists drift; one cannot.
		//
		// So both are generated from erase_map(). A table on the registry is deleted here AND
		// counted by residue(), and `done` is not reported until that count is zero. Adding a
		// user-keyed table without registering it now fails the purge loudly instead of leaking
		// quietly.
		$queries = array();
		foreach ( self::erase_map() as $table => $spec ) {
			if ( empty( $spec['sweep'] ) ) {
				continue; // Cascaded or delegated — see the registry's notes.
			}

			$queries[] = $wpdb->prepare(
				"DELETE FROM {$p}{$table} WHERE " . $spec['where'], // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry-owned constant fragments, never user input.
				...self::where_args( $user_id, (string) $spec['where'] )
			);
		}
		foreach ( $queries as $sql ) {
			if ( (int) $wpdb->query( $sql ) > 0 ) {
				$removed = true;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return $removed;
	}

	/**
	 * Delete ONE chunk of the member's authored content.
	 *
	 * This is the part that cannot be set-based and cannot be bounded away. Each post goes through
	 * PostService::delete(), which cascades that post's reactions, comments, poll options/votes,
	 * shares and hashtag links — a bare `DELETE FROM bn_posts` would orphan every one of them. So
	 * it stays a loop; it just stops being an unbounded one. A 50k-post member is now 500 slices
	 * that each finish, instead of one request that never does.
	 *
	 * Comments the member left on OTHER people's posts are removed the same way, and those posts'
	 * comment_count reconciled through the canonical recounter (which invalidates by id).
	 *
	 * @param int $user_id Member being removed.
	 * @return array{done: bool, removed: bool} done=true when no content is left.
	 */
	private function purge_content_chunk( int $user_id ): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$removed = false;

		/**
		 * How many authored posts to cascade per slice.
		 *
		 * @param int $chunk Default 100.
		 */
		$chunk = max( 1, (int) apply_filters( 'buddynext_purge_post_chunk', self::POST_CHUNK ) );

		$post_service = buddynext_service( 'post_service' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$own_post_ids = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare( "SELECT id FROM {$p}bn_posts WHERE user_id = %d LIMIT %d", $user_id, $chunk )
			)
		);

		if ( array() !== $own_post_ids && is_object( $post_service ) ) {
			foreach ( $own_post_ids as $pid ) {
				$post_service->delete( $pid, $user_id );
			}
			$removed = true;
		}

		// Their comments on other people's posts, one chunk at a time, recounting the posts they
		// were removed from.
		$commented_on = array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT object_id FROM {$p}bn_comments WHERE user_id = %d AND object_type = 'post' LIMIT %d",
					$user_id,
					$chunk
				)
			)
		);

		if ( array() !== $commented_on ) {
			$deleted = (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$p}bn_comments WHERE user_id = %d AND object_type = 'post' AND object_id IN ( " . self::id_list( $commented_on ) . ' )',
					$user_id
				)
			);
			if ( $deleted > 0 ) {
				$removed = true;
			}

			if ( is_object( $post_service ) && method_exists( $post_service, 'recount_counters' ) ) {
				$post_service->recount_counters( $commented_on );
			}
		}

		// Comments on anything that is not a post (and any the chunk above did not match) — small,
		// and swept once the post-attached ones are drained, so nothing is left keyed to the member.
		$other_comments = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_comments WHERE user_id = %d AND object_type <> 'post'", $user_id )
		);
		if ( $other_comments > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_comments WHERE user_id = %d AND object_type <> 'post'", $user_id ) );
			$removed = true;
		}

		$posts_left    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_posts WHERE user_id = %d", $user_id ) );
		$comments_left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}bn_comments WHERE user_id = %d", $user_id ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'done'    => ( 0 === $posts_left && 0 === $comments_left ),
			'removed' => $removed,
		);
	}

	/**
	 * The last pass: the member's own meta footprint, once their content is gone.
	 *
	 * @param int $user_id Member being removed.
	 * @return bool True when at least one row was removed.
	 */
	private function finalize( int $user_id ): bool {
		global $wpdb;

		// Sweep every bn_* usermeta row. A no-op on hard delete (core already removed the user's
		// meta), but the eraser keeps an anonymised user row, so this is what actually wipes their
		// bn_* meta footprint there.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			)
		) > 0;

		// Profile-field values + their searchable mirror/cache are owned by the Profile service —
		// delegate rather than reach into bn_profile_values here.
		( new ProfileService() )->delete_user_values( $user_id );

		clean_user_cache( $user_id );

		return $removed;
	}

	/*
	─────────────────────────────────────────────────────────────────────────────────
	 * Process state + continuation
	 * ───────────────────────────────────────────────────────────────────────────────
	 */

	/**
	 * Read this member's purge state.
	 *
	 * @param int $user_id Member.
	 * @return array<string, mixed>
	 */
	private function state( int $user_id ): array {
		return (array) get_option( self::STATE_PREFIX . $user_id, array() );
	}

	/**
	 * Persist this member's purge state.
	 *
	 * Never autoloaded: it exists for seconds on an ordinary member, and only long enough to
	 * finish on a large one.
	 *
	 * @param int                  $user_id Member.
	 * @param array<string, mixed> $state   State.
	 * @return void
	 */
	private function save_state( int $user_id, array $state ): void {
		update_option( self::STATE_PREFIX . $user_id, $state, false );
	}

	/**
	 * Drop this member's purge state — the process is over.
	 *
	 * @param int $user_id Member.
	 * @return void
	 */
	private function clear_state( int $user_id ): void {
		delete_option( self::STATE_PREFIX . $user_id );
	}

	/**
	 * Hand the unfinished remainder of a purge to Action Scheduler.
	 *
	 * Guarded, so a member cannot accumulate one continuation per attempt: core's GDPR eraser will
	 * call us again on its own (its paging loop IS a chunking driver), and each of those calls
	 * would otherwise queue another action for work that is already queued.
	 *
	 * With Action Scheduler absent there is nothing to defer to, and finishing the job matters more
	 * than finishing it quickly — so we say so, loudly, rather than dropping a half-erased member
	 * on the floor. AS ships bundled with BuddyNext, so this is the "someone unbundled it" path.
	 *
	 * @param int    $user_id Member being removed.
	 * @param string $context 'delete' | 'gdpr-erase'.
	 * @return void
	 */
	private function schedule_continuation( int $user_id, string $context ): void {
		$args = array( $user_id, $context );

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'BuddyNext member purge: user %d is not fully erased and Action Scheduler is unavailable to finish it. Re-run: wp buddynext purge-member %d',
					$user_id,
					$user_id
				)
			);

			return;
		}

		if ( false !== as_next_scheduled_action( self::CONTINUE_HOOK, $args, self::AS_GROUP ) ) {
			return; // Already queued.
		}

		as_enqueue_async_action( self::CONTINUE_HOOK, $args, self::AS_GROUP );
	}

	/**
	 * Action Scheduler entry point: keep purging this member until they are gone.
	 *
	 * Re-enters the same budgeted loop, which re-queues itself if it runs out of time again. Each
	 * pass gets a fresh request, so a member of any size converges.
	 *
	 * @param int    $user_id Member being removed.
	 * @param string $context 'delete' | 'gdpr-erase'.
	 * @return void
	 */
	public function continue_purge( int $user_id, string $context = 'delete' ): void {
		$this->purge_user_relations( $user_id, $context );
	}
}
