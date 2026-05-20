<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Outbound webhook dispatch service.
 *
 * Manages registered external endpoints in bn_outbound_webhooks, dispatches
 * HMAC-SHA256-signed HTTP POST payloads on BuddyNext lifecycle events, logs
 * every attempt in bn_outbound_webhook_log, and retries recent failures via a
 * five-minute WP-Cron job. Endpoints that accumulate three consecutive delivery
 * failures are automatically deactivated.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use WP_Error;

/**
 * Registers, dispatches, logs, and retries outbound webhooks.
 */
class OutboundWebhookService {

	/**
	 * Maximum characters stored for response bodies in bn_outbound_webhook_log.
	 */
	private const RESPONSE_BODY_MAX = 1000;

	/**
	 * HTTP POST timeout in seconds.
	 */
	private const HTTP_TIMEOUT = 5;

	/**
	 * Consecutive failure threshold before an endpoint is deactivated.
	 */
	private const MAX_CONSECUTIVE_FAILURES = 3;

	/**
	 * Register the five-minute WP-Cron schedule, schedule the retry event, and
	 * bind the retry handler. Called once during Plugin::init().
	 */
	public function init(): void {
		// Defer wp_schedule_event() to the init hook so it runs after after_setup_theme.
		// Calling it during plugins_loaded triggers cron_schedules → __() calls before
		// textdomains are loaded, causing _load_textdomain_just_in_time notices in WP 6.7+.
		add_action( 'init', array( $this, 'schedule_cron' ) );

		add_action( 'buddynext_webhook_retry', array( $this, 'retry_failed' ) );
	}

	/**
	 * Schedule the webhook retry cron event. Deferred to the init hook.
	 */
	public function schedule_cron(): void {
		// buddynext_5min schedule is registered by CronScheduler — no duplicate needed here.
		if ( ! wp_next_scheduled( 'buddynext_webhook_retry' ) ) {
			wp_schedule_event( time(), 'buddynext_5min', 'buddynext_webhook_retry' );
		}
	}

	// ── Registration ──────────────────────────────────────────────────────────

	/**
	 * Register a new webhook endpoint.
	 *
	 * The URL must be a valid https:// address. The label is derived from the
	 * URL hostname and the registering user's display name.
	 *
	 * @param string        $url        Target URL — must begin with https://.
	 * @param string        $secret     HMAC signing secret.
	 * @param array<string> $events     Event slugs to subscribe to; empty = all events.
	 * @param int           $created_by Admin user ID used to build the label.
	 * @return int|WP_Error Inserted webhook ID on success, WP_Error on failure.
	 */
	public function register( string $url, string $secret, array $events, int $created_by ): int|WP_Error {
		if ( ! str_starts_with( $url, 'https://' ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Webhook URL must begin with https://.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new WP_Error(
				'invalid_url',
				__( 'Webhook URL is not a valid URL.', 'buddynext' ),
				array( 'status' => 422 )
			);
		}

		/**
		 * Filter the maximum number of outbound webhook endpoints a site may register.
		 *
		 * Free limits sites to 1 webhook. Pro lifts this cap by returning PHP_INT_MAX
		 * or a higher integer. The count is checked against all registered endpoints
		 * (active or inactive) in bn_outbound_webhooks.
		 *
		 * @since 1.0.0
		 *
		 * @param int $limit Maximum webhook registrations allowed. Default 1.
		 */
		$webhook_limit = (int) apply_filters( 'buddynext_outbound_webhook_limit', 1 );

		if ( $webhook_limit > 0 ) {
			global $wpdb;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$current_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_outbound_webhooks" );

			if ( $current_count >= $webhook_limit ) {
				return new WP_Error(
					'webhook_limit_reached',
					__( 'You have reached the maximum number of registered webhook endpoints.', 'buddynext' ),
					array( 'status' => 422 )
				);
			}
		}

		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$user  = get_userdata( $created_by );
		$label = sprintf(
			'%s — %s',
			'' !== $host ? $host : $url,
			$user ? $user->display_name : 'User ' . $created_by
		);

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'bn_outbound_webhooks',
			array(
				'label'     => $label,
				'url'       => $url,
				'secret'    => $secret,
				'events'    => wp_json_encode( array_values( array_unique( $events ) ) ),
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( false === $inserted ) {
			return new WP_Error(
				'db_insert_failed',
				__( 'Failed to register webhook endpoint.', 'buddynext' ),
				array( 'status' => 500 )
			);
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * List all registered webhook endpoints ordered by id ascending.
	 *
	 * Decodes the events JSON column to an array for each row.
	 *
	 * @return array<array<string,mixed>>
	 */
	public function list_all(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, label, url, secret, events, is_active, created_at, updated_at
			   FROM {$wpdb->prefix}bn_outbound_webhooks
			  ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$decoded       = json_decode( (string) ( $row['events'] ?? 'null' ), true );
			$row['events'] = is_array( $decoded ) ? $decoded : array();
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Delete a webhook endpoint and all its log entries.
	 *
	 * Log entries are removed first to avoid orphaned rows.
	 *
	 * @param int $webhook_id Webhook row ID.
	 * @return bool True when the endpoint was deleted, false when not found.
	 */
	public function delete( int $webhook_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$wpdb->prefix . 'bn_outbound_webhook_log',
			array( 'webhook_id' => $webhook_id ),
			array( '%d' )
		);

		$deleted = $wpdb->delete(
			$wpdb->prefix . 'bn_outbound_webhooks',
			array( 'id' => $webhook_id ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Get paginated delivery log for a specific webhook endpoint.
	 *
	 * @param int $webhook_id Webhook row ID.
	 * @param int $per_page   Rows per page; clamped to 1–100.
	 * @param int $page       1-based page number.
	 * @return array{items: array<array<string,mixed>>, total: int}
	 */
	public function get_log( int $webhook_id, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}bn_outbound_webhook_log WHERE webhook_id = %d",
				$webhook_id
			)
		);

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, webhook_id, event, response_code, response_body, status, created_at
				   FROM {$wpdb->prefix}bn_outbound_webhook_log
				  WHERE webhook_id = %d
				  ORDER BY id DESC
				  LIMIT %d OFFSET %d",
				$webhook_id,
				$per_page,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Dispatch an event to all matching active endpoints.
	 *
	 * Webhooks with an empty events array receive all events. Webhooks with a
	 * non-empty events array receive only the events listed.
	 *
	 * @param string              $event_slug Event slug, e.g. 'member.registered'.
	 * @param array<string,mixed> $payload    Event-specific data.
	 */
	public function dispatch( string $event_slug, array $payload ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$webhooks = $wpdb->get_results(
			"SELECT id, url, secret, events
			   FROM {$wpdb->prefix}bn_outbound_webhooks
			  WHERE is_active = 1",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $webhooks ) ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			$subscribed = json_decode( (string) ( $webhook['events'] ?? 'null' ), true );

			$matches_all   = ! is_array( $subscribed ) || count( $subscribed ) === 0;
			$matches_event = is_array( $subscribed ) && in_array( $event_slug, $subscribed, true );

			if ( ! $matches_all && ! $matches_event ) {
				continue;
			}

			$this->deliver(
				(int) $webhook['id'],
				(string) $webhook['url'],
				(string) $webhook['secret'],
				$event_slug,
				$payload
			);
		}
	}

	/**
	 * Send a test ping to a specific webhook endpoint.
	 *
	 * Returns true when the endpoint responds with a 2xx HTTP status code.
	 *
	 * @param int $webhook_id Webhook row ID.
	 * @return bool True on 2xx response, false on error or non-2xx.
	 */
	public function send_test_ping( int $webhook_id ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$webhook = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, secret FROM {$wpdb->prefix}bn_outbound_webhooks WHERE id = %d LIMIT 1",
				$webhook_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $webhook ) ) {
			return false;
		}

		$code = $this->deliver(
			(int) $webhook['id'],
			(string) $webhook['url'],
			(string) $webhook['secret'],
			'ping',
			array( 'message' => 'BuddyNext webhook test ping' )
		);

		return $code >= 200 && $code < 300;
	}

	/**
	 * Retry all failed deliveries from the past 24 hours.
	 *
	 * Selects error log rows joined to still-active webhooks, extracts the
	 * original event slug and data from the stored payload envelope, and
	 * re-delivers each one. Called by the buddynext_webhook_retry cron action.
	 */
	public function retry_failed(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$failed = $wpdb->get_results(
			"SELECT l.webhook_id, l.event, l.payload, w.url, w.secret
			   FROM {$wpdb->prefix}bn_outbound_webhook_log l
			   JOIN {$wpdb->prefix}bn_outbound_webhooks w ON w.id = l.webhook_id
			  WHERE l.status = 'error'
			    AND l.created_at > DATE_SUB( NOW(), INTERVAL 24 HOUR )
			    AND w.is_active = 1
			  ORDER BY l.id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( empty( $failed ) ) {
			return;
		}

		foreach ( $failed as $row ) {
			$envelope = json_decode( (string) ( $row['payload'] ?? '{}' ), true );
			if ( ! is_array( $envelope ) ) {
				continue;
			}

			$event_slug = isset( $envelope['event'] ) ? (string) $envelope['event'] : (string) $row['event'];
			$data       = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

			$this->deliver(
				(int) $row['webhook_id'],
				(string) $row['url'],
				(string) $row['secret'],
				$event_slug,
				$data
			);
		}
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	/**
	 * Build, sign, POST and log a single webhook delivery.
	 *
	 * The request body is a JSON envelope: { event, timestamp, data }. An
	 * HMAC-SHA256 signature over the raw body string is sent via the
	 * X-BuddyNext-Signature header. On failed delivery the consecutive failure
	 * count is checked; three failures trigger automatic deactivation.
	 *
	 * @param int                 $webhook_id Webhook row ID.
	 * @param string              $url        Destination URL.
	 * @param string              $secret     HMAC signing secret.
	 * @param string              $event_slug Event type slug.
	 * @param array<string,mixed> $data       Event-specific data to include.
	 * @return int HTTP response code, or 0 on a network-level error.
	 */
	private function deliver( int $webhook_id, string $url, string $secret, string $event_slug, array $data ): int {
		global $wpdb;

		$envelope = array(
			'event'     => $event_slug,
			'timestamp' => time(),
			'data'      => $data,
		);

		$body = wp_json_encode( $envelope );
		if ( false === $body ) {
			return 0;
		}

		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => self::HTTP_TIMEOUT,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'          => 'application/json',
					'X-BuddyNext-Signature' => $signature,
					'X-BuddyNext-Event'     => $event_slug,
				),
				'body'     => $body,
			)
		);

		$is_error      = is_wp_error( $response );
		$response_code = $is_error ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$response_body = $is_error
			? $response->get_error_message()
			: wp_remote_retrieve_body( $response );

		if ( strlen( $response_body ) > self::RESPONSE_BODY_MAX ) {
			$response_body = substr( $response_body, 0, self::RESPONSE_BODY_MAX );
		}

		$success = ! $is_error && $response_code >= 200 && $response_code < 300;
		$status  = $success ? 'success' : 'error';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_outbound_webhook_log',
			array(
				'webhook_id'    => $webhook_id,
				'event'         => $event_slug,
				'payload'       => $body,
				'response_code' => $response_code > 0 ? $response_code : null,
				'response_body' => $response_body,
				'status'        => $status,
			),
			array(
				'%d',
				'%s',
				'%s',
				$response_code > 0 ? '%d' : '%s',
				'%s',
				'%s',
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		if ( ! $success ) {
			$this->maybe_deactivate_on_failure( $webhook_id );
		}

		return $response_code;
	}

	/**
	 * Deactivate a webhook when its last N deliveries were all failures.
	 *
	 * @param int $webhook_id Webhook row ID.
	 */
	private function maybe_deactivate_on_failure( int $webhook_id ): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$error_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM (
				       SELECT status
				         FROM {$wpdb->prefix}bn_outbound_webhook_log
				        WHERE webhook_id = %d
				        ORDER BY id DESC
				        LIMIT %d
				   ) AS recent
				  WHERE status = 'error'",
				$webhook_id,
				self::MAX_CONSECUTIVE_FAILURES
			)
		);

		if ( $error_count >= self::MAX_CONSECUTIVE_FAILURES ) {
			$wpdb->update(
				$wpdb->prefix . 'bn_outbound_webhooks',
				array( 'is_active' => 0 ),
				array( 'id' => $webhook_id ),
				array( '%d' ),
				array( '%d' )
			);
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
