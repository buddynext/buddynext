<?php
/**
 * Dead-cron detection and retry-counter preservation for the preset activation.
 *
 * Two residual silent-failures from card 10264291915, both fixed in PresetActivation:
 *
 *   1. A blocked-loopback host (WP-Cron's loopback cannot fire, but DISABLE_WP_CRON
 *      is undefined) armed the single event, which then sat overdue forever: run()
 *      never executed, OPT_GAVE_UP was never written, and the owner saw nothing. The
 *      fix detects an armed event overdue past the grace window as a dead cron and
 *      runs inline, so the give-up notice surfaces.
 *
 *   2. handle_retry() deleted OPT_ATTEMPTS on every click, so a still-blocked host
 *      could be retried forever without the MAX_ATTEMPTS give-up ever re-latching.
 *      The fix preserves the running count so repeated retries still reach give-up.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PresetActivation;

/**
 * @covers \BuddyNext\Core\PresetActivation
 */
class PresetActivationDeadCronTest extends \WP_UnitTestCase {

	/**
	 * Reset the activation option state and clear any left-over nonce request var.
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( PresetActivation::OPT_ACTIVATED );
		delete_option( PresetActivation::OPT_ATTEMPTS );
		delete_option( PresetActivation::OPT_GAVE_UP );
		wp_clear_scheduled_hook( PresetActivation::HOOK );
		unset( $_REQUEST['_wpnonce'] );
	}

	/**
	 * Force every outbound HTTP call to fail, so activation cannot succeed.
	 *
	 * @return \WP_Error
	 */
	public function fail_http() {
		return new \WP_Error( 'http_blocked', 'blocked' );
	}

	/**
	 * These tests target the NON-constant dead-cron path; if the test bootstrap has
	 * defined DISABLE_WP_CRON the constant path masks it and the cases are moot.
	 *
	 * @return bool
	 */
	private function cron_constant_forced(): bool {
		return defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
	}

	/**
	 * An armed-but-overdue event (no DISABLE_WP_CRON) must be treated as a dead cron:
	 * the attempt runs inline and, on failure, latches OPT_GAVE_UP so the owner notice
	 * appears — instead of sitting on a stale past timestamp forever.
	 */
	public function test_overdue_armed_event_surfaces_give_up(): void {
		if ( $this->cron_constant_forced() ) {
			$this->markTestSkipped( 'DISABLE_WP_CRON is forced on in this environment; the overdue-detection path cannot be exercised.' );
		}

		add_filter( 'pre_http_request', array( $this, 'fail_http' ) );

		// An event that armed two hours ago and never fired — the blocked-loopback
		// symptom the fix detects.
		wp_schedule_single_event( time() - ( 2 * HOUR_IN_SECONDS ), PresetActivation::HOOK );

		PresetActivation::maybe_schedule();

		$this->assertEmpty(
			get_option( PresetActivation::OPT_ACTIVATED ),
			'Activation must not report success while the store is unreachable.'
		);
		$this->assertGreaterThan(
			0,
			(int) get_option( PresetActivation::OPT_GAVE_UP, 0 ),
			'A blocked-loopback host (overdue event, no DISABLE_WP_CRON) must reach the give-up state so the owner notice can surface.'
		);
	}

	/**
	 * A retry must not reset the attempt counter. Seeded well past the ceiling, a
	 * single retry on a still-blocked host re-latches give-up; if the counter were
	 * cleared on each click (the bug) it would restart at 1 and never latch.
	 */
	public function test_retry_preserves_attempt_counter_and_relatches_give_up(): void {
		if ( $this->cron_constant_forced() ) {
			$this->markTestSkipped( 'DISABLE_WP_CRON is forced on in this environment; this case targets the cron-enabled retry path.' );
		}

		add_filter( 'pre_http_request', array( $this, 'fail_http' ) );

		// Well past any MAX_ATTEMPTS ceiling, so a single preserved increment latches
		// give-up regardless of the exact ceiling value.
		update_option( PresetActivation::OPT_ATTEMPTS, 999, false );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_REQUEST['_wpnonce'] = wp_create_nonce( PresetActivation::RETRY_ACTION );

		// handle_retry() ends in wp_safe_redirect(); exit — intercept the redirect so
		// the option writes that precede it are observable without killing the test.
		add_filter(
			'wp_redirect',
			static function () {
				throw new \RuntimeException( 'redirect-intercepted' );
			}
		);

		try {
			PresetActivation::handle_retry();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect-intercepted', $e->getMessage() );
		}

		$this->assertGreaterThanOrEqual(
			999,
			(int) get_option( PresetActivation::OPT_ATTEMPTS, 0 ),
			'The running attempt count must be preserved across a retry, not reset to zero.'
		);
		$this->assertGreaterThan(
			0,
			(int) get_option( PresetActivation::OPT_GAVE_UP, 0 ),
			'A retry on a still-blocked host with a past-ceiling count must re-latch give-up.'
		);
	}
}
