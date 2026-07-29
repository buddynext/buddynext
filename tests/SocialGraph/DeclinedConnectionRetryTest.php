<?php
/**
 * A declined connection request can be sent again.
 *
 * Regression cover for a permanent dead end: decline_request() only flips the
 * row's status to 'declined' (every other exit path — withdraw, disconnect —
 * deletes its row), while send_request() rejected on ANY existing row for the
 * pair regardless of status. After a single decline the two members could never
 * connect again without a database edit, and the Connect button kept rendering
 * normally the whole time.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

use BuddyNext\Core\Installer;

/**
 * Re-requesting after a decline.
 *
 * @covers \BuddyNext\SocialGraph\ConnectionService::send_request
 */
class DeclinedConnectionRetryTest extends \WP_UnitTestCase {

	/**
	 * Connection service under test.
	 *
	 * @var object
	 */
	private $connections;

	/**
	 * Create the schema and resolve the service.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->connections = buddynext_service( 'connections' );
	}

	/**
	 * Row count for a pair, in either direction.
	 *
	 * @param int $a First member.
	 * @param int $b Second member.
	 * @return int
	 */
	private function row_count( int $a, int $b ): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id = %d )
				    OR ( requester_id = %d AND recipient_id = %d )",
				$a,
				$b,
				$b,
				$a
			)
		);
	}

	/**
	 * The stored row for a pair.
	 *
	 * @param int $a First member.
	 * @param int $b Second member.
	 * @return object|null
	 */
	private function row( int $a, int $b ): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT requester_id, recipient_id, status FROM {$wpdb->prefix}bn_connections
				 WHERE ( requester_id = %d AND recipient_id = %d )
				    OR ( requester_id = %d AND recipient_id = %d )",
				$a,
				$b,
				$b,
				$a
			)
		);
	}

	/**
	 * After a decline the requester may try again - once the cooldown has passed.
	 *
	 * This test originally retried immediately, because at the time nothing sat
	 * between a decline and the next request. That turned out to be its own
	 * problem: a declined member could re-send instantly and forever, firing a
	 * fresh notification at the person who had just said no. A decline now holds
	 * for a cooldown.
	 *
	 * The contract this test exists to protect is unchanged and still asserted
	 * below: a declined pair must not be walled off PERMANENTLY, and the retry
	 * must reuse the existing row rather than insert a second one. Only the wait
	 * is new, so the decline is moved into the past rather than the assertion
	 * being relaxed.
	 *
	 * @return void
	 */
	public function test_declined_request_can_be_sent_again(): void {
		global $wpdb;

		$alex  = self::factory()->user->create();
		$priya = self::factory()->user->create();

		$this->assertTrue( $this->connections->send_request( $alex, $priya ) );
		$this->assertTrue( $this->connections->decline_request( $priya, $alex ) );

		// Age the decline past the cooldown.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_connections
				    SET declined_at = %s
				  WHERE requester_id = %d AND recipient_id = %d",
				gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ),
				$alex,
				$priya
			)
		);

		wp_cache_flush();

		$this->assertTrue(
			$this->connections->send_request( $alex, $priya ),
			'A declined pair must not be walled off permanently.'
		);

		$row = $this->row( $alex, $priya );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 1, $this->row_count( $alex, $priya ), 'Re-opening must reuse the row, not insert a second one.' );
	}

	/**
	 * The member who declined may later send their OWN request.
	 *
	 * The row is re-pointed, so requester/recipient reflect who is asking now.
	 *
	 * @return void
	 */
	public function test_the_decliner_can_request_in_the_other_direction(): void {
		$alex  = self::factory()->user->create();
		$priya = self::factory()->user->create();

		$this->connections->send_request( $alex, $priya );
		$this->connections->decline_request( $priya, $alex );
		wp_cache_flush();

		$this->assertTrue( $this->connections->send_request( $priya, $alex ) );

		$row = $this->row( $alex, $priya );
		$this->assertSame( $priya, (int) $row->requester_id );
		$this->assertSame( $alex, (int) $row->recipient_id );
		$this->assertSame( 'pending', $row->status );
		$this->assertSame( 1, $this->row_count( $alex, $priya ) );
	}

	/**
	 * A PENDING request still blocks a duplicate.
	 *
	 * Mutation guard: a fix that simply dropped the existing-row check would pass
	 * both tests above and fail here, having turned Connect into a spam button.
	 *
	 * @return void
	 */
	public function test_pending_request_still_blocks_a_duplicate(): void {
		$alex  = self::factory()->user->create();
		$priya = self::factory()->user->create();

		$this->connections->send_request( $alex, $priya );
		wp_cache_flush();

		$this->assertInstanceOf( \WP_Error::class, $this->connections->send_request( $alex, $priya ) );
		$this->assertSame( 1, $this->row_count( $alex, $priya ) );
	}

	/**
	 * An ACCEPTED connection still blocks a new request.
	 *
	 * @return void
	 */
	public function test_accepted_connection_still_blocks_a_request(): void {
		$alex  = self::factory()->user->create();
		$priya = self::factory()->user->create();

		$this->connections->send_request( $alex, $priya );
		$this->connections->accept_request( $priya, $alex );
		wp_cache_flush();

		$this->assertInstanceOf( \WP_Error::class, $this->connections->send_request( $alex, $priya ) );
	}
}
