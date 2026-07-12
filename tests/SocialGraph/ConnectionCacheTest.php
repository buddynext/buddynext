<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * ConnectionService cache-invalidation contract.
 *
 * WHY THIS EXISTS (cache audit, card 10086769239)
 *
 * `invalidate_connection_cache()` calls `wp_cache_flush_group()` — the only real group flush in
 * either repo. It is wrong twice over:
 *
 *   1. It is a silent no-op on persistent drop-ins that do not implement flush_group (some
 *      Memcached, older Redis). Core's default cache DOES implement it, so this is invisible
 *      locally and only bites production sites that installed the very thing that makes caching
 *      work.
 *   2. At scale it is a sledgehammer: ONE member accepting ONE connection request destroys the
 *      cached connection state of EVERY member on the site. On a busy 100k-member graph the cache
 *      is wiped faster than it warms, every read falls through to the database anyway, and the
 *      cache becomes pure cost. That is over-INVALIDATION, the twin of over-caching.
 *
 * HOW THIS FILE IS BUILT — READ BEFORE EDITING
 *
 * These are CHARACTERIZATION tests. Everything except the last one PASSES TODAY, because the
 * group flush really does bust everything. That is the point: they pin the behaviour we must not
 * lose while the mechanism underneath is replaced with delete-by-key + version keys.
 *
 * If the replacement misses even one of the six cached key shapes, one of these goes RED. We are
 * not refactoring blind — the alarm is armed before the change.
 *
 * The six key shapes the flush currently covers:
 *   pair_row_{low}_{high}                      (enumerable → delete-by-key)
 *   connection_count_{uid}                     (enumerable → delete-by-key)
 *   connections_{uid}_{limit}_{offset}         (unbounded offsets → version key)
 *   pending_sent_{uid}_{limit}_{offset}        (unbounded → version key)
 *   pending_received_{uid}_{limit}_{offset}    (unbounded → version key)
 *   mutual_{a}_{b}_{limit}                     (unbounded, PAIRWISE → version key on BOTH users)
 *
 * The last test — test_one_members_write_does_not_destroy_another_members_cache — is the ONLY one
 * that fails today. It is the scale fix, and it is what the group flush can never satisfy.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\SocialGraph\ConnectionService;
use WP_UnitTestCase;

/**
 * The cached reads must reflect the truth after every write path.
 */
class ConnectionCacheTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ConnectionService
	 */
	private ConnectionService $conn;

	/**
	 * Member A.
	 *
	 * @var int
	 */
	private int $a;

	/**
	 * Member B.
	 *
	 * @var int
	 */
	private int $b;

	/**
	 * An uninvolved bystander. Nothing A and B do should touch their cache.
	 *
	 * @var int
	 */
	private int $c;

	/**
	 * Fresh service + three members, with a cold cache.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->conn = new ConnectionService();
		$this->a    = (int) $this->factory->user->create();
		$this->b    = (int) $this->factory->user->create();
		$this->c    = (int) $this->factory->user->create();

		wp_cache_flush();
	}

	/**
	 * Read every cached shape for a member so the cache is warm.
	 *
	 * @param int $uid  Member.
	 * @param int $peer A peer, for the pairwise shapes.
	 * @return void
	 */
	private function warm( int $uid, int $peer ): void {
		$this->conn->connections( $uid );
		$this->conn->pending_sent( $uid );
		$this->conn->pending_received( $uid );
		$this->conn->connection_count( $uid );
		$this->conn->pair_row( $uid, $peer );
		$this->conn->mutual_connections( $uid, $peer );
	}

	// ── send_request ──────────────────────────────────────────────────────────────

	/**
	 * A pending request must appear in both members' pending lists immediately.
	 *
	 * @return void
	 */
	public function test_send_request_refreshes_the_pending_lists(): void {
		$this->warm( $this->a, $this->b );
		$this->warm( $this->b, $this->a );

		$this->assertSame( array(), $this->conn->pending_received( $this->b ), 'B starts with no requests' );

		$this->assertTrue( true === $this->conn->send_request( $this->a, $this->b ), 'the request must send' );

		$this->assertContains(
			$this->a,
			$this->conn->pending_received( $this->b ),
			'B must see A\'s request immediately — a cached empty pending list here means the member '
			. 'never learns someone asked to connect'
		);
		$this->assertContains(
			$this->b,
			$this->conn->pending_sent( $this->a ),
			'A must see their own outgoing request immediately'
		);
		$this->assertSame( 'pending', $this->conn->status( $this->a, $this->b ), 'the pair status must be fresh' );
	}

	// ── accept_request ────────────────────────────────────────────────────────────

	/**
	 * Accepting must refresh the pair, both counts, both connection lists, and both pending lists.
	 *
	 * This is the broadest one: it touches five of the six key shapes in a single write.
	 *
	 * @return void
	 */
	public function test_accept_request_refreshes_every_cached_read(): void {
		$this->conn->send_request( $this->a, $this->b );

		$this->warm( $this->a, $this->b );
		$this->warm( $this->b, $this->a );
		$this->assertSame( 0, $this->conn->connection_count( $this->a ), 'A starts with 0 connections (primed)' );
		$this->assertSame( 0, $this->conn->connection_count( $this->b ), 'B starts with 0 connections (primed)' );

		$this->assertTrue( true === $this->conn->accept_request( $this->b, $this->a ), 'the accept must succeed' );

		$this->assertSame( 'accepted', $this->conn->status( $this->a, $this->b ), 'pair_row must be fresh' );
		$this->assertSame( 1, $this->conn->connection_count( $this->a ), 'connection_count for A must be fresh' );
		$this->assertSame( 1, $this->conn->connection_count( $this->b ), 'connection_count for B must be fresh' );

		$this->assertContains(
			$this->b,
			$this->conn->connections( $this->a ),
			'A\'s connections list must be fresh'
		);
		$this->assertContains(
			$this->a,
			$this->conn->connections( $this->b ),
			'B\'s connections list must be fresh'
		);

		$this->assertSame(
			array(),
			$this->conn->pending_received( $this->b ),
			'the accepted request must leave B\'s pending list'
		);
		$this->assertSame(
			array(),
			$this->conn->pending_sent( $this->a ),
			'the accepted request must leave A\'s sent list'
		);
	}

	// ── decline_request ───────────────────────────────────────────────────────────

	/**
	 * Declining must clear both pending lists.
	 *
	 * @return void
	 */
	public function test_decline_request_refreshes_the_pending_lists(): void {
		$this->conn->send_request( $this->a, $this->b );

		$this->warm( $this->a, $this->b );
		$this->warm( $this->b, $this->a );
		$this->assertNotSame( array(), $this->conn->pending_received( $this->b ), 'B has a pending request (primed)' );

		$this->conn->decline_request( $this->b, $this->a );

		$this->assertSame( array(), $this->conn->pending_received( $this->b ), 'B\'s pending list must be fresh' );
		$this->assertSame( array(), $this->conn->pending_sent( $this->a ), 'A\'s sent list must be fresh' );
		$this->assertSame( 0, $this->conn->connection_count( $this->a ), 'a decline creates no connection' );
	}

	// ── withdraw_request ──────────────────────────────────────────────────────────

	/**
	 * Withdrawing must clear both pending lists.
	 *
	 * @return void
	 */
	public function test_withdraw_request_refreshes_the_pending_lists(): void {
		$this->conn->send_request( $this->a, $this->b );

		$this->warm( $this->a, $this->b );
		$this->warm( $this->b, $this->a );
		$this->assertNotSame( array(), $this->conn->pending_sent( $this->a ), 'A has an outgoing request (primed)' );

		$this->conn->withdraw_request( $this->a, $this->b );

		$this->assertSame( array(), $this->conn->pending_sent( $this->a ), 'A\'s sent list must be fresh' );
		$this->assertSame( array(), $this->conn->pending_received( $this->b ), 'B\'s pending list must be fresh' );
	}

	// ── remove_connection ─────────────────────────────────────────────────────────

	/**
	 * Removing must refresh the pair, both counts and both connection lists.
	 *
	 * @return void
	 */
	public function test_remove_connection_refreshes_every_cached_read(): void {
		$this->conn->send_request( $this->a, $this->b );
		$this->conn->accept_request( $this->b, $this->a );

		$this->warm( $this->a, $this->b );
		$this->warm( $this->b, $this->a );
		$this->assertSame( 1, $this->conn->connection_count( $this->a ), 'A is connected (primed)' );

		$this->conn->remove_connection( $this->a, $this->b );

		$this->assertSame( 0, $this->conn->connection_count( $this->a ), 'A\'s count must be fresh' );
		$this->assertSame( 0, $this->conn->connection_count( $this->b ), 'B\'s count must be fresh' );
		$this->assertNotSame( 'accepted', (string) $this->conn->status( $this->a, $this->b ), 'pair_row must be fresh' );
		$this->assertNotContains(
			$this->b,
			$this->conn->connections( $this->a ),
			'A\'s connections list must be fresh — a removed connection that lingers in a cached list '
			. 'is a member who cannot get rid of someone'
		);
	}

	// ── mutual_ (the pairwise shape — the hard one) ───────────────────────────────

	/**
	 * The mutual-connections cache is PAIRWISE, and that is what makes it hard.
	 *
	 * A and B both connect to C, so mutual(A,B) = [C]. When C then disconnects from B, the cached
	 * `mutual_{A}_{B}_{limit}` entry is stale — but the write only touched B and C. A was not
	 * involved at all.
	 *
	 * This is why the key set cannot be enumerated at write time, and it is exactly why someone
	 * reached for the group flush. The version key must embed BOTH users' versions, or a change to
	 * one member never invalidates the other's view of their mutuals.
	 *
	 * @return void
	 */
	public function test_mutual_connections_are_refreshed_when_either_side_changes(): void {
		// A—C and B—C.
		$this->conn->send_request( $this->a, $this->c );
		$this->conn->accept_request( $this->c, $this->a );
		$this->conn->send_request( $this->b, $this->c );
		$this->conn->accept_request( $this->c, $this->b );

		$mutual = $this->conn->mutual_connections( $this->a, $this->b );
		$this->assertNotSame( array(), $mutual, 'A and B share C as a mutual connection (primed)' );

		// C disconnects from B. This write touches B and C — NOT A.
		$this->conn->remove_connection( $this->b, $this->c );

		$this->assertSame(
			array(),
			$this->conn->mutual_connections( $this->a, $this->b ),
			'C is no longer a mutual connection. The cached mutual_{A}_{B} entry must be invalidated even '
			. 'though A was not part of the write — this is the pairwise case the version key must cover '
			. 'on BOTH users.'
		);
	}

	// ── the scale fix — the ONLY test that fails today ────────────────────────────

	/**
	 * One member's write must NOT destroy another member's cache.
	 *
	 * This is the whole point of the change. `wp_cache_flush_group()` wipes the entire
	 * `buddynext_connections` group, so one member accepting one request destroys the cached
	 * connection state of EVERY member on the site. On a busy 100k-member graph, connections are
	 * accepted continuously — the cache is destroyed faster than it can warm, every read falls
	 * through to the database anyway, and the cache is pure cost.
	 *
	 * EXPECTED TO FAIL until the group flush is replaced with delete-by-key + version keys.
	 *
	 * @return void
	 */
	public function test_one_members_write_does_not_destroy_another_members_cache(): void {
		// Warm the bystander's cache. C has nothing to do with A and B.
		$this->conn->connection_count( $this->c );

		$found = false;
		wp_cache_get( "connection_count_{$this->c}", 'buddynext_connections', false, $found );
		$this->assertTrue(
			$found,
			'the bystander\'s count must actually BE cached before we test that someone else\'s write '
			. 'leaves it alone — otherwise this test passes for the wrong reason'
		);

		// A and B do their thing. C is not involved in any way.
		$this->conn->send_request( $this->a, $this->b );
		$this->conn->accept_request( $this->b, $this->a );

		$found = false;
		wp_cache_get( "connection_count_{$this->c}", 'buddynext_connections', false, $found );

		$this->assertTrue(
			$found,
			'An uninvolved member\'s cache was destroyed because two OTHER members connected. '
			. 'wp_cache_flush_group() wipes the whole group, so at 100k members — where connections are '
			. 'accepted continuously — the cache is destroyed faster than it warms and every read falls '
			. 'through to the database anyway. Use delete-by-key for the enumerable keys and a version '
			. 'key for the unbounded ones.'
		);
	}
}
