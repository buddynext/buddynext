<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Admin slug-check REST controller.
 *
 * Replaces the legacy wp_ajax_bn_check_slug admin-ajax surface. The Nav Manager
 * admin page calls GET /buddynext/v1/admin/slug-check to probe whether a proposed
 * hub URL slug conflicts with reserved WP keywords, another BN hub, or an existing
 * post/page.
 *
 * The controller is admin-only (manage_options) and delegates the actual
 * conflict-resolution logic to NavManager::check_slug_status() so the source of
 * truth stays in one place.
 *
 * @package BuddyNext\Admin
 */

declare( strict_types=1 );

namespace BuddyNext\Admin;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Read-only REST endpoint that returns slug conflict status for the Nav Manager.
 *
 * Route: GET /buddynext/v1/admin/slug-check?slug=&context=
 * Permission: current_user_can( 'manage_options' )
 * Nonce: standard wp_rest (sent via X-WP-Nonce header).
 *
 * Response shape:
 *   { "status": "free" | "warn" | "block" }
 */
class SlugCheckController {

	/**
	 * Attach the registration callback to rest_api_init.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the slug-check route under buddynext/v1.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/admin/slug-check',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_slug' ),
				'permission_callback' => array( $this, 'permission_check' ),
				'args'                => array(
					'slug'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_title',
						'validate_callback' => array( $this, 'validate_slug' ),
					),
					'context' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Restrict the endpoint to site administrators.
	 *
	 * @return bool True when the current user can manage options.
	 */
	public function permission_check(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Reject empty / non-string slugs before the callback runs.
	 *
	 * @param mixed $value Raw request value.
	 * @return bool
	 */
	public function validate_slug( $value ): bool {
		return is_string( $value ) && '' !== trim( $value );
	}

	/**
	 * Return the slug conflict status as { status: 'free' | 'warn' | 'block' }.
	 *
	 * Delegates to NavManager::check_slug_status() so both the legacy form save
	 * path and the live probe share the same conflict-detection logic.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function check_slug( WP_REST_Request $request ): WP_REST_Response {
		$slug    = (string) $request->get_param( 'slug' );
		$context = (string) $request->get_param( 'context' );

		$status = ( new NavManager() )->check_slug_status( $slug, $context );

		return new WP_REST_Response(
			array(
				'status' => $status,
			),
			200
		);
	}
}
