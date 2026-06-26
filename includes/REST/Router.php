<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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

use BuddyNext\Admin\SlugCheckController;
use BuddyNext\Realtime\RealtimeController;
use BuddyNext\Outbound\AccessWebhookController;
use BuddyNext\Auth\AuthController;
use BuddyNext\Auth\TwoFactorController;
use BuddyNext\Onboarding\InviteController;
use BuddyNext\Onboarding\OnboardingController;
use BuddyNext\SocialGraph\BlockController;
use BuddyNext\Feed\BookmarkController;
use BuddyNext\Feed\ComposerDraftController;
use BuddyNext\SocialGraph\ConnectionController;
use BuddyNext\Feed\FeedController;
use BuddyNext\SocialGraph\FollowController;
use BuddyNext\Feed\PollController;
use BuddyNext\Feed\PostController;
use BuddyNext\Profile\MemberDirectoryController;
use BuddyNext\Profile\ProfileController;
use BuddyNext\Search\SearchController;
use BuddyNext\Feed\ShareController;
use BuddyNext\Comments\CommentController;
use BuddyNext\Hashtags\HashtagController;
use BuddyNext\MemberTypes\MemberTypeController;
use BuddyNext\Moderation\ModerationController;
use BuddyNext\Notifications\NotificationController;
use BuddyNext\Outbound\OutboundWebhookController;
use BuddyNext\Reactions\ReactionController;
use BuddyNext\Spaces\SpaceCategoryController;
use BuddyNext\Spaces\SpaceController;

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
		/**
		 * Fires before BuddyNext registers its REST routes.
		 *
		 * Lets extensions register their own routes under the buddynext/v1
		 * namespace, or wrap core controllers, in the same rest_api_init pass.
		 */
		do_action( 'buddynext_rest_init' );

		( new AccessWebhookController() )->register_routes();
		( new AuthController() )->register_routes();
		( new TwoFactorController() )->register_routes();
		( new InviteController() )->register_routes();
		( new OnboardingController() )->register_routes();
		( new FollowController() )->register_routes();
		( new ConnectionController() )->register_routes();
		( new BlockController() )->register_routes();
		( new PostController() )->register_routes();
		( new FeedController() )->register_routes();
		( new PollController() )->register_routes();
		( new BookmarkController() )->register_routes();
		( new ComposerDraftController() )->register_routes();
		( new ShareController() )->register_routes();
		( new ProfileController() )->register_routes();
		( new MemberDirectoryController() )->register_routes();
		( new SearchController() )->register_routes();
		( new SpaceCategoryController() )->register_routes();
		( new SpaceController() )->register_routes();
		( new NotificationController() )->register_routes();
		( new ReactionController() )->register_routes();
		( new CommentController() )->register_routes();
		( new HashtagController() )->register_routes();
		( new ModerationController() )->register_routes();
		( new MemberTypeController( buddynext_service( 'member_types' ) ) )->register_routes();
		if ( buddynext_service( 'features' )->is_enabled( 'webhooks' ) ) {
			( new OutboundWebhookController( buddynext_service( 'webhooks' ) ) )->register_routes();
		}
		( new SlugCheckController() )->register_routes();
		( new RealtimeController() )->register_routes();
		( new \BuddyNext\Media\MediaController() )->register_routes();
		( new \BuddyNext\Integrations\CompanionController() )->register_routes();

		/**
		 * Fires after all BuddyNext core REST routes are registered.
		 *
		 * Use for routes that must register after core (e.g. to override or
		 * decorate a core endpoint).
		 */
		do_action( 'buddynext_rest_routes_registered' );
	}
}
