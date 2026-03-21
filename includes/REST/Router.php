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
use BuddyNext\REST\Controllers\BlockController;
use BuddyNext\REST\Controllers\BookmarkController;
use BuddyNext\REST\Controllers\ConnectionController;
use BuddyNext\REST\Controllers\FeedController;
use BuddyNext\REST\Controllers\FollowController;
use BuddyNext\REST\Controllers\PollController;
use BuddyNext\REST\Controllers\PostController;
use BuddyNext\REST\Controllers\ProfileController;
use BuddyNext\REST\Controllers\SearchController;
use BuddyNext\REST\Controllers\ShareController;
use BuddyNext\REST\Controllers\SpaceController;

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
		( new FollowController() )->register_routes();
		( new ConnectionController() )->register_routes();
		( new BlockController() )->register_routes();
		( new PostController() )->register_routes();
		( new FeedController() )->register_routes();
		( new PollController() )->register_routes();
		( new BookmarkController() )->register_routes();
		( new ShareController() )->register_routes();
		( new ProfileController() )->register_routes();
		( new SearchController() )->register_routes();
		( new SpaceController() )->register_routes();
	}
}
