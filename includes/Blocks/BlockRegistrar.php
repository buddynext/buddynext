<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Gutenberg block and pattern registration.
 *
 * Registers all 18 free-tier BuddyNext blocks (server-rendered, dynamic)
 * and the 4 pre-built block patterns.
 *
 * Each block lives in blocks/bn-{slug}/block.json. Render callbacks are
 * defined inline as closures — no build step is required.
 *
 * @package BuddyNext\Blocks
 */

declare( strict_types=1 );

namespace BuddyNext\Blocks;

/**
 * Registers BuddyNext blocks and block patterns.
 */
class BlockRegistrar {

	/**
	 * Root path of the blocks/ directory.
	 *
	 * @var string
	 */
	private string $blocks_dir;

	/**
	 * Constructor.
	 *
	 * @param string $blocks_dir Absolute path to the blocks/ directory.
	 *                           Defaults to the plugin's blocks/ folder.
	 */
	public function __construct( string $blocks_dir = '' ) {
		$this->blocks_dir = '' !== $blocks_dir
			? $blocks_dir
			: dirname( __DIR__, 2 ) . '/blocks';
	}

	/**
	 * Attach hooks.
	 *
	 * Called from Plugin::init().
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_action( 'init', array( $this, 'register_patterns' ) );
		add_action( 'init', array( $this, 'register_block_category' ) );
		add_filter( 'block_categories_all', array( $this, 'add_block_category' ) );
	}

	/**
	 * Register the buddynext block category.
	 */
	public function register_block_category(): void {
		// Category registered via filter — nothing to do here.
	}

	/**
	 * Add the BuddyNext category to the block editor inserter.
	 *
	 * @param array[] $categories Existing block categories.
	 * @return array[]
	 */
	public function add_block_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'buddynext',
				'title' => __( 'BuddyNext', 'buddynext' ),
				'icon'  => null,
			)
		);
		return $categories;
	}

	/**
	 * Register all 18 dynamic blocks.
	 *
	 * The shared editor script (blocks.js) uses `wp.serverSideRender` to render
	 * live SSR previews inside the block editor. That component ships as the
	 * `wp-server-side-render` script handle. WordPress does not auto-add it as
	 * a dependency when the handle is declared via `editorScript` in block.json,
	 * so we pre-register the handle here with the correct dependency list before
	 * any block type is registered.  If the handle was already registered by an
	 * earlier code path we skip re-registration to avoid overwriting its URL.
	 */
	public function register_blocks(): void {
		$editor_script_src = dirname( __DIR__, 2 ) . '/assets/js/blocks.js';
		$editor_script_url = plugins_url( 'assets/js/blocks.js', dirname( __DIR__, 2 ) . '/buddynext.php' );

		if ( ! wp_script_is( 'buddynext-blocks-editor', 'registered' ) ) {
			wp_register_script(
				'buddynext-blocks-editor',
				$editor_script_url,
				array(
					'wp-blocks',
					'wp-element',
					'wp-block-editor',
					'wp-components',
					'wp-server-side-render',
				),
				BUDDYNEXT_VERSION,
				true
			);
		}

		$blocks = array(
			// Social / Feed.
			'bn-activity-feed'          => array( $this, 'render_activity_feed' ),
			'bn-post-composer'          => array( $this, 'render_post_composer' ),
			'bn-trending-hashtags'      => array( $this, 'render_trending_hashtags' ),
			// People.
			'bn-member-directory'       => array( $this, 'render_member_directory' ),
			'bn-member-card'            => array( $this, 'render_member_card' ),
			'bn-follow-button'          => array( $this, 'render_follow_button' ),
			'bn-connection-button'      => array( $this, 'render_connection_button' ),
			// Spaces.
			'bn-space-directory'        => array( $this, 'render_space_directory' ),
			'bn-space-card'             => array( $this, 'render_space_card' ),
			'bn-my-spaces'              => array( $this, 'render_my_spaces' ),
			// Profile.
			'bn-profile-header'         => array( $this, 'render_profile_header' ),
			'bn-profile-fields'         => array( $this, 'render_profile_fields' ),
			'bn-profile-completion-bar' => array( $this, 'render_profile_completion_bar' ),
			// Utility.
			'bn-registration-form'      => array( $this, 'render_registration_form' ),
			'bn-login-form'             => array( $this, 'render_login_form' ),
			'bn-notification-bell'      => array( $this, 'render_notification_bell' ),
			'bn-header-user-menu'       => array( $this, 'render_header_user_menu' ),
			'bn-search-bar'             => array( $this, 'render_search_bar' ),
		);

		foreach ( $blocks as $dir => $callback ) {
			$block_json = $this->blocks_dir . '/' . $dir . '/block.json';
			if ( ! file_exists( $block_json ) ) {
				continue;
			}
			register_block_type(
				$block_json,
				array( 'render_callback' => $callback )
			);
		}
	}

	/**
	 * Register the 4 pre-built page layout patterns.
	 */
	public function register_patterns(): void {
		register_block_pattern(
			'buddynext/community-home',
			array(
				'title'       => __( 'Community Home', 'buddynext' ),
				'description' => __( 'Activity feed with space directory sidebar.', 'buddynext' ),
				'categories'  => array( 'buddynext' ),
				'content'     => $this->pattern_community_home(),
			)
		);

		register_block_pattern(
			'buddynext/member-profile',
			array(
				'title'       => __( 'Member Profile', 'buddynext' ),
				'description' => __( 'Full member profile layout.', 'buddynext' ),
				'categories'  => array( 'buddynext' ),
				'content'     => $this->pattern_member_profile(),
			)
		);

		register_block_pattern(
			'buddynext/spaces-directory',
			array(
				'title'       => __( 'Spaces Directory', 'buddynext' ),
				'description' => __( 'Filterable spaces directory page layout.', 'buddynext' ),
				'categories'  => array( 'buddynext' ),
				'content'     => $this->pattern_spaces_directory(),
			)
		);

		register_block_pattern(
			'buddynext/member-directory',
			array(
				'title'       => __( 'Member Directory', 'buddynext' ),
				'description' => __( 'Full member directory page layout.', 'buddynext' ),
				'categories'  => array( 'buddynext' ),
				'content'     => $this->pattern_member_directory(),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Render callbacks — Social / Feed
	// -------------------------------------------------------------------------

	/**
	 * Render the Activity Feed block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_activity_feed( array $attributes ): string {
		$scope    = sanitize_key( $attributes['scope'] ?? 'home' );
		$per_page = (int) ( $attributes['perPage'] ?? 20 );

		ob_start();
		buddynext_get_template(
			'blocks/activity-feed.php',
			compact( 'scope', 'per_page' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Post Composer block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_post_composer( array $attributes ): string {
		$placeholder = sanitize_text_field( $attributes['placeholder'] ?? '' );

		ob_start();
		buddynext_get_template(
			'blocks/post-composer.php',
			compact( 'placeholder' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Trending Hashtags block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_trending_hashtags( array $attributes ): string {
		$count   = (int) ( $attributes['count'] ?? 10 );
		$display = sanitize_key( $attributes['display'] ?? 'list' );

		ob_start();
		buddynext_get_template(
			'blocks/trending-hashtags.php',
			compact( 'count', 'display' )
		);
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Render callbacks — People
	// -------------------------------------------------------------------------

	/**
	 * Render the Member Directory block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_member_directory( array $attributes ): string {
		$per_page = (int) ( $attributes['perPage'] ?? 24 );
		$layout   = sanitize_key( $attributes['layout'] ?? 'grid' );

		ob_start();
		buddynext_get_template(
			'blocks/member-directory.php',
			compact( 'per_page', 'layout' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Member Card block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_member_card( array $attributes ): string {
		$user_id = (int) ( $attributes['userId'] ?? 0 );

		ob_start();
		buddynext_get_template( 'blocks/member-card.php', compact( 'user_id' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Render the Follow Button block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_follow_button( array $attributes ): string {
		$user_id = (int) ( $attributes['userId'] ?? 0 );

		ob_start();
		buddynext_get_template( 'blocks/follow-button.php', compact( 'user_id' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Render the Connection Button block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_connection_button( array $attributes ): string {
		$user_id = (int) ( $attributes['userId'] ?? 0 );

		ob_start();
		buddynext_get_template( 'blocks/connection-button.php', compact( 'user_id' ) );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Render callbacks — Spaces
	// -------------------------------------------------------------------------

	/**
	 * Render the Space Directory block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_space_directory( array $attributes ): string {
		$per_page = (int) ( $attributes['perPage'] ?? 12 );
		$layout   = sanitize_key( $attributes['layout'] ?? 'grid' );

		ob_start();
		buddynext_get_template(
			'blocks/space-directory.php',
			compact( 'per_page', 'layout' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Space Card block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_space_card( array $attributes ): string {
		$space_id = (int) ( $attributes['spaceId'] ?? 0 );

		ob_start();
		buddynext_get_template( 'blocks/space-card.php', compact( 'space_id' ) );
		return (string) ob_get_clean();
	}

	/**
	 * Render the My Spaces block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_my_spaces( array $attributes ): string {
		$limit = (int) ( $attributes['limit'] ?? 10 );

		ob_start();
		buddynext_get_template( 'blocks/my-spaces.php', compact( 'limit' ) );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Render callbacks — Profile
	// -------------------------------------------------------------------------

	/**
	 * Render the Profile Header block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_profile_header( array $attributes ): string {
		$user_id      = (int) ( $attributes['userId'] ?? 0 );
		$show_stats   = (bool) ( $attributes['showStats'] ?? true );
		$show_actions = (bool) ( $attributes['showActions'] ?? true );

		ob_start();
		buddynext_get_template(
			'blocks/profile-header.php',
			compact( 'user_id', 'show_stats', 'show_actions' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Profile Fields block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_profile_fields( array $attributes ): string {
		$user_id = (int) ( $attributes['userId'] ?? 0 );
		$group   = sanitize_key( $attributes['group'] ?? '' );

		ob_start();
		buddynext_get_template(
			'blocks/profile-fields.php',
			compact( 'user_id', 'group' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Profile Completion Bar block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_profile_completion_bar( array $attributes ): string {
		$user_id = (int) ( $attributes['userId'] ?? 0 );

		ob_start();
		buddynext_get_template(
			'blocks/profile-completion-bar.php',
			compact( 'user_id' )
		);
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Render callbacks — Utility
	// -------------------------------------------------------------------------

	/**
	 * Render the Registration Form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_registration_form( array $attributes ): string {
		$redirect_url = esc_url_raw( $attributes['redirectUrl'] ?? '' );

		ob_start();
		buddynext_get_template(
			'blocks/registration-form.php',
			compact( 'redirect_url' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Login Form block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_login_form( array $attributes ): string {
		$redirect_url = esc_url_raw( $attributes['redirectUrl'] ?? '' );

		ob_start();
		buddynext_get_template(
			'blocks/login-form.php',
			compact( 'redirect_url' )
		);
		return (string) ob_get_clean();
	}

	/**
	 * Render the Notification Bell block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_notification_bell( array $attributes ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by register_block_type signature.
		ob_start();
		buddynext_get_template( 'blocks/notification-bell.php', array() );
		return (string) ob_get_clean();
	}

	/**
	 * Render the Header User Menu block (bell + messages + avatar dropdown).
	 *
	 * Logged-in header chrome usable as a block-based widget in any theme's
	 * header. Renders nothing for logged-out visitors.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_header_user_menu( array $attributes ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- required by register_block_type signature.
		ob_start();
		buddynext_get_template( 'blocks/header-user-menu.php', array() );
		return (string) ob_get_clean();
	}

	/**
	 * Render the Search Bar block.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_search_bar( array $attributes ): string {
		$placeholder = sanitize_text_field( $attributes['placeholder'] ?? '' );

		ob_start();
		buddynext_get_template( 'blocks/search-bar.php', compact( 'placeholder' ) );
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Pattern content helpers
	// -------------------------------------------------------------------------

	/**
	 * Pattern: Community Home page.
	 *
	 * @return string
	 */
	private function pattern_community_home(): string {
		return '<!-- wp:columns {"className":"bn-layout-community-home"} -->'
			. '<div class="wp-block-columns bn-layout-community-home">'
			. '<!-- wp:column {"width":"66.66%"} -->'
			. '<div class="wp-block-column" style="flex-basis:66.66%">'
			. '<!-- wp:buddynext/post-composer /-->'
			. '<!-- wp:buddynext/activity-feed {"scope":"home"} /-->'
			. '</div>'
			. '<!-- /wp:column -->'
			. '<!-- wp:column {"width":"33.33%"} -->'
			. '<div class="wp-block-column" style="flex-basis:33.33%">'
			. '<!-- wp:buddynext/space-directory {"perPage":6,"layout":"list"} /-->'
			. '<!-- wp:buddynext/trending-hashtags {"count":5} /-->'
			. '</div>'
			. '<!-- /wp:column -->'
			. '</div>'
			. '<!-- /wp:columns -->';
	}

	/**
	 * Pattern: Member Profile page.
	 *
	 * @return string
	 */
	private function pattern_member_profile(): string {
		// Wrap the body in a core wp:group — there is no core "wp:tabs" block, so
		// the old markup tripped an invalid-block warning and rendered raw.
		return '<!-- wp:buddynext/profile-header /-->'
			. '<!-- wp:group {"className":"bn-member-profile-body"} --><div class="wp-block-group bn-member-profile-body">'
			. '<!-- wp:buddynext/activity-feed {"scope":"profile"} /-->'
			. '<!-- wp:buddynext/profile-fields /-->'
			. '<!-- wp:buddynext/profile-completion-bar /-->'
			. '</div><!-- /wp:group -->';
	}

	/**
	 * Pattern: Spaces Directory page.
	 *
	 * @return string
	 */
	private function pattern_spaces_directory(): string {
		return '<!-- wp:buddynext/search-bar {"placeholder":"Search spaces\u2026"} /-->'
			. '<!-- wp:buddynext/space-directory {"perPage":12,"layout":"grid"} /-->';
	}

	/**
	 * Pattern: Member Directory page.
	 *
	 * @return string
	 */
	private function pattern_member_directory(): string {
		return '<!-- wp:buddynext/search-bar {"placeholder":"Search members\u2026"} /-->'
			. '<!-- wp:buddynext/member-directory {"perPage":24,"layout":"grid"} /-->';
	}
}
