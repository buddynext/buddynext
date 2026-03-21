<?php
/**
 * Outbound webhook dispatch service.
 *
 * Reads active webhook configurations from bn_outbound_webhooks and dispatches
 * HTTP POST payloads whenever a subscribed BuddyNext event fires. Each delivery
 * attempt is logged in bn_outbound_webhook_log regardless of outcome.
 *
 * Webhook payloads are signed with an HMAC-SHA256 signature in the
 * X-BuddyNext-Signature header so consumers can verify authenticity.
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
	 * Signature header sent with every delivery.
	 */
	private const SIG_HEADER = 'X-BuddyNext-Signature';

	/**
	 * Default HTTP timeout in seconds for outbound webhook requests.
	 */
	private const HTTP_TIMEOUT = 10;

	/**
	 * Register hooks so configured webhooks fire on BuddyNext events.
	 *
	 * Each BuddyNext action maps to an event name that webhook subscribers
	 * specify in their `events` JSON column. When the action fires, dispatch()
	 * is called with that event name and the relevant payload.
	 */
	public function init(): void {
		add_action( 'buddynext_user_followed', array( $this, 'on_user_followed' ), 10, 2 );
		add_action( 'buddynext_post_created', array( $this, 'on_post_created' ), 10, 3 );
		add_action( 'buddynext_comment_created', array( $this, 'on_comment_created' ), 10, 4 );
		add_action( 'buddynext_reaction_added', array( $this, 'on_reaction_added' ), 10, 4 );
		add_action( 'buddynext_connection_accepted', array( $this, 'on_connection_accepted' ), 10, 3 );
		add_action( 'buddynext_space_member_joined', array( $this, 'on_space_member_joined' ), 10, 3 );
		add_action( 'buddynext_strike_issued', array( $this, 'on_strike_issued' ), 10, 3 );
		add_action( 'buddynext_member_suspended', array( $this, 'on_member_suspended' ), 10, 2 );
		add_action( 'buddynext_appeal_resolved', array( $this, 'on_appeal_resolved' ), 10, 3 );
	}

	// ── Hook handlers ─────────────────────────────────────────────────────

	/**
	 * Dispatch webhook payload when a user follows another user.
	 *
	 * @param int $follower_id  User who followed.
	 * @param int $following_id User being followed.
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
	 * @param string $type    Post type.
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
	 * Dispatch webhook payload when a comment is created.
	 *
	 * @param int    $comment_id  Comment ID.
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     Author ID.
	 */
	public function on_comment_created( int $comment_id, string $object_type, int $object_id, int $user_id ): void {
		$this->dispatch(
			'comment.created',
			array(
				'comment_id'  => $comment_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $user_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a reaction is added to a post or comment.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @param int    $user_id     Reactor ID.
	 * @param string $emoji       Emoji used.
	 */
	public function on_reaction_added( string $object_type, int $object_id, int $user_id, string $emoji ): void {
		$this->dispatch(
			'reaction.added',
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'user_id'     => $user_id,
				'emoji'       => $emoji,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a connection request is accepted.
	 *
	 * @param int $connection_id Connection ID.
	 * @param int $user_a        First user.
	 * @param int $user_b        Second user.
	 */
	public function on_connection_accepted( int $connection_id, int $user_a, int $user_b ): void {
		$this->dispatch(
			'connection.accepted',
			array(
				'connection_id' => $connection_id,
				'user_a'        => $user_a,
				'user_b'        => $user_b,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a member joins a space.
	 *
	 * @param int    $space_id Space ID.
	 * @param int    $user_id  Joined user.
	 * @param string $_role    Role assigned (not included in payload — preserved for hook arity).
	 */
	public function on_space_member_joined( int $space_id, int $user_id, string $_role ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $_role required by hook contract.
		$this->dispatch(
			'space.member_joined',
			array(
				'space_id' => $space_id,
				'user_id'  => $user_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a moderation strike is issued.
	 *
	 * @param int $strike_id Strike ID.
	 * @param int $user_id   Struck user.
	 * @param int $actor_id  Issuing moderator.
	 */
	public function on_strike_issued( int $strike_id, int $user_id, int $actor_id ): void {
		$this->dispatch(
			'moderation.strike_issued',
			array(
				'strike_id' => $strike_id,
				'user_id'   => $user_id,
				'actor_id'  => $actor_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a member is suspended.
	 *
	 * @param int $user_id  Suspended user.
	 * @param int $actor_id Admin performing the suspension.
	 */
	public function on_member_suspended( int $user_id, int $actor_id ): void {
		$this->dispatch(
			'moderation.member_suspended',
			array(
				'user_id'  => $user_id,
				'actor_id' => $actor_id,
			)
		);
	}

	/**
	 * Dispatch webhook payload when a moderation appeal is resolved.
	 *
	 * @param int    $appeal_id Appeal ID.
	 * @param int    $user_id   Appellant.
	 * @param string $decision  'approved' or 'denied'.
	 */
	public function on_appeal_resolved( int $appeal_id, int $user_id, string $decision ): void {
		$this->dispatch(
			'moderation.appeal_resolved',
			array(
				'appeal_id' => $appeal_id,
				'user_id'   => $user_id,
				'decision'  => $decision,
			)
		);
	}

	// ── Core dispatch ─────────────────────────────────────────────────────

	/**
	 * Dispatch an event payload to all active webhooks subscribed to that event.
	 *
	 * Webhooks with an empty events JSON array (or null) receive every event.
	 * Otherwise, only webhooks whose events array includes $event_name fire.
	 *
	 * @param string $event_name Dot-notation event name (e.g. 'post.created').
	 * @param array  $payload    Arbitrary data to serialize and send.
	 */
	public function dispatch( string $event_name, array $payload ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$hooks = $wpdb->get_results(
			"SELECT id, url, secret, events FROM {$wpdb->prefix}bn_outbound_webhooks WHERE is_active = 1",
			ARRAY_A
		);

		if ( empty( $hooks ) ) {
			return;
		}

		$body = wp_json_encode(
			array(
				'event'    => $event_name,
				'payload'  => $payload,
				'fired_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'site_url' => get_site_url(),
			)
		);

		if ( false === $body ) {
			return;
		}

		foreach ( $hooks as $hook ) {
			$subscribed = json_decode( (string) ( $hook['events'] ?? 'null' ), true );

			// Empty or null events list means subscribe to everything.
			if ( ! empty( $subscribed ) && ! in_array( $event_name, (array) $subscribed, true ) ) {
				continue;
			}

			$this->send( (int) $hook['id'], (string) $hook['url'], (string) ( $hook['secret'] ?? '' ), $event_name, $body );
		}
	}

	/**
	 * Send a single HTTP POST and log the outcome.
	 *
	 * @param int    $webhook_id Webhook row ID.
	 * @param string $url        Destination URL.
	 * @param string $secret     Webhook signing secret (may be empty).
	 * @param string $event_name Event name for the log row.
	 * @param string $body       JSON-encoded request body.
	 */
	private function send( int $webhook_id, string $url, string $secret, string $event_name, string $body ): void {
		global $wpdb;

		$headers = array(
			'Content-Type' => 'application/json',
		);

		if ( '' !== $secret ) {
			$headers[ self::SIG_HEADER ] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		$response = wp_remote_post(
			$url,
			array(
				'headers' => $headers,
				'body'    => $body,
				'timeout' => self::HTTP_TIMEOUT,
			)
		);

		$is_error      = is_wp_error( $response );
		$response_code = $is_error ? null : wp_remote_retrieve_response_code( $response );
		$response_body = $is_error
			? $response->get_error_message()
			: wp_remote_retrieve_body( $response );

		$status = ( ! $is_error && $response_code >= 200 && $response_code < 300 ) ? 'success' : 'error';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->prefix . 'bn_outbound_webhook_log',
			array(
				'webhook_id'    => $webhook_id,
				'event'         => $event_name,
				'payload'       => $body,
				'response_code' => $response_code,
				'response_body' => $response_body,
				'status'        => $status,
			),
			array( '%d', '%s', '%s', $response_code ? '%d' : 'NULL', '%s', '%s' )
		);
	}
}
