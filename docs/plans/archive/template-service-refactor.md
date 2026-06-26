# Template → Service Refactor (REST-first, no SQL in templates)

**Goal (pre-go-live):** every rendered template gets its data from the service
layer — the same services the REST controllers call. Raw `$wpdb`/SQL lives only
in `includes/*Service.php`. Web SSR and the app's REST payloads then come from
one source and cannot drift.

**Scope:** 29 of 173 templates currently run raw SQL (all confirmed LIVE/routed —
none orphaned). Audited 2026-06-17.

**Pattern per template:** each `$wpdb` query → call the owning service method (or
add one); map post rows through `PostService::hydrate()`; verify the surface +
the matching REST route return identical data.

---

## Correctness bugs found (real bugs, fix regardless of cleanup)

1. **profile/edit.php follower/following counts omit `status='approved'`** → private
   accounts count *pending* requests as followers. Fix by calling the cached
   `FollowService::follower_count()` / `following_count()` (which filter status).
2. **spaces/settings.php uses raw `$wpdb->update` for role change / ownership
   transfer / space update** in some branches while using the service in others —
   the raw paths skip validation, cache invalidation, and `buddynext_*` hooks.
3. **spaces/members.php list vs count disagree** — list applies search/role/
   suspension filters, count reads the denormalized `member_count` column.
4. **home.php announcement** uses `NOW()` (server-local) vs the service's
   `UTC_TIMESTAMP()` → expires at the wrong wall-clock time, and bypasses the
   `announcements` feature gate. (See "Open design question" — do not blind-swap.)

---

## Open design question (needs decision before touching home.php announcement)

`FeedService::home_feed()` **prepends the announcement as a feed card** on the
for-you first page, while `home.php` also renders a separate **top banner**. They
overlap; a timezone bug is currently the only thing preventing a double-render.
**Decide: announcement shows as a top banner OR as a feed card — not both.** Then
the redundant path is deleted (not swapped).

---

## Work organized by service (single source of truth per service)

### SpaceService / SpaceMemberService / SpaceCategoryService
Existing methods to call: `get()` (caches+hydrates — replaces 7× raw `bn_spaces`
loads), `get_by_slug()`, `list_spaces()` (directory.php re-implements this —
~70 lines incl. secret-space predicate), `search()`, `update()`,
`transfer_ownership()`, `change_role()`, `get_members()`, `count_members()`,
`get_pending_requests()`, `count_pending_requests()`, `get_categories_full()`.
New methods: `list_spaces_with_total()`, `top_contributors()`, `owned_root_spaces()`,
`categories_with_counts()`, `get_pending_join_requests()` + `count_pending_joins()`,
`SpaceMemberService::membership_map()`, `transfer_candidates()`, `spaces_for_user()`,
`online_now()`; extend `get_members()` for search/role + suspension exclusion.
Templates: spaces/home, spaces/directory, spaces/moderation, spaces/settings,
spaces/members, onboarding/index, notifications/prefs, feed/parts/explore-aside.

### FeedService / PostService
Existing: `home_feed()` (already used — model path), `profile_feed()`,
`space_feed()`, `PostService::get()`, `hydrate()`, `active_announcement()`,
`get_posts_by_status()`.
New: `FeedService::space_pinned_post()`, `space_post_count()`,
`space_media_post_count()`, `space_media_ids()`; `PostService::user_replies()`,
`user_liked_posts()`, `user_post_count()` (promote from ProfileController),
`reply_count()`, `reaction_count()`, `filter_visible($ids,$viewer)` (bookmarks
visibility gate); `BookmarkService::user_bookmarks_paged()`.
Templates: feed/home, feed/bookmarks, profile/view, partials/post-card,
hashtags/feed, spaces/home.

### ModerationService / ModerationLogService
Existing: `get_queue()` (moderation/queue + spaces/moderation hand-roll this),
`moderation_exclude_sql()` (suspension/shadow visibility — currently inlined in 3
places), `is_suspended()`, `is_shadow_banned()`.
New/extend: `queue_stats()`, `count_open_reports_for_space()`,
`count_open_reports()` / `count_urgent_reports()`; extend `get_queue()` for
strikes/offender enrichment; add `space_id` + date filter to `get_log()`.
Templates: moderation/queue, spaces/moderation, community-admin, spaces/members,
directory/members.

### HashtagService
Existing: `get_feed()` (cursor-paginated + hydrated — feed.php ignores it for
LIMIT/OFFSET), `get_by_slug()`, `is_following()`, `trending()`/`get_trending()`
(transient-cached — explore-aside bypasses it), `autocomplete()`.
New/extend: `sort` arg on `get_feed()`; `related()`, `top_contributors()`,
`contributor_count()`, `following_map($uid,$slugs)`.
Templates: hashtags/feed, feed/parts/explore-aside, parts/hashtag-sidebar-related,
search/results.

### NotificationService / NotificationPrefService
Existing: `list_for_user()` (cursor+hydrate), `unread_count()`,
`NotificationPrefService::list_space_notification_prefs()` (prefs.php duplicates
it verbatim — only needs `avatar_url` added), `NotificationMessageService`.
New/extend: filter + offset on `list_for_user()`; `count_for_user($uid,$filter)`,
`unread_counts_by_type()`, `recent_actor_ids()`.
Templates: notifications/index, notifications/prefs.

### FollowService / ConnectionService
Existing (all cached): `follower_count()`, `following_count()` (fixes edit.php
bug), `following()`, `is_following()`, `connections()`, `connection_count()`
(connections.php duplicates these verbatim).
New: `FollowService::following_map($uid,$ids)` (kills per-card N+1); 7-day delta
helpers for follower/following/connection growth stats (profile/view).
Templates: profile/edit, profile/connections, profile/view, directory/members,
search parts.

### MemberDirectoryService (in includes/Profile/)
Existing: `list_members()` (directory/members.php re-implements the ENTIRE filter
pipeline in raw SQL — the single highest-value refactor), `matching_user_ids()`.
New: `online_now($limit)`.
Templates: directory/members.

### Admin (Insights / AdminHub) + ActivityLogService
community-admin.php duplicates `Insights::metrics()` counters → extend Insights /
add `AdminHub::overview_stats()` (today/yesterday + report + pending-join counts).
New `ActivityLogService::recent()` for the `bn_activity_log` feed.

### Sidebar\WidgetService + Engagement (lower risk — already filter-guarded)
sidebar-this-week-stats → `WidgetService::weekly_stats($uid)`.
sidebar-greeting-streak → `Engagement\StreakService::summary($uid)` (streak math
currently in the view).

### Messages
shell/rail.php raw DM-count query reaches into WPMediaVerse tables → move behind
`Messages\MessagesData::unread_count($uid)` (keep the guard + cache).

### Shared helpers (kill cross-file duplication)
- `bn_initials()` redefined 5+ times across templates → one
  `AvatarService::initials_for()` (AvatarService already has `tone_for()`).
- Avatar tone/colour palette duplicated (members.php named tones vs connections.php
  hex) → centralize in `AvatarService`.

---

## Execution order

**Phase 0 — Correctness bugs + pure-duplicate quick wins (lowest risk, call
existing methods):**
- profile/edit.php → `FollowService::follower_count/following_count` (fixes bug)
- profile/connections.php → `ConnectionService::connections/connection_count`
- notifications/prefs.php → `NotificationPrefService::list_space_notification_prefs` (+avatar_url)
- spaces/settings.php → route role/transfer/update through the services (fixes hooks/cache)
- shared `AvatarService::initials_for()` + replace the 5 copies

**Phase 1 — Call existing service methods (extend where noted):**
- directory/members.php → `MemberDirectoryService::list_members()` (biggest dup)
- hashtags/feed.php → `HashtagService::get_feed()` (+sort)
- moderation/queue.php + spaces/moderation.php → `ModerationService::get_queue()` (+log space_id)
- spaces/* → `SpaceService::get()` / `list_spaces_with_total()` / `get_members()`
- feed/parts/explore-aside.php → `HashtagService::trending()`
- N+1 fixes: search parts + hashtag-sidebar-related (batch maps)

**Phase 2 — Add new service methods, then switch templates:**
- profile/view.php tab data + growth stats
- notifications/index.php list filter/offset + per-type counts
- feed/bookmarks.php paginated + visibility gate
- community-admin.php counters/reports/activity
- search media + hashtag

**Phase 3 — Announcement design decision, then home.php; sidebar widgets; rail DM count.**

Verify each: surface renders identically (light+dark, 390px for member-facing) AND
the matching REST route returns the same data, before moving on.
