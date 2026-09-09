<?php
/**
 * Onboarding nudges are scheduled through Action Scheduler, not WP-Cron and not a
 * bespoke queue table.
 *
 * Card 10264295353: the original bug was two per-user WP-Cron single events queued
 * on every registration, which grew the autoloaded `cron` option without bound at
 * scale. A queue table + recurring sweep was tried, then dropped — it reimplemented
 * a slice of Action Scheduler, which BuddyNext already depends on. Nudges are now two
 * per-user Action Scheduler single actions (bn_onboarding_nudge_24h / _72h), scheduled
 * at registration and unscheduled when the member onboards.
 *
 * @package BuddyNext\Tests\Onboarding
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Onboarding;

use BuddyNext\Onboarding\OnboardingListener;
use WP_UnitTestCase;

/**
 * Onboarding nudges: scheduled, idempotent, and cancelled on completion.
 *
 * @covers \BuddyNext\Onboarding\OnboardingListener::enqueue_nudges
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
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded in this harness.' );
		}
		$this->listener = new OnboardingListener();
		$this->user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		// A fixture user_register may already have fired enqueue if the listener is
		// registered globally; clear so each test starts from a known state.
		$this->unschedule_all();
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		$this->unschedule_all();
		parent::tear_down();
	}

	/**
	 * Drop any pending nudge actions for the fixture user.
	 *
	 * @return void
	 */
	private function unschedule_all(): void {
		foreach ( array( 'bn_onboarding_nudge_24h', 'bn_onboarding_nudge_72h' ) as $hook ) {
			as_unschedule_action( $hook, array( $this->user ), 'buddynext' );
		}
	}

	/**
	 * True when the given nudge hook is pending for the fixture user.
	 *
	 * @param string $hook Nudge hook name.
	 * @return bool
	 */
	private function is_pending( string $hook ): bool {
		return false !== as_next_scheduled_action( $hook, array( $this->user ), 'buddynext' );
	}

	/**
	 * Registration schedules both nudges as pending Action Scheduler actions.
	 *
	 * @return void
	 */
	public function test_enqueue_schedules_both_nudges(): void {
		$this->listener->enqueue_nudges( $this->user );
		$this->assertTrue( $this->is_pending( 'bn_onboarding_nudge_24h' ), 'The 24h nudge is scheduled.' );
		$this->assertTrue( $this->is_pending( 'bn_onboarding_nudge_72h' ), 'The 72h nudge is scheduled.' );
	}

	/**
	 * The 72h nudge is scheduled later than the 24h one (right offsets, not both now).
	 *
	 * @return void
	 */
	public function test_nudges_are_scheduled_at_their_offsets(): void {
		$this->listener->enqueue_nudges( $this->user );
		$at24 = (int) as_next_scheduled_action( 'bn_onboarding_nudge_24h', array( $this->user ), 'buddynext' );
		$at72 = (int) as_next_scheduled_action( 'bn_onboarding_nudge_72h', array( $this->user ), 'buddynext' );
		// as_next_scheduled_action returns the timestamp when passed a specific action
		// signature. The 72h nudge must be due strictly after the 24h nudge.
		$this->assertGreaterThan( $at24, $at72, 'The 72h nudge is due after the 24h nudge.' );
	}

	/**
	 * Enqueuing is idempotent — a duplicate user_register does not double-schedule.
	 *
	 * @return void
	 */
	public function test_enqueue_is_idempotent(): void {
		$this->listener->enqueue_nudges( $this->user );
		$this->listener->enqueue_nudges( $this->user );

		foreach ( array( 'bn_onboarding_nudge_24h', 'bn_onboarding_nudge_72h' ) as $hook ) {
			$pending = as_get_scheduled_actions(
				array(
					'hook'   => $hook,
					'args'   => array( $this->user ),
					'group'  => 'buddynext',
					'status' => \ActionScheduler_Store::STATUS_PENDING,
				),
				'ids'
			);
			$this->assertCount( 1, (array) $pending, "Exactly one {$hook} action after a repeat register." );
		}
	}

	/**
	 * Onboarding completion cancels the member's pending nudges.
	 *
	 * @return void
	 */
	public function test_onboarding_completion_cancels_pending_nudges(): void {
		$this->listener->enqueue_nudges( $this->user );
		$this->listener->on_onboarding_completed_cancel_nudges( $this->user );

		$this->assertFalse( $this->is_pending( 'bn_onboarding_nudge_24h' ), 'The 24h nudge was cancelled.' );
		$this->assertFalse( $this->is_pending( 'bn_onboarding_nudge_72h' ), 'The 72h nudge was cancelled.' );
	}
}
