<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * REST controller for one-click companion installs.
 *
 * Route (under buddynext/v1):
 *   POST /companions/install — install + activate a catalog companion.
 *
 * Security: permission_callback requires the `install_plugins` capability (the
 * same gate WordPress puts on plugin installs); WP verifies the wp_rest nonce on
 * the cookie-authenticated request. Only registry slugs are accepted, and the
 * actual download host is locked to wbcomdesigns.com inside the installer.
 *
 * @package BuddyNext\Integrations
 */

declare( strict_types=1 );

namespace BuddyNext\Integrations;

use BuddyNext\REST\BaseRestController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles the companion install action over REST.
 */
class CompanionController extends BaseRestController {

	/**
	 * Register the controller's routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			'buddynext/v1',
			'/companions/install',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'install' ),
				'permission_callback' => array( $this, 'require_install_plugins' ),
				'args'                => array(
					'slug' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Install + activate the requested companion.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function install( WP_REST_Request $request ) {
		$slug = (string) $request->get_param( 'slug' );

		// Reject anything not in the catalog before touching the installer.
		if ( null === CompanionRegistry::get( $slug ) ) {
			return new WP_Error( 'buddynext_unknown_companion', __( 'Unknown integration.', 'buddynext' ), array( 'status' => 404 ) );
		}

		$result = CompanionInstaller::install( $slug );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'installed' => true,
				'slug'      => $slug,
				'status'    => CompanionRegistry::status( $slug ),
			),
			200
		);
	}

	/**
	 * Permission callback: the WordPress plugin-install capability.
	 *
	 * @return true|WP_Error
	 */
	public function require_install_plugins(): true|WP_Error {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'buddynext_cap', __( 'You do not have permission to install plugins.', 'buddynext' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
