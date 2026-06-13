<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Base REST controller.
 *
 * Shared permission helpers for buddynext/v1 controllers. Controllers extend
 * this to avoid re-declaring require_auth()/require_admin(). Each controller
 * still implements register_routes() and its own handlers.
 *
 * @package BuddyNext\REST
 */

declare( strict_types=1 );

namespace BuddyNext\REST;

use WP_Error;

/**
 * Permission helpers shared across REST controllers.
 */
abstract class BaseRestController {

	/**
	 * Register the controller's routes. Called from REST\Router.
	 */
	abstract public function register_routes(): void;

	/**
	 * Require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Require a user who can manage the community.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do that.', 'buddynext' ),
				array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
			);
		}

		return true;
	}

	/**
	 * Require a community moderator (currently site managers).
	 *
	 * Distinct method name so moderator-gated routes read clearly and can gain
	 * space-moderator semantics later without touching every controller.
	 *
	 * @return true|WP_Error
	 */
	public function require_moderator(): true|WP_Error {
		return $this->require_admin();
	}
}
