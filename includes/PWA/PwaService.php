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
	 * Strategy, and why each part is the way it is:
	 *
	 * - The SHELL (BuddyNext's base CSS and the offline page) is precached, so a
	 *   member who loses the network still sees something that looks like the
	 *   community rather than an unstyled list of links. Precaching only "/" — as
	 *   this did — left the one cached page rendering with 61 failed assets.
	 * - STATIC sub-resources are cached as they are used, capped, so repeat views
	 *   are fast and the offline page keeps its styling.
	 * - NAVIGATIONS go to the network first and fall back to a real offline page.
	 * - PAGE HTML IS DELIBERATELY NEVER CACHED. A community page is personalised;
	 *   replaying a stored copy would show one member's feed, notifications or DMs
	 *   to whoever opens the browser next, and would present stale content as if
	 *   it were current. Mainstream social shows an offline notice rather than
	 *   stale personalised content, and that is the behaviour chosen here.
	 * - REST responses are never cached either, for the same reason.
	 *
	 * @return string JavaScript source.
	 */
	public function get_service_worker_script(): string {
		$version     = defined( 'BUDDYNEXT_VERSION' ) ? BUDDYNEXT_VERSION : '1.0.0';
		$shell_cache = 'buddynext-shell-' . $version;
		$asset_cache = 'buddynext-assets-' . $version;

		// Path AND query. On a site without pretty permalinks rest_url() returns
		// "/?rest_route=/buddynext/v1/pwa/offline", so taking PHP_URL_PATH alone
		// collapsed the offline page to "/" — the worker would then precache the
		// homepage and serve it as the offline fallback. Keep the query.
		$offline_parts = (array) wp_parse_url( rest_url( self::REST_NAMESPACE . '/pwa/offline' ) );
		$offline_url   = isset( $offline_parts['path'] ) ? (string) $offline_parts['path'] : '/';
		if ( ! empty( $offline_parts['query'] ) ) {
			$offline_url .= '?' . (string) $offline_parts['query'];
		}

		// Precached without a version query so the offline page can link the same
		// bare URLs and be guaranteed a hit. Requests that DO carry ?ver= still
		// match, via the ignoreSearch fallback in bnMatch().
		$base  = defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : '/';
		$shell = array(
			$offline_url,
			$base . 'assets/css/bn-base.css',
			$base . 'assets/css/bn-shell.css',
			$base . 'assets/css/bn-fonts.css',
			$base . 'assets/js/pwa/offline.js',
		);

		/**
		 * Filters the URLs precached as the offline shell.
		 *
		 * Keep this small: every entry is downloaded on install, for every member.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $shell Absolute or root-relative URLs.
		 */
		$shell = (array) apply_filters( 'buddynext_pwa_shell_assets', $shell );
		$shell = wp_json_encode( array_values( array_unique( array_filter( array_map( 'strval', $shell ) ) ) ) );

		$offline_js = wp_json_encode( $offline_url );

		/*
		 * The real admin and login paths, not assumed ones.
		 *
		 * The fetch handler's bails were hardcoded root-relative —
		 * `url.pathname.indexOf('/wp-admin') === 0`. On a site installed in a
		 * SUBDIRECTORY the admin path is `/community/wp-admin/…`, so indexOf returns
		 * a positive offset rather than 0, the bail never fires, and the worker
		 * caches wp-admin. That includes `/wp-admin/load-scripts.php`, WordPress's
		 * concatenated admin bundle, whose contents differ per screen — so a copy
		 * cached on one admin page is replayed on another and every jQuery-dependent
		 * script on it breaks ("jQuery is not defined").
		 *
		 * The worker claims the whole origin (Service-Worker-Allowed: / plus
		 * scope '/'), so it is genuinely in front of those requests on a
		 * subdirectory install; only the bail was root-relative.
		 *
		 * Derived from admin_url()/site_url() rather than home_url(), because
		 * WordPress allows the admin to live somewhere other than the site root, and
		 * a guess is what this bug is made of.
		 */
		$admin_path = (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
		// site_url('wp-login.php'), NOT wp_login_url(): BuddyNext points the latter
		// at its own /login/ page, so using it would guard the community's auth
		// screen and quietly stop guarding WordPress's — which is the nonce-bearing
		// one the original bail was written for.
		$login_path = (string) wp_parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH );
		$rest_path  = (string) wp_parse_url( rest_url( '/' ), PHP_URL_PATH );

		// JSON_UNESCAPED_SLASHES so the generated worker reads as "/wp-admin/" rather
		// than "\/wp-admin\/". Both parse identically; this file is read in DevTools
		// by whoever is debugging a caching problem, so legibility is the point.
		$admin_path_js = wp_json_encode( '' !== $admin_path ? $admin_path : '/wp-admin/', JSON_UNESCAPED_SLASHES );
		$login_path_js = wp_json_encode( '' !== $login_path ? $login_path : '/wp-login.php', JSON_UNESCAPED_SLASHES );
		$rest_path_js  = wp_json_encode( '' !== $rest_path ? $rest_path : '/wp-json/', JSON_UNESCAPED_SLASHES );

		return <<<JS
'use strict';

const SHELL_CACHE = '{$shell_cache}';
const ASSET_CACHE = '{$asset_cache}';
const OFFLINE_URL = {$offline_js};
const SHELL_ASSETS = {$shell};

// This site's real admin / login / REST paths, injected from PHP. They are NOT
// assumed to be at the origin root: on a subdirectory install they are
// "/community/wp-admin/" and so on, and the guards below used to test for a
// leading "/wp-admin" that never matched there.
const ADMIN_PATH = {$admin_path_js};
const LOGIN_PATH = {$login_path_js};
const REST_PATH = {$rest_path_js};

// Cap the runtime asset cache. A community with many themes, avatars and icon
// sets can otherwise grow without limit on a member's device, and a phone that
// is low on storage evicts the WHOLE origin — including the shell that makes
// offline work at all. Trimmed oldest-first; cache.keys() is insertion-ordered.
const ASSET_CACHE_LIMIT = 60;

// A Response whose .redirected is true can NEVER be replayed for a navigation:
// respondWith() rejects it with "a redirected response was used for a request
// whose redirect mode is not follow", and the navigation fails outright — the
// browser shows a network error instead of the site.
//
// That is not hypothetical. cache.addAll() fetches with redirect mode "follow",
// so on any site with a canonical-host rule (www <-> apex) or http -> https in
// front of a precached URL, the stored copy came back redirected. Cache-first
// then replayed it for every navigation and took the whole site down for anyone
// who had the worker installed (Zoho #41005).
//
// Rebuilding the Response from its body clears the flag while keeping the
// content, so offline still works on those sites instead of being sacrificed.
async function bnCacheable(response) {
  if (!response || !response.ok) {
    return null;
  }
  if (!response.redirected) {
    return response;
  }
  const body = await response.blob();
  return new Response(body, {
    status: response.status,
    statusText: response.statusText,
    headers: response.headers,
  });
}

// Look up a cached entry, ignoring the ?ver= cache-buster on the second attempt.
// The shell is precached without a version query, but the page requests these
// files WITH one, so an exact-match-only lookup would miss every time and the
// offline page would render unstyled — the exact failure this release fixes.
async function bnMatch(request) {
  const exact = await caches.match(request);
  if (exact && !exact.redirected) {
    return exact;
  }
  const loose = await caches.match(request, { ignoreSearch: true });
  return (loose && !loose.redirected) ? loose : null;
}

async function bnTrim(cacheName, limit) {
  const cache = await caches.open(cacheName);
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - limit; i++) {
    await cache.delete(keys[i]);
  }
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(async (cache) => {
      // Deliberately not cache.addAll(): it stores whatever it gets, redirect
      // and all, and one failed asset rejects the whole install — which would
      // leave a member with no offline page at all.
      await Promise.all(SHELL_ASSETS.map(async (asset) => {
        try {
          const cacheable = await bnCacheable(await fetch(asset, { redirect: 'follow' }));
          if (cacheable) {
            await cache.put(asset, cacheable);
          }
        } catch (e) {
          // Precaching is best-effort; a missing asset must not block install.
        }
      }));
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  const keep = [SHELL_CACHE, ASSET_CACHE];
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys.filter((k) => keep.indexOf(k) === -1).map((k) => caches.delete(k))
      )
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  // Only GET is cacheable, and only our own origin. Leaving everything else
  // untouched means a POST, a cross-origin embed or an analytics beacon behaves
  // exactly as it would with no worker installed.
  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // wp-admin and the login screen must never be intercepted: they are
  // authenticated, nonce-bearing, and a stale copy locks an owner out.
  if (url.pathname.indexOf(ADMIN_PATH) === 0 || url.pathname.indexOf(LOGIN_PATH) === 0) {
    return;
  }

  // REST is member-specific data. Network only — no cache write, no cache read.
  // Falling back to a stored copy here would show one member another's feed.
  //
  // BOTH spellings, and the second one is the whole bug. With plain permalinks —
  // or any rest_url() built on a site that has them — REST arrives as
  // /index.php?rest_route=/wp/v2/... The pathname is then /index.php, so it
  // matches neither this test nor the /wp-admin one above, fell through to the
  // static-resource branch at the bottom, and got answered from cache or with the
  // offline page at HTTP 200 and content-type text/html.
  //
  // That broke WordPress CORE, not just our own routes: /wp/v2/users/me came back
  // as the offline page inside wp-admin, so any admin JS expecting JSON failed to
  // parse it, silently, with no error handler firing because the status was 200.
  //
  // This file already knew about the query-string form — see the offline-URL
  // comment in the PHP above, which preserves ?rest_route= precisely because
  // taking PHP_URL_PATH alone collapses it to "/". That reasoning simply never
  // reached the fetch bails.
  // REST_PATH is only a usable prefix when the site has pretty permalinks. Without
  // them rest_url() is "/?rest_route=/", whose PATH is just "/" — and
  // indexOf("/") === 0 is true for every request on the origin, which would bail on
  // everything and silently switch the whole PWA off. Those sites are covered by
  // the rest_route query check beside it, which is what identifies REST there.
  if ((REST_PATH !== '/' && url.pathname.indexOf(REST_PATH) === 0) || url.searchParams.has('rest_route')) {
    return;
  }

  // Navigations: network first, then the offline page. The rendered page itself
  // is never stored (see the class docblock), so there is no stale or
  // cross-member HTML to serve.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(async () => {
        const offline = await bnMatch(new Request(OFFLINE_URL));
        return offline || Response.error();
      })
    );
    return;
  }

  // Static sub-resources: serve from cache, and refresh in the background so an
  // updated file is picked up on the next view rather than pinned until the
  // plugin version changes.
  event.respondWith(
    (async () => {
      const cached = await bnMatch(request);

      const network = fetch(request).then(async (response) => {
        const cacheable = await bnCacheable(response.clone());
        if (cacheable) {
          const cache = await caches.open(ASSET_CACHE);
          await cache.put(request, cacheable);
          bnTrim(ASSET_CACHE, ASSET_CACHE_LIMIT);
        }
        return response;
      }).catch(() => null);

      if (cached) {
        return cached;
      }

      const fresh = await network;
      return fresh || Response.error();
    })()
  );
});
JS;
	}

	/**
	 * The offline fallback page.
	 *
	 * A real page rather than the browser's error screen: it carries the site
	 * name, BuddyNext's own styling (the stylesheets beside it in the precached
	 * shell) and a retry button, so losing signal reads as a moment in the
	 * product rather than as the site being broken.
	 *
	 * Linked stylesheets are deliberately un-versioned so they match the shell
	 * entries exactly and are guaranteed to resolve from cache while offline.
	 *
	 * @return string HTML document.
	 */
	public function get_offline_page(): string {
		$base  = defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : '/';
		$name  = get_bloginfo( 'name' );
		$title = __( 'You are offline', 'buddynext' );
		$body  = __( 'This page is not available without a connection. Your community is waiting once you are back online.', 'buddynext' );
		$retry = __( 'Try again', 'buddynext' );
		$home  = __( 'Go to the community', 'buddynext' );

		// Carry the site's colour choice so the offline page is not a white flash on
		// a dark community. 'auto' is the default and resolves through the
		// prefers-color-scheme block in bn-base.css, exactly as every other page does.
		$theme = (string) get_option( 'buddynext_default_theme', 'auto' );
		$theme = in_array( $theme, array( 'light', 'dark', 'auto' ), true ) ? $theme : 'auto';

		// The <link> and <script> tags below are hand-written rather than enqueued,
		// and WPCS is right to ask why. This document is served by the SERVICE
		// WORKER from cache with no WordPress runtime present: there is no wp_head()
		// to enqueue into. Routing it through the enqueue pipeline would also pull
		// in the theme and every other plugin's assets, none of which are precached
		// — reproducing the unstyled page this release exists to fix. The URLs are
		// the exact ones precached in get_service_worker_script().
		// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$html = sprintf(
			'<!doctype html><html %1$s data-bn-theme="%11$s"><head><meta charset="%2$s">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>%3$s</title>'
			. '<link rel="stylesheet" href="%4$sassets/css/bn-fonts.css">'
			. '<link rel="stylesheet" href="%4$sassets/css/bn-base.css">'
			. '<link rel="stylesheet" href="%4$sassets/css/bn-shell.css">'
			. '</head><body class="bn-offline-body">'
			. '<main class="bn-offline" role="main">'
			. '<p class="bn-offline__site">%5$s</p>'
			. '<h1 class="bn-offline__title">%3$s</h1>'
			. '<p class="bn-offline__text">%6$s</p>'
			. '<p class="bn-offline__actions">'
			. '<button type="button" class="bn-btn" data-variant="primary" data-bn-offline-retry>%7$s</button>'
			. '<a class="bn-btn" data-variant="ghost" href="%8$s">%9$s</a>'
			. '</p></main>'
			. '<script src="%10$sassets/js/pwa/offline.js"></script>'
			. '</body></html>',
			get_language_attributes(),
			esc_attr( get_bloginfo( 'charset' ) ),
			esc_html( $title ),
			esc_url( $base ),
			esc_html( $name ),
			esc_html( $body ),
			esc_html( $retry ),
			esc_url( home_url( '/' ) ),
			esc_html( $home ),
			esc_url( $base ),
			esc_attr( $theme )
		);
		// phpcs:enable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet, WordPress.WP.EnqueuedResources.NonEnqueuedScript

		/**
		 * Filters the offline fallback page HTML.
		 *
		 * @since 1.1.0
		 *
		 * @param string $html Full HTML document.
		 */
		return (string) apply_filters( 'buddynext_pwa_offline_page', $html );
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
			'/pwa/offline',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'rest_offline' ),
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

	/**
	 * Serve the offline fallback page as real HTML.
	 *
	 * Uses the same `rest_pre_serve_request` escape hatch as the worker: the REST
	 * server would otherwise JSON-encode the document into a quoted string, and
	 * the browser would cache and later display that string instead of a page.
	 *
	 * @param \WP_REST_Request $request The offline-page request.
	 * @return \WP_REST_Response
	 */
	public function rest_offline( \WP_REST_Request $request ): \WP_REST_Response {
		$html  = $this->get_offline_page();
		$route = (string) $request->get_route();

		add_filter(
			'rest_pre_serve_request',
			static function ( bool $served, $result, \WP_REST_Request $req ) use ( $html, $route ): bool {
				if ( (string) $req->get_route() !== $route ) {
					return $served;
				}

				if ( ! headers_sent() ) {
					header( 'Content-Type: text/html; charset=utf-8' );
					// Revalidate on every request: the page carries the site name and
					// translated copy, both of which change without the plugin version
					// changing. The service worker holds the offline-time copy.
					header( 'Cache-Control: no-cache' );
				}

				echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a complete HTML document, escaped field-by-field in get_offline_page().

				return true;
			},
			10,
			3
		);

		return new \WP_REST_Response( null, 200 );
	}
}
