# Conformance: PWA (free)

## Feature
Progressive Web App layer for the BuddyNext front-end: a Web App Manifest plus a
service worker, served from the `buddynext/v1` REST namespace and wired into every
front-end page via `wp_head` / `wp_footer`. This is the free-tier web counterpart
to the native React Native app described in the spec.

## Spec ref
- Locked spec: `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P4-mobile-app.md`
  (note: the locked spec describes a **React Native / Expo native app**, not a browser
  PWA. The only shippable code under `includes/PWA/` is a browser PWA — manifest +
  service worker. The spec's "Offline Handling" section is the nearest intent match:
  cached feed visible offline, no silent failures.)
- UX mockup: `/Users/vapvarun/dev/repos/buddynext/docs/v2 Plans/v2/mobile.html`
  (this mockup depicts the native app shell; it contains **no** PWA install-prompt,
  manifest, or service-worker UX — grep for `install`/`manifest`/`offline`/`standalone`
  returns nothing.)

## Verdict
**usable-minor-polish** — the PWA registration journey is fully wired at runtime
(manifest link emitted, SW registered, both REST routes live), and the site keeps
working if anything fails. The one real defect is that the manifest points at two
icon files that do not exist in the build, which breaks the "Add to Home Screen"
installability promise on most browsers.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| PWA service is booted on the front-end | service | wired | `includes/Core/Plugin.php:253` (`$container->get('pwa')->init()`); bind at `:668` |
| `<link rel="manifest">` emitted in `<head>` | ui | wired | `includes/PWA/PwaService.php:41,148-154` (`output_manifest_link` on `wp_head`) |
| Manifest JSON served over REST | rest | wired | `includes/PWA/PwaService.php:227-236,254-259` (`GET /buddynext/v1/pwa/manifest`, `__return_true` perm, `application/manifest+json`) |
| Manifest references app icons (192/512) | service | broken | `includes/PWA/PwaService.php:120-131` points at `assets/images/icon-192.png` / `icon-512.png`; **no `assets/images/` directory exists** (only `assets/icons/` of Lucide SVGs) |
| Service-worker registration script emitted | ui | wired | `includes/PWA/PwaService.php:42,66-97` (`output_sw_registration` on `wp_footer`, HTTPS/admin guards, opt-out filter) |
| Service worker JS served over REST | rest | wired | `includes/PWA/PwaService.php:238-247,266-272` (`GET /buddynext/v1/pwa/sw`, `Service-Worker-Allowed: /`, correct content-type) |
| SW caches shell + network-first for `/wp-json/` | service | wired | `includes/PWA/PwaService.php:166-214` (install/activate/fetch handlers; cache-first for assets, network-first with cache fallback for API) |
| Offline: site shell available, API falls back to cache | db/service | wired | `includes/PWA/PwaService.php:200-204` (API fetch catch → `caches.match`); satisfies spec "no silent failure" intent |
| Member can "Add to Home Screen" / install | ui | broken | depends on valid 192+512 icons in manifest (above); missing icons fail Chrome/Edge installability criteria. No `beforeinstallprompt` UI exists, but that is optional — the browser-native install affordance is the expected path. |

## First break
`includes/PWA/PwaService.php:120-131` — the manifest advertises `assets/images/icon-192.png`
and `assets/images/icon-512.png`, but that directory does not exist in the plugin
(`assets/` contains `icons/`, `svg/`, `css/`, `js/`, … but no `images/`). The manifest
loads and the SW registers fine, so offline caching works; but the two icon URLs 404,
which fails the maskable-icon installability requirement in Chromium browsers, so
"Add to Home Screen" is degraded/unavailable. This is the earliest break in the
install half of the journey. The offline half of the journey is complete.

## UX gaps

1. **Manifest icons 404 → not installable** — severity: high — confidence: confirmed-in-code
   — `includes/PWA/PwaService.php:120-131`; no `assets/images/` dir (verified by `ls`).
   Without a 192px and a 512px PNG icon, Chromium will not surface the install prompt
   and the home-screen icon falls back to a screenshot/letter glyph.

2. **No install affordance / discoverability** — severity: low — confidence: confirmed-in-code
   — there is no in-page "Install app" button or `beforeinstallprompt` capture anywhere
   (`grep beforeinstallprompt|Install App` returns nothing). This is acceptable (the
   browser's native menu still offers install once icons are valid), so it is polish,
   not a break.

3. **theme_color hard-coded to `#0073aa`** — severity: low — confidence: confirmed-in-code
   — `includes/PWA/PwaService.php:117` uses the legacy WP-admin blue rather than a
   BuddyNext OKLCH theme token. Cosmetic only; overridable via the
   `buddynext_pwa_manifest` filter (`:26,140`).

## Minimal refactor plan

1. Add `assets/images/icon-192.png` and `assets/images/icon-512.png` (a maskable 512
   recommended) so the manifest's existing icon entries resolve. No code change needed
   — the paths at `PwaService.php:120-131` already point there. This alone moves the
   verdict to fully usable.
2. (Optional, low priority) Set `theme_color` from the active OKLCH brand token instead
   of `#0073aa` at `PwaService.php:117`, or document that sites override it via the
   `buddynext_pwa_manifest` filter.

The service wiring, REST routes, SW caching strategy, and offline fallback are correct
and must not be rewritten.

## Live-walk URL
http://buddynext-dev.local/ — load over HTTPS/localhost, open DevTools →
Application → Manifest (expect the two icon rows to show a fetch error) and →
Service Workers (expect `…/wp-json/buddynext/v1/pwa/sw` activated). Toggle offline
and reload to confirm the cached shell renders.
