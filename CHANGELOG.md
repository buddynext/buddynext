# BuddyNext Changelog

## Unreleased

### Testing

- Added Playwright e2e suite under `tests/e2e/` covering every BN user journey across desktop, iPad, and mobile viewports. Run `npm run test:e2e`. See `docs/qa/JOURNEYS.md` for the journey catalogue (67 journeys grouped by role) and `docs/qa/HOW-TO-RUN.md` for the runbook. New devDeps: `@playwright/test`, `typescript`, `@types/node`.

### Shell

- **BREAKING (shell)** — Theme `get_header()` / `get_footer()` now render on every BN-mapped slug. The shell-takeover mode and `buddynext_render_with_theme_chrome` filter introduced in 0.3.0-beta1 are removed. `.bn-app` bursts to 100vw inside the theme so content stays edge-to-edge. The host theme always owns DOCTYPE / `<html>` / `<head>` / `wp_head()` / `<body>` / `wp_body_open()` / `wp_footer()` / `</html>`; BuddyNext only renders the `.bn-app` canvas between them.
- Removed the BN topbar from the hub shell. The active theme's `get_header()` is now the only top navigation; the v2 wireframes always intended this (the `chrome.js` injection in `docs/v2 Plans/v2/*.html` maps to the host theme header in production). `templates/shell/topbar.php` is deleted, and the corresponding `.bn-app__topbar*`, `.bn-app__brand*`, `.bn-app__search*`, `.bn-app__font-scale*`, and `.bn-app__icon-btn` rules are removed from `assets/css/bn-shell.css`. `--bn-topbar-h` is set to `0` so existing `calc()` expressions in feature stylesheets keep working. The shell now renders only rail + main + (optional right sidebar) + mobile bottom nav. The mobile bottom tab bar (`.bn-mobile-nav` from `templates/partials/nav.php`, the 5-item Feed / Spaces / + / Alerts / Profile bar from `docs/v2 Plans/v2/mobile.html`) is rendered by `hub-shell.php` on every BN hub so per-hub templates no longer need to include it.

## 0.3.0-beta1 — 2026-05-21

### Architecture (locked + extension-ready)

- **5-layer modular architecture** documented in `docs/specs/MODULAR-ARCHITECTURE.md`. Core / Bridges / Features / UI / Composition. Every Layer 2 feature is a self-contained folder with the canonical 4-file shape: `Service.php` / `Controller.php` / `Listener.php` / `Cache.php`.
- **Shell inside theme chrome** — PageRouter wraps every BN-mapped slug with the active theme's `get_header()` / `get_footer()`. The `.bn-app` canvas bursts to 100vw so content stays edge-to-edge regardless of the theme's content container. Right sidebar auto-detected via `has_action('buddynext_right_sidebar')`.
- **FeatureRegistry + Settings → Features tab** — site owners pick which Layer 2 features are active. Three tiers: mandatory (always on), default-on (toggleable), opt-in (off until enabled). 19 features catalogued. Third-party plugins extend via `apply_filters('buddynext_features', $catalog)`.
- **Plug-and-play model** — every feature opts in via `apply_filters('buddynext_feature_{slug}', true)`. `Container::has()` lets callers detect sibling features. Templates degrade gracefully when a feature is disabled.
- **7 extension surfaces** documented: new Feature module / hooks + filters / container rebinding / template parts override / `buddynext_right_sidebar` action / hub `_before` / `_after` hooks / REST namespace separation.

### Scale contract (100k × 100k)

- `docs/specs/SCALE-CONTRACT.md` codifies the 10 non-negotiable rules for the target scale.
- **Sidebar widgets** — Service + Cache + Listener pattern. Trending hashtags (60s TTL), suggested follows (300s TTL, per-user), joined spaces (300s TTL, per-user). Cache-bust hooks on 9 domain events.
- **FeedCache** — first-page home feed wrapped in 30s TTL. Page-2+ bypasses (cursor-paginated). Listener busts the writer's keys on `buddynext_post_created` / `_deleted`.

### Frontend (v2 design system, all hubs swept)

- Every BN hub (activity, explore, members, spaces directory + home, notifications, profile view + edit + connections, messages, search, hashtag feed, onboarding, leaderboard, moderation queue, community admin, blocks) chrome-stripped onto the shell. Sidebar widgets hooked via `buddynext_right_sidebar` action.
- `assets/css/bn-base.css` is the canonical v2 token source. Single `--bn-hue` rotates the whole palette (OKLCH).
- v2 attribute API across all primitives: `.bn-btn[data-variant]`, `.bn-input`, `.bn-textarea`, `.bn-select`, `.bn-badge[data-tone]`, `.bn-avatar[data-size+data-presence]`, `.bn-card`, `.bn-tabs` + `.bn-tab[aria-selected]`, `.bn-modal`, `.bn-toggle`, `.bn-stat`, `.bn-stepper`, `.bn-progress`.
- Composer single-state matching `v2/home-feed.html` — avatar + textarea + tools row (icons + privacy + Share).
- Post card uniform: head row + body + reaction chips + action row, all post types preserved.
- Mobile 44px tap targets (style-guide rule 04). Density + text-scale + dyslexia modes via `[data-bn-*]` attributes.
- Sidebar widgets gap (16px), main / right padding parity, post-card spacing — no double-spacing.

### Tooling + boundary skills

- Vendored `bin/ux-audit.sh` (from `/ux-audit` skill).
- `bin/check.sh` — full CI-parity gate: PHP lint + WPCS + PHPStan level 5 + REST-boundary + UX audit.
- `bin/check-rest-boundary.sh` — fails on any `admin-ajax.php` surface. 100% REST frontend enforced.
- `.githooks/pre-commit` — runs the staged-files slice of `bin/check.sh`.
- `bin/ux-audit.sh` audit dropped from 97 → ~12 block-severity violations (remaining are in v2 mockup HTML, expected).

### Tests

- 33 new architecture tests: FeatureRegistry (11), Container (6), Sidebar/WidgetService (6), Feed/FeedCache (5).
- Full suite: 747 tests, 1285 assertions, all OK with 21 pre-existing skips.

### Pro v0.4.0 (sibling repo)

- **P1.1 Stripe SDK + webhook controller** (`98e9975` in Pro). REST `POST /buddynext-pro/v1/stripe/webhook` with signature validation. Subscription events upsert `bn_subscriptions`. 9 new tests.
- **P2.2 AI moderation classifier** (`f219048`). Hooks Free's `buddynext_safeguard_check` filter. Provider: OpenAI / Anthropic / local heuristic. Admin settings + REST test endpoint. 15 new tests.
- **P3.1 Soketi WebSocket bootstrap** (`97f14fa`). Pusher-protocol client + RealtimeDispatcher (5 events) + REST auth handshake + admin. 23 new tests.
- **P4.1 FCM push** (`7225e38`). `bn_push_tokens` table + JWT auth via service account + PushDispatcher (hooks `buddynext_notification_created`) + admin. 24 new tests.
- Pro test suite: 351 → 384 tests, 1 pre-existing failure.

### Documentation

- `docs/v2 Plans/PLAN.md` — surface-to-prototype map + 6 uniformity gates + 9-rule contract + rollout phases.
- `docs/v2 Plans/REVIEW.md` — engineering review of v2 (browser support, accessibility, whitelabel correctness).
- `docs/v2 Plans/TEMPLATE-REFACTOR-PLAN.md` — long-term per-template refactor contract.
- `docs/specs/MODULAR-ARCHITECTURE.md` — 5-layer model + plug-and-play + 7 extension surfaces.
- `docs/specs/SCALE-CONTRACT.md` — 100k × 100k binding rules.
- `docs/specs/TEMPLATE-PARTS.md` — reusable partial library.
- `docs/specs/REST-FRONTEND-CONTRACT.md` — REST-only boundary contract.
- `docs/specs/REST-INVENTORY.md` — 113 captured REST routes.

### Removed

- `docs/superpowers/brainstorm/14544-1773947712/` legacy mockups (50 files, ~22k lines). v2 Plans is the only design source.
- Legacy `:root` alias block in `bn-feed.css` that created circular token references on hubs loading multiple stylesheets.
- All `wp_ajax_*` registrations from frontend code paths. The last surface (NavManager slug-check) migrated to `GET /buddynext/v1/admin/slug-check`.

## 0.2.0 — 2026-05-20

Beta dogfooding release. Baseline before the v2 architecture sweep.
