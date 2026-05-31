# Conformance: BuddyNext Pro — Analytics

**Feature:** Analytics (repo: buddynext-pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/P5-analytics.md` (Locked, 2026-03-19)
**Journey doc:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/analytics-dashboard.md`
**Live-walk URL:** http://buddynext-dev.local/wp-admin/
**Verdict:** usable-leave-as-is

---

## Core happy-path

Site owner expects an admin to open the Analytics dashboard and see community-health
metrics (DAU/WAU/MAU, member growth, top content, top members), backed by an event
stream that Pro collects from Free actions, with a CSV export and matching REST surface.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Free fires activity actions (`buddynext_post_created`, `buddynext_reaction_added`, `buddynext_space_member_joined`, `user_register`, etc.) | service | wired | `buddynext/includes/Feed/PostService.php:178`, `buddynext/includes/Reactions/ReactionService.php:81`, `buddynext/includes/Spaces/SpaceMemberService.php:145` |
| Collector hooks 25+ Free actions and writes rows | service | wired | `buddynext-pro/includes/Analytics/AnalyticsCollector.php:33-78`, write at `:470-501` |
| Collector registered at boot | store | wired | `buddynext-pro/includes/Core/Plugin.php:201` |
| `bn_analytics_events` table created on activation | db | wired | `buddynext-pro/includes/Core/Installer.php:357` (dbDelta at `:48`) |
| Service aggregates (dau/wau/mau, member_growth, top_content, top_members, space_health) | service | wired | `buddynext-pro/includes/Analytics/AnalyticsService.php:81,107,135,167,225,278,337` — event-type strings match Collector output (`user.registered`, `post.created`, `reaction.added`, `space.member_joined`) |
| Admin submenu + AdminHub "Growth → Analytics" tab | ui | wired | `buddynext-pro/includes/Admin/AnalyticsAdmin.php:125-159`; registered at `Plugin.php:204`; AdminHub `growth` section at `buddynext/includes/Admin/AdminHub.php:49` |
| Overview view: stat grid (DAU/WAU/MAU/posts/engagement/signups) + 3 cards | ui | wired | `AnalyticsAdmin.php:223-240`, `render_stat_grid` `:651-699` |
| Window filter (7/30/90/365) | ui | wired | `AnalyticsAdmin.php:619-643`, `resolve_window` `:584-590` |
| Extra views: Cohorts, Funnel, Profile Views | ui | wired | `AnalyticsAdmin.php:197-211`, `:247,299,370` |
| CSV export (admin-post, nonce-checked, content/members/overview cases) | ui+service | wired | form `AnalyticsAdmin.php:602-607`; handler `handle_csv_export` `:896-942` (nonce `:902`) |
| REST: overview / content/top / members/top / spaces/{id}/health / me\|users profile-views | rest | wired | `buddynext-pro/includes/Analytics/Controllers/AnalyticsController.php:79-192`; registered `Plugin.php:305-308` |
| REST auth: `require_admin` (manage_options) + `require_logged_in` | rest | wired | `AnalyticsController.php:346-357` |
| Member self-only profile views (privacy opt-out honoured, admin override) | rest+service | wired | `AnalyticsController.php:286-337`; `buddynext-pro/includes/Analytics/ProfileViewService.php` |

## First break

none — journey complete. The core admin analytics happy-path (entry via AdminHub
"Growth → Analytics" or legacy `admin.php?page=buddynextpro-analytics`, through to
rendered metrics, CSV export, and the REST surface) is wired at every layer. Event
collection genuinely fires because the Free `do_action` calls exist (verified) and
the Collector hooks them at boot.

## UX gaps (non-blocking)

1. **Stale journey doc vs. shipped UI** (low, confirmed-in-code).
   The journey doc describes four tabs `Overview / Content / Members / Spaces` via
   `&tab=`, and verification SQL using `user_registered` / `space_joined` event names.
   The shipped UI uses `&view=` with `Overview / Cohorts / Funnel / Profile Views`
   (`AnalyticsAdmin.php:511-547`); Content and Members are cards inside the Overview
   view (`:727,752`), and there is no standalone Spaces tab. Event-type strings are
   dotted (`user.registered`, `space.member_joined`) in BOTH Collector and Service, so
   the feature is internally consistent — only the doc is out of date. A human
   following the doc's URLs/SQL verbatim will be confused, not blocked. Doc fix only.

2. **Spec's "space moderators get a space-scoped analytics tab" has no front-end UI**
   (medium, confirmed-in-code). `AnalyticsService::space_health()` and
   `engagement_rate()` exist (`AnalyticsService.php:278,375`) and per-space health is
   reachable via `GET /analytics/spaces/{id}/health`, but that endpoint is gated by
   `require_admin` (`AnalyticsController.php:145`) and no space-moderator-facing
   analytics tab calls `space_health` anywhere outside Service/Controller. So per-space
   health is **api-only for admins** and **missing as a moderator UX surface**. This is
   a secondary surface in the spec, not part of the core admin happy-path.

## Minimal refactor plan

EMPTY. The core journey is usable end-to-end and the code is internally consistent;
no rewrite is warranted. The two items above are (1) a documentation refresh and
(2) a deferred secondary spec surface (moderator space-scoped tab) — neither blocks
the admin happy-path. Recommend a live-walk on a seeded site to confirm rendered
values, since collected metrics are runtime/seed-dependent and will read as zeros on
an empty test account.
