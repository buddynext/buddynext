<?php
/**
 * REST API router.
 *
 * Registers the buddynext/v1 namespace and delegates route registration
 * to each controller.
 *
 * @package BuddyNext\REST
 */

declare( strict_types=1 );

namespace BuddyNext\REST;

use BuddyNext\REST\Controllers\AccessWebhookController;

/**
 * Hooks REST controllers into rest_api_init.
 */
class Router {

	/**
	 * Attach the registration callback to rest_api_init.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register all buddynext/v1 routes.
	 *
	 * Called by rest_api_init.
	 */
	public function register_routes(): void {
		( new AccessWebhookController() )->register_routes();
	}
}
