<?php
/**
 * Main plugin orchestrator.
 *
 * Boots at plugins_loaded:15 — after first-party addons (priority 10)
 * and before BuddyNext Pro (priority 20).
 *
 * @package BuddyNext\Core
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

use BuddyNext\Admin\Settings;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PollService;
use BuddyNext\Feed\PostService;
use BuddyNext\Feed\ShareService;
use BuddyNext\Blocks\BlockRegistrar;
use BuddyNext\Bridges\CareerBoard as CareerBoardBridge;
use BuddyNext\Bridges\Jetonomy as JetonomyBridge;
use BuddyNext\Bridges\WBGamification as WBGamificationBridge;
use BuddyNext\Bridges\WPMediaVerse as WPMediaVerseBridge;
use BuddyNext\Comments\CommentService;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\REST\Router;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Search\MemberDirectoryService;
use BuddyNext\Search\SearchService;
use BuddyNext\SocialGraph\BlockService;
use BuddyNext\SocialGraph\ConnectionService;
use BuddyNext\SocialGraph\FollowService;
use BuddyNext\SocialGraph\PrivacyService;

/**
 * Plugin bootstrap.
 */
class Plugin {

	/**
	 * Guards against double-boot.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boot the plugin.
	 *
	 * Called via add_action( 'plugins_loaded', ..., 15 ) in buddynext.php.
	 */
	public static function init(): void {
		if ( static::$booted ) {
			return;
		}

		static::$booted = true;

		$container = Container::instance();
		static::register_services( $container );

		if ( is_admin() ) {
			$container->get( 'admin_settings' )->register();
		}

		$container->get( 'rest_router' )->register();

		// Register Gutenberg blocks and block patterns.
		( new BlockRegistrar() )->init();

		// Boot first-party bridges unconditionally — each bridge guards itself
		// against its dependency being absent via class_exists checks at hook time.
		add_action(
			'buddynext_load_bridges',
			function (): void {
				( new WPMediaVerseBridge() )->init();
				( new WBGamificationBridge() )->init();
				( new JetonomyBridge() )->init();
				( new CareerBoardBridge() )->init();
			}
		);

		/**
		 * Fires after BuddyNext services are registered.
		 *
		 * Bridge classes (WPMediaVerse, Jetonomy, etc.) hook here to load.
		 */
		do_action( 'buddynext_load_bridges' );

		/**
		 * Fires when BuddyNext is fully initialised.
		 *
		 * Pro plugin and any third-party extensions hook here.
		 */
		do_action( 'buddynext_loaded' );
	}

	/**
	 * Bind core services into the container.
	 *
	 * @param Container $container DI container.
	 */
	private static function register_services( Container $container ): void {
		$container->bind( 'permissions', fn() => new PermissionService() );
		$container->bind( 'abilities', fn() => new Abilities() );
		$container->bind( 'follows', fn() => new FollowService() );
		$container->bind( 'connections', fn() => new ConnectionService() );
		$container->bind( 'blocks', fn() => new BlockService() );
		$container->bind(
			'privacy',
			fn( $c ) => new PrivacyService(
				$c->get( 'follows' ),
				$c->get( 'connections' ),
				$c->get( 'blocks' )
			)
		);
		$container->bind( 'post_service', fn() => new PostService() );
		$container->bind( 'feed', fn( $c ) => new FeedService( $c->get( 'follows' ) ) );
		$container->bind( 'polls', fn() => new PollService() );
		$container->bind( 'bookmarks', fn() => new BookmarkService() );
		$container->bind( 'shares', fn() => new ShareService() );
		$container->bind( 'profiles', fn() => new ProfileService() );
		$container->bind( 'search', fn() => new SearchService() );
		$container->bind( 'member_directory', fn( $c ) => new MemberDirectoryService( $c->get( 'follows' ) ) );
		$container->bind( 'spaces', fn() => new SpaceService() );
		$container->bind( 'space_members', fn() => new SpaceMemberService() );
		$container->bind( 'notifications', fn() => new NotificationService() );
		$container->bind( 'notification_prefs', fn() => new NotificationPrefService() );
		$container->bind( 'reactions', fn() => new ReactionService() );
		$container->bind( 'comments', fn() => new CommentService() );
		$container->bind( 'hashtags', fn() => new HashtagService() );
		$container->bind( 'moderation', fn() => new ModerationService() );
		$container->bind( 'mod_log', fn() => new ModerationLogService() );
		$container->bind( 'rest_router', fn() => new Router() );
		$container->bind( 'admin_settings', fn() => new Settings() );

		// Abilities must be registered at plugins_loaded:15 so they are
		// available before rest_api_init and admin_menu fire.
		$container->get( 'abilities' )->register();
	}
}
