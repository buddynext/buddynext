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
			'name'             => buddynext_site_name(),
			'short_name'       => mb_substr( buddynext_site_name(), 0, 12 ),
			'description'      => get_bloginfo( 'description' ),
			'start_url'        => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => $this->splash_color(),
			'theme_color'      => \BuddyNext\Theme\Appearance::display_brand(),
			'orientation'      => 'portrait-primary',
			'scope'            => home_url( '/' ),
			'categories'       => array( 'social', 'community' ),
		);

		// Icon set: the site owner's own Site Icon (Settings > General) when one is set,
		// else the branded PNGs we ship. Chromium rejects REST-served SVG manifest icons,
		// so both are real raster files at the 192/512 break points the install criteria
		// look for. Only our own 512 is advertised `maskable`: it carries the safe-zone
		// padding, and an owner's icon would be cropped by a mask.
		$png_base = ( defined( 'BUDDYNEXT_URL' ) ? constant( 'BUDDYNEXT_URL' ) : plugins_url( '/', dirname( __DIR__, 2 ) ) ) . 'assets/images/pwa/';
		$icon_192 = (string) get_site_icon_url( 192 );
		$icon_512 = (string) get_site_icon_url( 512 );
		$own      = '' === $icon_192 || '' === $icon_512;
		// The owner's Site Icon may be a JPEG; declare what the file really is.
		$icon_type = $own ? 'image/png' : ( wp_check_filetype( $icon_512 )['type'] ?: 'image/png' );

		$manifest['icons'] = array(
			array(
				'src'     => $own ? $png_base . 'icon-192.png' : $icon_192,
				'sizes'   => '192x192',
				'type'    => $icon_type,
				'purpose' => 'any',
			),
			array(
				'src'     => $own ? $png_base . 'icon-512.png' : $icon_512,
				'sizes'   => '512x512',
				'type'    => $icon_type,
				'purpose' => 'any',
			),
		);

		if ( $own ) {
			$manifest['icons'][] = array(
				'src'     => $png_base . 'icon-512.png',
				'sizes'   => '512x512',
				'type'    => 'image/png',
				'purpose' => 'maskable',
			);
		}

		/**
		 * Filter the BuddyNext Web App Manifest.
		 *
		 * @param array<string, mixed> $manifest Manifest data.
		 */
		return (array) apply_filters( self::FILTER_MANIFEST, $manifest );
	}

	/**
	 * The installed app's splash colour: the page canvas of the default colour scheme.
	 *
	 * A manifest holds real colours and one value only, so these are the hex forms of
	 * --bn-canvas in bn-base.css, and 'auto' (which only the device can resolve) gets
	 * the light one.
	 *
	 * @return string Hex colour.
	 */
	private function splash_color(): string {
		return 'dark' === get_option( 'buddynext_default_theme', 'auto' ) ? '#15161f' : '#fbfbfd';
	}

	/**
	 * Output the <link rel="manifest"> and matching theme-color tags in wp_head.
	 *
	 * @return void
	 */
	public function output_manifest_link(): void {
		$url = rest_url( self::REST_NAMESPACE . '/pwa/manifest' );
		printf(
			'<link rel="manifest" href="%s">' . "\n",
			esc_url( $url )
		);

		// From the filtered manifest, so the browser bar and the installed app agree.
		$theme_color = (string) ( $this->get_manifest()['theme_color'] ?? '' );
		if ( '' !== $theme_color ) {
			printf( '<meta name="theme-color" content="%s">' . "\n", esc_attr( $theme_color ) );
		}
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
	 * - STATIC sub-resources are cached only when they are BuddyNext's own files
	 *   (the plugin directories in buddynext_pwa_asset_paths). The worker claims the
	 *   whole origin, so caching everything put it in front of the theme's and every
	 *   other plugin's files, and their updates were served stale for a view. A
	 *   cached copy is served only for the EXACT URL (a ?ver= bump always fetches
	 *   the new file); the version-blind match is the offline fallback only.
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
		// "own-" marks the cache that holds BuddyNext's files only; the previous
		// catch-all cache (theme and other plugins' files) is deleted on activate.
		$asset_cache = 'buddynext-assets-own-' . $version;

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

		$own_urls = array( defined( 'BUDDYNEXT_URL' ) ? BUDDYNEXT_URL : '' );
		if ( defined( 'BUDDYNEXTPRO_PLUGIN_URL' ) ) {
			$own_urls[] = BUDDYNEXTPRO_PLUGIN_URL;
		}
		/**
		 * Filters the URL prefixes whose static files the service worker caches.
		 *
		 * Everything outside these prefixes (the theme, other plugins, uploads) is
		 * left to the browser's normal HTTP cache, so their updates are never
		 * served stale by BuddyNext's worker. A family plugin that ships offline
		 * assets through buddynext_pwa_worker_imports adds its own directory here.
		 *
		 * @since 1.2.4
		 *
		 * @param string[] $own_urls Plugin directory URLs (absolute or root-relative).
		 */
		$own_urls  = (array) apply_filters( 'buddynext_pwa_asset_paths', $own_urls );
		$own_paths = array();
		foreach ( $own_urls as $own_url ) {
			// Decoded here; the worker encodes it the browser's way (browserPath()),
			// because PHP's rawurlencode() is stricter than a browser's pathname:
			// it encodes ( ) + ' @ , which url.pathname leaves alone.
			$own_path = rawurldecode( (string) wp_parse_url( (string) $own_url, PHP_URL_PATH ) );
			if ( '' !== $own_path && '/' !== $own_path ) {
				$own_paths[] = trailingslashit( $own_path );
			}
		}
		$own_paths_js = wp_json_encode( array_values( array_unique( $own_paths ) ), JSON_UNESCAPED_SLASHES );

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
		$admin_path = rawurldecode( (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH ) );
		// site_url('wp-login.php'), NOT wp_login_url(): BuddyNext points the latter
		// at its own /login/ page, so using it would guard the community's auth
		// screen and quietly stop guarding WordPress's — which is the nonce-bearing
		// one the original bail was written for.
		$login_path = rawurldecode( (string) wp_parse_url( site_url( 'wp-login.php' ), PHP_URL_PATH ) );
		$rest_path  = rawurldecode( (string) wp_parse_url( rest_url( '/' ), PHP_URL_PATH ) );

		// JSON_UNESCAPED_SLASHES so the generated worker reads as "/wp-admin/" rather
		// than "\/wp-admin\/". Both parse identically; this file is read in DevTools
		// by whoever is debugging a caching problem, so legibility is the point.
		$admin_path_js = wp_json_encode( '' !== $admin_path ? $admin_path : '/wp-admin/', JSON_UNESCAPED_SLASHES );
		$login_path_js = wp_json_encode( '' !== $login_path ? $login_path : '/wp-login.php', JSON_UNESCAPED_SLASHES );
		$rest_path_js  = wp_json_encode( '' !== $rest_path ? $rest_path : '/wp-json/', JSON_UNESCAPED_SLASHES );

		/**
		 * Filters extra scripts imported into the service worker via importScripts().
		 *
		 * A companion plugin (e.g. Learnomy's offline lesson-progress queue) adds its
		 * own worker logic here rather than shipping a second service worker, which
		 * cannot exist for the same scope. Same-origin URLs only: a cross-origin
		 * import is dropped (a worker may not importScripts across origins, and it
		 * would be a supply-chain hole). Each import is wrapped so one broken script
		 * cannot abort worker installation. When empty, the worker bytes are
		 * unchanged, so no needless re-install is forced.
		 *
		 * @since 1.2.1
		 *
		 * @param string[] $imports Same-origin absolute or root-relative script URLs.
		 */
		$worker_imports = (array) apply_filters( 'buddynext_pwa_worker_imports', array() );
		$origin_host    = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$import_lines   = array();
		foreach ( $worker_imports as $import_url ) {
			$import_url = (string) $import_url;
			if ( '' === $import_url ) {
				continue;
			}
			// Root-relative (no host) is same-origin; an absolute URL must match the
			// site host. Anything else is cross-origin and dropped.
			$host = (string) wp_parse_url( $import_url, PHP_URL_HOST );
			if ( '' !== $host && $host !== $origin_host ) {
				continue;
			}
			$import_lines[ $import_url ] = 'try { importScripts(' . wp_json_encode( $import_url, JSON_UNESCAPED_SLASHES ) . '); } catch (e) {}';
		}
		$imports_js = $import_lines ? ( implode( "\n", array_values( $import_lines ) ) . "\n\n" ) : '';

		// The worker's source is a plain JavaScript file, assets/js/pwa/sw.template.js,
		// so it can be read and linted as JavaScript. The values this site decides
		// (cache names, the shell list, its real admin / login / REST paths) are
		// dropped into the {{name}} markers.
		$template = (string) file_get_contents( BUDDYNEXT_DIR . 'assets/js/pwa/sw.template.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file in this plugin, not a remote URL.

		return strtr(
			$template,
			array(
				'{{imports_js}}'    => $imports_js,
				'{{shell_cache}}'   => $shell_cache,
				'{{asset_cache}}'   => $asset_cache,
				'{{offline_js}}'    => (string) $offline_js,
				'{{shell}}'         => (string) $shell,
				'{{admin_path_js}}' => (string) $admin_path_js,
				'{{login_path_js}}' => (string) $login_path_js,
				'{{own_paths_js}}'  => (string) $own_paths_js,
				'{{rest_path_js}}'  => (string) $rest_path_js,
			)
		);
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
		$name  = buddynext_site_name();
		$title = __( 'You are offline', 'buddynext' );
		$body  = __( 'This page is not available without a connection. Your community is waiting once you are back online.', 'buddynext' );
		$retry = __( 'Try again', 'buddynext' );
		$home  = __( 'Go to the community', 'buddynext' );

		// Carry the site's colour choice so the offline page is not a white flash on
		// a dark community. 'auto' is the default and resolves through the
		// prefers-color-scheme block in bn-base.css, exactly as every other page does.
		$theme = (string) get_option( 'buddynext_default_theme', 'auto' );
		// 'system' (follow the device) is what 'auto' does on this theme-less page.
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
