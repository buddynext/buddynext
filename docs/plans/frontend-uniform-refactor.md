# Frontend Template Uniform Refactor (pre-go-live)

Cover **every frontend template, template by template, to one uniform standard**
so the whole front end is consistent. Supersedes the service-only view in
`template-service-refactor.md` by folding in three audits: SQL-in-templates,
duplicate code, and Interactivity anti-patterns.

## Definition of done (every frontend template must meet ALL)

1. **No SQL** — zero `$wpdb`; all data from the service layer (the same services
   the REST controllers call). New query? Add a service method, point the REST
   controller at it too.
2. **Canonical hydration** — DB rows mapped through the owning hydrator
   (`PostService::hydrate()`, `SpaceService::hydrate()`, …), never hand-built.
3. **Reactive Interactivity** — visibility/active/follow/react state via
   `data-wp-bind` / `data-wp-class` / `data-wp-text` driven by one `context`/`state`
   source. No `querySelector`+`.hidden`/`.classList`/`setAttribute` paint loops,
   no triplicated state, no `data-wp-on`/`data-wp-bind` set on JS-built nodes
   (use delegated dispatch or `data-wp-each`). Real page-to-page nav → the
   Interactivity Router (`data-wp-router-region` + shell `navigate`), not
   `fetch`+`pushState`.
4. **No duplication** — shared helpers (`AvatarService::initials_for()`), CSS on
   `--bn-*` tokens, one component definition.
5. **Presentation** — responsive (390px for member-facing), light + dark verified.
6. **Verified** — surface renders + the matching REST route returns the same data.

## Reference patterns (copy these — from the Interactivity audit)

- **Reactive single-source visibility:** `buddynext/follow-requests`
  (`assets/js/social/follow-store.js`) — action sets `ctx.hidden`, `get rowHidden()`,
  template `data-wp-bind--hidden="state.rowHidden"`. The model for profile tabs.
- **Router navigation:** `buddynext` shell `navigate` (`assets/js/shell/navigate.js`
  + `data-wp-router-region="buddynext/main"`). The model for every
  `window.location`/`fetch`+`pushState` in notifications/search/spaces/messages.
- **Delegated dispatch for JS-built nodes:** `buddynext/messages` `onThreadClick`.
- **Derived state getters** (Interactivity can't eval `||`/`===` in directives):
  `buddynext/profile` twofa/slug getters.

---

## Phase A — Shared foundations (do FIRST; many screens depend on these)

- **A1 AvatarService::initials_for()** — kill `bn_initials()`/`bn_connections_initials()`
  + inline initials (5+ copies). (was task #5)
- **A2 Shared CSS dedup onto `--bn-*`** — `.bn-member-card` cluster (4 files),
  `.bn-composer__tool(s)` (blocks.css stale copy), `.bn-empty-state` (3),
  `.bn-completion-bar` (2), `.bn-post-card` (blocks.css vs bn-feed.css). (was #3/#4/#6)
- **A3 New service methods** (grep-confirmed missing): `SpaceService::list_spaces_with_total()`,
  `top_contributors()`, `owned_root_spaces()`, `categories_with_counts()`,
  `get_pending_join_requests()`/`count_pending_joins()`; `SpaceMemberService::membership_map()`,
  `transfer_candidates()`, `spaces_for_user()`, `online_now()`, + search/role/suspension
  args on `get_members()`; `FeedService::space_pinned_post()`, `space_post_count()`,
  `space_media_post_count()`, `space_media_ids()`; `PostService::user_replies()`,
  `user_liked_posts()`, `reply_count()`, `reaction_count()`, `filter_visible()`;
  `BookmarkService::user_bookmarks_paged()`; `ModerationService::queue_stats()`,
  `count_open_reports_for_space()`, `count_open/urgent_reports()`, strikes-enrichment +
  `space_id`/date filter on `get_log()`; `HashtagService` `sort` arg on `get_feed()`,
  `related()`, `top_contributors()`, `contributor_count()`, `following_map()`;
  `NotificationService::count_for_user()`, `unread_counts_by_type()`, `recent_actor_ids()`,
  filter+offset on `list_for_user()` (+ `avatar_url` on `list_space_notification_prefs()`);
  `MessagesData::unread_count()`; `Admin\AdminHub::overview_stats()`;
  `ActivityLogService::recent()`; `ProfileService` growth-delta helpers.
- **A4 Reactive-tabs pattern** — build `state.isActiveTab` + `data-wp-bind--hidden`
  on `buddynext/profile`; becomes the template for all tab/panel UIs.

## Phase B — Per-screen passes (uniform DoD). Status: ✅ done · ◻ todo

### Feed
- **home** ✅ SQL-free + announcement card. ◻ Interactivity: feed react/save/reactor-popover reactive (shared via post-card).
- **explore** ◻ SQL: explore-aside trending→`HashtagService::trending()`, categories→`categories_with_counts()`. ◻ poll card not recognizable on explore (add poll affordance). ◻ Interactivity follow/react.
- **bookmarks** ◻ SQL: `BookmarkService::user_bookmarks_paged()` + `PostService::filter_visible()` (drop inline visibility gate).
- **single-post / post-card / post-body** (shared component) ◻ SQL: top-reactions→`ReactionService::top_reactions()`, shared post→`PostService::get()`. ◻ Interactivity: reactor popover SSR + `data-wp-bind--hidden`, react/save reactive classes.

### Profile
- **view** ✅ deep-link fixed. ◻ SQL: replies/likes/discussions/member-spaces/post-count/growth-deltas (lines 186-284) → Feed/Post/Space services + ProfileService deltas. ◻ Interactivity: replace SSR `$bn_pf_hidden` + `applyTabId`/`bnValidTabs`/`applyTabFromUrl` with reactive `state.isActiveTab` (A4).
- **edit** ✅ done.
- **connections** ✅ done (shortcode path).

### Spaces
- **directory** ◻ SQL: replace inline grid with `SpaceService::list_spaces_with_total()`/`search()`; categories; membership map; sidebar closures. ◻ Interactivity: create-modal, syncUrl→Router.
- **home** ◻ SQL: 13 queries → `SpaceService::get()`, `SpaceMemberService::get_members()`/`count_pending_requests()`, `FeedService::space_feed()` + new pinned/count/media methods; move "who can post" rank to a service.
- **settings** ◻ SQL **+ correctness**: role change/ownership transfer/space update via raw `$wpdb->update` → `change_role()`/`transfer_ownership()`/`update()` (raw paths skip validation/cache/hooks). ◻ Interactivity: savebar state-machine → `context.savebarState`, modals → `data-wp-bind--hidden`.
- **members** ◻ SQL: extend `get_members()` (search/role + suspension exclusion via `moderation_exclude_sql()`); fix list-vs-count mismatch.
- **moderation** ◻ SQL: `get_queue()` + `get_log()` (+space_id) + report-count method.

### Members directory
- **directory/members + member-grid** ◻ SQL: `MemberDirectoryService::list_members()` (re-implements whole pipeline) + `online_now()`. ◻ Interactivity HIGH: JS-built cards set inert `data-wp-on`/`data-wp-bind` → Follow/Connect break after tab change; use `data-wp-each` or delegated dispatch; pager + syncUrl → Router.

### Hashtags
- **feed + hashtag parts** ◻ SQL: `HashtagService::get_feed()` (+sort) + `related()`/`top_contributors()`/`contributor_count()`; fix sidebar-related N+1 via `following_map()`; rows through `hydrate()`. ◻ Interactivity: follow/react triplicated state → reactive class/aria bindings.

### Notifications
- **index** ◻ SQL: `list_for_user()` (+filter/offset) + `count_for_user()` + `unread_counts_by_type()` + `recent_actor_ids()`. ◻ Interactivity HIGH: hand-rolled `fetch`+`DOMParser`+`pushState` SPA router → `data-wp-router-region` + shell `navigate`; tab active + badges reactive.
- **prefs** ◻ SQL: `list_space_notification_prefs()` (+avatar_url).

### Search
- **results + search parts** ◻ SQL: hashtag→`autocomplete()`, media→new `SearchService` media type; fix per-row N+1 in section parts (enrich SearchService results). ◻ Interactivity: join button reactive; filter reloads → Router.

### Moderation queue
- **queue** ◻ SQL: `get_queue()` (+strikes enrichment) + `queue_stats()`; drop in-template SQL-fragment assembly.

### Onboarding
- **index** ◻ SQL: recommended spaces→`list_spaces()`, suggested people→shared `suggested_follows()`, joined/following sets→bulk accessors.

### Messages
- **native** ◻ Interactivity: mostly legitimate real-time imperative build (keep; already uses delegated dispatch). nav `window.location` → Router candidates. Verify no raw SQL.

### Shell / sidebar widgets
- **shell/rail** ◻ SQL: DM count → `MessagesData::unread_count()` (stop reaching into WPMediaVerse tables).
- **parts/sidebar-this-week-stats** ◻ SQL → `Sidebar\WidgetService::weekly_stats()` (keep the `buddynext_user_weekly_*` filters).
- **parts/sidebar-greeting-streak** ◻ SQL + streak math → `Engagement\StreakService::summary()`.

### Admin-context (lower priority, separate token namespace)
- **community-admin** ◻ SQL: counters→`Insights`/`AdminHub::overview_stats()`, reports→`ModerationService`, joins→`SpaceService`, activity→`ActivityLogService::recent()`, signups→`get_users()`.

### Other frontend (uniform pass — audit for SQL/Interactivity/dedup)
- auth/*, settings/* (account/privacy use buddynext/profile), gamification/leaderboard (bn_initials copy), blocks/* (Gutenberg), remaining parts/* not covered above.

## Execution order
Phase A (foundations) → then Phase B screens in priority: profile/view, members
directory, hashtags, notifications (the HIGH Interactivity + SQL ones), then
spaces (home/settings/directory/members/moderation), then feed (explore/bookmarks/
single), search, moderation queue, onboarding, shell widgets, community-admin,
then the remaining uniform-pass templates. One screen fully (all 6 DoD points,
verified) before the next.
