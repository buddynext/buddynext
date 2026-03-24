<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Hub slug option setup.
 *
 * BuddyNext uses virtual pages — no backing WordPress pages are created.
 * All hub URLs are handled by PageRouter rewrite rules and rendered via
 * dispatch_hub_template() at template_redirect.
 *
 * This class is retained for forward-compatibility (admin settings panels
 * may reference it) but performs no page creation.
 *
 * @package BuddyNext\Core
 * @since   1.0.0
 */

declare( strict_types=1 );

namespace BuddyNext\Core;

/**
 * Placeholder — hub routing no longer requires backing WordPress pages.
 */
class PageSetup {

	/**
	 * Register hooks (no-op — virtual pages need no setup).
	 *
	 * @return void
	 */
	public function register(): void {
		// Intentionally empty. Hub URLs are handled entirely by PageRouter
		// rewrite rules + dispatch_hub_template(). No WP pages are created.
	}
}
