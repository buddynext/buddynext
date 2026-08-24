<?php
/**
 * A signed inbound webhook is NOT replayable on a default install.
 *
 * Closes card 10227863022. `verify_signature()` prefers the timestamped scheme —
 * HMAC of `{timestamp}.{body}`, with a freshness window and a replay log. When
 * `X-BuddyNext-Timestamp` is absent it CAN still accept an HMAC of the body ALONE
 * (which has nothing time-varying in it, so the same bytes verify forever) — but
 * only if a site has explicitly re-enabled that legacy scheme. As of 1.1.6 the
 * default is strict: `buddynext_webhook_strict_signatures` reads `true` when the
 * row is absent, so a fresh install AND an upgraded site both refuse body-only.
 *
 * The owner-chosen flip (Basecamp 10227863022): the earlier design left upgraded
 * sites dual-accepting the legacy scheme so a pre-1.1.6 sender was not broken on
 * upgrade. That window is now closed everywhere; a straggler mid-migration opts
 * out by setting the option to '0' (or via the `buddynext_require_signed_timestamp`
 * filter), which this test also pins.
 *
 * ## Scope, stated precisely
 *
 * This was never an unauthenticated hole. With no secret configured the route
 * answers 503, and forging a signature still needs the secret. It mattered for
 * sites that already configured an inbound webhook and where a request was
 * captured in transit or from a log. The exposure is EXACTLY replay of a
 * legitimately-signed body-only request — and it is closed by default now.
 *
 * The existing `WebhookReplayProtectionTest` covers the NEW scheme. This covers
 * the default and the opt-out.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Outbound\AccessWebhookController;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Inbound webhook signature schemes and replay.
 *
 * @covers \BuddyNext\Outbound\AccessWebhookController::verify_signature
 */
class BodyOnlySignatureIsRejectedByDefaultTest extends WP_UnitTestCase {

	/**
	 * The shared secret.
	 *
	 * @var string
	 */
	private const SECRET = 'a-configured-inbound-secret';

	/**
	 * The option holding it.
	 *
	 * @var string
	 */
	private string $secret_option = '';

	/**
	 * Configure a secret the way a site with an inbound integration would.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		// verify_signature() reads this option name as a literal, not via a
		// constant. The first draft searched for a *SECRET* class constant, found
		// none, and skipped all three tests - a run that looked fine and covered
		// nothing.
		$this->secret_option = 'buddynext_webhook_secret';
		update_option( $this->secret_option, self::SECRET );
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );
	}

	/**
	 * Clean up.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( $this->secret_option );
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );
		delete_option( 'buddynext_db_version' );
		parent::tear_down();
	}

	/**
	 * A request carrying only a body-signature, as a pre-1.1.6 sender would send.
	 *
	 * @param string $body JSON body.
	 * @return WP_REST_Request
	 */
	private function body_only_request( string $body ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/access-webhook' );
		$request->set_body( $body );
		$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . hash_hmac( 'sha256', $body, self::SECRET ) );

		return $request;
	}

	/**
	 * Run the signature check.
	 *
	 * @param WP_REST_Request $request Request to verify.
	 * @return mixed true, false, or WP_Error.
	 */
	private function verify( WP_REST_Request $request ) {
		$method = new \ReflectionMethod( AccessWebhookController::class, 'verify_signature' );
		$method->setAccessible( true );

		return $method->invoke( new AccessWebhookController(), $request );
	}

	/**
	 * On an UPGRADED site with no stored option, the legacy scheme is now REFUSED.
	 *
	 * The owner-chosen flip (Basecamp 10227863022): the option default is `true`,
	 * so an upgraded site that never stored a row is strict, exactly like a fresh
	 * install. A body-only request is refused (401 timestamp_required) rather than
	 * accepted-and-replayable. This is the tripwire that used to assert the opposite
	 * — inverted deliberately when the default was flipped, not weakened.
	 *
	 * @return void
	 */
	public function test_an_upgraded_site_now_refuses_the_legacy_scheme_by_default(): void {
		// No option row — the upgraded-site state the fallback default governs.
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );

		$body = wp_json_encode(
			array(
				'source' => 'partner',
				'action' => 'grant_ability',
				'email'  => 'victim@example.com',
			)
		);

		$this->assertNotTrue(
			$this->verify( $this->body_only_request( (string) $body ) ),
			'An upgraded site with no stored option must now refuse the body-only scheme by default.'
		);
	}

	/**
	 * A straggler mid-migration can still opt OUT: with the option set to '0', an
	 * upgraded site keeps accepting the legacy body-only scheme while it finishes
	 * moving its senders to the timestamped one. This is the escape hatch the flip
	 * kept.
	 *
	 * @return void
	 */
	public function test_the_opt_out_still_accepts_the_legacy_scheme(): void {
		update_option( AccessWebhookController::OPT_STRICT_SIGNATURES, '0' );

		$this->assertTrue(
			true === $this->verify( $this->body_only_request( '{"source":"partner"}' ) ),
			'With strict signatures explicitly turned off, a correctly body-only-signed request is still accepted.'
		);
	}

	/**
	 * A FRESH install starts closed, so it is never exposed at all.
	 *
	 * The whole fix. A new site has no senders built against the old scheme, so it
	 * can require the timestamped one from day one and never needs a migration.
	 *
	 * @return void
	 */
	public function test_a_fresh_install_requires_the_timestamped_scheme(): void {
		delete_option( AccessWebhookController::OPT_STRICT_SIGNATURES );
		delete_option( 'buddynext_db_version' );

		\BuddyNext\Core\Installer::run();

		$this->assertTrue(
			(bool) get_option( AccessWebhookController::OPT_STRICT_SIGNATURES ),
			'A fresh install must default to strict signatures - a new site has no legacy senders to protect.'
		);

		$this->assertNotTrue(
			$this->verify( $this->body_only_request( '{"source":"partner"}' ) ),
			'With the fresh-install default in place, the replayable scheme must be refused.'
		);
	}

	/**
	 * Upgrading must NOT flip it underneath an existing site.
	 *
	 * What makes that true is the `$is_fresh_install` gate in `Installer::run()`,
	 * which keys on `buddynext_db_version` already existing - not the `add_option()`
	 * call itself. Verified by mutation: swapping add_option for update_option
	 * leaves this test green, because on an upgrade the whole block is skipped and
	 * neither call runs. The add_option is belt-and-braces inside a branch that
	 * cannot execute here, and it is worth knowing which of the two is load-bearing
	 * before anyone "simplifies" the gate away.
	 *
	 * @return void
	 */
	public function test_an_upgrade_does_not_flip_an_existing_sites_setting(): void {
		// An UPGRADE is defined by buddynext_db_version already existing - that is
		// what Installer::run() keys $is_fresh_install on. The test DB has no such
		// option, so without this line every run() here looks like a fresh install
		// and this test would be measuring the wrong branch entirely.
		update_option( 'buddynext_db_version', '1.0.0' );

		// Stored as '0', not as false. `update_option( $key, false )` writes NOTHING
		// when the option is absent - WordPress short-circuits on "the value is
		// already false" and a missing option reads as false - so the first draft of
		// this test created no row at all and then watched add_option() legitimately
		// succeed, failing while the code under test was correct. '0' is falsy and
		// unambiguously present.
		update_option( AccessWebhookController::OPT_STRICT_SIGNATURES, '0' );
		$this->assertNotFalse(
			get_option( AccessWebhookController::OPT_STRICT_SIGNATURES, false ),
			'Fixture: the option row must exist for this to be an upgrade scenario.'
		);

		\BuddyNext\Core\Installer::run();

		$this->assertFalse(
			(bool) get_option( AccessWebhookController::OPT_STRICT_SIGNATURES ),
			'Running the installer over an existing site turned strict signatures on and broke its senders.'
		);
	}

	/**
	 * Turning the option on closes it — so the mechanism exists, it is just off.
	 *
	 * @return void
	 */
	public function test_strict_mode_refuses_the_body_only_scheme(): void {
		update_option( AccessWebhookController::OPT_STRICT_SIGNATURES, true );

		$result = $this->verify( $this->body_only_request( '{"source":"partner"}' ) );

		$this->assertNotTrue( $result, 'With strict signatures on, a body-only request must be refused.' );
	}

	/**
	 * A wrong signature is still refused, strict or not. Guards the guard.
	 *
	 * @return void
	 */
	public function test_a_forged_signature_is_still_refused(): void {
		$request = new WP_REST_Request( 'POST', '/buddynext/v1/access-webhook' );
		$request->set_body( '{"source":"partner"}' );
		$request->set_header( 'X-BuddyNext-Signature', 'sha256=' . str_repeat( '0', 64 ) );

		$this->assertNotTrue( $this->verify( $request ), 'An incorrect signature must never verify.' );
	}
}
