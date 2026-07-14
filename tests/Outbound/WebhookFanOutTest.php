<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Webhook delivery must fan out, not queue endpoints behind each other.
 *
 * @package BuddyNext\Tests\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Tests\Outbound;

use BuddyNext\Core\Installer;
use BuddyNext\Outbound\OutboundWebhookService;
use WP_UnitTestCase;

/**
 * N endpoints used to mean N SEQUENTIAL BLOCKING sends inside ONE job.
 *
 * Each send blocks with a 5-second timeout. Free caps registrations at 1, so it never showed.
 * Pro removes the cap entirely — that IS the feature — so an owner with 50 endpoints turned a
 * single Action Scheduler job into up to 250 seconds of serial blocking HTTP.
 *
 * That is not merely slow. One endpoint that hangs delays delivery to every OTHER endpoint
 * behind it in the same job, and a job that runs long enough to be killed takes the
 * undelivered remainder with it. The endpoints are independent; the loop is what made them a
 * queue.
 *
 * @covers \BuddyNext\Outbound\OutboundWebhookService::run_delivery
 */
class WebhookFanOutTest extends WP_UnitTestCase {

	/**
	 * Fresh schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		Installer::install_schema();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}bn_outbound_webhooks" );
	}

	/**
	 * Register an active endpoint subscribed to all events.
	 *
	 * @param string $url Endpoint URL.
	 * @return int
	 */
	private function seed_endpoint( string $url ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_outbound_webhooks',
			array(
				'url'       => $url,
				'secret'    => 'shh',
				'events'    => '[]',
				'is_active' => 1,
			)
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Three endpoints produce three jobs — and ZERO outbound HTTP in the dispatching job.
	 *
	 * The HTTP assertion is the one that matters: if run_delivery() still sent inline, the
	 * http_api filter below would fire, and one slow endpoint would still be able to stall the
	 * others.
	 *
	 * @return void
	 */
	public function test_delivery_fans_out_one_job_per_endpoint(): void {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}

		$this->seed_endpoint( 'https://one.example.test/hook' );
		$this->seed_endpoint( 'https://two.example.test/hook' );
		$this->seed_endpoint( 'https://three.example.test/hook' );

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$http_calls ) {
				++$http_calls;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			}
		);

		( new OutboundWebhookService() )->run_delivery( 'post.created', array( 'id' => 1 ) );

		$this->assertSame(
			0,
			$http_calls,
			'run_delivery() is still sending HTTP inline. N endpoints = N sequential BLOCKING sends in one job, so one hanging endpoint delays every endpoint behind it.'
		);

		$queued = as_get_scheduled_actions(
			array(
				'hook'   => 'buddynext_webhook_deliver_one',
				'status' => \ActionScheduler_Store::STATUS_PENDING,
				'group'  => 'buddynext',
			),
			'ids'
		);

		$this->assertCount(
			3,
			(array) $queued,
			'Each endpoint must get its own job, so its latency and its failures are its own.'
		);
	}

	/**
	 * The per-endpoint worker re-reads the row, so a just-deactivated endpoint is not called.
	 *
	 * Between fan-out and execution the owner may have switched the endpoint off. Delivering
	 * to it anyway is exactly the kind of thing an owner reports as a bug.
	 *
	 * @return void
	 */
	public function test_a_deactivated_endpoint_is_not_delivered_to(): void {
		global $wpdb;

		$id = $this->seed_endpoint( 'https://gone.example.test/hook' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'bn_outbound_webhooks', array( 'is_active' => 0 ), array( 'id' => $id ) );

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt ) use ( &$http_calls ) {
				++$http_calls;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => '',
				);
			}
		);

		( new OutboundWebhookService() )->run_delivery_one( $id, 'post.created', array( 'id' => 1 ) );

		$this->assertSame( 0, $http_calls, 'An endpoint the owner switched off was still called.' );
	}
}
