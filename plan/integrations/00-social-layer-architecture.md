# Integration Social Layer — shared architecture (read before any integration)

**Locked 2026-06-14.** Every integration adds a community layer ON TOP of a standalone
plugin (see `WORKFLOW.yaml` integration_law). This doc defines the **shared surfaces**
each integration plugs into so that N integrations never cost N profile tabs.

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
