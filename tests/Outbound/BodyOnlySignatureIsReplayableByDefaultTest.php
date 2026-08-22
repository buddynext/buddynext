<?php
/**
 * A signed inbound webhook must not be replayable on a default install.
 *
 * Reproduces card 10227863022 as a code flow.
 *
 * `verify_signature()` prefers the timestamped scheme — HMAC of
 * `{timestamp}.{body}`, with a freshness window and a replay log — and that half
 * works. The leftover is the fallback: when `X-BuddyNext-Timestamp` is absent it
 * accepts an HMAC of the body ALONE, and that path is reached unless the site
 * owner has turned on `buddynext_webhook_strict_signatures`, whose default is
 * `false`.
 *
 * A body-only signature has nothing time-varying in it, so the same bytes verify
 * forever. `$this->accepted_signature` is deliberately set to `''` on that branch,
 * which means the replay log never records it and cannot refuse it next time. One
 * captured `grant_ability` or `set_role` call can therefore be resent
 * indefinitely.
 *
 * ## Scope, stated precisely
 *
 * This is not an unauthenticated hole. With no secret configured the route
 * answers 503, and forging a signature still needs the secret. It matters for
 * sites that already configured an inbound webhook — exactly the sites where the
 * route grants privileges — and where a request was captured in transit or from a
 * log.
 *
 * The existing `WebhookReplayProtectionTest` covers the NEW scheme. Nothing
 * covered the default.
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
class BodyOnlySignatureIsReplayableByDefaultTest extends WP_UnitTestCase {

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
	 * On an UPGRADED site, dual-accept is still on and the bytes replay.
	 *
	 * Kept as a passing description of a known, deliberate state rather than a
	 * failing assertion: existing sites have senders built against the body-only
	 * scheme, and flipping the default underneath them would 401 every one of their
	 * calls on upgrade. They keep dual-accept, with the deprecation warning already
	 * written to the webhook log per request, until the default flips for everyone.
	 *
	 * What this pins is that the exposure is EXACTLY replay of a legitimately-signed
	 * request - not forgery, and not an unauthenticated hole.
	 *
	 * @return void
	 */
	public function test_an_upgraded_site_still_accepts_the_replayable_legacy_scheme(): void {
		$body = wp_json_encode(
			array(
				'source' => 'partner',
				'action' => 'grant_ability',
				'email'  => 'victim@example.com',
			)
		);

		$first  = $this->verify( $this->body_only_request( (string) $body ) );
		$second = $this->verify( $this->body_only_request( (string) $body ) );

		$this->assertTrue( true === $first, 'Precondition: the first body-only request must be accepted, or the replay below proves nothing.' );

		$this->assertTrue(
			true === $second,
			'Documented state, not an aspiration: an upgraded site accepts the replay. If this ever starts failing, the default was flipped for existing sites and every legacy sender broke on upgrade - which is the thing this arrangement exists to avoid.'
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
