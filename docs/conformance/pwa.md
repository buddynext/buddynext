# Conformance: PWA (free)

**Feature:** PWA (Progressive Web App — installable web community surface)
**Spec ref:** `docs/specs/features/P4-mobile-app.md` (note: this locked spec describes the React Native / Expo *native* app. There is no dedicated locked spec for the web PWA layer. The PWA code is the web-installability counterpart to the mobile journey.)
**Journey/mockup:** `docs/v2 Plans/v2/mobile.html` (phone-frame visualization of mobile surfaces; contains no PWA-specific install/manifest UX).
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/

---

## What the PWA layer does

`includes/PWA/PwaService.php` is a single self-contained service that makes the existing BuddyNext web community installable to a device home screen:

- Emits `<link rel="manifest">` on `wp_head` (`PwaService.php:192`).
- Serves the Web App Manifest JSON at `GET /wp-json/buddynext/v1/pwa/manifest` (`PwaService.php:341`, route at `:307`).
- Serves a generated, site-branded SVG app icon at `GET /wp-json/buddynext/v1/pwa/icon` (`PwaService.php:369`).
- Serves a service worker (cache-first static, network-first API) at `GET /wp-json/buddynext/v1/pwa/sw` with `Service-Worker-Allowed: /` (`PwaService.php:353`).
- Injects the SW registration bootstrap on `wp_footer`, skipped in admin, with an opt-out filter `buddynext_pwa_register_sw` (`PwaService.php:100`).

It is wired into boot: `Plugin.php:672` binds `pwa`; `Plugin.php:257` calls `$container->get( 'pwa' )->init()`.

The locked spec's substantive coverage (feed, spaces, DMs, push tokens, offline drafts, white-label store metadata) is the *native Expo app*, which lives outside this repo path. Those items are out of scope for the `includes/PWA/` web layer and are not gaps in this code.

---

## Journey chain (web "install to home screen")

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member loads any front-end page; manifest link present in head | ui | wired | `includes/PWA/PwaService.php:192` |
| Browser fetches manifest JSON | rest | wired | `includes/PWA/PwaService.php:307` (route), `:341` (callback, public `__return_true`) |
| Manifest carries install-required keys (name, short_name, start_url, display=standalone, icons) | service | wired | `includes/PWA/PwaService.php:143`; asserted `tests/PWA/PwaServiceTest.php:67` |
| Browser fetches app icon | rest | wired | `includes/PWA/PwaService.php:327` (route), `:369` (callback, `image/svg+xml`) |
| Service worker registers (non-admin) | ui | wired | `includes/PWA/PwaService.php:100`; SW served `:353` with `Service-Worker-Allowed: /` |
| Installed app opens standalone; cached shell offline, API network-first | service | wired | `includes/PWA/PwaService.php:243` (install/activate/fetch handlers) |
| Site owner customizes name/colors/icon | service | wired | `buddynext_pwa_manifest` filter `includes/PWA/PwaService.php:184`; covered `tests/PWA/PwaServiceTest.php:88` |

---

## First break

none — journey complete. Every link from page load to manifest to icon to SW registration to offline cache is present, public, and booted. No proven break by code reading.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Manifest ships SVG-only icons (`type: image/svg+xml`) for the 192/512 entries. Chromium accepts SVG for display, but install-prompt eligibility for SVG-only manifests is browser/version dependent; some Chrome versions still require a raster PNG at 192 and 512 to fire `beforeinstallprompt`. The code comment at `PwaService.php:157-161` asserts SVG satisfies the install bar — true on current Chromium, not guaranteed across all install-prompt heuristics. | low | needs-live-verification | `includes/PWA/PwaService.php:164` |
| No in-page "Install app" control; install relies on the browser's native affordance (no `beforeinstallprompt` handler). Standard PWA behavior, not a break — flagged for completeness. | low | confirmed-in-code | `includes/PWA/PwaService.php` (no `beforeinstallprompt` handler present) |

Neither gap stops the journey. A member on a modern Chromium browser can install and run the community standalone with offline shell caching.

---

## Minimal refactor plan

None. The feature is usable as-is. Do not rewrite.

If a future live walk (Lighthouse "Installable" audit at the live URL) proves the install prompt does not fire due to SVG-only icons, the minimal fix is to add one 512x512 PNG icon entry to the manifest `icons` array. Prove live first; do not pre-emptively change.

---

## Live-walk checklist (human, in browser)

1. Open http://buddynext-dev.local/ (HTTPS or localhost required for SW).
2. DevTools to Application to Manifest: confirm name, icons, `display: standalone` load with no errors.
3. DevTools to Application to Service Workers: confirm `buddynext/v1/pwa/sw` is activated.
4. Run Lighthouse PWA / "Installable" audit. Pass = SVG-icon gap closed. Icon flag = apply PNG fallback above.
5. Install via address-bar icon; confirm standalone launch and a cached page renders with network set to Offline.
