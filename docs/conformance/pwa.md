# Conformance — PWA (free)

## Feature
Progressive Web App: installable web-app shell for the BuddyNext community surface. Emits a Web App Manifest, registers a service worker (offline shell + REST network-first caching), and serves a generated app icon — all over the `buddynext/v1` REST namespace.

## Spec ref
- Locked spec: `docs/specs/features/P4-mobile-app.md`
- UX mockup: `docs/v2 Plans/v2/mobile.html`
- Cross-cutting: `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`

> Spec scope note: `P4-mobile-app.md` describes a **React Native / Expo native app** (EAS builds, Expo Push, JWT, white-label store submission). The code under audit (`includes/PWA/PwaService.php`) is a **web PWA** (manifest + service worker), a different delivery vehicle. The native-app deliverables in P4 are **not** implemented in this repo (they would live in a separate Expo project, not `includes/PWA/`). This audit verifies the web-PWA journey that the shipped code actually targets; the native-app spec items are tracked as scope gaps, not code breaks.

## Verdict
**usable-leave-as-is** — the web-PWA install/offline journey is fully wired and self-contained.

## Journey chain (web PWA: install to home screen + offline shell)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Service boots on front-end (manifest link, SW registration, REST routes hooked) | service | wired | `includes/Core/Plugin.php:257`, `includes/PWA/PwaService.php:40-44` |
| `<link rel="manifest">` emitted in `wp_head` | ui | wired | `includes/PWA/PwaService.php:192-198` |
| Manifest JSON served (name, short_name, start_url, display=standalone, scope, icons 192/512, maskable) | rest | wired | `includes/PWA/PwaService.php:143-185`, `rest_manifest()` 341-346 |
| App icon SVG served (opaque, maskable, brand-coloured, site initial) | rest | wired | `includes/PWA/PwaService.php:217-231`, `rest_app_icon()` 369-374 |
| Service worker registered client-side (HTTPS/localhost, skips admin, opt-out filter) | ui | wired | `includes/PWA/PwaService.php:100-131` |
| Service worker served with `Service-Worker-Allowed: /` for site-wide scope | rest | wired | `includes/PWA/PwaService.php:353-359` |
| SW caches `/` shell on install, network-first for `/wp-json/`, cache-first for assets (offline shell) | store | wired | `includes/PWA/PwaService.php:247-291` |
| Browser shows install prompt / Add-to-Home-Screen; standalone launch loads cached shell offline | ui | unknown | Installability is browser-evaluated against the served manifest+SW; cannot be proven from PHP alone |

## First break
none — journey complete (web-PWA install + offline shell is fully wired in code; only the browser-side install eligibility needs a live walk).

## UX gaps
1. **Native mobile app (React Native/Expo) per P4 spec is absent from this repo.** The locked spec's core deliverables — Expo app shell, EAS builds, Expo Push token registration, JWT auth flow, white-label admin panel (app name/icon/splash/store metadata), offline draft sync — have no implementation under `includes/PWA/` (only the web PWA service exists). Severity: high. Confidence: confirmed-in-code. Scope gap against P4, not a break in the shipped web-PWA journey.
2. **No admin UI to configure the web PWA (name/colors/icon).** Customization is code-only via the `buddynext_pwa_manifest` filter (`includes/PWA/PwaService.php:179-184`) and `buddynext_pwa_register_sw` (`:109`). A site owner cannot brand the installable app or toggle it without writing PHP. Severity: low. Confidence: confirmed-in-code. Acceptable for a developer-extensible free feature.
3. **Offline coverage is minimal — only `/` is precached.** `STATIC_ASSETS = ['/']` (`includes/PWA/PwaService.php:251-253`); deeper routes rely on runtime cache-first population, and the network-first `/wp-json/` handler falls back to `caches.match` only if that exact request was previously cached. A cold offline launch shows the home shell but most sub-pages fail. Does not match the P4 "cached feed visible offline / drafts sync on reconnect" goal. Severity: medium. Confidence: confirmed-in-code. Does not break the install journey.

## Minimal refactor plan
(empty — web-PWA journey is usable as-is. The P4 native-app deliverables are a separate body of work that belongs in a dedicated Expo project, not a refactor of this service; raised here as scope tracking only.)

## Live-walk URL
http://buddynext-dev.local/

Browser walk to confirm the unknown step: open on a mobile browser / Chrome DevTools, check Application > Manifest (no errors, icons load from `/wp-json/buddynext/v1/pwa/icon`), Application > Service Workers (registered, scope `/`), then trigger the install prompt and relaunch offline to confirm the home shell renders.
