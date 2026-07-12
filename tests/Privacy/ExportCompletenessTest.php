<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * A GDPR export must be COMPLETE, and it must not fall over on a big member.
 *
 * WHY THIS EXISTS (card 10086831294 step 3, and the export half of card 10086819064)
 *
 * The eraser lied about being finished. The exporter has the same shape of bug, twice over — and
 * the second one is worse, because it is silent.
 *
 * 1. PAGE 1 IS NOT "THE BOUNDED SETS"
 *
 *    export() calls page 1 "all the bounded sets in one shot" and puts the member's entire social
 *    graph in it: every follow (both directions), every connection, every block. None of those are
 *    bounded by anything. A member with 100k followers has all 100k rows fetched into one array,
 *    inside one request. Same defect the purge had, on the read side.
 *
 * 2. THE EXPORT SILENTLY TRUNCATES NOTIFICATIONS
 *
 *    export_notifications() ends in `ORDER BY created_at DESC LIMIT 500`. There is no paging behind
 *    it and no disclosure in front of it. A member with 5,000 notifications is handed 500 of them
 *    and told the export is complete.
 *
 *    That is not a performance bug, it is a compliance one. An incomplete export presented as whole
 *    is the export-side twin of the eraser hard-coding `done => true`: in both cases core takes us
 *    at our word — it zips the file and emails the member to say here is everything we hold on you.
 *
 *    A cap is the right instinct for a SCREEN. On a subject-access request it is the wrong one:
 *    the answer to "too much data to send at once" is to send it in pages, never to send less of it.
 *
 * @package BuddyNext\Tests\Privacy
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Privacy;

use BuddyNext\Privacy\PrivacyTools;
use WP_UnitTestCase;

/**
 * The personal-data export must be complete and bounded.
 */
class ExportCompletenessTest extends WP_UnitTestCase {

	/**
	 * Exporter under test.
	 *
	 * @var PrivacyTools
	 */
	private PrivacyTools $privacy;

	/**
	 * The member being exported.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Their email.
	 *
	 * @var string
	 */
	private string $email;

	/**
	 * Every SELECT the export ran.
	 *
	 * @var array<int,string>
	 */
	private array $queries = array();

	/**
	 * Small pages, so "prolific" is cheap to fixture.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->privacy = new PrivacyTools();
		$this->member  = (int) $this->factory->user->create();
		$this->email   = (string) get_userdata( $this->member )->user_email;

		add_filter( 'buddynext_export_per_page', static fn(): int => 5 );

		wp_cache_flush();
	}

	/**
	 * Record every query the export runs.
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
	 * Give the member N followers.
	 *
	 * @param int $n How many.
	 * @return void
	 */
	private function give_followers( int $n ): void {
		global $wpdb;

		for ( $i = 0; $i < $n; $i++ ) {
			$other = (int) $this->factory->user->create();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_follows',
				array(
					'follower_id'  => $other,
					'following_id' => $this->member,
					'status'       => 'approved',
				)
			);
		}
	}

	/**
	 * Give the member N notifications.
	 *
	 * @param int $n How many.
	 * @return void
	 */
	private function give_notifications( int $n ): void {
		global $wpdb;

		for ( $i = 0; $i < $n; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$wpdb->prefix . 'bn_notifications',
				array(
					'recipient_id' => $this->member,
					'sender_id'    => 0,
					'type'         => 'test_' . $i,
					'object_type'  => 'post',
					'object_id'    => $i + 1,
					'is_read'      => 0,
				)
			);
		}
	}

	/**
	 * Walk the whole export the way core does, and return every item.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function walk_export(): array {
		$items = array();
		$page  = 1;

		do {
			$result = $this->privacy->export( $this->email, $page );
			$items  = array_merge( $items, $result['data'] );
			++$page;
		} while ( ! $result['done'] && $page < 200 );

		$this->assertTrue( $result['done'], 'the export must converge, or core pages forever' );

		return $items;
	}

	/**
	 * Count the exported items in one group.
	 *
	 * @param array<int,array<string,mixed>> $items Exported items.
	 * @param string                         $group Group id.
	 * @return int
	 */
	private function count_group( array $items, string $group ): int {
		return count( array_filter( $items, static fn( array $i ): bool => ( $i['group_id'] ?? '' ) === $group ) );
	}

	// ── 1. nothing unbounded ───────────────────────────────────────────────────────

	/**
	 * The export must never SELECT a member's whole social graph into one array.
	 *
	 * @return void
	 */
	public function test_the_export_never_loads_the_whole_social_graph_at_once(): void {
		$this->give_followers( 12 );

		$this->record_queries();
		$this->walk_export();

		foreach ( $this->queries as $sql ) {
			$flat = (string) preg_replace( '/\s+/', ' ', $sql );

			if ( 0 !== stripos( ltrim( $flat ), 'select' ) ) {
				continue;
			}
			if ( false === stripos( $flat, 'bn_follows' ) && false === stripos( $flat, 'bn_connections' ) ) {
				continue;
			}
			if ( false !== stripos( $flat, 'count(' ) ) {
				continue; // an aggregate returns one row.
			}

			$this->assertMatchesRegularExpression(
				'/\bLIMIT\b/i',
				$flat,
				"The export SELECTs the member's entire social graph into one PHP array. Page 1 calls "
				. 'itself "the bounded sets" — follows and connections are not bounded by anything. '
				. "A member with 100k followers OOMs the export request.\n\nSQL: " . $flat
			);
		}
	}

	// ── 2. nothing silently dropped ────────────────────────────────────────────────

	/**
	 * Every notification must be in the export — not the most recent 500.
	 *
	 * This is the silent one. A cap with no paging and no disclosure hands the member a partial
	 * answer and tells them it is complete.
	 *
	 * @return void
	 */
	public function test_no_notification_is_silently_dropped_from_the_export(): void {
		// 505, not 12. The cap is a hard-coded LIMIT 500, so a fixture under it proves nothing —
		// the test would pass on the broken code and pin nothing at all.
		$this->give_notifications( 505 );

		$items = $this->walk_export();

		$this->assertSame(
			505,
			$this->count_group( $items, 'buddynext_notifications' ),
			'The exporter caps notifications (LIMIT 500) with no paging behind it and no disclosure '
			. 'in front of it. The member is handed a partial export and told it is complete. On a '
			. 'subject-access request the answer to "too much to send at once" is to send it in '
			. 'pages — never to send less of it.'
		);
	}

	/**
	 * Every follow must be in the export too — both directions.
	 *
	 * @return void
	 */
	public function test_every_follow_is_exported(): void {
		$this->give_followers( 12 );

		$items = $this->walk_export();

		$this->assertSame(
			12,
			$this->count_group( $items, 'buddynext_followers' ),
			'streaming the followers must not lose any of them'
		);
	}

	// ── 3. done still means done ───────────────────────────────────────────────────

	/**
	 * An ordinary member still exports in a single page.
	 *
	 * Streaming the big sections must not turn a member with three follows into a ten-request
	 * export. The common case stays one page.
	 *
	 * @return void
	 */
	public function test_an_ordinary_member_still_exports_in_one_page(): void {
		$this->give_followers( 2 );

		$result = $this->privacy->export( $this->email, 1 );

		$this->assertTrue(
			$result['done'],
			'a member with 2 follows and nothing else must be finished on page 1'
		);
		$this->assertSame(
			2,
			$this->count_group( $result['data'], 'buddynext_followers' ),
			'and their followers are in it'
		);
	}

	/**
	 * A member with nothing at all is done immediately, and does not page forever.
	 *
	 * @return void
	 */
	public function test_an_empty_member_is_done_on_page_one(): void {
		$result = $this->privacy->export( $this->email, 1 );

		$this->assertTrue( $result['done'], 'nothing to export means done' );
	}
}
