<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Outbound webhook dispatch service.
 *
 * Manages registered external endpoints in bn_outbound_webhooks, dispatches
 * HMAC-SHA256-signed HTTP POST payloads on BuddyNext lifecycle events, logs
 * every attempt in bn_outbound_webhook_log, and retries failures reactively via
 * a single wp_schedule_single_event per failed delivery (with exponential backoff)
 * instead of a perpetual polling cron. Endpoints that accumulate three consecutive
 * delivery failures are automatically deactivated.
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
	 * Transient holding the active-endpoint count, so dispatch() on a site with
	 * no webhooks costs no query per lifecycle event. Self-heals within the TTL
	 * and is flushed explicitly on register/delete/auto-deactivate.
	 */
	private const ACTIVE_COUNT_CACHE_KEY = 'bn_outbound_webhooks_active_count';

	/**
	 * Cron hook that performs the (off-request) outbound delivery for one event.
	 */
	private const DELIVER_HOOK = 'buddynext_webhook_deliver';

	/**
	 * Object-cache group for the atomic dispatch de-dupe lock.
	 */
	private const DISPATCH_LOCK_GROUP = 'buddynext_webhook';

	/**
	 * Single-event cron hook for a per-delivery retry with backoff.
	 *
	 * Fires once per failed delivery. Arguments: (int $webhook_id, string $event_slug,
	 * array $payload, int $attempt). The handler reschedules itself (up to
	 * MAX_RETRY_ATTEMPTS times) with exponential backoff so failed deliveries
	 * are retried without a perpetual polling cron.
	 */
	private const RETRY_HOOK = 'buddynext_webhook_retry_single';

	/**
	 * Maximum number of single-event retry attempts before giving up.
	 */
	private const MAX_RETRY_ATTEMPTS = 3;

	/**
	 * Base backoff delay in seconds for the first retry attempt.
	 * Subsequent attempts multiply this by 2^(attempt-1): 300s, 600s, 1200s.
	 */
	private const RETRY_BASE_DELAY = 300;

	/**
	 * Bind handler hooks. Called once during Plugin::init().
	 *
	 * No recurring cron is registered here. Failed deliveries schedule individual
	 * single-events via maybe_schedule_retry() inside deliver().
	 */
	public function init(): void {
		// Off-request delivery worker: dispatch() queues this single event so the
		// originating request never blocks on outbound HTTP.
		add_action( self::DELIVER_HOOK, array( $this, 'run_delivery' ), 10, 2 );

		// Single-event retry worker: fired with backoff after each failed delivery.
		add_action( self::RETRY_HOOK, array( $this, 'run_retry' ), 10, 4 );
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

		$this->flush_active_cache();

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

		// Never expose the per-endpoint HMAC secret in a list response — it would
		// let an admin-scoped reader forge signed deliveries. Return a boolean
		// has_secret flag instead of the raw value (the secret is write-only).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, label, url, ( secret <> '' ) AS has_secret, events, is_active, created_at, updated_at
			   FROM {$wpdb->prefix}bn_outbound_webhooks
			  ORDER BY id ASC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as &$row ) {
			$decoded           = json_decode( (string) ( $row['events'] ?? 'null' ), true );
			$row['events']     = is_array( $decoded ) ? $decoded : array();
			$row['has_secret'] = ! empty( $row['has_secret'] );
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

		$this->flush_active_cache();

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
	 * Queue an event for outbound delivery.
	 *
	 * Runs inside core lifecycle hooks (post created, follow, …), so it must be
	 * cheap and non-blocking: it does no per-webhook query and never calls out
	 * over HTTP. When at least one endpoint is active (cached count) it schedules
	 * a single-run cron job that performs the event-filtered query and the
	 * blocking sends off the request. Sites with no webhooks pay nothing.
	 *
	 * @param string              $event_slug Event slug, e.g. 'member.registered'.
	 * @param array<string,mixed> $payload    Event-specific data.
	 */
	public function dispatch( string $event_slug, array $payload ): void {
		if ( $this->active_webhook_count() < 1 ) {
			return;
		}

		$args = array( $event_slug, $payload );

		// Guard against a double-schedule when two requests fire the SAME event +
		// payload concurrently: wp_next_scheduled() + wp_schedule_single_event() is
		// not atomic, so both could see "not scheduled" and both schedule, the cron
		// runs twice, and the endpoint gets duplicate POSTs. The per-delivery
		// dedup header does NOT reliably catch this (its hash includes a per-send
		// timestamp, so duplicates firing in different seconds look distinct).
		// Under a persistent object cache, wp_cache_add() is atomic and returns
		// false when the key already exists, so only the first request schedules.
		$lock_key = 'bn_owh_' . md5( $event_slug . '|' . (string) wp_json_encode( $payload ) );
		if ( wp_using_ext_object_cache() ) {
			if ( ! wp_cache_add( $lock_key, 1, self::DISPATCH_LOCK_GROUP, MINUTE_IN_SECONDS ) ) {
				return;
			}
			wp_schedule_single_event( time(), self::DELIVER_HOOK, $args );
			return;
		}

		// No persistent object cache — fall back to the best-effort scheduled check.
		if ( ! wp_next_scheduled( self::DELIVER_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::DELIVER_HOOK, $args );
		}
	}

	/**
	 * Cron worker: deliver one queued event to every subscribed active endpoint.
	 *
	 * The subscription match is pushed into SQL (events = all, or JSON_CONTAINS
	 * the slug) so only relevant endpoints are loaded — not every active row —
	 * and the blocking HTTP sends happen here, off the originating request.
	 *
	 * @param string              $event_slug Event slug.
	 * @param array<string,mixed> $payload    Event-specific data.
	 */
	public function run_delivery( string $event_slug, array $payload ): void {
		global $wpdb;

		$event_json = (string) wp_json_encode( $event_slug );

		// COALESCE/NULLIF normalises legacy NULL/'' event columns to '[]' so
		// JSON_CONTAINS always receives valid JSON; '[]' means "all events".
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$webhooks = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, url, secret, events
				   FROM {$wpdb->prefix}bn_outbound_webhooks
				  WHERE is_active = 1
				    AND ( COALESCE( NULLIF( events, '' ), '[]' ) = '[]'
				          OR JSON_CONTAINS( COALESCE( NULLIF( events, '' ), '[]' ), %s ) )",
				$event_json
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $webhooks ) ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			// Re-affirm the match in PHP — authoritative, and defensive against
			// any JSON quoting edge case the SQL filter might admit.
			$subscribed    = json_decode( (string) ( $webhook['events'] ?? 'null' ), true );
			$matches_all   = ! is_array( $subscribed ) || count( $subscribed ) === 0;
			$matches_event = is_array( $subscribed ) && in_array( $event_slug, $subscribed, true );

			if ( ! $matches_all && ! $matches_event ) {
				continue;
			}

			$webhook_id = (int) $webhook['id'];
			$code       = $this->deliver(
				$webhook_id,
				(string) $webhook['url'],
				(string) $webhook['secret'],
				$event_slug,
				$payload
			);

			// On failure, schedule the first single-event retry (attempt 1).
			// Subsequent retries are chained inside run_retry().
			if ( ! ( $code >= 200 && $code < 300 ) ) {
				$this->schedule_single_retry( $webhook_id, $event_slug, $payload, 1 );
			}
		}
	}

	/**
	 * Active-endpoint count, cached so dispatch() costs no query on the common
	 * (no-webhooks) path. Self-heals within the TTL; flushed on register/delete/
	 * auto-deactivate via flush_active_cache().
	 *
	 * @return int
	 */
	private function active_webhook_count(): int {
		$cached = get_transient( self::ACTIVE_COUNT_CACHE_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}bn_outbound_webhooks WHERE is_active = 1" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		set_transient( self::ACTIVE_COUNT_CACHE_KEY, $count, HOUR_IN_SECONDS );

		return $count;
	}

	/**
	 * Invalidate the cached active-endpoint count. Public so a Pro toggle path
	 * that flips is_active outside this service can keep the cache honest.
	 *
	 * @return void
	 */
	public function flush_active_cache(): void {
		delete_transient( self::ACTIVE_COUNT_CACHE_KEY );
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
	 * Single-event retry worker for one failed delivery.
	 *
	 * Re-attempts delivery for a specific webhook + event combination. On success
	 * or on permanent failure (endpoint deactivated), the sequence ends. On failure
	 * with remaining attempts, a new single-event is scheduled with doubled backoff.
	 *
	 * @param int                 $webhook_id Webhook row ID.
	 * @param string              $event_slug Event type slug.
	 * @param array<string,mixed> $payload    Original event payload data.
	 * @param int                 $attempt    1-based attempt number (1 = first retry).
	 * @return void
	 */
	public function run_retry( int $webhook_id, string $event_slug, array $payload, int $attempt ): void {
		global $wpdb;

		// Bail if the endpoint was deactivated between scheduling and now.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$webhook = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, url, secret FROM {$wpdb->prefix}bn_outbound_webhooks
				 WHERE id = %d AND is_active = 1 LIMIT 1",
				$webhook_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $webhook ) ) {
			return;
		}

		$code    = $this->deliver(
			(int) $webhook['id'],
			(string) $webhook['url'],
			(string) $webhook['secret'],
			$event_slug,
			$payload
		);
		$success = $code >= 200 && $code < 300;

		// On success or once we have exhausted retry attempts, stop.
		if ( $success || $attempt >= self::MAX_RETRY_ATTEMPTS ) {
			return;
		}

		// Schedule next retry with exponential backoff.
		$this->schedule_single_retry( $webhook_id, $event_slug, $payload, $attempt + 1 );
	}

	/**
	 * Schedule a single-event retry for a failed delivery.
	 *
	 * Delay follows exponential backoff: attempt 1 = RETRY_BASE_DELAY,
	 * attempt 2 = 2× base, attempt 3 = 4× base, etc.
	 *
	 * @param int                 $webhook_id Webhook row ID.
	 * @param string              $event_slug Event slug.
	 * @param array<string,mixed> $payload    Original event payload data.
	 * @param int                 $attempt    1-based attempt number for this retry.
	 * @return void
	 */
	private function schedule_single_retry( int $webhook_id, string $event_slug, array $payload, int $attempt ): void {
		$delay = self::RETRY_BASE_DELAY * ( 2 ** ( $attempt - 1 ) );
		wp_schedule_single_event(
			time() + $delay,
			self::RETRY_HOOK,
			array( $webhook_id, $event_slug, $payload, $attempt )
		);
	}

	// ── Internal ──────────────────────────────────────────────────────────────

	/**
	 * Build, sign, POST and log a single webhook delivery.
	 *
	 * The request body is a JSON envelope: { event, timestamp, data }. An
	 * HMAC-SHA256 signature over the raw body string is sent via the
	 * X-BuddyNext-Signature header. On failed delivery the consecutive failure
	 * count is checked; three failures trigger automatic deactivation. The caller
	 * (dispatch path via run_delivery) also schedules a single-event retry via
	 * maybe_schedule_retry() when the initial attempt fails.
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

		// Stable delivery id so consumers can dedup retries: a cron retry re-sends
		// the identical stored envelope, so hashing (webhook id + body) yields the
		// same X-BuddyNext-Delivery on every attempt of the same logical delivery,
		// while distinct events (different event/timestamp in the body) differ.
		$delivery_id = hash( 'sha256', $webhook_id . '|' . $body );

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => self::HTTP_TIMEOUT,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'          => 'application/json',
					'X-BuddyNext-Signature' => $signature,
					'X-BuddyNext-Event'     => $event_slug,
					'X-BuddyNext-Delivery'  => $delivery_id,
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
			$this->flush_active_cache();
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
