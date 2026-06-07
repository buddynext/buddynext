# Conformance: Pro Analytics

**Feature:** Analytics (BuddyNext Pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P5-analytics.md` (Locked)
**Journey ref:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/analytics-dashboard.md`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ → `admin.php?page=buddynextpro-analytics`
**Verdict:** usable-leave-as-is

---

## Summary

The site-owner analytics journey (collect events → open admin dashboard → read
DAU/WAU/MAU + top content + top members + space/profile insights → export CSV) is
wired end-to-end. The data layer (events table), the collector (25+ Free-action
listeners), the query service, the admin UI, and the REST surface are all present
and connected in `Plugin.php`. This is an admin-only, server-rendered feature, so
there is no front-end Interactivity-API store to wire — the web journey is the
admin page render itself.

The journey doc is **stale** relative to the implemented dashboard (it describes
`?tab=content/members/spaces`; the code ships richer `?view=overview/cohorts/funnel/profile-views`).
The locked spec's intent is fully met by the implemented views. No code break.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Events table exists with proper indexes | db | wired | `includes/Core/Installer.php:357` (CREATE TABLE bn_analytics_events, KEYs event_date/actor_date/target/created) |
| Collector listens to 25+ Free actions, writes rows | service | wired | `includes/Analytics/AnalyticsCollector.php:33` (register), `:470` (record → `$wpdb->insert` into bn_analytics_events) |
| Collector registered at boot | service | wired | `includes/Core/Plugin.php:201` (`AnalyticsCollector::register()`) |
| Aggregate queries (dau/wau/mau, top_content, top_members, space_health, member_growth) | service | wired | `includes/Analytics/AnalyticsService.php:81,167,225,278,337` — event_type strings match collector's dotted slugs (`user.registered`, `reaction.added`, `comment.created`) |
| Admin dashboard page registered (sidebar + legacy submenu) | ui | wired | `includes/Admin/AnalyticsAdmin.php:125` (register: AdminHub tab `growth/analytics` + `add_submenu`), `:145` (add_submenu_page slug `buddynextpro-analytics`) |
| Admin instantiated at boot | ui | wired | `includes/Core/Plugin.php:204` (`new AnalyticsAdmin(...)->register()`) |
| Overview view renders stat grid + charts from service | ui | wired | `includes/Admin/AnalyticsAdmin.php:223` (render_overview_view), `:651` (render_stat_grid calls dau/wau/mau), `:727` (top content), `:752` (top members) |
| CSV export (nonce + cap, streams text/csv) | ui | wired | `includes/Admin/AnalyticsAdmin.php:127` (admin_post hook), `:896` (handle_csv_export: cap check + check_admin_referer + fputcsv) |
| REST overview/content/members/space-health endpoints (admin) | rest | wired | `includes/Analytics/Controllers/AnalyticsController.php:79` (register_routes), `:346` (require_admin = manage_options); registered at `includes/Core/Plugin.php:305` |
| REST own profile-views (logged-in) + admin user profile-views | rest | wired | `includes/Analytics/Controllers/AnalyticsController.php:162,173` (routes), `:355` (require_logged_in) |

---

## First break

none — journey complete.

---

## UX gaps

- **Journey doc drift (doc-only, not a code break)** — severity: low, confidence:
  confirmed-in-code. The journey doc walks `?tab=content/members/spaces` and a 4-tab
  Overview/Content/Members/Spaces layout; the shipped admin uses `?view=` with
  Overview/Cohorts/Funnel/Profile Views (`includes/Admin/AnalyticsAdmin.php:511-547`).
  The data the spec requires is all reachable; only the doc is out of date. Update the
  journey doc, not the code.
- **Spaces CSV export falls back to growth data** — severity: low, confidence:
  confirmed-in-code. `handle_csv_export()` has cases for `content` and `members` only;
  any other view hits the `default` growth branch
  (`includes/Admin/AnalyticsAdmin.php:917-938`). The Export CSV button in the section
  head always posts `view=overview` (`:603`), so it consistently exports growth — no
  broken download, just a narrower export than the doc's "per-tab CSV" wording implies.
- **Profile-view opt-out not honored on admin endpoint** — severity: low, confidence:
  confirmed-in-code. By design (spec: "admin overrides opt-out"); noted only so the
  live walker does not flag it as a leak. `AnalyticsController::get_user_profile_views`
  bypasses the member opt-out (`:318`).

None of these stop the core journey.

---

## Minimal refactor plan

Empty — usable-leave-as-is. (Optional doc-hygiene, not code: refresh
`analytics-dashboard.md` to match the `?view=` tabs and the growth-only CSV fallback.)

---

## Live-walk notes

- Precondition: BuddyNext Free must be active — the admin page is a submenu of the
  Free `buddynext` top-level menu and a tab in Free's `AdminHub`. With Free inactive
  the page has no parent (documented Free+Pro precondition, not a defect).
- Empty test data shows zeros / "No data for this window." rows by design
  (`AnalyticsAdmin.php:736,761`). Seed activity (fire Free actions or run a prior
  journey) before concluding the dashboard is dead.
- REST admin endpoints require `manage_options`; expect 401/403 for non-admins
  (`AnalyticsController.php:346`).
