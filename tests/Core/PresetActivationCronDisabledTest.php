<?php
/**
 * On a host where WP-Cron cannot fire, preset-license activation must not fail
 * silently: the give-up state has to be reachable so the owner notice appears.
 *
 * Regression cover for card 10264291915 round-3: the give-up flag was written only
 * inside run(), and run() only executed from the cron hook — so on a DISABLE_WP_CRON
 * host with no system cron it never ran, attempts stayed 0, and the owner saw
 * nothing forever. The activation is now driven inline from admin_init when cron is
 * disabled, and a single failure gives up immediately so the notice surfaces.
 *
 * @package BuddyNext\Tests\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PresetActivation;
use WP_UnitTestCase;

/**
 * Inline give-up path when WP-Cron is disabled.
 *
 * @covers \BuddyNext\Core\PresetActivation::maybe_schedule
 * @covers \BuddyNext\Core\PresetActivation::run
 */
class PresetActivationCronDisabledTest extends WP_UnitTestCase {

	/**
	 * Reset activation state and simulate a firewalled, cron-disabled host.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		delete_option( PresetActivation::OPT_ACTIVATED );
		delete_option( PresetActivation::OPT_ATTEMPTS );
		delete_option( PresetActivation::OPT_GAVE_UP );

		// Cron cannot fire (stands in for DISABLE_WP_CRON with no system cron).
		add_filter( 'buddynext_wp_cron_disabled', '__return_true' );
		// Every outbound request fails, as on a host that blocks wbcomdesigns.com.
		add_filter( 'pre_http_request', array( $this, 'block_request' ), 99 );
	}

	/**
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'buddynext_wp_cron_disabled', '__return_true' );
		remove_filter( 'pre_http_request', array( $this, 'block_request' ), 99 );
		delete_option( PresetActivation::OPT_ACTIVATED );
		delete_option( PresetActivation::OPT_ATTEMPTS );
		delete_option( PresetActivation::OPT_GAVE_UP );
		parent::tear_down();
	}

	/**
	 * Short-circuit every HTTP request with an error.
	 *
	 * @return \WP_Error
	 */
	public function block_request() {
		return new \WP_Error( 'blocked', 'blocked' );
	}

	/**
	 * With cron disabled, maybe_schedule() drives the attempt inline and a failure
	 * records the give-up immediately — so the admin notice can surface instead of
	 * the failure being silent forever.
	 *
	 * @return void
	 */
	public function test_disabled_cron_gives_up_inline_so_the_notice_shows(): void {
		PresetActivation::maybe_schedule();

		$this->assertGreaterThan(
			0,
			(int) get_option( PresetActivation::OPT_GAVE_UP, 0 ),
			'A cron-disabled host must record give-up inline, not wait on an event that never fires.'
		);
		$this->assertSame(
			1,
			(int) get_option( PresetActivation::OPT_ATTEMPTS, 0 ),
			'The inline attempt must have run exactly once.'
		);
		$this->assertFalse(
			(bool) wp_next_scheduled( PresetActivation::HOOK ),
			'No dead retry event should be scheduled when cron cannot fire it.'
		);
	}

	/**
	 * A successful inline activation marks activated and never gives up.
	 *
	 * @return void
	 */
	public function test_disabled_cron_inline_success_activates(): void {
		remove_filter( 'pre_http_request', array( $this, 'block_request' ), 99 );
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'body'     => wp_json_encode( array( 'license' => 'valid' ) ),
					'response' => array( 'code' => 200 ),
				);
			},
			99
		);

		PresetActivation::maybe_schedule();

		$this->assertSame( 1, (int) get_option( PresetActivation::OPT_ACTIVATED, 0 ), 'A valid response must activate.' );
		$this->assertSame( 0, (int) get_option( PresetActivation::OPT_GAVE_UP, 0 ), 'A success must not record give-up.' );
	}
}
