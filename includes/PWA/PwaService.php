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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_sw_registration' ) );
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Enqueue the client bootstrap that registers the service worker.
	 *
	 * The behaviour lives in assets/js/pwa/sw-register.js (no inline script —
	 * see the UX-audit F2 rule); the SW URL is passed via wp_localize_script.
	 * The SW is served from /wp-json/buddynext/v1/pwa/sw with a
	 * Service-Worker-Allowed: / header (set in rest_service_worker()) so the
	 * browser accepts the site-wide scope override.
	 *
	 * Gated by an opt-in filter so a site can disable PWA without unhooking
	 * the whole service:
	 *   add_filter( 'buddynext_pwa_register_sw', '__return_false' );
	 *
	 * Skips in the WP admin (the manifest only applies to the front-end
	 * community surface).
	 *
	 * @return void
	 */
	public function enqueue_sw_registration(): void {
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

		wp_enqueue_script(
			'bn-pwa-sw',
			BUDDYNEXT_URL . 'assets/js/pwa/sw-register.js',
			array(),
			BUDDYNEXT_VERSION,
			true
		);
		wp_localize_script(
			'bn-pwa-sw',
			'bnPwaSw',
			array( 'swUrl' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/pwa/sw' ) ) )
		);
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

		// Icon set: branded PNG marks at the 192/512 break points the install
		// criteria look for. PNGs are the only universally-accepted manifest icon
		// format — Chromium rejects REST-served SVG manifest icons ("isn't a
		// valid image"), so we ship real PNGs. The 512 is also advertised
		// `maskable`; the brand mark carries enough padding for the safe zone.
		$png_base = ( defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) : plugins_url( '/', dirname( __DIR__, 2 ) ) ) . 'assets/images/pwa/';

		$manifest['icons'] = array(
			array(
				'src'     => $png_base . 'icon-192.png',
				'sizes'   => '192x192',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => $png_base . 'icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'any',
			),
			array(
				'src'     => $png_base . 'icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
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

		// There is deliberately NO /pwa/icon route.
		//
		// It existed, it was broken in the same way the service worker was (the REST
		// server JSON-encoded the SVG body), and NOTHING consumed it — get_manifest()
		// points its icons at real PNG files, because Chromium rejects REST-served SVG
		// manifest icons ("isn't a valid image"). So it was a broken, unreachable route
		// that looked like a working icon endpoint: the next person needing an icon URL
		// would have reached for it and shipped a broken image. Removed rather than
		// repaired.
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
	 * @param \WP_REST_Request $request Incoming request (used to scope the raw serve).
	 * @return \WP_REST_Response
	 */
	public function rest_service_worker( \WP_REST_Request $request ): \WP_REST_Response {
		$script = $this->get_service_worker_script();
		$route  = (string) $request->get_route();

		// SERVE THE SCRIPT RAW. A service worker is JavaScript, not an API resource.
		//
		// Returning a WP_HTTP_Response whose body is a string does NOT serve that
		// string: the REST server runs the response data through wp_json_encode()
		// regardless of the Content-Type header you set on it. Setting the header
		// changes the header, not the serializer. So the browser received
		//
		// "'use strict';\n\nconst CACHE_NAME = 'buddynext-v1.0.7';\n…"
		//
		// — a JSON string literal, quoted and escaped.
		//
		// And that is WORSE than a broken script, because it is VALID JavaScript: a
		// string expression that evaluates to a string and does nothing. It does not
		// throw. So navigator.serviceWorker.register() SUCCEEDED, the browser installed
		// the worker, and the worker had ZERO event listeners — every
		// self.addEventListener() was trapped inside the string. The .catch() on the
		// register call never fired, because nothing failed.
		//
		// Result: every customer had a service worker that was installed, "active", and
		// completely inert — no offline cache, no precache, no push handling — with no
		// console error, no failed request, and nothing in any log to reveal it.
		//
		// rest_pre_serve_request is the supported way out: echo the bytes ourselves and
		// tell the REST server we already served the response.
		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, $result, \WP_REST_Request $req ) use ( $script, $route ): bool {
				if ( (string) $req->get_route() !== $route ) {
					return $served;
				}

				if ( ! headers_sent() ) {
					header( 'Content-Type: application/javascript; charset=utf-8' );
					// The worker is served from /wp-json/…, but it must control the whole
					// site, so it has to be allowed a scope above its own path.
					header( 'Service-Worker-Allowed: /' );
					header( 'Cache-Control: no-cache' );
				}

				echo $script; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JavaScript source, not HTML; escaping it is what broke this in the first place.

				return true;
			},
			10,
			3
		);

		return new \WP_REST_Response( null, 200 );
	}
}
