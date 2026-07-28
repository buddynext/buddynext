<?php
/**
 * The report queue says who is already suspended.
 *
 * A row whose offender was already suspended still offered an active "Suspend
 * account" button and said nothing about the existing suspension, so a moderator
 * working a queue could not tell whether they had already acted on that person.
 * Pressing it again is harmless — suspend_user() returns the existing id rather
 * than inserting a second row — but "harmless" is not the same as "informative".
 *
 * The flag is resolved in the SAME batch pass as the strike counts and display
 * names, which is the load-bearing part: the queue template documents that no
 * per-row lookup happens inside its loop, and a per-row is_suspended() would
 * have been one query per report on a page that can show hundreds.
 *
 * @package BuddyNext\Tests\Moderation
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\ModerationService;

/**
 * offender_suspended on the enriched queue rows.
 *
 * @covers \BuddyNext\Moderation\ModerationService::get_queue
 */
class QueueSuspendedStateTest extends \WP_UnitTestCase {

	/**
	 * Moderation service.
	 *
	 * @var ModerationService
	 */
	private $moderation;

	/**
	 * Moderator taking action.
	 *
	 * @var int
	 */
	private $admin = 0;

	/**
	 * Author who ends up suspended.
	 *
	 * @var int
	 */
	private $suspended_author = 0;

	/**
	 * Author who stays in good standing.
	 *
	 * @var int
	 */
	private $clean_author = 0;

	/**
	 * Boot schema, users, posts and reports.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::run();

		$this->moderation       = new ModerationService();
		$this->admin            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->suspended_author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->clean_author     = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$posts = new PostService();

		foreach ( array( $this->suspended_author, $this->clean_author ) as $author ) {
			$post_id = (int) $posts->create( $author, array( 'content' => 'Reported content by ' . $author ) );
			$this->moderation->report( $this->admin, 'post', $post_id, 'spam' );
		}
	}

	/**
	 * Fetch the enriched queue rows keyed by offender id.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function rows_by_offender(): array {
		$result = $this->moderation->get_queue( array( 'status' => 'pending', 'enrich' => true ) );
		$items  = $result['items'] ?? $result;

		$out = array();
		foreach ( (array) $items as $item ) {
			$out[ (int) $item['offender_id'] ] = $item;
		}

		return $out;
	}

	/**
	 * Every row carries the flag, and it is false while nobody is suspended.
	 *
	 * @return void
	 */
	public function test_the_flag_is_present_and_false_by_default(): void {
		foreach ( $this->rows_by_offender() as $offender_id => $row ) {
			$this->assertArrayHasKey( 'offender_suspended', $row, 'Row for offender ' . $offender_id . ' has no suspension state.' );
			$this->assertFalse( $row['offender_suspended'] );
		}
	}

	/**
	 * The reported gap: suspending the author flips the flag on their row only.
	 *
	 * @return void
	 */
	public function test_a_suspended_offender_is_flagged_and_others_are_not(): void {
		$this->moderation->suspend_user( $this->suspended_author, $this->admin, 'Spam', array( 'duration_days' => 7 ) );

		$rows = $this->rows_by_offender();

		$this->assertTrue(
			$rows[ $this->suspended_author ]['offender_suspended'],
			'The queue offered "Suspend account" for someone already suspended.'
		);
		$this->assertFalse(
			$rows[ $this->clean_author ]['offender_suspended'],
			'A member in good standing was marked suspended.'
		);
	}

	/**
	 * Lifting the suspension puts the action back. Without this the row would
	 * be stuck showing "Already suspended" after the member returns.
	 *
	 * @return void
	 */
	public function test_lifting_the_suspension_clears_the_flag(): void {
		$this->moderation->suspend_user( $this->suspended_author, $this->admin, 'Spam', array( 'duration_days' => 7 ) );
		$this->assertTrue( $this->rows_by_offender()[ $this->suspended_author ]['offender_suspended'] );

		$this->moderation->unsuspend_user( $this->suspended_author, $this->admin );

		$this->assertFalse( $this->rows_by_offender()[ $this->suspended_author ]['offender_suspended'] );
	}

	/**
	 * The flag agrees with is_suspended(), which is what every gate actually
	 * consults. A queue that disagreed with the gate would be worse than one
	 * that said nothing.
	 *
	 * @return void
	 */
	public function test_the_flag_agrees_with_the_real_gate(): void {
		$this->moderation->suspend_user( $this->suspended_author, $this->admin, 'Spam', array( 'duration_days' => 7 ) );

		foreach ( $this->rows_by_offender() as $offender_id => $row ) {
			$this->assertSame(
				$this->moderation->is_suspended( $offender_id ),
				$row['offender_suspended'],
				'Queue state and is_suspended() disagree for user ' . $offender_id . '.'
			);
		}
	}

	/**
	 * The flag costs a fixed number of queries regardless of how many reports
	 * are on the page. This is the assertion that fails if someone later
	 * "simplifies" it into a per-row is_suspended() call.
	 *
	 * @return void
	 */
	public function test_the_flag_does_not_add_a_query_per_row(): void {
		global $wpdb;

		$posts = new PostService();
		for ( $i = 0; $i < 8; $i++ ) {
			$author  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
			$post_id = (int) $posts->create( $author, array( 'content' => 'More reported content ' . $i ) );
			$this->moderation->report( $this->admin, 'post', $post_id, 'spam' );
			$this->moderation->suspend_user( $author, $this->admin, 'Spam', array( 'duration_days' => 7 ) );
		}

		$before = $wpdb->num_queries;
		$this->moderation->get_queue( array( 'status' => 'pending', 'enrich' => true ) );
		$ten_rows = $wpdb->num_queries - $before;

		$before  = $wpdb->num_queries;
		$this->moderation->get_queue( array( 'status' => 'pending', 'enrich' => true, 'per_page' => 2 ) );
		$two_rows = $wpdb->num_queries - $before;

		$this->assertSame(
			$two_rows,
			$ten_rows,
			'Query count grew with the number of rows — the suspension lookup went per-row.'
		);
	}
}
