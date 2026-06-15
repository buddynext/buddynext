# QA — Social Graph (free)

**Manifest refs:** tables: `bn_follows` · `bn_connections` · `bn_blocks` · REST routes: `buddynext/v1/users/{id}/follow*` · `buddynext/v1/me/follow-requests*` · `buddynext/v1/users/{id}/connect*` · `buddynext/v1/me/connections*` · `buddynext/v1/users/{id}/block*` · `buddynext/v1/users/{id}/mute*` · `buddynext/v1/users/{id}/restrict*` · `buddynext/v1/me/blocked*` · `buddynext/v1/me/muted*` · `buddynext/v1/me/restricted*` · services: FollowService · ConnectionService · BlockService · PrivacyService · UserCleanupListener · capabilities: `manage_options` (admin endpoints only)
**Cross-ref (no dup):** JOURNEYS J-27 (follow a member from directory) · J-28 (follow request / private account) · J-31 (block/unblock from profile) · FLOW-TEST-MATRIX M10 (follow/unfollow flow) · M11 (connection request/accept/remove) · M12 (block/mute/restrict)
**Admin location:** No dedicated Social Graph admin tab. Social-graph–relevant options live in BuddyNext → Settings → Social tab (`admin.php?page=buddynext&tab=social`). Per-user privacy preferences are stored in `wp_usermeta` and are managed by the user, not the admin.

---

## 1. Backend settings & options (justify each)

Social Graph options span two storage layers: (1) global `wp_options` keys configured by the site owner in the Social tab, and (2) per-user `wp_usermeta` keys controlled by individual members via `PrivacyService`. Neither layer has a dedicated Social Graph admin tab — they are embedded in the general Social tab and in member-facing profile settings respectively.

Toggle options (type `boolean`) rendered via `render_toggle_row()` are flagged **[TOGGLE-BUG]**: unchecked state is never saved because no preceding hidden `<input type="hidden">` is emitted (see `AdminPageBase.php` `render_toggle_row()`). This affects all three boolean `wp_options` keys below.

### wp_options keys (site-wide defaults, Social tab)

| Option key | Type | Default | Saved? | Notes / Gaps |
|---|---|---|---|---|
| `buddynext_default_post_privacy` | select (public / followers / connections / private) | `'public'` | Never written — absent from live `wp_options` | Controls the default selection in the composer privacy picker. Running on code default. No gap in the select mechanism itself (not a toggle); but site owner has never confirmed this via the admin UI. |
| `buddynext_allow_polls` | boolean toggle | `true` | Never written — absent from live `wp_options` | Controls whether the poll composer type is available. **[TOGGLE-BUG]**: if an admin unchecks this and saves, no hidden input is submitted, so the option stays `1` (ON). Cannot be turned OFF reliably. |
| `buddynext_allow_shares` | boolean toggle | `true` | Never written — absent from live `wp_options` | Controls whether the share/repost action appears on posts. **[TOGGLE-BUG]**: same unchecked-state bug; cannot be turned OFF. |
| `buddynext_allow_bookmarks` | boolean toggle | `true` | Never written — absent from live `wp_options` | Controls whether the bookmark action appears on posts. **[TOGGLE-BUG]**: same unchecked-state bug; cannot be turned OFF. |

### wp_usermeta keys (per-user privacy preferences, managed by PrivacyService)

All keys use the prefix `bn_privacy_`. Written via `PrivacyService::set_preference()`. No admin UI exists to read or override these values for individual members. No site-wide default can be pushed to all existing users.

| Meta key | Type | Default (code) | Saved? | Notes / Gaps |
|---|---|---|---|---|
| `bn_privacy_who_can_follow` | enum: `everyone` / `nobody` | `'everyone'` | Yes — written on member privacy save | Controls `FollowService::follow()` gate via `PrivacyService::can_follow()`. When `nobody`, the follow endpoint returns an error for all non-self callers. No admin override. No UI for the site owner to see which members have locked their follow. |
| `bn_privacy_who_can_connect` | enum: `everyone` / `followers` / `nobody` | `'everyone'` | Yes — written on member privacy save | Controls `ConnectionService::send_request()` gate via `PrivacyService::can_connect()`. When `followers`, a non-follower cannot send a connection request. No admin override. |
| `bn_privacy_profile_visibility` | enum: `public` / `followers` / `connections` / `private` | `'public'` | Yes — written on member privacy save | Controls what profile sections non-permitted visitors can see. Enforced in profile view template and `PrivacyService::block_exclude_sql()`. No admin override or site-wide default. |

### wp_usermeta keys (account privacy, per-user)

| Meta key | Type | Default | Saved? | Notes / Gaps |
|---|---|---|---|---|
| `bn_account_private` | boolean | unset (false) | Yes — set per-user | When truthy, `FollowService::follow()` stores `status='pending'` instead of `'approved'` and fires `buddynext_follow_requested` instead of `buddynext_user_followed`. No admin-level UI to toggle this for a specific user. No site-wide setting to make all accounts private or all accounts public by default. Admin has no dashboard view of which accounts are private. |

---

## 2. Frontend functions — service by service

### FollowService (`includes/SocialGraph/FollowService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `follow(int $follower_id, int $following_id)` | Checks `PrivacyService::can_follow()`. Checks `BlockService::is_blocked()` (bidirectional). If target has `bn_account_private`, inserts `bn_follows` with `status='pending'` and fires `buddynext_follow_requested`; otherwise inserts `status='approved'`, fires `buddynext_user_followed` + `buddynext_follower_gained` + (on first follow ever) `buddynext_user_followed_first_time`. | `bn_follows` (INSERT); `buddynext_follows` cache group busted | `buddynext_follow_requested` OR `buddynext_user_followed` + `buddynext_follower_gained` (+ `buddynext_user_followed_first_time`) | No rate-limit guard — an authenticated user can spam follow requests. No check for self-follow at the service layer (only at controller). |
| `unfollow(int $follower_id, int $following_id)` | Deletes the row from `bn_follows`. Fires `buddynext_user_unfollowed`. Busts cache. | `bn_follows` (DELETE); cache bust | `buddynext_user_unfollowed` | Return type was `void` in early versions; confirmed fixed to `bool`. No cleanup of pending follow-request row if the follower withdraws before approval — the only removal path is `reject_follow_request()` on the recipient side. There is no "withdraw my pending follow request" endpoint exposed to the requester. |
| `followers(int $user_id, int $page, int $per_page)` | Returns paginated list of approved followers. Calls `filter_blocked()` to strip blocked users from results. | `bn_follows` (SELECT with `following_id` + `status='approved'`); `buddynext_follows` cache | None | Uses `following` index (`following_id`, `status`) — efficiently covered. `filter_blocked()` performs an additional per-batch query; at very large scale this is an N+1 pattern if block lists are large. |
| `following(int $user_id, int $page, int $per_page)` | Returns paginated list of users that `$user_id` follows (approved only). | `bn_follows` (SELECT via PRIMARY scanning `follower_id`); cache | None | PRIMARY index is `(follower_id, following_id)` — follower-first lookup is covered. However, filtering `status='approved'` on this scan has no dedicated index for `(follower_id, status)`, meaning an extra status filter pass on what could be a large rowset if a user follows thousands. Not critical at current scale but a gap at 10k+ follows per user. |
| `suggestions(int $user_id, int $limit)` | Computes friends-of-friends in PHP: fetches the user's following list, then for each followed user fetches their following list, flattens, deduplicates in PHP with `array_unique`, removes already-followed users, returns up to `$limit` results. | `bn_follows` (multiple SELECTs, no JOIN) | None | **N+1 risk**: one query per followed user to fetch their following list. For a user following 500 people, this is 500 queries. Should be replaced with a single SQL `JOIN` or `IN()` query. No SQL-level computation. |
| `approve_follow_request(int $following_id, int $follower_id)` | Updates `status='approved'` on the `bn_follows` row. Fires `buddynext_follow_request_approved`. Busts cache. | `bn_follows` (UPDATE); cache bust | `buddynext_follow_request_approved` | No guard against approving a row that is already `approved` — double-approve is a no-op (UPDATE WHERE status='pending'). Safe but silent. |
| `reject_follow_request(int $following_id, int $follower_id)` | Deletes the pending `bn_follows` row. Fires `buddynext_follow_request_rejected`. Busts cache. | `bn_follows` (DELETE); cache bust | `buddynext_follow_request_rejected` | Deleting the row means the requester has no record of their request ever existing. If they attempt to follow again, a new pending row is inserted. This is correct behavior but could confuse users who expected a "declined" state. |
| `get_follow_requests(int $user_id, int $page, int $per_page)` | Returns paginated pending inbound follow requests. Uses `pending_inbox` index (`following_id`, `status`, `created_at`). | `bn_follows` (SELECT); cache | None | Index covers this query well. |
| `get_follow_requests_count(int $user_id)` | Returns count of pending inbound follow requests. | `bn_follows` (SELECT COUNT(*)); cache | None | Properly uses `COUNT(*)` — no N+1. |
| `follow_status(int $follower_id, int $following_id)` | Returns the `status` enum value (`approved` / `pending`) or `null` if no row exists. | `bn_follows` (SELECT single row via PRIMARY); cache | None | |
| `get_account_type(int $user_id)` | Returns `'private'` or `'public'` based on `bn_account_private` usermeta. | `wp_usermeta` | None | No caching — each call is a `get_user_meta()` hit. Acceptable for single lookups; could degrade in directory batch rendering. |

**Cache group:** `buddynext_follows`, TTL 600 s.

---

### ConnectionService (`includes/SocialGraph/ConnectionService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `send_request(int $requester_id, int $recipient_id, string $note)` | Checks `PrivacyService::can_connect()`. Checks `BlockService::is_blocked()`. Caps `$note` at 280 chars. Inserts into `bn_connections` with `status='pending'`. | `bn_connections` (INSERT); `buddynext_connections` cache bust | `buddynext_connection_requested` | No rate-limit guard. No guard against sending a request when one is already pending in the reverse direction (recipient → requester). |
| `accept_request(int $connection_id, int $actor_id)` | Updates `bn_connections.status='accepted'`. Fires `buddynext_connection_accepted`. | `bn_connections` (UPDATE); cache bust | `buddynext_connection_accepted` | |
| `decline_request(int $connection_id, int $actor_id)` | Updates `bn_connections.status='declined'`. Fires `buddynext_connection_declined`. | `bn_connections` (UPDATE); cache bust | `buddynext_connection_declined` | A declined request row stays in the table with `status='declined'`. No automatic cleanup. Requester could see stale "declined" state. |
| `withdraw_request(int $requester_id, int $recipient_id)` | Falls back to `remove_connection()` if no pending row is found. Fires `buddynext_connection_withdrawn`. | `bn_connections` (DELETE / UPDATE); cache bust | `buddynext_connection_withdrawn` | |
| `remove_connection(int $user_a, int $user_b)` | Deletes the accepted `bn_connections` row. Fires `buddynext_connection_withdrawn`. | `bn_connections` (DELETE); cache bust | `buddynext_connection_withdrawn` | Uses the UNIQUE `(requester_id, recipient_id)` pair to find the row — correct. |
| `mutual_connections(int $user_a, int $user_b)` | Fetches the accepted connection IDs for both users in two separate queries, then computes intersection via `array_intersect()` in PHP. | `bn_connections` (2 × SELECT); cache | None | **N+1 / scale risk**: for users with thousands of connections, two unbounded `SELECT recipient_id ... UNION SELECT requester_id` queries return full lists into PHP. Should be replaced with a SQL self-join or `IN(SELECT ...)`. |
| `statuses_for(int $viewer_id, array $target_ids)` | Batch-fetches connection status for a viewer against many targets. Single query using `IN()`. | `bn_connections` (1 × SELECT); cache | None | Correctly avoids N+1 for directory rendering. |
| `connections(int $user_id, int $limit, int $offset)` | Returns paginated accepted connections. Uses `recipient_status` and `requester_status` indexes. | `bn_connections` (SELECT); cache | None | |
| `pending_sent(int $user_id, int $limit, int $offset)` | Returns pending requests sent by user. | `bn_connections` (SELECT via `requester_status` index); cache | None | |
| `pending_received(int $user_id, int $limit, int $offset)` | Returns pending requests received by user. | `bn_connections` (SELECT via `recipient_status` index); cache | None | |

**Cache group:** `buddynext_connections`, TTL 600 s.

---

### BlockService (`includes/SocialGraph/BlockService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `block(int $blocker_id, int $blocked_id)` | If a mute row exists for the pair, upgrades it via `ON DUPLICATE KEY UPDATE type='block'`. Otherwise inserts `type='block'`. Fires `buddynext_block`. | `bn_blocks` (INSERT / ON DUPLICATE KEY UPDATE); `buddynext_blocks` cache bust | `buddynext_block` | **Missing index**: `bn_blocks` has only `PRIMARY (blocker_id, blocked_id)`. Upgrading from mute requires a lookup by `(blocker_id, blocked_id)` which is the PK — fine. But `is_blocked()` checks BOTH directions (see below) and the reverse direction lookup (`blocked_id` as the leading column) has no supporting index. |
| `unblock(int $blocker_id, int $blocked_id)` | Deletes the `type='block'` row. Fires `buddynext_unblock`. | `bn_blocks` (DELETE); cache bust | `buddynext_unblock` | |
| `mute(int $muter_id, int $muted_id)` | Uses `INSERT IGNORE` — never downgrades an existing block to mute. Fires no hook (mute is a soft action). | `bn_blocks` (INSERT IGNORE); cache bust | None | No hook fired on mute — addons/bridges cannot react to mute events. |
| `unmute(int $muter_id, int $muted_id)` | Deletes `type='mute'` row (uses `AND type='mute'` so it cannot accidentally delete a block row). | `bn_blocks` (DELETE); cache bust | None | |
| `restrict(int $restrictor_id, int $restricted_id)` | Instagram-style soft block. Restricted users see public posts but their replies are hidden from others. | `bn_blocks` (INSERT / ON DUPLICATE KEY UPDATE); cache bust | None | |
| `unrestrict(int $restrictor_id, int $restricted_id)` | Deletes `type='restrict'` row. | `bn_blocks` (DELETE); cache bust | None | |
| `is_blocked(int $user_a, int $user_b)` | **Bidirectional**: checks if either `(user_a blocks user_b)` OR `(user_b blocks user_a)`. Delegates to `is_blocking_either()`. | `bn_blocks` (SELECT); cache | None | **Index gap**: the reverse direction check (`blocked_id = user_a` with type filter) requires a full PK scan starting from `blocker_id`. `bn_blocks` has no index on `blocked_id` alone. At large scale (10k+ block rows) this becomes a sequential scan. A compound index on `(blocked_id, type)` would fix this. |
| `has_blocked(int $blocker_id, int $blocked_id)` | Unidirectional — only checks blocker→blocked direction. Uses PK directly. | `bn_blocks` (SELECT via PK); cache | None | |
| `is_restricted(int $restrictor_id, int $restricted_id)` | Unidirectional. Checks `type='restrict'` for the specific direction. | `bn_blocks` (SELECT via PK); cache | None | |
| `is_user_online(int $viewer_id, int $subject_id)` | Applies restrict gate: restricted users appear offline to the restrictor. | `bn_blocks` (via is_restricted()); `wp_usermeta` | None | |
| `prime_restricted_cache(array $user_ids, int $viewer_id)` | Batch-warms the restricted cache for a list of users against the viewer. Used in directory rendering to prevent N+1. | `bn_blocks` (1 × SELECT IN()); cache warm | None | Correct batch pattern. |
| `blocking_either_map(array $user_ids, int $viewer_id)` | Returns a map of `user_id => bool` for directory listings — true if either party blocks the other. Single query. | `bn_blocks` (1 × SELECT IN()); cache | None | Correct batch pattern. |
| `muted_users(int $user_id)` | Returns list of user IDs muted by `$user_id`. | `bn_blocks` (SELECT WHERE `blocker_id=$user_id AND type='mute'`); cache | None | Unbounded — no pagination. Large mute lists are fetched entirely into PHP. |

**Cache group:** `buddynext_blocks`, TTL 600 s.

---

### PrivacyService (`includes/SocialGraph/PrivacyService.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `can_follow(int $follower_id, int $target_id)` | Reads `bn_privacy_who_can_follow` usermeta for `$target_id`. Returns false if value is `'nobody'`. | `wp_usermeta` | None | No caching. Each call is a `get_user_meta()` hit. |
| `can_connect(int $requester_id, int $target_id)` | Reads `bn_privacy_who_can_connect` for target. Returns false if `'nobody'`. Returns false if `'followers'` and the requester does not follow the target. | `wp_usermeta`; `bn_follows` (if followers-only check) | None | Followers check triggers a `bn_follows` read. No caching on the privacy preference read itself. |
| `get_preference(int $user_id, string $key)` | Reads a single `bn_privacy_*` usermeta key. | `wp_usermeta` | None | |
| `set_preference(int $user_id, string $key, string $value)` | Writes a `bn_privacy_*` usermeta key after validating against allowed values per key. | `wp_usermeta` | None | No hook fired on change — addons cannot react to privacy preference updates. |
| `block_exclude_sql(int $viewer_id, string $alias, string $col)` | Returns a SQL fragment (`AND $alias.$col NOT IN (...)`) that excludes users blocked in either direction from feed, directory, and search queries. Supports directional type filtering. | `bn_blocks` (SELECT to build exclusion list) | None | **Index gap**: the blocked_id→blocker lookup inside the exclusion query suffers from the same missing `blocked_id` index as `is_blocked()`. At scale, building the exclusion list becomes slow. |

---

### UserCleanupListener (`includes/SocialGraph/UserCleanupListener.php`)

| Method | What it does | Tables / cache touched | Hooks fired | Candidate bugs / gaps |
|---|---|---|---|---|
| `register()` | Registers `deleted_user` at priority 5. | None | None | Priority 5 fires before default WP user cleanup. Correct ordering. |
| `on_user_deleted(int $user_id)` | Deletes all rows referencing `$user_id` from: `bn_follows`, `bn_connections`, `bn_blocks`, `bn_space_members`, `bn_hashtag_follows`, `bn_notification_prefs`, `bn_notifications`, `bn_user_strikes`, `bn_user_suspensions`. Decrements `bn_spaces.member_count` for each space the user belonged to. Fires `buddynext_user_relations_purged`. | All listed tables (DELETE); `bn_spaces` (UPDATE); all related cache groups busted | `buddynext_user_relations_purged` | Correct cascade order. No gap for the social-graph tables specifically. Note: `bn_posts` authored by the deleted user are NOT deleted here — post orphaning or anonymization is handled separately by the anonymize-on-delete flow. |

---

## 3. QA cases

| ID | Role | Layer | Pre-conditions | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-SG-001 | member (User A) | REST + DB | User B exists; no prior relationship; neither has set privacy restrictions | POST `/buddynext/v1/users/{B}/follow` as User A | `201` response; `bn_follows` row with `follower_id=A, following_id=B, status='approved'`; `buddynext_user_followed` hook fired | 1440px, 390px |
| QA-SG-002 | member (User A) | REST + DB | User B has `bn_account_private=1` usermeta; no prior relationship | POST `/buddynext/v1/users/{B}/follow` as User A | `201` response with `status='pending'`; `bn_follows` row `status='pending'`; `buddynext_follow_requested` hook fired; `buddynext_user_followed` NOT fired | 1440px, 390px |
| QA-SG-003 | member (User B) | REST + DB | A pending follow request from User A exists (QA-SG-002 pre-condition) | POST `/buddynext/v1/me/follow-requests/{A}/approve` as User B | `200` response; `bn_follows` row updated to `status='approved'`; `buddynext_follow_request_approved` fired; `GET /me/follow-requests/count` decrements by 1 | 1440px, 390px |
| QA-SG-004 | member (User B) | REST + DB | A pending follow request from User A exists | POST `/buddynext/v1/me/follow-requests/{A}/reject` as User B | `200` response; `bn_follows` row deleted; `buddynext_follow_request_rejected` fired; count returns 0 | 1440px |
| QA-SG-005 | member (User A) | REST + DB | User A follows User B (`status='approved'`) | DELETE `/buddynext/v1/users/{B}/follow` as User A | `200` response; `bn_follows` row deleted; `buddynext_user_unfollowed` fired | 1440px, 390px |
| QA-SG-006 | member (User A) | REST | No existing row | POST `/buddynext/v1/users/{A}/follow` as User A (self-follow) | `400` error response; no row inserted | 1440px |
| QA-SG-007 | member (User A) | REST | User A already follows User B (`status='approved'`) | POST `/buddynext/v1/users/{B}/follow` again | `409` or `400` conflict response; no duplicate row in `bn_follows` (UNIQUE constraint on PRIMARY KEY enforces this at DB level) | 1440px |
| QA-SG-008 | member (User A) | REST + DB | User A has `bn_privacy_who_can_follow='nobody'` set via PrivacyService | Any authenticated user attempts POST `/buddynext/v1/users/{A}/follow` | `403` response; no row inserted; `PrivacyService::can_follow()` returns false | 1440px, 390px |
| QA-SG-009 | member (User A) | REST + DB | No prior relationship | POST `/buddynext/v1/users/{B}/connect` as User A with `note: "Hello"` | `201`; `bn_connections` row `status='pending', note='Hello'`; `buddynext_connection_requested` fired | 1440px, 390px |
| QA-SG-010 | member (User B) | REST + DB | Pending connection request from User A (QA-SG-009 pre-condition) | POST `/buddynext/v1/users/{A}/connect/accept` as User B | `200`; `bn_connections.status='accepted'`; `buddynext_connection_accepted` fired | 1440px, 390px |
| QA-SG-011 | member (User B) | REST + DB | Pending connection request from User A | POST `/buddynext/v1/users/{A}/connect/decline` as User B | `200`; `bn_connections.status='declined'`; `buddynext_connection_declined` fired | 1440px |
| QA-SG-012 | member (User A) | REST + DB | Pending connection request sent by User A to User B | DELETE `/buddynext/v1/users/{B}/connect` as User A | `200`; `bn_connections` row deleted; `buddynext_connection_withdrawn` fired | 1440px, 390px |
| QA-SG-013 | member (User A) | REST + DB | User A has `bn_privacy_who_can_connect='followers'`; User C does not follow User A | POST `/buddynext/v1/users/{A}/connect` as User C | `403`; `PrivacyService::can_connect()` returns false because C is not a follower | 1440px |
| QA-SG-014 | member (User A) | REST | User A and User B are accepted connections; User C and User B are accepted connections; User A and User C have no direct connection | GET `/buddynext/v1/users/{A}/mutual-connections` as User C | Returns User B in the list; count is 1 | 1440px, 390px |
| QA-SG-015 | member (User A) | REST + DB | User A follows User B and has an accepted connection with User B | POST `/buddynext/v1/users/{B}/block` as User A | `201`; `bn_blocks` row `type='block'`; `buddynext_block` fired; `GET /users/{B}/followers` for User B must NOT include User A; `GET /users/{B}/following` for User A must NOT include User B (`filter_blocked()` strips them) | 1440px, 390px |
| QA-SG-016 | member (User A) | REST + DB | No prior relationship | POST `/buddynext/v1/users/{B}/mute` as User A | `201`; `bn_blocks` row `type='mute'`; User A can still see User B's posts (mute is soft); no follow/connection changes | 1440px, 390px |
| QA-SG-017 | member (User A) | REST + DB | User A has muted User B (`type='mute'`) | POST `/buddynext/v1/users/{B}/block` as User A | `201`; existing mute row upgraded to `type='block'` via `ON DUPLICATE KEY UPDATE`; no duplicate row | 1440px |
| QA-SG-018 | member (User A) | REST + DB | User A has blocked User B (`type='block'`) | POST `/buddynext/v1/users/{B}/mute` as User A | Row unchanged; `INSERT IGNORE` fires but is silently ignored; block is NOT downgraded to mute; `bn_blocks` still shows `type='block'` | 1440px |
| QA-SG-019 | member (User A) | REST + DB | No prior relationship | POST `/buddynext/v1/users/{B}/restrict` as User A | `201`; `bn_blocks` row `type='restrict'`; User B appears offline to User A (`is_user_online()` returns false for restricted users seen by restrictor); User B's replies to User A's posts are hidden from others | 1440px, 390px |
| QA-SG-020 | member (User A) | REST | No prior relationship | POST `/buddynext/v1/users/{A}/block` as User A (self-block) | `400` error; no row inserted | 1440px |
| QA-SG-021 | member (User A) | REST + UI | User A has 3 pending inbound follow requests | GET `/buddynext/v1/me/follow-requests` and GET `/buddynext/v1/me/follow-requests/count` as User A | Count endpoint returns `3`; list endpoint returns 3 items; `pending_inbox` index (`following_id, status, created_at`) used | 1440px, 390px |
| QA-SG-022 | member (User A) | REST + UI | User A follows 10 users; 3 of those users follow 5 common users that User A does not follow | GET `/buddynext/v1/follow-suggestions` as User A | Returns up to the configured limit of suggested users; returned suggestions are users not already followed by User A; suggestions are friends-of-friends; no self-suggestion | 1440px, 390px |
| QA-SG-023 | admin | DB | User X is deleted from WordPress (`wp_delete_user()`) | Trigger deletion; query `bn_follows`, `bn_connections`, `bn_blocks` for any rows referencing `user_id=X` | All rows referencing X in all three tables are deleted; `bn_spaces.member_count` decremented for each space X belonged to; `buddynext_user_relations_purged` fired; no orphan rows | 1440px |
| QA-SG-024 | unauthenticated | REST | No session | POST `/buddynext/v1/users/{id}/follow` without authentication | `401` response; no row inserted | 1440px, 390px |
| QA-SG-025 | member (User A) | UI + REST | No prior relationship | Navigate to User B's profile at 390px; locate Follow and Connect buttons; tap Follow | Follow button renders correctly at 390px (no overflow, no clipped text); POST fires correctly; button state changes to Following reactively via WP Interactivity API | 390px |

---

## 4. Site-owner expectations & missing must-haves

- **[TOGGLE-BUG] affects `allow_polls`, `allow_shares`, `allow_bookmarks`.** All three boolean social options cannot be turned OFF reliably. An admin who unchecks any of these three toggles will find the setting silently reverts to ON after saving, because `render_toggle_row()` in `AdminPageBase.php` emits no preceding hidden input to capture the unchecked state. None of these options have ever been written to `wp_options` on the live install — they are running on code defaults. This must be fixed before these toggles are considered functional. Priority: critical.

- **No admin UI to set a site-wide default follow mode (public vs. private accounts).** Whether a user's account is private (follow requires approval) is controlled by `bn_account_private` usermeta, which can only be toggled per-user from their own profile settings. There is no site owner option to say "all accounts are private by default" or "new accounts start as private." A site owner running a closed community or a creator platform needs this. Priority: high.

- **No admin dashboard for pending follow requests across all users.** When users have private accounts, follow requests queue up per user. The site admin has no consolidated view of pending follow requests across the membership. There is no admin screen equivalent to the `GET /me/follow-requests` endpoint filtered by all users. Priority: high.

- **`bn_blocks` has no index on `blocked_id` alone — bidirectional `is_blocked()` scans the PK from the wrong direction at scale.** `BlockService::is_blocked()` checks both `(blocker_id=A, blocked_id=B)` and `(blocker_id=B, blocked_id=A)`. The first direction uses the PRIMARY key efficiently. The reverse direction requires scanning all rows where `blocked_id=A`, but the PK starts with `blocker_id` — so MySQL scans the full table. At 100k+ block rows, `PrivacyService::block_exclude_sql()` (which also constructs a bidirectional exclusion list) will degrade. Fix: add a compound index on `(blocked_id, type)`. Priority: high.

- **`FollowService::suggestions()` is computed in PHP, not SQL — will degrade at scale.** The suggestions engine fetches the full following list for the viewer and then, for each followed user, fetches that user's full following list (N+1 queries). For a user following 500 people, this is 500 separate `SELECT` statements on `bn_follows`. The query total grows linearly with graph density. Should be replaced with a single SQL `JOIN` or `UNION` approach. Priority: high.

- **`ConnectionService::mutual_connections()` is computed in PHP via `array_intersect()`, not SQL.** Two unbounded `SELECT` queries pull full connection ID lists for both users into PHP memory, then `array_intersect()` finds the overlap. For users with thousands of connections, both lists could be very large. Should be replaced with a SQL `INNER JOIN` or `IN (SELECT ...)` query. Priority: high.

- **No rate-limit on follow or connect endpoints.** An authenticated member can issue unlimited follow requests and connection requests. There is no per-user-per-hour guard at the REST layer or service layer. This enables follow-spam and connection-request harassment at scale. Priority: high.

- **No "withdraw my own pending follow request" UI or endpoint for the requester.** `FollowService::unfollow()` deletes an approved follow row. But if a follow is `status='pending'`, the requester has no dedicated endpoint to cancel it. `DELETE /users/{id}/follow` would need to handle both states (approved and pending). The only removal path for a pending request is `reject_follow_request()` on the recipient side. A requester who changes their mind and wants to cancel their pending request to a private account has no self-service option. Priority: high.

- **Privacy preferences have no admin override or site-wide defaults.** `bn_privacy_who_can_follow`, `bn_privacy_who_can_connect`, and `bn_privacy_profile_visibility` are per-user only. The site owner has no way to: (a) set the default value for new accounts, (b) override a specific user's privacy settings, or (c) see a report of how many members have locked each setting. This limits the site owner's ability to manage access patterns for the community as a whole. Priority: medium.

- **No hook fired on mute or restrict actions.** `BlockService::mute()`, `unmute()`, `restrict()`, and `unrestrict()` fire no hooks. Addons (such as notification plugins, analytics, or gamification) have no way to react to mute or restrict events. `block()` and `unblock()` correctly fire `buddynext_block` and `buddynext_unblock`. Mute and restrict should have parallel hooks (`buddynext_mute`, `buddynext_unmute`, `buddynext_restrict`, `buddynext_unrestrict`). Priority: medium.

- **`muted_users()` has no pagination.** `BlockService::muted_users()` returns all muted user IDs for a user in a single unbounded query. A power user who mutes thousands of accounts would cause a large result set to be loaded into PHP on every call. Should accept `limit`/`offset` parameters. Priority: medium.

- **`get_account_type()` is uncached.** Each call is a direct `get_user_meta()` hit. In directory rendering where many profile cards are displayed, this could result in repeated usermeta reads for the same users. Should be cached within the `buddynext_follows` or a dedicated cache group. Priority: low.

- **`PrivacyService::set_preference()` fires no hook on change.** Addons have no way to react to a member changing their privacy settings. A notification plugin, analytics tracker, or CRM integration cannot observe these transitions. A `buddynext_privacy_preference_updated` hook with `($user_id, $key, $new_value, $old_value)` args would close this gap. Priority: low.
