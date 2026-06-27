# Plan: Per-integration owner controls (nav + activity), with per-sub-tab granularity

Status: PLAN. Free + Pro coordinated. Lands after the nav-content-seam cutover (which made every
integration a registry nav item + render — this feature gates THOSE declaratively).

## Why (owner framing — "1000 site owners, don't force one layout")
A site owner who installs Career Board / Listora / Learnomy / Jetonomy / Gamification should decide, per
integration, whether it (a) shows its nav tab/sub-tab and (b) posts to the activity feed — without code.
Today the only gate is "integration active + has data", which forces every integration's tab + activity on
every site. Defaults stay ON so no existing install changes behaviour ([[no-empty-options-default]]).

## Owner decision (2026-06-27): granularity = INTEGRATION-LEVEL **+ PER-SUB-TAB**
Per integration: one **Show in navigation** toggle + one **Post to activity feed** toggle. PLUS each Portfolio
sub-tab (Jobs / Resume / Listings / Certifications / Teaching) and each top-level integration tab is
individually toggleable — Portfolio is where multiple integrations aggregate, so that's where fine control
matters most. Integration nav OFF ⇒ all its sub-tabs off (the integration toggle is the parent).

## GOVERNING PRINCIPLE: general + open (dogfood — "lots of plugins, future ones too")
There is NO hardcoded integration list anywhere. ANY integration — our in-house bridges AND a third-party
plugin — becomes controllable by doing exactly two things, the same way ours do (no core edit, no privileged
path):
1. **Register** itself on the public `buddynext_integrations` filter (key + label + has_nav/has_feed + subtabs).
2. **Gate** itself through the public `buddynext_integration_enabled($key, …)` helper in its nav `condition`
   and its activity handler.
Doing those two things, the integration AUTOMATICALLY appears on the `BuddyNext → Integrations` admin page with
its toggles, and its tab/sub-tab + activity obey the owner's choice. The admin page, the options, and the
gating are 100% derived from the registry — add the 20th integration and nothing in core changes. This mirrors
the Nav API dogfood seam: our Jetonomy/Gamification/Career Board/Listora/Learnomy code IS the reference a new
plugin copies. Scales to many integrations: the page groups by active/inactive and the registry is O(N) built
once per request; option reads are autoloaded O(1).

## Architecture (one source of truth, no per-bridge bespoke option code)
1. **Integration registry** — a `buddynext_integrations` filter each bridge populates (ONLY when its plugin is
   active), keyed by a short integration key:
   ```php
   'careerboard' => [
     'label'    => 'Career Board',
     'has_nav'  => true,   // contributes nav tab(s)/sub-tab(s)
     'has_feed' => true,   // posts activity
     'subtabs'  => [ 'jobs' => 'Jobs', 'resume' => 'Resume' ], // [] for single-tab integrations
   ]
   ```
   Keys: `jetonomy`, `gamification`, `mediaverse` (free); `careerboard`, `listora`, `learnomy` (pro). The
   registry is the SINGLE list the admin page renders and the helper validates against — no duplicated list.
2. **Options** (default ON, autoload-safe): `buddynext_integration_{key}_nav`, `_{key}_feed`, and per-sub-tab
   `buddynext_integration_{key}_subtab_{sub}`. Stored '1'/'0'; ABSENT = on (so a fresh install + a newly added
   integration are on by default, [[no-empty-options-default]]).
3. **Helper** (free, `buddynext.php`): `buddynext_integration_enabled( string $key, string $aspect = 'nav', string $sub = '' ): bool`
   - aspect `nav` → `_{key}_nav`; if `$sub` given AND nav on → also require `_{key}_subtab_{sub}`.
   - aspect `feed` → `_{key}_feed`.
   - default true; wrapped in `apply_filters( 'buddynext_integration_enabled', $on, $key, $aspect, $sub )` so a
     site can override per-context (e.g. per-space) without touching options.
4. **Gating** (the wiring — declarative, rides the existing seams):
   - NAV: every integration nav item's `condition` adds `&& buddynext_integration_enabled($key,'nav')`. For the
     Portfolio sub-tabs, `SuiteProfile::add_subnav()` drops a panel when its sub-tab toggle is off
     (`buddynext_integration_enabled($key,'nav',$sub)`), and each `*Social` bridge keys its panel to an
     integration key + sub key.
   - FEED: every bridge activity handler (the `*Bridge` event→`IntegrationActivity`/`SuiteActivity` path)
     early-returns when `! buddynext_integration_enabled($key,'feed')`.
5. **Admin page** — `BuddyNext → Integrations`: one card per registered integration (auto from the registry),
   a row with the two integration toggles, and a nested row per sub-tab. Detected-but-inactive integrations
   show greyed ("install/activate to configure"). Uses the shared admin primitives (AdminPageBase render_*_row).

## Files
| File | Change |
|---|---|
| `buddynext.php` | add `buddynext_integration_enabled()` helper + `buddynext_integrations()` accessor |
| `includes/Integrations/IntegrationRegistry.php` (new) | collects the `buddynext_integrations` filter, validates keys, exposes `all()` |
| `includes/Admin/IntegrationsAdmin.php` (new) | the `BuddyNext → Integrations` settings page (registry-driven) |
| `includes/Bridges/JetonomyBridge.php` | register integration entry; gate discussions nav `condition` + the activity push (`feed`) |
| `includes/Profile/GamificationAchievements.php` + `Bridges/GamificationBridge.php` | register entry; gate the Achievements `condition` (nav) + the badge/level activity (feed) |
| `includes/Bridges/WPMediaVerseBridge.php` | register entry; gate media activity (feed); media tab nav (`nav`) |
| `buddynext-pro/includes/Integrations/{CareerBoard,Listora,Learnomy}/*Social.php` | tag each suite panel with `key`+`sub`; gate via the helper |
| `buddynext-pro/includes/Bridges/{CareerBoard,Listora,Learnomy}Bridge.php` | gate each activity push (`feed`) |
| `buddynext-pro/includes/Suite/SuiteProfile.php` | drop a sub-tab in `add_subnav()` when its toggle is off |

## Migration order (each its own browser-verified commit; free + pro coordinated)
1. Foundation: `buddynext_integration_enabled()` + `IntegrationRegistry` + the `buddynext_integrations` filter
   contract + unit tests (helper default-on, sub-tab parent gating, filter override). Nothing wired yet.
2. Free gating: Jetonomy + Gamification + MediaVerse register entries; gate their nav `condition` + activity.
3. Pro gating: the 3 `*Social` panels tagged + gated; the 3 `*Bridge` activity pushes gated; SuiteProfile
   sub-tab drop.
4. Admin: `IntegrationsAdmin` page (registry-driven, sub-tab rows), placed in the admin IA via AdminHub.
5. Verify: each toggle OFF actually hides the tab/sub-tab + stops the activity (browser + feed check), per
   integration AND per sub-tab; defaults-on preserves today's behaviour; 1000-row-safe (options are O(1)
   reads, no per-request integration scan beyond the registry). Free + pro `bin/check.sh` green.

## Defaults / safety
- ABSENT option = ON. A brand-new integration is on by default; an owner opts OUT, never in.
- The helper is the ONLY read path; bridges never read the raw option (no key drift — [[wp-contract-audit]]).
- Performance: option reads are autoloaded + O(1); the registry is built once per request (memoize like
  NavRegistry). No new queries on the hot path ([[performance-first-fast-community]]).
