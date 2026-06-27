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
1. DONE (free `401ad41a`): Foundation — `buddynext_integration_enabled()` + `IntegrationRegistry` +
   `buddynext_integrations()` + 5 unit tests (default-on, nav/feed independence, sub-tab parent gating,
   filter override, registry normalization).
2. DONE (free `32ae5dc8`): Free gating — JetonomyBridge + GamificationAchievements register entries; nav
   `condition` + activity (`feed`) gated. (MediaVerse's Media tab is a CORE SpaceNav/ProfileNav tab, not a
   bridge item — its gating is a separate follow-up, see below.)
3. DONE (pro `5cd60a0`): Pro gating — the 3 `*Social` bridges register entries (with subtabs) and gate
   `add_panels` (integration nav + per-sub-tab); the 3 `*Bridge` feed-activity publishes gated (remove +
   notification-mirror paths intentionally NOT gated). `phpstan-bootstrap` stubs the Free helper. SuiteProfile
   needed no change.
4. DONE (free `2925d7a6`): Admin — `IntegrationControlsAdmin` (BuddyNext → Platform → Integration Display),
   registry-driven toggle matrix, admin-post save (`write_flag` persists only opt-OUT). Placed via
   `TAB_PLACEMENT` after Integrations.
5. DONE (browser-verified on Docker, Pro active): all 5 integrations render their toggles; integration nav OFF
   hides the whole integration (Learnomy → both sub-tabs gone), a single sub-tab OFF hides just that sub-tab
   (Teaching gone, Certifications kept), the admin save round-trips and takes effect. Free + pro `bin/check.sh`
   green; +5 foundation tests; full free suite re-run for regressions.

### MediaVerse — DONE (browser-verified, Pro + wpmediaverse active)
`WPMediaVerseBridge` registers the **`media`** entry (label "Media", has_nav + has_feed, sub-tab `albums`) and
the gating rides the three media nav surfaces + the activity:
- `SpaceNav` + `ProfileNav` Media tab `condition` require `buddynext_integration_enabled('media','nav')`.
- The rail Media item (`inject_media_nav_item`) and `publish_media_activity` gate on nav / feed.
- The in-panel **Albums** sub-view (an Interactivity sub-nav in `media-tab.php`, NOT a registry sub-tab) is
  gated by a `$bn_mt_albums_enabled` flag from `render_media` → `buddynext_integration_enabled('media','nav','albums')`;
  when off the Media|Albums sub-nav is dropped AND `data-wp-init="callbacks.initAlbums"` is omitted (no albums REST load).

CRITICAL scope guarantee: MediaVerse also powers **Direct Messaging**. The `media` toggle gates ONLY the media
surfaces — NO message hook (`mvs_message_sent`, `mvs_can_send_message`, `mvs_user_profile_url`, the DM page)
reads it — so DMs keep working with Media off. Browser-verified: Media nav OFF dropped the tab + rail item, the
`/messages/` page still rendered with its UI and nav link.

### Legacy-option convergence — DONE (`ed81d91f`)
The legacy `buddynext_jetonomy_feed_sync` option is fully retired so the admin has ONE control, not two:
- `Installer` v10 `maybe_migrate_jetonomy_feed_sync()` carries an explicit opt-out into
  `buddynext_integration_jetonomy_feed`, then deletes the legacy option (idempotent; default-on maps to absent).
- `JetonomyBridge` feed gate drops the legacy `get_option` read (unified helper only).
- `Settings` removed the duplicate `Jetonomy Settings` toggle + its field-list entry + sanitize registration
  (pointer comment left). `RecommendedDefaults` dropped the legacy seed.
- Verified on Docker: a legacy `0` migrated to the unified key + the legacy option was deleted; the old
  Settings → Integrations tab shows no Jetonomy toggle.

## Status: COMPLETE
Foundation + free + pro + MediaVerse + admin + legacy convergence all landed and browser-verified. Every
integration (Career Board, Listora, Learnomy, Gamification, Jetonomy, Media) is owner-controllable from the one
Integration Display tab, default-on, with per-sub-tab granularity. The only open item is the optional
MediaVerse follow-up already covered above (none remain).

## Defaults / safety
- ABSENT option = ON. A brand-new integration is on by default; an owner opts OUT, never in.
- The helper is the ONLY read path; bridges never read the raw option (no key drift — [[wp-contract-audit]]).
- Performance: option reads are autoloaded + O(1); the registry is built once per request (memoize like
  NavRegistry). No new queries on the hot path ([[performance-first-fast-community]]).
