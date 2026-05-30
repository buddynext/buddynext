# Conformance: BuddyNext Pro — Analytics

**Feature:** Analytics (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P5-analytics.md`
**Journey doc:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/analytics-dashboard.md`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/ (Analytics under the BuddyNext "Growth" hub; legacy URL `admin.php?page=buddynextpro-analytics`)
**Verdict:** usable-leave-as-is

---

## Summary

The core happy path — an admin opens the Analytics dashboard and sees community-health
metrics (DAU/WAU/MAU, daily activity, top content, top members), switches time windows,
explores Cohorts / Funnel / Profile-Views, and exports CSV — is **fully wired end to end**:
collector → `bn_analytics_events` (indexed) → `AnalyticsService` aggregates → admin UI (rendered
server-side via Free template parts and SVG chart helpers) and a parallel REST surface.

The journey doc is **stale relative to the code** (it describes tabs Overview/Content/Members/Spaces
with `?tab=`; the shipped admin uses views Overview/Cohorts/Funnel/Profile-Views with `?view=`).
The shipped UI is a superset of the spec intent, not a regression. This is a doc-drift item, not a
journey break.

One real but contained data-correctness gap exists on the REST-only `spaces/{id}/health` endpoint
(`posts` always returns 0). It has no admin UI binding and does not affect the admin journey.

---

## Journey chain (admin happy path)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Free fires 25+ activity actions; collector records events | service | wired | `includes/Analytics/AnalyticsCollector.php:33-78` (hooks), `:470-501` (`record()` insert) |
| Events persisted to `bn_analytics_events` (indexed) | db | wired | `includes/Core/Installer.php:357-371`; activation hook `buddynext-pro.php:28` |
| Collector registered at boot | store | wired | `includes/Core/Plugin.php:200` |
| Admin page registered (Growth hub + legacy submenu) | ui | wired | `includes/Admin/AnalyticsAdmin.php:125-159`; hub section `buddynext/includes/Admin/AdminHub.php:49,217` |
| Dashboard renders: section head, window filter, stat grid, 3 charts | ui | wired | `AnalyticsAdmin.php:187-240` (`render_content`/`render_overview_view`) |
| DAU/WAU/MAU + posts/engagement/signups tiles | service→ui | wired | `AnalyticsAdmin.php:651-699`; queries `AnalyticsService.php:81-155`, `count_events` `:31-73` |
| Daily activity (LineChart), top content, top members cards | service→ui | wired | `AnalyticsAdmin.php:707-770`; `AnalyticsService::top_content` `:167-216`, `top_members` `:225-269` |
| Time-window switch (7/30/90/365) | ui | wired | `AnalyticsAdmin.php:580-643` (`resolve_window`/`render_window_filter`) |
| Cohorts / Funnel / Profile-Views views | service→ui | wired | `AnalyticsAdmin.php:197-211,247-466` |
| Export CSV (overview/content/members) | ui→service | wired | `AnalyticsAdmin.php:597-611` (nonce form), `:896-942` (`handle_csv_export` admin-post) |
| REST overview / content / members / me-profile-views | rest | wired | `Controllers/AnalyticsController.php:79-192`; registered `Plugin.php:301-304` on `rest_api_init` |
| REST permission gating (`manage_options` / logged-in) | rest | wired | `AnalyticsController.php:346-357` |
| REST `spaces/{id}/health` `posts` metric | rest | broken | see First break — collector never writes `post.created` with `target_type='space'` |

---

## First break

**`spaces/{space_id}/health` REST endpoint returns `posts: 0` permanently.**

`AnalyticsService::space_health()` counts `event_type='post.created' AND target_type='space'`
(`AnalyticsService.php:310-320`), but `AnalyticsCollector::on_post_created()` always records
`post.created` with `target_type='post'` and `target_id=$post_id` — the space ID is never the
target (`AnalyticsCollector.php:149-163`). So the `posts` field (and `engagement_rate()`, which
also keys on `target_type='space'` for reactions/comments at `AnalyticsService.php:386-408`) will
always be 0.

This is **not** an admin-journey break: there is no Spaces view or space-health UI control in the
shipped `AnalyticsAdmin` (only Overview/Cohorts/Funnel/Profile-Views). The break is confined to a
REST-only surface and the app/REST client journey for per-space post volume. `joins`, `leaves`, and
`net_members` on the same endpoint are correct (collector records `space.member_joined` /
`space.member_left` with `target_type='space'`, `AnalyticsCollector.php:324-343`).

For the admin web journey the spec describes: **none — journey complete.**

---

## UX gaps

1. **Space-health `posts`/`engagement_rate` always 0** — severity: medium — confidence: confirmed-in-code.
   Evidence: `AnalyticsService.php:310-320,386-408` query `target_type='space'` for post/reaction/comment
   events, but `AnalyticsCollector.php:156-163,232-259` record those with `target_type='post'`/`'comment'`,
   never `'space'`. Affects REST `spaces/{id}/health` and `engagement_rate()` only; no admin UI consumes it.

2. **Journey doc drift** — severity: low — confidence: confirmed-in-code.
   Doc (`analytics-dashboard.md:42-104`) walks tabs Overview/Content/Members/**Spaces** via `?tab=`;
   shipped UI (`AnalyticsAdmin.php:511-548`) is Overview/Cohorts/Funnel/Profile-Views via `?view=`.
   The shipped UI delivers spec intent; the doc and its REST examples should be refreshed. No code change required.

3. **`notification.created` actor is null + interim 3-arg signature** — severity: low — confidence: confirmed-in-code.
   Evidence: `AnalyticsCollector.php:67-73,421-433`. Documented Free-seam, does not affect the admin journey
   (notifications are not surfaced in the dashboard). Listed for completeness.

---

## Minimal refactor plan

The admin happy-path journey is complete and usable; no rewrite is warranted. The one real
correctness fix is small and isolated:

1. Make per-space post/reaction/comment volume measurable. Either (a) record a space dimension in
   `post.created`/`reaction.added`/`comment.created` event `properties` (e.g. `space_id`) inside
   `AnalyticsCollector` and have `AnalyticsService::space_health()`/`engagement_rate()` filter on
   that property, or (b) if the Free hooks do not expose the space context, document
   `posts`/`engagement_rate` as join-flow-only and remove them from the `space_health()` response
   shape so the endpoint does not advertise a metric it cannot compute. Pick (a) only if the Free
   `buddynext_post_created` payload already carries the space; otherwise (b).
2. (Docs, no code) Update `analytics-dashboard.md` to match the shipped `?view=` UI and REST surface.

---

## Notes for the live walk

- Seed activity first (empty `bn_analytics_events` shows all zeros, which is correct, not a bug):
  fire `buddynext_post_created`, `user_register`, `buddynext_space_member_joined`, `buddynext_reaction_added`.
- Verify the Analytics entry appears under the **Growth** section of the BuddyNext admin hub, and the
  legacy `admin.php?page=buddynextpro-analytics` still resolves.
- Confirm `?view=cohorts`, `?view=funnel`, `?view=profile-views`, and `?window=7|30|90|365` all render.
- Confirm Export CSV downloads `buddynext-analytics-overview-<date>.csv` (and `-content-`, `-members-`).
- REST 403 for non-admin on `/analytics/overview` (gating at `AnalyticsController.php:346-348`).
