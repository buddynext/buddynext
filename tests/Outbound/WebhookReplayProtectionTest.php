<?php
/**
 * A captured webhook call must stop working.
 *
 * The signature covered the body and nothing else, so it never went stale. A
 * `grant_ability` request captured once could be replayed on a timer to renew a
 * membership indefinitely, and every replay verified perfectly because nothing
 * about the request changed between the first send and the thousandth.
 *
 * Two things fix that, and both are needed. Signing the timestamp puts an expiry
 * on a captured call; recording the accepted signature stops it being spent
 * twice inside the window that expiry allows.
 *
 * The old body-only scheme is REFUSED by default, including on upgraded sites
 * (68a89d71). That is an owner-chosen breaking change: a captured grant_ability
 * call that can be replayed forever is the worse outcome, so a straggler gets a
 * 401 and an escape hatch rather than an indefinitely open door. The hatch is the
 * `buddynext_webhook_strict_signatures` option or the
 * `buddynext_require_signed_timestamp` filter, and the tests at the end pin both
 * the closed default and the way out of it.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Outbound\AccessWebhookController;

/**
 * Timestamp tolerance, replay refusal, and the closed legacy default.
 *
 * @covers \BuddyNext\Outbound\AccessWebhookController
 */
class WebhookReplayProtectionTest extends \WP_UnitTestCase {

	/**
	 * Shared secret both sides sign with.
	 */
	private const SECRET = 'test-webhook-secret';

	/**
	 * Member the grants are aimed at.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Configure the secret, register routes, empty the log.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		update_option( 'buddynext_webhook_secret', self::SECRET );
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );

		$this->user_id = self::factory()->user->create();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_webhook_log" );

		// The route is registered by the plugin on rest_api_init, which
		// rest_do_request() fires for us. Calling register_routes() here instead
		// registers it off-action, which WP flags as incorrect usage and which
		// would also be testing my own wiring rather than the plugin's.
	}

	/**
	 * Restore options.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'buddynext_webhook_secret' );
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );
		parent::tear_down();
	}

	/**
	 * Build a signed request the way an integrator would.
	 *
	 * @param array<string,mixed> $body      Request body.
	 * @param int|null            $timestamp Unix time to sign with; null for the legacy scheme.
	 * @return \WP_REST_Request
	 */
	private function signed_request( array $body, ?int $timestamp ): \WP_REST_Request {
		$json    = (string) wp_json_encode( $body );
		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( $json );

		if ( null === $timestamp ) {
			$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . hash_hmac( 'sha256', $json, self::SECRET ) );

			return $request;
		}

		$request->set_header( 'X-BuddyNext-Timestamp', (string) $timestamp );
		$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $json, self::SECRET ) );

		return $request;
	}

	/**
	 * A grant body for the fixture member.
	 *
	 * @param string $ability Ability slug.
	 * @return array<string,mixed>
	 */
	private function grant_body( string $ability = 'tier:gold' ): array {
		return array(
			'action'  => 'grant_ability',
			'user_id' => $this->user_id,
			'ability' => $ability,
			'source'  => 'stripe',
		);
	}

	/**
	 * The current scheme works.
	 *
	 * @return void
	 */
	public function test_timestamped_request_is_accepted(): void {
		$response = rest_do_request( $this->signed_request( $this->grant_body(), time() ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Sending the same captured call twice is refused the second time.
	 *
	 * @return void
	 */
	public function test_replayed_request_is_refused(): void {
		$stamp = time();

		$first = rest_do_request( $this->signed_request( $this->grant_body(), $stamp ) );
		$this->assertSame( 200, $first->get_status(), 'The first send must succeed.' );

		// Byte-identical: same body, same timestamp, therefore same signature.
		$second = rest_do_request( $this->signed_request( $this->grant_body(), $stamp ) );

		$this->assertSame( 409, $second->get_status(), 'The same signed call must not be spendable twice.' );
		$this->assertSame( 'replayed_request', $second->get_data()['code'] ?? '' );
	}

	/**
	 * A call captured earlier today is outside the window.
	 *
	 * @return void
	 */
	public function test_stale_timestamp_is_refused(): void {
		$response = rest_do_request( $this->signed_request( $this->grant_body(), time() - 3600 ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'stale_request', $response->get_data()['code'] ?? '' );
	}

	/**
	 * A clock a little ahead is drift, not an attack.
	 *
	 * @return void
	 */
	public function test_small_clock_drift_is_tolerated(): void {
		$response = rest_do_request( $this->signed_request( $this->grant_body(), time() + 60 ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Signing the body but claiming a different timestamp does not verify.
	 *
	 * @return void
	 */
	public function test_timestamp_must_be_part_of_the_signature(): void {
		$request = $this->signed_request( $this->grant_body(), time() );
		$request->set_header( 'X-BuddyNext-Timestamp', (string) ( time() - 10 ) );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status(), 'Editing the timestamp must invalidate the signature.' );
	}

	/**
	 * A body-only signature is refused, on an upgraded site too.
	 *
	 * These two tests asserted the opposite until 2026-08-29 - that an unstamped
	 * request still returned 200 - because 68a89d71 flipped the runtime fallback to
	 * strict and updated two other files but not this one. Its commit message
	 * claimed the Outbound suite was green; it had been run filtered. The
	 * assertions below now pin the policy the code actually implements.
	 *
	 * An upgraded site is the case that matters: it has no stored option row, so it
	 * takes the fallback, and the fallback is what changed.
	 *
	 * @return void
	 */
	public function test_legacy_unstamped_request_is_refused_by_default(): void {
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );

		$response = rest_do_request( $this->signed_request( $this->grant_body(), null ) );

		$this->assertSame(
			401,
			$response->get_status(),
			'An unstamped signature cannot be replay-checked, so it is refused even on upgrade.'
		);
	}

	/**
	 * The escape hatch still works, and still records who is using it.
	 *
	 * The deprecation row is the reason the hatch is safe to offer: an owner who
	 * opens it can see which integrators have not moved, rather than leaving it
	 * open forever because nobody knows who would break.
	 *
	 * @return void
	 */
	public function test_the_opt_out_accepts_a_legacy_request_and_logs_it(): void {
		global $wpdb;

		update_option( AccessWebhookController::OPT_STRICT_SIGNATURES, '0' );

		$response = rest_do_request( $this->signed_request( $this->grant_body(), null ) );

		$this->assertSame( 200, $response->get_status(), 'The documented opt-out must still let a straggler through.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT status FROM {$wpdb->prefix}bn_webhook_log WHERE action = 'signature_scheme_deprecated'", ARRAY_A );

		$this->assertCount( 1, $rows );
		$this->assertSame( 'error', $rows[0]['status'] );
	}

	/**
	 * An owner who has finished migrating can close the window today.
	 *
	 * @return void
	 */
	public function test_strict_mode_refuses_the_legacy_scheme(): void {
		update_option( AccessWebhookController::OPT_STRICT_SIGNATURES, true );

		$response = rest_do_request( $this->signed_request( $this->grant_body(), null ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'timestamp_required', $response->get_data()['code'] ?? '' );
	}

	/**
	 * A wrong secret fails whichever scheme it is sent with.
	 *
	 * @return void
	 */
	public function test_a_bad_signature_is_still_refused(): void {
		$request = $this->signed_request( $this->grant_body(), time() );
		$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . str_repeat( 'a', 64 ) );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
	}
}
