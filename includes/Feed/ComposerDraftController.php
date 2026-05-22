<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Composer-draft REST controller.
 *
 * Routes (all under buddynext/v1):
 *   POST /me/drafts — store the current user's composer draft in usermeta.
 *   GET  /me/drafts — read the current user's stored draft (used when the
 *                     viewer opts into cross-device cloud sync; localStorage
 *                     remains the canonical source of truth).
 *
 * The viewer opts into cloud-sync by flipping a localStorage flag in their
 * browser (`bn_composer_cloud_sync = '1'`). The server endpoint stays
 * stateless in spirit: the payload is stored verbatim under usermeta
 * `bn_composer_draft` and overwritten on every save. There is no history
 * and no per-composer keying; the latest draft wins. Auto-cleanup happens
 * implicitly when the user successfully publishes (POST /posts) — the JS
 * side calls `clearDraft()` which both removes the localStorage entry and
 * issues no further cloud-sync writes until the next composer session.
 *
 * @package BuddyNext\Feed
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace BuddyNext\Feed;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Stores composer drafts on usermeta so they can sync across the viewer's devices.
 */
class ComposerDraftController {

	/**
	 * User-meta key under which the latest draft payload is stored.
	 */
	private const META_KEY = 'bn_composer_draft';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/me/drafts',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'require_auth' ),
					'args'                => array(
						'payload' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'clear' ),
					'permission_callback' => array( $this, 'require_auth' ),
				),
			)
		);
	}

	/**
	 * Persist the current user's draft.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$payload = (array) $request->get_param( 'payload' );

		// Light sanitisation: only known keys are stored. Content is kept
		// raw so the textarea can re-render line breaks and whitespace
		// exactly as the user typed them; sanitisation happens when the
		// draft is actually published via PostController::create_post().
		$clean = array(
			'content'      => isset( $payload['content'] ) ? (string) $payload['content'] : '',
			'composerType' => isset( $payload['composerType'] ) ? sanitize_key( (string) $payload['composerType'] ) : 'text',
			'privacy'      => isset( $payload['privacy'] ) ? sanitize_key( (string) $payload['privacy'] ) : 'public',
			'spaceId'      => isset( $payload['spaceId'] ) ? (int) $payload['spaceId'] : 0,
			'savedAt'      => isset( $payload['savedAt'] ) ? (int) $payload['savedAt'] : time() * 1000,
		);

		update_user_meta( $user_id, self::META_KEY, $clean );

		return new WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * Return the stored draft for the current user, or an empty payload.
	 *
	 * @return WP_REST_Response
	 */
	public function read(): WP_REST_Response {
		$user_id = get_current_user_id();
		$stored  = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return new WP_REST_Response( array( 'payload' => $stored ), 200 );
	}

	/**
	 * Clear the stored draft for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function clear(): WP_REST_Response {
		$user_id = get_current_user_id();
		delete_user_meta( $user_id, self::META_KEY );
		return new WP_REST_Response( array( 'cleared' => true ), 200 );
	}

	/**
	 * Require the user to be logged in.
	 *
	 * @return bool|WP_Error
	 */
	public function require_auth(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'You must be logged in.', 'buddynext' ), array( 'status' => 401 ) );
		}
		return true;
	}
}
