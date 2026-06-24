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
}
