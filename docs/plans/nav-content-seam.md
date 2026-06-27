# Plan: Uniform Nav Content Seam — single profile + single space tab menus (v3)

Status: PLAN (no code yet). Lands in **1.0.4**, coordinated free + pro, real
implementation flow. v3 reconciles the doc after the SSR-active + transport-hydration
pivot (v1 pre-render-all → v2 lead-dev review → v3 transport + scope-tightened).

## Scope (exact)
ONLY the two per-entity tab menus: the tab nav on a SINGLE member profile
(`/members/{slug}/...`) and on a SINGLE space hub (`/spaces/{slug}/...`) - the registry
`profile` and `space` surfaces. UNTOUCHED (not "a menu on a single profile/space"): the
global community rail (`buddynext_rail_items`), context-nav on integrations' own external
pages (`buddynext_context_nav`), mobile global nav, admin tabs (`AdminHub`).

## Goal
ONE uniform nav API used by EVERY template — this is the whole reason for the work. No
template hand-rolls its own nav/header: each space + profile template renders its tabs
through the shared registry + the shared header part, and its body through the one panel
seam. For the two per-entity menus this means one dogfooded mechanism for primary tabs AND
one level of sub-tabs: register a tab with a `render` callable; the surface SSRs only the
active panel and hydrates switches via the transport. Core panels, Pro's Portfolio/Suite
panels, and a third-party panel all use the EXACT same path - our code is the model devs copy.

Litmus test for every commit: could a NEW space/profile template be added that gets the full
nav + tabs by calling the one shared API, writing ZERO nav/header markup of its own? If a
template still hand-rolls nav (today `home.php` does), the goal is not met yet.

## Principles
- **Dogfood:** core + Pro use the same seam third parties use. No privileged path.
- **Single source of truth:** ONE renderer for both surfaces. No per-surface duplicate logic.
- **Simplest possible API:** a developer supplies ONLY a `render` callable that echoes
  server HTML. No reactive bindings, no helpers, no route wiring, no Interactivity contract.
- **Performance:** SSR the ACTIVE panel only - never pre-render all panels. The reactive
  switching lives in the transport (router), not in pre-rendered hidden DOM.
- **No dups / no over-build:** exactly TWO levels (tab + sub-tab); reuse the existing sub-nav
  LINK component (`nav-subnav.php`); one URL scheme; one `render` contract.
- **No back-compat shims** (early dev): delete old paths, but only AFTER every consumer
  (incl. Pro) is migrated - nothing breaks mid-flight.

## Model (both surfaces, identical)
1. **Every tab/sub-tab is a real URL:** `/{members|spaces}/{slug}/{tab}/{sub}/`. The URL is
   derived from surface + subject + the item `id` (the id IS the slug). An optional `url`
   callable is ONLY for tabs that link elsewhere (external / partner page).
2. **SSR only the active panel:** `PanelRenderer::render_panels()` resolves the active item
   (+ active sub via `NavContext->sub`) and calls its `render($ctx)`. Inactive panels are
   NOT rendered. Render 1, not N - cost is flat regardless of how many integrations add tabs.
3. **Transport hydration (the reactive feel):** the Interactivity router (the
   `@buddynext/navigate` client-nav layer, matured) intercepts a tab/sub-tab click, fetches
   the target URL's panel region, and swaps it - no full reload. Core, self-integrations
   (Jetonomy, Pro Portfolio), and third parties ALL ride this one transport.
4. **Graceful degradation:** tabs are real `<a href>`. Router/JS off = a normal full-page
   load to the tab URL (still SSRs only the active panel). Identical content either way.

This RETIRES the profile pre-render-all + reactive-reveal model (its only "instant" feel
came from pre-paying every panel's cost). Profile converges to the space model. The JS
reactive reveal store is removed/superseded by the router.

## Contract (NavItem)
- ADD `render => callable(NavContext $ctx): void` - echoes the panel HTML. The whole API.
- ADD `NavContext->sub` - the active sub-tab slug (so one `render` paints the right screen).
- DROP `tab` - redundant under the URL model (id = slug). KEEP `url` ONLY as the optional
  link-out override. KEEP `parent` (sub-tab nesting), `count`, `condition`, `capability`,
  `priority`, `before`/`after`, `hide_empty`, `icon`, `label`.
- Trim dead: the `global` surface and the `rail`/`context` LAYERS (never resolved). NOTE:
  the dead registry `context` LAYER is NOT the live `buddynext_context_nav` FILTER - the
  filter stays untouched.

## Validated against the existing integrations (how each maps to v3)
- **GamificationAchievements (profile):** tab + after-hook panel + `condition`/`count` →
  panel becomes the item `render`; rest unchanged. One-to-one.
- **JetonomyBridge (both surfaces):** profile + space discussions panels (today hardcoded in
  `profile-tab-panel.php` and a `home.php` branch that does `new JetonomyBridge()`) → become
  `render` callables; the hardcoded branches + direct instantiation are DELETED (fixes the
  tight coupling). On-demand forum provision stays a `template_redirect` (orthogonal); the
  discussions `render` shows the linked-forum or empty/provision state.
- **Pro SuiteProfile / Portfolio (dynamic sub-tabs):** already sets `url => /members/{slug}/{key}/`
  on each sub-tab with "JS off → URL server-renders the panel" - i.e. already the v3 URL
  fallback. Migrates to: drop `tab`, render the ACTIVE sub-panel only (not all).
  TWO handling rules the renderer MUST implement (surfaced by this case):
  1. **Parent with no `render` → render its FIRST child.** A primary item that owns no panel
     (Portfolio) falls through to its first sub-tab's `render`; the bare `/portfolio/` URL deep
     -links there.
  2. **Dynamic sub-tabs carry a per-item `render` closure.** `add_subnav()` already generates
     one child per member panel via `buddynext_nav_items`; under v3 each generated child gets
     its OWN `render` (capturing that panel's data), replacing the monolithic `render_panel`.
- Preserved unchanged for all: `count`, `condition`, `capability`, and dynamic injection via
  `buddynext_nav_items`.

## Blast radius (audited both repos - migrate before delete)
- `buddynext_part_profile_tab_panel_after` consumers: free `GamificationAchievements`,
  **pro `SuiteProfile`** → both move to `render`; THEN the hook is deleted.
- `buddynext_register_nav` consumers: `ProfileNav`, `SpaceNav`, `JetonomyBridge`,
  `GamificationAchievements`, **pro `SuiteProfile`** → each panel becomes a `render`.
- `assets/js/profile/store.js` reactive reveal (`data-tab-panel`/`tabSlug`/`isActiveTab`)
  → REMOVED/superseded by the router transport (intentional, not a break).
- `tab` field readers → none after the migration (it is dropped).
- `buddynext_rail_items`, `buddynext_context_nav` → UNTOUCHED.

## File-by-file
| File | Change |
|---|---|
| `includes/Nav/NavItem.php` | add `render`; drop `tab`; keep `url` (link-out only); trim dead `global` surface + `rail`/`context` layers |
| `includes/Nav/NavContext.php` | add `->sub` |
| `includes/Nav/PanelRenderer.php` (new) | the ONE `render_panels()` - SSR the active panel region only; emits the router swap-target wrapper (uses `buddynext_nav_panel_id` for the region id) |
| `includes/Nav/Providers/ProfileNav.php` | core profile panels → `render`; tabs become URLs |
| `includes/Nav/Providers/SpaceNav.php` | core space panels → `render` |
| `includes/Bridges/JetonomyBridge.php` | discussions via `render` (both surfaces); remove the hardcoded `home.php` branch + direct `new JetonomyBridge()` |
| `buddynext-pro/includes/Suite/SuiteProfile.php` | Portfolio tab + sub-tabs + panel → `render`; drop `tab`; drop the after-hook (COORDINATED) |
| `templates/profile/view.php` | call `render_panels()`; drop the pre-render-all panel include |
| `templates/parts/profile-tab-panel.php` | removed (logic folds into `PanelRenderer`) |
| `templates/spaces/home.php` | call `render_panels()`; delete the if/elseif + Jetonomy branch |
| `templates/parts/nav-subnav.php` | reused as-is for sub-tab LINKS (the nav, not the panel) |
| `includes/Core/PageRouter.php` | add `/{...}/{tab}/{sub}/` routing + `bn_*_sub_action`; the existing space `{action}` rewrite already routes unknown tabs to the surface template |
| `assets/js/profile/store.js` | reactive-reveal logic removed/superseded by the router transport |
| `assets/js/shell/navigate.js` (+ `@buddynext/navigate`) | the transport that swaps the active panel region on tab/sub-tab nav |
| `docs/website/developer-guide/47-nav-api.md` | rewrite: one `render` recipe (tab + sub-tab + content), SSR-active + transport, core/Pro use it |

## Migration order (incremental, each its own commit, browser-verified, all in 1.0.4)
1. `render` contract + `NavContext->sub` + `PanelRenderer` (SSR-active). Migrate ONE space
   panel (`about`) end to end through the surface template. Prove the active-only path.
2. Remaining space panels → `render`; delete the `if/elseif` + the Jetonomy `home.php` coupling.
3. Profile core panels → `render`; profile converges to URL + SSR-active (drop pre-render-all).
4. **Pro `SuiteProfile` + free `Gamification` → `render`; THEN delete the after-hook + drop `tab`.**
5. Sub-tab routing (`{tab}/{sub}/` + `->sub`); migrate the profile network sub-tabs + a space sub-tab.
6. Wire the transport (router) to swap the active panel region on tab/sub-tab clicks; verify
   no-reload + graceful full-load fallback. Drop dead surfaces/layers; rewrite the doc.

## Verification (per panel/sub-tab, both surfaces, before each commit)
- The active panel renders correctly (functionally equivalent to today) on desktop + 390px + dark.
- Deep-link to any tab/sub-tab URL SSRs the right panel (no JS needed).
- With the transport on: tab/sub-tab switch swaps the region with NO full reload; with it off:
  a normal full-page load to the URL shows the same panel.
- ONLY the active panel hits the DB (confirm inactive panels are not rendered).
- **Pro Portfolio tab + its sub-tabs behave identically.**
- A sample third-party tab + sub-tab with content works with ZERO core edits.
- Admin overrides still hide/relabel/reorder; rail + context-nav + mobile nav untouched.
- Zero console errors; `bin/check.sh` green on free + pro.

## Routing reality (discovered during Phase 2 - corrects the panel scope)
Not every space tab renders through `home.php`. `PageRouter::get_template_for` routes the
space sub-action to a template:
- `members` -> `spaces/members.php` (dedicated full template)
- `moderation` -> `spaces/moderation.php` (dedicated full template)
- `settings` -> `spaces/settings.php`, `admin` -> `spaces/admin.php`
- everything else (`feed`, `about`, `media`, integration tabs like `discussions`) -> `spaces/home.php`

So the `home.php` panel seam only governs **feed / about / media / discussions**. The
`home.php` `members` + `moderation` if/elseif branches are effectively DEAD for clean URLs
(those URLs load the dedicated templates, never `home.php`). Consequences:
- **Seam migrations (home.php):** about (done), media (done); **feed** and **discussions** remain.
- **members / moderation:** a SEPARATE decision, NOT a home.php render. Either (a) leave them
  as dedicated full-page templates (they are "pages", not in-hub panels) and just delete the
  dead `home.php` branches, or (b) re-route them through `home.php` + the seam for true
  uniformity. Pick before touching them. Do NOT add a SpaceNav render for members/moderation
  while they route to dedicated templates - it would be dead code (never invoked via the bridge).

## Uniform header/nav call (discovered Phase 2 - the real unification)
`parts/space-header.php` IS the uniform header+nav call: from just space_id + viewer it
resolves membership + stats + the registry nav tabs, then delegates to space-hero.php ->
nav-bar.php. `spaces/members.php` and `spaces/moderation.php` already use it. BUT
`spaces/home.php` does NOT - it hand-rolls a SECOND copy of the whole header inline
(membership 101-124, stats 572-594, nav context 533-534, hero call 595-613). So the nav is
uniform for members/moderation and duplicated by home.php.

Target (cleaner than per-panel migration):
1. A shared space-view-context resolver (membership + stats + nav + gating, computed once).
2. EVERY space template renders the header via `space-header.php` (home.php included - delete
   its inline header copy).
3. Body differs: the panel SEAM for home.php tabs (feed/about/media/discussions); the
   dedicated page for members/moderation. Header = one uniform call; body = seam or page.
This removes the home.php<->space-header.php duplication AND makes the nav exactly one call,
and it settles members/moderation (they keep dedicated bodies but already share the header).

## Progress log (DONE)
- Phase 0 (`601bf817`): `NavItem.render` contract + `PanelRenderer` (SSR active panel) + `NavContext->sub` + 8 tests. Full Nav suite 52/52.
- Phase 1 (`75db80ed`): space **About** via the seam; shared `SpaceService::get_object()` + `display_meta()`; legacy About branch deleted; browser-verified (desktop/390/dark, feed+members regress OK).
- Phase 2a (`c81f87a9`): space **Media** via the seam; legacy Media branch deleted; browser-verified (media-on gallery, media-off → feed fallback).
- Plan corrections (`9d8e72ac`, `81a89052`, `6f7512a1`): routing reality (members/moderation use dedicated templates) + the uniform-header finding + "one uniform nav API for every template" set as the governing goal.
- Reverted: a members-panel seam attempt (members routes to `spaces/members.php`, not `home.php` — a SpaceNav members render would be dead code). Verify-per-item caught it via missing `.bn-sh-body`.
- Phase 2 header step (THIS commit): `home.php` adopts `parts/space-header.php` for the hero+nav and DROPS its inline hero copy (stats build + `space-hero.php` call); dead `$is_admin_mod` + `$bn_notif_pref` removed. `NavRegistry::resolve()` now memoizes per context signature, so the header part + the body resolving the same space nav no longer double-runs count callables (some are DB queries) — the no-dup gate for the double resolution. +2 tests (memo + reset clears it); Nav suite 54/54. Browser-verified on Docker (home/about/members, desktop + 390 + dark, 0 console errors): home + members + about all render the ONE uniform header with the correct active tab.

## OWNER DECISION (2026-06-27) — sidebar is uniform too, not just the header
Owner feedback after the header step: switching Feed/About → Members/Moderation "feels off" because the right rail VANISHES. Root cause: the shell renders the right column only when something hooks `buddynext_right_sidebar`; `home.php` registers the space sidebar widgets (2-col), but `members.php`/`moderation.php` register NOTHING (full-width, no rail). So the main column snaps wide on those tabs.
Decision: **the right sidebar is part of the uniform shell.** EVERY space tab keeps the same 2-column shell + the same sidebar; only the WIDGETS vary per tab (e.g. drop the redundant Members-preview card on the Members tab). This RESOLVES the deferred members/moderation routing question — they stay dedicated bodies but adopt the uniform shell + sidebar (header was already shared). Implementation: extract the space sidebar registration out of `home.php`'s inline closure into a shared `parts/space-sidebar.php` (computes mods/members/contributors/about once, registers the `buddynext_right_sidebar` cards, takes `active_tab` for per-tab variation); `home.php`, `members.php`, `moderation.php` each call it. No card rendered by two code paths (no-dup gate).

DONE (Phase 2 sidebar step, this commit): new `parts/space-sidebar.php` (self-contained: resolves space + mods + members preview + contributors from `space_id`/`viewer_id`/`active_tab`, registers the 4 rail cards; `bn_sh_avatar_tone` moved here). `home.php`, `members.php`, `moderation.php` all call it → every space tab now renders the SAME 2-col shell + sidebar (was: members/moderation full-width, no rail). The Members-preview card self-suppresses on the Members tab (roster IS the body). Removed from `home.php` in the same commit: the inline sidebar closure, its sidebar-only data fetches (`$bn_to_objects`/`$sidebar_members`/`$top_contributors`/`$member_count_fmt`/`$privacy_*`/`$bn_display_meta`/`$bn_post_count`/`$bn_media_count`), the dead `members`/`moderation` body branches (those URLs route to the dedicated templates — Phase 3 deletion brought forward) and their now-orphan vars (`$can_moderate`/`$bn_mod_count`/`$bn_pending_count`/`$bn_full_members`/`$bn_member_filter`). Net: `home.php` body is now ONLY its real panels (feed/about/media/discussions) + the uniform header + the uniform sidebar; the wasted top-contributors/full-roster queries it ran on every load are gone. Browser-verified on Docker (feed/about/members/moderation, desktop + 390 + dark): one uniform header + sidebar on every tab, correct active tab, 0 console errors.

## PENDING (resume here) — Phase 2: one uniform header/nav for every space template
Findings from the Phase 2 scoping (use these on resume):
- `SpaceMemberService::get_role()`/`get_status()` are `wp_cache`-backed, so membership resolved in BOTH `home.php` body and `space-header.php` is cache-cheap — the dup is LOGIC, not queries. So no heavy resolver is strictly required.
- `home.php` body STILL needs the resolved nav for the panel seam (`$bn_space_ctx` / `$bn_space_nav` / `$bn_nav_items` / `$bn_panel_item`) — keep those.
- `buddynext_nav()` → `NavRegistry::resolve()` (`includes/Nav/NavRegistry.php:79`) — CHECK whether it memoizes per context; if not, `home.php`-body + `space-header.php` resolve nav twice (cheap CPU, but memoizing `resolve()` per context id removes it cleanly).

Resume recipe (the minimal uniform-header step):
1. In `home.php`, DELETE the header rendering only: the stats build (~572-594) and the `space-hero.php` call (~565-613). KEEP the body's nav resolution + membership/gate/feed state.
2. Replace that block with `buddynext_get_template( 'parts/space-header.php', array( 'space_id' => $space_id, 'active_tab' => $active_tab ) )`.
3. (no-dup polish, optional) extract `SpaceMemberService::resolve_membership($space_id,$viewer_id): array` (memoized) and use it in BOTH `home.php` and `space-header.php`; consider memoizing `NavRegistry::resolve()`.
4. CONFIRM every space template uses the one header/nav call: home (new), members ✅, moderation ✅, settings, admin.
5. TEST per the litmus: home.php header/nav identical to today (counts, active tab, hero actions); members/moderation unchanged; About/Media bodies still render; 390/dark; 0 console errors; `bin/check.sh` green.

Then Phase 3 (feed + discussions bodies via the seam; delete dead members/moderation `home.php` branches), Phases 4-7 per the task list.

## Task list (revised — UNIFORM NAV API across EVERY template is the goal)
CORE PRINCIPLE (why we build this): every space + profile template renders its nav through
ONE uniform nav API — the registry + the shared header part — NEVER a hand-rolled per-template
copy. The body differs per template; the nav never does. A template that hand-rolls its own
nav/header (today: `home.php`) is the defect this work removes.
Each phase = its own browser-verified commit. All land in 1.0.4.

### Phase 0 — Foundation ✅ DONE (`601bf817`)
`NavItem.render` content seam + `PanelRenderer` (SSR the active panel only) + `NavContext->sub`
+ 8 tests. Additive, nothing wired.

### Phase 1 — Space body seam, proof ✅ DONE (About `75db80ed`, Media `c81f87a9`)
`home.php` bridge routes a tab's `render` via `PanelRenderer`; About + Media migrated; shared
`SpaceService::get_object()`/`display_meta()`; legacy branches deleted; browser-verified.

### Phase 2 — ONE UNIFORM HEADER/NAV + SIDEBAR FOR EVERY SPACE TEMPLATE ✅ DONE
- [x] `home.php` adopts `parts/space-header.php` for the hero+nav — inline duplicate DELETED (`627d6b3e`).
- [x] `NavRegistry::resolve()` memoized per context, so header + body don't double-run count callables (`627d6b3e`).
- [x] Uniform SIDEBAR: shared `parts/space-sidebar.php`; home + members + moderation all call it
      (members/moderation were full-width/no-rail) — owner decision 2026-06-27. Members-preview card
      self-suppresses on the Members tab.
- [x] CONFIRM every IN-HUB space tab renders header+nav+sidebar via the ONE call: home, members,
      moderation. (settings + admin are dedicated management drill-in pages — a "Back to space" header,
      no in-hub tab nav by design — intentionally OUT of this scope.)
- [x] TEST: every space tab's header/nav/sidebar identical (counts, active-state); browser-verified
      desktop + 390 + dark, 0 console errors; zero dup (single header path + single sidebar path).

### Phase 3 — Finish the `home.php` body seam
- [x] Delete the now-dead `home.php` `members`/`moderation` branches (done early in the Phase 2 sidebar commit — they route to dedicated templates).
- [x] `discussions` (Jetonomy) → `render`: `JetonomyBridge::render_space_discussions_panel()` is now the
      space discussions item's `render` callable (self-contained: resolves forum threads + forum/provision
      context + can_post from space_id + viewer). Deleted the `home.php` discussions data setup + body branch
      + the `new JetonomyBridge()` coupling. The integration's PANEL is now on the SAME `render` contract as
      core About/Media — tab + panel declared together in the bridge. Browser-verified (empty-state + provision
      CTA render identically under the uniform header + sidebar; 0 console errors). Nav suite 54/54.
- [x] `feed` → `render`: `SpaceNav::render_feed_panel()` is the Feed item's `render` (self-contained:
      recomputes membership/can_post/archived + fetches pinned + hydrated feed from the context). The
      archived banner folded into `space-feed-panel.php` (`is_archived` arg) so the callable stays echo-free.
      `home.php` body is now JUST `gate CTA` OR `render_panels` — every in-hub tab (feed/about/media/
      discussions) paints through the registry seam; the feed data fetch only runs when Feed is the active
      panel (not when viewing About). Added an active-tab normalize (unknown/hidden tab → feed, the floor),
      which also subsumes the old media-off reset. Removed from `home.php`: the feed fetch, `$bn_can_post`,
      `$bn_space_archived`, `$bn_current_user`, `$bn_feed_service`, the `$bn_panel_item` lookup + media reset.
- [x] TEST: feed renders via the seam (composer + empty state, pixel-identical); stale-tab URL falls back to
      Feed (header + body agree); About regression OK; 0 console errors. Nav suite 54/54.

**Phase 3 COMPLETE — `home.php` no longer hand-rolls ANY panel.** Every space panel (core Feed/About/Media +
Jetonomy Discussions) flows through one `render` contract; the integration uses the EXACT same seam core does.

### Phase 4 — Profile surface (same uniform pattern)
SCOPING FINDING (2026-06-27, before any code): the profile surface is BIGGER + RICHER than spaces was —
plan this against the reality below before touching it.
- `templates/profile/view.php` fetches ALL ~10 panels' data on EVERY load (posts/scheduled/about/replies/
  media/likes/discussions/followers/following/connections + pending follow/connection requests), then
  `templates/parts/profile-tab-panel.php` (500 lines) PRE-RENDERS every panel into the DOM.
- `assets/js/profile/store.js` is **2242 lines** and already implements full CLIENT-SIDE tab navigation:
  `setTab` pushState pretty URLs, `popstate`/`initView` Back/Forward, instant reveal via
  `data-wp-bind--hidden="!state.isActiveTab"` (`activeTab===tabSlug`). So profile ALREADY has a
  transport-like client-nav — it reveals pre-rendered DOM instead of fetching.
- ProfileNav registers tabs with `tab` (reactive), metrics (followers/following/connections) as `metric`
  pills, and a `network` parent + sub-nav (connections/followers/following carry `url`). No `render` on any.
- Bridges on this surface: JetonomyBridge profile Discussions (`tab=>'discussions'`), Gamification
  Achievements (after-hook) — both pre-render into the panel.

CONSEQUENCE — the migration is ATOMIC and has an interim-regression risk:
- Converting to the SSR-active `render` seam means deleting the 500-line pre-render panel + gutting the
  2242-line store's reveal/tab-switching. WITHOUT the Phase 7 transport, profile tab switching regresses
  from INSTANT client reveal (today) to FULL PAGE LOAD. Spaces had no such instant switching to lose;
  profile does. So Phase 4 alone is a UX downgrade on the most-used member surface.
- Recommendation: land Phase 4 (SSR-active renders) and Phase 7 transport TOGETHER for the profile (or
  generalize the `@buddynext/navigate` transport first, then cut profile over), so there is no interim
  regression. Do NOT ship Phase 4-only on profile.

DONE (cutover prep — render-ready parts extracted): the panel markup from the 500-line
`profile-tab-panel.php` is now wrapper-free, self-contained parts under `templates/parts/profile/`:
`posts-panel.php` (posts/scheduled/likes), `replies-panel.php`, `discussions-panel.php`, `about-panel.php`
(self-fetches profile fields + renders about-cards + the generic field-engine detail sections), and
`people-panel.php` (followers/following/connections + the owner pending-request inbox). These are the parts
the `render` callables will include — same pattern spaces uses (space-feed-panel.php etc.). Harmless until
wired (nothing includes them yet → profile unchanged). Lint + WPCS clean.

WRINKLE found while wiring (do this in the flip): the profile's followers/following/connections are METRIC
pills + `network` sub-nav CHILDREN (parent=network), not top-level primary tabs. `PanelRenderer::render_panels`
only searches top-level primary items, so `active_tab='followers'` paints nothing. The flip must extend
PanelRenderer to resolve a sub-nav child by the active id (this is the Phase 6 sub-tab resolution pulled
forward), OR register followers/following/connections as render-bearing items the renderer can find. So the
profile flip ENTANGLES Phase 6 — it is materially larger than the space cutover.

Remaining flip steps (atomic — do together, fresh context, full matrix verify):
- [ ] PanelRenderer: resolve a sub-nav child (or metric panel) by active id → its render (Phase 6 pulled in).
- [ ] ProfileNav: tabs `tab`→`url` (clean /members/{slug}/{tab}/) + a `render` per item that self-fetches and
      includes its part (posts/scheduled/replies/media[→media-tab.php]/likes/about + people for the network
      children); About tab keeps its content `condition`.
- [ ] JetonomyBridge: profile Discussions `render` → `parts/profile/discussions-panel.php`.
- [ ] `view.php`: drop the ~150 lines of bulk panel fetch + the about_html build + the `profile-tab-panel.php`
      call; render the active panel via `render_panels()`; keep hero + nav-bar + sidebar + modals.
- [ ] DELETE `templates/parts/profile/`... no — DELETE `templates/parts/profile-tab-panel.php`.
- [ ] `store.js`: remove the reveal logic (`isActiveTab`/`tabSlug`/`setTab` tab-switch/`data-tab-panel`); the
      generalized transport now drives tab switching (profile tabs are url-links → shell transport swaps the
      whole region). KEEP the hero action logic (follow/connect/block/report/mute).
- [ ] TEST matrix (the reason this needs room): each tab via its URL; deep-link paints the right panel;
      active-only DB; owner vs visitor; empty vs populated; pending follow/connection sub-sections; metric
      pills; 390/dark; client-nav ON (no-reload tab switch) AND OFF (full-load) both correct.

## Phase 4 FLIP — MICRO TASK LIST (execute top-down; check [x] + add a NOTE as each lands)
Decomposition: **Step A** (PanelRenderer child/metric resolution) is additive + safely separable → its own
commit. **Steps B–F** are the ATOMIC flip (view.php can't half-render) → one verified commit. Step G = verify.

### A. PanelRenderer resolves sub-nav children + metric panels (additive — own commit) ✅ DONE
- [x] A1. `render_panels()`: after the top-level primary match, if none matches `$active`, search each
       primary's `->children` for an id match → call that child's `render_panel`.
- [x] A2. Also accept a metric-layer panel: if still unmatched, search the `metric` layer for an id match.
- [x] A3. Unit tests: `test_active_child_id_renders_that_child` + `test_active_metric_panel_renders`.
- [x] A4. Lint + WPCS clean, Nav suite 57/57; committed.

### ⛔ BLOCKER FOUND (2026-06-27) — the flip is a COORDINATED Free+Pro change
Steps B+C+D are written + green locally but are STASHED, not shipped, because deleting `profile-tab-panel.php`
(Step E) / removing the reveal (Step F) breaks TWO after-hook consumers that still use the reveal model:
- FREE `includes/Profile/GamificationAchievements.php` — injects the Achievements panel on
  `buddynext_part_profile_tab_panel_after` with `data-tab-panel` + `data-wp-bind--hidden="!state.isActiveTab"`.
- PRO `buddynext-pro/includes/Suite/SuiteProfile.php` — injects the Portfolio panel the SAME way (active in
  the smoke container, so this breaks today, not hypothetically).
The render-active model and the pre-render reveal model CANNOT coexist in one view: render_panels paints only
the active core panel (unbound to activeTab), so it won't hide when an integration reveal-tab activates, and
the integration panels won't render at all once view.php stops firing the after-hook. So Phase 5 (migrate
Gamification + Pro SuiteProfile to `render`, then DELETE the after-hook) MUST land in the SAME coordinated
change as Steps E+F. This is a Free+Pro lockstep release.

STASH: `git stash list` → "nav-seam Phase4 B+C+D …" holds ProfileNav (url+render), JetonomyBridge (profile
discussions render), view.php (→render_panels). Pop it to resume the flip. Step A + the parts are committed,
so only this glue is stashed. Repo is back on the working reveal model (nothing broken).

REVISED ORDER for the coordinated flip (all in one verified pass, free+pro):
1. Pop the stash (B+C+D core render seam).
2. Phase 5a — FREE GamificationAchievements: drop the after-hook usage; give its Achievements nav item a
   `render` → an achievements panel part (mirror the discussions-panel pattern).
3. Phase 5b — PRO SuiteProfile: Portfolio parent → first-child (PanelRenderer Step A already supports it) +
   each dynamic sub-tab gets its own `render`; drop `tab`; drop the after-hook usage. (Pro repo.)
4. Step E — delete `templates/parts/profile-tab-panel.php` (now no consumers).
5. Step F — remove the reveal from `store.js`; nav-bar metric pills/hero → plain links.
6. Step G — full matrix verify, free + pro, client-nav on/off.
GATE: `grep -rn buddynext_part_profile_tab_panel_after` across BOTH repos must be ZERO before Step E.

### B. ProfileNav → url + render (part of the atomic flip) — ✅ written, ⛔ STASHED (see blocker)
- [ ] B1. Add a `tab_url($uid,$tab)` helper (clean `/members/{slug}/{tab}/`, posts = base).
- [ ] B2. primary tabs: drop `tab`, add `url` + `render`. posts→posts-panel(kind=posts); scheduled→
       posts-panel(kind=scheduled, owner-only via existing condition); replies→replies-panel;
       media→`partials/media-tab.php`(condition MediaClient); likes→posts-panel(kind=likes).
- [ ] B3. About: register here (not view.php) with `condition` = has-about-content + `render`→about-panel.
- [ ] B4. network children (connections/followers/following): keep `url`, add `render`→people-panel(relation).
- [ ] B5. metric pills (followers/following/connections): keep `url` so the hero pills deep-link (no render —
       the panel comes from the network child / Step A2).

### C. JetonomyBridge profile Discussions → url + render
- [ ] C1. profile discussions item: drop `tab`, add `url` (/members/{slug}/discussions/) + `render`→
       `parts/profile/discussions-panel.php` (bridge owns jt_* access, self-fetches user_discussions).

### D. view.php rewrite (the cutover)
- [ ] D1. Resolve nav + active_tab (normalize unknown→posts; parent network→first child as today).
- [ ] D2. Body = `PanelRenderer::render_panels()` (active-only) instead of the bulk `profile-tab-panel.php`.
- [ ] D3. DELETE the bulk panel fetches (recent_posts/replies/likes/scheduled/media/jt_discussions/
       follower_users/following_users/connection_users/pending_*) — each render self-fetches now.
- [ ] D4. DELETE the about_html build + the dynamic About-tab `buddynext_nav_items` filter (moved to B3).
- [ ] D5. KEEP the hero (its relationship state + profile-field data), the right-sidebar registration, the
       report/block modals, and the `buddynext/profile` interactive root (hero actions still need it).
- [ ] D6. nav-bar now renders url tabs (ProfileNav change) — confirm active state + branch still light up.

### E. Delete `templates/parts/profile-tab-panel.php` (folded into the parts/renders).

### F. store.js reveal removal — REMAINING (pure cleanup; cutover already verified working without it)
Confirmed-dead targets (the cutover shipped + verified with these still present but UNREACHABLE — no item
carries `tab` anymore, so nothing renders the reactive branches or binds the getters; 0 console errors):
- [ ] F1. `assets/js/profile/store.js`: remove the tab-reveal ONLY — `isActiveTab`/`isActiveBranch` getters,
       the `setTab` action's tab-switch + pushState, `initView`'s tab seeding/popstate, the `data-tab-panel`
       handling. KEEP every hero action (follow/connect/block/mute/report/share) + their state — they are the
       reason the `buddynext/profile` store still loads. Surgical: this is a 2242-line store, so excise only
       the reveal members; re-verify hero actions click through after.
- [ ] F2. Dead reactive branches in the shared nav templates (now unreachable — every item is url-only):
       `nav-bar.php` (the `reactive && url` `<a>` + the `reactive` `<button>` branches → keep only the
       url-link `else`), `nav-subnav.php` (same), `nav-metrics.php` (the `$bn_m->tab` button branch).
       Removing them also lets the `data-wp-class--active="state.isActiveTab"` bindings go.
- [ ] F3. Drop the now-unused `activeTab` seed from view.php's `$bn_pf_ctx` + any `bn-pf-tab-content` JS hook.
- [ ] F4. Grep sweep (both repos): zero `data-tab-panel` / `state.isActiveTab` / `state.isActiveBranch` /
       `actions.setTab` on the profile + space surfaces. Re-verify: profile tabs navigate, hero actions work,
       client-nav on/off both correct, 0 console errors.
DONE (Step F): removed the dead reveal code, both surfaces re-verified (profile + space render identically,
0 console errors, Nav 57/57):
- `assets/js/profile/store.js`: excised the tab-reveal — `isActiveTab`/`isActiveBranch` getters, `setTab`
  action, `initView` callback, and the `syncActiveTabFromUrl`/`pushTabToUrl`/`bnProfileBase` helpers. Kept
  every hero/edit action.
- `nav-bar.php` / `nav-subnav.php` / `nav-metrics.php`: dropped the dead reactive branches (`actions.setTab`,
  `data-wp-class--active="state.isActiveTab"`, `data-wp-bind--hidden`) — every tab/pill/sub-tab is now a plain
  url `<a>` with server-rendered `aria-current`; simplified the `$bn_target`/`$bn_reactive` logic to `->id`.
- `view.php`: removed `data-wp-init="callbacks.initView"` + the `activeTab` context seed.
- Grep sweep (templates + JS): ZERO `actions.setTab` / `state.isActiveTab` / `state.isActiveBranch` /
  `data-tab-panel` remain.
DONE (final vestige): the `NavItem::$tab` field is GONE — removed the constructor param/property, the
`from_array` parse, the `tab:` arg, and simplified the primary-validity check to `url|render`. Converted the
~37 `'tab' => 'x'` Nav test fixtures to `'url' => 'https://t/x/'` and the one dedup assertion off `->tab` onto
`->label`. Sweep: zero `->tab` access and zero `'tab' =>` registrations remain in nav code (the `Admin/*`
`'tab'` arrays are unrelated admin-page tabs). Nav suite 57/57, WPCS + PHPStan clean. `buddynext_nav_panel_id()`
is now unused but kept — it's a documented public nav-API helper for the panel region id.

ZERO DEAD CODE REMAINS on the nav surface: no reveal store members, no reactive template branches, no `tab`
field. Every nav item across profile + space (core + all bridges) is `url` + `render`, riding one
nav-API-driven transport. The whole nav-content-seam plan is complete.

### G. VERIFY (browser, Docker; client-nav OFF default + a pass with it ON)
- [ ] G1. Each tab via its URL deep-link paints the right panel (posts/scheduled/replies/media/likes/about/
       discussions/followers/following/connections); active-only DB (inactive panels don't query).
- [ ] G2. Owner vs visitor (scheduled owner-only; pending follow/connection inboxes owner-only; composer owner).
- [ ] G3. Empty vs populated states each panel.
- [ ] G4. Hero metric pills deep-link to the people panels; network parent + sub-nav active states correct.
- [ ] G5. 390px + dark; 0 console errors; `bin/check.sh` green free (+ pro if touched).
- [ ] G6. client-nav ON: tab switch is no-reload (transport) and active syncs; OFF: full-load to the URL, same panel.

PROGRESS NOTES (append as each lands):
- COORDINATED FLIP LANDED (free+pro), browser-verified on Docker with Pro active, 0 console errors:
  - B/C/D: ProfileNav every tab = clean url + self-fetching `render`; About self-registers only with content;
    JetonomyBridge profile Discussions → render; view.php → `render_panels()` (bulk fetch + about-build +
    About-filter removed). Posts/Network-followers(via Step A child resolution)/Discussions all verified.
  - Phase 5a: free `GamificationAchievements` → `render` (dropped the after-hook + reveal wrapper).
  - Phase 5b: pro `SuiteProfile` Portfolio → parent(url, no panel)+dynamic sub-tabs each with their OWN
    `render` closure (drops `tab` + the after-hook + the reveal wrapper).
  - Step E: DELETED `templates/parts/profile-tab-panel.php` + the orphaned `buddynext_profile_tab_panel_open/
    close()` helpers; gate-grep `buddynext_part_profile_tab_panel_after` → ZERO live consumers across both repos.
  - Result: NO registered nav item carries `tab` anymore (every surface is url+render), so nav-bar/nav-subnav/
    nav-metrics all render plain links (server-side `aria-current`); the reactive `setTab`/`isActiveTab` paths
    are now dead but harmless (0 console errors). Step F = remove that dead reveal code (store.js + the dead
    reactive template branches) — pure cleanup, no behavior change.
- Step A done (committed `964244a2`): `PanelRenderer::render_panels()` now, when no top-level primary matches
  the active id, searches each primary's `->children` then the `metric` layer for an id match that owns a
  render. Lets a profile metric pill / network-child URL (`/members/x/followers/`) paint the people panel
  without Phase 6 routing. +2 tests; Nav suite 57/57.
- Steps B+C+D WRITTEN + green locally (ProfileNav url+render with self-fetching panels; JetonomyBridge profile
  discussions render; view.php → render_panels, bulk fetch + about-build + About-filter removed). Then
  ⛔ STASHED on discovering the Free+Pro after-hook BLOCKER above — shipping them standalone breaks free
  Gamification + Pro Portfolio. The flip must land coordinated with Phase 5 (Gamification + Pro SuiteProfile →
  render, delete the after-hook). Stash holds the B+C+D glue; Step A + the 5 panel parts are committed.
- Decision: did NOT ship breakage. Repo stays on the working reveal model; the coordinated flip is fully
  scoped above (REVISED ORDER) for a focused free+pro pass. Transport (already generalized + committed) means
  that pass lands with NO tab-switch regression once client-nav is enabled.

### Original execution checklist (superseded by the micro list above):
- [ ] ONE uniform header/nav call for the profile template(s); body via `render_panels()`.
- [ ] Migrate ALL profile panels → `render` in ProfileNav (posts/scheduled/replies/media/likes/about +
      followers/following/connections) and JetonomyBridge (discussions); each self-fetches its own data so
      only the active panel queries (the perf win — today every load fetches all 10).
- [ ] Jetonomy profile discussions → `render`; DELETE `profile-tab-panel.php` (folds into the renders).
- [ ] Transport (Phase 7) wired for profile so tab switches stay no-reload; reveal logic removed from
      `store.js` and replaced by the fetch-swap transport (no interim full-reload regression).
- [ ] TEST: each profile tab renders via its URL; deep-link paints the right panel; active-only; owner vs
      visitor; empty vs populated; pending follow/connection request sub-sections; metric pills; 390/dark.

### Phase 5 — Integrations AS THE MODEL, then delete the bolt-on
- [ ] GamificationAchievements → `render` (drop the after-hook usage).
- [ ] Pro `SuiteProfile` → `render` (Portfolio parent→first-child + dynamic sub-tab renders; drop `tab`).
- [ ] DELETE `buddynext_part_profile_tab_panel_after` (free + pro, now unreferenced).
- [ ] TEST: Pro Portfolio + dynamic sub-tabs identical; Gamification works.

### Phase 6 — Sub-tabs
- [ ] PageRouter: `/{tab}/{sub}/` routing + `bn_*_sub_action`; wire `NavContext->sub`.
- [ ] Profile network sub-tabs → child `render`; a worked space sub-tab end-to-end.
- [ ] TEST: space sub-tab URL renders the right child screen; profile sub-tabs unchanged.

### Phase 7 — Transport + cleanup + docs
OWNER DIRECTIVE (2026-06-27): **the navigate transport must be wired THROUGH the Nav API itself, not via
hardcoded element selectors / path regexes.** Today `assets/js/shell/navigate.js` hardcodes nav knowledge:
`syncActiveNav()` targets `.bn-app__rail` / `.bn-mobile-nav` / `.bn-mobile-nav__item--active` by name, and
`isDenied()` hardcodes route regexes (`/edit/`, `/settings|admin/`, `/p/\d+/`, `/checkout/`). The registry is
the single source of truth for nav, so the transport must READ from it:
- Every registry-rendered nav container (rail, mobile bar, the shared `nav-bar.php` tab bar) carries a
  generic marker emitted by the ONE shared renderer (e.g. `data-bn-nav`), and active-state re-sync iterates
  that marker generically + drives `aria-current="page"` (CSS keys off `[aria-current]`, not bespoke
  `--active` classes). The transport never names a specific nav.
- Client-nav eligibility (full-load vs swap) is declared on the NavItem contract / through the nav
  registration (the `buddynext_client_nav_deny` surface filter generalized to a per-item `full_load` flag),
  NOT a hardcoded regex list in the JS. A new nav item/surface opts out by declaration, no JS edit.
- The swap region id comes from the nav/panel layer (`buddynext_nav_panel_id`), not a string literal.
This is the "transport first" work the owner chose (sequence: generalize the nav-API-driven transport →
then cut profile over behind it, so profile never ships a full-reload regression).

KEY INSIGHT (owner, 2026-06-27): the Nav API ALREADY knows the exact nav set per surface (profile vs space)
— every item's id, resolved URL, condition, parent/children. So the transport can be DYNAMIC: the resolved
nav is the data source. The shared renderer emits each registry link with its id + a `full_load` flag from
the item; the transport reads those per-link instead of hardcoding which paths swap vs reload. Result: a
new surface or nav item drives the transport automatically (no JS edit), and when profile is cut over its
registry items light up the transport for free. Concrete pieces:
  1. `NavItem` gains `full_load` (bool, default false) — "this tab is a drill-in page, full-load it."
  2. The ONE shared nav renderer (`nav-bar.php` + rail + mobile) marks the container `data-bn-nav` and each
     link `data-bn-nav-id` + (when set) `data-bn-full-load`; active = `aria-current="page"` (CSS keys off it).
  3. `navigate.js`: `isDenied()` → reads the clicked link's `data-bn-full-load` (falling back to the
     filter-driven `navDeny` surface-base map for partner external bases); `syncActiveNav()` → iterates
     `[data-bn-nav]` generically. No `.bn-app__rail` / `.bn-mobile-nav__item--active` / route-regex literals.

DONE (transport increment 1 — nav-API-driven active sync): every registry-rendered nav container now carries
`data-bn-nav` (rail.php, partials/nav.php mobile + context, parts/nav-bar.php). `syncActiveNav()` rewritten
to iterate `[data-bn-nav]` generically and drive `aria-current="page"` only — the hardcoded `.bn-app__rail`
/ `.bn-mobile-nav` selectors AND the `bn-mobile-nav__item--active` class toggle are GONE. The mobile nav
active state converged onto `aria-current` (CSS `.bn-mobile-nav__item[aria-current="page"]`; server emits it;
no bespoke class). Browser-verified on Docker with `buddynext_client_nav_enabled` on: clicking the rail
Members link client-swaps (no reload — sentinel survived) and the rail active re-syncs Spaces→Members via the
generic marker; 0 console errors. Flag still ships OFF by default.

DONE (transport increment 2 — nav-API/server-driven deny, no hardcoded routes): `NavItem.full_load` (bool)
added to the contract; the shared `nav-bar.php` emits `data-bn-full-load` on a full-load tab. PageRouter now
emits `navDenyPatterns` (the rich-route shapes IT owns — profile edit / space settings+admin / post permalink
/ checkout — built from the LIVE admin-configurable bases, filterable via `buddynext_client_nav_deny_patterns`).
`navigate.js isDenied()` rewritten to (1) read the clicked link's `data-bn-full-load`, (2) apply the server
`navDenyPatterns`, (3) fall back to the `navDeny` surface-prefix map — the four hardcoded route regexes are
GONE. Browser-verified on Docker (client-nav on): `navDenyPatterns` present + built from live bases;
profile-edit/post-permalink match (full-load), normal space URL does not; clicking the space hero Settings
link FULL-loaded end-to-end (no-reload sentinel cleared); 0 console errors. +1 Nav test (full_load round-trips),
suite 55/55. The transport now hardcodes NO routes or selectors — fully nav-API/route-API driven, flag still OFF.
NEXT: profile cutover (Phase 4) behind the generalized transport — its registry tabs light it up, no JS edits.

- [ ] Wire `@buddynext/navigate` to swap the active panel region (no-reload; graceful full-load fallback).
- [ ] Remove `profile/store.js` reactive-reveal; drop the dead `global` surface + `rail`/`context` layers.
- [ ] Rewrite `47-nav-api.md`: ONE `render` recipe; document that EVERY template uses the uniform nav API.
- [ ] Refresh `audit/manifest.json` + contract docs for added/removed hooks.
- [ ] FINAL: `bin/check.sh` green free + pro; full browser smoke — both surfaces, a sample
      third-party tab + sub-tab with ZERO core edits, Pro Portfolio, admin overrides, mobile, dark.

## Dead-code / no-dup GATE (enforced every commit, hard requirement)
A panel migration commit must REMOVE the old path in the SAME commit - never leave the new
`render` AND the old hardcoded branch for the same panel co-existing. Concrete grep checks
that must pass before each commit and as a final sweep (free + pro):
- Each migrated panel: its old `if/elseif` branch / hardcoded `<div>` is gone (no panel
  rendered by two code paths). `grep` the surface template for the slug → only the registry path.
- After Phase 3: `grep -r buddynext_part_profile_tab_panel_after` → ZERO refs (free + pro).
- After contract change: `grep -rn "'tab'\s*=>" + ->tab` in Nav/providers/bridges/pro → ZERO.
- `templates/parts/profile-tab-panel.php` is DELETED, not left as an empty stub.
- Dead `global` surface + `rail`/`context` layers removed from `NavItem` (no orphan constants).
- ONE `render_panels()` - profile and space do NOT each carry their own loop/wrapper copy.
- No leftover reactive-reveal code in `profile/store.js` once the transport lands.
- `buddynext_nav_panel_id` and `nav-subnav.php` REUSED (not re-implemented per surface).
Run a final repo-wide sweep at Phase 5 close: zero references to any deleted hook/field/branch.

## Risk
Touches the two most-used templates, the Nav contract, the profile JS, AND Pro - and changes
profile tab switching from in-page reveal to URL + transport. Mitigation: it lands in 1.0.4
incrementally (one panel per commit, browser-verified each step), every consumer (incl. Pro)
migrates BEFORE any deletion, the transport degrades to plain full-page loads so nothing is
JS-dependent, and we reuse the existing sub-nav link component (no dup). Free + pro tagged together.
