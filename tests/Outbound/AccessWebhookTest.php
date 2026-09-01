<?php
/**
 * Tests for the POST buddynext/v1/webhook/access endpoint.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Core\Installer;
use BuddyNext\Core\PermissionService;
use BuddyNext\REST\Router;

/**
 * @covers \BuddyNext\Outbound\AccessWebhookController
 */
class AccessWebhookTest extends \WP_Test_REST_TestCase {

	private int $user_id;

	/** @var string */
	private string $secret = 'phpunit-webhook-secret';

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		update_option( 'buddynext_webhook_secret', $this->secret );
		$this->user_id = self::factory()->user->create();
		update_user_meta( $this->user_id, 'bn_community_role', 'member' );
		( new Router() )->register();
	}

	/**
	 * Build and dispatch a signed (or intentionally bad) webhook request.
	 *
	 * Signs with the TIMESTAMPED scheme - HMAC of `{timestamp}.{body}` plus an
	 * `X-BuddyNext-Timestamp` header - because that is what a site is expected to
	 * send. These calls used the body-only scheme, which a fresh install now
	 * refuses outright (`buddynext_webhook_strict_signatures` is seeded on for new
	 * sites, so a captured request cannot be replayed for ever). The body-only
	 * scheme keeps its own coverage in
	 * BodyOnlySignatureIsRejectedByDefaultTest, where it is the subject rather
	 * than the transport.
	 *
	 * Each call gets a fresh timestamp so the replay log does not reject the second
	 * identical request in a test that makes two.
	 *
	 * @param array $body       Request payload.
	 * @param bool  $valid_sig  Whether to use the correct HMAC signature.
	 * @return \WP_REST_Response
	 */
	private function call( array $body, bool $valid_sig = true ): \WP_REST_Response {
		$payload   = (string) wp_json_encode( $body );
		$timestamp = (string) time();
		$signature = $valid_sig
			? 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $payload, $this->secret )
			: 'sha256=bad_signature';

		$request = new \WP_REST_Request( 'POST', '/buddynext/v1/webhook/access' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'X-BuddyNext-Timestamp', $timestamp );
		$request->set_header( 'X-BuddyNext-Signature', $signature );
		$request->set_body( $payload );

		return rest_do_request( $request );
	}

	public function test_invalid_signature_returns_401(): void {
		$response = $this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'set_role',
				'role'    => 'admin',
			),
			false
		);

		$this->assertSame( 401, $response->get_status() );
	}

	public function test_set_role(): void {
		$response = $this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'set_role',
				'role'    => 'moderator',
				'source'  => 'phpunit',
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'moderator', get_user_meta( $this->user_id, 'bn_community_role', true ) );
	}

	public function test_grant_and_revoke_ability(): void {
		$service = new PermissionService();

		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'grant_ability',
				'ability' => 'buddynext-spaces/join-gated',
				'source'  => 'phpunit',
			)
		);

		$this->assertTrue( $service->can( $this->user_id, 'buddynext-spaces/join-gated' ) );

		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'revoke_ability',
				'ability' => 'buddynext-spaces/join-gated',
				'source'  => 'phpunit',
			)
		);

		$this->assertFalse( $service->can( $this->user_id, 'buddynext-spaces/join-gated' ) );
	}

	public function test_add_credits(): void {
		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'add_credits',
				'amount'  => 50,
				'source'  => 'phpunit',
			)
		);

		$balance = (int) get_user_meta( $this->user_id, 'bn_credits', true );

		$this->assertSame( 50, $balance );
	}

	public function test_set_credits(): void {
		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'add_credits',
				'amount'  => 100,
				'source'  => 'phpunit',
			)
		);
		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'set_credits',
				'amount'  => 25,
				'source'  => 'phpunit',
			)
		);

		$balance = (int) get_user_meta( $this->user_id, 'bn_credits', true );

		$this->assertSame( 25, $balance );
	}

	public function test_every_call_is_logged(): void {
		global $wpdb;

		$this->call(
			array(
				'user_id' => $this->user_id,
				'action'  => 'set_role',
				'role'    => 'member',
				'source'  => 'phpunit',
			)
		);

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}bn_webhook_log WHERE action = 'set_role'"
		);

		$this->assertGreaterThan( 0, $count );
	}
}
