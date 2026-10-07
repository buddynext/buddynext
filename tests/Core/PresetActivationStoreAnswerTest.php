<?php
/**
 * A refusal from the store is final; silence from the store is retried.
 *
 * @package BuddyNext\Tests
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Core;

use BuddyNext\Core\PresetActivation;
use WP_UnitTestCase;

/**
 * The shared preset-activation model (docs/standards/preset-licence-activation.md):
 * the store saying "expired" or "invalid" cannot change by asking again, so it is
 * never retried automatically. Only no answer at all (network error, firewall
 * page) gets the bounded hourly retry. Basecamp 10375332367: old releases retried
 * every refusal and flooded the store.
 *
 * @covers \BuddyNext\Core\PresetActivation::run
 * @covers \BuddyNext\Core\PresetActivation::maybe_render_notice
 */
class PresetActivationStoreAnswerTest extends WP_UnitTestCase {

	/**
	 * Requests sent to the store.
	 *
	 * @var int
	 */
	private int $requests = 0;

	/**
	 * What the store answers with: an array for wp_remote_post, or a WP_Error.
	 *
	 * @var array<string,mixed>|\WP_Error
	 */
	private $answer;

	public function set_up(): void {
		parent::set_up();
		$this->forget();
		add_filter( 'pre_http_request', array( $this, 'store' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'store' ), 10 );
		$this->forget();
		parent::tear_down();
	}

	private function forget(): void {
		foreach ( array( PresetActivation::OPT_ACTIVATED, PresetActivation::OPT_ATTEMPTS, PresetActivation::OPT_GAVE_UP, PresetActivation::OPT_REFUSED ) as $option ) {
			delete_option( $option );
		}
		wp_clear_scheduled_hook( PresetActivation::HOOK );
	}

	/**
	 * Stand in for wbcomdesigns.com.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return mixed
	 */
	public function store( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'wbcomdesigns.com' ) ) {
			return $pre;
		}
		++$this->requests;
		return $this->answer;
	}

	private function json( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	public function test_a_refusal_is_final_and_names_its_reason(): void {
		$this->answer = $this->json(
			array(
				'success' => false,
				'license' => 'invalid',
				'error'   => 'expired',
			)
		);

		$this->assertFalse( PresetActivation::run() );

		$this->assertSame( 1, $this->requests );
		$this->assertSame( 'expired', get_option( PresetActivation::OPT_REFUSED ) );
		$this->assertGreaterThan( 0, (int) get_option( PresetActivation::OPT_GAVE_UP, 0 ), 'Stopped at once.' );
		$this->assertFalse( wp_next_scheduled( PresetActivation::HOOK ), 'No automatic retry of a refusal.' );

		// Admin page loads after that send nothing more.
		PresetActivation::maybe_schedule();
		PresetActivation::maybe_schedule();
		$this->assertSame( 1, $this->requests );
		$this->assertFalse( wp_next_scheduled( PresetActivation::HOOK ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		PresetActivation::maybe_render_notice();
		$notice = (string) ob_get_clean();
		$this->assertStringContainsString( 'reason: expired', $notice );
		$this->assertStringContainsString( 'Retry activation now', $notice );
	}

	public function test_no_answer_is_retried_within_the_bound(): void {
		$this->answer = array(
			'response' => array( 'code' => 403 ),
			'body'     => '<html>Blocked by firewall</html>',
		);

		// A host whose cron works (the test environment switches WP-Cron off).
		add_filter( 'buddynext_wp_cron_disabled', '__return_false' );
		$ran = PresetActivation::run();
		remove_filter( 'buddynext_wp_cron_disabled', '__return_false' );
		$this->assertFalse( $ran );

		$this->assertSame( '', (string) get_option( PresetActivation::OPT_REFUSED, '' ), 'The store never answered.' );
		$this->assertSame( 1, (int) get_option( PresetActivation::OPT_ATTEMPTS ) );
		$this->assertSame( 0, (int) get_option( PresetActivation::OPT_GAVE_UP, 0 ) );
		$this->assertNotFalse( wp_next_scheduled( PresetActivation::HOOK ), 'One retry is queued, an hour out.' );
	}

	public function test_valid_clears_an_earlier_refusal(): void {
		update_option( PresetActivation::OPT_REFUSED, 'expired', false );
		update_option( PresetActivation::OPT_GAVE_UP, time(), false );
		$this->answer = $this->json( array( 'license' => 'valid' ) );

		$this->assertTrue( PresetActivation::run() );

		$this->assertSame( 1, (int) get_option( PresetActivation::OPT_ACTIVATED ) );
		$this->assertFalse( get_option( PresetActivation::OPT_REFUSED ) );
		$this->assertFalse( get_option( PresetActivation::OPT_GAVE_UP ) );
	}
}
