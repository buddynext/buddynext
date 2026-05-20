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
		$result = $this->dispatch( $action, $user->ID, $body );
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
	private function dispatch( string $action, int $user_id, array $body ): true|WP_Error {
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
	private function action_set_role( int $user_id, array $body ): true|WP_Error {
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
	private function action_grant_ability( int $user_id, array $body ): true|WP_Error {
		global $wpdb;

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_user_abilities',
			array(
				'user_id'    => $user_id,
				'ability'    => $ability,
				'source'     => $source,
				'expires_at' => $expires_at,
			)
		);

		wp_cache_delete( "bn_abilities_{$user_id}" );

		/**
		 * Fires when an ability is granted to a user.
		 *
		 * @param int    $user_id User ID.
		 * @param string $ability Ability slug.
		 */
		do_action( 'buddynext_ability_granted', $user_id, $ability );

		return true;
	}

	/**
	 * Revoke an ability from a user.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true|WP_Error
	 */
	private function action_revoke_ability( int $user_id, array $body ): true|WP_Error {
		global $wpdb;

		$ability = sanitize_text_field( $body['ability'] ?? '' );

		if ( ! $ability ) {
			return new WP_Error(
				'missing_ability',
				__( '"ability" is required.', 'buddynext' ),
				array( 'status' => 400 )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete(
			$wpdb->prefix . 'bn_user_abilities',
			array(
				'user_id' => $user_id,
				'ability' => $ability,
			)
		);

		wp_cache_delete( "bn_abilities_{$user_id}" );

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
	private function action_add_credits( int $user_id, array $body ): true {
		global $wpdb;

		$amount = abs( (int) ( $body['amount'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance)
				 VALUES (%d, %d)
				 ON DUPLICATE KEY UPDATE balance = balance + %d",
				$user_id,
				$amount,
				$amount
			)
		);

		return true;
	}

	/**
	 * Replace a user's credit balance.
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true
	 */
	private function action_set_credits( int $user_id, array $body ): true {
		global $wpdb;

		$amount = max( 0, (int) ( $body['amount'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$wpdb->prefix . 'bn_user_credits',
			array(
				'user_id' => $user_id,
				'balance' => $amount,
			)
		);

		return true;
	}

	/**
	 * Deduct from a user's credit balance (floors at 0).
	 *
	 * @param int   $user_id User ID.
	 * @param array $body    Request body.
	 * @return true
	 */
	private function action_deduct_credits( int $user_id, array $body ): true {
		global $wpdb;

		$amount = abs( (int) ( $body['amount'] ?? 0 ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->prefix}bn_user_credits (user_id, balance)
				 VALUES (%d, 0)
				 ON DUPLICATE KEY UPDATE balance = GREATEST(0, balance - %d)",
				$user_id,
				$amount
			)
		);

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
	 * Verify the HMAC-SHA256 signature from the X-BuddyNext-Signature header.
	 *
	 * Returns a WP_Error when the secret is not configured, false when the
	 * signature does not match, and true when it passes.
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

		$header   = (string) ( $request->get_header( 'X-BuddyNext-Signature' ) ?? '' );
		$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );

		return hash_equals( $expected, $header );
	}

	/**
	 * Write an audit row to bn_webhook_log.
	 *
	 * @param string $action  Action slug.
	 * @param int    $user_id User ID.
	 * @param array  $body    Request body (payload stored as JSON).
	 * @param string $status  'success' or 'error'.
	 */
	private function log( string $action, int $user_id, array $body, string $status ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'bn_webhook_log',
			array(
				'source'  => sanitize_key( $body['source'] ?? '' ),
				'action'  => $action,
				'user_id' => $user_id,
				'payload' => wp_json_encode( $body ),
				'status'  => $status,
			)
		);
	}
}
