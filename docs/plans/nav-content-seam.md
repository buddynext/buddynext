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
For those two menus, ONE uniform, dogfooded mechanism for primary tabs AND one level of
sub-tabs: register a tab with a `render` callable; the surface SSRs only the active panel
and hydrates switches via the transport. Core panels, Pro's Portfolio/Suite panels, and a
third-party panel all use the EXACT same path - our code is the model devs copy.

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

## Progress log
- Phase 0 (`601bf817`): render contract + PanelRenderer + NavContext->sub + 8 tests.
- Phase 1 (`75db80ed`): space About via the seam; SpaceService::get_object()/display_meta().
- Phase 2a (`c81f87a9`): space Media via the seam.

## Task list (implementation + test + integration-as-model, by phase)
Each phase is its own commit(s), browser-verified before moving on. All land in 1.0.4.

### Phase 0 - Foundation (contract + renderer)
- [ ] NavItem: add `render` + validation; drop `tab`; keep `url` (link-out only).
- [ ] NavContext: add `->sub` (active sub-tab).
- [ ] `PanelRenderer::render_panels()` (new): SSR the ACTIVE panel only; implement the two
      rules - (a) parent-with-no-render → first child, (b) per-item `render` closures.
- [ ] PHPUnit: NavItem `render` validation; render_panels picks active item + sub + parent fallback.

### Phase 1 - Space surface (proof, then full)
- [ ] Wire `spaces/home.php` → `render_panels()`; migrate the `about` panel (proof-of-path).
- [ ] TEST: `/spaces/{slug}/about/` renders; only active panel queries the DB; 390px + dark.
- [ ] Migrate feed / members / media / moderation panels → `render`; delete the `if/elseif` chain.
- [ ] INTEGRATION: Jetonomy space discussions → `render`; delete the `home.php` branch + `new JetonomyBridge()`.
- [ ] TEST: every space tab renders; Jetonomy discussions + on-demand provision still work; 0 console errors.

### Phase 2 - Profile surface
- [ ] Wire `profile/view.php` → `render_panels()`; remove the pre-render-all include.
- [ ] Migrate posts / replies / media / likes / scheduled / about → `render`.
- [ ] INTEGRATION: Jetonomy profile discussions → `render`; delete the `profile-tab-panel.php` branch.
- [ ] TEST: each profile tab renders via its URL; deep-link paints the right panel; only active renders.

### Phase 3 - Existing integrations AS THE MODEL (Pro + Gamification), then delete the bolt-on
- [ ] INTEGRATION: GamificationAchievements → `render` (drop the after-hook usage).
- [ ] INTEGRATION: Pro SuiteProfile → `render`: Portfolio parent (no-panel → first child) +
      dynamic per-member sub-tab `render` closures; drop `tab`; drop the after-hook.
- [ ] DELETE `buddynext_part_profile_tab_panel_after` (now unreferenced, free + pro).
- [ ] TEST: Pro Portfolio tab + its dynamic sub-tabs behave identically; Gamification tab works.

### Phase 4 - Sub-tabs (close the space gap)
- [ ] PageRouter: add `/{tab}/{sub}/` routing + `bn_*_sub_action`; wire `NavContext->sub`.
- [ ] Migrate profile network sub-tabs (connections / followers / following) → child `render`.
- [ ] Add a worked SPACE sub-tab end-to-end (the new capability).
- [ ] TEST: space sub-tab URL renders the right child screen; profile sub-tabs unchanged.

### Phase 5 - Transport + cleanup + docs
- [ ] Wire `@buddynext/navigate` transport to swap the active panel region on tab/sub-tab clicks.
- [ ] TEST: no-reload switch when transport on; graceful full-page load when off; both surfaces.
- [ ] Remove `profile/store.js` reactive-reveal; drop the dead `global` surface + `rail`/`context` layers.
- [ ] Rewrite `47-nav-api.md`: ONE `render` recipe (tab + sub-tab + content); show core/Pro using it.
- [ ] Refresh `audit/manifest.json` + contract docs for added/removed hooks.
- [ ] FINAL: `bin/check.sh` green free + pro; full browser smoke - both surfaces, a sample
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
