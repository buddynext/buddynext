# Frontend Interactivity & Client-Side Navigation — Adoption Notes

**Status:** Notes / pre-implementation. NO code changed yet.
**Date:** 2026-06-18.
**Standard being adopted:** `docs/standards/frontend-interactivity.md` v1.0 (reference impl: Jetonomy 1.5.0).
**Scope:** `buddynext` (free) + `buddynext-pro`.
**Purpose:** Capture the complete, verified current-state picture and the adoption plan so implementation is one planned solution, not incremental patches.

> Every number and file:line below was verified against the codebase and the **live REST server** on this date — not inferred. Re-verify before acting if the tree has moved on.

---

## 1. TL;DR verdict

| Layer | Ready? | One-line reason |
|---|---|---|
| **REST endpoints (server)** | ✅ Yes, ~100% | 216 live routes, 0 admin-ajax, 0 orphan frontend calls. |
| **Frontend calling layer (client)** | ❌ No | 198 scattered raw `fetch()`, no shared client, **no nonce-refresh** → stale-nonce 403 ships today. |
| **Client-side navigation (the standard)** | ❌ Not adopted | 0 router regions, 0 `interactivity-router`, 0 `buddynext:navigated`. App full-page-loads every route. |
| **Markup readiness for the region** | 🟡 Close | Chrome already outside main (R8 met); region needs **1 frame edit**, not 41 template rewrites. |

Two distinct problems hide here:
1. **A present-day bug** (stale-nonce 403 on long sessions) — independent of client-nav, worth fixing on its own.
2. **A non-adoption** (the app doesn't navigate like an app) — the larger, optional product upgrade.

---

## 2. The standard in one screen (what we are measured against)

From `docs/standards/frontend-interactivity.md` §3 (normative rules):

- **R1** One element per layout with BOTH `data-wp-interactive` AND `data-wp-router-region`.
- **R2** No per-route/per-view script enqueues for region content — store module + router only.
- **R3** Interactive controls declarative (`data-wp-on--*`) by default — auto-hydrate on swap.
- **R4** Any unavoidable classic script is idempotent AND re-inits on both initial load AND a `{ns}:navigated` event. Never `DOMContentLoaded` alone for region content.
- **R5** No inline `<script>` / `wp_add_inline_script` driving region behavior.
- **R6** All frontend data calls go through one shared REST client with automatic nonce-refresh.
- **R7** Minimal **deny-list** of routes that must full-load (rich editors). Deny-list, not allow-list.
- **R8** Persistent UI (header/nav/opt-ins) renders OUTSIDE the region.
- **R9** Verify every interactive surface AFTER a client-side navigation, not just full load.

For BuddyNext the namespace is `buddynext`, so the nav event is **`buddynext:navigated`** and the region id should be **`buddynext/main`**.

---

## 3. Current-state audit (verified facts)

### 3.1 The frame — `templates/shell/hub-shell.php`
Confirmed render path: `includes/Core/PageRouter.php:683-687` selects `shell/hub-shell.php` and wraps it in `get_header()`/`get_footer()` (theme owns the document).

Structure (full read):
```
<div class="bn-app" id="bn-app">                       line 75  — NOT interactive, NOT a region
  <div class="bn-app__shell">                          line 77
    <nav class="bn-app__rail">                          rail.php:145   CHROME (before main)
    <main class="bn-app__main" id="bn-main-content">    line 83  — PLAIN HTML, holds inner hub
        echo $bn_main_html                              line 86  — buffered inner template
    </main>
    [right-sidebar.php]                                 line 91  (conditional)
  </div>
  <nav class="bn-mobile-nav" data-wp-interactive=...>   nav.php:203    CHROME (after shell)
</div>
```

### 3.2 Navigation model TODAY
Full page loads. `PageRouter::dispatch_hub_template()` on `template_redirect` emits the whole document via the theme. No client-side router exists.

### 3.3 Markup gaps vs standard
| Rule | Status | Evidence |
|---|---|---|
| R1 router region | ❌ | `data-wp-router-region` count across **all 41 templates = 0**. `<main id="bn-main-content">` is plain. |
| R8 chrome outside region | ✅ | Rail (`rail.php:145`) before `<main>`; mobile nav (`nav.php:203`) after `.bn-app__shell`. Both outside the future region. No restructuring needed. |
| R5 inline script | ✅ effectively | Only **1** in-region inline script: `templates/feed/explore.php:259` — a `<script type="application/json" id="bn-feed-explore-config">` data island (not behavior). `partials/nav.php` line 10 is a *stale comment*, no actual inline script. Head-enqueued `wp_add_inline_script` (PageRouter:982 `window.bnSpaces`, Appearance.php:196, WPMediaVerseBridge.php:417) are not region content. |

**Multi-island reality (compatible, but no single wrapper to convert):** inner templates scatter scoped islands rather than wrapping content once —
- `feed/home.php` → `buddynext/feed-tabs` (:355) + `buddynext/announcement` (:452); `.bn-feed-stack` (:317) plain.
- `directory/members.php` → `buddynext/members` (:539) + a block deliberately outside it (:667).
- `spaces/moderation.php` → `buddynext/spaces` (:245) + `buddynext/moderation` (:375).
- `parts/profile-tab-panel.php` → two per-row islands.

Nested `data-wp-interactive` re-hydrates inside the region on swap, so the region must be added **once at the frame** (`<main>`), which covers all templates.

### 3.4 JS layer — client-nav landmines
- **`buddynext:navigated` listeners anywhere:** 0. No nav-aware re-init exists.
- **Frontend `DOMContentLoaded` handlers:** 23 across 11 files (excl. admin). Breakdown:

| File | DCL | Notes |
|---|---|---|
| `feed/store.js` | **11** | drafts restore, composer enhancements, realtime new-posts pill, comment indicator, reactors popover, emoji picker — all region content |
| `notifications/store.js` | 2 | region content |
| `shell/font-scale.js` | 2 | **chrome/global** — runs on shell, persists (exempt) |
| `members/store.js` | 1 | region content |
| `search/store.js` | 1 | region content |
| `spaces/store.js` | 1 | region content |
| `social/relation-remove.js` | 1 | region content |
| `privacy/consent-banner.js` | 1 | **global banner** (exempt) |
| `buddynext-pro/realtime/store.js` | 1 | region content (pro) |
| `buddynext-pro/membership-stripe.js` | 1 | region content (pro) |
| `buddynext-pro/profile-fields.js` | 1 | region content (pro) |

→ **~20 region-content init()s** need to become idempotent + bind `buddynext:navigated`. `feed/store.js` is the bulk (11).

### 3.5 API calling layer
- **Frontend raw `fetch()`:** 198 (free+pro, excl admin). Heaviest: `feed/store.js` 31, `profile/store.js` 31, `spaces/store.js` 27, `members/store.js` 15, `messages/store.js` 15, `notifications/store.js` 13, `blocks.js` 11, `moderation/store.js` 10, `social/follow-store.js` 10.
- **Shared REST client (`window.buddynextRest.restFetch`):** does not exist.
- **Nonce sent:** yes — 225 references to `X-WP-Nonce`/`restNonce` (passed via `data-wp-context`).
- **403 / `rest_cookie_invalid_nonce` recovery:** none — the 4 grep hits (`profile/store.js:1367,1451`, `feed/store.js:189,1478`) are comments, not refresh logic.

### 3.6 REST endpoints (server) — the good news
- **Registered routes (live server):** 216 (`buddynext/v1` + `buddynext-pro/v1`); 234 `register_rest_route` calls.
- **admin-ajax on frontend:** 0. `bin/check-rest-boundary.sh` gate = green. Contract: `docs/specs/REST-FRONTEND-CONTRACT.md`.
- **`wp_ajax_` handlers:** 0.
- **Orphan frontend calls:** 0 — every fully-qualified frontend path resolves to a live route. (An earlier static matcher false-flagged ~38; the live server disproved all of them. Do not trust line-based route matching — query the server.)

---

## 4. The bug that ships TODAY (priority, independent of client-nav)

`wp_create_nonce('wp_rest')` lifetime is 12–24h (WP nonce tick). After it rolls, **every frontend write 403s with no recovery** — user must hard-reload. Present on any long-lived tab/session right now. Client-nav adoption only amplifies it (one nonce must survive many in-place navigations).

**Fix = the shared client (§5 Phase 2).** This is the highest-value, lowest-risk slice and is worth doing standalone even if client-nav is deferred.

---

## 5. Adoption plan (phased, no big-bang)

| Phase | Work | Effort | Risk | Ships standalone? |
|---|---|---|---|---|
| 0 | Plan + study Jetonomy 1.5.0 region/restFetch/navigate action | 0.5d | — | — |
| **2 (do first)** | Build `window.buddynextRest.restFetch` (nonce + 403→refresh + central error/toast); migrate 198 `fetch()` across ~20 store files | 3–4d | Med | ✅ ships standalone; fixes §4 bug |
| 1 | Add `data-wp-interactive="buddynext"` + `data-wp-router-region="buddynext/main"` to `<main id="bn-main-content">` (hub-shell.php:83); enqueue `interactivity-router` as dynamic dep of store module; add `navigate` action dispatching `buddynext:navigated` | 0.5–1d | Low | needs Phase 2 first |
| 3 | Make ~20 region-content init()s idempotent + bind `buddynext:navigated` (feed/store.js = 11) | 2–3d | Med | needs Phase 1 |
| 4 | Editor deny-list (composer, post view) full-load per R7 | 0.5d | Low | needs Phase 1 |
| 5 | Verify EVERY interactive surface after client-nav, across roles/viewports/dark mode (§9 + verify-per-item rule) | 2d | — | needs 1,3,4 |
| 6 | Copy standard into `buddynext-pro/docs/standards/` + CLAUDE.md pointer; audit pro stores | 0.5d | Low | anytime |

**Total ~9–12 dev-days** (≈2 weeks solo; phases 2–3 compressible with parallel agents). Markup is cheap; phases 2–3 are the cost.

**Recommended rollout:** Phase 2 standalone → enable region (Phase 1) behind a **broad deny-list** (most routes still full-load) → migrate surfaces one at a time, shrinking the deny-list as each is hardened + verified. De-risks; ships incrementally.

---

## 6. How it helps (payoff)

1. **App-like navigation** — in-place swaps between feed/profile/spaces/messages; matches the premium bar BuddyNext targets (CLAUDE.md: Notion/Asana/LinkedIn/Facebook).
2. **Performance, biggest on mobile** — theme chrome/CSS/scripts stop re-downloading per nav; only `<main>` swaps.
3. **Eliminates the "works on full load, dead after nav" bug class** — the exact failure that bit the reference plugin (Jetonomy 1.5.0: messaging typeahead, conversation auto-scroll).
4. **Kills the stale-nonce 403 + 198 scattered fetches** — one client, one nonce-refresh, one error path.
5. **Portfolio alignment** — matches the normative Wbcom standard; `wp-plugin-qa` folds §5 checklist into audits.

---

## 7. Risks & non-negotiables

- **All-or-nothing per surface.** Half-adopting client-nav *creates* the bug class the standard warns about. Phases 3 and 5 are mandatory, not optional polish.
- **Verification is per-surface, post-navigation** (§9). HTTP 200 / DOM-present / clean grep do NOT count — must exercise the control after a client-side swap.
- **Pro renders into the same region.** Pro frontend stores (realtime, membership-stripe, profile-fields) need the same nav-aware treatment + shared client. Pro is in scope, not a follow-up.
- **`feed/explore.php:259` JSON island** must be re-read on `buddynext:navigated` (or moved into `data-wp-context`) or explore config goes stale after a swap.

---

## 8. Decisions (owner, 2026-06-18)

1. **Adopt fully.** Full client-side navigation adoption, not just the nonce fix. ✅ DECIDED
2. **Implement uniformly across free + pro.** ONE pattern (shared `restFetch`, region wiring, nav-aware init shape) applied identically to every surface and maintained as the standing convention — no bespoke per-file variants. ✅ DECIDED
3. **Bundle the whole adoption** (all phases), not Phase 2 in isolation. ✅ DECIDED
4. **Deny-list contents** — CONFIRMED (2026-06-18): full-load auth, onboarding, profile-edit, space settings/admin, single-post permalink, and membership/Stripe checkout. Everything else client-navs. ✅ DECIDED
5. **Kickoff** — implement **Phase 2 + Phase 1 together** (shared client + region/router behind a broad deny-list), then pause for review before Phase 3 (nav-aware init). ✅ DECIDED

**Uniformity is the headline constraint:** the pattern produced in Phase 2/1/3 becomes the documented house style; all 20+ stores and both plugins conform to it. Deviations are bugs.

---

## 9. Verification commands (reproduce this audit)

```bash
# zero admin-ajax (contract gate)
bin/check-rest-boundary.sh

# router region / client-nav presence (expect 0)
grep -rc "data-wp-router-region" templates/
grep -rn "interactivity-router\|add_client_navigation_support\|navigated" assets/js ../buddynext-pro/assets/js

# authoritative live routes (do NOT trust static route matching)
wp eval '$r=rest_get_server()->get_routes();foreach($r as $k=>$v){if(strpos($k,"/buddynext")===0)echo $k."\n";}' | sort -u

# frontend DOMContentLoaded + fetch census (excl admin)
find assets/js ../buddynext-pro/assets/js -name '*.js' ! -path '*/admin/*' ! -name '*.min.js' \
  -exec grep -Hc "DOMContentLoaded" {} \;
```

---

# PART B — Compiled Implementation Blueprint

Compiled 2026-06-18 from three parallel Plan passes, all anchored to the Jetonomy 1.5.0 reference. This is the executable plan; PART A above is the audit/rationale. **No code written yet — awaiting sign-off.**

## B0. New artifacts (the uniform pattern lives in 3 shell modules + 1 route)

| Artifact | Kind | Purpose |
|---|---|---|
| `assets/js/shell/rest-client.js` | NEW ES module (`@buddynext/rest-client`) | The single `restFetch(path,opts) → {ok,status,data}` with nonce + 403→refresh→retry. Also assigns `window.buddynextRest`. |
| `assets/js/shell/navigate.js` | NEW ES module (`@buddynext/navigate`) | The bare-`buddynext` iAPI store owning `actions.navigate`; loads `interactivity-router` as a dynamic dep; dispatches `buddynext:navigated`. |
| `assets/js/shell/nav-init.js` | NEW ES module (`@buddynext/nav-init`) | The single `onNavReady(init,{once})` helper every store uses for init binding. |
| `GET buddynext/v1/auth/nonce` | NEW REST route in `includes/Auth/AuthController.php` | Mints a fresh `wp_rest` nonce by re-validating the auth cookie directly (request carries a stale nonce). Copy Jetonomy `get_nonce()` verbatim. `permission_callback => __return_true`. |

**Two uniform JS shapes — every region script collapses into one of these (uniformity decision):**
- **Flavor A (element-scoped):** `init()` queries `selector:not([data-bn-x-wired])`, stamps the flag, attaches listeners. Re-run safely on `buddynext:navigated`. → `onNavReady(init)`.
- **Flavor B (document-delegated singleton):** install once behind a `window.__bnXInited` flag; delegated listener auto-covers swapped content. → `onNavReady(init, { once:true })` (flag makes any re-run a no-op).

## B1. Phase 2 — Shared REST client (DO FIRST; ships standalone, fixes the live 403)

1. Add `GET buddynext/v1/auth/nonce` to `AuthController` (register at `:31`). Body = Jetonomy `class-auth-controller.php:204-229`: `wp_validate_auth_cookie('', 'logged_in')` → `wp_set_current_user()` → `wp_create_nonce('wp_rest')` + `Cache-Control: no-store`. **Not** behind `require_auth` (runs without a valid nonce by design). Ship + test in isolation first.
2. Create `assets/js/shell/rest-client.js` mirroring `jetonomy-rest.js:120-202`, plus two BuddyNext extensions: **`opts.base`** (per-call namespace override — `messages/store.js` calls WPMediaVerse `mvs/v1` via `ctx.mvsRest`) and **`opts.nonce`** (honor context-supplied nonce; resolution `opts.nonce → currentNonce → window.buddynextRestData.restNonce`). Imports `bnToast` from `shell/dialog.js`; `opts.toastOnError` default-on, **opt-out at sites that already do optimistic rollback + own toast**. FormData bodies pass through untouched (uploads).
3. In `AssetService` (`:387-445`): register `@buddynext/rest-client` (dep `@buddynext/shell-dialog`); localize `window.buddynextRestData = {restBase, restNonce}`; add it as a dep of **every** feature store (free + pro). Pro reuses the free module — never ships its own.
4. Migrate all **198** `fetch()` → `restFetch()` using the 4 transforms (A: JSON body, B: no-body DELETE/POST, C: GET w/ context nonce → `res.data`, D: cross-namespace `base:ctx.mvsRest`). Order: feed(31)→profile(31)→spaces(27) to flush the pattern, then the tail, Pro(7) last. 3 FormData upload sites (`messages:799`, `spaces:2774`, `profile:1358`) just pass FormData as `body`.
5. **Gate:** `grep -rn "fetch(" assets/js/ | grep -v "restFetch\|min"` returns only `rest-client.js`. Replace stray `res.json()` with `res.data`.

**Acceptance:** stale nonce on a feed page → mutate → today 403s & loses the action; after Phase 2 → silent `/auth/nonce` refresh + retry succeeds.

## B2. Phase 1 — Frame region + router + navigate action

1. `templates/shell/hub-shell.php`:
   - **Line 75** `.bn-app#bn-app` → add `data-wp-interactive="buddynext"` + `data-wp-on--click="actions.navigate"` (click-delegation host; stays persistent shell, NOT a region — so rail + mobile-nav links also client-nav).
   - **Line 83** `<main id="bn-main-content">` → add `data-wp-interactive="buddynext"` + `data-wp-router-region="buddynext/main"` (the region; keep `tabindex="-1"`). **No other template touched** — nested islands re-hydrate inside the swapped `<main>`.
2. Create `assets/js/shell/navigate.js`: `store('buddynext',{actions:{*navigate(event){…}}})` mirroring `view.js:688-764` — bail on defaultPrevented/`#`/modified/cross-origin; deny-list check (B4); `preventDefault()` → `yield import('@wordpress/interactivity-router')` → `router.actions.navigate(href)`; dispatch `buddynext:navigated` on `document` with `{detail:{href}}`; focus region + `scrollTo(0,0)` + re-sync rail/mobile-nav `.active` by pathname; `catch{ location.href=href }`.
3. `AssetService`: register `@buddynext/navigate` with deps `[{id:'@wordpress/interactivity'},{id:'@wordpress/interactivity-router',import:'dynamic'}]` (router loads once, lazily, on first nav).
4. `PageRouter::enqueue_hub_assets()` (`:836-1041`): enqueue `@buddynext/navigate` on every hub. **R2 resolution — move region-content modules to an always-on union** (`@buddynext/feed,profile,spaces,members,messages,notifications,search,hashtags,moderation,space-members,gamification` + Pro region stores), because a client-nav never re-runs `enqueue_hub_assets()`. Region-content **CSS** likewise unioned to always-on (conservative; correct styling after any swap). Auth/onboarding modules stay per-route (deny-listed). Move `window.bnSpaces` (`:982`) to always-on.

## B3. Phase 3 — Nav-aware init (~20 sites) + leak fixes

1. Create `assets/js/shell/nav-init.js` exporting `onNavReady(init,{once=false})` (readyState guard + `buddynext:navigated` bind unless `once`). Register `@buddynext/nav-init`; add as dep of every store. Pro's 2 IIFEs (`membership-stripe.js`, `profile-fields.js`) inline the 5-line body.
2. Convert each site (full table in PART B source / agent C output). Summary:
   - **feed/store.js (11):** drafts(1), infinite-scroll(2), explore-search(3), composer-enhance(5) → Flavor A. realtime-posts-pill(6) hybrid (singleton listeners + re-seeded poll, clear `pollTimer` first). comment-indicator(7), reactors-popover(8), emoji-picker(9) → Flavor B singletons. comment MutationObserver(4) → leave as module-level body singleton, no nav bind.
   - **notifications/store.js (2):** popstate + hot-poll → Flavor B singletons (window flags).
   - **members(1), search(1), spaces-form(1):** Flavor A (per-element/per-form dataset guards).
   - **social/relation-remove.js:** already Flavor B — just adopt `onNavReady(_, {once:true})`.
   - **EXEMPT chrome:** `shell/font-scale.js`, `privacy/consent-banner.js` → `onNavReady(init,{once:true})`.
3. **Leak fixes (mandatory — these duplicate per nav):** realtime pill poll + 2 doc listeners; comment-indicator doc listener (double-counts); reactors/emoji 3-4 doc/window listeners + duplicate body panels; notifications poll chain + 4 listeners; members menu-close doc click; search keydown; spaces create-form submit (double-creates spaces).
4. **`templates/feed/explore.php:258-271`** — DELETE the `bn-feed-explore-config` JSON island. Verified read by zero JS; config already rides `data-wp-context`. (Fallback if hesitant: move into context — never keep as re-read island.)

## B4. Phase 4 — Deny-list (broad first, then shrink per verified surface)

Deny-list lives **in the navigate action**, parameterized by **server-localized URL prefixes** (BuddyNext hub slugs are admin-configurable — cannot hardcode segments like Jetonomy). Localize `{authUrl, onboardingUrl, spacesUrl, peopleUrl, postBase}` in `enqueue_hub_assets()`. Full-load when path: starts with `authUrl`/`onboardingUrl`; under `spacesUrl` ending `settings`/`admin`; profile-edit (`peopleUrl`…`/edit/`); single-post `/p/{digits}/`; **+ membership checkout (Stripe)**. Default = client-nav. **Rollout:** start broad (most hubs full-load), shrink surface-by-surface only after that surface is nav-aware (Phase 3) AND verified (Phase 5).

## B5. Phase 5 — Verification (per surface, post-navigation)

Per standard §9 + verify-per-item rule. For each surface: full-load elsewhere → click-nav in → exercise control → confirm identical to full load. **High-risk regressions to target:** realtime pills (duplicate-count), reactors/emoji popovers (duplicate body panels), Pro Leaflet map (throws on re-init), spaces create-form (double-create), auth/onboarding (must full-load into correct shell).

## B6. Phase 6 — Pro

1. Copy `frontend-interactivity.md` → `buddynext-pro/docs/standards/` (byte-identical); add pointers to **both** CLAUDE.md files (Free currently lacks one too).
2. Pro stores: **realtime/store.js** — bind `buddynext:navigated` to a `resubscribe()` (unsub stale page channels → `subscribePageChannels(readConfig())`); keep Pusher conn + visibility singleton. **profile-fields.js** — 3 mandatory dataset guards (`bnMapWired` — Leaflet throws on re-init; `bnFileWired` — duplicate filename span; `bnCondWired`). **membership-stripe.js** — deny-list the checkout route (recommended) OR `container.dataset.bnStripeMounted` guard (duplicate iframe otherwise).

## B7. Cross-phase dependency contract

- **Phase 1 emits** `buddynext:navigated` (document, `{detail:{href}}`); **Phase 3 consumes** it. Idempotency guards (Phase 3) are safe to add *before* the event exists.
- **Phase 2's 403→refresh is a hard prerequisite** before any deny-list shrink — one nonce must survive many in-place navs.
- **R2 union (Phase 1) must include a surface's module** before that surface leaves the deny-list, else it dies post-swap (the exact bug class the standard prevents). **Never shrink the deny-list for a surface that isn't both nav-aware (Phase 3) and verified (Phase 5).**
- Pro renders inside Free's `<main>`, so the Free navigate action covers it — **no Pro-side navigate action.** One pattern, Free owns it.

## B8. Recommended execution order

`Phase 2 (ship standalone) → Phase 1 + Phase 4 broad deny-list together → Phase 3 (+ nav-init helper first) → Phase 6 Pro stores → Phase 5 verify + progressively shrink deny-list per verified surface.`
