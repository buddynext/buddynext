<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Webhook endpoint for managing user access, roles, abilities, and credits.
 *
 * POST buddynext/v1/webhook/access
 *
 * All requests must carry a valid HMAC-SHA256 signature in the
 * X-BuddyNext-Signature header. The shared secret is stored in the
 * buddynext_webhook_secret option and set via the admin settings page.
 *
 * Supported actions:
 *   set_role       — Set the user's community role (admin/moderator/member).
 *   grant_ability  — Grant a BuddyNext ability with optional expiry.
 *   revoke_ability — Remove a granted ability.
 *   add_credits    — Add to the user's credit balance.
 *   set_credits    — Replace the user's credit balance.
 *   deduct_credits — Subtract from the balance (floors at 0).
 *
 * Every call is written to bn_webhook_log for auditing.
 *
 * @package BuddyNext\Outbound
 */

declare( strict_types=1 );

namespace BuddyNext\Outbound;

use BuddyNext\Core\PermissionService;
use BuddyNext\Core\RoleService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_User;

/**
 * Handles incoming access-management webhooks.
 */
class AccessWebhookController {

	/**
	 * Register the route with the WordPress REST API.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/webhook/access',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming webhook request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$signature_check = $this->verify_signature( $request );

		if ( $signature_check instanceof WP_Error ) {
			return $signature_check;
		}

		if ( ! $signature_check ) {
			return new WP_Error(
				'invalid_signature',
				__( 'Invalid webhook signature.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$body = json_decode( $request->get_body(), true );

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'invalid_payload',
				__( 'Payload must be valid JSON.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		$user = $this->resolve_user( $body );

		if ( ! $user ) {
			return new WP_Error(
				'user_not_found',
				__( 'User not found.', 'buddynext' ),
				array( 'status' => 404 )
			);
		}

		$action = sanitize_key( $body['action'] ?? '' );

		// This request logs itself, below, with the whole signed body — richer than
		// anything the action can carry. WebhookLogListener watches the same grant
		// action for callers that have no request to log from, so it is told to
		// stand down for the duration of this one. Without that, every HTTP grant
		// would be recorded twice: once here and once by the listener.
		WebhookLog::begin_request_scope();
		$result = $this->dispatch( $action, $user->ID, $body );
		WebhookLog::end_request_scope();

		$status = ( $result instanceof WP_Error ) ? 'error' : 'success';

		$this->log( $action, $user->ID, $body, $status );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * Route the action to its handler.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id Resolved user ID.
	 * @param array  $body    Decoded request body.
	 * @return true|WP_Error
	 */
	private function dispatch( string $action, int $user_id, array $body ): bool|WP_Error {
		return match ( $action ) {
			'set_role'       => $this->action_set_role( $user_id, $body ),
			'grant_ability'  => $this->action_grant_ability( $user_id, $body ),
			'revoke_ability' => $this->action_revoke_ability( $user_id, $body ),
			'add_credits'    => $this->action_add_credits( $user_id, $body ),
			'set_credits'    => $this->action_set_credits( $user_id, $body ),
			'deduct_credits' => $this->action_deduct_credits( $user_id, $body ),
			default          => new WP_Error(
				'unknown_action',
				/* translators: %s: action name */
				sprintf( __( 'Unknown action: "%s".', 'buddynext' ), $action ),
				array( 'status' => 400 )
			),
		};
	}

	/**
	 * Set a user's community role.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true|WP_Error
	 */
	private function action_set_role( int $user_id, array $body ): bool|WP_Error {
		$role = sanitize_key( $body['role'] ?? '' );

		if ( ! in_array( $role, array( 'admin', 'moderator', 'member' ), true ) ) {
			return new WP_Error(
				'invalid_role',
				__( 'Role must be admin, moderator, or member.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		update_user_meta( $user_id, 'bn_community_role', $role );

		/**
		 * Fires when a user's community role changes via webhook.
		 *
		 * @param int    $user_id User ID.
		 * @param string $role    New role slug.
		 */
		do_action( 'buddynext_role_changed', $user_id, $role );

		return true;
	}

	/**
	 * Grant an ability to a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true|WP_Error
	 */
	private function action_grant_ability( int $user_id, array $body ): bool|WP_Error {
		$ability    = sanitize_text_field( $body['ability'] ?? '' );
		$expires_at = ! empty( $body['expires_at'] ) ? sanitize_text_field( $body['expires_at'] ) : null;
		$source     = sanitize_key( $body['source'] ?? '' );

		if ( ! $ability ) {
			return new WP_Error(
				'missing_ability',
				__( '"ability" is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		// 0 = no expiry; otherwise unix timestamp.
		$expires_ts = ( null === $expires_at || '' === $expires_at ) ? 0 : (int) strtotime( $expires_at );

		update_user_meta( $user_id, PermissionService::ability_meta_key( $ability ), $expires_ts );

		/**
		 * Fires when an ability is granted to a user.
		 *
		 * @param int    $user_id User ID.
		 * @param string $ability Ability slug.
		 * @param string $source  Source tag (e.g. 'stripe', 'manual'); empty when not supplied.
		 */
		do_action( 'buddynext_ability_granted', $user_id, $ability, $source );

		return true;
	}

	/**
	 * Revoke an ability from a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true|WP_Error
	 */
	private function action_revoke_ability( int $user_id, array $body ): bool|WP_Error {
		$ability = sanitize_text_field( $body['ability'] ?? '' );

		if ( ! $ability ) {
			return new WP_Error(
				'missing_ability',
				__( '"ability" is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		delete_user_meta( $user_id, PermissionService::ability_meta_key( $ability ) );

		/**
		 * Fires when an ability is revoked from a user.
		 *
		 * @param int    $user_id User ID.
		 * @param string $ability Ability slug.
		 */
		do_action( 'buddynext_ability_revoked', $user_id, $ability );

		return true;
	}

	/**
	 * Add to a user's credit balance.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true
	 */
	private function action_add_credits( int $user_id, array $body ): bool {
		( new RoleService() )->add_credits( $user_id, abs( (int) ( $body['amount'] ?? 0 ) ) );
		return true;
	}

	/**
	 * Replace a user's credit balance.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true
	 */
	private function action_set_credits( int $user_id, array $body ): bool {
		( new RoleService() )->set_credits( $user_id, max( 0, (int) ( $body['amount'] ?? 0 ) ) );
		return true;
	}

	/**
	 * Deduct from a user's credit balance (floors at 0).
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true
	 */
	private function action_deduct_credits( int $user_id, array $body ): bool {
		( new RoleService() )->deduct_credits( $user_id, abs( (int) ( $body['amount'] ?? 0 ) ) );
		return true;
	}

	/**
	 * Resolve a user from user_id or user_email in the request body.
	 *
	 * @param array $body Request body.
	 * @return WP_User|false
	 */
	private function resolve_user( array $body ): WP_User|false {
		if ( ! empty( $body['user_id'] ) ) {
			return get_userdata( (int) $body['user_id'] );
		}

		if ( ! empty( $body['user_email'] ) ) {
			return get_user_by( 'email', sanitize_email( $body['user_email'] ) );
		}

		return false;
	}

	/**
	 * Signature this request was accepted on, recorded so a replay is detectable.
	 *
	 * Empty for a legacy body-only request: there is nothing time-bounded to
	 * compare a later copy against, which is the reason that scheme is going.
	 *
	 * @var string
	 */
	private string $accepted_signature = '';

	/**
	 * Tolerance, in seconds, between a request's timestamp and now.
	 *
	 * Wide enough for ordinary clock drift between two servers, narrow enough
	 * that a captured request is worthless within minutes.
	 */
	private const TIMESTAMP_TOLERANCE = 300;

	/**
	 * Option that turns off acceptance of the pre-1.1.6 signature scheme.
	 */
	public const OPT_STRICT_SIGNATURES = 'buddynext_webhook_strict_signatures';

	/**
	 * Verify the HMAC-SHA256 signature from the X-BuddyNext-Signature header.
	 *
	 * Two schemes are accepted during the migration window.
	 *
	 * The current one signs `"{timestamp}.{body}"` and sends the timestamp in
	 * `X-BuddyNext-Timestamp`. The old one signed the body alone, which made a
	 * captured request valid forever: `grant_ability` replayed on a timer renews
	 * a membership indefinitely, and nothing about the request ever goes stale.
	 * Signing the timestamp is what puts an expiry on a captured call, and
	 * recording the signature (see `replay_seen()`) is what stops it being used
	 * twice inside that window.
	 *
	 * Timestamped signatures are now REQUIRED by default (1.1.6): the body-only
	 * fallback is refused unless a site explicitly re-enables it. A site still
	 * mid-migration can keep accepting the legacy scheme by setting
	 * `buddynext_webhook_strict_signatures` to '0', or per-request via the
	 * `buddynext_require_signed_timestamp` filter — the opt-out for a straggler
	 * finishing a move. Each legacy call that IS accepted is still logged as
	 * deprecated so an owner can see who to move.
	 *
	 * This flip closes the replay window on upgraded sites too (fresh installs
	 * were already strict via Installer::run()). It is a breaking change for a
	 * sender still signing the body alone — that is the deliberate, owner-chosen
	 * trade recorded on Basecamp 10227863022; note it in the release changelog.
	 *
	 * Returns a WP_Error when the secret is not configured or the request is
	 * stale/replayed, false when no scheme matches, true when one does.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|false|WP_Error
	 */
	private function verify_signature( WP_REST_Request $request ): bool|WP_Error {
		$secret = (string) get_option( 'buddynext_webhook_secret', '' );

		if ( '' === $secret ) {
			return new WP_Error(
				'webhook_not_configured',
				__( 'Webhook secret is not configured.', 'buddynext' ),
				array( 'status' => 503 )
			);
		}

		$header    = (string) ( $request->get_header( 'X-BuddyNext-Signature' ) ?? '' );
		$timestamp = (string) ( $request->get_header( 'X-BuddyNext-Timestamp' ) ?? '' );
		$body      = (string) $request->get_body();

		if ( '' !== $timestamp ) {
			$expected = 'sha256=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );

			if ( ! hash_equals( $expected, $header ) ) {
				return false;
			}

			// Signature checked first, deliberately: a stale-timestamp message for
			// an unsigned request would tell an attacker their guess was otherwise
			// well-formed.
			if ( abs( time() - (int) $timestamp ) > self::TIMESTAMP_TOLERANCE ) {
				return new WP_Error(
					'stale_request',
					__( 'Request timestamp is outside the accepted window.', 'buddynext' ),
					array( 'status' => 401 )
				);
			}

			if ( $this->replay_seen( self::signature_digest( $header ) ) ) {
				return new WP_Error(
					'replayed_request',
					__( 'This request has already been processed.', 'buddynext' ),
					array( 'status' => 409 )
				);
			}

			$this->accepted_signature = self::signature_digest( $header );

			return true;
		}

		// ── Legacy: body-only signature, no timestamp ──────────────────────────
		// Strict by default now (1.1.6). An upgraded site with legacy senders still
		// mid-migration opts out by setting the option to '0', or per request via
		// the filter. The option default is `true` so a site that never had the
		// row (upgraded from before it existed) is strict, matching a fresh install.
		$strict = (bool) apply_filters(
			'buddynext_require_signed_timestamp',
			(bool) get_option( self::OPT_STRICT_SIGNATURES, true ),
			$request
		);
		if ( $strict ) {
			return new WP_Error(
				'timestamp_required',
				__( 'This site requires a signed request timestamp.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		$legacy = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		if ( ! hash_equals( $legacy, $header ) ) {
			return false;
		}

		$this->accepted_signature = '';

		WebhookLog::write(
			'signature_scheme_deprecated',
			0,
			array(
				'source' => sanitize_key( (string) ( $request->get_param( 'source' ) ?? '' ) ),
				'reason' => 'request signed without X-BuddyNext-Timestamp; this scheme cannot be replay-checked and will stop being accepted',
			),
			WebhookLog::STATUS_ERROR
		);

		return true;
	}

	/**
	 * The hex digest out of a signature header.
	 *
	 * The header is `sha256=<64 hex>` — 71 characters, which does not fit the
	 * CHAR(64) column and made MySQL reject the row outright, so nothing was
	 * recorded and every replay sailed through. The prefix is a constant and
	 * carries no information, so only the digest is kept.
	 *
	 * @param string $header Signature header value.
	 * @return string 64-char digest, or '' when the header is not in that shape.
	 */
	private static function signature_digest( string $header ): string {
		$digest = str_starts_with( $header, 'sha256=' ) ? substr( $header, 7 ) : $header;

		return 1 === preg_match( '/^[a-f0-9]{64}$/', $digest ) ? $digest : '';
	}

	/**
	 * Whether this exact signature has already been accepted recently.
	 *
	 * Only inside the tolerance window: outside it the timestamp check has
	 * already refused the request, so the log does not need to be kept forever
	 * to make this work.
	 *
	 * @param string $signature Signature header value.
	 * @return bool
	 */
	private function replay_seen( string $signature ): bool {
		global $wpdb;

		if ( '' === $signature ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$wpdb->prefix}bn_webhook_log
				 WHERE signature = %s AND created_at > %s
				 LIMIT 1",
				$signature,
				gmdate( 'Y-m-d H:i:s', time() - self::TIMESTAMP_TOLERANCE )
			)
		);

		return null !== $found;
	}

	/**
	 * Write an audit row to bn_webhook_log.
	 *
	 * Delegates to WebhookLog, which is the shared door: same-site callers that
	 * fire `buddynext_ability_granted` directly have no HTTP request to log from,
	 * and they need the same trail this one writes.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id User ID.
	 * @param array  $body    Request body (payload stored as JSON).
	 * @param string $status  'success' or 'error'.
	 */
	private function log( string $action, int $user_id, array $body, string $status ): void {
		WebhookLog::write( $action, $user_id, $body, $status, $this->accepted_signature );
	}
}
