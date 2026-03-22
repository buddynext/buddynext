<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName, WordPress.Files.FileName.NotHyphenatedLowercase -- PSR-4 PascalCase naming is the project standard.
/**
 * Outbound webhook dispatch service.
 *
 * Reads active webhook configurations from bn_outbound_webhooks and dispatches
 * signed HTTP POST payloads whenever a subscribed BuddyNext event fires. Each
 * delivery attempt is logged in bn_outbound_webhook_log regardless of outcome.
 *
 * Webhook payloads are signed with an HMAC-SHA256 signature delivered via the
 * X-BuddyNext-Signature header so consumers can verify authenticity.
 *
 * Bind as 'outbound_webhooks' in Plugin.php and call ->init()
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Dispatches signed HTTP webhook payloads for configurable BuddyNext events.
 */
class OutboundWebhookService {

	/**
	 * Maximum length stored for response bodies in the log table.
	 */
	private const RESPONSE_BODY_MAX_LENGTH = 2000;

	/**
	 * HTTP timeout in seconds for outbound webhook requests.
	 */
	private const HTTP_TIMEOUT = 10;

	/**
	 * Initialise the service.
	 */
	public function __construct() {}

	// ── Registration ──────────────────────────────────────────────────────

	/**
	 * Register hooks so configured webhooks fire on BuddyNext events.
	 *
	 * Each BuddyNext action maps to an event-type string that webhook
	 * subscribers list in their `events` JSON column. When the action fires,
	 * dispatch() fans out to every matching active webhook.
	 */
	public function init(): void {
		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );
		add_action( 'buddynext_space_created', array( $this, 'on_space_created' ), 10, 2 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_space_member_joined' ), 10, 3 );
		add_action( 'buddynext_member_suspended', array( $this, 'on_member_suspended' ), 10, 2 );
		add_action( 'buddynext_appeal_resolved', array( $this, 'on_appeal_resolved' ), 10, 3 );
	}

	// ── Hook handlers ─────────────────────────────────────────────────────

	/**
	 * Dispatch webhook payload when a user follows another user.
	 *
	 * @param int $follower_id  User who initiated the follow.
	 * @param int $following_id User who was followed.
	 */
	public function on_user_followed( int $follower_id, int $following_id ): void {
		$this->dispatch(
			'user.followed',
			array(
				'follower_id'  => $follower_id,
				'following_id' => $following_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a new post is created.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Author ID.
	 * @param string $type    Post type slug.
	 */
	public function on_post_created( int $post_id, int $user_id, string $type ): void {
		$this->dispatch(
			'post.created',
			array(
				'post_id' => $post_id,
				'user_id' => $user_id,
				'type'    => $type,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a new space is created.
	 *
	 * @param int $space_id Space ID.
	 * @param int $user_id  Owner ID.
	 */
	public function on_space_created( int $space_id, int $user_id ): void {
		$this->dispatch(
			'space.created',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a member joins a space.
	 *
	 * The $_role parameter is received from the hook but intentionally excluded
	 * from the payload — its only purpose is to satisfy the hook's arity.
	 *
	 * @param int    $space_id Space that was joined.
	 * @param int    $user_id  User who joined.
	 * @param string $_role    Role assigned (unused — required by hook contract).
	 */
	public function on_space_member_joined( int $space_id, int $user_id, string $_role ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_role required by hook contract.
		$this->dispatch(
			'space.member_joined',
			array(
				'user_id'  => $user_id,
				'space_id' => $space_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a member is suspended.
	 *
	 * @param int $user_id  Suspended user ID.
	 * @param int $mod_id   Moderator who issued the suspension.
	 */
	public function on_member_suspended( int $user_id, int $mod_id ): void {
		$this->dispatch(
			'user.suspended',
			array(
				'user_id' => $user_id,
				'by'      => $mod_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a moderation appeal is resolved.
	 *
	 * @param int    $appeal_id Appeal row ID.
	 * @param int    $user_id   Appellant user ID.
	 * @param string $status    Resolution status: 'approved' or 'denied'.
	 */
	public function on_appeal_resolved( int $appeal_id, int $user_id, string $status ): void {
		$this->dispatch(
			'appeal.resolved',
			array(
				'appeal_id' => $appeal_id,
				'status'    => $status,
			)
		);
	}

	// ── Core dispatch ─────────────────────────────────────────────────────

	/**
	 * Dispatch an event payload to all active webhooks subscribed to that event.
	 *
	 * Loads every active webhook row and, for each row whose `events` JSON array
	 * includes $event_type, calls send() to deliver the payload.
	 *
	 * @param string $event_type Dot-notation event name (e.g. 'post.created').
	 * @param array  $payload    Arbitrary data to include in the delivery body.
	 */
	public function dispatch( string $event_type, array $payload ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$webhooks = $wpdb->get_results(
			"SELECT id, label, url, secret, events, is_active FROM {$wpdb->prefix}bn_outbound_webhooks WHERE is_active = 1"
		);

		if ( empty( $webhooks ) ) {
			return;
		}

		foreach ( $webhooks as $webhook ) {
			$subscribed = json_decode( (string) ( $webhook->events ?? 'null' ), true );

			if ( ! is_array( $subscribed ) || ! in_array( $event_type, $subscribed, true ) ) {
				continue;
			}

			$this->send( $webhook, $event_type, $payload );
		}
	}

	/**
	 * Build, sign, send and log a single webhook delivery.
	 *
	 * The request body is a JSON object containing `event`, `timestamp`, and
	 * `payload`. An HMAC-SHA256 signature over the raw body string is attached
	 * via the X-BuddyNext-Signature header when the webhook has a secret.
	 *
	 * @param object $webhook    Webhook row from bn_outbound_webhooks.
	 * @param string $event_type Dot-notation event name.
	 * @param array  $payload    Event-specific data.
	 */
	public function send( object $webhook, string $event_type, array $payload ): void {
		global $wpdb;

		$body = wp_json_encode(
			array(
				'event'     => $event_type,
				'timestamp' => gmdate( 'c' ),
				'payload'   => $payload,
			)
		);

		if ( false === $body ) {
			return;
		}

		$secret    = (string) ( $webhook->secret ?? '' );
		$signature = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		$response = wp_remote_post(
			(string) $webhook->url,
			array(
				'timeout'  => self::HTTP_TIMEOUT,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'          => 'application/json',
					'X-BuddyNext-Signature' => $signature,
					'X-BuddyNext-Event'     => $event_type,
				),
				'body'     => $body,
			)
		);

		$is_error      = is_wp_error( $response );
		$response_code = $is_error ? null : (int) wp_remote_retrieve_response_code( $response );
		$response_body = $is_error
			? $response->get_error_message()
			: wp_remote_retrieve_body( $response );

		if ( strlen( $response_body ) > self::RESPONSE_BODY_MAX_LENGTH ) {
			$response_body = substr( $response_body, 0, self::RESPONSE_BODY_MAX_LENGTH );
		}

		$delivered_at = ( ! $is_error && null !== $response_code && $response_code < 400 )
			? gmdate( 'Y-m-d H:i:s' )
			: null;

		$status = ( null !== $delivered_at ) ? 'success' : 'error';

		$data    = array(
			'webhook_id'    => (int) $webhook->id,
			'event'         => $event_type,
			'payload'       => $body,
			'response_code' => $response_code,
			'response_body' => $response_body,
			'status'        => $status,
		);
		$formats = array( '%d', '%s', '%s', null !== $response_code ? '%d' : 'NULL', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_outbound_webhook_log',
			$data,
			$formats
		);
	}

	// ── CRUD ──────────────────────────────────────────────────────────────

	/**
	 * Return all webhook rows for the admin UI.
	 *
	 * @return array<int, object> All rows from bn_outbound_webhooks.
	 */
	public function get_webhooks(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, label, url, secret, events, is_active, created_at, updated_at FROM {$wpdb->prefix}bn_outbound_webhooks ORDER BY id ASC"
		);

		return $rows ? $rows : array();
	}

	/**
	 * Insert a new webhook row.
	 *
	 * A random 40-character alphanumeric secret is generated automatically
	 * when the caller passes an empty string.
	 *
	 * @param string   $label        Human-readable label.
	 * @param string   $endpoint_url Destination URL.
	 * @param string[] $events       Array of dot-notation event names to subscribe to.
	 * @param string   $secret       Signing secret. Auto-generated when empty.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public function create_webhook( string $label, string $endpoint_url, array $events, string $secret = '' ): int|false {
		global $wpdb;

		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false );
		}

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prefix . 'bn_outbound_webhooks',
			array(
				'label'     => $label,
				'url'       => $endpoint_url,
				'secret'    => $secret,
				'events'    => wp_json_encode( array_values( $events ) ),
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a webhook row and its associated log entries.
	 *
	 * @param int $id Webhook row ID.
	 * @return bool True when a row was deleted, false otherwise.
	 */
	public function delete_webhook( int $id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write operation; no read-cache to invalidate.
			$wpdb->prefix . 'bn_outbound_webhooks',
			array( 'id' => $id ),
			array( '%d' )
		);

		return false !== $deleted && $deleted > 0;
	}

	/**
	 * Enable or disable a webhook.
	 *
	 * @param int  $id     Webhook row ID.
	 * @param bool $active True to activate, false to deactivate.
	 * @return bool True when the row was updated, false otherwise.
	 */
	public function toggle_webhook( int $id, bool $active ): bool {
		global $wpdb;

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- write operation; no read-cache to invalidate.
			$wpdb->prefix . 'bn_outbound_webhooks',
			array( 'is_active' => (int) $active ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $updated && $updated > 0;
	}
}
