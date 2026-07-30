<?php
/**
 * Declining a connection request has to mean something.
 *
 * Re-opening a declined pair is right - two people should be able to connect
 * later - but nothing sat between the decline and the next request. A declined
 * member could re-send instantly and forever, and every attempt fired a fresh
 * notification at the person who had just said no. Declining was not a way to
 * make it stop. Reproduced before the fix: five requests, five declines, back to
 * back, all accepted.
 *
 * Worse, the row is REUSED rather than a second one inserted, so `created_at`
 * was overwritten on each re-open and no record survived that a decline had ever
 * happened - the data a cooldown needs was being destroyed on every retry.
 *
 * The rule now is the one LinkedIn and Facebook both use: a decline holds for a
 * cooldown, then the request goes through again. A pause, not a permanent block.
 *
 * @package BuddyNext\Tests\SocialGraph
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\SocialGraph;

/**
 * Cooldown between a decline and the next request.
 *
 * @covers \BuddyNext\SocialGraph\ConnectionService::send_request
 * @covers \BuddyNext\SocialGraph\ConnectionService::decline_request
 */
class ConnectionDeclineCooldownTest extends \WP_UnitTestCase {

	/**
	 * The member who asks.
	 *
	 * @var int
	 */
	private $asker = 0;

	/**
	 * The member who declines.
	 *
	 * @var int
	 */
	private $decliner = 0;

	/**
	 * An uninvolved third member.
	 *
	 * @var int
	 */
	private $stranger = 0;

	/**
	 * Three members and a clean slate.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->asker    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->decliner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * Ask, then have it declined.
	 *
	 * @return void
	 */
	private function ask_and_get_declined(): void {
		$connections = buddynext_service( 'connections' );
		$connections->send_request( $this->asker, $this->decliner, '', null );
		$connections->decline_request( $this->decliner, $this->asker );
	}

	/**
	 * Move the recorded decline into the past.
	 *
	 * @param int $seconds How far back.
	 * @return void
	 */
	private function backdate_decline( int $seconds ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_connections
				    SET declined_at = %s
				  WHERE requester_id = %d AND recipient_id = %d",
				gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				$this->asker,
				$this->decliner
			)
		);
	}

	/**
	 * The decline must be recorded at all. Without a timestamp there is nothing
	 * for a cooldown to measure, which is the state this table shipped in.
	 *
	 * @return void
	 */
	public function test_a_decline_is_recorded(): void {
		global $wpdb;

		$this->ask_and_get_declined();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$declined_at = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT declined_at FROM {$wpdb->prefix}bn_connections
				  WHERE requester_id = %d AND recipient_id = %d",
				$this->asker,
				$this->decliner
			)
		);

		$this->assertNotEmpty( $declined_at, 'Nothing recorded that a decline happened.' );
	}

	/**
	 * The regression: asking again straight away is refused.
	 *
	 * @return void
	 */
	public function test_the_declined_member_cannot_ask_again_immediately(): void {
		$this->ask_and_get_declined();

		$result = buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null );

		$this->assertWPError( $result, 'A declined member could re-send immediately.' );
		$this->assertSame( 'request_declined_recently', $result->get_error_code() );
	}

	/**
	 * ...and repeating it does not eventually get through.
	 *
	 * @return void
	 */
	public function test_repeated_attempts_stay_refused(): void {
		$this->ask_and_get_declined();

		$connections = buddynext_service( 'connections' );
		for ( $i = 0; $i < 4; $i++ ) {
			$this->assertWPError(
				$connections->send_request( $this->asker, $this->decliner, '', null ),
				'A repeated request slipped through on attempt ' . ( $i + 1 ) . '.'
			);
		}
	}

	/**
	 * It is a pause, not a ban: once the cooldown passes the request works again.
	 *
	 * @return void
	 */
	public function test_the_request_is_allowed_again_once_the_cooldown_passes(): void {
		$this->ask_and_get_declined();
		$this->backdate_decline( 8 * DAY_IN_SECONDS );

		$this->assertNotWPError(
			buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null ),
			'The cooldown never expired, so a decline was effectively permanent.'
		);
	}

	/**
	 * The person who DECLINED may still reach out themselves. That is a different
	 * and welcome action, and holding them to the asker's cooldown would punish
	 * the wrong member.
	 *
	 * @return void
	 */
	public function test_the_decliner_may_still_reach_out_themselves(): void {
		$this->ask_and_get_declined();

		$this->assertNotWPError(
			buddynext_service( 'connections' )->send_request( $this->decliner, $this->asker, '', null ),
			'The member who declined was blocked from starting their own request.'
		);
	}

	/**
	 * A first-ever request between two members who have no history is untouched.
	 *
	 * @return void
	 */
	public function test_a_first_request_is_unaffected(): void {
		$this->assertNotWPError(
			buddynext_service( 'connections' )->send_request( $this->asker, $this->stranger, '', null )
		);
	}

	/**
	 * The cooldown is the site owner's call, so it is filterable - and setting it
	 * to zero restores the old behaviour for a site that wants it.
	 *
	 * @return void
	 */
	public function test_the_cooldown_is_filterable(): void {
		$off = static fn(): int => 0;
		add_filter( 'buddynext_connection_redeclare_cooldown', $off );

		$this->ask_and_get_declined();
		$result = buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null );

		remove_filter( 'buddynext_connection_redeclare_cooldown', $off );

		$this->assertNotWPError( $result, 'A zero cooldown still refused the request.' );
	}

	/**
	 * A pair declined BEFORE the declined_at column existed must be covered too.
	 *
	 * The v38 upgrade added the column as NULL and only new declines stamp it,
	 * so every historical decline kept a NULL — and the guard skips a NULL
	 * stamp. The members most likely to be re-requested were exactly the ones
	 * the cooldown did not cover. The tests that already passed all created
	 * FRESH declines, which is the case that always worked.
	 *
	 * @return void
	 */
	public function test_a_legacy_decline_with_no_stamp_is_backfilled_and_held(): void {
		global $wpdb;

		$this->ask_and_get_declined();

		// Recreate the pre-upgrade shape: declined, stamp never written, and the
		// row created recently (created_at is what the backfill reads).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_connections
				    SET declined_at = NULL, created_at = %s
				  WHERE requester_id = %d AND recipient_id = %d",
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				$this->asker,
				$this->decliner
			)
		);

		// Precondition: without the stamp the pair really is re-requestable —
		// if this ever stops holding, the test below proves nothing.
		$this->assertNotWPError(
			buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null ),
			'Fixture is wrong: a NULL stamp already blocks, so the backfill is not what is being tested.'
		);

		// Put the row back into the legacy shape and run the upgrade path.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_connections
				    SET status = 'declined', declined_at = NULL, created_at = %s
				  WHERE requester_id = %d AND recipient_id = %d",
				gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
				$this->asker,
				$this->decliner
			)
		);

		delete_option( 'buddynext_schema_version' );
		\BuddyNext\Core\Installer::maybe_upgrade();

		$stamp = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT declined_at FROM {$wpdb->prefix}bn_connections
				  WHERE requester_id = %d AND recipient_id = %d",
				$this->asker,
				$this->decliner
			)
		);
		$this->assertNotNull( $stamp, 'The upgrade left a historical decline unstamped.' );

		$result = buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null );

		$this->assertWPError( $result, 'A pair declined before the upgrade is still instantly re-requestable.' );
		$this->assertSame( 'request_declined_recently', $result->get_error_code() );
	}

	/**
	 * The backfill must not invent a hold. A decline old enough to have expired
	 * stays expired — stamping NOW() at upgrade time would open a fresh window
	 * on every historical decline, including year-old ones.
	 *
	 * @return void
	 */
	public function test_the_backfill_does_not_revive_an_expired_decline(): void {
		global $wpdb;

		$this->ask_and_get_declined();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_connections
				    SET declined_at = NULL, created_at = %s
				  WHERE requester_id = %d AND recipient_id = %d",
				gmdate( 'Y-m-d H:i:s', time() - ( 400 * DAY_IN_SECONDS ) ),
				$this->asker,
				$this->decliner
			)
		);

		delete_option( 'buddynext_schema_version' );
		\BuddyNext\Core\Installer::maybe_upgrade();

		$this->assertNotWPError(
			buddynext_service( 'connections' )->send_request( $this->asker, $this->decliner, '', null ),
			'The backfill re-armed a decline from over a year ago.'
		);
	}
}
