<?php
/**
 * Onboarding nudges are driven by an indexed queue table, not a wp_users scan.
 *
 * Card 10264295353: the per-user WP-Cron events (the original unbounded-cron bug)
 * were replaced by a sweep, but that sweep range-scanned the unindexed
 * wp_users.user_registered — the 100k-member concern. Nudges are now enqueued at
 * registration into bn_onboarding_nudges (indexed on sent, due_at) and the sweep
 * drains due, unsent rows off that index.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Core\Installer;
use BuddyNext\Onboarding\OnboardingListener;
use WP_UnitTestCase;

/**
 * The bn_onboarding_nudges queue: enqueue, due-only sweep, prune, cancel.
 *
 * @covers \BuddyNext\Onboarding\OnboardingListener::enqueue_nudges
 * @covers \BuddyNext\Onboarding\OnboardingListener::run_nudge_sweep
 * @covers \BuddyNext\Onboarding\OnboardingListener::on_onboarding_completed_cancel_nudges
 */
class NudgeQueueTest extends WP_UnitTestCase {

	/** @var OnboardingListener */
	private $listener;

	/** @var int */
	private $user = 0;

	/**
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();
		$this->listener = new OnboardingListener();
		$this->user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function rows(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT kind, sent FROM {$wpdb->prefix}bn_onboarding_nudges WHERE user_id = %d ORDER BY kind", $this->user ),
			ARRAY_A
		);
	}

	/**
	 * Registration enqueues both nudges, unsent and due in the future.
	 *
	 * @return void
	 */
	public function test_enqueue_creates_two_future_rows(): void {
		$this->listener->enqueue_nudges( $this->user );
		$rows = $this->rows();
		$this->assertCount( 2, $rows, 'Two nudges (24h, 72h) are queued.' );
		$this->assertSame( array( '0', '0' ), array_map( static fn( $r ) => (string) $r['sent'], $rows ), 'Both start unsent.' );
	}

	/**
	 * Enqueuing is idempotent — a duplicate user_register does not double-queue.
	 *
	 * @return void
	 */
	public function test_enqueue_is_idempotent(): void {
		$this->listener->enqueue_nudges( $this->user );
		$this->listener->enqueue_nudges( $this->user );
		$this->assertCount( 2, $this->rows(), 'Still exactly two rows after a repeat register.' );
	}

	/**
	 * The sweep sends only DUE rows; a future row is left untouched.
	 *
	 * @return void
	 */
	public function test_sweep_sends_due_prunes_and_skips_future(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'bn_onboarding_nudges';
		$this->listener->enqueue_nudges( $this->user );

		// Make only the 24h nudge due; leave 72h in the future.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET due_at = '2000-01-01 00:00:00' WHERE user_id = %d AND kind = '24h'", $this->user ) );

		$this->listener->run_nudge_sweep();

		$rows = $this->rows();
		// The due 24h row was sent then pruned; the future 72h row remains unsent.
		$this->assertCount( 1, $rows, 'Only the future nudge remains after the sweep.' );
		$this->assertSame( '72h', $rows[0]['kind'], 'The remaining row is the not-yet-due 72h nudge.' );
		$this->assertSame( '0', (string) $rows[0]['sent'], 'The future nudge is still unsent.' );
	}

	/**
	 * Onboarding early cancels the member's pending queued nudges.
	 *
	 * @return void
	 */
	public function test_onboarding_completion_cancels_pending_nudges(): void {
		$this->listener->enqueue_nudges( $this->user );
		$this->listener->on_onboarding_completed_cancel_nudges( $this->user );

		foreach ( $this->rows() as $row ) {
			$this->assertSame( '1', (string) $row['sent'], 'Pending nudges are marked sent when the member onboards.' );
		}
	}
}
