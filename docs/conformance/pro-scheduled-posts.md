# Conformance: Scheduled Posts (Pro)

**Feature:** Scheduled Posts
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/02-activity-feed.md` (Post Features → Pro: "Schedule (future publish datetime) — `scheduled_at` column on `bn_posts`")
**Journey doc:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/scheduled-posts.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Date:** 2026-05-31

## Verdict

**usable-leave-as-is** for the journey as written (REST schedule/cancel/list + cron publish + admin queue management). The journey doc defines the happy path as a REST + admin walk, and every link in that chain is wired and bound to a real handler.

One observation, not a journey break: there is no member-facing **web composer** control to set a publish time. The schedule action is REST-only on the front-end. This is fully usable for app/REST clients and the admin queue; it is a UX gap only if a site owner expects a member to schedule from the `/activity` composer in a browser. The journey doc and the locked spec do not mandate such a composer control, so this does not break the documented journey.

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member creates a draft post (Free endpoint) | rest | wired | Free `POST /buddynext/v1/posts` (journey doc line 192; Free-owned) |
| 2 | Schedule the draft: `POST /buddynext-pro/v1/posts/{id}/schedule` | rest | wired | `includes/Feed/Controllers/ScheduledPostsController.php:64-86` route + `schedule_post()` :193-213 |
| 3 | Permission: must be logged-in + post owner | rest/service | wired | controller `post_owner_permission()` :135-145 (login gate); ownership enforced in `ScheduledPostsService::assert_owner()` :347-362 |
| 4 | Service sets `status='scheduled'` + `scheduled_at`, validates future time | service/db | wired | `ScheduledPostsService::schedule_post()` :115-149; future-time guard :122-128 returns `buddynextpro_invalid_schedule_time` |
| 5 | Post hidden from feeds while scheduled | db | wired | Free feed query excludes scheduled rows: `templates/feed/home.php:192` (`scheduled_at IS NULL OR scheduled_at <= NOW()`); Integration also flips status on create `ScheduledPostsIntegration::maybe_mark_scheduled()` :74-113 |
| 6 | Member lists own scheduled posts: `GET /me/scheduled-posts` | rest/service | wired | controller :104-113 + `get_my_scheduled_posts()` :251-262; `ScheduledPostsService::get_scheduled_posts()` :211-241 |
| 7 | Admin sees the queue at `admin.php?page=buddynextpro-scheduled-posts` | ui/db | wired | `includes/Admin/ScheduledPostsAdmin.php:252-345` renders queue table from `bn_posts WHERE status='scheduled'` |
| 8 | Cron registered + ticks every 5 min | service | wired | `ScheduledPostsService::register_cron()` :69-81 (booted at `includes/Core/Plugin.php:192`); interval :91-100 |
| 9 | Tick publishes overdue posts, re-fires `buddynext_post_created` | service/db | wired | `ScheduledPostsService::tick()` :265-314 |
| 10 | Published post appears in feed (Free REST) | rest/db | wired | status now `published`; feed query no longer excludes it (`templates/feed/home.php:192`) |
| 11 | Admin "Publish Overdue Posts Now" button | ui/service | wired | form `bnpro_admin_tick_scheduled` :290-296 → `handle_tick()` :224-243 → `tick()` |
| 12 | Admin per-row "Publish Now" / "Cancel" | ui/service | wired | `handle_publish_now()` :165-213, `handle_cancel()` :130-154; Cancel uses `data-bn-confirm` :334 |
| 13 | Cancel reverts to draft + clears `scheduled_at` | service/db | wired | `ScheduledPostsService::cancel_schedule()` :163-201 |
| 14 | Member schedules a post from the **web composer** | ui | missing | no scheduling control in Free composer `templates/partials/composer.php` (no schedule/future/publish-time field); Pro ships no `templates/` or front-end `assets/` for this feature |

## First break

**None for the documented journey** — the REST + admin happy path (steps 1-13) is complete and every link is bound to a working handler. The only missing link is step 14 (a member-facing web composer scheduling control), which is outside the documented journey and not mandated by the locked spec.

## UX gaps

1. **No web composer affordance to schedule a post (api-only on front-end).**
   Severity: medium. Confidence: confirmed-in-code.
   Evidence: `templates/partials/composer.php` (Free) has no schedule/publish-time field; the "schedule" strings in `assets/js/feed/store.js:1278-1592` are for events and voice rooms, not post publish-time. Pro `includes/Feed/` ships no template or JS store binding. A browser member can only schedule via raw REST. App/REST clients are fully served; the admin can manage the queue. This is a gap only for the web member journey and only if a site owner expects in-composer scheduling.

2. **Notification-on-publish seam (documented, accepted).**
   Severity: low. Confidence: confirmed-in-code.
   Evidence: `ScheduledPostsIntegration.php:19-30` caveat — Free's `NotificationListener` (priority 10) may fire before/around the priority-5 status correction; on `tick()` re-fire of `buddynext_post_created` the notification template may not distinguish "scheduled post now live" from a fresh post. Flagged as a Free-side TODO in both the integration docblock and the journey "Known limitations" (journey doc lines 205-209). Does not block the journey.

## Minimal refactor plan

Empty. The documented journey is usable as-is. Do not rewrite working backend code.

(If a future spec revision requires in-composer scheduling for web members, the minimal add would be: a publish-time field in `templates/partials/composer.php` bound via the Interactivity store in `assets/js/feed/store.js` to call the existing, already-working `POST /buddynext-pro/v1/posts/{id}/schedule` after draft creation. The REST + service layers need no changes. This is net-new work, not a fix, and is out of scope for this conformance pass.)
