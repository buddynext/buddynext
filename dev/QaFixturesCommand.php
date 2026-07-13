<?php
/**
 * QA fixtures — deterministic edge-case and scale data for local verification.
 *
 * DEV-ONLY. This directory is NOT on bin/build-release.sh's RUNTIME allowlist,
 * so it never reaches a customer. Plugin.php requires it behind a file_exists()
 * check, so on a packaged install the command simply does not register.
 *
 * WHY THIS EXISTS
 * ---------------
 * `wp buddynext demo` seeds the *customer* demo: a community that should look
 * healthy and appealing. It must never contain expired invites, orphaned space
 * owners, or cancelled subscriptions — a site owner clicking "Load demo data"
 * would see a broken product.
 *
 * But QA needs exactly those broken states, and until now every verification run
 * hand-rolled them: a throwaway user here, a backdated row there. That is how a
 * 2026-07-13 QA pass corrupted `bn_spaces.member_count` (a raw DELETE that never
 * decremented the counter), and how six audit cards ended up quoting space IDs
 * that did not exist on any other machine.
 *
 * So: the demo stays pretty, and the ugly states live here — deterministic,
 * tagged, and removable.
 *
 * PROFILES
 *   edge   Broken/boundary states a correctness test needs (invites, archived
 *          and orphaned spaces, the subscription+invoice matrix, rows backdated
 *          across the 30/60/90 retention windows, open + resolved reports).
 *   scale  The big-site-readiness numbers (a 6k-follower celebrity, a 6,025-comment
 *          viral thread, 2k posts, 2k bookmarks) so nobody hand-seeds them again
 *          to prove a query is bounded.
 *
 * Everything is tagged (`qa_fx_` logins, `qa-fx-` slugs, a manifest option), so
 * `cleanup` removes exactly what it created and nothing else — then recomputes
 * the counters a raw delete would otherwise leave skewed.
 *
 * @package BuddyNext
 */

namespace BuddyNext\Dev;

use BuddyNext\Spaces\SpaceService;

/**
 * `wp buddynext qa-fixtures` — seed / cleanup / status.
 */
final class QaFixturesCommand {

	/** Manifest option holding every id this command created. */
	private const MANIFEST = 'bn_qa_fixtures_manifest';

	/** Login prefix for fixture users. Cleanup keys off this. */
	private const USER_PREFIX = 'qa_fx_';

	/** Slug prefix for fixture spaces. */
	private const SLUG_PREFIX = 'qa-fx-';

	/** Scale sizes. Big enough to expose an unbounded query, small enough to seed fast. */
	private const SCALE_FOLLOWERS = 6000;
	private const SCALE_REPLIES   = 6000;
	private const SCALE_POSTS     = 2000;

	/** Bookmarks are bounded by SCALE_POSTS — you cannot bookmark a post that does not exist. */
	private const SCALE_BOOKMARKS = self::SCALE_POSTS;

	/**
	 * Seed QA fixtures.
	 *
	 * ## OPTIONS
	 *
	 * [--profile=<profile>]
	 * : Which fixtures to create.
	 * ---
	 * default: edge
	 * options:
	 *   - edge
	 *   - scale
	 *   - all
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp buddynext qa-fixtures seed
	 *     wp buddynext qa-fixtures seed --profile=all
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function seed( array $args, array $assoc_args ): void {
		$profile = (string) ( $assoc_args['profile'] ?? 'edge' );
		$m       = $this->manifest();

		if ( 'edge' === $profile || 'all' === $profile ) {
			$this->seed_edge( $m );
		}
		if ( 'scale' === $profile || 'all' === $profile ) {
			$this->seed_scale( $m );
		}

		update_option( self::MANIFEST, $m, false );
		$this->recount_spaces();
		\WP_CLI::success( sprintf( 'QA fixtures seeded (profile: %s).', $profile ) );
		$this->status();
	}

	/**
	 * Remove every fixture this command created, then repair counters.
	 *
	 * Takes no parameters: WP-CLI passes ($args, $assoc_args) and PHP discards
	 * extras on a non-variadic method, so declaring unused ones is dead weight.
	 *
	 * @return void
	 */
	public function cleanup(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$m = $this->manifest();

		require_once ABSPATH . 'wp-admin/includes/user.php';

		// Rows first, then spaces, then users — so ownership checks still pass and
		// nothing is orphaned mid-teardown.
		$wpdb->query( "DELETE FROM {$p}bn_invites WHERE token LIKE 'QAFX%'" ); // phpcs:ignore WordPress.DB
		$wpdb->query( "DELETE FROM {$p}bn_reports WHERE notes LIKE 'qa-fx:%'" ); // phpcs:ignore WordPress.DB

		$spaces = new SpaceService();
		foreach ( (array) ( $m['spaces'] ?? array() ) as $sid ) {
			$sid = (int) $sid;
			if ( $sid > 0 ) {
				// Through the service so member rows / counters unwind properly.
				$spaces->delete( $sid, 1 );
				// An orphan-owner fixture may refuse the service path; force it.
				$wpdb->delete( $p . 'bn_spaces', array( 'id' => $sid ), array( '%d' ) );
				$wpdb->delete( $p . 'bn_space_members', array( 'space_id' => $sid ), array( '%d' ) );
			}
		}

		$users = get_users(
			array(
				'search'         => self::USER_PREFIX . '*',
				'search_columns' => array( 'user_login' ),
				'fields'         => 'ID',
				'number'         => 100000,
			)
		);
		foreach ( $users as $uid ) {
			wp_delete_user( (int) $uid );
		}

		// Anything the scale pass inserted straight into the graph tables for speed.
		foreach ( (array) ( $m['posts'] ?? array() ) as $pid ) {
			$pid = (int) $pid;
			$wpdb->delete(
				$p . 'bn_comments',
				array(
					'object_id'   => $pid,
					'object_type' => 'post',
				),
				array( '%d', '%s' )
			);
			$wpdb->delete( $p . 'bn_bookmarks', array( 'post_id' => $pid ), array( '%d' ) );
			$wpdb->delete( $p . 'bn_posts', array( 'id' => $pid ), array( '%d' ) );
		}

		delete_option( self::MANIFEST );

		// The whole point of the tagged teardown: leave the counters honest. A raw
		// DELETE does not fire adjust_member_count(), which is how a previous QA run
		// left every space reporting a member count it no longer had.
		$this->recount_spaces();

		\WP_CLI::success( sprintf( 'QA fixtures removed (%d users, %d spaces). Space counters recomputed.', count( $users ), count( (array) ( $m['spaces'] ?? array() ) ) ) );
	}

	/**
	 * Show what is currently seeded.
	 *
	 * @return void
	 */
	public function status(): void {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = array();

		$q = static function ( string $sql ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB
		};

		$rows[] = array( 'fixture users', $q( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE 'qa\_fx\_%'" ) );
		$rows[] = array( 'fixture spaces', $q( "SELECT COUNT(*) FROM {$p}bn_spaces WHERE slug LIKE 'qa-fx-%'" ) );
		$rows[] = array( 'archived spaces', $q( "SELECT COUNT(*) FROM {$p}bn_spaces WHERE is_archived = 1" ) );
		$rows[] = array( 'sub-spaces', $q( "SELECT COUNT(*) FROM {$p}bn_spaces WHERE parent_id IS NOT NULL" ) );
		$rows[] = array( 'invites (pending)', $q( "SELECT COUNT(*) FROM {$p}bn_invites WHERE status = 'pending'" ) );
		$rows[] = array( 'invites (expired)', $q( "SELECT COUNT(*) FROM {$p}bn_invites WHERE expires_at < UTC_TIMESTAMP()" ) );
		$rows[] = array( 'reports (open)', $q( "SELECT COUNT(*) FROM {$p}bn_reports WHERE status = 'pending'" ) );
		$rows[] = array( 'reports (resolved)', $q( "SELECT COUNT(*) FROM {$p}bn_reports WHERE status <> 'pending'" ) );
		$rows[] = array( 'notifs > 90d old', $q( "SELECT COUNT(*) FROM {$p}bn_notifications WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)" ) );
		$rows[] = array( 'email log > 90d old', $q( "SELECT COUNT(*) FROM {$p}bn_email_log WHERE sent_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)" ) );

		if ( $this->table_exists( $p . 'bn_subscriptions' ) ) {
			$rows[] = array( 'subs (active)', $q( "SELECT COUNT(*) FROM {$p}bn_subscriptions WHERE status = 'active'" ) );
			$rows[] = array( 'subs (cancelled)', $q( "SELECT COUNT(*) FROM {$p}bn_subscriptions WHERE status = 'cancelled'" ) );
			$rows[] = array( 'subs (expired)', $q( "SELECT COUNT(*) FROM {$p}bn_subscriptions WHERE status = 'expired'" ) );
			$rows[] = array( 'invoices w/o txn', $q( "SELECT COUNT(*) FROM {$p}bn_invoices WHERE meta IS NULL OR meta NOT LIKE '%transaction_id%'" ) );
		}

		foreach ( $rows as $row ) {
			\WP_CLI::log( sprintf( '  %-22s %s', (string) $row[0], (string) $row[1] ) );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Edge profile
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Broken/boundary states. Each one maps to a bug class QA has actually hit.
	 *
	 * @param array<string,mixed> $m Manifest, by reference via array return.
	 * @return void
	 */
	private function seed_edge( array &$m ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		\WP_CLI::log( 'Edge fixtures…' );

		$owner  = $this->make_user( 'owner', 'QA Owner' );
		$doomed = $this->make_user( 'doomed', 'QA Doomed Owner' );
		$mod    = $this->make_user( 'heir', 'QA Heir' );
		$member = $this->make_user( 'member', 'QA Member' );

		$spaces = new SpaceService();

		// 1. Archived space — the archive/unarchive + directory-exclusion path.
		$archived = $spaces->create(
			$owner,
			array(
				'name' => 'QA Archived Space',
				'slug' => self::SLUG_PREFIX . 'archived',
				'type' => 'open',
			)
		);
		if ( is_int( $archived ) && $archived > 0 ) {
			$spaces->archive( $archived, $owner );
			$m['spaces'][] = $archived;
			\WP_CLI::log( "  archived space #$archived" );
		}

		// 2. Orphan-owner space — owner_id pointing at a user that does not exist.
		// This is what `wp buddynext repair-space-owners` is for, and QA had to
		// hand-build it every time.
		$orphan = $spaces->create(
			$owner,
			array(
				'name' => 'QA Orphan Owner Space',
				'slug' => self::SLUG_PREFIX . 'orphan-owner',
				'type' => 'open',
			)
		);
		if ( is_int( $orphan ) && $orphan > 0 ) {
			$wpdb->update( $p . 'bn_spaces', array( 'owner_id' => 999999 ), array( 'id' => $orphan ), array( '%d' ), array( '%d' ) );
			$wpdb->delete(
				$p . 'bn_space_members',
				array(
					'space_id' => $orphan,
					'role'     => 'owner',
				),
				array( '%d', '%s' )
			);
			$m['spaces'][] = $orphan;
			\WP_CLI::log( "  orphan-owner space #$orphan (owner_id=999999, 0 owner rows)" );
		}

		// 3. Succession space — a real owner with a real moderator heir, so
		// deleting the owner exercises SpaceSuccession rather than the fallback.
		$succ = $spaces->create(
			$doomed,
			array(
				'name' => 'QA Succession Space',
				'slug' => self::SLUG_PREFIX . 'succession',
				'type' => 'open',
			)
		);
		if ( is_int( $succ ) && $succ > 0 ) {
			$wpdb->insert(
				$p . 'bn_space_members',
				array(
					'space_id'   => $succ,
					'user_id'    => $mod,
					'role'       => 'moderator',
					'status'     => 'active',
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%d', '%d', '%s', '%s', '%s' )
			);
			$m['spaces'][] = $succ;
			\WP_CLI::log( "  succession space #$succ (owner $doomed, heir $mod)" );
		}

		// 4. Invites — every state the redemption path can meet, including one bound
		// to a space (the case where redemption must also JOIN that space).
		$now     = time();
		$invites = array(
			array( 'QAFX-PENDING-0001', 'qa-invitee-pending@example.test', 'pending', $now + 7 * DAY_IN_SECONDS, 0 ),
			array( 'QAFX-EXPIRED-0002', 'qa-invitee-expired@example.test', 'pending', $now - 2 * DAY_IN_SECONDS, 0 ),
			array( 'QAFX-USED-0003', 'qa-invitee-used@example.test', 'registered', $now + 7 * DAY_IN_SECONDS, 0 ),
			array( 'QAFX-SPACE-0004', 'qa-invitee-space@example.test', 'pending', $now + 7 * DAY_IN_SECONDS, $succ ),
		);
		foreach ( $invites as $inv ) {
			$wpdb->insert(
				$p . 'bn_invites',
				array(
					'email'      => $inv[1],
					'first_name' => 'QA',
					'space_id'   => $inv[4] > 0 ? $inv[4] : null,
					'token'      => $inv[0],
					'status'     => $inv[2],
					'expires_at' => gmdate( 'Y-m-d H:i:s', $inv[3] ),
					'created_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);
		}
		\WP_CLI::log( '  invites: pending / expired / redeemed / space-bound' );

		// 5. Retention rows — straddling the 30/60/90 windows, read AND unread,
		// because the retention rule keeps unread notifications until the 90d
		// hard max. Without these, the purge job can only be tested by hand.
		foreach ( array( 10, 40, 70, 100 ) as $age ) {
			foreach ( array( 0, 1 ) as $is_read ) {
				$wpdb->insert(
					$p . 'bn_notifications',
					array(
						'recipient_id' => $member,
						'sender_id'    => $owner,
						'type'         => 'bn.qa_fixture',
						'is_read'      => $is_read,
						'created_at'   => gmdate( 'Y-m-d H:i:s', $now - $age * DAY_IN_SECONDS ),
					),
					array( '%d', '%d', '%s', '%d', '%s' )
				);
			}
			$wpdb->insert(
				$p . 'bn_email_log',
				array(
					'user_id' => $member,
					'type'    => 'bn.qa_fixture',
					'sent_at' => gmdate( 'Y-m-d H:i:s', $now - $age * DAY_IN_SECONDS ),
				),
				array( '%d', '%s', '%s' )
			);
		}
		\WP_CLI::log( '  notifications + email log backdated 10/40/70/100 days (read + unread)' );

		// 6. Moderation queue — an open case and a closed one, so retention can be
		// proven to keep the open case and prune only the resolved one.
		//
		// NOTE the distinct object_id per row: bn_reports carries
		// UNIQUE one_per_reporter (reporter_id, object_type, object_id) — one
		// member may report a given object once. Reusing the object silently
		// drops the second row (wpdb->insert returns false, nothing throws),
		// which is exactly how the first cut of this fixture "seeded" a resolved
		// report that was never there.
		foreach ( array( array( 'pending', 5, 999901 ), array( 'resolved', 400, 999902 ) ) as $r ) {
			$wpdb->insert(
				$p . 'bn_reports',
				array(
					'reporter_id' => $member,
					'object_type' => 'post',
					'object_id'   => (int) $r[2],
					'reason'      => 'spam',
					'notes'       => 'qa-fx: ' . $r[0],
					'status'      => $r[0],
					'resolved_by' => 'resolved' === $r[0] ? 1 : null,
					'resolved_at' => 'resolved' === $r[0] ? gmdate( 'Y-m-d H:i:s', $now - 399 * DAY_IN_SECONDS ) : null,
					'created_at'  => gmdate( 'Y-m-d H:i:s', $now - (int) $r[1] * DAY_IN_SECONDS ),
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
			);
		}
		\WP_CLI::log( '  reports: 1 open (5d), 1 resolved (400d)' );

		// 7. Money matrix (Pro only). The PayPal-renewal bug shipped because no
		// fixture existed for "invoice written with no transaction reference",
		// so refund_invoice() was never exercised against one.
		if ( $this->table_exists( $p . 'bn_subscriptions' ) && $this->table_exists( $p . 'bn_invoices' ) ) {
			$buyer  = $this->make_user( 'buyer', 'QA Buyer' );
			$matrix = array(
				array( 'stripe', 'active', '{"transaction_id":"ch_qafx_active"}' ),
				array( 'stripe', 'cancelled', '{"transaction_id":"ch_qafx_cancelled"}' ),
				array( 'stripe', 'expired', '{"transaction_id":"ch_qafx_expired"}' ),
				array( 'paypal', 'active', null ), // ← the bug shape: no txn ref.
				array( 'paypal', 'active', '{"transaction_id":"PAYID-QAFX"}' ),
			);
			foreach ( $matrix as $i => $row ) {
				$wpdb->insert(
					$p . 'bn_subscriptions',
					array(
						'user_id'     => $buyer,
						'tier_id'     => 1,
						'status'      => $row[1],
						'source'      => $row[0],
						'started_at'  => gmdate( 'Y-m-d H:i:s', $now - 60 * DAY_IN_SECONDS ),
						'expires_at'  => gmdate( 'Y-m-d H:i:s', $now + 30 * DAY_IN_SECONDS ),
						'external_id' => 'qafx_' . $row[0] . '_' . $i,
						'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
				$wpdb->insert(
					$p . 'bn_invoices',
					array(
						'user_id'    => $buyer,
						'plan_id'    => 1,
						'gateway'    => $row[0],
						'amount'     => '5.00',
						'total'      => '5.00',
						'currency'   => 'USD',
						'status'     => 'paid',
						'number'     => 'QAFX-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ),
						'created_at' => gmdate( 'Y-m-d H:i:s' ),
						'meta'       => $row[2],
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
			\WP_CLI::log( '  subscriptions + invoices: stripe/paypal x active/cancelled/expired, incl. one invoice with NO transaction_id' );
		} else {
			\WP_CLI::log( '  (Pro money tables absent — skipped the subscription/invoice matrix)' );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Scale profile
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * The big-site-readiness numbers. Batched inserts — a service call per row
	 * would take minutes and prove nothing extra.
	 *
	 * @param array<string,mixed> $m Manifest.
	 * @return void
	 */
	private function seed_scale( array &$m ): void {
		global $wpdb;
		$p = $wpdb->prefix;

		\WP_CLI::log( 'Scale fixtures (this takes a minute)…' );

		$celeb          = $this->make_user( 'celebrity', 'QA Celebrity' );
		$m['celebrity'] = $celeb;

		// A 6k-follower celebrity: the shape that made ProfileNav load every row.
		// Follower ids are synthetic (no wp_users rows) on purpose — we are testing
		// that the QUERY is bounded, not that 6,000 accounts render.
		$now = gmdate( 'Y-m-d H:i:s' );
		for ( $b = 0; $b < self::SCALE_FOLLOWERS; $b += 500 ) {
			$vals = array();
			$end  = min( $b + 500, self::SCALE_FOLLOWERS );
			for ( $i = $b; $i < $end; $i++ ) {
				$fid    = 900000 + $i;
				$vals[] = $wpdb->prepare( '(%d,%d,%s,%s)', $fid, $celeb, 'active', $now );
				$vals[] = $wpdb->prepare( '(%d,%d,%s,%s)', $celeb, $fid, 'active', $now );
			}
			$wpdb->query( "INSERT IGNORE INTO {$p}bn_follows (follower_id, following_id, status, created_at) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB
		}
		\WP_CLI::log( '  celebrity: ' . self::SCALE_FOLLOWERS . ' followers + ' . self::SCALE_FOLLOWERS . ' following' );

		// A viral thread: one post, 25 roots, 6k replies — the CommentService::list()
		// memory blow-up shape.
		$wpdb->insert(
			$p . 'bn_posts',
			array(
				'user_id'    => $celeb,
				'type'       => 'text',
				'content'    => 'QA viral thread fixture.',
				'privacy'    => 'public',
				'status'     => 'published',
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		$viral        = (int) $wpdb->insert_id;
		$m['posts'][] = $viral;

		$roots = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$wpdb->insert(
				$p . 'bn_comments',
				array(
					'user_id'     => $celeb,
					'object_type' => 'post',
					'object_id'   => $viral,
					'content'     => "QA root $i",
					'created_at'  => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s' )
			);
			$roots[] = (int) $wpdb->insert_id;
		}
		for ( $b = 0; $b < self::SCALE_REPLIES; $b += 500 ) {
			$vals = array();
			$end  = min( $b + 500, self::SCALE_REPLIES );
			for ( $i = $b; $i < $end; $i++ ) {
				$vals[] = $wpdb->prepare(
					'(%d,%s,%d,%d,%s,%s)',
					$celeb,
					'post',
					$viral,
					$roots[ $i % 25 ],
					"QA reply $i",
					$now
				);
			}
			$wpdb->query( "INSERT INTO {$p}bn_comments (user_id, object_type, object_id, parent_id, content, created_at) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB
		}
		\WP_CLI::log( '  viral thread: post #' . $viral . ' with 25 roots + ' . self::SCALE_REPLIES . ' replies' );

		// 2k posts + 5k bookmarks: the feed / saved-ids unbounded-read shapes.
		$post_ids = array();
		for ( $b = 0; $b < self::SCALE_POSTS; $b += 500 ) {
			$vals = array();
			$end  = min( $b + 500, self::SCALE_POSTS );
			for ( $i = $b; $i < $end; $i++ ) {
				$vals[] = $wpdb->prepare( '(%d,%s,%s,%s,%s,%s)', $celeb, 'text', "QA scale post $i", 'public', 'published', $now );
			}
			$wpdb->query( "INSERT INTO {$p}bn_posts (user_id, type, content, privacy, status, created_at) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB
			$first = (int) $wpdb->insert_id;
			$batch = $end - $b;
			for ( $k = 0; $k < $batch; $k++ ) {
				$post_ids[] = $first + $k;
			}
		}
		$m['posts'] = array_merge( (array) ( $m['posts'] ?? array() ), $post_ids );

		$bm = array_slice( $post_ids, 0, self::SCALE_BOOKMARKS );
		foreach ( array_chunk( $bm, 500 ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $pid ) {
				$vals[] = $wpdb->prepare( '(%d,%d,%s)', $celeb, $pid, $now );
			}
			$wpdb->query( "INSERT IGNORE INTO {$p}bn_bookmarks (user_id, post_id, created_at) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB
		}
		\WP_CLI::log( '  ' . count( $post_ids ) . ' posts, ' . count( $bm ) . ' bookmarks on the celebrity' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Create (or reuse) a tagged fixture user.
	 *
	 * @param string $key  Short key, becomes qa_fx_<key>.
	 * @param string $name Display name.
	 * @return int User id.
	 */
	private function make_user( string $key, string $name ): int {
		$login = self::USER_PREFIX . $key;
		$user  = get_user_by( 'login', $login );
		if ( $user ) {
			return (int) $user->ID;
		}
		$id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $login . '@example.test',
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => $name,
				'role'         => 'subscriber',
			)
		);
		return is_wp_error( $id ) ? 0 : (int) $id;
	}

	/**
	 * Recompute bn_spaces.member_count from live membership.
	 *
	 * Raw DELETEs do not fire adjust_member_count(). Any fixture teardown that
	 * skips this leaves every touched space reporting a count it no longer has —
	 * which is exactly what happened on 2026-07-13 and cost an hour of confusion.
	 *
	 * @return void
	 */
	private function recount_spaces(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- only the
		// table names are interpolated, from $wpdb->prefix and never from input; a
		// table name cannot be a prepare() placeholder.
		$wpdb->query(
			"UPDATE {$p}bn_spaces s
			 SET s.member_count = (
			     SELECT COUNT(*) FROM {$p}bn_space_members m
			     WHERE m.space_id = s.id AND m.status = 'active'
			 )"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Does a table exist?
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * Read the manifest.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		$m = get_option( self::MANIFEST, array() );
		return is_array( $m ) ? $m : array();
	}
}
