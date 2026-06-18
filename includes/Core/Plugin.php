<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
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

use BuddyNext\Admin\AdminHub;
use BuddyNext\Admin\EmailEditor;
use BuddyNext\Admin\Members;
use BuddyNext\Admin\NavManager;
use BuddyNext\Admin\Settings;
use BuddyNext\Admin\Spaces;
use BuddyNext\PWA\PwaService;
use BuddyNext\Shortcodes\ShortcodeService;
use BuddyNext\Widgets\WidgetService;
use BuddyNext\Core\AssetService;
use BuddyNext\Core\CacheService;
use BuddyNext\Core\CounterService;
use BuddyNext\Core\CronScheduler;
use BuddyNext\Core\RoleService;
use BuddyNext\Core\TemplateLoader;
use BuddyNext\Core\PageRouter;
use BuddyNext\Theme\TokenService;
use BuddyNext\Feed\BookmarkService;
use BuddyNext\Feed\FeedService;
use BuddyNext\Feed\PollService;
use BuddyNext\Feed\PostService;
use BuddyNext\Moderation\SafeguardService;
use BuddyNext\Feed\ShareService;
use BuddyNext\Blocks\BlockRegistrar;
use BuddyNext\Bridges\BuddyXBridge;
use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Bridges\GamificationBridgeListener;
use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Bridges\JetonomyBridgeListener;
use BuddyNext\Bridges\WPMediaVerseBridge;
use BuddyNext\Comments\CommentService;
use BuddyNext\Hashtags\HashtagListener;
use BuddyNext\Hashtags\HashtagService;
use BuddyNext\Moderation\ModerationListener;
use BuddyNext\Moderation\ModerationLogService;
use BuddyNext\Moderation\ModerationService;
use BuddyNext\Notifications\EmailDispatchListener;
use BuddyNext\Notifications\EmailSender;
use BuddyNext\Notifications\NotificationListener;
use BuddyNext\Notifications\NotificationMessageService;
use BuddyNext\Notifications\NotificationPrefService;
use BuddyNext\Notifications\NotificationService;
use BuddyNext\Profile\AvatarService;
use BuddyNext\Profile\ProfileService;
use BuddyNext\Reactions\ReactionService;
use BuddyNext\REST\Router;
use BuddyNext\Spaces\SpaceMemberService;
use BuddyNext\Spaces\SpaceService;
use BuddyNext\Profile\MemberDirectoryService;
use BuddyNext\Search\SearchIndexListener;
use BuddyNext\Search\SearchService;
use BuddyNext\Auth\VerificationListener;
use BuddyNext\Auth\VerificationService;
use BuddyNext\Outbound\OutboundWebhookService;
use BuddyNext\Onboarding\OnboardingListener;
use BuddyNext\Privacy\PrivacyTools;
use BuddyNext\Outbound\OutboundWebhookListener;
use BuddyNext\Realtime\TransportFactory;
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
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		$container = Container::instance();
		self::register_services( $container );

		// Purge a user's social-graph / per-user rows when their account is
		// deleted (any path: admin, CLI, REST) so no orphans are left behind.
		( new \BuddyNext\SocialGraph\UserCleanupListener() )->register();

		// Enforce per-space "who can post" + "require approval" at post-save time
		// (the composer gate alone is bypassable via REST).
		( new \BuddyNext\Spaces\SpacePostGuard() )->register();

		// Apply the admin-editable capability → required-role overrides on top of
		// PermissionService's defaults. Registered front + admin (the gate must
		// change everywhere, not just in wp-admin) via the native role-map filter
		// the Roles & Capabilities editor writes (option bn_role_map_overrides).
		add_filter(
			'buddynext_role_map',
			static function ( array $map ): array {
				$overrides = get_option( 'bn_role_map_overrides', array() );
				return is_array( $overrides ) && ! empty( $overrides ) ? array_merge( $map, $overrides ) : $map;
			}
		);

		// Apply admin Appearance options on the front-end (accent colour, default
		// theme, custom CSS). Registered everywhere — branding is not admin-only.
		( new \BuddyNext\Theme\Appearance() )->register();

		// Apply Settings → Navigation overrides (hidden/label/order) to the
		// front-end nav renderers. NavManager (the admin UI) only runs in
		// wp-admin, so this front-end applier is what actually makes those saved
		// settings take effect.
		( new \BuddyNext\Nav\NavOverrides() )->register();

		// Front-end cookie-consent banner (no-op unless the Privacy setting is on).
		( new \BuddyNext\Privacy\CookieConsentService() )->register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'buddynext demo', new \BuddyNext\Demo\DemoCommand() );
			\WP_CLI::add_command( 'buddynext cert', new \BuddyNext\Cert\CertCommand() );
		}

		// Record last-login time on every login. This MUST be wired
		// unconditionally: logins happen via REST (/auth/login), wp-login.php,
		// and social login — all non-admin contexts where the admin-only
		// Members::register() does not run. Hooking it here (outside is_admin)
		// is what makes the admin Members list show a real last-login instead
		// of always "Never".
		add_action(
			'wp_login',
			array( $container->get( 'admin_members' ), 'handle_last_login' ),
			10,
			2
		);

		if ( is_admin() ) {
			// AdminHub owns the BuddyNext top-level menu and dispatches every
			// section page to its registered tabs. Boot first so feature
			// classes that call AdminHub::register_tab() in their register()
			// methods find the hub already initialised.
			AdminHub::instance()->init();

			// Admin pages call AdminHub::register_tab() with __() labels in their
			// register() methods. Defer registration to `init` so those labels are
			// not evaluated before the textdomain is available (WP 6.7+'s
			// _load_textdomain_just_in_time notice). Every page here only attaches
			// admin_* hooks (admin_menu, admin_init, admin_post_*,
			// admin_enqueue_scripts), all of which fire after init, so the menus
			// and handlers are in place before they are needed.
			add_action(
				'init',
				static function () use ( $container ): void {
					$container->get( 'admin_settings' )->register();
					$container->get( 'admin_members' )->register();
					$container->get( 'admin_spaces' )->register();
					$container->get( 'admin_nav' )->register();
					$container->get( 'admin_email_editor' )->register();
					$container->get( 'setup_wizard' )->init();
					( new \BuddyNext\Demo\DemoAdmin() )->register();
					( new \BuddyNext\Admin\AppearanceTab() )->register();
					( new \BuddyNext\Admin\ToolsTab() )->register();
					( new \BuddyNext\Admin\RolesTab() )->register();
					( new \BuddyNext\Admin\Insights() )->register();
					( new \BuddyNext\Admin\ModerationQueue() )->register();
					// "BuddyNext" metabox on Appearance → Menus — add per-member
					// account and auth links to any WordPress menu (resolved by
					// MenuRenderer).
					( new \BuddyNext\Admin\NavMenuMetabox() )->register();
					( new PageSetup() )->register();
				}
			);

			// Redirect to setup wizard on first activation.
			add_action(
				'admin_init',
				static function (): void {
					// Only consume the one-shot activation redirect on a genuine,
					// user-facing admin page load. admin_init also fires for AJAX,
					// REST, cron and Heartbeat requests; letting any of those
					// read-and-delete the transient was a race that swallowed the
					// redirect before the browser reached the Plugins page.
					if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
						return;
					}
					// Browser page loads are GET; ignore POST/PUT admin actions.
					if ( 'GET' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) ) {
						return;
					}
					// Never hijack the screen during bulk (multi-plugin) activation.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only presence check, no state mutation.
					if ( isset( $_GET['activate-multi'] ) ) {
						return;
					}
					if ( ! get_transient( 'buddynext_do_activation_redirect' ) ) {
						return;
					}
					delete_transient( 'buddynext_do_activation_redirect' );
					if ( current_user_can( 'manage_options' ) && '1' !== (string) get_option( 'buddynext_setup_complete', '0' ) ) {
						wp_safe_redirect( admin_url( 'admin.php?page=buddynext-setup' ) );
						exit;
					}
				}
			);

			// Admin notice: prompt admins to complete setup when wizard is not done.
			add_action(
				'admin_notices',
				static function (): void {
					if ( '1' === (string) get_option( 'buddynext_setup_complete', '0' ) ) {
						return;
					}
					if ( ! current_user_can( 'manage_options' ) ) {
						return;
					}
					// Suppress on the wizard page itself.
					$screen = get_current_screen();
					if ( $screen && str_contains( (string) $screen->id, 'buddynext-setup' ) ) {
						return;
					}
					$wizard_url = admin_url( 'admin.php?page=buddynext-setup' );
					printf(
						'<div class="notice notice-info"><p><strong>%s</strong> — <a href="%s">%s</a></p></div>',
						esc_html__( 'BuddyNext setup is not complete.', 'buddynext' ),
						esc_url( $wizard_url ),
						esc_html__( 'Run the setup wizard', 'buddynext' )
					);
				}
			);
		}

		// Register and enqueue frontend assets.
		$container->get( 'assets' )->init();

		// Strip foreign CSS/JS from BN routes for a uniform, conflict-free UX.
		$container->get( 'asset_isolation' )->init();

		$container->get( 'rest_router' )->register();

		// Wire avatar filter — replaces Gravatar site-wide with BuddyNext initials SVG.
		$container->get( 'avatars' )->init();

		// Wire social-event hooks to in-app notification routing.
		( new NotificationListener() )->register();

		// Wire email verification hooks.
		( new VerificationListener( $container->get( 'verification' ) ) )->register();

		// Wire admin-approval registration emails (pending / approved / rejected).
		( new \BuddyNext\Auth\RegistrationEmailListener() )->register();

		// Social login (OAuth2) — registers configured providers into the
		// buddynext_auth_social_providers seam + handles the OAuth round-trip.
		( new \BuddyNext\Auth\SocialLogin() )->register();

		// Approval-mode gate: block sign-in for accounts awaiting administrator
		// approval (set during registration when buddynext_reg_mode = 'approval').
		add_filter(
			'wp_authenticate_user',
			static function ( $user ) {
				if ( $user instanceof \WP_User && get_user_meta( $user->ID, 'bn_pending_approval', true ) ) {
					return new \WP_Error(
						'bn_pending_approval',
						__( 'Your account is awaiting administrator approval.', 'buddynext' )
					);
				}
				return $user;
			},
			10,
			1
		);

		// Wire search index lifecycle hooks — handles async dispatch via Action
		// Scheduler when available, or falls back to synchronous inline indexing.
		$container->get( 'search_index_listener' )->register();

		// Wire hashtag extraction to post_created and bridge index actions.
		( new HashtagListener( $container->get( 'hashtags' ) ) )->register();

		// Wire the on-demand scheduled-post publisher (Free-owned; works without
		// Pro). Posts created with a future scheduled_at publish via a single
		// cron event armed at their due time — no perpetual poll.
		\BuddyNext\Feed\ScheduledPostsPublisher::register();

		// Wire moderation notification/email handlers and daily cron alert.
		( new ModerationListener() )->register();

		// Backfill the directory search mirror for all members when an admin
		// edits a profile field's searchable flag or default visibility, so the
		// change applies to existing members without waiting for each to re-save.
		add_action(
			'buddynext_profile_field_updated',
			static function ( $field_id ) use ( $container ) {
				$container->get( 'profiles' )->rebuild_field_mirror( (int) $field_id );
			},
			10,
			1
		);

		// Wire onboarding nudge scheduling and cron handlers.
		( new OnboardingListener() )->register();

		// Wire the WordPress Privacy Tools integration so Tools → Export/Erase
		// Personal Data covers BuddyNext's custom tables and bn_* user meta.
		// Registered unconditionally — admin GDPR/CCPA compliance must always work.
		( new PrivacyTools() )->register();

		// Wire per-session + per-day engagement pulses (streak driver
		// for gamification plugins). Idempotent within a session window
		// and within a UTC calendar day via transient guards.
		( new \BuddyNext\Engagement\SessionTracker() )->register();

		// Online-presence heartbeat — stamps bn_last_active for the logged-in
		// user (zero-JS via template_redirect, topped up by the REST heartbeat).
		( new \BuddyNext\Realtime\PresenceService() )->register();

		// BN-native media assets (grid/tile styles + lightbox). API-level
		// consumption of WPMediaVerse only — BN owns the media UX entirely.
		( new \BuddyNext\Media\MediaAssets() )->register();

		// Wire outbound webhooks (cron retry + domain event listener) only when
		// the opt-in feature is enabled — otherwise no deliveries fire.
		if ( $container->get( 'features' )->is_enabled( 'webhooks' ) ) {
			$container->get( 'webhooks' )->init();
			( new OutboundWebhookListener() )->register();
		}

		// Sidebar feature — Listener registers cache-bust hooks. Conditional
		// per plug-and-play model: only when the feature is bound.
		if ( $container->has( 'sidebar_widgets' ) ) {
			( new \BuddyNext\Sidebar\WidgetListener( $container->get( 'sidebar_cache' ) ) )->register();
		}

		// Feed cache — always bound (feed is mandatory). Listener busts
		// the writer's first-page cache on post_created / post_deleted.
		( new \BuddyNext\Feed\FeedListener( $container->get( 'feed_cache' ) ) )->register();

		// Wire email dispatch to the notification created action.
		( new EmailDispatchListener(
			$container->get( 'email_sender' ),
			$container->get( 'notification_prefs' )
		) )->register();

		// Register Gutenberg blocks and block patterns.
		( new BlockRegistrar() )->init();

		// Resolve BuddyNext `#bn-*` menu items to the current member in any WP
		// menu (and hide items that do not match the visitor's login state).
		( new \BuddyNext\Nav\MenuRenderer() )->register();

		// Register URL rewrite rules for pretty profile URLs.
		( new PageRouter() )->init();

		// Register core shortcodes.
		$container->get( 'shortcodes' )->init();

		// Register sidebar widgets.
		$container->get( 'widgets' )->init();

		// Register PWA manifest + service worker.
		$container->get( 'pwa' )->init();

		// Emit CSS custom-property token block on wp_head.
		( new TokenService() )->init();

		// Register WP-Cron schedules and recurring events.
		( new CronScheduler() )->init();

		// Handle the one-time post-activation reindex cron (scheduled by Installer
		// when Action Scheduler is absent and the container is not yet available).
		add_action( 'buddynext_reindex_all_cron', array( SearchService::class, 'reindex_all_cron' ) );

		// Register navigation menu locations + custom meta box in Appearance > Menus.
		add_action( 'after_setup_theme', array( new self(), 'register_nav_menus' ) );
		add_action( 'admin_head-nav-menus.php', array( new self(), 'add_nav_menu_meta_box' ) );

		// Level 2 context nav — per-section sub-navigation items.
		add_filter( 'buddynext_context_nav', array( new self(), 'register_context_nav' ), 10, 2 );

		// Boot first-party bridges at plugins_loaded:25 so they fire after both
		// BuddyNext (priority 15) and Pro plugins like Jetonomy Pro / WPMediaVerse Pro
		// (priority 20). Each bridge guards itself via class_exists checks at hook time.
		add_action(
			'plugins_loaded',
			static function (): void {
				do_action( 'buddynext_load_bridges' );
			},
			25
		);

		add_action(
			'buddynext_load_bridges',
			function (): void {
				// BuddyX is a theme bridge, not a togglable feature — always wire it.
				( new BuddyXBridge() )->init();

				// Each integration bridge is gated on its Platform → Features toggle
				// (default-on; the bridge still self-guards via class_exists when the
				// partner plugin is absent), so turning a bridge off actually
				// disables it. CareerBoardBridge lives in Pro and gates itself on
				// the 'career_board' feature on this same seam.
				if ( buddynext_feature_enabled( 'wpmediaverse' ) ) {
					( new WPMediaVerseBridge() )->init();
				}
				if ( buddynext_feature_enabled( 'gamification' ) ) {
					( new GamificationBridge() )->init();
					( new GamificationBridgeListener() )->register();
					// Gamification's Achievements profile tab (badge grid + standing).
					( new \BuddyNext\Profile\GamificationAchievements() )->register();
				}
				if ( buddynext_feature_enabled( 'jetonomy' ) ) {
					( new JetonomyBridge() )->init();
					( new JetonomyBridgeListener() )->register();
				}
			}
		);

		/**
		 * Fires when BuddyNext is fully initialised.
		 *
		 * Pro plugin and any third-party extensions hook here.
		 */
		do_action( 'buddynext_loaded' );
	}

	/**
	 * Register BuddyNext navigation menu locations with WordPress.
	 *
	 * Hooked to `after_setup_theme` so themes can override or extend these
	 * locations in their own `after_setup_theme` callback.
	 *
	 * @return void
	 */
	public function register_nav_menus(): void {
		register_nav_menus(
			array(
				'buddynext-community' => __( 'BuddyNext Community Nav', 'buddynext' ),
			)
		);
	}

	/**
	 * Register Level 2 context nav items for core sections.
	 *
	 * Bridges (Jetonomy, WPMediaVerse) add their own items at higher priority.
	 *
	 * @param array  $items   Existing context nav items.
	 * @param string $section Current active section from main nav.
	 * @return array
	 */
	public function register_context_nav( array $items, string $section ): array {
		$current_url = home_url( add_query_arg( array() ) );

		switch ( $section ) {
			case 'spaces':
				// Space-level context nav is handled by the space template's own tab bar.
				break;

			case 'notifications':
				// Notification filters are inline in the template — no L2 nav needed.
				break;

			default:
				// No default context nav for feed, members, messages — these are single-purpose.
				break;
		}

		return $items;
	}

	/**
	 * Add a "BuddyNext Pages" meta box to Appearance > Menus.
	 *
	 * Lists all community pages so site owners can add Feed, Members, Spaces,
	 * Media, Discussions, Notifications, Messages to any WP nav menu.
	 */
	public function add_nav_menu_meta_box(): void {
		add_meta_box(
			'buddynext-nav-menu-pages',
			__( 'BuddyNext Pages', 'buddynext' ),
			array( $this, 'render_nav_menu_meta_box' ),
			'nav-menus',
			'side',
			'default'
		);
	}

	/**
	 * Render the BuddyNext pages meta box content.
	 *
	 * Uses the Walker_Nav_Menu_Checklist pattern so checked items can be
	 * added to the menu via the standard "Add to Menu" button.
	 */
	public function render_nav_menu_meta_box(): void {
		// WP core exposes the active menu id as a global, not a function — the
		// "Add to Menu" button is disabled until a menu is selected.
		global $nav_menu_selected_id;

		$pages = array(
			array(
				'title' => __( 'Feed', 'buddynext' ),
				'url'   => PageRouter::activity_url(),
			),
			array(
				'title' => __( 'Explore', 'buddynext' ),
				'url'   => PageRouter::explore_url(),
			),
			array(
				'title' => __( 'Members', 'buddynext' ),
				'url'   => PageRouter::people_url(),
			),
			array(
				'title' => __( 'Spaces', 'buddynext' ),
				'url'   => PageRouter::spaces_url(),
			),
			array(
				'title' => __( 'Notifications', 'buddynext' ),
				'url'   => PageRouter::notifications_url(),
			),
			array(
				'title' => __( 'Messages', 'buddynext' ),
				'url'   => PageRouter::messages_url(),
			),
			array(
				'title' => __( 'Search', 'buddynext' ),
				'url'   => PageRouter::search_url(),
			),
			array(
				'title' => __( 'Leaderboard', 'buddynext' ),
				'url'   => PageRouter::leaderboard_url(),
			),
		);

		// Add Jetonomy pages if active.
		if ( class_exists( 'Jetonomy\Jetonomy' ) && function_exists( 'Jetonomy\base_url' ) ) {
			$jt_base = \Jetonomy\base_url();
			$pages[] = array(
				'title' => __( 'Discussions', 'buddynext' ),
				'url'   => $jt_base . '/',
			);
		}

		// Add WPMediaVerse pages if active.
		if ( class_exists( 'WPMediaVerse\Core\Plugin' ) ) {
			$pages[] = array(
				'title' => __( 'Media', 'buddynext' ),
				'url'   => home_url( '/media/' ),
			);
		}

		// Build fake post objects for Walker_Nav_Menu_Checklist.
		$items = array();
		$i     = -1;
		foreach ( $pages as $page ) {
			$item                   = new \stdClass();
			$item->ID               = $i;
			$item->object_id        = $i;
			$item->db_id            = 0;
			$item->object           = 'buddynext';
			$item->menu_item_parent = 0;
			$item->type             = 'custom';
			$item->title            = $page['title'];
			$item->url              = $page['url'];
			$item->target           = '';
			$item->attr_title       = '';
			$item->description      = '';
			$item->classes          = array();
			$item->xfn              = '';
			$items[]                = $item;
			--$i;
		}

		$walker = new \Walker_Nav_Menu_Checklist( array() );
		?>
		<div id="buddynext-pages" class="posttypediv">
			<div id="tabs-panel-buddynext-pages" class="tabs-panel tabs-panel-active">
				<ul id="buddynext-pages-checklist" class="categorychecklist form-no-clear">
					<?php echo walk_nav_menu_tree( array_map( 'wp_setup_nav_menu_item', $items ), 0, (object) array( 'walker' => $walker ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- walker generates escaped HTML ?>
				</ul>
			</div>
			<p class="button-controls wp-clearfix">
				<span class="list-controls">
					<label class="arrangement-fields">
						<input type="checkbox" class="select-all" value="1">
						<?php esc_html_e( 'Select All', 'buddynext' ); ?>
					</label>
				</span>
				<span class="add-to-menu">
					<input type="submit"<?php wp_nav_menu_disabled_check( $nav_menu_selected_id ); ?> class="button-secondary submit-add-to-menu right" value="<?php esc_attr_e( 'Add to Menu', 'buddynext' ); ?>" name="add-buddynext-pages-menu-item" id="submit-buddynext-pages">
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Bind core services into the container.
	 *
	 * @param Container $container DI container.
	 */
	private static function register_services( Container $container ): void {
		/**
		 * Fires before BuddyNext registers its core services.
		 *
		 * Bindings registered here are overridden by the core bindings below, so
		 * use this for new services. To REPLACE a core service, rebind it on
		 * buddynext_services_registered instead (fires after the core bindings).
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'buddynext_register_services', $container );

		$container->bind( 'permissions', fn() => new PermissionService() );
		$container->bind( 'roles', fn() => new RoleService() );
		$container->bind( 'cache', fn() => new CacheService() );
		$container->bind( 'counters', fn() => new CounterService() );
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
		$container->bind( 'safeguard', fn() => new SafeguardService() );
		$container->bind( 'post_service', fn() => new PostService() );
		$container->bind( 'feed_cache', fn() => new \BuddyNext\Feed\FeedCache() );
		$container->bind( 'feed', fn( $c ) => new FeedService( $c->get( 'follows' ), $c->get( 'post_service' ), $c->get( 'feed_cache' ) ) );
		$container->bind( 'polls', fn() => new PollService() );
		$container->bind( 'bookmarks', fn() => new BookmarkService() );
		$container->bind( 'shares', fn() => new ShareService() );
		$container->bind( 'profiles', fn() => new ProfileService() );
		$container->bind( 'avatars', fn() => new AvatarService() );
		$container->bind( 'search', fn() => new SearchService() );
		$container->bind( 'search_index_listener', fn() => new SearchIndexListener() );
		$container->bind( 'member_directory', fn() => new MemberDirectoryService() );
		$container->bind( 'spaces', fn() => new SpaceService() );
		$container->bind( 'space_members', fn() => new SpaceMemberService() );
		$container->bind( 'notifications', fn() => new NotificationService() );
		$container->bind( 'notification_prefs', fn() => new NotificationPrefService() );
		$container->bind( 'notification_message', fn() => new NotificationMessageService() );
		$container->bind( 'notification_pref_catalogue', fn() => new \BuddyNext\Notifications\NotificationPrefCatalogue() );
		$container->bind(
			'email_sender',
			fn( $c ) => new EmailSender( $c->get( 'notification_prefs' ), $c->get( 'notification_pref_catalogue' ) )
		);
		$container->bind( 'reactions', fn() => new ReactionService() );
		$container->bind( 'comments', fn() => new CommentService() );
		$container->bind( 'hashtags', fn() => new HashtagService() );
		$container->bind( 'moderation', fn() => new ModerationService() );
		$container->bind( 'mod_log', fn() => new ModerationLogService() );
		$container->bind( 'member_types', fn( $c ) => new \BuddyNext\MemberTypes\MemberTypeService( $c->get( 'cache' ) ) );
		$container->bind( 'rest_router', fn() => new Router() );
		$container->bind( 'template_loader', fn() => new TemplateLoader() );
		$container->bind( 'assets', fn() => new AssetService() );
		$container->bind( 'asset_isolation', fn() => new AssetIsolation() );
		$container->bind( 'admin_settings', fn() => new Settings() );
		$container->bind( 'admin_members', fn() => new Members() );
		$container->bind( 'admin_spaces', fn() => new Spaces() );
		$container->bind( 'admin_nav', fn() => new NavManager() );
		$container->bind( 'admin_email_editor', fn() => new EmailEditor() );
		$container->bind( 'shortcodes', fn() => new ShortcodeService() );
		$container->bind( 'widgets', fn() => new WidgetService() );
		$container->bind( 'pwa', fn() => new PwaService() );
		$container->bind( 'webhooks', fn() => new OutboundWebhookService() );

		// Feature registry — site-owner controls which Layer 2 features are
		// active. Mandatory tier is always on; default_on can be disabled;
		// opt_in must be enabled. See docs/specs/MODULAR-ARCHITECTURE.md.
		$container->bind( 'features', fn() => new FeatureRegistry() );
		$features = $container->get( 'features' );

		// Sidebar widget feature — Service + Cache pair. Bound only when
		// the registry says enabled (default_on tier; owner can disable in
		// Settings → Features).
		if ( $features->is_enabled( 'sidebar' ) ) {
			$container->bind( 'sidebar_cache', fn() => new \BuddyNext\Sidebar\WidgetCache() );
			$container->bind(
				'sidebar_widgets',
				fn( $c ) => new \BuddyNext\Sidebar\WidgetService( $c->get( 'sidebar_cache' ) )
			);
		}
		$container->bind( 'verification', fn() => new VerificationService() );
		$container->bind( 'onboarding', fn() => new \BuddyNext\Onboarding\OnboardingService() );
		$container->bind( 'invite', fn() => new \BuddyNext\Onboarding\InviteService() );
		$container->bind( 'setup_wizard', fn() => new \BuddyNext\Onboarding\SetupWizard() );
		$container->bind( 'realtime', fn() => TransportFactory::current() );

		/**
		 * Fires after all BuddyNext core services are bound, before any are
		 * resolved. Rebind a key here to REPLACE a core service with your own
		 * implementation (the container resolves lazily, so a rebind at this
		 * point wins). Hook early (low priority) to win over later listeners.
		 *
		 * @param Container $container The service container.
		 */
		do_action( 'buddynext_services_registered', $container );

		// Abilities must be registered at plugins_loaded:15 so they are
		// available before rest_api_init and admin_menu fire.
		$container->get( 'abilities' )->register();
	}

	// ── White-label helpers ───────────────────────────────────────────────────

	/**
	 * Return the filterable brand name for this plugin.
	 *
	 * Pro white-label builds hook buddynext_brand_name to substitute the
	 * operator's own product name throughout the UI without forking templates.
	 * Free always returns 'BuddyNext'.
	 *
	 * Note: This helper exposes the seam only — the current codebase does NOT
	 * automatically replace every hardcoded 'BuddyNext' string in templates.
	 * A future Pro feature audit will sweep templates and call Plugin::brand_name()
	 * where the string is user-visible.
	 *
	 * @since 1.0.0
	 *
	 * @return string Brand name. Default 'BuddyNext'.
	 */
	public static function brand_name(): string {
		/**
		 * Filter the plugin brand name shown in the community UI.
		 *
		 * @since 1.0.0
		 *
		 * @param string $name Default brand name. Default 'BuddyNext'.
		 */
		return (string) apply_filters( 'buddynext_brand_name', 'BuddyNext' );
	}

	/**
	 * Return the filterable brand logo URL for this plugin, or null when unset.
	 *
	 * Pro white-label builds hook buddynext_brand_logo_url to supply a custom
	 * logo image URL. Free returns null (no custom logo — templates fall back
	 * to text or the default SVG icon).
	 *
	 * @since 1.0.0
	 *
	 * @return string|null Absolute URL to the logo image, or null if not configured.
	 */
	public static function brand_logo_url(): ?string {
		/**
		 * Filter the plugin brand logo URL shown in the community UI.
		 *
		 * Return an absolute https:// URL pointing to the logo image (PNG or SVG
		 * recommended). Return null to use the default text/icon fallback.
		 *
		 * @since 1.0.0
		 *
		 * @param string|null $url Logo image URL or null. Default null.
		 */
		$url = apply_filters( 'buddynext_brand_logo_url', null );

		return ( null !== $url && '' !== $url ) ? (string) $url : null;
	}
}
