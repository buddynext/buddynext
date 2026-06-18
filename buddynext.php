<?php
/**
 * Plugin Name: BuddyNext
 * Plugin URI:  https://wbcomdesigns.com/buddynext
 * Description: The social layer for WordPress.
 * Version:     0.6.0-beta1
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com
 * Text Domain: buddynext
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.2
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

define( 'BUDDYNEXT_VERSION', '0.6.0-beta1' );
define( 'BUDDYNEXT_FILE', __FILE__ );
define( 'BUDDYNEXT_DIR', plugin_dir_path( __FILE__ ) );
define( 'BUDDYNEXT_URL', plugin_dir_url( __FILE__ ) );
define( 'BUDDYNEXT_BASENAME', plugin_basename( __FILE__ ) );

require_once BUDDYNEXT_DIR . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( \BuddyNext\Core\Installer::class, 'run' ) );
register_activation_hook(
	__FILE__,
	static function (): void {
		set_transient( 'buddynext_do_activation_redirect', '1', 30 );
	}
);
register_deactivation_hook(
	__FILE__,
	static function (): void {
		\BuddyNext\Core\Installer::remove_mu_plugin();
	}
);

add_action( 'plugins_loaded', array( \BuddyNext\Core\Plugin::class, 'init' ), 15 );

// Load translations on init (WP 6.7+ wants textdomains loaded at init or later).
// The Domain Path header points at /languages; .mo files drop in there per locale.
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'buddynext', false, dirname( BUDDYNEXT_BASENAME ) . '/languages' );
	}
);

// ---------------------------------------------------------------------------
// EDD Software Licensing SDK — automatic updates for free and Pro.
//
// The SDK is vendored at libs/edd-sl-sdk and is the single source of truth
// for the whole product family: BuddyNext Pro registers its own product on
// the same registry hook and requires this same file (require_once makes the
// double load safe). The free product ships with a preset, unlimited-
// activation key so updates work with zero customer setup. License state
// never gates functionality — it only authorises update downloads.
// ---------------------------------------------------------------------------

add_action(
	'edd_sl_sdk_registry',
	static function ( $registry ): void {
		$registry->register(
			array(
				'id'      => 'buddynext',
				'url'     => 'https://wbcomdesigns.com',
				'item_id' => 1664401,
				'version' => BUDDYNEXT_VERSION,
				'file'    => BUDDYNEXT_FILE,
				'license' => 'buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55',
			)
		);
	}
);

// Action Scheduler — bundled so async background work (search reindex, space
// fan-out, async notifications) runs on a standalone install with nothing else
// active. Self-arbitrates: if another active plugin ships a newer copy, that one
// wins. Must load no later than plugins_loaded, so require it here at plugin load.
if ( file_exists( BUDDYNEXT_DIR . 'libs/action-scheduler/action-scheduler.php' ) ) {
	require_once BUDDYNEXT_DIR . 'libs/action-scheduler/action-scheduler.php';
}

if ( file_exists( BUDDYNEXT_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once BUDDYNEXT_DIR . 'libs/edd-sl-sdk/edd-sl-sdk.php';
}

// Apply pending DB schema upgrades on a plain plugin update (no deactivate/
// reactivate needed). Cheap no-op once the stored schema revision matches.
add_action( 'admin_init', array( \BuddyNext\Core\Installer::class, 'maybe_upgrade' ) );

// Activate the preset key against the store once per site so update
// downloads are authorised. Admin-only; retries on the next admin load
// until the store confirms the activation.
add_action(
	'admin_init',
	static function (): void {
		$preset_key = 'buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55';
		$option     = 'buddynext_license_key';
		$activated  = 'buddynext_preset_activated';

		// Already activated for this domain — skip.
		if ( get_option( $activated ) ) {
			return;
		}

		// Store the key so the SDK can find it.
		update_option( $option, $preset_key, false );

		// Activate with the EDD store.
		$response = wp_remote_post(
			'https://wbcomdesigns.com',
			array(
				'timeout' => 15,
				'body'    => array(
					'edd_action' => 'activate_license',
					'license'    => $preset_key,
					'item_id'    => 1664401,
					'url'        => home_url(),
				),
			)
		);

		if ( ! is_wp_error( $response ) ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 'valid' === ( $body['license'] ?? '' ) ) {
				update_option( $activated, 1, false );
				update_option(
					$option . '_allow_tracking',
					array(
						'allowed'   => true,
						'timestamp' => time(),
					),
					false
				);
			}
		}
	}
);

/**
 * Check whether a user holds a BuddyNext capability.
 *
 * Acts as the single entry point for every permission check in the plugin.
 * Prefer this over direct capability checks so that all gates flow through
 * the 4-layer model in PermissionService.
 *
 * @param int    $user_id    WordPress user ID.
 * @param string $capability Dot-namespaced capability, e.g. 'buddynext-feed/create-post'.
 * @param array  $context    Optional key/value context, e.g. ['space_id' => 42].
 * @return bool
 */
function buddynext_can( int $user_id, string $capability, array $context = array() ): bool {
	return \BuddyNext\Core\Container::instance()
		->get( 'permissions' )
		->can( $user_id, $capability, $context );
}

/**
 * Spend credits from a user's BuddyNext credit balance.
 *
 * Thin wrapper around RoleService::spend_credits() so callers throughout the
 * codebase don't need to resolve the service container themselves.
 *
 * Returns false without deducting when the balance is insufficient.
 *
 * @param int    $user_id WordPress user ID.
 * @param int    $amount  Credits to deduct.
 * @param string $reason  Short description for audit (e.g. 'create-space').
 * @return bool
 */
function buddynext_spend_credits( int $user_id, int $amount, string $reason ): bool {
	return \BuddyNext\Core\Container::instance()
		->get( 'roles' )
		->spend_credits( $user_id, $amount, $reason );
}

/**
 * Retrieve a service from the BuddyNext container.
 *
 * @param string $key Service identifier as registered in Plugin::register_services().
 * @return mixed
 */
function buddynext_service( string $key ): mixed {
	return \BuddyNext\Core\Container::instance()->get( $key );
}

/**
 * Whether a community feature toggle is enabled.
 *
 * Single, defensive accessor for the FeatureRegistry so enforcement points
 * (controllers, services, templates) don't each repeat the
 * is_object()/method_exists()/is_enabled() guard. Returns $fallback (enabled by
 * default) when the container isn't booted yet or the registry is unavailable,
 * matching the fail-open behaviour of the inline checks it replaces.
 *
 * @param string $slug     Feature slug (e.g. 'announcements', 'hashtags', 'verification').
 * @param bool   $fallback Result when the registry can't be resolved. Default true.
 * @return bool
 */
function buddynext_feature_enabled( string $slug, bool $fallback = true ): bool {
	if ( ! did_action( 'buddynext_loaded' ) ) {
		return $fallback;
	}
	try {
		$features = buddynext_service( 'features' );
	} catch ( \Throwable $e ) {
		return $fallback;
	}
	if ( ! is_object( $features ) || ! method_exists( $features, 'is_enabled' ) ) {
		return $fallback;
	}
	return (bool) $features->is_enabled( $slug );
}

/**
 * Register a profile field programmatically (no database write).
 *
 * The field is injected into the live group/field tree via the
 * `buddynext_profile_fields` filter, so it appears in the profile editor, on
 * the profile, and — when `show_on_register` is true — on the registration
 * form, without an admin creating it by hand. Call this on `buddynext_loaded`
 * or `init`.
 *
 * Because a programmatic field has no `bn_profile_fields` row, its submitted
 * value is stored to usermeta (`bn_field_{field_key}`) on save rather than the
 * `bn_profile_values` table — addons needing custom storage can hook
 * `buddynext_registration_fields_saved`.
 *
 * @param array $args {
 *     Field definition.
 *
 *     @type string $field_key        Required. Unique kebab/snake key.
 *     @type string $label            Required. Human label.
 *     @type string $type             Field type slug. Default 'text'.
 *     @type string $group_key        Group to attach to (created if absent). Default 'details'.
 *     @type string $group_label      Label when the group is created. Optional.
 *     @type bool   $is_required      Whether the field is required. Default false.
 *     @type bool   $show_on_register Surface on the registration form. Default false.
 *     @type string $visibility       public|followers|connections|private. Default 'public'.
 *     @type array  $options          Choices for select/radio/checkbox types. Optional.
 * }
 * @return void
 */
function buddynext_register_profile_field( array $args ): void {
	static $registry = array();
	static $hooked   = false;

	$registry[] = $args;

	if ( $hooked ) {
		return;
	}
	$hooked = true;

	add_filter(
		'buddynext_profile_fields',
		static function ( array $groups ) use ( &$registry ): array {
			foreach ( $registry as $field ) {
				$group_key = sanitize_key( (string) ( $field['group_key'] ?? 'details' ) );

				$attached = false;
				foreach ( $groups as $gi => $group ) {
					if ( ( $group['group_key'] ?? '' ) === $group_key ) {
						$groups[ $gi ]['fields'][] = $field;
						$attached                  = true;
						break;
					}
				}

				if ( ! $attached ) {
					$groups[] = array(
						'id'         => 0,
						'group_key'  => $group_key,
						'label'      => (string) ( $field['group_label'] ?? ucwords( str_replace( array( '_', '-' ), ' ', $group_key ) ) ),
						'type'       => 'flat',
						'visibility' => 'public',
						'is_system'  => false,
						'sort_order' => 99,
						'fields'     => array( $field ),
					);
				}
			}

			return $groups;
		}
	);
}

/**
 * Effective default registration mode when the owner has not set one.
 *
 * Per owner decision, BuddyNext does not impose its own opinion on a fresh
 * install — it follows WordPress's "Anyone can register" setting: open when
 * registration is allowed, closed when it is not. Read sites pass this as the
 * fallback to get_option( 'buddynext_reg_mode', ... ) so the unset state mirrors
 * WordPress instead of silently forcing "open".
 *
 * @return string 'open' or 'closed'.
 */
function buddynext_default_reg_mode(): string {
	return get_option( 'users_can_register' ) ? 'open' : 'closed';
}

/**
 * Product-level default values for the login / sign-up branding panel.
 *
 * Single source of truth so the admin Settings fields and the front-end auth
 * surfaces always agree — the same defaults are wired to both, which is what
 * makes a fresh install feel plug-and-play (no blank fields, no empty panel).
 * Dynamic defaults (heading, tagline) follow the site's own identity.
 *
 * @return array<string,string> Option name => default value.
 */
function buddynext_auth_panel_defaults(): array {
	$tagline = wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES );

	return array(
		'buddynext_auth_panel_heading' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		'buddynext_auth_panel_tagline' => '' !== trim( $tagline ) ? $tagline : __( 'Next-generation community for WordPress.', 'buddynext' ),
		'buddynext_auth_panel_quote'   => __( 'Join the conversation, build real connections, and grow in a community that is truly yours.', 'buddynext' ),
		'buddynext_auth_panel_image'   => BUDDYNEXT_URL . 'assets/images/auth-cover.svg',
	);
}

/**
 * The effective value for a login / sign-up panel field: the admin's saved value
 * when set, otherwise the product-level default. Used by both the Settings UI
 * and the auth templates so neither is ever empty.
 *
 * @param string $key Option name (a key of buddynext_auth_panel_defaults()).
 * @return string
 */
function buddynext_auth_panel_value( string $key ): string {
	$value = (string) get_option( $key, '' );
	if ( '' !== trim( $value ) ) {
		return $value;
	}
	$defaults = buddynext_auth_panel_defaults();
	return (string) ( $defaults[ $key ] ?? '' );
}

/**
 * Render a BuddyNext template, with theme-override support.
 *
 * Delegates to the TemplateLoader service which checks child-theme, parent-theme,
 * and plugin templates/ directory in order.
 *
 * When called during a Gutenberg server-side-render (SSR) preview before the
 * BuddyNext DI container has finished bootstrapping (i.e. before buddynext_loaded
 * has fired), the function returns a neutral placeholder instead of throwing.
 * This prevents React Error #130 in the block editor caused by an exception
 * propagating through the REST block-renderer endpoint.
 *
 * @param string               $relative Relative template path (e.g. 'blocks/search-bar.php').
 * @param array<string, mixed> $vars     Variables to extract into the template scope.
 * @return void
 */
function buddynext_get_template( string $relative, array $vars = array() ): void {
	if ( ! did_action( 'buddynext_loaded' ) ) {
		echo '<p class="buddynext-editor-loading">' . esc_html__( 'BuddyNext loading…', 'buddynext' ) . '</p>';
		return;
	}
	buddynext_service( 'template_loader' )->render( $relative, $vars );
}

/**
 * Resolve the cover image URL to show for a user.
 *
 * Priority:
 *   1. The user's own uploaded cover (buddynext_cover_url usermeta).
 *   2. The site-wide default cover (bn_default_cover_url option, set in
 *      BuddyNext > Members > Avatar & Cover).
 *   3. Empty string — callers fall back to the tonal gradient.
 *
 * @param int $user_id User ID.
 * @return string Cover URL or '' when neither a custom nor a default cover exists.
 */
function buddynext_user_cover_url( int $user_id ): string {
	$cover = (string) get_user_meta( $user_id, 'buddynext_cover_url', true );
	if ( '' !== $cover ) {
		return $cover;
	}
	return (string) get_option( 'bn_default_cover_url', '' );
}

/**
 * Return the canonical URL for a single space by its slug.
 *
 * Thin procedural wrapper around PageRouter::spaces_url() so templates do not
 * need a direct dependency on the PageRouter class.
 *
 * @param string $slug The space's slug (post_name) as stored in bn_spaces.slug.
 * @return string Absolute URL.
 */
function buddynext_space_url( string $slug ): string {
	if ( '' === $slug ) {
		return \BuddyNext\Core\PageRouter::spaces_url();
	}

	return \BuddyNext\Core\PageRouter::spaces_url() . rawurlencode( sanitize_title( $slug ) ) . '/';
}

/**
 * Return the settings page URL for a single space.
 *
 * @param string $slug The space's slug as stored in bn_spaces.slug.
 * @return string Absolute URL.
 */
function buddynext_space_settings_url( string $slug ): string {
	return buddynext_space_url( $slug ) . 'settings/';
}

/**
 * Return the moderation page URL for a single space.
 *
 * @param string $slug The space's slug as stored in bn_spaces.slug.
 * @return string Absolute URL.
 */
function buddynext_space_moderation_url( string $slug ): string {
	return buddynext_space_url( $slug ) . 'moderation/';
}

/**
 * Return the Community Admin Panel URL.
 *
 * @return string Absolute URL.
 */
function buddynext_community_admin_url(): string {
	return \BuddyNext\Core\PageRouter::community_admin_url();
}

/**
 * Return the URL for the "create a space" flow.
 *
 * Routes to the spaces hub with the ?bn_action=create query argument which
 * the directory template and PageRouter use to open the creation modal.
 *
 * @return string Absolute URL.
 */
function buddynext_create_space_url(): string {
	return add_query_arg( 'bn_action', 'create', \BuddyNext\Core\PageRouter::spaces_url() );
}

/**
 * Return a deterministic avatar background colour for a user ID.
 *
 * Used as a fallback behind avatar images so the placeholder is visually
 * distinct per user while the image loads or when no Gravatar exists.
 *
 * @param int $user_id WordPress user ID.
 * @return string Hex colour string, e.g. '#0073aa'.
 */
function buddynext_avatar_colour( int $user_id ): string {
	static $palette = array(
		'#0073aa',
		'#059669',
		'#7c3aed',
		'#d97706',
		'#dc2626',
		'#0891b2',
		'#c2410c',
		'#4f46e5',
	);
	return $palette[ abs( $user_id ) % count( $palette ) ];
}

/**
 * Echo a BuddyNext SVG icon inline.
 *
 * Reads the named SVG from assets/icons/<name>.svg, sanitizes it via
 * wp_kses(), and echoes the result. Safe for direct use in templates.
 *
 * @param string $name      Icon slug, e.g. 'user', 'bell', 'graduation-cap'.
 * @param string $css_class Optional CSS class(es) to add to the <svg> element.
 * @return void
 */
function buddynext_icon( string $name, string $css_class = '' ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is pre-sanitized via wp_kses() inside IconService::render().
	echo \BuddyNext\Core\IconService::render( $name, $css_class );
}

/**
 * Return a BuddyNext SVG icon as a string.
 *
 * Same as buddynext_icon() but returns the markup instead of echoing it.
 * Useful when the icon needs to be passed as a variable or concatenated.
 *
 * @param string $name      Icon slug, e.g. 'user', 'bell', 'graduation-cap'.
 * @param string $css_class Optional CSS class(es) to add to the <svg> element.
 * @return string Sanitized SVG markup, safe to echo.
 */
function buddynext_get_icon( string $name, string $css_class = '' ): string {
	return \BuddyNext\Core\IconService::render( $name, $css_class );
}

/**
 * Whether the site owner has the BuddyNext community navigation enabled.
 *
 * Controlled by the "Show community navigation" setting
 * (buddynext_enable_community_nav, default on). When off, BuddyNext stops
 * rendering its own navigation chrome (hub-shell left rail, mobile tab bar,
 * bridge-added nav items) and the MenuRenderer also stops injecting BN items
 * into the host theme menus.
 *
 * @return bool True when community navigation should render.
 */
function buddynext_community_nav_enabled(): bool {
	return (bool) get_option( 'buddynext_enable_community_nav', true );
}

/**
 * Whether the desktop left rail should render.
 *
 * Sub-toggle of the community-nav master: the rail shows only when the master
 * is on AND the rail sub-toggle is on, so an owner can keep the mobile bottom
 * bar but hide the desktop rail (e.g. to rely on theme menus on desktop).
 *
 * @return bool
 */
function buddynext_community_rail_enabled(): bool {
	return buddynext_community_nav_enabled() && (bool) get_option( 'buddynext_enable_community_rail', true );
}

/**
 * Whether the mobile bottom tab bar should render.
 *
 * Sub-toggle of the community-nav master (see buddynext_community_rail_enabled).
 *
 * @return bool
 */
function buddynext_community_mobile_nav_enabled(): bool {
	return buddynext_community_nav_enabled() && (bool) get_option( 'buddynext_enable_community_mobile_nav', true );
}

/**
 * Echo a Microsoft Fluent reaction emoji inline.
 *
 * Reads the SVG from `assets/emoji/<slug>.svg` and emits an `<img>` tag
 * so the same emoji renders identically across every host platform
 * (vs native Unicode emoji which look different per OS). Use for
 * reaction chips and any "emoji-style" decorative imagery the v2
 * prototypes call for.
 *
 * @param string      $slug      Reaction slug — see `assets/emoji/README.md`.
 * @param string      $css_class Optional CSS class(es) appended to `bn-emoji`.
 * @param string|null $alt       Alt text. Default null → auto-derived
 *                               (`like` → `Like reaction`). Pass `''` for
 *                               a decorative image.
 * @return void
 */
function buddynext_emoji( string $slug, string $css_class = '', ?string $alt = null ): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- output is escaped inside IconService::render_emoji().
	echo \BuddyNext\Core\IconService::render_emoji( $slug, $css_class, $alt );
}

/**
 * Echo the BuddyNext notification bell (icon + server-rendered unread badge).
 *
 * Header chrome for any theme. Renders nothing for logged-out visitors. Use in
 * a classic theme's header where a notifications icon belongs.
 *
 * @return void
 */
function buddynext_header_notification_bell(): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from escaped pieces inside HeaderUserSection.
	echo \BuddyNext\Header\HeaderUserSection::notification_bell();
}

/**
 * Echo the BuddyNext messages icon → the member's messages inbox.
 *
 * Renders nothing when the messages feature is unavailable or the visitor is
 * logged out.
 *
 * @return void
 */
function buddynext_header_messages_bell(): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from escaped pieces inside HeaderUserSection.
	echo \BuddyNext\Header\HeaderUserSection::messages_link();
}

/**
 * Echo the BuddyNext avatar + profile dropdown (quick links + log out).
 *
 * The avatar links to the member's profile; a CSS-only dropdown exposes the
 * quick links and log out. Renders nothing for logged-out visitors.
 *
 * @return void
 */
function buddynext_header_user_menu(): void {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup is built from escaped pieces inside HeaderUserSection.
	echo \BuddyNext\Header\HeaderUserSection::user_menu();
}

/**
 * Return the full BuddyNext header user section (bell + messages + avatar menu).
 *
 * Used by the `[buddynext_user_menu]` shortcode and the header-user-menu block.
 *
 * @return string Safe HTML (empty for logged-out visitors).
 */
function buddynext_header_user_section(): string {
	return \BuddyNext\Header\HeaderUserSection::render();
}

add_shortcode(
	'buddynext_user_menu',
	static function (): string {
		return buddynext_header_user_section();
	}
);

/**
 * Return a Microsoft Fluent reaction emoji `<img>` tag as a string.
 *
 * Same as buddynext_emoji() but returns the markup instead of echoing it.
 *
 * @param string      $slug      Reaction slug.
 * @param string      $css_class Optional CSS class(es).
 * @param string|null $alt       Alt text. Default null → auto-derived.
 * @return string `<img>` markup, safe to echo. Empty string when asset is missing.
 */
function buddynext_get_emoji( string $slug, string $css_class = '', ?string $alt = null ): string {
	return \BuddyNext\Core\IconService::render_emoji( $slug, $css_class, $alt );
}

/**
 * Format post content: convert #hashtag and @mention patterns to clickable links.
 *
 * Hashtags link to the BuddyNext hashtag feed (/activity/hashtag/{slug}/).
 * Mentions link to the member profile (/members/{username}/).
 *
 * @param string $content Raw post text.
 * @return string HTML-safe content with linked tags and mentions.
 */
function buddynext_format_content( string $content ): string {
	// Encode only the three characters dangerous in HTML text content (<, >, &).
	// esc_html() also encodes apostrophes to &#039; which wp_kses() then
	// double-encodes (&→&amp;), causing the entity to display literally in the
	// browser. Single quotes are perfectly safe in HTML text nodes.
	$escaped = htmlspecialchars( $content, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );

	// Inline code first — anything inside backticks is treated literally and
	// is not subject to other markdown / mention / hashtag parsing below.
	// Use a placeholder pass to extract code runs, then restore them at the
	// end so subsequent regexes don't touch their contents.
	$code_segments = array();
	$escaped       = preg_replace_callback(
		'/`([^`\n]+)`/u',
		static function ( array $m ) use ( &$code_segments ): string {
			$idx                   = count( $code_segments );
			$code_segments[ $idx ] = '<code class="bn-code">' . $m[1] . '</code>';
			return "\x01CODE{$idx}\x01";
		},
		$escaped
	);

	// Markdown link: [label](https://url) — only matches valid http(s) URLs
	// to prevent javascript: vectors. The label allows arbitrary printable
	// characters except `]`.
	$escaped = preg_replace_callback(
		'/\[([^\]\n]+)\]\((https?:\/\/[^\s)]+)\)/u',
		static function ( array $m ): string {
			return '<a href="' . esc_url( $m[2] ) . '" class="bn-md-link" rel="noopener nofollow ugc">' . $m[1] . '</a>';
		},
		$escaped
	);

	// Bold: **text** — runs of 1+ non-asterisk chars between double asterisks.
	$escaped = preg_replace( '/\*\*([^*\n]+)\*\*/u', '<strong>$1</strong>', $escaped );

	// Italic: _text_ — underscores chosen over single asterisks so multiplier
	// math like 2*3*4 in chat doesn't accidentally render as italic.
	$escaped = preg_replace( '/(?<![\w])_([^_\n]+)_(?![\w])/u', '<em>$1</em>', $escaped );

	// Replace #hashtag with a link (word boundary; allow hyphens and underscores).
	// Single chokepoint for the Hashtags feature: when the owner turns it off, the
	// tag stays as plain text everywhere this formatter runs (feed posts +
	// comments) — no clickable hashtags leak into a community that disabled them.
	if ( buddynext_feature_enabled( 'hashtags' ) ) {
		$escaped = preg_replace_callback(
			'/#([a-zA-Z0-9_-]+)/u',
			static function ( array $m ): string {
				$slug = sanitize_title( $m[1] );
				$url  = home_url( '/activity/hashtag/' . $slug . '/' );
				return '<a href="' . esc_url( $url ) . '" class="bn-hashtag">#' . esc_html( $m[1] ) . '</a>';
			},
			$escaped
		);
	}

	// Replace @username with a link to the member profile.
	$escaped = preg_replace_callback(
		'/@([a-zA-Z0-9_-]+)/u',
		static function ( array $m ): string {
			$url = home_url( '/members/' . rawurlencode( $m[1] ) . '/' );
			return '<a href="' . esc_url( $url ) . '" class="bn-mention">@' . esc_html( $m[1] ) . '</a>';
		},
		$escaped
	);

	// Restore code placeholders. Done last so the <code> markup is opaque to
	// all other regexes above.
	if ( ! empty( $code_segments ) ) {
		$escaped = preg_replace_callback(
			'/\x01CODE(\d+)\x01/',
			static function ( array $m ) use ( $code_segments ): string {
				$idx = (int) $m[1];
				return $code_segments[ $idx ] ?? '';
			},
			$escaped
		);
	}

	return $escaped;
}

/**
 * Render a UTC datetime as a compact, localized "time ago" label.
 *
 * BuddyNext stores every user-facing timestamp in UTC (current_time('mysql', true)
 * on write). Relative durations are timezone-independent, so this anchors the
 * stored value to UTC and measures the gap to the current instant: the result is
 * identical on every server regardless of its PHP timezone. The site's WordPress
 * timezone selection governs only absolute dates — see buddynext_date_local().
 *
 * Canonical helper for every "X ago" surface (feed, comments, notifications,
 * spaces, profile, search); the previous per-template copies are removed.
 *
 * @param string $gmt_datetime A 'Y-m-d H:i:s' datetime stored in UTC.
 * @return string Escaped relative-time label (e.g. "3h ago"), or '' when empty/invalid.
 */
function buddynext_time_ago( string $gmt_datetime ): string {
	if ( '' === trim( $gmt_datetime ) ) {
		return '';
	}

	$timestamp = strtotime( $gmt_datetime . ' UTC' );
	if ( false === $timestamp ) {
		return '';
	}

	$diff = time() - $timestamp;
	if ( $diff < 0 ) {
		$diff = 0;
	}

	if ( $diff < MINUTE_IN_SECONDS ) {
		return esc_html__( 'just now', 'buddynext' );
	}

	if ( $diff < HOUR_IN_SECONDS ) {
		$mins = (int) round( $diff / MINUTE_IN_SECONDS );
		/* translators: %d: number of minutes. */
		return esc_html( sprintf( _n( '%dm ago', '%dm ago', $mins, 'buddynext' ), $mins ) );
	}

	if ( $diff < DAY_IN_SECONDS ) {
		$hours = (int) round( $diff / HOUR_IN_SECONDS );
		/* translators: %d: number of hours. */
		return esc_html( sprintf( _n( '%dh ago', '%dh ago', $hours, 'buddynext' ), $hours ) );
	}

	$days = (int) round( $diff / DAY_IN_SECONDS );
	/* translators: %d: number of days. */
	return esc_html( sprintf( _n( '%dd ago', '%dd ago', $days, 'buddynext' ), $days ) );
}

/**
 * Format a UTC datetime as an absolute date in the site's configured timezone.
 *
 * Honours the WordPress Settings > General timezone selection uniformly: the
 * stored value is UTC and get_date_from_gmt() converts it to site-local time
 * before formatting. Use this for calendar-style displays ("Joined June 2026");
 * use buddynext_time_ago() for "X ago" durations.
 *
 * @param string $gmt_datetime A 'Y-m-d H:i:s' datetime stored in UTC.
 * @param string $format       PHP date format. Defaults to the site date_format option.
 * @return string Escaped localized date string, or '' when empty/invalid.
 */
function buddynext_date_local( string $gmt_datetime, string $format = '' ): string {
	if ( '' === trim( $gmt_datetime ) ) {
		return '';
	}

	if ( '' === $format ) {
		$format = (string) get_option( 'date_format', 'F j, Y' );
	}

	return esc_html( get_date_from_gmt( $gmt_datetime, $format ) );
}

/**
 * Classify a post row into an explore discovery-card "kind".
 *
 * The Explore grid renders varied, compact card treatments instead of the
 * uniform interactive post card. This pure function maps a (hydrated or raw)
 * post row to one of the visual treatments so the SSR template and the REST
 * pagination endpoint stay byte-for-byte consistent — both classify through
 * here, never by ad-hoc inline checks.
 *
 * Returned kinds (all real-data driven — no fabricated card types):
 *   - 'post-poll'  : a poll post → recognizable poll card (click-through to vote).
 *   - 'post-media' : carries at least one media attachment → image card.
 *   - 'post-forum' : a discussion / forum post → tinted, taller thread card.
 *   - 'post-text'  : everything else → plain text card.
 *
 * @since 1.6.0
 *
 * @param array<string,mixed> $post A post row with at least type / content /
 *                                  media_ids.
 * @return string One of: post-poll, post-media, post-forum, post-text.
 */
function buddynext_explore_card_kind( array $post ): string {
	$type      = (string) ( $post['type'] ?? 'text' );
	$media_ids = $post['media_ids'] ?? null;

	// Poll wins before media so a poll never renders as a plain text/link teaser
	// (it stored no recognizable affordance before): polls read as a question +
	// "Vote" call-to-action card.
	if ( 'poll' === $type ) {
		return 'post-poll';
	}

	// Media wins next: anything with an attachment reads best as an image card.
	$has_media = false;
	if ( is_array( $media_ids ) ) {
		$has_media = ! empty( $media_ids );
	} elseif ( is_string( $media_ids ) ) {
		$trimmed   = trim( $media_ids );
		$has_media = '' !== $trimmed && '[]' !== $trimmed;
	}
	if ( $has_media || 'image' === $type || 'video' === $type ) {
		return 'post-media';
	}

	// Discussion / forum posts (e.g. the Jetonomy bridge publishes synced
	// threads as type 'discussion') get the tinted thread treatment.
	if ( 'discussion' === $type || 'forum_post' === $type || 'forum' === $type ) {
		return 'post-forum';
	}

	return 'post-text';
}
