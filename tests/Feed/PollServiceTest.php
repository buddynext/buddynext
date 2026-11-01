<?php // phpcs:disable Squiz.Commenting.FunctionComment.Missing, Squiz.Commenting.VariableComment.Missing, Generic.Commenting.DocComment.MissingShort -- concise, self-describing test methods and fixtures.
/**
 * Tests for PollService.
 *
 * @package BuddyNext\Tests\Feed
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PollService;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PollService
 */
class PollServiceTest extends \WP_UnitTestCase {

	private PollService $service;
	private PostService $posts;
	private int $alice;
	private int $bob;
	private int $carol;
	private int $poll_id;
	private int $option_a;
	private int $option_b;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->posts   = new PostService();
		$this->service = new PollService();
		$this->alice   = self::factory()->user->create();
		$this->bob     = self::factory()->user->create();
		$this->carol   = self::factory()->user->create();

		// Create a poll post with two options.
		$this->poll_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Which is best?',
				'options' => array( 'Option A', 'Option B' ),
			)
		);

		$post           = $this->posts->get( $this->poll_id );
		$this->option_a = $post['poll_options'][0]['id'];
		$this->option_b = $post['poll_options'][1]['id'];
	}

	public function test_vote_records_vote(): void {
		$result = $this->service->vote( $this->bob, $this->poll_id, $this->option_a );

		$this->assertTrue( $result );
	}

	public function test_vote_increments_option_count(): void {
		$this->service->vote( $this->bob, $this->poll_id, $this->option_a );
		$this->service->vote( $this->carol, $this->poll_id, $this->option_a );

		$results  = $this->service->results( $this->poll_id );
		$option_a = array_values( array_filter( $results, fn( $o ) => $o['id'] === $this->option_a ) )[0];

		$this->assertSame( 2, $option_a['vote_count'] );
	}

	public function test_repeat_vote_on_same_option_toggles_off(): void {
		// First vote — counts as a vote for option A.
		$this->service->vote( $this->bob, $this->poll_id, $this->option_a );

		// Same option a second time — service treats this as "un-vote" toggle.
		$result = $this->service->vote( $this->bob, $this->poll_id, $this->option_a );
		$this->assertTrue( $result );

		// After toggling off, the user's vote should be cleared and the option
		// count back to whatever it was before bob voted.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->prefix}bn_poll_votes WHERE post_id = %d AND user_id = %d",
				$this->poll_id,
				$this->bob
			)
		);
		$this->assertNull( $row, 'Bob should no longer have a vote row after toggling.' );
	}

	public function test_vote_returns_error_for_wrong_post(): void {
		$other_id = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'text',
				'content' => 'Not a poll',
			)
		);

		$result = $this->service->vote( $this->bob, $other_id, $this->option_a );

		$this->assertWPError( $result );
		$this->assertSame( 'not_a_poll', $result->get_error_code() );
	}

	public function test_results_returns_all_options(): void {
		$results = $this->service->results( $this->poll_id );

		$this->assertCount( 2, $results );
		$texts = array_column( $results, 'option_text' );
		$this->assertContains( 'Option A', $texts );
		$this->assertContains( 'Option B', $texts );
	}

	public function test_user_vote_returns_option_id(): void {
		$this->service->vote( $this->bob, $this->poll_id, $this->option_b );

		$voted = $this->service->user_vote( $this->bob, $this->poll_id );

		$this->assertSame( $this->option_b, $voted );
	}

	public function test_user_vote_returns_null_when_not_voted(): void {
		$voted = $this->service->user_vote( $this->carol, $this->poll_id );

		$this->assertNull( $voted );
	}

	/**
	 * The Tools "Repair & recount poll votes" remedy purges a cross-linked vote row
	 * (one that predates the ownership guard) and reconciles every counter from the
	 * surviving, correctly-linked votes — without touching a sibling poll's real
	 * counts (card 10264292330).
	 *
	 * @return void
	 */
	public function test_recount_purges_cross_linked_votes_and_reconciles(): void {
		global $wpdb;

		// A second poll (B) with its own options.
		$poll_b     = $this->posts->create(
			$this->alice,
			array(
				'type'    => 'poll',
				'content' => 'Second poll?',
				'options' => array( 'B-one', 'B-two' ),
			)
		);
		$post_b     = $this->posts->get( $poll_b );
		$b_option_1 = (int) $post_b['poll_options'][0]['id'];

		// A legitimate vote on poll B keeps its real count at 1.
		$this->service->vote( $this->bob, $poll_b, $b_option_1 );
		$this->assertSame( 1, $this->option_count( $b_option_1 ), 'B option should start at one real vote.' );

		// Simulate PRE-guard corruption: a vote row on poll A carrying poll B's
		// option id, plus the inflated counter that the old unscoped increment left
		// on B. (The current guard rejects this at write time; the row can only
		// exist from before the fix.)
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->insert(
			$wpdb->prefix . 'bn_poll_votes',
			array(
				'post_id'   => $this->poll_id, // poll A
				'user_id'   => $this->carol,
				'option_id' => $b_option_1,    // belongs to poll B
			)
		);
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}bn_poll_options SET vote_count = vote_count + 1 WHERE id = %d",
				$b_option_1
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 2, $this->option_count( $b_option_1 ), 'B option should be inflated to two before repair.' );

		$result = $this->service->recount_all_poll_votes();

		$this->assertGreaterThanOrEqual( 1, $result['deleted'], 'The cross-linked row must be purged.' );
		$this->assertSame( 1, $this->option_count( $b_option_1 ), 'B option must reconcile back to its one real vote.' );
		$this->assertSame( 0, $this->option_count( $this->option_a ), 'Poll A option A had no real votes.' );

		// The cross-linked row is gone.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphans = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_poll_votes WHERE post_id = %d AND option_id = %d",
				$this->poll_id,
				$b_option_1
			)
		);
		$this->assertSame( 0, $orphans, 'The cross-linked vote row must no longer exist.' );
	}

	/**
	 * Read an option's stored vote_count.
	 *
	 * @param int $option_id Option id.
	 * @return int
	 */
	private function option_count( int $option_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT vote_count FROM {$wpdb->prefix}bn_poll_options WHERE id = %d", $option_id )
		);
	}

	/**
	 * Create a poll with a given deadline, as a new author.
	 *
	 * @param mixed $end   Requested poll_end_date.
	 * @param array $extra Extra post data.
	 * @return int|\WP_Error
	 */
	private function poll_ending( $end, array $extra = array() ) {
		$author = self::factory()->user->create();
		wp_set_current_user( $author );
		return $this->posts->create(
			$author,
			array(
				'type'          => 'poll',
				'content'       => 'QA-FIX poll',
				'options'       => array( 'A', 'B' ),
				'poll_end_date' => $end,
			) + $extra
		);
	}

	/**
	 * A past or malformed deadline is refused with a clear 422.
	 *
	 * @dataProvider bad_poll_end_dates
	 *
	 * @param mixed  $end  Requested deadline.
	 * @param string $code Expected error code.
	 */
	public function test_bad_poll_end_is_refused( $end, string $code ): void {
		$result = $this->poll_ending( $end );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * Deadlines a poll must not take.
	 *
	 * @return array<string,array{0:mixed,1:string}>
	 */
	public function bad_poll_end_dates(): array {
		return array(
			'past'         => array( '2020-01-01 00:00:00', 'poll_end_in_past' ),
			'garbage'      => array( 'garbage', 'invalid_poll_end_date' ),
			'not a date'   => array( '2099-13-45 00:00:00', 'invalid_poll_end_date' ),
			'date only'    => array( '2099-01-01', 'invalid_poll_end_date' ),
			'not a string' => array( array( '2099-01-01 00:00:00' ), 'invalid_poll_end_date' ),
		);
	}

	/**
	 * A future deadline is stored; no deadline is still no deadline.
	 */
	public function test_future_or_absent_poll_end_is_accepted(): void {
		global $wpdb;
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

		$id = $this->poll_ending( $future );
		$this->assertIsInt( $id );
		$this->assertSame( $future, $wpdb->get_var( $wpdb->prepare( "SELECT end_date FROM {$wpdb->prefix}bn_poll_options WHERE post_id = %d LIMIT 1", $id ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$open = $this->poll_ending( '' );
		$this->assertIsInt( $open );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT end_date FROM {$wpdb->prefix}bn_poll_options WHERE post_id = %d LIMIT 1", $open ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A scheduled poll must not go live already closed, on create or on a reschedule.
	 */
	public function test_scheduled_poll_cannot_open_after_it_ends(): void {
		$end    = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
		$before = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );
		$after  = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );

		$refused = $this->poll_ending( $end, array( 'scheduled_at' => $after ) );
		$this->assertSame( 'poll_end_before_publish', $refused->get_error_code() );

		$id = $this->poll_ending( $end, array( 'scheduled_at' => $before ) );
		$this->assertIsInt( $id );

		$moved = $this->posts->update( $id, get_current_user_id(), array( 'scheduled_at' => $after ) );
		$this->assertSame( 'poll_end_before_publish', $moved->get_error_code() );
		$this->assertFalse( $this->posts->set_schedule( $id, $after ) );
		$this->assertTrue( $this->posts->set_schedule( $id, gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS ) ) );
	}
}
