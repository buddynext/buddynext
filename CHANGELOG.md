# BuddyNext Changelog

## Unreleased

### Social Graph (production)

- **Social Graph (production)** — Member directory rebuilt as a reactive store with debounced live search, member-type pill row, instant sort + relation tab switching, loading skeleton, recoverable error state, and empty state with a Reset filters CTA. Follow / Connect chips on every card use optimistic UI with rollback + toast on REST 4xx/5xx. Per-card kebab menu surfaces Mute, Block, and Report through the same modal partials the profile view uses. Page title renders as `Members · {site name}` via the `document_title_parts` filter.
- `templates/directory/members.php` drops the Apply submit, adds `.bn-md-pill-row` fed by `MemberTypeService`, wires the search input to a 250 ms debounced `actions.handleSearchInput`, renders reactive skeleton / error / empty / grid blocks bound to `state.loading` / `state.hasError` / `state.showEmpty` / `state.gridHidden`, and adds the per-card overflow menu (Mute / Block / Report). Cross-surface block and report modal markup is rendered outside the Interactivity root so the directory store opens them imperatively via `[hidden]` toggles + bound DOM handlers.
- `assets/js/members/store.js` rewritten end-to-end: reactive filter state (search / sort / relation / member type), `refresh( ctx )` REST round-trip with skeleton + error states, browser URL kept in sync via `history.replaceState`, per-card optimistic toggleFollow / toggleConnection / acceptConnection / declineConnection / toggleMute with full rollback + `bnToast` success/failure copy, openBlock + openReport routing into the shared modals, kebab outside-click auto-close.
- New REST endpoint `GET /buddynext/v1/members` (see `includes/Profile/MemberDirectoryController.php`) returns a shaped payload per row: display_name, handle, avatar_url, bio_excerpt, profile_url, messages_url, member_type label, follow + connection state, mutual_count, is_online. Validates sort / relation enums, supports cursor pagination via the existing `MemberDirectoryService::list_members()`, and respects suspend / shadow-ban / bidirectional block filtering.
- `templates/partials/follow-button.php` upgraded to a 5-state reactive button (`unfollowed` / `following` / `pending` / `blocked` / `self`) driven by `data-wp-bind--data-state`, `data-wp-bind--class`, and `data-wp-bind--aria-pressed`. `blocked` + `self` short-circuit in PHP so the bound element never renders. New `assets/js/social/follow-store.js` ships the production toggle: optimistic flip + REST round-trip + toast on success/failure + rollback on 4xx/5xx + sidebar widget cache invalidated server-side by the existing WidgetListener.
- `templates/partials/connection-button.php` and the same `social/follow-store.js` now surface all five connection states (`none` / `pending-sent` / `pending-received` / `accepted` / `blocked`) with the right CTA per state. Each action runs optimistically with rollback + toast on failure.
- Feed surface: comment menu gains a Report button (visible to non-owners), wired to the same `/reports` endpoint with a `bnPrompt` modal + success / failure toast. Post-card Report flow now emits a success toast on submit and a danger toast on failure.
- `assets/css/bn-members.css` adds `.bn-md-pill-row`, skeleton, error, empty-action, and per-card kebab menu styles. All tokens come from `--bn-*`.
- `includes/Core/AssetService.php` registers a new `@buddynext/social-buttons` script module (powered by `assets/js/social/follow-store.js`) declared as a shell-dialog consumer; `PageRouter::enqueue_hub_assets()` enqueues it on every BN hub so the standalone Follow / Connect partials (sidebar widgets, blocks, etc.) load their reactive store on the frontend. `@buddynext/members` joins the shell-dialog consumer list.
- New tests:
  - `tests/SocialGraph/FollowServiceToggleTest.php` — toggle cycle, idempotency, cache invalidation on toggle, action emission count.
  - `tests/SocialGraph/ConnectionServiceTest.php` — five additional cases covering the 5-state machine (`none → pending-sent → pending-received → accepted`, `pending-sent → withdrawn → none`, `pending-received → declined`, `accepted → disconnected → none`, plus authorisation guard on `accept_request`).
  - `tests/Profile/MemberDirectoryServiceTest.php` — search filter, alphabetical sort, most-active sort, member-type meta scope, connections-relation scope.

### Hashtags + Search + DM bridge (production)

- **DM bridge detection hardened** (Messages, row 12). `templates/messages/list.php` now detects WPMediaVerse via three independent signals: the canonical `WPMediaVerse\Core\Plugin` class, the `MVS_VERSION` constant, or any listener on the `buddynext_render_messages` action. A single upstream rename or load-order quirk no longer collapses the messages page into the "WPMediaVerse required" empty state. `mu-plugins/buddynext-early-router.php` whitelist now spells out the WPMediaVerse Free / Pro / hyphenated slug variants explicitly so plugin-isolation on BN routes can never strip the DM engine.
- **Hashtag feed polish** (row 3). Sort tab labelled "Following" renamed to "Following only" to disambiguate from the follow-toggle. Follow / unfollow actions now emit success + error toasts via `window.bnToast`. The Related hashtags sidebar gains a per-row Follow chip wired to the same `actions.toggleFollowHashtag` action; the store now prefers the clicked button's `data-hashtag` attribute over the page context so chips work in any list. Context-state updates are scoped to the page-level hashtag so sidebar toggles do not desync the header.
- **Explore facet wiring** (row 2). The All / People / Posts / Spaces / Media chips on `/activity/explore/` previously had no handler attached because `setFilter` lived in the `buddynext/feed-tabs` store while the chips bind to `buddynext/feed`. Added `setFilter` + `onSearch` to the `buddynext/feed` namespace so chips route to the unified search results page with the matching facet (`/search/?type=members|posts|spaces|media|hashtags`). Trending-tag chips (`tag:foo`) route to the hashtag feed. The explore search input now fires Enter to navigate to `/search/?q=`.
- **Search results page** (row 17). Added Hashtags and Media facets to the type tabs + result list. Hashtag facet renders the bn_hashtags slug match list with post counts. Media facet renders posts that have non-empty `media_ids`. Allowed-tab list extended to `media`. Total result count now includes hashtag + media counts.
- Tests: existing `tests/Bridges/WPMediaVerseBridgeTest`, `tests/Hashtags/HashtagServiceTest`, `tests/Search/SearchControllerTest`, and `tests/Search/SearchServiceTest` continue to pass; bridge test is structural and unaffected by the multi-signal detection; search tests do not assert on the new facets.

### Feed (production)

- **Feed (production)** — Composer gains Event, Voice, AI tools + chip privacy selector. Home feed gains For you / Following / Spaces / Network filter tabs with per-tab counts + empty states. Post card gains Share action with Repost / Quote / Copy-link modal. Sidebar widgets gain per-row Follow chips + This-week caption + unread-space dots.
- `templates/partials/composer.php` — five composer tools (Image, Poll, Event, Voice, AI helper) plus a chip-style privacy popover (Public / Followers / Only me) replace the native `<select>`. Inline error band + retry CTA + disabled textarea while submitting.
- New partials `composer-event-modal.php`, `composer-voice-modal.php`, `composer-ai-modal.php`, `share-modal.php` rendered in the home feed shell.
- `FeedService::home_feed( $user_id, $cursor, $per_page, $filter )` accepts `for-you | following | spaces | network`. New `FeedService::home_feed_counts()` powers per-tab badges (24h window). New REST routes `GET /buddynext/v1/feed/home?filter=` (enum-validated) and `GET /buddynext/v1/feed/counts`. New `PostService` types `event` + `voice_room`.
- `WidgetService::suggested_follows()` returns `follow_status` per row (`unfollowed | requested | following`); cache key bumped to invalidate. Per-row Follow chip wired via the existing `buddynext/follow-button` store. Trending Topics gains a "This week" caption. Your Spaces shows an unread-count dot when `bn_space_members.unread_count` is non-zero (column-detected; no-op gracefully when the column is absent). Each widget renders an empty state ("No trending topics yet" / "We'll suggest people once you've completed onboarding" / "Join your first space").
- Three new icons: `mic.svg`, `sparkles.svg`, `bar-chart-2.svg`.
- New CSS for chip privacy popover, composer error band, composer modals, home-feed filter tabs, feed skeleton, share modal, sidebar caption/empty/unread dot.
- New tests: `tests/Feed/FeedServiceTest` covers each filter (`for-you`, `following`, `spaces`, `network`), unknown-filter fallback, and counts shape. `tests/Feed/FeedControllerTest` covers `?filter=` enum + `/feed/counts` shape + auth.

### Notifications (production)

- Every notification type now renders human-readable copy via the new `NotificationMessageService`. The fallback "memberX sent you a notification" string is gone. Exhaustive message map is documented in `docs/specs/NOTIFICATION-MESSAGES.md` covering 30+ types across social graph, feed activity, spaces, messages, moderation, growth, and bridges. Adding a new type now requires adding a switch case + spec row + test — no template changes.
- `NotificationMessageService::compose()` is the single source of truth used by `templates/notifications/index.php`, `GET /me/notifications` (response now includes `message`, `url`, `icon`, `tone`, `label`, `actor_name`), the nav-dropdown partial, and the email-token resolver.
- Group-collapse copy uses `_n()` so "X and N others" renders correctly in every plural form.
- Empty / error states — each filter tab (All / Unread / Mentions / Comments / Reactions / People / Spaces / Messages) renders its own empty copy + emblem + CTA. A REST-failure error block surfaces a retry button.
- Unread badge reactive — Interactivity store exposes `state.unreadCount`, `state.unreadLabel`, `state.badgeHidden` with 99+ cap. Mark-as-read / mark-all-read use optimistic UI with rollback toast on 4xx. Mobile nav badge now uses the same store.

### Profile (production)

- **Profile (production)** - Edit profile now has master Save with sticky save bar, dirty-state guard, field-level validation. View renders all 25 saved fields incl. Work/Education timelines + social chips + interests tag cloud. Other-user view gets Follow / Connect / Message / More-options menu with Mute / Block / Report wired.
- `templates/profile/edit.php` wraps every section in a single `<form data-wp-on--submit=actions.saveProfile>`; sticky save bar renders dirty / saving / saved status pills; display name flagged red on blur when blank; website + social URL fields show inline error text and a red outline when invalid; beforeunload guard prevents accidental navigation while dirty; page title is now `Edit Profile : {display_name}` via the document_title_parts filter wired in PageRouter.
- `assets/js/profile/store.js` ships a real `saveProfile()` flow that POSTs the full payload, handles 200 / 422 / 5xx with field-level error mapping; adds `markDirty`, `validateField`, `confirmCancel`, plus the report-modal and block-confirm modal actions (`openReport`, `setReportReason`, `setReportNotes`, `submitReport`, `confirmBlock`, `closeBlockConfirm`); follow / unfollow / connect / mute / block now use optimistic UI with rollback on REST 4xx/5xx; every action emits a toast via the shared `bnToast` helper.
- `includes/Profile/ProfileController.php::update_profile()` now validates the payload before persistence, returns `{ saved: false, errors: { field: message } }` with status 422 on validation failure, normalises bare-host URLs by prefixing https://, caps long-form fields at sensible lengths (bio 1000 / headline 160 / location 120 / pronouns 40), and round-trips the saved profile in the 200 response payload.
- `templates/profile/view.php` renders inline Work Experience and Education timeline cards, a Community Interests tag cloud linking to `/activity/hashtag/{slug}/`, brand-coloured Social Link chips below the hero meta row (Twitter / LinkedIn / GitHub / Instagram / YouTube), and pulls the website link rel attribute to `nofollow noopener noreferrer ugc` per spec.
- New partials `templates/partials/report-modal.php` and `templates/partials/block-confirm-modal.php` replace any native `confirm()` / `alert()` paths for the destructive Block flow and the category-driven Report flow; report POSTs to `/buddynext/v1/reports` with `{ object_type: 'user', object_id, reason, notes }`.
- `tests/Profile/ProfileControllerTest.php` adds full-payload happy path, blank display_name 422, invalid website URL 422, empty website passthrough, and protocol-less URL normalisation tests (10 tests / 24 assertions).
- `includes/Core/AssetService.php` registers `@buddynext/profile` as a shell-dialog consumer so the store can import `bnToast` cleanly.

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
