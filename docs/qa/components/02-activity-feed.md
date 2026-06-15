# QA — Activity Feed (free)

**Manifest refs:** tables: `bn_posts` · `bn_poll_options` · `bn_poll_votes` · `bn_shares` · `bn_bookmarks` · `bn_feed_items` · REST routes: `buddynext/v1/posts/*` · `buddynext/v1/feed/*` · `buddynext/v1/me/shares` · `buddynext/v1/me/bookmarks` · `buddynext/v1/me/drafts` · services: PostService, FeedService, FeedCache, PollService, ShareService, BookmarkService, ComposerDraftController, IntegrationActivity, SinglePostMeta · capabilities: `manage_options` (pin/announce), authenticated member (create/edit/delete), public (explore/OG)
**Cross-ref (no dup):** JOURNEYS J-11 (feed home view) · J-12 (compose text post) · J-13 (compose link post) · J-14 (compose photo) · J-15 (compose poll) · J-16 (react to post) · J-17 (comment on post) · J-18 (share post) · J-19 (bookmark post) · MATRIX M5 (compose post) · M6 (feed home/explore) · M7 (react/comment) · M8 (share) · M9 (bookmark)
**Frontend location:** `/activity/` (home feed, hub page ID 5) · `/activity/explore/` (explore feed, page ID 11) · `/p/{id}/` (single post permalink) · Admin: none (feed is member-facing only)

---

## 1. Backend settings & options

Options are drawn from Settings.php `render_tab_social()` and `render_tab_features()`. Toggle options (type `boolean`) that use `render_toggle_row()` are flagged **[TOGGLE-BUG]**: unchecked state is never saved because no preceding `<input type="hidden">` is emitted (see `AdminPageBase.php`). `buddynext_post_edit_window` is an integer field, not a toggle, and is not affected by the toggle bug.

The `buddynext_features` array (Features tab) controls which module groups are enabled via `FeatureRegistry::persist()`. It is not in `wp_options` on this live site — the site runs on code defaults for all feature flags listed below.

### Social tab options

| Option key | Type | Default | Saved in DB? | Notes / Gaps |
|---|---|---|---|---|
| `buddynext_default_post_privacy` | select (public / followers / connections / private) | `'public'` | Not in wp_options on live site — running on code default | Controls default privacy picker value in composer. Members can override per-post. No gap beyond missing DB row. |
| `buddynext_allow_polls` | boolean toggle | `1` (true) | Not in wp_options — running on code default `true` | **[TOGGLE-BUG]** Disabling polls via Settings > Social never saves. Unchecked state is not submitted. `wp option get buddynext_allow_polls` returns nothing (not set), and code falls back to `true`. Poll UI therefore cannot be hidden via admin toggle. |
| `buddynext_allow_shares` | boolean toggle | `1` (true) | Not in wp_options | **[TOGGLE-BUG]** Same as above. Share/repost button cannot be removed via settings. |
| `buddynext_allow_bookmarks` | boolean toggle | `1` (true) | Not in wp_options | **[TOGGLE-BUG]** Same as above. Bookmark button cannot be removed via settings. |
| `buddynext_enable_link_preview` | boolean toggle | `1` (true) | Not in wp_options | **[TOGGLE-BUG]** Same as above. OG fetch on URL paste cannot be disabled. |
| `buddynext_enable_emoji_picker` | boolean toggle | `1` (true) | Not in wp_options | **[TOGGLE-BUG]** Same as above. Emoji picker cannot be hidden via settings. |
| `buddynext_post_edit_window` | integer (minutes) | `60` (confirmed default in code; admin hub doc shows `15` — verify source of truth) | Not in wp_options | Integer field, not a toggle — not affected by toggle bug. Controls how many minutes after `created_at` an author can call `PUT /posts/{id}`. Passed through `PostService::update()` edit window check. No UI indicator shown to members of their remaining edit time. |
| `buddynext_enabled_reactions` | array of reaction slugs | `[]` (array) | Not in wp_options | Sanitized via `sanitize_enabled_reactions()` which always preserves at least one reaction and enforces canonical order. Checkboxes use `name="buddynext_enabled_reactions[]"` — not affected by toggle bug. No drag-reorder control for reaction palette order. |

### Features tab reference

| Option key | Type | Default | Saved in DB? | Notes / Gaps |
|---|---|---|---|---|
| `buddynext_features` | array of enabled feature slugs | `[]` (code defaults apply) | Not in wp_options on live site | Activity feed, polls, bookmarks, shares, reactions, link-preview, and emoji-picker are all feature-controlled. `FeatureRegistry::persist()` enforces tier rules (free vs. pro). No granular per-role control — features are on or off site-wide. |

### Page assignment options (NavManager — feeds)

| Option key | Live value | Notes / Gaps |
|---|---|---|
| `buddynext_page_activity` | `5` | WP page assigned as the Activity/Feed hub. BuddyNext routes `/activity/` through this page. |
| `buddynext_page_feed` | `11` | Separate option from `buddynext_page_activity`. On this live site page ID 11 is the Explore page. If both point to different pages, the hub router resolves `page_activity` for the home feed and `page_feed` for explore — but this is undocumented. A site owner who assigns the same page to both options (or different pages than intended) will get undefined routing behavior. No UI note explains the distinction. |
| `buddynext_slug_activity` | `'activity'` | URL slug for the feed hub. `/activity/` resolves to the home feed. |

---

## 2. Frontend functions (service and controller level)

### PostService.php

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `create()` | Validates post type (allowed: text, photo, file, link, poll, announcement, activity, media, discussion, job, event). Checks suspension + safeguard. Fires `buddynext_post_before_save` filter. Auto-fetches OG meta for `link` type. Inserts `bn_poll_options` rows for `poll` type (requires 2–5 options). Parses `@mentions` and fires `buddynext_user_mentioned`. Auto-flags if safeguard triggers. Admin-only gate for `announcement` type. | `bn_posts` (INSERT), `bn_poll_options` (INSERT for poll type) | `buddynext_post_before_save` (filter), `buddynext_post_created`, `buddynext_user_mentioned` | `event` type is in the allowed list and passes validation but the UI is incomplete (v0.3 roadmap fixme). A non-admin member who crafts a raw REST request can create an `announcement` post if the admin gate in `create()` is not enforced at the API layer — verify `PostController` enforces admin capability before reaching `PostService`. |
| `update()` | Enforces edit window: post must be within `buddynext_post_edit_window` minutes of `created_at`. Sets `edited_at`. | `bn_posts` (UPDATE) | None confirmed | No indication in the UI of remaining edit time. After the window expires, `PUT /posts/{id}` returns an error — no frontend countdown. |
| `delete()` | Soft-deletes post by setting `status='deleted'`. Cascades delete to `bn_reactions`, `bn_comments`, then `bn_poll_options` and `bn_poll_votes`. Does NOT physically remove the `bn_posts` row. | `bn_posts` (UPDATE status), `bn_reactions` (DELETE), `bn_comments` (DELETE), `bn_poll_options` (DELETE), `bn_poll_votes` (DELETE) | `buddynext_post_deleted` | No admin bulk-purge tool for soft-deleted posts. `status='deleted'` rows accumulate indefinitely. |
| `increment_counter()` / `decrement_counter()` | Increments or decrements `reaction_count`, `comment_count`, or `share_count` on `bn_posts` using `GREATEST(1, col) - 1` for floor protection on decrement. | `bn_posts` (UPDATE) | None | Counters are maintained in application code with no DB-level constraint. Concurrent reaction/comment/share requests could result in double increments before the first write lands. |
| `pin()` | Sets `is_pinned=1` and `site_pin_expires_at`. Admin-only. | `bn_posts` (UPDATE) | `buddynext_post_pinned` | No cron job confirmed to auto-unpin when `site_pin_expires_at` passes. Verify `CronService` handles pin expiry. |
| `get()` / cache | Reads from cache group `buddynext_posts`, TTL 600s. | Cache group `buddynext_posts` | None | Cache is invalidated on `delete()` and `update()` but not verified on `pin()` or counter changes. |

### FeedService.php

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `home_feed()` | Wraps `home_feed_uncached()` with `FeedCache` for page 1 / for-you filter (TTL 30s). Returns paginated post rows. | `bn_posts`, `bn_space_members`, `bn_follows`, `bn_connections`, `bn_user_suspensions` (subqueries), usermeta (shadow-ban check) | `buddynext_post_impression` per item | FeedCache TTL is only 30 seconds — new-post pill must poll frequently for perceived freshness. |
| `home_source_clause()` | Builds SQL subqueries for 4 feed filters: `for-you` (followed users + own posts + joined space posts), `following` (followed users only), `spaces` (joined spaces only), `network` (public posts from connections). | Subqueries on `bn_follows`, `bn_connections`, `bn_space_members` | None | No index on `(status, created_at)` alone on `bn_posts` — home feed "for-you" and "network" subqueries filtering `status='published'` may scan. No index on `(user_id, status, created_at)` composite — profile feed join may scan on large datasets. |
| `home_feed_new_count()` | Watermark stored in usermeta. Polled by the "N new posts" pill on the frontend. | usermeta (watermark), `bn_posts` (COUNT) | None | If the watermark usermeta write fails silently, the pill count will drift and never reset. |
| `explore_feed()` | Returns public posts ordered by `created_at`. No ranking logic in the free tier. | `bn_posts` | `buddynext_post_impression` per item | No dedup on impression events — rapid scroll triggers duplicate `buddynext_post_impression` fires for the same post within one page load. Impression counts are inflated on fast scroll. |
| `profile_feed()` | Returns posts by a specific user, gated by `PrivacyService::can_view_activity()`. | `bn_posts`, usermeta (shadow-ban) | `buddynext_post_impression` per item | Same double-impression risk as explore feed. |
| `space_feed()` | Returns posts in a specific space, gated by space membership. Excludes suspended/shadow-banned users. | `bn_posts`, `bn_space_members`, usermeta | `buddynext_post_impression` per item | Same double-impression risk. |

### FeedCache.php

| Method | What it does | Candidate bugs / gaps |
|---|---|---|
| `get()` / `set()` | Caches page-1 for-you feed for 30s (`TTL_HOME_PAGE_1 = 30`). Caches trending feed for 300s (`TTL_TRENDING = 300`). | — |
| `invalidate_writer()` | Busts cache keys for `per_page=10` and `per_page=20` only. | **Gap:** If the frontend requests `per_page=15` or `per_page=25`, those cache entries are never invalidated after a new post is created. Stale feed pages persist until TTL expires (30s for page 1). |
| `invalidate_all_users()` | Documented no-op. Does not bust any cache entries. | **Critical gap:** Any admin action that affects feed content for all users (bulk moderation, announcement creation, pinned post) relies on TTL drift, not real invalidation. Site owners expecting instant propagation will see stale feeds. |

### PollService.php

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `vote()` | Toggle-off (same option voted again deletes the row), switch (different option deletes old + inserts new), first-vote (insert). | `bn_poll_votes` (DELETE + INSERT), `bn_poll_options` (UPDATE `vote_count`) | None confirmed | **No DB-level UNIQUE constraint on `(post_id, user_id)`** in `bn_poll_votes`. Duplicate vote guard is handled in application code only. Two concurrent requests (e.g., double-tap on mobile) can race and insert two rows before either read confirms a prior vote exists. A `UNIQUE KEY (post_id, user_id)` constraint is missing. |
| `results()` | Returns options ordered by `vote_count DESC`. | `bn_poll_options` | None | `end_date` is stored in `bn_poll_options` and read by code, but **no cron job or hook auto-closes polls when `end_date` passes**. Polls with an expiry date remain open indefinitely after their deadline unless manually closed. |

### ShareService.php

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `share()` | Flattens share-of-a-share chains to the root post (`shared_post_id` is always the original). Checks for duplicate share (one per user per post) before insert. Creates a feed post of type `'share'` inheriting the original's privacy. | `bn_shares` (INSERT), `bn_posts` (INSERT for the share feed post), counters on original post | `buddynext_post_created` (for the share post) | Share-chain flattening prevents runaway nesting, which is correct. The duplicate guard is a SELECT-before-INSERT (no DB-level UNIQUE on `(user_id, post_id)` in `bn_shares`) — concurrent shares from the same user could race through. |
| `user_shares_paginated()` | Returns paginated share history for the authenticated user. | `bn_shares`, `bn_posts` | None | — |

### BookmarkService.php

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `bookmark()` | `INSERT IGNORE` for dedup — the `PRIMARY KEY (user_id, post_id)` on `bn_bookmarks` enforces uniqueness at DB level. | `bn_bookmarks` (INSERT IGNORE) | Fires event only on actual DB row change (row_count check) | — |
| `unbookmark()` | Deletes the `(user_id, post_id)` row. | `bn_bookmarks` (DELETE) | Fires event only on actual row deletion | — |
| Cache | Cache group `buddynext_bookmarks`, 10-min TTL. | — | — | — |

### ComposerDraftController.php (@since 1.5.0)

| Method | What it does | Storage | Candidate bugs / gaps |
|---|---|---|---|
| `GET /me/drafts` | Returns current draft or `null`. | usermeta `bn_composer_draft` | — |
| `POST /me/drafts` | Overwrites the single draft slot. No version history. | usermeta `bn_composer_draft` | **One draft per user across all devices and tabs.** If a member starts composing on mobile and then opens a browser tab, the second device's autosave will overwrite the first. No per-session or per-device draft isolation. |
| `DELETE /me/drafts` | Clears `bn_composer_draft` usermeta. | usermeta | — |

### IntegrationActivity.php

| Method | What it does | Candidate bugs / gaps |
|---|---|---|
| `exists_by_link()` + publish | Idempotent publish via a link-URL check before insert. Used by Jetonomy bridge and Career Board bridge to sync external content into `bn_posts`. | Relies on `link_url` uniqueness for dedup — no DB UNIQUE constraint on `link_url` in `bn_posts`, so a race between two bridge fires could insert duplicates. |

### SinglePostMeta.php (@since 1.5.0)

| Method | What it does | Candidate bugs / gaps |
|---|---|---|
| OG / Twitter Card / canonical / robots / meta-description on `/p/{id}/` | Resolves image from `media_ids` → `link_meta.thumbnail` → author avatar → site icon. | Private, followers-only, connections-only, and space-members posts get `noindex, nofollow`. Public posts get full OG tags. |

---

## 3. QA cases

| ID | Role | Layer | Pre-conditions | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-AF-001 | subscriber | frontend | Logged in; feed page at `/activity/?autologin=1` | Navigate to home feed; observe default "for-you" tab | Feed renders; composer visible; posts from followed users + own posts + joined spaces appear; no PHP errors in debug log | 1440px, 768px, 390px |
| QA-AF-002 | subscriber | frontend | Logged in; home feed | Click "Following" tab | Feed switches to posts from followed users only; tab highlight updates; URL or JS state reflects filter | 1440px, 390px |
| QA-AF-003 | subscriber | frontend | Logged in; home feed | Click "Spaces" tab | Feed shows posts from spaces the user is a member of; empty state shown if no space membership | 1440px, 390px |
| QA-AF-004 | subscriber | frontend | Logged in; home feed | Click "Network" tab | Feed shows public posts from connected users; empty state shown if no connections | 1440px, 390px |
| QA-AF-005 | anonymous | frontend | Logged out | Navigate to `/activity/explore/` | Explore feed renders with public posts ordered by `created_at`; no login wall; composer absent; post actions (react/share/bookmark) prompt login | 1440px, 390px |
| QA-AF-006 | subscriber | REST + frontend | Logged in; composer open | Type text; click Post | `POST /buddynext/v1/posts` called with `type=text`; new post appears at top of feed; `reaction_count=0`, `comment_count=0`, `share_count=0` in DB; `buddynext_post_created` hook fires | 1440px, 390px |
| QA-AF-007 | subscriber | REST + frontend | Logged in; composer open | Paste a URL; observe link preview | OG meta auto-fetched (if `buddynext_enable_link_preview` is on); preview card renders below composer text area with title, description, thumbnail; post submitted with `type=link` and `link_meta` JSON populated | 1440px, 390px |
| QA-AF-008 | subscriber | REST + frontend | Logged in; composer open | Attach a photo via media picker; submit | Post created with `type=photo` and `media_ids` JSON populated; photo renders in feed card | 1440px, 390px |
| QA-AF-009 | subscriber | REST + frontend | Logged in; composer open | Click "Poll" button; add 2 options; set question text; submit | `POST /buddynext/v1/posts` with `type=poll`; `bn_poll_options` rows created; poll card renders in feed with options and vote counts (0) | 1440px, 390px |
| QA-AF-010 | subscriber | REST | Logged in; composer open | Attempt to create a poll with only 1 option | Server returns 400/422 validation error; poll not created; user sees error message in composer | 1440px |
| QA-AF-011 | subscriber | REST | Logged in; composer open | Attempt to create a post with `type=announcement` via direct REST call | Server returns 403 Forbidden; post not created; only admin can create announcement type | 1440px |
| QA-AF-012 | admin | REST + frontend | Logged in as admin; feed page | Create an announcement post via admin composer or direct REST call (`type=announcement`) | Post created with `is_announcement=1`; announcement banner appears at top of home feed (first page); `buddynext_post_created` fires | 1440px, 390px |
| QA-AF-013 | subscriber | REST + frontend | Logged in; own post visible within edit window | Click Edit on a post; change text; save | `PUT /buddynext/v1/posts/{id}` succeeds; `edited_at` set in `bn_posts`; "Edited" indicator appears on post card | 1440px, 390px |
| QA-AF-014 | subscriber | REST | Logged in; own post created more than `buddynext_post_edit_window` minutes ago (default 60 min) | Attempt `PUT /buddynext/v1/posts/{id}` | Server returns 403 or 422 indicating edit window has passed; post unchanged in DB | 1440px |
| QA-AF-015 | subscriber | REST + frontend | Logged in; own post visible | Click Delete on a post; confirm | `DELETE /buddynext/v1/posts/{id}` called; `status='deleted'` set in `bn_posts`; post disappears from feed; `share_count` and `reaction_count` on any parent posts decremented; `buddynext_post_deleted` fires | 1440px, 390px |
| QA-AF-016 | admin | REST + frontend | Logged in as admin; pinned post with future `site_pin_expires_at` | Pin a post via `POST /buddynext/v1/posts/{id}/pin` | `is_pinned=1` set; `site_pin_expires_at` set; post appears at top of feed; `buddynext_post_pinned` fires; non-admin receives 403 on the same endpoint | 1440px |
| QA-AF-017 | subscriber | REST + frontend | Logged in; poll post visible with 2 options | Click option A to vote | `POST /buddynext/v1/posts/{id}/vote` with selected option; row inserted in `bn_poll_votes`; `vote_count` on option A increments; fill bar width updates in UI | 1440px, 390px |
| QA-AF-018 | subscriber | REST + frontend | Logged in; already voted on option A of a poll | Click option B (switch vote) | Old row deleted from `bn_poll_votes`; new row inserted; `vote_count` on A decrements, B increments; UI updates to reflect new selection | 1440px, 390px |
| QA-AF-019 | subscriber | REST + frontend | Logged in; already voted on option A | Click option A again (toggle-off vote) | Row deleted from `bn_poll_votes`; `vote_count` on A decrements; UI shows unvoted state | 1440px, 390px |
| QA-AF-020 | subscriber | REST | Logged in; poll with `end_date` in the past | Attempt `POST /buddynext/v1/posts/{id}/vote` | **EXPECTED BEHAVIOR UNCERTAIN:** No cron auto-closes the poll. Code reads `end_date` but no enforcement confirmed server-side. Verify whether voting on an expired poll is blocked or silently allowed. Document result. | 1440px |
| QA-AF-021 | subscriber | REST + frontend | Logged in; another user's post visible | Click Share / Repost | `POST /buddynext/v1/posts/{id}/share`; row inserted in `bn_shares`; share feed post created in `bn_posts` (type=share, `shared_post_id` = original); `share_count` increments on original post; shared post renders in feed | 1440px, 390px |
| QA-AF-022 | subscriber | REST | Logged in; share of a share post visible (post B shares post A) | Click Share on post B | Service flattens chain: share created with `shared_post_id` pointing to post A (the root), not post B; verify `bn_shares.post_id` = A | 1440px |
| QA-AF-023 | subscriber | REST | Logged in; already shared post X | Attempt `POST /buddynext/v1/posts/{id}/share` again on the same post | Duplicate share guard triggers; server returns 409 or existing share; no new row in `bn_shares` | 1440px |
| QA-AF-024 | subscriber | REST + frontend | Logged in; any post visible | Click Bookmark | `POST /buddynext/v1/posts/{id}/bookmark`; `INSERT IGNORE` into `bn_bookmarks`; bookmark icon changes to filled state in UI | 1440px, 390px |
| QA-AF-025 | subscriber | REST + frontend | Logged in; already bookmarked post | Click Bookmark again (unbookmark) | `DELETE /buddynext/v1/posts/{id}/bookmark`; row removed from `bn_bookmarks`; bookmark icon returns to unfilled state | 1440px, 390px |
| QA-AF-026 | subscriber | REST + frontend | Logged in | Navigate to `/me/bookmarks` or bookmarks tab; call `GET /buddynext/v1/me/bookmarks?expand=posts` | Returns paginated bookmarked posts with full post data expanded; renders in bookmark list UI | 1440px, 390px |
| QA-AF-027 | subscriber | REST + frontend | Logged in; composer open with text partially entered | Wait for autosave or manually trigger draft save | `POST /buddynext/v1/me/drafts` stores content to usermeta `bn_composer_draft`; reload page; `GET /buddynext/v1/me/drafts` returns saved content; composer pre-populated | 1440px, 390px |
| QA-AF-028 | subscriber | REST | Logged in; draft saved on device A (mobile) | Open composer on device B (desktop tab) and type different content; autosave triggers | Device B's draft **overwrites** device A's draft in `bn_composer_draft` usermeta; only one slot exists per user; no conflict warning shown | 1440px |
| QA-AF-029 | subscriber | frontend | Logged in; composer open | Open composer, type text, then navigate away without saving | `DELETE /buddynext/v1/me/drafts` called (or draft persists); verify whether dismiss clears or retains draft | 390px |
| QA-AF-030 | subscriber | frontend | User A has blocked user B; User A on home feed | User B creates a new post | Post from user B does not appear in user A's home feed; `PrivacyService::block_exclude_sql()` exclusion confirmed in FeedService query | 1440px, 390px |
| QA-AF-031 | subscriber | frontend | User with `status='suspended'` exists; viewer on home feed | Browse home feed | Suspended user's posts do not appear; `bn_user_suspensions` subquery exclusion confirmed in `home_source_clause()` | 1440px |
| QA-AF-032 | subscriber | frontend | User who is shadow-banned exists; viewer on home feed | Browse home feed | Shadow-banned user's posts excluded from all feed methods; `usermeta bn_shadow_banned` subquery confirmed | 1440px |
| QA-AF-033 | subscriber | frontend | User A's profile; User A has privacy set to "followers only" for activity; viewer is not a follower | Navigate to `/members/{username}/activity/` | Profile feed returns empty or 403; `PrivacyService::can_view_activity()` gates the response | 1440px |
| QA-AF-034 | admin | REST + frontend | Announcement post exists on home feed; logged in as subscriber | Click "Dismiss" on the announcement | `POST /buddynext/v1/feed/announcements/{id}/dismiss` called; announcement no longer appears in this user's feed; dismissal stored (verify usermeta or `bn_announcement_dismissals` table) | 1440px, 390px |
| QA-AF-035 | admin | REST | Logged in as admin; announcement post active | Call `POST /buddynext/v1/feed/announcements/{id}/end` | `is_announcement` or status updated; announcement removed from all feeds; non-admin receives 403 | 1440px |
| QA-AF-036 | admin | REST + frontend | Any post visible | Set content warning via `PUT /buddynext/v1/posts/{id}/content-warning` with `type='nsfw'` | `content_warning=1`, `content_warning_type='nsfw'` set in `bn_posts`; post card renders with content warning overlay and "Show content" toggle in UI | 1440px, 390px |
| QA-AF-037 | subscriber | frontend | Home feed; new posts created since last visit | Observe "N new posts" pill | Pill appears with correct count from `GET /buddynext/v1/feed/new-count`; watermark stored in usermeta; clicking pill scrolls to top and loads new posts | 1440px, 390px |
| QA-AF-038 | subscriber | frontend | Home feed; switch from for-you to another tab and back | Observe feed refresh behavior | Feed cache (30s TTL) means switching tabs within 30s returns cached page-1; after 30s a fresh query runs | 1440px |
| QA-AF-039 | anonymous | frontend | Guest on `/p/{id}/` for a public post | View page source / inspect `<head>` | `<meta property="og:title">`, `og:description`, `og:image`, `twitter:card`, canonical URL, and meta description all present; robots: index, follow | 1440px |
| QA-AF-040 | anonymous | frontend | Guest on `/p/{id}/` for a private or followers-only post | View page source / inspect `<head>` | `<meta name="robots" content="noindex, nofollow">` present; post content gated or empty; no OG image exposing private content | 1440px |
| QA-AF-041 | subscriber | frontend | Composer open on mobile | Open composer; type text; select privacy; submit | Composer occupies full width at 390px with no horizontal overflow; privacy picker accessible; submit button reachable without scrolling past viewport; post appears in feed below | 390px |
| QA-AF-042 | subscriber | frontend | Poll post in feed on mobile | View poll options; vote | Poll fill bars render correctly at 390px; option text does not overflow; vote button touch target is at least 44px; vote registers and fill bar updates | 390px |
| QA-AF-043 | subscriber | frontend | Home feed on mobile; scroll through multiple posts | Scroll through 10+ post cards | No horizontal overflow on any post card type; image posts scale correctly; link preview cards do not overflow; reaction bar wraps cleanly | 390px |
| QA-AF-044 | subscriber | frontend | Logged in; create a post with `type=event` via direct REST call (since UI is incomplete) | `POST /buddynext/v1/posts` with `type=event`, `content=...` | Server accepts the post (type is in allowed list); post stored with `type=event`; frontend post card renders with a fallback or generic type display (since event UI is v0.3 roadmap — no dedicated template expected) | 1440px |

---

## 4. Site-owner expectations & missing must-haves

Priority-ranked from critical to low.

1. **[CRITICAL] Toggle-OFF is broken for 5 Social tab settings.** `buddynext_allow_polls`, `buddynext_allow_shares`, `buddynext_allow_bookmarks`, `buddynext_enable_link_preview`, and `buddynext_enable_emoji_picker` all use `render_toggle_row()` which emits no preceding `<input type="hidden">`. Unchecked states are not submitted in the form POST. A site owner who disables polls or shares via Settings > Social will find the UI silently reverts to enabled on page reload. Root cause: `AdminPageBase.php` toggle renderer missing the hidden fallback input. This is the same toggle bug documented across all tabs — fix must be applied to `render_toggle_row()` globally. Priority: critical.

2. **[CRITICAL] No poll expiry enforcement.** `end_date` is stored in `bn_poll_options` and the code reads it, but no cron job, scheduled action, or hook auto-closes a poll when its `end_date` passes. Polls with set deadlines remain open and voteable indefinitely after expiry. A `CronService` job or `wp_schedule_event` for poll closure is required. Priority: critical (data integrity).

3. **[HIGH] Missing DB-level UNIQUE constraint on `(post_id, user_id)` in `bn_poll_votes`.** The duplicate vote guard is application-code only (SELECT-before-INSERT). Two simultaneous vote requests (double-tap on mobile, slow network retry) can race through and insert two rows for the same user on the same poll. A `ALTER TABLE bn_poll_votes ADD UNIQUE KEY unique_user_poll (post_id, user_id)` constraint would enforce correctness at the DB level and make the application guard redundant rather than load-bearing. Priority: high (data integrity).

4. **[HIGH] `FeedCache::invalidate_all_users()` is a documented no-op.** Any admin action that should immediately propagate to all members (pinned announcement, bulk moderation, banned word enforcement) relies entirely on the 30-second TTL drift. There is no mechanism to forcibly clear all cached feed pages. Site owners expect announcement posts to appear immediately for all members. Priority: high.

5. **[HIGH] `FeedCache::invalidate_writer()` only busts `per_page=10` and `per_page=20`.** If the frontend is configured to fetch `per_page=15`, `per_page=25`, or any other page size, those cache entries are never cleared after a new post is published. The stale feed persists until the 30-second TTL expires. Either invalidate all keys with the `buddynext_feed_` prefix, or restrict supported page sizes to those two values and document the constraint. Priority: high.

6. **[MEDIUM] No index on `(status, created_at)` or `(user_id, status, created_at)` on `bn_posts`.** Home feed "for-you" and "network" subqueries filter on `status='published'` without a composite index that leads with `status`. Profile feed queries filter on `(user_id, status)` but the existing `user_feed` index is `(user_id, created_at)` — `status` is not in the index. At 50 000+ posts these queries will scan the index range and become slow. Add `KEY status_feed (status, created_at)` and `KEY profile_feed (user_id, status, created_at)` to `bn_posts`. Priority: medium (performance / big-site readiness).

7. **[MEDIUM] `buddynext_post_impression` fires per render with no dedup.** Every time a post is rendered in any feed method, `buddynext_post_impression` is dispatched for that post. Fast scrolling through a page of 20 posts fires 20 impressions. Rapid tab switching re-fires all impressions for re-rendered posts. Impression analytics built on this hook will overcount significantly. Add a per-session or per-request dedup layer (e.g., a request-scoped Set of post IDs already impressed). Priority: medium (analytics quality).

8. **[MEDIUM] `buddynext_page_feed` (ID 11) and `buddynext_page_activity` (ID 5) are separate wp_options entries pointing to different pages.** On the live site page 11 is the Explore page and page 5 is the Activity/Feed hub. The distinction is undocumented in the admin UI. A site owner who misconfigures these options will get undefined routing behavior — the feed router may serve content on the wrong page. These options should be unified, or a clear admin UI note should explain which controls the home feed and which controls explore. Priority: medium.

9. **[MEDIUM] Post delete is soft-only — no admin bulk purge tool.** `DELETE /buddynext/v1/posts/{id}` sets `status='deleted'` but never removes the row. Soft-deleted rows accumulate in `bn_posts` indefinitely. For a site with active moderation, this table will grow continuously. An admin UI page or WP-CLI command for bulk-purging soft-deleted posts older than N days is needed. Priority: medium.

10. **[LOW] One composer draft per user — no per-device or per-session isolation.** The `bn_composer_draft` usermeta slot is a single string per user. A member composing on mobile who opens a desktop tab will have their mobile draft overwritten by desktop autosave (and vice versa). No conflict warning is shown. Consider per-session draft keying or at minimum a "draft conflict" notice when a newer draft is detected from another session. Priority: low.

11. **[LOW] Event post type ships with incomplete UI (v0.3 roadmap).** `PostService::create()` accepts `type=event` and the row is stored, but no dedicated post-card template or event fields exist in the free tier frontend. A member who discovers the type via the REST API can create event posts that render as generic cards. The admin hub has no warning about this partial feature. Either gate `event` type at the API layer (return 422 with a "not yet available" message) or document the limitation in the admin UI. Priority: low.

12. **[LOW] No per-space rate-limit or post moderation queue for space posts.** The global `buddynext_post_rate_limit` setting (Moderation tab) applies site-wide. Space owners cannot configure a stricter rate limit or require mod approval before space posts are published. A high-traffic open space has no queue gate. Priority: low.
