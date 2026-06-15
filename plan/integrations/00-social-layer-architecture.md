# Integration Social Layer — shared architecture (read before any integration)

**Locked 2026-06-14.** Every integration adds a community layer ON TOP of a standalone
plugin (see `WORKFLOW.yaml` integration_law). This doc defines the **shared surfaces**
each integration plugs into so that N integrations never cost N profile tabs.

## App coverage (REST-first) — non-negotiable

BuddyNext ships a native app, so **every surface is REST-first: data via REST, web +
app both render from it.** No server-rendered-only feature. One data source, two surfaces.

| Surface | Data source | App endpoint |
|---|---|---|
| Activity / feed | `bn_posts` (via `IntegrationActivity`) | existing feed REST ✅ |
| Notifications | `bn_notifications` (via `SuiteNotifications`) | existing notifications REST ✅ |
| Portfolio panels | `SuiteProfile::panels()` (= `buddynext_member_suite_panels`) | `GET buddynext-pro/v1/members/{id}/portfolio` ✅ |
| Profile stats tiles | `buddynext_profile_extra_data` | served with the profile REST ✅ |

**Rule for every new integration touchpoint:** build the REST endpoint in the same
change as the web render, both reading the same provider/service. A web tab without an
app endpoint is incomplete.

## The rule that prevents tab explosion

> Integrations do **not** add their own profile tabs, routes, or screens. They register
> into **shared surfaces** through a provider API. One Portfolio tab, one stats strip,
> one feed — many integrations.

## The five shared surfaces

| Surface | Mechanism | Cost as integrations grow | Use for |
|---|---|---|---|
| **Activity feed** | `SuiteActivity::publish()` → a BN feed post | 0 (one shared stream) | "Member posted a new job/course/event" → engagement |
| **Notifications** | bridge → `NotificationService` | 0 | partner events (application, enrolment) |
| **Search** | bridge → `SearchService::index()` | 0 | partner content findable in BN search |
| **Profile stats strip** | existing `buddynext_profile_extra_data` filter | 0 (tiles) | lightweight COUNT signals ("3 jobs", "Level 4") |
| **Profile "Portfolio" tab** | ONE tab + `buddynext_member_suite_panels` provider API | 0 (one tab, many panels) | browseable lists of a member's partner content |

A member never sees a "Jobs tab", "Courses tab", "Events tab". They see **one Portfolio
tab** whose sections are contributed by whichever integrations are active.

## Provider API — `buddynext_member_suite_panels`

```php
// Each integration registers ONE panel per content type.
add_filter( 'buddynext_member_suite_panels', function ( array $panels, int $member_id ): array {
    $jobs = /* member's posted jobs */;
    if ( $jobs ) {
        $panels[] = array(
            'key'      => 'cb_jobs',                       // unique
            'label'    => __( 'Jobs', 'buddynext-pro' ),
            'icon'     => 'briefcase',                      // buddynext_icon slug
            'count'    => count( $jobs ),
            'priority' => 20,                               // ordering within the tab
            'cta'      => array( 'label' => __( 'View on Career Board', 'buddynext-pro' ), 'url' => $author_jobs_url ),
            'items'    => array_map( fn( $j ) => array(
                'title' => $j['title'],
                'url'   => $j['permalink'],                 // links OUT to the partner page
                'meta'  => $j['company'] . ' · ' . $j['salary'],
            ), $jobs ),
        );
    }
    return $panels;
}, 10, 2 );
```

- The **Portfolio tab only appears when ≥1 panel has content** (empty profiles show no tab).
- `count` badge on the tab = sum of panel counts.
- Items **link out** to the partner's own pages — BN never rebuilds them.

## Shared infrastructure (build once, in Pro)

- `includes/Suite/SuiteProfile.php` — registers the Portfolio tab via
  `buddynext_part_profile_tab_bar_args` (only when panels exist), renders the aggregated
  panels (template `templates/suite/portfolio.php`), and owns the
  `buddynext_member_suite_panels` filter + panel schema validation.
- `includes/Suite/SuiteActivity.php` — `publish( int $member_id, string $verb, string $title, string $url, string $type )`
  helper so any integration emits a consistent "member created X" feed activity through
  Free's `PostService` (no raw SQL; one card style for all integrations).

## Per-integration code (one folder per integration)

`includes/Integrations/<Name>/` holds that integration's social module — its panel
provider(s) + its activity emitters. Event→notification/search wiring stays in its
`Bridges/<Name>Bridge.php` (may consolidate under the integration folder later).

A new integration = (1) a panel provider on `buddynext_member_suite_panels`, (2) optional
activity emit on content-create, (3) optional stats-strip tile. **No new tab, route, or screen.**

## Open implementation note (confirm at build)

Profile tab content renders via `parts/profile-tab-panel.php` (Interactivity `data-tab`
switching). Confirm the exact way a Pro-registered tab injects its panel markup into that
switcher (likely render the panel on `buddynext_profile_after` with a matching `data-tab`,
hidden until active) before finalising `SuiteProfile`.

---

# AUTHORITATIVE CONTRACT (current as of 2026-06-14) — read before adding any panel

This supersedes the sketch above where they differ. `SuiteProfile` (Pro) is **built**; it
owns rendering so every integration gets the same premium chrome for free. Helper names as
shipped: feed activity = `BuddyNext\Feed\IntegrationActivity::publish()` (NOT `SuiteActivity`);
notifications = `BuddyNextPro\Suite\SuiteNotifications`.

## Panel array shape (exact)

```php
$panels[] = array(
    'key'       => 'cb_jobs',     // REQUIRED, unique across ALL integrations
    'label'     => __( 'Jobs', 'buddynext-pro' ),
    'icon'      => 'briefcase',   // header icon — see Icon rule (rows do NOT use it)
    'count'     => count( $items ),
    'priority'  => 20,            // see Priority table — pick a free slot
    'items'     => array( array(
        'title' => 'Senior PHP Dev',
        'url'   => 'https://partner/job/1',  // links OUT to the partner page
        'image' => 'https://…/logo.png',     // OPTIONAL per-row image (logo/photo)
        'meta'  => 'Acme Corp',              // one-line subtitle; also seeds the monogram
    ) ),
    'cta'       => array( 'label' => '…', 'url' => '…' ),       // optional, everyone
    'owner_cta' => array( 'label' => 'Manage on X', 'url' => '…' ), // optional, owner-only
);
```

**The renderer handles all chrome — you never style rows or counts:**
- **Row medal** = `image` when present, else a deterministic **monogram** (initials + tone
  from `meta` ?: `title`). The header `icon` is NEVER repeated per row. → don't try to give
  each row an icon; give it an `image` (logo/photo) or rely on the monogram.
- **Count** renders as BN's `.bn-tab__count` chip automatically.
- `owner_cta` shows only to the profile owner; `cta` to everyone.

## Priority allocation table (claim a free slot — no collisions)

`SuiteProfile` sorts by `(priority, key)` so ties are deterministic, but pick a UNIQUE
priority so order is intentional. Reserved:

| Range | Owner | Panels |
|---|---|---|
| 20 | Career Board | `cb_jobs` |
| 25 | Career Board | `cb_resume` |
| 30 | Learnomy | `learnomy_certifications` |
| 35 | Learnomy | `learnomy_teaching` |
| 40 | WB Listora | `listora_listings` |
| 50–90 | **free — your integration** | claim the next unused 10-slot and add a row here |

Update this table in the same change that adds a panel.

## Icon rule (or it renders blank)

- `icon` MUST be a slug with a file at `buddynext/assets/icons/<slug>.svg`. If it doesn't
  exist, `SuiteProfile::safe_icon()` falls back to `folder` and trips `_doing_it_wrong`
  (visible with `WP_DEBUG`). Same guard for `SuiteNotifications::register_source()` (→ `bell`).
- Check availability with `IconService::has('slug')`. To add an icon, drop a Lucide SVG
  (viewBox `0 0 24 24`, `stroke="currentColor"`) into `assets/icons/` — see existing files.
- Shipped slugs include: `briefcase file-text graduation-cap book-open store award folder
  bell chevron-right user users grid building bookmark star`.

## The other hard rules

- **Data-gate every panel.** Build it only when the member has that content; an empty panel
  is dropped (and the whole Portfolio tab hides when no panel has content). BN is for
  everyone — a non-user of your plugin must see nothing from it. See
  `[[feedback_linkedin_minimum_value]]`.
- **LinkedIn-minimum.** Show credentials/outcomes, not enrolment/in-progress/activity churn.
- **Core vs app surface.** The 3 core integrations (media/messages, discussions, gamification)
  get their OWN prominent profile tab (e.g. gamification's Achievements tab). Business apps
  (jobs/listings/courses) consolidate into the shared Portfolio tab. Never a per-plugin tab
  for an app. See `03-jetonomy.md` #1 and `06-gamification.md`.
- **App coverage (REST-first).** Panels are served by `GET buddynext-pro/v1/members/{id}/portfolio`
  (generic) — no per-integration endpoint needed. Activity → feed REST, notifications →
  notifications REST.
- **No role bleed.** Owner CTAs and role-specific panels must match the viewer's actual role.

## Pre-ship checklist for a new integration panel

1. [ ] Panel registered on `buddynext_member_suite_panels`, unique `key`, data-gated.
2. [ ] `priority` is a free slot — added to the table above.
3. [ ] `icon` resolves (`IconService::has()` true) — or icon SVG added.
4. [ ] Rows carry `image` where a logo/photo exists; otherwise the monogram covers it.
5. [ ] `owner_cta` (if any) is owner-only and role-correct.
6. [ ] Notification source (if any) via `SuiteNotifications::register_source()` with a real
   icon + `can_email=false` (the partner owns its emails).
7. [ ] Verified in the browser desktop + 390px with another integration also active (so the
   combined Portfolio tab is exercised), 0 console errors.
8. [ ] Tests: a `*SocialTest` asserting the panel's items/image/gating.
