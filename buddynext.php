<?php
/**
 * Plugin Name: BuddyNext
 * Plugin URI:  https://wbcomdesigns.com/buddynext
 * Description: The social layer for WordPress.
 * Version:     0.1.0
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com
 * Text Domain: buddynext
 * Domain Path: /languages
 * Requires at least: 6.9
 * Requires PHP: 8.1
 *
 * @package BuddyNext
 */

defined( 'ABSPATH' ) || exit;

define( 'BUDDYNEXT_VERSION', '0.1.0' );
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

	// Replace #hashtag with a link (word boundary; allow hyphens and underscores).
	$escaped = preg_replace_callback(
		'/#([a-zA-Z0-9_-]+)/u',
		static function ( array $m ): string {
			$slug = sanitize_title( $m[1] );
			$url  = home_url( '/activity/hashtag/' . $slug . '/' );
			return '<a href="' . esc_url( $url ) . '" class="bn-hashtag">#' . esc_html( $m[1] ) . '</a>';
		},
		$escaped
	);

	// Replace @username with a link to the member profile.
	$escaped = preg_replace_callback(
		'/@([a-zA-Z0-9_-]+)/u',
		static function ( array $m ): string {
			$url = home_url( '/members/' . rawurlencode( $m[1] ) . '/' );
			return '<a href="' . esc_url( $url ) . '" class="bn-mention">@' . esc_html( $m[1] ) . '</a>';
		},
		$escaped
	);

	return $escaped;
}
