<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * BuddyNext Progressive Web App service.
 *
 * Outputs the Web App Manifest link tag on wp_head and provides a REST
 * endpoint serving the manifest JSON.  Also generates the service worker
 * script string used by the SW endpoint.
 *
 * Manifest values can be overridden via the `buddynext_pwa_manifest` filter.
 *
 * @package BuddyNext\PWA
 */

declare( strict_types=1 );

namespace BuddyNext\PWA;

/**
 * Manages PWA manifest and service worker delivery.
 */
class PwaService {

	/**
	 * Filter name for customising the manifest array.
	 */
	public const FILTER_MANIFEST = 'buddynext_pwa_manifest';

	/**
	 * REST namespace for PWA routes.
	 */
	private const REST_NAMESPACE = 'buddynext/v1';

	// ── Boot ──────────────────────────────────────────────────────────────────

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_head', array( $this, 'output_manifest_link' ) );
		add_action( 'wp_footer', array( $this, 'output_sw_registration' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Resolve the manifest theme colour used for the generated app icon.
	 *
	 * Reads the colour from the (filtered) manifest so the icon stays in
	 * sync with whatever a site sets via `buddynext_pwa_manifest`. Falls
	 * back to the default brand blue and rejects anything that is not a
	 * 3/6-digit hex so the generated SVG can never be malformed.
	 *
	 * @param array<string, mixed> $manifest Manifest data.
	 * @return string A safe `#rrggbb`/`#rgb` hex colour.
	 */
	private function icon_color( array $manifest ): string {
		$color = isset( $manifest['theme_color'] ) ? (string) $manifest['theme_color'] : '#0073aa';
		if ( ! preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ) {
			$color = '#0073aa';
		}
		return $color;
	}

	/**
	 * Derive the single-letter brand glyph for the generated app icon.
	 *
	 * Uses the first character of the site name (uppercased) so the
	 * home-screen icon reads as the community's own mark. Defaults to
	 * `B` (BuddyNext) when the site name is empty.
	 *
	 * @return string A single, escaped uppercase character.
	 */
	private function icon_glyph(): string {
		$name  = trim( (string) get_bloginfo( 'name' ) );
		$glyph = '' !== $name ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : 'B';
		return $glyph;
	}

	/**
	 * Emit the small client bootstrap that registers the service worker.
	 *
	 * Runs in wp_footer so the script lands after the page body. The SW
	 * is served from /wp-json/buddynext/v1/pwa/sw with a
	 * Service-Worker-Allowed: / header (set in rest_service_worker())
	 * so the browser accepts the site-wide scope override.
	 *
	 * Gated by an opt-in filter so a site can disable PWA without
	 * unhooking the whole service:
	 *   add_filter( 'buddynext_pwa_register_sw', '__return_false' );
	 *
	 * Skips when:
	 *   - The site is loaded over an insecure origin (SW requires HTTPS,
	 *     except on localhost which the browser handles).
	 *   - The visitor is in the WP admin (the manifest only applies to
	 *     the front-end community surface).
	 *
	 * @return void
	 */
	public function output_sw_registration(): void {
		if ( is_admin() ) {
			return;
		}
		/**
		 * Filters whether the service worker registration is emitted.
		 *
		 * @param bool $emit Default true.
		 */
		if ( ! apply_filters( 'buddynext_pwa_register_sw', true ) ) {
			return;
		}
		$sw_url = rest_url( self::REST_NAMESPACE . '/pwa/sw' );
		?>
		<script>
		( function () {
			if ( ! ( 'serviceWorker' in navigator ) ) {
				return;
			}
			window.addEventListener( 'load', function () {
				navigator.serviceWorker.register(
					<?php echo wp_json_encode( esc_url_raw( $sw_url ) ); ?>,
					{ scope: '/' }
				).catch( function () {
					// Registration failures are non-fatal — the site keeps
					// working without offline support.
				} );
			} );
		} )();
		</script>
		<?php
	}

	// ── Manifest ──────────────────────────────────────────────────────────────

	/**
	 * Return the Web App Manifest data array.
	 *
	 * Applies the `buddynext_pwa_manifest` filter before returning so themes
	 * and plugins can customise any value.
	 *
	 * @return array<string, mixed>
	 */
	public function get_manifest(): array {
		$manifest = array(
			'name'             => get_bloginfo( 'name' ),
			'short_name'       => substr( get_bloginfo( 'name' ), 0, 12 ),
			'description'      => get_bloginfo( 'description' ),
			'start_url'        => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => '#ffffff',
			'theme_color'      => '#0073aa',
			'orientation'      => 'portrait-primary',
			'scope'            => home_url( '/' ),
			'categories'       => array( 'social', 'community' ),
		);

		// Build the icon list from a self-contained SVG served over REST. A
		// single scalable, opaque SVG marked `any maskable` satisfies the
		// Chromium installability bar (it accepts SVG icons), so no binary
		// PNG asset needs to ship with the plugin. The `sizes` list advertises
		// the 192 and 512 break points the install criteria look for.
		$icon_url = rest_url( self::REST_NAMESPACE . '/pwa/icon' );

		$manifest['icons'] = array(
			array(
				'src'     => $icon_url,
				'sizes'   => 'any',
				'type'    => 'image/svg+xml',
				'purpose' => 'any maskable',
			),
			array(
				'src'     => $icon_url,
				'sizes'   => '192x192 512x512',
				'type'    => 'image/svg+xml',
				'purpose' => 'any maskable',
			),
		);

		/**
		 * Filter the BuddyNext Web App Manifest.
		 *
		 * @param array<string, mixed> $manifest Manifest data.
		 */
		return (array) apply_filters( self::FILTER_MANIFEST, $manifest );
	}

	/**
	 * Output the <link rel="manifest"> tag in wp_head.
	 *
	 * @return void
	 */
	public function output_manifest_link(): void {
		$url = rest_url( self::REST_NAMESPACE . '/pwa/manifest' );
		printf(
			'<link rel="manifest" href="%s">' . "\n",
			esc_url( $url )
		);
	}

	/**
	 * Return the generated app-icon SVG markup.
	 *
	 * Produces an opaque, square, maskable icon: a solid brand-coloured
	 * rounded tile carrying the site's initial. Built entirely from the
	 * manifest values (theme colour + site name) so it tracks any site
	 * customisation made through `buddynext_pwa_manifest`. Self-contained
	 * with literal colours — CSS `--bn-*` tokens are intentionally NOT used
	 * here because the icon is fetched standalone by the browser, outside
	 * any stylesheet context, where custom properties do not resolve.
	 *
	 * Drawn on a 512×512 canvas with the glyph kept inside the central
	 * ~80% "safe zone" so the maskable purpose survives aggressive
	 * platform cropping.
	 *
	 * @return string SVG source.
	 */
	public function get_app_icon_svg(): string {
		$manifest = $this->get_manifest();
		$color    = $this->icon_color( $manifest );
		$glyph    = $this->icon_glyph();

		$safe_glyph = htmlspecialchars( $glyph, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		$safe_color = htmlspecialchars( $color, ENT_QUOTES | ENT_XML1, 'UTF-8' );

		return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512" role="img" aria-label="App icon">
  <rect width="512" height="512" rx="96" ry="96" fill="{$safe_color}"/>
  <text x="256" y="256" text-anchor="middle" dominant-baseline="central" fill="#ffffff" font-family="system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif" font-size="288" font-weight="700">{$safe_glyph}</text>
</svg>
SVG;
	}

	// ── Service worker ────────────────────────────────────────────────────────

	/**
	 * Return the service worker JavaScript.
	 *
	 * The SW uses a cache-first strategy for static assets and a
	 * network-first strategy for API and HTML responses.
	 *
	 * @return string JavaScript source.
	 */
	public function get_service_worker_script(): string {
		$version    = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : '1.0.0';
		$cache_name = 'buddynext-v' . $version;

		return <<<JS
'use strict';

const CACHE_NAME = '{$cache_name}';
const STATIC_ASSETS = [
  '/',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Network-first for REST API calls.
  if (url.pathname.startsWith('/wp-json/')) {
    event.respondWith(
      fetch(event.request).catch(() => caches.match(event.request))
    );
    return;
  }

  // Cache-first for everything else.
  event.respondWith(
    caches.match(event.request).then(
      (cached) => cached || fetch(event.request)
    )
  );
});
JS;
	}

	// ── REST routes ───────────────────────────────────────────────────────────

	/**
	 * Register PWA REST routes.
	 *
	 * GET /buddynext/v1/pwa/manifest  → manifest JSON
	 * GET /buddynext/v1/pwa/sw        → service worker JavaScript
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/pwa/manifest',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_manifest' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pwa/sw',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_service_worker' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/pwa/icon',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_app_icon' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST callback — serve the manifest JSON.
	 *
	 * @return \WP_REST_Response
	 */
	public function rest_manifest(): \WP_REST_Response {
		$response = new \WP_REST_Response( $this->get_manifest(), 200 );
		$response->header( 'Content-Type', 'application/manifest+json' );
		$response->header( 'Cache-Control', 'public, max-age=3600' );
		return $response;
	}

	/**
	 * REST callback — serve the service worker JavaScript.
	 *
	 * @return \WP_HTTP_Response
	 */
	public function rest_service_worker(): \WP_HTTP_Response {
		$response = new \WP_HTTP_Response( $this->get_service_worker_script(), 200 );
		$response->header( 'Content-Type', 'application/javascript' );
		$response->header( 'Service-Worker-Allowed', '/' );
		$response->header( 'Cache-Control', 'no-cache' );
		return $response;
	}

	/**
	 * REST callback — serve the generated app-icon SVG.
	 *
	 * Referenced by the manifest `icons` entries. Served as image/svg+xml
	 * so Chromium treats it as a valid installable icon.
	 *
	 * @return \WP_HTTP_Response
	 */
	public function rest_app_icon(): \WP_HTTP_Response {
		$response = new \WP_HTTP_Response( $this->get_app_icon_svg(), 200 );
		$response->header( 'Content-Type', 'image/svg+xml' );
		$response->header( 'Cache-Control', 'public, max-age=86400' );
		return $response;
	}
}
