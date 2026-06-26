<?php
/**
 * BuddyNext INTERNAL scale / QA seeder — drives the real services so every
 * write goes through the same wiring the REST API uses (hooks fire, counters
 * increment, caches bust, search index + analytics collectors populate). It is
 * deliberately NOT the customer demo installer (includes/Demo/*) and is NOT
 * loaded by the plugin at runtime — it lives under docs/ so it never ships in
 * the dist zip. Run it ONLY on a throwaway / local dev site.
 *
 * WHY services, not $wpdb inserts: a raw INSERT skips every hook, so counters,
 * caches, the search index and bn_analytics_events all go stale/empty — you end
 * up testing an inconsistent state that hides real bugs and invents fake ones.
 * Seeding through the services reproduces exactly how data accumulates in
 * production, so the scale behaviour (and the counters' own correctness) is real.
 *
 * USAGE (from the WordPress root):
 *
 *   # Defaults (a quick, meaningful load)
 *   wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php
 *
 *   # Scale it up
 *   BN_SEED_USERS=20000 BN_SEED_POWER_FOLLOWS=4000 BN_SEED_POSTS=500 \
 *   BN_SEED_VIRAL_REACTIONS=10000 BN_SEED_SPACE_MEMBERS=8000 \
 *   wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php
 *
 *   # Faster for very large user counts: pre-generate raw users, then reuse them
 *   wp user generate --count=50000 --role=subscriber
 *   BN_SEED_USERS=0 wp eval-file .../docs/qa/seed-scale.php   # 0 = reuse existing
 *
 *   # Remove everything this seeder created
 *   BN_SEED_CLEANUP=1 wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php
 *
 * Every user/space/post the seeder creates is tagged (usermeta / option) so
 * cleanup removes exactly its own data and nothing else.
 *
 * @package BuddyNext\QA
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'buddynext_service' ) ) {
	\WP_CLI::error( 'BuddyNext is not loaded — activate the plugin on this site first.' );
}

/** Read an integer env knob with a default. */
$bn_env = static function ( string $key, int $fallback ): int {
	$val = getenv( $key );
	return false === $val ? $fallback : (int) $val;
};

$bn_seed_tag  = 'bn_seed_scale';            // usermeta flag on every seeded user.
$bn_space_opt = 'bn_seed_scale_space_id';   // option holding the seeded space id.
$bn_log       = static fn( string $m ) => \WP_CLI::log( '[seed-scale] ' . $m );

// ─────────────────────────────────────────────────────────────────────────────
// Cleanup mode — delete exactly what this seeder created, via the WP/service API.
// ─────────────────────────────────────────────────────────────────────────────
if ( $bn_env( 'BN_SEED_CLEANUP', 0 ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	// Delete the space FIRST (its owner is a seeded user we are about to remove),
	// with an administrator as the actor so the service permission check passes.
	$admins   = get_users(
		array(
			'role'   => 'administrator',
			'fields' => 'ID',
			'number' => 1,
		)
	);
	$admin_id = ! empty( $admins ) ? (int) $admins[0] : 1;
	$space_id = (int) get_option( $bn_space_opt, 0 );
	if ( $space_id > 0 ) {
		buddynext_service( 'spaces' )->delete( $space_id, $admin_id ); // Service delete → member/ban cache flush.
		delete_option( $bn_space_opt );
		$bn_log( 'Deleted the seeded space ' . $space_id . '.' );
	}

	$ids = get_users(
		array(
			'meta_key'   => $bn_seed_tag, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => '1',          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'     => 'ID',
			'number'     => -1,
		)
	);
	$bn_log( sprintf( 'Deleting %d seeded users (content cascades via delete hooks)…', count( $ids ) ) );
	foreach ( $ids as $uid ) {
		wp_delete_user( (int) $uid ); // Fires deletion listeners; BN cleans its rows.
	}

	$bn_log( 'Cleanup complete.' );
	return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Volume knobs.
// ─────────────────────────────────────────────────────────────────────────────
$n_users    = max( 0, $bn_env( 'BN_SEED_USERS', 1000 ) );         // 0 = reuse existing seeded users.
$n_follows  = max( 0, $bn_env( 'BN_SEED_POWER_FOLLOWS', 4000 ) ); // one power-user follows this many (service caps at 5000).
$n_posts    = max( 0, $bn_env( 'BN_SEED_POSTS', 200 ) );
$n_viral    = max( 0, $bn_env( 'BN_SEED_VIRAL_REACTIONS', 5000 ) );
$n_spacemem = max( 0, $bn_env( 'BN_SEED_SPACE_MEMBERS', 2000 ) );

$follows     = buddynext_service( 'follows' );
$post_svc    = buddynext_service( 'post_service' );
$reactions   = buddynext_service( 'reactions' );
$comment_svc = buddynext_service( 'comments' );
$shares      = buddynext_service( 'shares' );
$spaces      = buddynext_service( 'spaces' );
$members     = buddynext_service( 'space_members' );

// Bulk seeding posts many comments quickly; lift the per-minute comment rate
// limit for the duration so the throttle doesn't reject legitimate seed writes.
$bn_prev_comment_rate = get_option( 'buddynext_comment_rate_limit', 30 );
update_option( 'buddynext_comment_rate_limit', 0 );

// ── Users (via wp_insert_user → fires user_register, drip enroll, etc.) ───────
$bn_log( $n_users > 0 ? "Creating {$n_users} members…" : 'Reusing existing seeded members…' );
for ( $i = 0; $i < $n_users; $i++ ) {
	$suffix = wp_generate_password( 6, false );
	$uid    = wp_insert_user(
		array(
			'user_login'   => 'bnseed_' . $suffix . '_' . $i,
			'user_pass'    => wp_generate_password( 16 ),
			'user_email'   => 'bnseed_' . $suffix . '_' . $i . '@example.test',
			'display_name' => 'Seed Member ' . $i,
			'role'         => 'subscriber',
		)
	);
	if ( ! is_wp_error( $uid ) ) {
		update_user_meta( $uid, $bn_seed_tag, '1' );
		// Presence: write through the canonical table writer (what stamp() calls),
		// some online, some stale — exercises the bn_presence range scans.
		\BuddyNext\Realtime\PresenceService::write( (int) $uid, time() - wp_rand( 0, 1200 ) );
	}
	if ( 0 === ( $i + 1 ) % 500 ) {
		$bn_log( sprintf( '  …%d members', $i + 1 ) );
	}
}

$user_ids = get_users(
	array(
		'meta_key'   => $bn_seed_tag, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'meta_value' => '1',          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		'fields'     => 'ID',
		'number'     => -1,
	)
);
$user_ids = array_map( 'intval', $user_ids );
if ( empty( $user_ids ) ) {
	update_option( 'buddynext_comment_rate_limit', $bn_prev_comment_rate );
	\WP_CLI::error( 'No seeded users available — set BN_SEED_USERS > 0 on the first run.' );
}
$bn_log( sprintf( 'Working with %d seeded members.', count( $user_ids ) ) );

// ── Power follower (drives the home-feed "people I follow" subquery + the cap) ─
if ( $n_follows > 0 ) {
	$hub      = $user_ids[0];
	$capped   = 0;
	$followed = 0;
	foreach ( $user_ids as $target ) {
		if ( $target === $hub || $followed >= $n_follows ) {
			continue;
		}
		$res = $follows->follow( $hub, $target ); // Fires buddynext_user_followed, busts follow cache, honours cap.
		if ( is_wp_error( $res ) ) {
			++$capped;
			break; // Hit the follow cap — expected; the cap is doing its job.
		}
		++$followed;
	}
	$bn_log( sprintf( 'Power member %d follows %d others%s.', $hub, $followed, $capped ? ' (then blocked by the follow cap)' : '' ) );
}

// ── Posts + engagement (fires post/reaction/comment hooks, counters, analytics) ─
$post_ids = array();
for ( $i = 0; $i < $n_posts; $i++ ) {
	$author = $user_ids[ $i % count( $user_ids ) ];
	$pid    = $post_svc->create(
		$author,
		array(
			'type'    => 'text',
			'content' => 'Seed post #' . $i . ' ' . wp_generate_password( 8, false ),
		)
	);
	if ( is_wp_error( $pid ) ) {
		continue;
	}
	$post_ids[] = (int) $pid;

	// Light, spread engagement so counters/analytics accumulate realistically.
	foreach ( array_slice( $user_ids, 0, min( 8, count( $user_ids ) ) ) as $j => $actor ) {
		if ( $actor === $author ) {
			continue;
		}
		$reactions->react( $actor, 'post', (int) $pid, 'like' );
		if ( 0 === $j % 3 ) {
			$comment_svc->create( $actor, 'post', (int) $pid, 'Seed comment from ' . $actor );
		}
		if ( 0 === $j % 5 ) {
			$shares->share( $actor, (int) $pid, '' );
		}
	}
	if ( 0 === ( $i + 1 ) % 100 ) {
		$bn_log( sprintf( '  …%d posts', $i + 1 ) );
	}
}
$bn_log( sprintf( 'Created %d posts with engagement.', count( $post_ids ) ) );

// ── One viral post (hot-row counter + analytics at volume) ────────────────────
if ( $n_viral > 0 && ! empty( $post_ids ) ) {
	$viral = $post_ids[0];
	$hit   = 0;
	foreach ( $user_ids as $actor ) {
		if ( $hit >= $n_viral ) {
			break;
		}
		if ( ! is_wp_error( $reactions->react( $actor, 'post', $viral, 'like' ) ) ) {
			++$hit;
		}
	}
	$bn_log( sprintf( 'Viral post %d received %d reactions (counter maintained on each write).', $viral, $hit ) );
}

// ── A populous space (member_count + space presence/role at scale) ────────────
if ( $n_spacemem > 0 ) {
	$owner    = $user_ids[0];
	$space_id = (int) get_option( $bn_space_opt, 0 );
	if ( $space_id <= 0 ) {
		$created  = $spaces->create(
			$owner,
			array(
				'name'        => 'Seed Scale Space',
				'slug'        => 'seed-scale-space',
				'type'        => 'open',
				'description' => 'Internal scale seed.',
			)
		);
		$space_id = is_wp_error( $created ) ? 0 : (int) $created;
		if ( $space_id > 0 ) {
			update_option( $bn_space_opt, $space_id );
		}
	}
	if ( $space_id > 0 ) {
		$joined = 0;
		foreach ( $user_ids as $member ) {
			if ( $member === $owner || $joined >= $n_spacemem ) {
				continue;
			}
			if ( ! is_wp_error( $members->join( $space_id, $member ) ) ) { // Fires join hooks, bumps member_count.
				++$joined;
			}
		}
		$bn_log( sprintf( 'Space %d now has %d members (via join()).', $space_id, $joined ) );
	}
}

update_option( 'buddynext_comment_rate_limit', $bn_prev_comment_rate );

$bn_log( 'Done. Every row was written through the services, so counters are correct, caches are warm, and analytics/search are populated.' );
$bn_log( 'Tip: `wp eval "buddynext_service(\'post_service\')->recount_counters();"` should now find ZERO drift — proof the wiring fired.' );
