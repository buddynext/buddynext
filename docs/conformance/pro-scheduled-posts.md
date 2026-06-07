# Conformance Dossier — Scheduled Posts (Pro)

**Feature:** Scheduled Posts
**Repo:** buddynext-pro (with required Free seam in buddynext)
**Spec ref:** `buddynext/docs/specs/features/02-activity-feed.md` (Pro post feature: "Schedule (future publish datetime) — `scheduled_at` column on `bn_posts`")
**Journey doc:** `buddynext-pro/docs/journeys/scheduled-posts.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The journey is wired end-to-end across both the web (Interactivity API composer → Free REST → DB → cron → feed) and the app/REST surface (Pro `buddynext-pro/v1` endpoints). All Pro classes are instantiated and registered at boot. No usability break was found by reading the code. The most notable architectural fact: the **member web journey runs entirely through Free** — the Free composer + Free `PostService` + Free `CronService` already schedule, hide, and publish a future post. Pro adds a parallel/management layer (its own cron worker, REST endpoints, admin queue page) plus the one required status-correction seam (`ScheduledPostsIntegration`).

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member opens composer, clicks the clock "Schedule for later" tool, picks a datetime | ui | wired | `buddynext/templates/partials/composer.php:253-260` (tool button, unconditional — not Pro-gated), `:203-224` (datetime-local panel) |
| 2 | Store toggles schedule panel + captures UTC datetime | store | wired | `buddynext/assets/js/feed/store.js:1447-1465` (`toggleSchedule`, `setScheduledAt` → `toUtcSqlDatetime`), getters `:1256-1261` |
| 3 | Submit POSTs `{content, scheduled_at}` to Free `/posts` | store→rest | wired | `buddynext/assets/js/feed/store.js:1534-1544` (`body.scheduled_at`), toast `:1553` |
| 4 | Free persists row with `scheduled_at` | rest→service→db | wired | `buddynext/includes/Feed/PostController.php:108`, `PostService.php:166` (insert), `Installer.php:408,416` (`scheduled_at` column + index) |
| 5 | Pro corrects `status` to `scheduled` for future dates | service | wired | `buddynext-pro/includes/Feed/ScheduledPostsIntegration.php:74-113` (priority-5 hook on `buddynext_post_created`); registered `buddynext-pro/includes/Core/Plugin.php:192` |
| 6 | Scheduled row hidden from all feeds until due | service→db | wired | `buddynext/includes/Feed/FeedService.php:265,451,510,697,810,894` (`AND (scheduled_at IS NULL OR scheduled_at <= NOW())`); home `templates/feed/home.php:203` |
| 7 | New-post space notification suppressed while scheduled | service | wired | `buddynext/includes/Notifications/NotificationListener.php:530-540` (gate on `status='scheduled' && scheduled_at>now`) |
| 8 | Cron publishes overdue posts, re-fires `buddynext_post_created` | service→db | wired | Free: `CronService.php:267` `handle_publish_scheduled` (1-min), registered `CronScheduler.php:65,86`. Pro: `ScheduledPostsService.php:265-313` `tick()` (5-min), `register_cron` `:69-81`, wired `Plugin.php:193` |
| 9 | Published post appears in feed via Free REST | rest→db | wired | `buddynext/includes/Feed/FeedService.php` (same `scheduled_at<=NOW()` predicate now passes); journey step 10 `/buddynext/v1/posts` |
| 10 | (App/REST) schedule / cancel / list own / list all (admin) | rest→service | wired | `buddynext-pro/includes/Feed/Controllers/ScheduledPostsController.php:62-125` (4 routes), registered `Plugin.php:300-301`; service `schedule_post`/`cancel_schedule`/`get_scheduled_posts` `ScheduledPostsService.php:115,163,211` |
| 11 | (Admin) queue page: Publish Now / Cancel / Publish Overdue | ui→service | wired | `buddynext-pro/includes/Admin/ScheduledPostsAdmin.php:88-92` (handlers), `:111-121` (submenu + AdminHub tab `:94-101`), render `:252-345`; registered `Plugin.php:194-195` |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Spec lists "Schedule" as a **Pro** post feature, but the composer clock tool renders unconditionally for all users (static tool row, above the `buddynext_composer_tools` Pro injection point). On a Free-only site the schedule UI is fully functional via Free's own `PostService` + `CronService`. Spec-vs-build mismatch, not a journey break — the feature works; it is simply not gated behind Pro. | low | confirmed-in-code | `buddynext/templates/partials/composer.php:253-260` (no `$composer_has_pro` guard) vs spec `02-activity-feed.md` Pro section; Free publisher `CronService.php:267` |
| No member-facing web surface to review/cancel a scheduled post. After scheduling, the member only gets a transient toast ("Post scheduled"). Cancel/list-own exists only via Pro REST (`GET /me/scheduled-posts`, `DELETE /posts/{id}/schedule`) and the admin queue — no front-end template was found. | medium | needs-live-verification | toast `store.js:1553`; member cancel only via REST `ScheduledPostsController.php:88-100` or admin `ScheduledPostsAdmin.php:329-337`; no member-facing scheduled-posts template under `buddynext*/templates` |
| Double-publish race: Free's 1-min cron and Pro's 5-min cron both select `status='scheduled' AND scheduled_at<=NOW()` and both re-fire `buddynext_post_created`. Neither claims the row atomically before firing the action, so a post due in the overlap could fire `buddynext_post_created` twice. Idempotency is documented as a listener responsibility. | low | needs-live-verification | Free `CronService.php:267-300`; Pro `ScheduledPostsService.php:265-313`; both call `do_action('buddynext_post_created', ...)` |

---

## Minimal refactor plan

EMPTY — verdict is usable-leave-as-is. The happy-path completes end-to-end on both web and REST surfaces; working code is not rewritten. Items worth raising with the owner (not code changes mandated here):

- Decide whether the composer clock tool should be Pro-gated to match the spec, or the spec should drop Schedule from the Pro-only list (build treats it as Free-capable).
- Confirm in a live walk whether a member-facing "scheduled posts" review/cancel surface is required for the web journey, or whether admin + REST management is the intended UX.
- Confirm in a live walk that the dual Free/Pro cron does not double-fire notifications for a post due in the 5-min overlap window.

---

## Notes for live walk (http://buddynext-dev.local/activity)

- Logged-in member: compose a post, click the clock icon, pick a near-future time, submit. Expect toast "Post scheduled" and the post absent from the feed.
- Verify `wp_bn_posts.status='scheduled'` and `scheduled_at` set.
- Backdate `scheduled_at`, run `wp eval "do_action('buddynext_publish_scheduled');"` (Free) and/or `do_action('buddynextpro_publish_scheduled');` (Pro); expect `status='published'` and the post in-feed.
- Admin queue: `wp-admin/admin.php?page=buddynextpro-scheduled-posts` — verify row, Cancel, Publish Now, Publish Overdue Posts Now.
- Watch Mailpit (http://localhost:10010/) to confirm the space new-post notification fires only at publish time, not at schedule time.
