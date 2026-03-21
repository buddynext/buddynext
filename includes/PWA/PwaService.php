<?php
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
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
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
			'icons'            => array(
				array(
					'src'   => plugins_url( 'assets/images/icon-192.png', BUDDYNEXT_FILE ),
					'sizes' => '192x192',
					'type'  => 'image/png',
				),
				array(
					'src'   => plugins_url( 'assets/images/icon-512.png', BUDDYNEXT_FILE ),
					'sizes' => '512x512',
					'type'  => 'image/png',
				),
			),
			'categories'       => array( 'social', 'community' ),
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
}
