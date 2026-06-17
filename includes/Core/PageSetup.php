<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Hub page/route setup (no-op placeholder).
 *
 * Hub URLs are slug-routed: each hub is reachable at a configurable slug
 * (buddynext_slug_*) via PageRouter rewrite rules, rendered by
 * dispatch_hub_template() at template_redirect. A hub MAY also be backed by a
 * real WordPress page (buddynext_page_*) — created by Installer::create_hub_pages()
 * on activation, or on demand from the admin Pages & URLs tab (NavManager) — but
 * the backing page is optional and is never the canonical URL source.
 *
 * Page creation therefore lives in Installer + NavManager, not here. This class
 * is retained only because other code references it; it performs no work.
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
