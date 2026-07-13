<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Caps and counts on the connection graph.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;
use BuddyNext\SocialGraph\ConnectionService;
use WP_UnitTestCase;

/**
 * The connection graph had no write cap and no count method.
 *
 * FollowService has had `buddynext_max_following` since day one. The connection graph had
 * nothing: a single account could accumulate accepted rows without limit, and every surface
 * that touches its peer set got more expensive for everyone.
 *
 * The counting half matters just as much. The profile header renders one integer — "N
 * mutual connections" — and got it by materialising the ENTIRE mutual set into a PHP array
 * and calling count() on it.
 *
 * @covers \BuddyNext\SocialGraph\ConnectionService
 */
class ConnectionCapTest extends WP_UnitTestCase {

	/**
	 * Service under test.
	 *
	 * @var ConnectionService
	 */
	private ConnectionService $connections;

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->connections = new ConnectionService();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_connections" );
		wp_cache_flush();
	}

	/**
	 * Connect two users through the real service, so counters and caches stay honest.
	 *
	 * @param int $a Requester.
	 * @param int $b Recipient.
	 * @return void
	 */
	private function connect( int $a, int $b ): void {
		$this->connections->send_request( $a, $b );
		$this->connections->accept_request( $b, $a );
	}

	/**
	 * A mutual count is a COUNT(*), and it is exact.
	 *
	 * @return void
	 */
	public function test_mutual_count_matches_the_real_intersection(): void {
		$viewer = self::factory()->user->create();
		$peer   = self::factory()->user->create();

		$shared = self::factory()->user->create_many( 3 );
		foreach ( $shared as $mutual_id ) {
			$this->connect( $viewer, $mutual_id );
			$this->connect( $peer, $mutual_id );
		}

		// One connection only the viewer has — must NOT count as mutual.
		$this->connect( $viewer, self::factory()->user->create() );

		$this->assertSame( 3, $this->connections->mutual_count( $viewer, $peer ) );
		$this->assertSame(
			3,
			count( $this->connections->mutual_connections( $viewer, $peer ) ),
			'mutual_count() and mutual_connections() disagree — the count the profile shows is not the list it links to.'
		);
	}

	/**
	 * The mutual_count() method is 0 for a user against themselves, and for strangers.
	 *
	 * @return void
	 */
	public function test_mutual_count_edge_cases(): void {
		$a = self::factory()->user->create();
		$b = self::factory()->user->create();

		$this->assertSame( 0, $this->connections->mutual_count( $a, $a ), 'Self must be 0.' );
		$this->assertSame( 0, $this->connections->mutual_count( $a, $b ), 'Strangers share nobody.' );
		$this->assertSame( 0, $this->connections->mutual_count( 0, $b ), 'Logged-out viewer must be 0.' );
	}

	/**
	 * The mutual_connections() method no longer returns an unbounded list by default.
	 *
	 * `$limit = 0` used to mean "no LIMIT clause at all", and BOTH real callers took the
	 * default — one of them purely to call count() on the result.
	 *
	 * @return void
	 */
	public function test_the_default_mutual_list_is_capped(): void {
		$viewer = self::factory()->user->create();
		$peer   = self::factory()->user->create();

		foreach ( self::factory()->user->create_many( 5 ) as $mutual_id ) {
			$this->connect( $viewer, $mutual_id );
			$this->connect( $peer, $mutual_id );
		}

		add_filter( 'buddynext_mutual_list_cap', static fn(): int => 2 );

		$this->assertCount(
			2,
			$this->connections->mutual_connections( $viewer, $peer ),
			'The default call ignored the site ceiling and materialised the whole mutual set.'
		);
	}

	/**
	 * A degree check asking for exactly 1 row still works.
	 *
	 * The connection_degree() method passes limit=1 to test existence without materialising the set.
	 * Redefining what `0` means must not disturb a caller that passes a real limit.
	 *
	 * @return void
	 */
	public function test_an_explicit_limit_is_still_honoured(): void {
		$viewer = self::factory()->user->create();
		$peer   = self::factory()->user->create();

		foreach ( self::factory()->user->create_many( 3 ) as $mutual_id ) {
			$this->connect( $viewer, $mutual_id );
			$this->connect( $peer, $mutual_id );
		}

		$this->assertCount( 1, $this->connections->mutual_connections( $viewer, $peer, 1 ) );

		// 2nd degree: they share connections but are not connected to each other. This is
		// the branch that calls mutual_connections( .., .., 1 ) to test existence without
		// materialising the set, so it is the one that breaks if `0 = unlimited` is
		// redefined carelessly.
		$this->assertSame(
			2,
			$this->connections->connection_degree( $viewer, $peer ),
			'connection_degree() broke — it relies on mutual_connections( .., .., 1 ).'
		);

		// 1st degree once they connect directly.
		$this->connect( $viewer, $peer );
		wp_cache_flush();
		$this->assertSame( 1, $this->connections->connection_degree( $viewer, $peer ) );
	}

	/**
	 * The accept path enforces a cap — on BOTH parties.
	 *
	 * Capping only the requester would let the recipient's side grow without limit, which is
	 * the same bug with an extra step. A connection lands on both graphs.
	 *
	 * @return void
	 */
	public function test_accept_is_refused_when_either_party_is_at_the_cap(): void {
		add_filter( 'buddynext_max_connections', static fn(): int => 2 );

		$hub = self::factory()->user->create();

		// Fill the hub to the cap of 2.
		foreach ( self::factory()->user->create_many( 2 ) as $friend_id ) {
			$this->connect( $hub, $friend_id );
		}
		$this->assertSame( 2, $this->connections->connection_count( $hub ) );

		// A third member requests; the HUB is the one at the cap, and it is the RECIPIENT.
		$newcomer = self::factory()->user->create();
		$this->connections->send_request( $newcomer, $hub );
		$result = $this->connections->accept_request( $hub, $newcomer );

		$this->assertWPError( $result, 'A member past the cap kept accepting connections.' );
		$this->assertSame( 'connection_limit_reached', $result->get_error_code() );
		$this->assertSame( 2, $this->connections->connection_count( $hub ), 'The refused connection was still written.' );

		// And the mirror case: the capped party as the REQUESTER.
		$other = self::factory()->user->create();
		$this->connections->send_request( $hub, $other );
		$mirror = $this->connections->accept_request( $other, $hub );

		$this->assertWPError( $mirror, 'The cap only looked at the recipient. The requester side could still grow without limit.' );
	}

	/**
	 * A cap of 0 disables it, matching buddynext_max_following.
	 *
	 * @return void
	 */
	public function test_a_zero_cap_disables_the_limit(): void {
		add_filter( 'buddynext_max_connections', static fn(): int => 0 );

		$hub = self::factory()->user->create();
		foreach ( self::factory()->user->create_many( 3 ) as $friend_id ) {
			$this->connect( $hub, $friend_id );
		}

		$this->assertSame( 3, $this->connections->connection_count( $hub ) );
	}
}
