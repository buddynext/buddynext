# Conformance: Scheduled Posts (Pro)

**Feature:** Scheduled Posts (BuddyNext Pro)
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/02-activity-feed.md` (Pro feature: "Schedule (future publish datetime) — `scheduled_at` column on `bn_posts`")
**Journey doc:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/scheduled-posts.md`
**Date:** 2026-05-31
**Verdict:** partial-needs-wiring — REST/app path complete; the web member journey has no UI entry point to schedule a post.

---

## Summary

Every backend layer of Scheduled Posts is built, wired, and reachable: the Free `bn_posts` schema carries `status='scheduled'` + `scheduled_at`, the Free create endpoint accepts `scheduled_at`, Pro intercepts creation and flips the row to `scheduled`, a 5-minute cron promotes overdue rows to `published` (re-firing `buddynext_post_created`), four Pro REST endpoints cover schedule/cancel/list, and a full admin queue page manages the queue.

The single break is at the **web member UI**: the Free Interactivity composer (the only member-facing post-creation surface) does not expose any "schedule for later" control and never sends `scheduled_at` or a `status` in its create payload. A member browsing the site at `/activity` cannot schedule a post. The feature is fully usable by REST/app clients and by admins via the queue page, but not by a web member through the composer — which is the journey a site owner expects.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `bn_posts` supports `status='scheduled'` + `scheduled_at` | db | wired | `buddynext/includes/Core/Installer.php:398,408,416` (ENUM + column + key); upgrade path `:927-941` |
| Free create endpoint accepts `scheduled_at` | rest | wired | `buddynext/includes/Feed/PostController.php:108` |
| Web composer offers a "schedule" control | ui | **missing** | `buddynext/templates/partials/composer.php:205-301` — toolbar has media, poll, event, privacy only; no schedule/clock affordance |
| Web composer sends `scheduled_at` / `status:draft` | store | **missing** | `buddynext/assets/js/feed/store.js:1457-1492` — submit body is only `content`/`privacy`/`type`(/`media_ids`/`options`); always toasts "Post published" (`:1500`) |
| Pro flips new future-dated row to `scheduled` | service | wired | `buddynext-pro/includes/Feed/ScheduledPostsIntegration.php:74-113` (hooked `buddynext_post_created` @ prio 5, `register()` :56-58) |
| Pro REST: POST `/posts/{id}/schedule` | rest | api-only | `buddynext-pro/includes/Feed/Controllers/ScheduledPostsController.php:64-86,193-213`; service `ScheduledPostsService.php:115-149` |
| Pro REST: DELETE `/posts/{id}/schedule` (cancel) | rest | api-only | `ScheduledPostsController.php:88-100,223-242`; service `:163-201` |
| Pro REST: GET `/me/scheduled-posts` | rest | api-only | `ScheduledPostsController.php:105-113,251-262`; service `:211-241` |
| Pro REST: GET `/posts/scheduled` (admin) | rest | wired | `ScheduledPostsController.php:116-124,272-315` |
| Controllers + integration + cron registered at boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:192-195,300-301` |
| Cron promotes overdue rows to `published` | service | wired | `ScheduledPostsService.php:265-314` (`tick()` re-fires `buddynext_post_created` :312); cron registered `:69-81` |
| Admin queue page (list / cancel / publish-now / publish-overdue) | ui | wired | `buddynext-pro/includes/Admin/ScheduledPostsAdmin.php:88-102,252-345` (forms with nonces + `data-bn-confirm` cancel) |

---

## First break

**Web composer schedule control (ui layer).** `buddynext/templates/partials/composer.php` exposes no schedule affordance and `buddynext/assets/js/feed/store.js:1457-1492` never includes `scheduled_at` or `status` in the post-create body. This is the earliest broken link for the web member journey. (Note: the `schedule`/`scheduled_at` references in store.js at `:1541-1604` belong to the *event* and *voice-room* composers, not post scheduling.)

Everything downstream of a scheduled row existing works: the REST schedule endpoint, the integration listener, the cron tick, and the admin queue are all wired. The break is purely the absence of a member-facing entry control on the web.

---

## UX gaps

1. **No web UI to schedule a post (member journey).** Severity: high. Confidence: confirmed-in-code. The only member post-creation surface is the Free Interactivity composer, which has no schedule control and sends no `scheduled_at`. A web member cannot reach `POST /buddynext-pro/v1/posts/{id}/schedule` through any rendered control. Evidence: `buddynext/templates/partials/composer.php:205-301`, `buddynext/assets/js/feed/store.js:1457-1492`. Fully usable for app/REST clients (`PostController.php:108` + the Pro schedule endpoint) and for admins via the queue page — but not for the web member, which is the spec's intent for a Pro "Schedule" feature.

2. **No member-facing "my scheduled posts" view on the web.** Severity: medium. Confidence: confirmed-in-code. `GET /buddynext-pro/v1/me/scheduled-posts` exists (`ScheduledPostsController.php:105-113`) but no front-end template/store consumes it, so a web member who scheduled a post via API has no place in the UI to see, edit, or cancel it. Only admins see the global queue (`ScheduledPostsAdmin.php`).

---

## Minimal refactor plan (reuse existing working code — no rewrites)

1. Add a schedule affordance to the composer toolbar in `buddynext/templates/partials/composer.php` (a clock-icon button alongside the existing media/poll/event controls, using `buddynext_icon('clock')`) that toggles a datetime input bound to composer state.
2. In `buddynext/assets/js/feed/store.js` `submit` action (~line 1457), when a scheduled datetime is set, include `scheduled_at` (UTC `Y-m-d H:i:s`) in the create body — the Free endpoint already accepts it (`PostController.php:108`) and Pro already intercepts it (`ScheduledPostsIntegration.php:74`). Switch the success toast to "Post scheduled" when `scheduled_at` was sent instead of the unconditional "Post published" (`store.js:1500`).
3. (Medium) Add a lightweight member "Scheduled" list view that calls the existing `GET /buddynext-pro/v1/me/scheduled-posts` and offers cancel via the existing `DELETE /posts/{id}/schedule` — reuse the existing endpoints; no new service code.

No backend, service, cron, admin, or REST changes are required — those layers are complete.

---

## Live-walk URL

http://buddynext-dev.local/activity

Walk note: open the composer, look for a schedule/clock control. As of this audit none exists on the web; confirm on a Pro-active site with a seeded member. The REST path and admin queue (`wp-admin/admin.php?page=buddynextpro-scheduled-posts`) should be walked to confirm the backend half works end-to-end.
