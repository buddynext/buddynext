# QA — Reactions, Comments & Hashtags (free)

**Manifest refs:** tables: `bn_reactions` · `bn_comments` · `bn_hashtags` · `bn_post_hashtags` · `bn_hashtag_follows` · REST routes: `/reactions/*` · `/comments/*` · `/hashtags/*` · services: ReactionService, CommentService, HashtagService, HashtagListener · capabilities: logged-in member, `bn_moderator`, `manage_options`
**Cross-ref (no dup):** JOURNEYS J-16 (react to post) · J-17 (comment on post) · J-22 (use hashtag in post) · J-23 (follow hashtag, view hashtag feed) · MATRIX M7 (react/comment) · M17 (hashtags)
**Admin location:** No dedicated admin tab. `buddynext_enabled_reactions` controlled via Settings > Social tab. Banned hashtags controlled via Settings > Moderation tab.

---

## 1. Backend settings & options

Options relevant to this component span two admin tabs (Social and Moderation). There is no dedicated admin tab for reactions, comments, or hashtags.

| Option key | Type | Default | Saved in DB? | Notes / Gaps |
|---|---|---|---|---|
| `buddynext_enabled_reactions` | `array` of slugs | all 6 canonical types: `['like','love','haha','wow','sad','angry']` | No — not present in live `wp_options`; running entirely on code default | Sanitized by `Settings::sanitize_enabled_reactions()`: strips non-canonical slugs, preserves order, always keeps at least one slug. Filterable via `buddynext_reaction_types` for Pro custom types. Because the option is never written to DB on a fresh install, a site owner who opens the Social tab, checks boxes, and saves may see the setting appear saved in the UI but a `wp option get buddynext_enabled_reactions` will confirm whether the value is actually persisted. **Gap: no on-save DB confirmation for the owner.** |
| `buddynext_banned_hashtags` | `string` (newline-separated) | `''` | Yes (Moderation tab save writes it) | Consumed by `HashtagService::extract()` — banned slugs stripped before any tag is indexed or linked. Lives in the Moderation tab, not a hashtag-specific tab. No wildcard or partial-match support; exact slug comparison only. |

**No other dedicated options exist for this component:**

- Comments have no per-site enable/disable toggle. The comment system is always on.
- There is no rate-limit option specific to comment POST requests (unlike posts, which have `buddynext_post_rate_limit`).
- There is no comment-editing window option (unlike posts, which have `buddynext_post_edit_window`).
- `MAX_REPLY_DEPTH = 5` is enforced in `CommentService::create()` code only — it is not stored as an option and cannot be changed by a site owner without code.

**Schema gaps flagged for this section:**

- **`is_pinned` column on `bn_comments` (verify):** `CommentController` exposes `POST /comments/{id}/pin` and `DELETE /comments/{id}/pin`, and `CommentService` has `pin()`/`unpin()` methods. However, the `DESCRIBE wp_bn_comments` output does not include an `is_pinned` column. Pin state may be stored in a separate table, in `usermeta`, or the column was added after the schema snapshot was taken. This is a candidate critical bug — pin/unpin endpoints either silently fail or write to a non-existent column. **Must verify against live DB before marking pin feature done.**
- **Missing index `(object_type, object_id, emoji)` on `bn_reactions`:** `ReactionService::get_counts()` issues a `GROUP BY emoji` on the full reaction set for an object. Without a covering index on `(object_type, object_id, emoji)`, MySQL scans all rows for the object and groups in memory. At scale (high-reaction posts), this scan grows linearly.
- **Missing index on `hashtag_id` alone in `bn_hashtag_follows`:** The primary key is `(user_id, hashtag_id)`. Reverse lookups — "who follows this hashtag" (needed for fan-out notifications and `follower_count` audits) — cannot use the PK efficiently and require a full table scan on larger communities.
- **Missing index on `(hashtag_id, post_id)` in `bn_post_hashtags`:** `HashtagService::sync()` deduplication check ("does this hashtag already link this post?") cannot use the existing `hashtag_feed (hashtag_id, created_at)` index for a fast point lookup and must do a PK scan in the wrong direction.

---

## 2. Frontend functions (service by service)

### ReactionService (`includes/Reactions/ReactionService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / notes |
|---|---|---|---|---|
| `react($user_id, $object_type, $object_id, $emoji)` | Inserts a new reaction row. PK is `(user_id, object_type, object_id)` — changing emoji updates `emoji` column in-place (upsert semantics). | `bn_reactions` (INSERT/UPDATE), WP object cache bust (TTL 300s) | `buddynext_reaction_added` | None. |
| `unreact($user_id, $object_type, $object_id)` | Deletes the reaction row for a user-object pair. | `bn_reactions` (DELETE), cache bust | `buddynext_reaction_removed` | Does not fire `buddynext_reaction_removed` with the emoji arg unless `react()` does a SELECT before DELETE — verify arg signature. |
| `toggle($user_id, $object_type, $object_id, $emoji)` | Checks current reaction: same emoji → calls `unreact()`; different emoji → calls `react($new_emoji)`; no reaction → calls `react()`. | `bn_reactions` (SELECT + INSERT/UPDATE/DELETE), cache bust | Delegates to `react()` / `unreact()` which fire their hooks | **Denormalization risk:** `toggle()` calls `PostService::increment_counter()` / `decrement_counter()` to keep `bn_posts.reaction_count` in sync. There is no transaction wrapping these two writes. If the `bn_reactions` INSERT succeeds but the `bn_posts` UPDATE fails (e.g., deadlock), `reaction_count` on the post drifts from the true count. A daily recount cron (`CronService::handle_recount_stats()`) provides eventual consistency but the window is up to 24 hours. |
| `toggle_reaction($user_id, $object_type, $object_id, $emoji)` | Alias for `toggle()`. | Same as `toggle()` | Same | — |
| `get_counts($object_type, $object_id)` | Returns per-emoji breakdown via `GROUP BY emoji` on `(object_type, object_id)`. | `bn_reactions` (SELECT + GROUP BY), reads from WP object cache (TTL 300s) | None | **Performance gap:** no index on `(object_type, object_id, emoji)`. MySQL scans all reaction rows for the object then groups in memory. Acceptable at low reaction counts per post; degrades linearly at scale. |
| `count($object_type, $object_id)` | Returns total reaction count for an object (single `COUNT(*)` query). | `bn_reactions` (SELECT COUNT) | None | Uses dedicated `COUNT(*)` — no N+1 risk. |
| `has_reacted($user_id, $object_type, $object_id)` | Boolean single-row PK lookup. | `bn_reactions` (SELECT on PK — optimal) | None | — |
| `get_user_emoji($user_id, $object_type, $object_id)` | Returns the current reaction emoji string for the viewer, or empty string if not reacted. | `bn_reactions` (SELECT on PK) | None | — |
| `get_reactors($object_type, $object_id, $emoji, $per_page, $page)` | Paginated list of users who reacted; optionally filtered by emoji. Restrict gate hides blocked users from non-owner/non-admin viewers. | `bn_reactions` JOIN `wp_users` (SELECT + LIMIT/OFFSET) | None | Blocked-user filtering applied correctly per restrict gate. |

---

### CommentService (`includes/Comments/CommentService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / notes |
|---|---|---|---|---|
| `create($user_id, $object_type, $object_id, $content, $parent_id)` | Validates `parent_id` depth (rejects at depth 5); checks if user is suspended; inserts comment row; increments `bn_posts.comment_count` via `PostService::increment_counter()`. | `bn_comments` (INSERT), `bn_posts` (UPDATE via PostService), generation-counter cache bust | `buddynext_comment_created`, `buddynext_post_comment_received` | **MAX_REPLY_DEPTH = 5** enforced in code by walking up the parent chain. Not enforced by DB schema (no depth column, no trigger). Attempting a 6th level returns an error response from the service. Counter increment shares the same no-transaction risk as reactions. |
| `update($comment_id, $user_id, $content)` | Checks ownership or `manage_options` cap. Sets `is_edited = 1`, updates `content` and `updated_at`. | `bn_comments` (UPDATE), cache bust | `buddynext_comment_updated` | No editing time window enforced (unlike posts). Any owner can edit at any time after posting. |
| `delete($comment_id, $user_id)` | Soft-delete: sets `is_deleted = 1`, clears `content` to `''`. Author name replaced with `'Deleted user'` in API response. Tree shape preserved — replies to a deleted comment remain visible. Decrements `bn_posts.comment_count`. | `bn_comments` (UPDATE is_deleted=1), `bn_posts` (UPDATE via PostService), cache bust | `buddynext_comment_deleted` | Soft-delete preserves thread structure, which is the correct behavior. No hard-delete path is exposed at the API level for admins — once soft-deleted, the row persists in the table indefinitely. |
| `pin($comment_id, $space_id_or_context)` | Sets the `is_pinned` flag on the comment row. Moderator/admin only. | `bn_comments` (UPDATE is_pinned=1) — **column existence unverified (see Section 1)** | None confirmed | **Critical candidate bug:** `DESCRIBE wp_bn_comments` does not show an `is_pinned` column. If the column is absent, this method silently fails or throws a DB error. REST endpoint `POST /comments/{id}/pin` exists and `CommentController` calls this method. Must verify column in live DB schema. |
| `unpin($comment_id)` | Clears `is_pinned` flag. Moderator/admin only. | `bn_comments` (UPDATE is_pinned=0) — same caveat | None confirmed | Same as `pin()` — column existence must be verified. |
| `list($object_type, $object_id, $per_page, $page)` | Builds N-deep tree up to `MAX_REPLY_DEPTH = 5`. Fetches top-level comments (parent_id IS NULL) paginated; fetches all replies for those top-level comments in one extra query; attaches replies per parent. Pinned comment prepended at top. Restrict gate applied (blocked users hidden). | `bn_comments` (SELECT × 2 — top-level + replies batch), WP object cache (generation-counter strategy, TTL 300s) | None | Two-query strategy avoids N+1 for reply attachment. Cache uses generation-counter bust strategy — any write to the comment set increments a generation key, invalidating all cached list pages. |
| `list_for_object($object_type, $object_id, $per_page, $page)` | Paginated top-level-only comments (parent_id IS NULL). Used by REST GET /comments. | `bn_comments` (SELECT + LIMIT/OFFSET), cache | None | Delegates to `list()` internally. |

---

### HashtagService (`includes/Hashtags/HashtagService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / notes |
|---|---|---|---|---|
| `extract($text)` | Regex-based extraction of hashtag slugs from post content. Pattern filterable via `buddynext_hashtag_pattern`. Strips slugs present in `buddynext_banned_hashtags` option before returning. | None (pure string operation) | None | Banned hashtag check is exact-slug comparison only — no wildcard, no partial match. |
| `sync($post_id, $object_type, array $tag_slugs)` | For each extracted slug: upserts row in `bn_hashtags` (INSERT ... ON DUPLICATE KEY UPDATE); inserts into `bn_post_hashtags` pivot; removes obsolete pivot rows for tags no longer in content; recomputes `post_count` on `bn_hashtags`. | `bn_hashtags` (UPSERT), `bn_post_hashtags` (INSERT + DELETE), cache group bust (`buddynext_hashtags`, TTL 300s) | None | **Missing index on `(hashtag_id, post_id)` in `bn_post_hashtags`:** the deduplication lookup during sync cannot use `hashtag_feed (hashtag_id, created_at)` for a fast point lookup. At high post volume, sync writes degrade. |
| `get_trending($limit)` | 24-hour rolling window `COUNT` on `bn_post_hashtags` grouped by `hashtag_id`, ordered by count descending. Cached 300s. | `bn_post_hashtags` (SELECT + GROUP BY + LIMIT), `bn_hashtags` (JOIN for name/slug), object cache | None | Count scan covers 24h window — acceptable. Cache TTL 300s means trending list can lag up to 5 minutes. |
| `autocomplete($query, $limit)` | Prefix `LIKE` search on `bn_hashtags.slug`. Case-normalized because slugs are lowercased on insert. | `bn_hashtags` (SELECT WHERE slug LIKE 'prefix%' LIMIT n) | None | LIKE with trailing wildcard uses the `slug` UNIQUE index efficiently (prefix scan). No full-text required. |
| `follow($user_id, $hashtag_id)` | INSERT into `bn_hashtag_follows`; increments `bn_hashtags.follower_count`. | `bn_hashtag_follows` (INSERT), `bn_hashtags` (UPDATE follower_count), cache bust | None | **Missing reverse index:** `bn_hashtag_follows` PK is `(user_id, hashtag_id)`. Lookup "all followers of hashtag X" requires a full table scan. At scale this affects fan-out notification and count audit queries. |
| `unfollow($user_id, $hashtag_id)` | DELETE from `bn_hashtag_follows`; decrements `bn_hashtags.follower_count`. | `bn_hashtag_follows` (DELETE), `bn_hashtags` (UPDATE follower_count), cache bust | None | Same reverse-index gap as `follow()`. |
| `is_following($user_id, $hashtag_id)` | PK lookup on `bn_hashtag_follows`. | `bn_hashtag_follows` (SELECT on PK — optimal) | None | — |
| `get_feed($hashtag_id, $per_page, $cursor)` | Keyset cursor pagination on `bn_post_hashtags JOIN bn_posts`. Filters `bn_posts.privacy = 'public'` only. | `bn_post_hashtags` JOIN `bn_posts` (SELECT + keyset WHERE), cache | None | **Privacy exclusion gap:** only `privacy = 'public'` posts are returned. Posts with `privacy = 'followers'`, `'connections'`, or `'space_members'` are excluded from the hashtag feed even when the viewer is entitled to see them (i.e., they follow the author or are in the space). This is a silent business logic gap — a member's post tagged `#announcement` with `followers` privacy will not appear in the `#announcement` feed for their followers. |
| `register($slug)` | Upserts a hashtag row by slug (case-normalized). Returns the hashtag ID. | `bn_hashtags` (INSERT ... ON DUPLICATE KEY) | None | — |
| `trending($limit)` | Alias for `get_trending($limit)`. | Same as `get_trending()` | None | — |
| `extract_from_text($text)` | Alias for `extract($text)`. | None | None | — |

---

### HashtagListener (`includes/Hashtags/HashtagListener.php`)

| Method / Hook | What it does | Hooks consumed | Hooks fired | Candidate bugs / notes |
|---|---|---|---|---|
| `register()` | Wires `buddynext_post_created` → `on_post_created()`; wires `buddynext_index_hashtags` → `on_index_hashtags()`; wires `buddynext_async_index_hashtags` → `async_index()` (AS worker). | — | — | — |
| `on_post_created($post_id, $user_id, $type)` | Dispatches async hashtag extraction for post types: `text`, `link`, `announcement`, `activity`. Schedules `buddynext_async_index_hashtags` via Action Scheduler if available; falls back to inline `do_action_ref_array`. | `buddynext_post_created` | `buddynext_async_index_hashtags` | **Extraction gap:** only 4 of the 10 known post types are dispatched. Post types `photo`, `poll`, `media`, `discussion`, `job`, `event` are NOT in the dispatch list. Hashtags in photo captions, poll questions, media descriptions, job posts, event details, and discussion bodies are never indexed. This is a known gap — affects 6 post types. |
| `async_index($post_id)` | Worker: fetches post `content` from `bn_posts` by ID; calls `HashtagService::extract()` then `HashtagService::sync()`. | `buddynext_async_index_hashtags` | None | Falls back to inline sync if Action Scheduler unavailable. No retry on DB fetch failure — if the post row is not yet committed when the AS job fires, the worker silently no-ops. |
| `on_index_hashtags($post_id, $content)` | Bridge entry for external content (Jetonomy, Career Board). Calls `extract()` + `sync()` directly with provided content. | `buddynext_index_hashtags` | None | Used by Jetonomy and Career Board bridges to index hashtags in forum posts and job posts without going through the async queue. |

---

## 3. QA cases

| ID | Role | Layer | Pre-conditions | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-RCH-001 | member | frontend | Logged in; post exists with no reactions | Navigate to a post; open the reaction picker; click the `like` reaction | Reaction recorded in `bn_reactions`; `bn_posts.reaction_count` increments by 1; reaction bar shows `like` emoji with count 1; viewer state reflects `like` selected | 1440px, 768px, 390px |
| QA-RCH-002 | member | frontend | Same as QA-RCH-001 but repeat for each remaining type | Repeat QA-RCH-001 for `love`, `haha`, `wow`, `sad`, `angry` on separate posts | Each emoji is accepted; `bn_reactions.emoji` column stores the correct slug; reaction counts reflect each type | 1440px, 390px |
| QA-RCH-003 | member | frontend | Member has reacted `like` to a post | Open reaction picker on same post; click `love` | `bn_reactions` row for `(user_id, 'post', post_id)` updates `emoji` from `like` to `love` in-place (no duplicate row); `like` count decrements, `love` count increments; viewer state reflects `love` | 1440px, 390px |
| QA-RCH-004 | member | frontend | Member has reacted `like` to a post | Open reaction picker; click `like` again | Reaction row deleted from `bn_reactions` (unreact); `bn_posts.reaction_count` decrements; reaction bar reflects no active reaction for viewer; viewer state clears | 1440px, 390px |
| QA-RCH-005 | member | REST API | Post exists; user is authenticated | `POST /buddynext/v1/reactions/toggle` with `object_type=comment`, `object_id={comment_id}`, `emoji=love` | Reaction recorded with `object_type='comment'`; `GET /reactions?object_type=comment&object_id={id}` returns `love: 1`; no `bn_posts.reaction_count` change (comments are separate object type) | N/A |
| QA-RCH-006 | member A (blocked by member B) | frontend | Member B has blocked member A; Member B reacted to post | Member A calls `GET /buddynext/v1/reactions/list?object_type=post&object_id={id}` | Member B does NOT appear in the reactor list returned to Member A (restrict gate applied in `get_reactors()`) | N/A |
| QA-RCH-007 | admin | REST API + DB | Member has reacted; post exists | React (toggle once); verify `bn_posts.reaction_count`; kill DB mid-toggle on a test instance (simulate failure); check `bn_reactions` vs `bn_posts.reaction_count` | **Expected gap:** if `bn_reactions` INSERT succeeds but `bn_posts` UPDATE fails, `reaction_count` drifts. Normal run: both writes succeed and counts match. Confirm daily recount cron corrects drift. `SELECT COUNT(*) FROM bn_reactions WHERE object_type='post' AND object_id={id}` should equal `reaction_count` on the post row. | N/A |
| QA-RCH-008 | guest | REST API | No auth | `POST /buddynext/v1/reactions/toggle` without authentication | 401 Unauthorized response | N/A |
| QA-RCH-009 | member | frontend | Post exists; no comments | Click "Comment"; type content; submit | Comment appears in thread; `bn_comments` row inserted with `parent_id = NULL`; `bn_posts.comment_count` increments; `buddynext_comment_created` hook fires | 1440px, 768px, 390px |
| QA-RCH-010 | member | frontend | Post exists with one top-level comment | Click "Reply" on that comment; type content; submit | Reply inserted with correct `parent_id`; thread renders nested one level; `bn_posts.comment_count` increments | 1440px, 390px |
| QA-RCH-011 | member | REST API | Comment tree at depth 4 exists | `POST /buddynext/v1/comments` with `parent_id` pointing to depth-4 comment | Comment created at depth 5 (accepted) | N/A |
| QA-RCH-012 | member | REST API | Comment tree at depth 5 exists | `POST /buddynext/v1/comments` with `parent_id` pointing to depth-5 comment | 422 or 400 error returned; no row inserted in `bn_comments`; `MAX_REPLY_DEPTH = 5` enforced | N/A |
| QA-RCH-013 | member (comment owner) | frontend | Member has posted a comment | Click "Edit" on own comment; change content; save | `bn_comments.content` updated; `is_edited = 1`; `updated_at` refreshed; "(edited)" indicator visible in UI | 1440px, 390px |
| QA-RCH-014 | member (comment owner) | frontend | Member has posted a comment with two replies | Click "Delete" on own comment | Comment soft-deleted: `is_deleted = 1`, `content = ''`; thread renders `[deleted]` placeholder where content was and `Deleted user` for author; reply count and child replies remain visible | 1440px, 390px |
| QA-RCH-015 | admin | REST API | Soft-deleted comment exists | Verify via DB: `SELECT content, is_deleted FROM bn_comments WHERE id={id}` | `content = ''`, `is_deleted = 1`; no hard-delete path exposed via REST; row persists indefinitely | N/A |
| QA-RCH-016 | moderator | REST API | Comment exists; moderator authenticated | `POST /buddynext/v1/comments/{id}/pin` | **Pre-verify column:** if `is_pinned` column exists in `bn_comments`, row updated and `GET /comments` returns pinned comment first; if column absent, 500 error or silent no-op — this is the candidate bug to confirm | 1440px, 390px |
| QA-RCH-017 | moderator | frontend | Post with multiple comments; one comment pinned | Load comment thread | Pinned comment appears first in the list regardless of chronological order; non-pinned comments follow in order; pinned comment has visual indicator | 1440px, 390px |
| QA-RCH-018 | member | REST API | Suspended user's post exists (suspension active) | Authenticated member attempts `POST /buddynext/v1/comments` on suspended user's post | `CommentService::create()` checks suspension status; if the commenter themselves is suspended, 403 returned; post author's suspension status does not block comments on their content (comments on suspended user's posts are allowed unless moderation hides them) | N/A |
| QA-RCH-019 | member | frontend | Post with `#testhashtag` in content just created | Wait for async hashtag extraction (Action Scheduler); then call `GET /buddynext/v1/hashtags/autocomplete?q=test` | `testhashtag` appears in autocomplete results; `bn_hashtags` has a row with slug `testhashtag`; `bn_post_hashtags` has the pivot row | 1440px, 390px |
| QA-RCH-020 | member | frontend | Member follows hashtag `#testhashtag`; a public post with that tag exists | Navigate to hashtag feed for `#testhashtag` | Public post appears in the hashtag feed; `bn_hashtag_follows` has the member's row; `bn_hashtags.follower_count` reflects the follow | 1440px, 390px |
| QA-RCH-021 | member | frontend | Member follows user B; user B creates a post tagged `#testhashtag` with `privacy = 'followers'` | Navigate to hashtag feed for `#testhashtag` | **Expected gap:** post does NOT appear in the hashtag feed because `HashtagService::get_feed()` filters `privacy = 'public'` only. Even though the viewer follows user B, follower-privacy posts are excluded from hashtag feeds. This is a known silent exclusion. | 1440px |
| QA-RCH-022 | member | frontend | Posts exist in the last 24 hours with hashtag `#trending` | Call `GET /buddynext/v1/hashtags/trending` | `#trending` appears in the trending list; count reflects posts in the rolling 24h window; response cached for up to 300s | 1440px, 390px |
| QA-RCH-023 | member | frontend | Autocomplete endpoint available | Type `#test` in the post composer hashtag input | `GET /buddynext/v1/hashtags/autocomplete?q=test` fires; results containing prefix `test` in slug returned; results sorted by `post_count` descending | 1440px, 390px |
| QA-RCH-024 | admin | REST API + DB | `buddynext_banned_hashtags` option set to `badtag` | Create a post with content containing `#badtag #goodtag` | After async extraction: `bn_post_hashtags` has a pivot row for `goodtag`; no pivot row for `badtag`; `bn_hashtags` has no row for `badtag` (banned slug stripped before indexing) | N/A |
| QA-RCH-025 | member | frontend | Photo post created with `#photohashtag` in caption | Wait for async extraction; call `GET /buddynext/v1/hashtags/autocomplete?q=photo` | **Expected gap:** `#photohashtag` does NOT appear in autocomplete. `HashtagListener::on_post_created()` only dispatches extraction for post types `text`, `link`, `announcement`, `activity`. Photo posts are excluded. This is a confirmed known gap. | 1440px |
| QA-RCH-026 | member | frontend | Poll post created with `#pollhashtag` in question text | Same as QA-RCH-025 | **Expected gap:** `#pollhashtag` not indexed. Poll, media, discussion, job, event post types all share this extraction gap. | 1440px |
| QA-RCH-027 | member | frontend | Reaction picker rendered on post card | Open reaction picker at 390px viewport | Reaction picker renders within viewport without horizontal overflow; all 6 reaction icons visible and tappable; picker closes on outside tap | 390px |
| QA-RCH-028 | member | frontend | Comment thread with 3 levels of nesting | View comment thread at 390px viewport | Thread renders without horizontal overflow; reply indentation is readable; submit button reachable; keyboard does not cause layout shift | 390px |
| QA-RCH-029 | member | frontend | Post composer open | Begin typing a hashtag `#tag` in composer at 390px viewport | Autocomplete dropdown renders within viewport; suggestions tappable; selected tag is inserted correctly into content | 390px |

---

## 4. Site-owner expectations & missing must-haves

The following issues are ranked by priority. "Critical" means the feature is broken or data loss is possible. "High" means a major gap that affects normal use. "Medium" means a significant UX or data quality gap. "Low" means minor polish or documentation.

**Priority: critical (verify)**
- **`is_pinned` column missing from `bn_comments` schema.** `CommentController` exposes `POST /comments/{id}/pin` and `DELETE /comments/{id}/pin`, and `CommentService::pin()`/`unpin()` exist and are called by those routes. `DESCRIBE wp_bn_comments` does not show an `is_pinned` column. If the column is absent from the live table, pin/unpin REST calls will either throw a DB error (visible as a 500) or silently fail (if the `UPDATE` runs against a non-existent column in some MySQL modes). This must be verified against the live DB before the pin feature is considered functional. Fix: add `is_pinned tinyint(1) NOT NULL DEFAULT 0` via `dbDelta()` in a version-gated `Installer` update and add a `deleted (is_deleted)` parallel index for `is_pinned`.

**Priority: high**
- **No transaction wrapping reaction toggle + post counter update.** `ReactionService::toggle()` performs two writes: (1) INSERT/UPDATE/DELETE on `bn_reactions`, (2) `PostService::increment_counter()` / `decrement_counter()` on `bn_posts`. These are not wrapped in a DB transaction. If write (1) succeeds and write (2) fails (deadlock, timeout, connection loss), `bn_posts.reaction_count` silently drifts from the true count. The daily recount cron provides eventual consistency but the window is up to 24 hours. Same pattern applies to `CommentService::create()` and `delete()`. Fix: wrap both writes in `$wpdb->query('START TRANSACTION')` / `COMMIT` / `ROLLBACK`, or accept the eventual consistency model and document it explicitly.

- **Hashtag extraction covers only 4 of 10 post types.** `HashtagListener::on_post_created()` dispatches async extraction only for `text`, `link`, `announcement`, and `activity` post types. Post types `photo`, `poll`, `media`, `discussion`, `job`, and `event` are skipped. A member who tags a photo `#travel` or a poll `#sports` will find their content absent from hashtag feeds and autocomplete. Fix: extend the dispatch list to include all content post types, or document explicitly which post types support hashtag indexing.

- **Hashtag feed silently excludes non-public posts even when the viewer is entitled.** `HashtagService::get_feed()` filters `bn_posts.privacy = 'public'` unconditionally. Posts with `privacy = 'followers'`, `'connections'`, or `'space_members'` are excluded from hashtag feeds regardless of whether the viewer has access to that content through the social graph. A user following someone who posts `#news` with follower-only privacy will never see that post in the `#news` hashtag feed. Fix: expand the feed query to include posts where the viewer is in the permitted audience, using the same privacy gate pattern applied in `FeedService::home_feed()`.

- **No admin UI to view or moderate all comments.** There is no admin list page for `bn_comments` in the BuddyNext admin hub. A site owner cannot search, filter, bulk-delete, or review comments without direct DB access or WP-CLI. The Members admin page lists members; the Moderation queue handles reported content; but individual comment review is not surfaced. Fix: add a Comments admin sub-page under the BuddyNext menu with search, object-type filter, is_deleted filter, and soft-delete/hard-delete actions.

**Priority: medium**
- **No per-emoji index on `bn_reactions` — `get_counts()` scans full object reaction set.** At low reaction volumes this is acceptable. For viral posts with thousands of reactions, `GROUP BY emoji` without a covering index on `(object_type, object_id, emoji)` scans every reaction row for the post. Fix: add `KEY emoji_counts (object_type, object_id, emoji)` to `bn_reactions` via `dbDelta()`.

- **No index on `hashtag_id` alone in `bn_hashtag_follows`.** Reverse lookups ("who follows this hashtag?") are needed for fan-out notification and `follower_count` audit queries. The PK `(user_id, hashtag_id)` does not support this direction efficiently. Fix: add `KEY hashtag_followers (hashtag_id)` to `bn_hashtag_follows`.

- **`buddynext_enabled_reactions` not written to DB on fresh install.** The option runs on code defaults (all 6 types) without ever being persisted to `wp_options`. A site owner who opens Settings > Social and saves without changing anything will not create a DB entry. If the code default changes in a future release, all sites running on the implicit default will silently adopt the new default with no option to restore the old one. Fix: write the default to `wp_options` during plugin activation in `Installer::run()` using `add_option()`.

- **No rate-limit on comment POST endpoint.** `buddynext_post_rate_limit` applies to post creation only. There is no equivalent for comments. A member or bot can comment without API-level throttling. Fix: add a per-user comment rate-limit option to the Moderation tab and enforce it in `CommentService::create()` using a transient-based counter.

- **No per-post comment count admin view.** Admins cannot see which posts have the most comments without a DB query. The Members admin shows post-level data is not surfaced. A comment count column on the Moderation queue or a dedicated Comments admin list would help community managers identify high-activity posts.

**Priority: low**
- **Comment editing window not enforced.** Posts have `buddynext_post_edit_window` (default 15 minutes). Comments have no equivalent. A member can edit a comment hours or days after posting with no indication to other readers that the content changed beyond the `(edited)` flag. Fix: add a `buddynext_comment_edit_window` option to the Social tab, default 60 minutes, enforced in `CommentService::update()`.

- **`MAX_REPLY_DEPTH = 5` is not documented in the UI.** When a member attempts a 6th-level reply, they receive a generic error response with no explanation. Fix: surface the depth limit in the UI (e.g., "Maximum reply depth reached") using the error message from `CommentService::create()` and disable the Reply button at depth 5 client-side.

- **No index on `(hashtag_id, post_id)` in `bn_post_hashtags` for sync deduplication.** `HashtagService::sync()` checks whether a pivot row already exists before inserting. Without a `(hashtag_id, post_id)` index, this lookup cannot use the existing `hashtag_feed (hashtag_id, created_at)` index. At high post-sync volume this is a minor write overhead. Fix: add `KEY hashtag_post_lookup (hashtag_id, post_id)` to `bn_post_hashtags`.
