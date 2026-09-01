<?php
/**
 * Access grants leave a trail whichever door they came through.
 *
 * `bn_webhook_log` was written from one place — the end of the signed webhook
 * request handler. `buddynext_ability_granted` is a plain action though, and a
 * plugin on the same site grants by firing it directly, with no request behind
 * it. Those grants were recorded nowhere.
 *
 * The fix has two halves that pull against each other, so both are pinned here:
 * the action must be logged, and an HTTP grant must not be logged twice now that
 * two things are watching it.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Outbound\WebhookLog;

/**
 * Logging of in-process ability grants.
 *
 * @covers \BuddyNext\Outbound\WebhookLogListener
 * @covers \BuddyNext\Outbound\WebhookLog
 */
class WebhookLogListenerTest extends \WP_UnitTestCase {

	/**
	 * User the grants are about.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Register the listener and start from an empty log.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$this->user_id = self::factory()->user->create();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_webhook_log" );

		// Deliberately NOT registering a listener here. Plugin::init() already
		// wired one at boot, and a second instance is a different callable to
		// add_action(), so WP keeps both and every grant lands twice. That is the
		// double-logging this class exists to prevent, and building it into the
		// fixture would have hidden it.
		WebhookLog::end_request_scope();
	}

	/**
	 * Leave no scope flag behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		WebhookLog::end_request_scope();
		parent::tear_down();
	}

	/**
	 * Read the log.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT action, user_id, source, status, payload FROM {$wpdb->prefix}bn_webhook_log ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * The door that had no trail now has one.
	 *
	 * @return void
	 */
	public function test_in_process_grant_is_logged(): void {
		do_action( 'buddynext_ability_granted', $this->user_id, 'tier:gold', 'pmpro' );

		$rows = $this->rows();

		$this->assertCount( 1, $rows, 'A same-site grant must leave a row.' );
		$this->assertSame( 'grant_ability', $rows[0]['action'] );
		$this->assertSame( (string) $this->user_id, (string) $rows[0]['user_id'] );
		$this->assertSame( 'pmpro', $rows[0]['source'], 'The caller named itself; the log must keep that.' );
		$this->assertStringContainsString( 'tier:gold', (string) $rows[0]['payload'] );
	}

	/**
	 * Revokes matter as much as grants — losing access unrecorded is worse.
	 *
	 * @return void
	 */
	public function test_in_process_revoke_is_logged(): void {
		do_action( 'buddynext_ability_revoked', $this->user_id, 'tier:gold' );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'revoke_ability', $rows[0]['action'] );
	}

	/**
	 * The other half: two watchers must not both write.
	 *
	 * The controller logs the whole signed body itself. While it is dispatching,
	 * the listener stands down — otherwise every HTTP grant produces two rows,
	 * which is the defect pattern already filed against the email log.
	 *
	 * @return void
	 */
	public function test_grant_inside_a_webhook_request_is_not_double_logged(): void {
		WebhookLog::begin_request_scope();
		do_action( 'buddynext_ability_granted', $this->user_id, 'tier:gold', 'stripe' );
		WebhookLog::end_request_scope();

		$this->assertCount(
			0,
			$this->rows(),
			'Inside a webhook request the controller owns the row; the listener must stay quiet.'
		);
	}

	/**
	 * The scope must not leak past the request that opened it.
	 *
	 * @return void
	 */
	public function test_logging_resumes_after_the_request_scope_closes(): void {
		WebhookLog::begin_request_scope();
		do_action( 'buddynext_ability_granted', $this->user_id, 'tier:gold', 'stripe' );
		WebhookLog::end_request_scope();

		do_action( 'buddynext_ability_granted', $this->user_id, 'tier:silver', 'woocommerce' );

		$rows = $this->rows();

		$this->assertCount( 1, $rows, 'Only the grant fired outside the request should be recorded.' );
		$this->assertSame( 'woocommerce', $rows[0]['source'] );
	}

	/**
	 * A grant with no source is still recorded, just anonymously.
	 *
	 * @return void
	 */
	public function test_grant_without_a_source_is_still_logged(): void {
		do_action( 'buddynext_ability_granted', $this->user_id, 'tier:gold', '' );

		$rows = $this->rows();

		$this->assertCount( 1, $rows );
		$this->assertSame( '', $rows[0]['source'] );
	}
}
