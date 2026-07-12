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
	 * Purge all of a member's relational rows across every BuddyNext table.
	 *
	 * Decrements the denormalised `bn_spaces.member_count` for the member's active
	 * spaces before their membership rows go away, captures the posts they reacted
	 * to so `reaction_count` can be reconciled after, then deletes every user-keyed
	 * row AND hard-deletes their authored posts + comments (standard GDPR erasure —
	 * uniform across both delete paths; content is never reassigned/kept). Finally
	 * fires the canonical `buddynext_purge_user_data` extension event so addons clean
	 * their own per-user tables on the SAME signal regardless of how the member was
	 * removed.
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

		// The complete user-keyed table set. Two-direction relations match either
		// side; search-index removal covers both the member's own entry and every
		// row they authored.
		$queries = array(
			$wpdb->prepare( "DELETE FROM {$p}bn_follows WHERE follower_id = %d OR following_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_connections WHERE requester_id = %d OR recipient_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_blocks WHERE blocker_id = %d OR blocked_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_space_members WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_space_bans WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_hashtag_follows WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notification_prefs WHERE user_id = %d", $user_id ),
			// Verification tokens: without this a deleted user's tokens lingered
			// until the expiry cron - a gap in the uniform hard-delete contract.
			$wpdb->prepare( "DELETE FROM {$p}bn_verify_tokens WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_notifications WHERE recipient_id = %d OR sender_id = %d", $user_id, $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_user_strikes WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_user_suspensions WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_appeals WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_bookmarks WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_reactions WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_poll_votes WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_shares WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_member_type_assignments WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_presence WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_search_index WHERE author_id = %d OR ( object_type = 'member' AND object_id = %d )", $user_id, $user_id ),

			// The member's own log rows. These were missing from the purge entirely.
			//
			// They are not permanent residue — the retention crons age them out (activity 365d,
			// email 60-365d, webhook 30d). But GDPR requires erasure "without undue delay", and a
			// YEAR is not without undue delay. A member who asks to be forgotten should not sit in
			// the activity log until next summer.
			//
			// bn_mod_log and bn_reports are deliberately NOT here — see the retention policy in the
			// class docblock. Those record what a MODERATOR did, or a case against someone else;
			// erasing them would let a member erase the record of being actioned.
			$wpdb->prepare( "DELETE FROM {$p}bn_activity_log WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_email_log WHERE user_id = %d", $user_id ),
			$wpdb->prepare( "DELETE FROM {$p}bn_webhook_log WHERE user_id = %d", $user_id ),
		);
		foreach ( $queries as $sql ) {
			if ( (int) $wpdb->query( $sql ) > 0 ) {
				$removed = true;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		/**
		 * Fires after BuddyNext has purged a removed member's relational rows.
		 *
		 * The canonical member-cleanup contract: fires on BOTH the hard-delete and
		 * the GDPR-erase path so an addon cleans its own per-user tables on ONE
		 * signal, regardless of how the member was removed.
		 *
		 * ORDER IS THE CONTRACT. This fires BEFORE the authored-content cascade below,
		 * and it is not a stylistic choice.
		 *
		 * Everything above this line is set-based: a fixed number of statements no
		 * matter how big the member is. Everything below is not — the cascade walks
		 * every post the member ever wrote, one PostService::delete() per row. A member
		 * with 50k posts can time out or exhaust memory in there.
		 *
		 * When this hook sat at the END of the method, that timeout took every listener
		 * with it: SpaceSuccession never reassigned the member's spaces, Pro never
		 * cleaned its seven tables, media and messages stayed. The member's account was
		 * already gone (core deletes wp_users first), so there was no longer an account
		 * to find the residue by. A retry is not available to a listener that was never
		 * called.
		 *
		 * A try/finally cannot fix this. The failure mode is a fatal — OOM or the
		 * execution-time limit — and PHP does not run finally blocks on a fatal. Order
		 * is the only protection there is, so the cheap, bounded, must-not-be-skipped
		 * work goes first.
		 *
		 * No listener depends on the cascade having finished: they clean their own
		 * per-user tables, keyed by user_id, not by BuddyNext's content.
		 *
		 * @param int    $user_id The removed member's id.
		 * @param string $context 'delete' | 'gdpr-erase'.
		 */
		do_action( 'buddynext_purge_user_data', $user_id, $context );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Authored posts + comments are hard-deleted with the member — standard GDPR
		// erasure: the person's content goes with the person, on BOTH the admin-delete
		// and the GDPR-eraser paths (it is never reassigned to a tombstone). They are
		// deliberately kept out of the user-keyed delete set above so this runs through
		// PostService::delete, which cascades each post's child rows (reactions,
		// comments, poll options/votes, shares, hashtag links) — a bare table DELETE
		// would orphan those. Comments the member left on OTHERS' posts are then removed
		// and those posts' comment_count reconciled.
		$own_post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}bn_posts WHERE user_id = %d", $user_id ) );
		$commented_on = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_id FROM {$p}bn_comments WHERE user_id = %d AND object_type = 'post'", $user_id ) );
		$post_service = buddynext_service( 'post_service' );
		if ( is_object( $post_service ) ) {
			foreach ( $own_post_ids as $pid ) {
				$post_service->delete( (int) $pid, $user_id );
			}
		}
		if ( (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$p}bn_comments WHERE user_id = %d", $user_id ) ) > 0 || ! empty( $own_post_ids ) ) {
			$removed = true;
		}
		if ( ! empty( $commented_on ) && is_object( $post_service ) && method_exists( $post_service, 'recount_counters' ) ) {
			$post_service->recount_counters( array_map( 'intval', $commented_on ) );
		}

		// Sweep every bn_* usermeta row. A no-op on hard delete (core already removed
		// the user's meta), but the eraser keeps an anonymised user row, so this is
		// what actually wipes their bn_* meta footprint there.
		$deleted_meta = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key LIKE %s",
				$user_id,
				$wpdb->esc_like( 'bn_' ) . '%'
			)
		);
		if ( $deleted_meta > 0 ) {
			$removed = true;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		// Profile-field values + their searchable mirror/cache are owned by the
		// Profile service — delegate rather than reach into bn_profile_values here.
		( new ProfileService() )->delete_user_values( $user_id );

		clean_user_cache( $user_id );

		return $removed;
	}
}
