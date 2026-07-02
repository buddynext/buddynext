<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Immutable description of one BuddyNext hub surface.
 *
 * Core hubs and addon hubs are described identically; the routing pipeline
 * consumes these via HubRegistry rather than hardcoding per-hub logic.
 *
 * @package BuddyNext\Core
 * @since 1.0.4
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Immutable description of one BuddyNext hub surface.
 */
final class HubDescriptor {
	/**
	 * Creates an immutable hub surface descriptor.
	 *
	 * @param string            $key              Hub key (bn_hub value), e.g. 'feed'.
	 * @param string            $slug_option      Option holding the URL slug.
	 * @param string            $default_slug     Built-in slug when the option is empty.
	 * @param string            $page_option      Option holding the backing page id.
	 * @param string            $title            Backing page title.
	 * @param string            $shortcode        Backing page content shortcode.
	 * @param string|null       $query_var        bn_hub value if it differs from $key (else $key).
	 * @param callable|null     $register_rules   Registers this hub's rewrite rules. Null = core hub handled by PageRouter directly.
	 * @param callable|null     $resolve_template fn(string $hub): ?string addon template resolver. Null = resolved by PageRouter's core switch.
	 * @param array<int,string> $query_vars       Extra public query vars this hub reads.
	 * @param bool              $backing_page     Whether Installer creates a backing WP page.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $slug_option,
		public readonly string $default_slug,
		public readonly string $page_option,
		public readonly string $title,
		public readonly string $shortcode,
		public readonly ?string $query_var = null,
		public readonly mixed $register_rules = null,
		public readonly mixed $resolve_template = null,
		public readonly array $query_vars = array(),
		public readonly bool $backing_page = true
	) {}

	/**
	 * Returns the effective bn_hub query-var value for this hub.
	 *
	 * Falls back to the hub key when no explicit query_var is set.
	 *
	 * @return string
	 */
	public function hub_query_var(): string {
		return $this->query_var ?? $this->key;
	}
}
