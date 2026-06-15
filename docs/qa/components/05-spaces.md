# QA — Spaces — sub-communities (free)

**Manifest refs:** tables: `bn_spaces` · `bn_space_members` · `bn_space_categories` · REST routes: `/spaces` (GET/POST) · `/spaces/{id}` (GET/PUT/DELETE) · `/spaces/{id}/members` (GET) · `/spaces/{id}/pending-requests` (GET) · `/spaces/{id}/join` (POST/DELETE) · `/spaces/{id}/join/cancel` (POST) · `/spaces/{id}/leave` (POST) · `/spaces/{id}/invite` (POST) · `/spaces/{id}/members/{user_id}/approve` (POST) · `/spaces/{id}/members/{user_id}/decline` (POST) · `/spaces/{id}/ban/{user_id}` (POST/DELETE) · `/spaces/{id}/members/{user_id}/role` (PUT) · `/spaces/{id}/members/{user_id}` (DELETE) · `/spaces/{id}/transfer-ownership` (POST) · `/spaces/{id}/notification-pref` (GET/POST) · `/spaces/{id}/permissions` (PUT) · `/space-categories` (GET/POST) · `/space-categories/{id}` (PUT/DELETE) · services: SpaceService · SpaceMemberService · SpaceCategoryController · capabilities: `manage_options` (admin space delete / category CRUD) · `buddynext-view-space` · `buddynext_can_join_space` filter (Pro gate)
**Cross-ref (no dup):** JOURNEYS J-37 (spaces directory) · J-38 (filter by category) · J-39 (spaces search) · J-40 (join open space) · J-41 (request private space) · J-42 (space home feed) · J-43 (post in space) · J-44 (space member list) · J-52 (space settings) · J-54 (moderator review content) · FLOW-TEST-MATRIX M14 (spaces browse/join/post) · O4 (spaces admin: create/settings/branding)
**Admin location:** BuddyNext → Spaces (`admin.php?page=buddynext-spaces`) · Spaces → Categories (`admin.php?page=buddynext-spaces&subtab=categories`) · Space creation and settings via frontend only (no admin creation form)

---

## 1. Backend settings & options (justify each)

### Spaces tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_enable_spaces` | Spaces | `true` (bool) | Globally enable or disable the Spaces feature **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably — unchecked state not POSTed (no preceding hidden input in `render_toggle_row()`); spaces always visible |
| `buddynext_space_creation_role` | Spaces | `'member'` | Minimum WP role required to create a space: `subscriber` / `member` / `editor` / `administrator` (verify: code uses `sanitize_key`; no enum enforcement at DB level) | Yes | No equivalent BuddyNext capability check; relies on WP role string comparison only |
| `buddynext_space_max_sub_spaces` | Spaces | `0` | Max sub-spaces allowed per parent space; `0` = unlimited | Questionable | `0` meaning "unlimited" is not documented in the admin UI; owners who want to allow zero sub-spaces have no way to express that — any positive number allows sub-spaces; `0` disables the limit |

### Missing must-haves (no option exists)

| Expected option | Gap description |
|---|---|
| Default space type | No option to set the default privacy type when a member creates a new space (`open` is hardcoded as the form default) |
| Join approval email | No option to send an email notification to the requestor when a join request is approved or declined; this is handled in code (NotificationListener) but not user-configurable |
| Space cover/avatar size limits | No option to cap the file size or dimensions of space avatar/cover uploads |
| Space slug prefix | No option to namespace space slugs (e.g., all spaces under `/communities/general/` instead of `/spaces/general/`); slug prefix is hardcoded via `buddynext_slug_spaces` nav option |

---

## 2. Frontend functions (function-by-function)

### Space directory

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::list_spaces()` | `/spaces/` directory hub | `GET /buddynext/v1/spaces` | Paginated space cards; filters: `category_id`, `type`, `search`; guests see open + private spaces (not secret); authenticated members see spaces they are in; secret spaces hidden from non-members |
| `SpaceCategoryController::list_categories()` | `/spaces/` filter chips | `GET /buddynext/v1/space-categories` | Returns all categories (`bn_space_categories`); ordered by `sort_order`; permission `__return_true`; used to render category filter chips |

### Space view

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::get_space()` | `/spaces/{slug}/` hub | `GET /buddynext/v1/spaces/{id}` | Returns space data; 404 for secret spaces when viewer is not an active member (enforced in SpaceController); permission `__return_true` for public/private, hidden for secret |
| `SpaceService::get_by_slug()` | PageRouter slug resolution | none (internal) | Resolves space slug to ID; uses `slug` column (not `post_name` — fixed 2026-03-24) |
| `SpaceService::hydrate()` | Space cards, space home | none (internal) | Adds `avatar_url`, `cover_image_url` to space array from `bn_spaces` columns |

### Space membership — join / leave / request

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::join_space()` | Space directory / space home join button | `POST /buddynext/v1/spaces/{id}/join` | Open space: inserts `bn_space_members` row (`status='active'`, `role='member'`); fires `buddynext_space_member_joined`; adjusts `bn_spaces.member_count`; Private space: inserts with `status='pending'`; fires no join hook (pending state) |
| `SpaceController::leave_space()` | Space home → Leave | `DELETE /buddynext/v1/spaces/{id}/join` or `POST /buddynext/v1/spaces/{id}/leave` | Removes `bn_space_members` row; fires `buddynext_space_member_left`; adjusts member count; owner cannot leave without transferring ownership first |
| `SpaceMemberService::request_join()` | Private space → Request button | (via `join_space`) | Sets `status='pending'`; notifies owner/mods (via NotificationListener) |
| `SpaceController::leave_space()` via `POST /spaces/{id}/join/cancel` | Pending request → Cancel | `POST /buddynext/v1/spaces/{id}/join/cancel` | Added 2026-06-14; removes pending `bn_space_members` row; no hook fired (verify) |

### Space membership — moderation (owner / mod)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::approve_request()` | Space settings → Pending members | `POST /buddynext/v1/spaces/{id}/members/{user_id}/approve` | Updates `bn_space_members.status` to `'active'`; fires `buddynext_space_join_approved`; notifies requestor |
| `SpaceController::decline_request()` | Space settings → Pending members | `POST /buddynext/v1/spaces/{id}/members/{user_id}/decline` | Deletes `bn_space_members` row (`status='pending'`); no hook fired (verify) |
| `SpaceController::get_pending_requests()` | Space settings → Pending tab | `GET /buddynext/v1/spaces/{id}/pending-requests` | Returns pending members; requires auth + owner/mod role |
| `SpaceMemberService::ban_user()` | Space moderation tab | `POST /buddynext/v1/spaces/{id}/ban/{user_id}` | Sets `bn_space_members.status='banned'`; fires `buddynext_space_user_banned` (3 args: space_id, user_id, banned_by); note: `banned_by` stored as `0` when not provided (fixed 2026-03-13 — was null into NOT NULL column) |
| `SpaceMemberService::unban_user()` | Space moderation tab | `DELETE /buddynext/v1/spaces/{id}/ban/{user_id}` | Sets `status='active'` or removes row; fires `buddynext_space_user_unbanned` |
| `SpaceController::remove_member()` | Space settings → Members list | `DELETE /buddynext/v1/spaces/{id}/members/{user_id}` | Calls `SpaceMemberService::remove()`; fires `buddynext_space_member_removed` (canonical, 3 args: user_id, space_id, removed_by); owner cannot remove themselves |
| `SpaceController::change_member_role()` | Space settings → Members list | `PUT /buddynext/v1/spaces/{id}/members/{user_id}/role` | Updates `bn_space_members.role` to `'owner'/'moderator'/'member'`; no hook currently fired on role change (gap — verify) |
| `SpaceController::transfer_ownership()` | Space settings → Danger zone | `POST /buddynext/v1/spaces/{id}/transfer-ownership` or `/transfer` (alias) | Delegates to `SpaceService::transfer_ownership()`; swaps `owner_id` on `bn_spaces`; updates `bn_space_members` role; fires `buddynext_space_created` reuse or no dedicated hook (verify) |
| `SpaceController::invite_member()` | Space home → Invite | `POST /buddynext/v1/spaces/{id}/invite` | Inserts `bn_space_members` row with `status='invited'`; fires notification to invitee |

### Space settings (owner)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::update_space()` | `/spaces/{slug}/?tab=settings` | `PUT /buddynext/v1/spaces/{id}` | Updates `bn_spaces` columns (name, description, type, category_id, rules, accent_color, description_layout); owner + admin only |
| `SpaceController::update_permissions()` | Space settings → Permissions | `PUT /buddynext/v1/spaces/{id}/permissions` | Updates `required_ability` column (Pro gating) or free permission flags; requires owner/admin |
| `SpaceController::get_notification_pref()` / `set_notification_pref()` | Space home → bell icon | `GET/POST /buddynext/v1/spaces/{id}/notification-pref` | Reads/writes `bn_space_members.notification_pref`; valid values from `NotificationPrefService::VALID_SPACE_PREFS` = `['all','mentions_only','none']`; **BUG: DB ENUM is `('all','mentions','none')` — value `'mentions_only'` is rejected by MySQL ENUM constraint and silently falls back to empty string or default** |

### Space settings (admin)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SpaceController::delete_space()` | Admin Spaces page | `DELETE /buddynext/v1/spaces/{id}` or `admin_post_bn_delete_space` | Removes `bn_spaces` row; cascades `bn_space_members`; cascades `bn_posts` (space posts); fires no explicit deletion hook in SpaceService currently (verify `buddynext_space_deleted`) |
| `SpaceCategoryController::create_category()` | Admin Spaces → Categories subtab | `POST /buddynext/v1/space-categories` | Inserts `bn_space_categories` row; `manage_options` required |
| `SpaceCategoryController::update_category()` | Admin Spaces → Categories subtab | `PUT /buddynext/v1/space-categories/{id}` | Added 2026-06-14; updates name/description/sort_order; `manage_options` required; not yet in manifest |
| `SpaceCategoryController::delete_category()` | Admin Spaces → Categories subtab | `DELETE /buddynext/v1/space-categories/{id}` | Deletes category; sets `bn_spaces.category_id = NULL` for affected spaces (verify no orphan rows) |
| `SpaceMemberService::get_moderated_space_ids()` | Moderation queue filter | none (internal) | Returns space IDs where user has `owner` or `moderator` role; used by `ModerationController` to scope space mods' report queue view |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-SPAC-001 | member | frontend | 3 spaces seeded (1 open, 1 private, 1 secret); member1 is in all 3 | Visit `http://buddynext.local/spaces/?autologin=1` | Space cards for open + private shown; secret space card not shown; category filter chips visible if categories exist; search input visible | 1440px, 768px, 390px |
| QA-SPAC-002 | guest | frontend | 3 spaces seeded; `buddynext_public_explore` = true | Visit `/spaces/` without logging in | Open + private space cards visible; Join button absent (guest); secret space absent | 1440px, 390px |
| QA-SPAC-003 | member | frontend | Category `Tech` exists; 2 spaces assigned to it | Click `Tech` category filter chip on `/spaces/` | Directory re-fetches `GET /spaces?category_id={id}`; only Tech spaces shown; chip shows active state | 1440px, 390px |
| QA-SPAC-004 | member | frontend | Spaces directory loaded | Type `general` in spaces search input | Directory re-fetches with `search=general`; matching spaces shown; empty state if no match | 1440px, 390px |
| QA-SPAC-005 | member2 | frontend | `general` space exists (type=open); member2 is not a member | Visit `/spaces/general/?autologin=member2`; click Join | `POST /buddynext/v1/spaces/{id}/join` fires; `bn_space_members` row inserted (`status='active'`, `role='member'`); `bn_spaces.member_count` incremented; button toggles to Joined; `buddynext_space_member_joined` hook fires | 1440px, 390px |
| QA-SPAC-006 | member2 | frontend | `private-space` exists (type=private); member2 is not a member | Visit `/spaces/private-space/?autologin=member2`; click Request to Join | `POST /spaces/{id}/join` fires; `bn_space_members` row inserted (`status='pending'`); button toggles to Requested; owner/mod notified | 1440px, 390px |
| QA-SPAC-007 | member2 | frontend | member2 has a pending join request for `private-space` | On space directory card; click Cancel Request | `POST /spaces/{id}/join/cancel` fires; `bn_space_members` row with `status='pending'` deleted; button reverts to Request to Join | 1440px, 390px |
| QA-SPAC-008 | member | frontend | member1 is active member of `general` space | Visit `/spaces/general/?autologin=member1`; space home loads | Space hero (name, avatar, cover, description) renders; feed of space posts below; Compose visible; Members tab accessible; category badge if assigned | 1440px, 390px |
| QA-SPAC-009 | member | frontend | member1 in `general` space | Compose a post in space home; submit | `POST /buddynext/v1/posts` with `space_id`; post appears in space feed; `bn_posts.space_id` set; post does not appear in global feed unless space is open (verify FeedService space_feed logic) | 1440px, 390px |
| QA-SPAC-010 | member | frontend | `secret-space` exists; member3 is not a member | Visit `/spaces/secret-space/?autologin=member3` directly | 404 response or redirect; `SpaceController::get_space()` returns 404 for secret spaces when viewer is not active member | 1440px |
| QA-SPAC-011 | space owner | frontend | member1 is owner of `private-space`; member2 has pending request | Visit `/spaces/private-space/?tab=settings&autologin=member1`; open Pending Members tab | Pending member2 row visible; Approve and Decline buttons present | 1440px, 390px |
| QA-SPAC-012 | space owner | frontend | member2 has pending request for `private-space` | Click Approve for member2 | `POST /spaces/{id}/members/{member2_id}/approve` fires; `bn_space_members.status` updated to `'active'`; `buddynext_space_join_approved` hook fires; member2 notified; member_count incremented | 1440px |
| QA-SPAC-013 | space owner | frontend | member2 has pending request for `private-space` | Click Decline for member2 | `POST /spaces/{id}/members/{member2_id}/decline` fires; `bn_space_members` row deleted; member2 notified (verify hook fires) | 1440px |
| QA-SPAC-014 | space owner | frontend | member2 is active member of `general` space | Open Members tab; click Remove for member2 | `DELETE /spaces/{id}/members/{member2_id}` fires; `SpaceMemberService::remove()` called; `bn_space_members` row deleted; `buddynext_space_member_removed` fires (3 args); member_count decremented; member2 loses access to space posts | 1440px, 390px |
| QA-SPAC-015 | space owner | frontend | member2 is active member; space has a moderator | Promote member2 to moderator | `PUT /spaces/{id}/members/{member2_id}/role` fires with `{role: 'moderator'}`; `bn_space_members.role` updated; member2 can now see moderation queue for this space | 1440px |
| QA-SPAC-016 | space moderator | frontend | member3 posted content that was reported | Visit moderation tab for the space | `GET /reports/queue?space_id={id}` (scoped via `get_moderated_space_ids()`); reported post visible; Approve/Remove controls present | 1440px, 390px |
| QA-SPAC-017 | space owner | frontend | `general` space; member3 is active member | Ban member3 from space | `POST /spaces/{id}/ban/{member3_id}` fires; `bn_space_members.status` set to `'banned'`; `buddynext_space_user_banned` fires (3 args: space_id, user_id, banned_by); banned_by stored (not null) | 1440px |
| QA-SPAC-018 | space owner | frontend | member3 is banned from `general` space | Unban member3 | `DELETE /spaces/{id}/ban/{member3_id}` fires; `bn_space_members.status` updated to `'active'`; `buddynext_space_user_unbanned` fires; member3 can re-join | 1440px |
| QA-SPAC-019 | member1 (space member) | api | member1 is in `general` space | `GET /wp-json/buddynext/v1/spaces/{id}/notification-pref` with auth | Returns current `notification_pref` value for member1 in this space; value should be one of `'all'/'mentions_only'/'none'` |  |
| QA-SPAC-020 | member1 | api | member1 is in `general` space | `POST /wp-json/buddynext/v1/spaces/{id}/notification-pref` body `{pref: "mentions_only"}` | **EXPECTED BUG:** `'mentions_only'` is not a valid MySQL ENUM value in `bn_space_members.notification_pref` (DB ENUM is `'all','mentions','none'`); write likely silently fails or truncates to empty string; `bn_space_members.notification_pref` will not equal `'mentions_only'` after write; verify with `SELECT notification_pref FROM bn_space_members WHERE user_id={id}` | — |
| QA-SPAC-021 | space owner | frontend | Space settings open | Change space type from `open` to `private`; Save | `PUT /spaces/{id}` fires with `{type: 'private'}`; `bn_spaces.type` updated; guests and non-members can no longer see space posts in global feed; existing members retain access | 1440px |
| QA-SPAC-022 | space owner | frontend | Space settings → Danger Zone | Transfer ownership to member2 | `POST /spaces/{id}/transfer-ownership` fires; `bn_spaces.owner_id` updated to member2; member2 `bn_space_members.role` set to `'owner'`; original owner role downgraded to `'member'` (verify) | 1440px |
| QA-SPAC-023 | admin | backend | Admin Spaces page | Visit `admin.php?page=buddynext-spaces?autologin=1`; filter by type `private`; delete `general` space | Space list filters; Delete fires `admin_post_bn_delete_space`; `bn_spaces` row removed; `bn_space_members` rows for that space removed; space posts cascade (verify `bn_posts` with `space_id` deleted) | 1440px, 390px |
| QA-SPAC-024 | admin | backend | Admin Spaces → Categories subtab | Create category `Gaming` with slug `gaming` | `POST /space-categories` fires; row in `bn_space_categories`; category appears in directory filter chips on frontend | 1440px, 390px |
| QA-SPAC-025 | admin | backend | `Gaming` category exists | Edit category label to `Gaming & Esports` | `PUT /space-categories/{id}` fires (added 2026-06-14); label updated in `bn_space_categories`; filter chip label updates on frontend; verify `manifest.json` does not list this route yet (known gap) | 1440px |
| QA-SPAC-026 | admin | backend | `Gaming` category exists; 1 space assigned to it | Delete `Gaming` category | `DELETE /space-categories/{id}` fires; row removed from `bn_space_categories`; `bn_spaces.category_id` set to NULL for affected spaces; no orphan rows; space still exists | 1440px |
| QA-SPAC-027 | member1 (active in space) | frontend | member1 just joined open space | Leave space | `DELETE /spaces/{id}/join` or `POST /spaces/{id}/leave`; `bn_space_members` row deleted; `bn_spaces.member_count` decremented; `buddynext_space_member_left` fires; member1 can no longer post in space | 1440px, 390px |
| QA-SPAC-028 | member | frontend | Space home; member has `notification_pref='all'` | Click notification bell on space home | `GET /spaces/{id}/notification-pref` read; dropdown shows All / Mentions / None; select Mentions | 1440px, 390px |
| QA-SPAC-029 | member | frontend | Mobile at 390px | Visit `/spaces/?autologin=1` and `/spaces/general/?autologin=1` | Space directory cards stack vertically; space home hero full-width; composer below hero; member list tab accessible; no horizontal scroll; dark mode tokens apply | 390px |
| QA-SPAC-030 | subscriber | api | Logged in as subscriber; `buddynext_space_creation_role` = `'member'` (default) | `POST /wp-json/buddynext/v1/spaces` body `{name:"Test",slug:"test",type:"open"}` | 403 Forbidden (subscriber is below `'member'` threshold in role check); no space created | — |
| QA-SPAC-031 | member | api | Logged in as member1 (role = `member`) | Same `POST /spaces` request | 201 Created; space row in `bn_spaces`; `owner_id` = member1; `buddynext_space_created` fires | — |
| QA-SPAC-032 | member | frontend | `general` space has sub-space `general-announcements` (parent_id set) | Visit `general-announcements` space home | Sub-space renders as independent space; parent_id relationship visible (breadcrumb or "part of" link) — verify if UI exposes this | 1440px |
| QA-SPAC-033 | admin | backend | `buddynext_space_max_sub_spaces` = 1 (set to 1 in admin) | Owner creates a second sub-space under the same parent | System blocks creation at sub-space limit; error returned; verify enforcement in SpaceController or SpaceService (code-path to confirm) | 1440px |
| QA-SPAC-034 | member | frontend | Dark mode active | Visit `/spaces/` and space home in dark mode | Space cards, hero image overlay, member count badges, category chips, type badges all use `--bg`, `--text-1`, `--border` tokens; no hardcoded colours | 1440px, 390px |
| QA-SPAC-035 | member | api | member1 is banned from `private-space` | `POST /wp-json/buddynext/v1/spaces/{id}/join` (member1 auth) | 403 or appropriate error; `SpaceMemberService::is_banned_from_space()` check fires; banned member cannot re-join | — |

---

## 4. Site-owner expectations & suggestions

- **`notification_pref` ENUM mismatch is a confirmed bug.** `bn_space_members.notification_pref` ENUM is `('all','mentions','none')` but `NotificationPrefService::VALID_SPACE_PREFS` validates and writes `'mentions_only'`. Writing `'mentions_only'` to a MySQL ENUM column that does not include that value silently inserts an empty string (strict mode) or the ENUM default. The per-space notification preference for "mentions only" is therefore never correctly stored. Fix: update the DB ENUM to include `'mentions_only'` (migration via `dbDelta` or `ALTER TABLE`) and align the VALID_SPACE_PREFS constant to match. Priority: critical.

- **Space deletion has no cascade verification for bn_posts.** The admin delete fires `admin_post_bn_delete_space` and removes `bn_spaces` + `bn_space_members` rows, but there is no confirmed cascade of `bn_posts` rows with `space_id = deleted_space_id`. Orphan posts in a deleted space remain in the feed index. Site owners expect that deleting a space removes all its content. Priority: high.

- **No admin UI to create or edit a space.** The Spaces admin page shows a read-only list with Delete. Site owners cannot rename a space, change its type (open/private/secret), or update its category without going to the frontend as the space owner. An inline edit modal or a dedicated edit form is expected at `admin.php?page=buddynext-spaces&action=edit&space_id=X`. Priority: high.

- **`buddynext_enable_spaces` toggle is broken (TOGGLE-BUG).** Unchecking it does not disable the Spaces feature because unchecked checkboxes are not POSTed. The spaces hub and space creation are always available, regardless of this setting. Priority: critical (same root cause as all toggle bugs documented in component 11).

- **No hook fires on role change.** When a space member's role is updated via `PUT /spaces/{id}/members/{user_id}/role`, no `do_action()` call is made. Pro modules (Realtime, Push, Analytics) and custom integrations have no way to react to moderator promotion/demotion. Add `buddynext_space_member_role_changed( $user_id, $space_id, $new_role, $old_role )`. Priority: medium.

- **No dedicated hook on ownership transfer.** `SpaceService::transfer_ownership()` does not fire a specific action. Pro modules and webhooks cannot react to ownership changes. Add `buddynext_space_ownership_transferred( $space_id, $new_owner_id, $old_owner_id )`. Priority: medium.

- **`PUT /space-categories/{id}` is not in manifest.** The route was added 2026-06-14 but `audit/manifest.json` does not list it. Manifest is out of sync; refresh on next release. Priority: low (process gap, not functional).

- **Secret space slug is guessable.** A direct URL guess to `/spaces/{secret-slug}/` correctly returns 404 for non-members, but the slug itself may be discoverable via `GET /spaces` if the filter does not exclude secret types for guests. Verify that `list_spaces()` excludes `type='secret'` for unauthenticated and non-member requests. Priority: medium (security).

- **No max member count per space.** Site owners on shared or tiered hosting may want to cap the membership size of individual spaces (e.g. max 500 members per space, with a waitlist). No such option or enforcement exists. Priority: low.
