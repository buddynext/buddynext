# BuddyNext — Master Development Plan

**Created:** 2026-03-21
**Updated:** 2026-03-25
**Status:** Phases 1-13 verified. Template audit (D-L) complete. UX planning in progress.
**Scope:** BuddyNext Free + Pro + Jetonomy + WPMediaVerse (unified platform)

---

## Key Documents

| Document | Path | Purpose |
|----------|------|---------|
| **UX Audit + Plan** | `docs/UX_AUDIT_ALL_PLUGINS.md` | 85 templates audited, 6-phase UX plan, widgets/blocks strategy, presentation checklist |
| **Template Audit** | `docs/TEMPLATE_AUDIT_BUGS.md` | Phases A-L execution tracking (all complete) |
| **Plugin CLAUDE.md** | `CLAUDE.md` | Coding standards, design tokens, file conventions |
| **Feature Specs** | `docs/specs/features/` | 20 free + 6 Pro locked specs |
| **Design System** | `docs/v2 Plans/` | v2 prototypes + `tokens.css` + `PLAN.md` (canonical UI source) |

---

## Resources

### Feature Specs (all locked 2026-03-20)
`docs/specs/features/` — 20 free specs + 6 Pro specs + HOOKS.md + FREE-VS-PRO.md

| Spec | File |
|------|------|
| 00 Architecture | `docs/specs/features/00-architecture.md` |
| 01 Social Graph | `docs/specs/features/01-social-graph.md` |
| 02 Activity Feed | `docs/specs/features/02-activity-feed.md` |
| 03 Spaces | `docs/specs/features/03-spaces.md` |
| 04 Member Directory + Search | `docs/specs/features/04-member-directory-search.md` |
| 05 User Profiles | `docs/specs/features/05-user-profiles.md` |
| 06 Notifications + Email | `docs/specs/features/06-notifications-email.md` |
| 07 Direct Messaging | `docs/specs/features/07-direct-messaging.md` |
| 08 Reactions + Comments | `docs/specs/features/08-reactions-comments.md` |
| 09 Moderation | `docs/specs/features/09-moderation.md` |
| 10 Onboarding + Setup Wizard | `docs/specs/features/10-onboarding-setup-wizard.md` |
| 11 Gutenberg Blocks | `docs/specs/features/11-gutenberg-blocks.md` |
| 12 WBGamification Bridge | `docs/specs/features/12-wbgamification-bridge.md` |
| 13 Jetonomy Bridge | `docs/specs/features/13-jetonomy-bridge.md` |
| 14 WPMediaVerse Bridge | `docs/specs/features/14-wpmediaverse-bridge.md` |
| 15 Career Board Bridge | `docs/specs/features/15-career-board-bridge.md` |
| 16 Admin Settings | `docs/specs/features/16-admin-settings.md` |
| 17 Roles + Permissions | `docs/specs/features/17-roles-permissions.md` |
| 18 Hashtags | `docs/specs/features/18-hashtags.md` |
| 19 Database + Scale | `docs/specs/features/19-database-scale.md` |
| 20 Theme Integration | `docs/specs/features/20-theme-integration.md` |
| Free vs Pro | `docs/specs/features/FREE-VS-PRO.md` |
| Hook Reference | `docs/specs/HOOKS.md` |
| WPMediaVerse DM Integration | `docs/specs/WPMediaVerse-DM-Integration-Requirements.md` |
| P1 Stripe Membership | `docs/specs/features/P1-stripe-membership.md` |
| P2 AI Engine | `docs/specs/features/P2-ai-engine.md` |
| P3 Real-time WebSocket | `docs/specs/features/P3-realtime-websocket.md` |
| P4 Mobile App | `docs/specs/features/P4-mobile-app.md` |
| P5 Analytics | `docs/specs/features/P5-analytics.md` |
| P6 White-label | `docs/specs/features/P6-white-label.md` |

### Design System

`docs/v2 Plans/` — canonical v2 design source. The prior brainstorm wireframes have been removed; v2 prototypes are the only UI reference.

| Surface | v2 prototype |
|---|---|
| Home feed | `docs/v2 Plans/v2/home-feed.html` |
| Explore feed | `docs/v2 Plans/v2/explore-feed.html` |
| Post detail | `docs/v2 Plans/v2/post-detail.html` |
| User profile (view) | `docs/v2 Plans/v2/user-profile.html` |
| Member directory | `docs/v2 Plans/v2/member-directory.html` |
| Spaces directory | `docs/v2 Plans/v2/spaces-directory.html` |
| Space home | `docs/v2 Plans/v2/space-home.html` |
| DM list | `docs/v2 Plans/v2/dm-list.html` |
| DM thread | `docs/v2 Plans/v2/dm-thread.html` |
| Notifications | `docs/v2 Plans/v2/notifications.html` |
| Search results | `docs/v2 Plans/v2/search-results.html` |
| Onboarding | `docs/v2 Plans/v2/onboarding.html` |
| Admin chrome (all admin pages) | `docs/v2 Plans/v2/admin.html` |
| Hub navigation index | `docs/v2 Plans/v2/index.html` |
| Mobile responsive shell | `docs/v2 Plans/v2/mobile.html` |
| Style guide canon | `docs/v2 Plans/style-guide.html` |
| Token + primitive source | `docs/v2 Plans/tokens.css` |
| Surface-to-prototype map + composition rules + uniformity gates | `docs/v2 Plans/PLAN.md` |
| Engineering review of v2 | `docs/v2 Plans/REVIEW.md` |

Surfaces without a direct prototype (profile edit, space settings/moderation, hashtag feed, auth, moderation queue, Pro admin pages, etc.) compose from v2 primitives per `docs/v2 Plans/PLAN.md` Part 3 — never from any other design source.

### Implementation Plans
`docs/superpowers/plans/`

| Plan | File |
|------|------|
| Phase 1 — Core Foundation | `docs/superpowers/plans/2026-03-20-phase-1-core-foundation.md` |

---

## The Standard We Are Building To

This is not a prototype. Every line of code ships as production-ready enterprise WordPress. That means:

- WPCS validated on every file before it exists in the repo
- PHPStan level 5 on every class
- TDD — tests written before implementation
- No partial implementations — features are either complete or not started
- Every UI matches the HTML mockups exactly — Premium UX is the baseline, not a goal
- No AI code markers — no `// Generated by`, no `// AI-assisted`, no `// Claude`, no `@generated`, no `// This was created by`. Zero. Code reads as written by a senior WordPress engineer who has been doing this for 10 years.
- No `// TODO: implement later` — either implement it or don't write the function

---

## Code Quality Workflow (Every Task, No Exceptions)

### Step 1 — Write the test first
```bash
vendor/bin/phpunit tests/[Area]/[ClassTest].php --testdox
# Must FAIL before you write implementation
```

### Step 2 — Write implementation
- Strict types declaration on every file
- Proper DocBlocks on every method
- No inline comments that explain what the code does — code must be self-documenting
- Comments only for non-obvious WHY decisions

### Step 3 — WPCS check (MCP)
```
mcp__wpcs__wpcs_check_file({ file_path: "...", standard: "WordPress" })
```
Fix every error and warning. Zero tolerance.

### Step 4 — PHPStan check (MCP)
```
mcp__wpcs__wpcs_phpstan_check({ path: "includes/...", level: 5 })
```
Fix every issue.

### Step 5 — Run tests
```bash
vendor/bin/phpunit --testdox
# Must PASS
```

### Step 6 — Browser check
Navigate to the relevant page at `http://forums.local?autologin=1`
Verify at desktop (1280px) and mobile (390px).

---

## Phase Map

| # | Phase | Status | Depends on |
|---|-------|--------|-----------|
| 1 | Core Foundation | ✅ Verified 2026-03-23 | — |
| 2 | Social Graph | ✅ Verified 2026-03-23 | 1 |
| 3 | Activity Feed | ✅ Verified 2026-03-23 | 1, 2 |
| 4 | Profiles + Member Directory + Search | ✅ Verified 2026-03-23 | 1 |
| 5 | Spaces | ✅ Verified 2026-03-23 | 1, 2, 3 |
| 6 | Notifications + Email | ✅ Verified 2026-03-23 | 1, 2 |
| 7 | Reactions + Comments + Hashtags | ✅ Verified 2026-03-23 | 1, 3 |
| 8 | Moderation | ✅ Verified 2026-03-23 | 1, 3, 7 |
| 9 | Direct Messaging (UI bridge) | ✅ Verified 2026-03-23 | 1, 2, 6 |
| 10 | Bridges | ✅ Verified 2026-03-23 | 1–6 |
| 11 | Gutenberg Blocks + Onboarding | ✅ Verified 2026-03-23 | 1–5 |
| 12 | Theme Integration | ✅ Verified 2026-03-23 | 1 |
| 16 | Admin Panel | ✅ Verified 2026-03-23 | 1–8 |
| 13 | Unified Platform (BuddyNext as Master) | 🔲 In Progress | 10, 12 |

**Legend:** ✅ Done · ⚠️ Partial (gaps listed below in each phase) · 🔲 Not done

---

## Remaining Backend Work — Priority Order

Complete these before any template/UI work.

### BLOCK 1 — Schema additions (Installer.php)

- [x] Add `bn_user_suspensions` table — tracks active and historical suspensions per user
- [x] Add `bn_appeals` table — stores user appeals against suspensions with admin review state
- [x] Add `bn_space_bans` table — tracks users banned from specific spaces
- [x] Add `bn_outbound_webhooks` table — stores registered external webhook endpoints
- [x] Add `bn_outbound_webhook_log` table — delivery log for outbound webhook attempts
- [x] Add `content_warning` and `content_warning_type` columns to `bn_posts` — already in Installer
- [x] Use `bn_shadow_banned` usermeta key (no table needed) for shadow ban flag

---

### BLOCK 2 — ModerationService additions

`ModerationService` — add:
- [x] Shadow ban a user (sets usermeta flag)
- [x] Remove shadow ban
- [x] Check if user is shadow-banned
- [x] Suspend a user with reason, duration, and content visibility choice (keep or hide their posts)
- [x] Unsuspend a user
- [x] Check if user is suspended
- [x] Get active suspension details for a user
- [x] Issue a formal warning to a user
- [x] Submit an appeal against a suspension
- [x] Resolve an appeal (approve or deny)

`SpaceMemberService` — add:
- [x] Ban a user from a specific space
- [x] Unban a user from a space
- [x] Check if a user is banned from a space

---

### BLOCK 3 — Feed / Search / Directory filtering

- [x] Home feed, explore feed, space feed: hide posts from shadow-banned users; hide posts from suspended users when their suspension uses "hide content" mode
- [x] Search results: exclude shadow-banned and suspended users
- [x] Member directory: exclude suspended users
- [x] Space roster: exclude suspended users from member lists

---

### BLOCK 4 — ModerationController new REST endpoints

- [x] Warn a user (moderator or admin)
- [x] Shadow-ban a user (admin only)
- [x] Remove shadow ban (admin only)
- [x] Suspend a user with reason and duration (admin only)
- [x] Unsuspend a user (admin only)
- [x] Get active suspension details for a user (admin only)
- [x] Submit an appeal — authenticated user, own account only
- [x] List pending appeals (admin only)
- [x] Approve an appeal (admin only)
- [x] Deny an appeal (admin only)
- [x] List bans for a space (space owner or admin)
- [x] Ban a user from a space (space owner or admin)
- [x] Unban a user from a space (space owner or admin)

---

### BLOCK 5 — SafeguardService (new class)

New service that gates post creation. All checks run before a post is saved:
- [x] Banned word filter (admin-configurable list)
- [x] Post rate limit per user (admin-configurable max per minute)
- [x] Blocked domain / link filter (admin-configurable list)
- [x] New member gate — first N posts from new accounts go to pending review (admin-configurable threshold)
- [x] Wire into PostService so any failed check blocks the post and returns the reason to the caller

---

### BLOCK 6 — Content warnings on posts

- [x] PostController: accept content warning flag and type (nsfw, spoilers, violence, language) when creating or editing a post
- [x] PostService: save content warning fields on create and update
- [x] FeedService: include content warning fields in feed results so the frontend can blur/hide the post
- [x] ModerationController: admin endpoint to force-apply a content warning to any post

---

### BLOCK 7 — OutboundWebhookService (new class)

New service that pushes signed event payloads to admin-registered external URLs:
- [x] Register, list, and delete webhook endpoints (admin only)
- [x] Dispatch events: sign payload with HMAC-SHA256, POST to matching active endpoints, log result
- [x] Auto-disable an endpoint after 3 consecutive delivery failures
- [x] WP-Cron retry job — re-attempt failed deliveries from the last 24 hours every 5 minutes
- [x] New REST controller with endpoints: list, register, delete, view delivery log, send test ping
- [x] Hook dispatch into all 13 spec events: member registered/verified/suspended, post created/deleted, space joined/left, connection accepted, user followed, reaction added, comment created, ability granted/revoked

---

### BLOCK 8 — Space-scoped permission enforcement

- [x] PermissionService: when checking space moderation ability, verify the user's role within that specific space (owner or moderator), not just their site-wide role
- [x] ModerationController: space moderators see only their own spaces' reports in the queue, not all reports site-wide

---

### BLOCK 9 — EventListener additions

- [x] User warned → in-app notification to the warned user
- [x] User suspended → send suspension email to the user with a link to submit an appeal
- [x] User unsuspended → send confirmation email to the user
- [x] Appeal submitted → in-app notification to all site admins
- [x] Appeal resolved → email the user with the outcome (approved or denied)
- [x] User shadow-banned → immediately remove all their posts from the search index
- [x] Daily cron job: if the moderation queue exceeds the admin-configured threshold, email the alert address

---

### BLOCK 10 — Admin premium wrapper + 6 page implementations

**Spec:** `docs/specs/features/16-admin-settings.md`
**File architecture:** `docs/specs/features/00-architecture.md` — Admin Layer section

- [x] Create `AdminPageBase` — shared admin chrome: sidebar sub-nav, tab bar, section cards, save bar — matching `admin-settings.html` mockup
- [x] Settings page — 10 tabs: General, Registration, Social, Spaces, Notifications, Email, Moderation, Privacy & Data, Navigation, Webhooks — matches `admin-settings.html`
- [x] Members page file architecture split — thin controller + Members/ subdirectory + Helpers/ (2026-03-22)
- [x] Edit-member form — tabbed layout: fixed Account tab (photo + WP fields) + dynamic tabs one per `bn_profile_groups` row ordered by `group_order` (2026-03-22)
- [x] Members page — stats cards (total / active / suspended), filterable member table with avatar and last-active, bulk actions — matches `admin-members.html`
- [x] Spaces page — table with owner, type, member count, pending requests, archive/delete actions — matches `admin-spaces.html`
- [x] Integration Hub page — addon status cards, per-addon feature toggles — matches `admin-integration-hub.html`
- [x] Nav Manager page — three-panel layout, drag-reorder navigation items, custom tab creation — matches `admin-nav-manager.html`
- [x] Email Editor page — template list with enable/disable, inline subject + body editor, variable reference — matches `email-editor.html`

---

### BLOCK 11 — Hook name alignment (HOOKS.md compliance) ⚠️

Several hooks in the implementation use wrong names or wrong argument order. This breaks addon integrations — WBGamification currently never receives reactions or space joins because it listens to the spec-correct names which the code doesn't fire.

- [x] Rename reaction hook to `buddynext_reaction_added` with correct argument order (ReactionService)
- [x] Add missing `buddynext_reaction_removed` hook (ReactionService)
- [x] Rename comment hook to `buddynext_comment_created` with correct argument order (CommentService)
- [x] Add missing `buddynext_comment_updated` and `buddynext_comment_deleted` hooks (CommentService)
- [x] Rename block/unblock hooks to `buddynext_block` and `buddynext_unblock` (BlockService)
- [x] Fix typo: rename `buddynext_onboarding_complete` → `buddynext_onboarding_completed` (OnboardingService)
- [x] Rename space member joined hook to `buddynext_space_member_joined` with correct argument order including role (SpaceMemberService)
- [x] Rename space member removed hook to `buddynext_space_member_removed` (SpaceMemberService)
- [x] Rename space member left hook to `buddynext_space_member_left` (SpaceMemberService)
- [x] Rename space join approved hook to `buddynext_space_join_approved` (SpaceMemberService)
- [x] Add missing connection ID argument to `buddynext_connection_requested` and `buddynext_connection_accepted` (ConnectionService)
- [x] Add `buddynext_report_created` hook to ModerationService when a report is submitted
- [x] Update CLAUDE.md Key Integration Hooks section to reflect corrected hook names

---

### BLOCK 12 — Frontend UX Completion (bridges the gap between "backend works" and "users can use it")

**Context:** Backend services are functional. Templates render. But the product is not usable end-to-end because navigation doesn't exist, primary social actions (follow/connect) are wired to missing JS actions, and key editing flows point to WP admin. This block makes the product usable by real users.

#### 12a — Global BuddyNext subnav (all pages)

`templates/partials/nav.php` — sticky `.bn-subnav` partial included in every main template.

- Links: Feed · Explore · Members · Spaces · Notifications (with unread pill) · Messages (with unread pill)
- URLs resolved from `buddynext_page_*` options with `home_url()` fallbacks
- Registered as WordPress menu location `buddynext-community` so admins can reorder/add items
- Unread counts fetched from DB at render time (cached via `wp_cache_get`) — no JS polling needed at render
- Dark mode toggle button
- Mobile: collapses to horizontal scroll (`overflow-x: auto`, snap scrolling)
- Desktop: sticky below theme header (`top: 60px` per wireframe)
- Active item detected by comparing current URL to nav URL (`is_page()` / `get_query_var`)
- Design: matches `home-feed.html` `.bn-subnav` exactly (44px tall, `.bn-nav-item.active` brand underline)

**Options to set (WP-CLI on activation):**
- `buddynext_page_feed` — ID of the Community Feed page
- `buddynext_page_members` — ID of the Members page
- `buddynext_page_spaces` — ID of the Spaces page
- `buddynext_page_notifications` — ID of Notifications page (create if absent)
- `buddynext_page_messages` — ID of Messages page (create if absent)

**Wire into:** `templates/feed/home.php`, `templates/feed/explore.php`, `templates/profile/view.php`, `templates/profile/edit.php`, `templates/directory/members.php`, `templates/spaces/directory.php`, `templates/notifications/index.php`, `templates/messages/list.php`

- [x] Set `buddynext_page_feed`, `buddynext_page_members`, `buddynext_page_spaces` options (2026-03-22)
- [x] Create `templates/partials/nav.php` — full implementation (2026-03-22)
- [x] Wire nav partial into all 8 main templates (2026-03-22)
- [x] Register `buddynext-community` as WordPress menu location in `Core/Plugin.php`

#### 12b — Profile owner action bar

- [x] Create `templates/partials/profile-actions.php` (2026-03-22)
- [x] Remove inline edit link from `view.php` profile actions div (2026-03-22)
- [x] Wire `profile-actions.php` into `templates/profile/view.php` (own-profile only) (2026-03-22)

#### 12c — Follow / Unfollow / Connect in profile and directory

- [x] Add follow/unfollow/connect to `assets/js/profile/store.js` (2026-03-22)
- [x] Implement full `assets/js/members/store.js` (2026-03-22)

#### 12d — Cover photo upload REST endpoint

- [x] Add `POST /me/cover` + `DELETE /me/cover` to `ProfileController` (2026-03-22)

#### 12e — Page existence assurance

- [x] Create `includes/Core/PageSetup.php`
- [x] Bind and init in `Plugin.php`

#### 12f — Avatar system & site-wide defaults (Members admin → Avatar & Cover tab)

`includes/Admin/Members/AvatarSettings.php` — new tab after Profile Fields.

Settings:
- `bn_avatar_style` — `initials` | `default_image` | `gravatar` (default: `initials`)
  - **initials**: SVG data URI with coloured circle + member initials (fully offline, no Gravatar)
  - **default_image**: single fallback image set by admin, shown for all members without a custom avatar
  - **gravatar**: defers to WordPress core / Gravatar (custom uploads still win)
- `bn_default_avatar_url` — URL of admin-uploaded fallback avatar image
- `bn_default_cover_url` — URL of site-wide default cover photo (shown on profiles with no cover set)

`AvatarService::filter_avatar_data()` updated to read these options — priority: user custom upload → site style setting → SVG initials fallback.

Avatar `img src` in all templates uses `esc_attr()` (not `esc_url()`) so SVG data URIs render correctly.

- [x] Create `includes/Admin/Members/AvatarSettings.php` (2026-03-22)
- [x] Add "Avatar & Cover" tab to Members admin panel (2026-03-22)
- [x] Update `AvatarService::filter_avatar_data()` to honour site style + default image options (2026-03-22)
- [x] Fix `esc_url()` → `esc_attr()` for avatar src in all templates (directory, profile view, edit, onboarding) (2026-03-22)
- [x] Add `POST /me/cover` + `DELETE /me/cover` REST endpoints (2026-03-22)

#### 12g — Member Types (grouping / segmentation of members)

A **Member Type** is an admin-defined label assigned to users (e.g. "Student", "Alumni", "Faculty", "Verified Creator"). Each user has one type at a time (Free tier). Types appear as badges on profile cards, profile pages, and directory filter tabs. Each type gets its own routable directory URL (`/members/alumni/`). Profile field groups can be scoped to a specific type.

---

##### Database Schema

**`bn_member_types`** — type definitions:

```sql
CREATE TABLE bn_member_types (
  id          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(100)   NOT NULL,            -- 'alumni', 'student'
  name        VARCHAR(100)   NOT NULL,            -- 'Alumni'
  description TEXT           NOT NULL DEFAULT '',
  color       VARCHAR(7)     NOT NULL DEFAULT '#0073aa',   -- badge background
  text_color  VARCHAR(7)     NOT NULL DEFAULT '#ffffff',   -- badge text
  icon_svg    MEDIUMTEXT     NOT NULL DEFAULT '',          -- inline SVG
  sort_order  SMALLINT       NOT NULL DEFAULT 0,
  show_in_dir TINYINT(1)     NOT NULL DEFAULT 1,  -- appear as directory tab
  self_select TINYINT(1)     NOT NULL DEFAULT 0,  -- user can self-assign
  created_at  DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY  uq_slug (slug),
  KEY         idx_sort (sort_order)
);
```

**`bn_member_type_assignments`** — user ↔ type mapping (source of truth):

```sql
CREATE TABLE bn_member_type_assignments (
  id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  type_id     INT UNSIGNED    NOT NULL,
  assigned_by BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = self-assigned
  assigned_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY  uq_user_type (user_id, type_id),  -- no duplicate assignments
  KEY         idx_user_id  (user_id),
  KEY         idx_type_id  (type_id)
);
```

**`bn_profile_groups`** — add `type_restriction` column (dbDelta adds it on next activation):

```sql
-- Column added to existing CREATE TABLE statement in Installer::schema()
type_restriction VARCHAR(100) DEFAULT NULL
-- NULL = visible to all types; 'alumni' = only shown for users with that type slug
```

---

##### Scale Design — 100k Members

The system uses a **write-through usermeta cache** pattern:

| Layer | Purpose | Performance |
|-------|---------|-------------|
| `bn_member_type_assignments` | Source of truth + audit trail | Indexed JOIN, fast for admin/export |
| `wp_usermeta` key `bn_member_type` | Denormalized read cache (slug) | Powers `WP_User_Query` `meta_query` — O(log n) indexed lookup |
| `CacheService` key `bn_member_type_{user_id}` | In-memory type object cache | Zero DB hit on hot paths |
| `CacheService` key `bn_member_types_all` | All-types list cache | One DB read for all filter tabs |
| `CacheService` key `bn_member_type_count_{type_id}` | Count per type | Admin stats without per-request COUNT |

**Assignment write path** (every `assign_type()` call):
1. `DELETE FROM bn_member_type_assignments WHERE user_id = %d` (single-type enforcement in Free)
2. `INSERT INTO bn_member_type_assignments ...` (protected by `uq_user_type` — safe under concurrent requests)
3. `update_user_meta($user_id, 'bn_member_type', $slug)` — write-through to usermeta
4. `CacheService::delete("bn_member_type_{$user_id}")` + `bn_member_type_count_{$type_id}`

**Directory read path** (100k-safe):
- `WP_User_Query` with `meta_query => [['key' => 'bn_member_type', 'value' => 'alumni']]`
- Hits `wp_usermeta` index on `(meta_key, meta_value)` — no custom table JOINs in the hot path
- Pagination is cursor-based (ID offset) for consistent performance at depth

**Pro extension** (multi-type): no schema change needed. The assignments table already allows `(user_id=5, type_id=1)` + `(user_id=5, type_id=2)`. Free tier enforces single-type in service code only.

---

##### REST Endpoints

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| `GET`    | `/member-types`                    | Public | List all types (id, slug, name, color, text_color, icon_svg, member_count) |
| `POST`   | `/member-types`                    | Admin  | Create type |
| `PUT`    | `/member-types/(?P<slug>[a-z0-9-]+)` | Admin  | Update type |
| `DELETE` | `/member-types/(?P<slug>[a-z0-9-]+)` | Admin  | Delete type + cascade |
| `GET`    | `/users/(?P<id>\d+)/member-type`   | Public | Get user's assigned type or null |
| `PUT`    | `/users/(?P<id>\d+)/member-type`   | Admin or self (if self_select=1) | Assign type to user |
| `DELETE` | `/users/(?P<id>\d+)/member-type`   | Admin  | Remove user's type |

---

##### Admin Tab — Members → Member Types

`includes/Admin/Members/MemberTypesManager.php` — tab after Avatar & Cover.

- **Types list table:** colored badge preview · name · slug · member count · edit · delete
- **Create/Edit form:** name · auto-slug (JS-generated) · description · color picker (`<input type="color">`) · text_color · icon_svg textarea · sort_order · show_in_dir toggle · self_select toggle
- **In Edit Member view:** "Member Type" dropdown — select or clear type
- Form POSTs to `admin_post_bn_save_member_type` / `admin_post_bn_delete_member_type`
- On delete: cascade assignment + profile field group cleanup shown as confirmation

---

##### Frontend Integration

| Surface | What changes |
|---------|-------------|
| `templates/directory/members.php` | Type filter pill tabs above grid; small type badge chip on each member card |
| `templates/profile/view.php` | Type badge chip in profile header (below display name) |
| `includes/Core/PageRouter.php` | Rewrite rule for `/members/{type-slug}/` — resolves to directory template with `bn_member_type` query var |
| `templates/blocks/member-card.php` | Type badge in card footer |

---

##### Hooks Fired

```php
do_action( 'buddynext_member_type_assigned', int $user_id, string $new_slug, string $old_slug );
do_action( 'buddynext_member_type_removed',  int $user_id, string $removed_slug );
do_action( 'buddynext_member_type_created',  int $type_id, array $type_data );
do_action( 'buddynext_member_type_deleted',  int $type_id, string $slug );
```

---

##### Files

**New:**
```
includes/MemberTypes/MemberTypeService.php
includes/REST/Controllers/MemberTypeController.php
includes/Admin/Members/MemberTypesManager.php
```

**Modified:**
```
includes/Core/Installer.php          — add 2 tables + type_restriction column
includes/Core/PageRouter.php         — /members/{type-slug}/ rewrite rule
includes/Core/Plugin.php             — bind member_types service in container
includes/REST/Router.php             — register MemberTypeController
includes/Admin/Members.php           — add Member Types tab + wire MemberTypesManager
templates/directory/members.php      — type filter tabs + card badges
templates/profile/view.php           — type badge in header
templates/blocks/member-card.php     — type badge in card
```

---

- [x] Add `bn_member_types` + `bn_member_type_assignments` to `Installer.php`; add `type_restriction` to `bn_profile_groups` schema
- [x] Create `includes/MemberTypes/MemberTypeService.php` — CRUD, assign, cache, hooks
- [x] Create `includes/REST/Controllers/MemberTypeController.php` — 7 routes
- [x] Create `includes/Admin/Members/MemberTypesManager.php` — admin tab + form handlers
- [x] Wire tab into `Members.php`; bind service in `Plugin.php`; register controller in `Router.php`
- [x] Update `PageRouter.php` — `/members/{type-slug}/` rewrite
- [x] Update `templates/directory/members.php` — type filter tabs + card badges
- [x] Update `templates/profile/view.php` — type badge in header

---

## Phase 1 — Core Foundation

**Goal:** Bootable plugin with all `bn_*` tables, `buddynext_can()` permission function, Abilities API, and webhook endpoint.

**Detailed step-by-step:** `docs/superpowers/plans/2026-03-20-phase-1-core-foundation.md`

### Deliverables Checklist

- [x] `composer.json` with PSR-4 autoload + PHPUnit dev dependency
- [x] `phpunit.xml.dist` configured
- [x] `buddynext.php` — plugin header + constants + `plugins_loaded:15` bootstrap
- [x] `includes/Core/Container.php` — singleton DI container
- [x] `includes/Core/Plugin.php` — orchestrates boot sequence
- [x] `includes/Core/Installer.php` — 45 `bn_*` tables created
- [x] `includes/Core/Abilities.php` — registers all `buddynext-*` abilities
- [x] `includes/Core/PermissionService.php` — `buddynext_can()` 4-layer implementation
- [x] `includes/REST/Router.php` — registers `buddynext/v1` namespace
- [x] `includes/REST/Controllers/AccessWebhookController.php` — all 6 webhook actions
- [x] `includes/Admin/Settings.php` — admin menu registered
- [x] `includes/Core/Installer.php` — **add 5 new tables** (see BLOCK 1 above)
- [x] `includes/Core/Installer.php` — **add 2 new columns** to bn_posts (see BLOCK 1 above)
- [x] All tests pass

---

## Phase 2 — Social Graph ✅

**Spec:** `docs/specs/features/01-social-graph.md`

### Done
- [x] FollowService, ConnectionService, BlockService (+ muted_users), PrivacyService
- [x] All REST controllers + routes
- [x] Block check before follow/connect
- [x] ConnectionService::remove_connection() for accepted pairs
- [x] ConnectionController DELETE fallback to remove_connection()

### Three relationship types
- **Follow** — asymmetric, no approval. Powers feed access.
- **Connection** — mutual, request → accept. Powers private content access.
- **Block/Mute** — Block is hard (invisible + no DM). Mute is soft (invisible in feed only).

### DB Tables
`bn_follows`, `bn_connections`, `bn_blocks`

### Services
- FollowService — follow, unfollow, follow status, follower/following lists, suggestions
- ConnectionService — request, accept, decline, withdraw, connection status, mutual count
- BlockService — block, unblock, mute, unmute, block/mute status

### REST Endpoints
Follow, unfollow, connect, update connection, remove connection, block, unblock, list followers/following/connections, user suggestions

### Events fired
- Follow / Unfollow
- Connection requested / accepted / declined / withdrawn
- Block / Unblock *(name fix pending — BLOCK 11)*

### Privacy filter
`buddynext_can_view` — lets addons extend visibility rules

### WPMediaVerse follow sync
Follow events sync bidirectionally between BuddyNext and WPMediaVerse (loop-safe)

---

## Phase 3 — Activity Feed ⚠️

**Spec:** `docs/specs/features/02-activity-feed.md`
**Mockup:** `home-feed.html`, `explore-feed.html`

### Done
- [x] PostService, FeedService, PollService, ShareService, BookmarkService
- [x] FeedController, PostController, PollController, ShareController, BookmarkController
- [x] is_announcement written + home_feed prepends announcement on page 1
- [x] PostService::delete() cascades to bn_reactions + bn_comments + bn_poll_votes
- [x] FeedController: POST /feed/announcements/{id}/dismiss

### Remaining
- [x] SafeguardService check in PostService::create() (BLOCK 5)
- [x] Content warning columns written in PostService::create()/update() (BLOCK 6)
- [x] FeedService: exclude shadow-banned + suspended-hide users (BLOCK 3)

### Post Types
| Type | Notes |
|------|-------|
| `text` | Rich text, @mentions, #hashtags |
| `link` | URL auto-unfurl via oEmbed |
| `poll` | 2–5 options, vote inline |
| `activity` | System-generated items |
| `photo` / `video` | WPMediaVerse only — no standalone media in BuddyNext |

### Feed Scopes
- `home` — followed users + joined spaces
- `profile` — single user's posts
- `space` — posts within a space
- `explore` — public, no login needed

### Key Features
- Cursor-based pagination (no offset)
- Connections-first ordering free / AI ranking Pro
- Scheduled posts
- Edit post (tracks edited_at)
- Pinned posts (per profile + per space)
- "New posts" bar — no auto-scroll

### DB Tables
`bn_posts`, `bn_bookmarks`, `bn_shares`, `bn_poll_options`, `bn_poll_votes`, `bn_feed_items` (pre-computed cache for >1M member communities)

---

## Phase 4 — Profiles + Member Directory + Search ⚠️

**Spec:** `docs/specs/features/04-member-directory-search.md`, `05-user-profiles.md`
**Mockups:** `user-profile.html`, `edit-profile.html`, `member-directory.html`, `search-results.html`

### Done
- [x] ProfileService, SearchService, MemberDirectoryService
- [x] ProfileController, SearchController
- [x] SearchService passes viewer_id for block exclusion
- [x] ProfileController: GET /profile-fields + POST /profile-fields (admin)

### Remaining
- [x] SearchService: exclude shadow-banned + suspended users (BLOCK 3)
- [x] MemberDirectoryService: exclude suspended users (BLOCK 3)

### Profile Field Architecture
`bn_profile_fields` and `bn_profile_values` — supports repeater fields (Work Experience, Education) where multiple entries exist for the same field per user.

### Built-in Field Groups
Basic Info, Social Links, Work Experience (repeater), Education (repeater), Skills (tag multi-select)

### Search Index
`bn_search_index` — updated async, privacy-aware, MySQL FULLTEXT by default. Swappable to ElasticSearch or Algolia via filter.

---

## Phase 5 — Spaces ⚠️

**Spec:** `docs/specs/features/03-spaces.md`
**Mockups:** `spaces-directory.html`, `space-home.html`, `space-settings.html`, `space-moderation.html`

### Done
- [x] SpaceService, SpaceMemberService, SpaceCategoryController, SpaceController
- [x] SpaceService::hydrate() returns avatar_url + cover_image_url
- [x] SpaceMemberService::adjust_member_count() busts cache
- [x] SpaceController: GET /spaces/{id}/pending-requests
- [x] SpaceController: 404 for secret spaces when viewer not member

### Remaining
- [x] SpaceMemberService: ban_from_space, unban_from_space, is_banned_from_space (BLOCK 2)
- [x] SpaceMemberService::get_members(): exclude suspended users (BLOCK 3)
- [x] PermissionService: space-scoped `buddynext-spaces/moderate` check (BLOCK 8)

### Space Types
- Open (instant join), Private (request to join), Secret (invite only)

### Sub-spaces
One level deep. `bn_spaces.parent_id` nullable. Inherits parent privacy by default.

### Roles
Owner → Moderator → Member. Extensible via `buddynext_space_roles` filter.

### DB Tables
`bn_spaces`, `bn_space_members`, `bn_space_categories`

---

## Phase 6 — Notifications + Email ⚠️

**Spec:** `docs/specs/features/06-notifications-email.md`
**Mockups:** `notifications.html`, `email-editor.html`

### Done
- [x] NotificationService (fires buddynext_notification_created, grouped events fixed)
- [x] EmailSender, EmailDispatchListener
- [x] NotificationPrefService: get_all_prefs() + set_all_prefs()
- [x] NotificationController: GET/PUT /me/notification-prefs
- [x] VerificationService + VerificationListener
- [x] EventListener: follow, connection, reaction, comment, space, strike, badge handlers
- [x] 16 email templates seeded in Installer

### Remaining
- [x] EventListener: suspension email, unsuspend email, warning notification, appeal emails (BLOCK 9)
- [x] CronScheduler: buddynext_admin_alerts job for queue threshold alerts (BLOCK 9)
- [x] OutboundWebhookService — event dispatch on all 13 spec events (BLOCK 7)

### In-app Notifications
`bn_notifications`, `bn_notification_prefs`

### Email System
`bn_email_templates`, `bn_email_log` — email queue via WP-Cron, digest deduplication via digest key

### Email Catalog (free)
New follower, Connection request/accepted, New post in space, Post reacted, Post commented, @mention, New DM (routed via `mvs_message_sent`), Space join request/accepted, Moderation action

---

## Phase 7 — Reactions + Comments + Hashtags ✅

**Spec:** `docs/specs/features/08-reactions-comments.md`, `18-hashtags.md`

### Done
- [x] ReactionService (fires buddynext_post_reacted, increments/decrements reaction_count)
- [x] CommentService (fires buddynext_post_commented, increments/decrements comment_count)
- [x] HashtagService (follow/unfollow/autocomplete, post_count maintenance, banned filter)
- [x] HashtagController, ReactionController, CommentController
- [x] PollController: GET /posts/{id}/my-vote
- [x] Installer: bn_reactions PRIMARY KEY fixed (dbDelta requires PK)

### Reactions
6 emoji reactions on posts and comments. One reaction per user per object — re-reacting changes the emoji rather than adding a second. `bn_reactions`

### Comments
Threaded one level deep (comment + replies). Rich text, @mentions supported. `bn_comments`

### Hashtags
Registry with post count, pivot table linking posts to hashtags, follow tracking per user. `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`

---

## Phase 8 — Moderation

**Spec:** `docs/specs/features/09-moderation.md`
**Mockup:** `moderation-queue.html`, `space-moderation.html`

Report → Review → Action → Log. All actions logged in `bn_mod_log`.

### Done
- [x] ModerationService: report(), dismiss(), escalate(), resolve(), issue_strike(), reverse_strike(), get_queue(), get_strikes(), get_active_strike_count()
- [x] ModerationController: POST /reports, GET /reports, GET /reports/queue, POST /reports/{id}/dismiss|escalate|resolve, GET/POST /users/{id}/strikes, POST /users/{id}/strikes/{sid}/reverse
- [x] ModerationLogService: log()
- [x] EventListener: strike threshold enforcement (warn/suspend at configurable thresholds)

### Remaining (see BLOCK 2–9 above)
- [x] ModerationService: shadow_ban, unshadow_ban, is_shadow_banned
- [x] ModerationService: suspend, unsuspend, is_suspended, get_active_suspension
- [x] ModerationService: warn, submit_appeal, resolve_appeal
- [x] SpaceMemberService: ban_from_space, unban_from_space, is_banned_from_space
- [x] SafeguardService (new) — banned words, rate limit, link blocklist, new member gate
- [x] Content warning columns + PostService/FeedService/ModerationController support
- [x] ModerationController: 13 new routes (shadow-ban, suspend, warn, appeals, space bans)
- [x] FeedService/SearchService/MemberDirectoryService: shadow-ban + suspension filtering
- [x] SearchService deindex triggered on shadow-ban and suspension
- [x] Space-scoped permission enforcement in PermissionService + ModerationController
- [x] EventListener: suspension email, appeal emails, admin alert cron
- [x] OutboundWebhookService (new) + OutboundWebhookController

### DB Tables
Existing: `bn_reports`, `bn_mod_log`, `bn_user_strikes`
To add (BLOCK 1): `bn_user_suspensions`, `bn_appeals`, `bn_space_bans`

---

## Phase 9 — Direct Messaging (UI Bridge) ✅

**Spec:** `docs/specs/features/07-direct-messaging.md`
**Mockups:** `dm-list.html`, `dm-thread.html`, `message-requests.html`

WPMediaVerse owns the engine. BuddyNext builds the UI that consumes WPMediaVerse REST API.

### Done
- [x] WPMediaVerse bridge — mvs_buddynext_active, mvs_can_send_message, mvs_message_sent
- [x] templates/messages/list.php, thread.php, requests.php
- [x] Auth redirect guard on message templates
- [x] REST route paths corrected (mvs/v1/... not mvs/v1/messaging/...)

### What BuddyNext owns in Phase 9
- Bridge that suppresses WPMediaVerse's own DM UI when BuddyNext is active
- Block check: prevent sending a message to a blocked user
- Route incoming DM notifications through BuddyNext notification system
- Conversation list, chat thread, and message requests templates (matching mockups)
- Unread DM count badge in BuddyNext navigation
- DM link on member profile cards and member directory

No BuddyNext tables for DM. All data lives in WPMediaVerse tables.

---

## Phase 10 — Bridges ✅

**Specs:** `docs/specs/features/12–15-*.md`

### Done
- [x] All 4 bridges exist with class_exists guards in init()
- [x] Bridges fire at plugins_loaded:25 (after Pro plugins at :20)
- [x] Jetonomy bridge: duplicate handler removed; EventListener is authoritative
- [x] CareerBoard bridge: accepted_args fixed (4→3), employer resolved via get_post_field

### WPMediaVerse Bridge
- DM integration: BuddyNext UI replaces WPMediaVerse chat panel when both active
- Block check prevents sending to blocked users
- DM notifications routed through BuddyNext notification system
- WPMediaVerse media uploads create feed posts in BuddyNext
- Reactions and comments on WPMediaVerse media create BuddyNext notifications
- Media tab injected on space pages; upload widget in post composer

### Jetonomy Bridge
- Forum posts optionally appear in BuddyNext feed (admin toggle, default off)
- Jetonomy replies create BuddyNext notifications
- @mention parsing for Jetonomy post content
- Forum tab injected on linked spaces
- Hot Topics block scoped to linked forum

### WBGamification Bridge
- Badge awards and level changes create BuddyNext notifications
- BuddyNext actions (follow, post, comment, join space) feed into gamification point system
- Leaderboard template integrated

### Career Board Bridge
- New job postings appear as feed posts in BuddyNext
- Application submitted/withdrawn/status-changed create notifications to employer or applicant

---

## Phase 11 — Gutenberg Blocks + Onboarding

**Spec:** `docs/specs/features/10-onboarding-setup-wizard.md`, `11-gutenberg-blocks.md`
**Mockups:** `widgets-blocks.html`, `onboarding.html`, `register-login.html`

### Done
- [x] BlockRegistrar — all 17 blocks registered with block.json
- [x] Block render callbacks — all 17 blocks have real PHP render functions (confirmed 2026-03-22)
- [x] Block patterns — Community Home, Profile, Spaces Directory, Member Directory (confirmed 2026-03-22)
- [x] Block supports declarations in block.json (color, typography, spacing) (confirmed 2026-03-22)
- [x] SetupWizard admin page — 8-step wizard fully implemented (confirmed 2026-03-22)

### Remaining (see BLOCK 13 + BLOCK 14 below)
- [x] OnboardingService nudge emails — WP-Cron jobs at +24h and +72h after registration
- [x] InviteService — send email on create(), resend(), admin CSV upload UI + 7-day expiry

### Core Blocks (all free)
Activity Feed, Member Directory, Space Directory, User Profile, Follow Button, Connect Button, Notification Bell, Unread DM Badge, Trending Hashtags, People You May Know, Hot Topics (Jetonomy bridge), Space Members, Leaderboard (Gamification bridge)

### Pro Blocks
Membership Gate, Analytics Dashboard

### Setup Wizard
6-step first-run wizard: Welcome → Create Profile → Find Members → Join Spaces → Connect Integrations → Done

---

## Phase 12 — Theme Integration ✅

**Spec:** `docs/specs/features/20-theme-integration.md`

Works out of the box on every theme — block themes, BuddyX, Reign, and classic themes all inherit BuddyNext styling automatically.

### Done
- [x] `theme.json` at plugin root — neutral defaults for color, typography, spacing. Active theme always overrides.
- [x] `TokenService` — maps WordPress preset vars to `--bn-*` CSS tokens, output at `wp_head`. All component CSS uses `--bn-*` only.
- [x] `buddynext_css_vars` filter — lets themes inject Customizer values (BuddyX Pro, Reign hook this to forward Kirki color and font settings)
- [x] Gutenberg blocks declare `supports` in `block.json` so block editor controls inherit active theme palette and fonts

---

## Phase 16 — Admin Panel

**Spec:** `docs/specs/features/16-admin-settings.md`
**Mockups:** `admin-settings.html`, `admin-members.html`, `admin-spaces.html`, `admin-integration-hub.html`, `admin-nav-manager.html`, `email-editor.html`

### Done
- [x] All 6 admin classes registered + data layer methods exist (Settings, Members, Spaces, NavManager, IntegrationHub, EmailEditor)
- [x] admin_post_ hookups for suspend/unsuspend/export (Members), delete space (Spaces)

### Remaining (see BLOCK 10 above)
- [x] `AdminPageBase` abstract class — premium wrapper matching `admin-settings.html` design
- [x] `Settings.php` `render_page()` — 10 tabs with real form fields (2026-03-22)
- [x] `Members.php` `render_page()` — stats cards, filterable table, bulk actions, pagination (2026-03-22)
- [x] `Spaces.php` `render_page()` — table with owner, type, member count, actions (2026-03-22)
- [x] `IntegrationHub.php` `render_page()` — addon cards, status badges, feature toggles (2026-03-22)
- [x] `NavManager.php` `render_page()` — three-panel layout, page assignments, visibility config, drag-reorder, conflict validation (2026-03-22)
- [x] `EmailEditor.php` `render_page()` — template list + inline editor + variable reference (2026-03-22)

---

## Pass 1 Integration Test Results (2026-03-23)

**Completed:** 2026-03-23
**PHPUnit:** 640 tests · 0 failures · 21 skipped (Abilities API requires WP 6.9+ — expected)
**WPCS:** 0 violations in `includes/` · 0 violations in `templates/`

### Playwright Browser Coverage
| Page | URL | Result |
|------|-----|--------|
| Home Feed | `/community-feed?autologin=1` | ✅ Feed composer + empty state renders |
| Members | `/members?autologin=1` | ✅ Directory grid + follow buttons visible |
| Spaces | `/spaces?autologin=1` | ✅ Space cards render |
| Admin Settings | `/wp-admin/admin.php?page=buddynext-settings` | ✅ 10-tab settings page renders |
| Admin Members | `/wp-admin/admin.php?page=buddynext-members` | ✅ Members table renders |
| Admin Spaces | `/wp-admin/admin.php?page=buddynext-spaces` | ✅ Spaces table renders |
| Admin Email Editor | `/wp-admin/admin.php?page=buddynext-email` | ✅ Template catalogue renders |
| Messages | `/messages?autologin=1` | ✅ WPMediaVerse bridge state visible |

### Fixes Applied During Pass 1
- `HashtagService.php` — removed duplicate `$wpdb->` calls introduced by deduplication script
- `ProfileService.php` — same duplication fix (52 lines removed)
- `templates/feed/home.php` — recreated from scratch after file was missing (PHP fatal)
- `templates/partials/nav.php` — Yoda conditions + NoCaching phpcs:disable block
- `templates/partials/profile-actions.php` — expanded inline associative array to multi-line
- `templates/spaces/directory.php`, `templates/feed/explore.php`, `templates/notifications/index.php` — auto-fixed UseRequire warnings
- `templates/auth/verify.php` — **created** (was missing entirely)

### Known Gaps for Pass 2
- Dark mode browser verification pending (all CSS has `[data-theme="dark"]` blocks but no automated screenshot comparison run)
- Mobile 390px layout browser verification pending
- PHPUnit coverage does not include Admin page classes (render methods are HTML output — integration test territory)

---

## Pro Phases

| Phase | Description |
|-------|-------------|
| P1 | Stripe Membership — tiers, paywalled spaces, Stripe SDK direct |
| P2 | AI Engine — feed ranking, recommendations, content moderation |
| P3 | WebSocket Real-time — Soketi, swaps RestPollingTransport |
| P4 | React Native Mobile App — Expo, white-labelable |
| P5 | Advanced Analytics — community dashboards |
| P6 | White-label — custom branding, domain |

---

## Design System Reference

Full token + primitive source: `docs/v2 Plans/tokens.css`
Style-guide canon: `docs/v2 Plans/style-guide.html`
v2 prototypes for major pages: `docs/v2 Plans/v2/*.html`
Plan + composition rules + uniformity gates: `docs/v2 Plans/PLAN.md`

### Key Rules
- CSS custom properties only — no inline `color:`, no hardcoded pixel values outside `:root`.
- Every component must support `[data-bn-theme="dark"]` (legacy `[data-theme="dark"]` still accepted).
- Spacing uses `--bn-s1` … `--bn-s16`.
- Radius uses `--bn-r-sm` / `-md` / `-lg` / `-xl` / `-full`.
- Typography uses `--bn-font-ui` / `--bn-font-display` / `--bn-font-mono` + `--bn-text-*`.
- Never `font-weight: bold` — use numeric weights (500, 600, 700, 800).
- Focus states: `box-shadow: var(--bn-ring); outline: none;` (the ring carries the visible focus indicator).

---

## Integration with Other Plugins

| Plugin | Status | Notes |
|--------|--------|-------|
| WPMediaVerse (free) | ✅ Ready | DM engine built, hooks in place |
| WPMediaVerse Pro | ✅ Ready | Group DM + WebSocket (Pro phase) |
| Jetonomy | ✅ Active | Forum bridge in Phase 10 |
| Jetonomy Pro | ✅ Active | Reactions hook verified |
| WBGamification | ✅ Installed | Bridge in Phase 10 |
| Career Board | ✅ Installed | Bridge in Phase 10 |
| BuddyX Theme | ✅ Active | Full-width canvas — see BLOCK BX below |

---

## Phase 13 — Unified Platform (BuddyNext as Master)

**Status:** In Progress
**Created:** 2026-03-24
**Principle:** BuddyNext is the boss. WPMediaVerse and Jetonomy are native sections of BuddyNext — not standalone plugins with their own chrome. Every community page renders inside BuddyNext's hub shell with the unified nav and community sidebar.

### Architecture

```
┌─────────────────── BuddyNext Hub Shell (1100px grid: 1fr + 300px) ──────────────┐
│                                                                                    │
│  ┌── Content Area (1fr) ──────────────────────────┐  ┌── Community Sidebar ──────┐ │
│  │                                                 │  │ Trending Topics           │ │
│  │  BuddyNext pages: Feed, Members, Spaces,       │  │ People to Follow          │ │
│  │    Notifications, Community Admin               │  │ Your Spaces               │ │
│  │                                                 │  │                           │ │
│  │  WPMediaVerse pages: Media Explore, Dashboard,  │  │ (shared across ALL pages) │ │
│  │    Single Media, Albums, Collections            │  │                           │ │
│  │                                                 │  │                           │ │
│  │  Jetonomy pages: Forum Listing, Thread,         │  │                           │ │
│  │    Category, User Topics                        │  │                           │ │
│  │                                                 │  │                           │ │
│  │  WPMediaVerse DM: Chat List + Thread ✅ DONE    │  │                           │ │
│  └─────────────────────────────────────────────────┘  └───────────────────────────┘ │
└────────────────────────────────────────────────────────────────────────────────────┘
```

### URL Map (target state)

| URL | Plugin | Content inside BuddyNext shell |
|-----|--------|-------------------------------|
| `/activity/` | BuddyNext | Home feed (includes media + forum activity) |
| `/activity/explore/` | BuddyNext | Explore feed |
| `/members/` | BuddyNext | Member directory |
| `/members/{slug}/` | BuddyNext | Unified profile (posts + media gallery + forum tab) |
| `/spaces/` | BuddyNext | Spaces directory |
| `/spaces/{slug}/` | BuddyNext | Space home (feed + forum tab + media tab) |
| `/media/` | WPMediaVerse | Media explore inside BN shell + sidebar |
| `/media/my/` | WPMediaVerse | User media dashboard inside BN shell |
| `/media/{id}/` | WPMediaVerse | Single media view inside BN shell |
| `/media/albums/` | WPMediaVerse | Albums inside BN shell |
| `/discussion/` | Jetonomy | Forum listing inside BN shell + sidebar |
| `/discussion/{slug}/` | Jetonomy | Forum thread inside BN shell + sidebar |
| `/discussion/{slug}/{topic}/` | Jetonomy | Topic/thread inside BN shell |
| `/messages/` | WPMediaVerse engine | Two-pane chat inside BN shell ✅ DONE |
| `/notifications/` | BuddyNext | All notifications (from all 3 plugins) |

### Integration Layers

**Layer 1 — Shell Wrapping (BN hub shell + sidebar on every page)**

Each plugin fires `before_content` / `after_content` actions. BuddyNext bridges hook them to inject the hub shell.

| Plugin | Hook | Bridge method |
|--------|------|---------------|
| WPMediaVerse | `mvs_before_content` | Opens `<div class="bn-hub-shell"><div class="bn-mvs-content">` |
| WPMediaVerse | `mvs_after_content` | Closes content div + renders sidebar + closes hub shell |
| Jetonomy | `jetonomy_before_content` | Opens `<div class="bn-hub-shell"><div class="bn-jt-content">` |
| Jetonomy | `jetonomy_after_content` | Closes content div + renders sidebar + closes hub shell |
| Messages | `buddynext_render_messages` | ✅ DONE — two-pane chat + sidebar |

- [ ] **WPMediaVerseBridge shell wrap** — hook `mvs_before_content` / `mvs_after_content` to wrap all MVS pages in BN hub shell + sidebar
- [ ] **JetonomyBridge shell wrap** — hook `jetonomy_before_content` / `jetonomy_after_content` to wrap all JT pages in BN hub shell + sidebar
- [ ] **Verify `mvs_before_content` fires on all MVS templates** — check explore, dashboard, media-single, album, collection, profile-edit
- [ ] **Verify `jetonomy_before_content` fires on all JT templates** — check community listing, topic, category, user-topics

**Layer 2 — Profile Unification (one profile shows everything)**

User profile at `/members/{slug}/` should have tabs for all content types:

| Tab | Source | Status |
|-----|--------|--------|
| Posts | BuddyNext `bn_posts` | ✅ Exists |
| Media | WPMediaVerse `mvs_media` where author = user | New |
| Discussions | Jetonomy topics where author = user | New |
| Connections | BuddyNext `bn_connections` | ✅ Exists |
| Badges | WBGamification via bridge | Exists (bridge done) |

- [ ] **Profile media tab** — WPMediaVerseBridge renders media grid on profile view (extend the existing `buddynext_part_profile_tab_bar_args` filter + add a panel-content seam)
- [ ] **Profile discussions tab** — JetonomyBridge renders user's forum topics on profile view (same seam as above)
- [ ] **Profile tab panel seam** — `buddynext_part_profile_tab_bar_args` already registers tabs in `templates/parts/profile-tab-bar.php`; add a matching panel-render hook so bridges can supply tab *content*, not a new tab-registry filter

**Layer 3 — Feed Unification (all activity in one stream)**

Home feed at `/activity/` should show activity from all 3 plugins:

| Content type | Source | Feed entry type | Status |
|---|---|---|---|
| Text/poll/link posts | BuddyNext | `text`, `poll`, `link` | ✅ |
| Media uploads | WPMediaVerse | `media_share` | Opt-in via bridge |
| Forum posts/replies | Jetonomy | `forum_post` | Opt-in via bridge (buddynext_jetonomy_feed_sync) |
| Job listings | Career Board | `job_post` | ✅ via bridge |
| Shared posts | BuddyNext | `share` | ✅ |

- [ ] **WPMediaVerse feed sync** — media uploads create `bn_posts` entries (type: `media_share`) with thumbnail + link
- [ ] **Feed card rendering** — `partials/post-card.php` renders `media_share` and `forum_post` types with appropriate previews
- [ ] **Admin toggle** — Settings page toggle for each feed sync source (media, discussions, jobs)

**Layer 4 — Search Unification (one search, all content)**

Search at `/activity/search/` already queries `bn_search_index`. Bridges index their content there:

| Content | Indexed by | Status |
|---|---|---|
| BuddyNext posts | SearchIndexListener | ✅ |
| Users | SearchIndexListener | ✅ |
| Spaces | SearchIndexListener | ✅ |
| Jetonomy discussions | JetonomyBridge | ✅ (on post create) |
| Career Board jobs | CareerBoardBridge | ✅ (on job create) |
| WPMediaVerse media | WPMediaVerseBridge | New |

- [ ] **Media search indexing** — WPMediaVerseBridge indexes media titles/descriptions in `bn_search_index` on `publish_mvs_media`
- [ ] **Search results rendering** — `templates/search/results.php` renders media and discussion results with type badges

**Layer 5 — Space Integration (spaces have media + forum tabs)**

Spaces at `/spaces/{slug}/` should have tabs for sub-content:

| Tab | Source | Status |
|-----|--------|--------|
| Feed | BuddyNext space posts | ✅ |
| Forum | Jetonomy linked forum | Partially done (bridge injects tab) |
| Media | WPMediaVerse space gallery | New |
| Members | BuddyNext space members | ✅ |

- [ ] **Space media tab** — WPMediaVerseBridge injects media gallery tab on space pages via `buddynext_space_tabs` filter
- [ ] **Space media upload** — post composer in space supports media attachment via MVS upload
- [ ] **Space tabs filter** — add `buddynext_space_tabs` filter in `templates/spaces/home.php`

**Layer 6 — Notification Unification**

All notifications from all plugins flow through BuddyNext's notification system. **Already complete** via bridges:

| Source | Notification types | Status |
|---|---|---|
| BuddyNext | follow, connect, react, comment, mention, space_join, etc. | ✅ |
| WPMediaVerse | new_message, media_favorited | ✅ |
| Jetonomy | discussion_reply, mention | ✅ |
| WBGamification | badge_awarded, level_changed | ✅ |
| Career Board | application_received, application_status | ✅ |

### Execution Priority

**Wave 1 — Shell Wrapping (immediate)**
1. WPMediaVerse shell wrap (all MVS pages inside BN hub shell + sidebar)
2. Jetonomy shell wrap (all JT pages inside BN hub shell + sidebar)
3. Browser-verify unified nav on all pages

**Wave 2 — Profile + Space Tabs**
4. Profile tabs filter + media tab + discussions tab
5. Space tabs filter + media tab
6. Browser-verify profile and space pages

**Wave 3 — Feed + Search**
7. Media feed sync (MVS uploads → bn_posts)
8. Media search indexing
9. Post card rendering for media_share + forum_post types
10. Search results for all content types

**Wave 4 — Dedicated Content Integration (no patchwork)**
11. Hashtag ↔ Tag bridge (see BLOCK HT below)
12. Post card unification — all templates use shared `partials/post-card.php`
13. Composer unification — same composer with media upload on activity + spaces
14. WP Menu System — `register_nav_menus()` meta box for all BuddyNext URLs

**Wave 5 — Level 2 Context Nav**
15. Context nav bar (slim secondary bar below platform nav)
16. Discussion context: Home | Search | Leaderboard
17. Space context: Feed | Forum | Media | Members | Settings
18. Media context: Explore | My Media | Albums
19. Community Admin context: Settings | Members | Reports

### Design Constraints

- Hub shell is always `max-width: 1100px; grid-template-columns: 1fr 300px`
- Content area gets `min-width: 0` to prevent overflow
- Sidebar hidden at `≤640px` (mobile)
- Each plugin's content area CSS uses its own prefix (`bn-mvs-content`, `bn-jt-content`)
- Design tokens flow from BuddyNext → plugin via CSS custom properties (BLOCK DT done)
- Dark mode controlled by BuddyNext `[data-theme="dark"]` (BLOCK DT done)

### Shared Sidebar Widget Spec — All Plugins Must Follow

Every sidebar widget across BuddyNext, Jetonomy, WPMediaVerse, and Jetonomy Pro MUST use the same card skeleton. No plugin invents its own card structure.

**Widget Wrapper Rules (ALL plugins MUST follow):**

Rule 1: Every sidebar widget is ONE `bn-sidebar-card` div. No bare `<div>`, no `<section>`, no `<aside>` inside the sidebar.

Rule 2: Every card has exactly TWO children — `__header` and `__body`. No exceptions.

Rule 3: `__header` contains ONLY a plain text label. No HTML tags inside, no icons, no links. Uppercase via CSS.

Rule 4: `__body` contains the widget content. All spacing/padding comes from `__body`, not the card.

Rule 5: List-based widgets (trending, members, tags) use separator rows inside `__body`. Last row has no border.

Rule 6: Empty state — if a widget has zero items, do NOT render the card at all. No "Nothing to show" messages.

Rule 7: Detection — use `did_action('buddynext_loaded')` (PHP) to choose skeleton. Never check `class_exists`.

Rule 8: Standalone — when BuddyNext is NOT active, output plugin's native card class (`jt-card`, `mvs-card`). The plugin's own CSS styles it.

**HTML skeleton (when BuddyNext active):**

```html
<div class="bn-sidebar-card">
  <div class="bn-sidebar-card__header">SECTION TITLE</div>
  <div class="bn-sidebar-card__body">
    <!-- Widget content here -->
  </div>
</div>
```

**HTML skeleton (standalone fallback — Jetonomy example):**

```html
<div class="jt-card jt-mb-md">
  <h4>Section Title</h4>
  <!-- Widget content here -->
</div>
```

**PHP detection pattern (copy-paste into any sidebar partial):**

```php
$bn_active = did_action( 'buddynext_loaded' );
$card_class   = $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md';
$header_tag   = $bn_active ? 'div' : 'h4';
$header_class = $bn_active ? ' class="bn-sidebar-card__header"' : '';
$body_open    = $bn_active ? '<div class="bn-sidebar-card__body">' : '';
$body_close   = $bn_active ? '</div>' : '';
```

**CSS tokens used by `bn-sidebar-card` (defined in `bn-base.css`):**

| Class | Tokens |
|---|---|
| `.bn-sidebar-card` | `background: var(--surface)`, `border: 1px solid var(--border)`, `border-radius: var(--r-lg)`, `overflow: hidden` |
| `.bn-sidebar-card__header` | `padding: var(--s3) var(--s4)`, `border-bottom: 1px solid var(--border-soft)`, `font-size: var(--text-xs)`, `font-weight: 700`, `text-transform: uppercase`, `letter-spacing: var(--ls-wider)`, `color: var(--text-3)` |
| `.bn-sidebar-card__body` | `padding: var(--s3) var(--s4)` |

**Content patterns inside `__body`:**

| Pattern | HTML | Tokens |
|---|---|---|
| Separator row | `<div class="bn-sbar-row">...</div>` | `padding: var(--s2) 0`, `border-bottom: 1px solid var(--border-soft)`, last-child no border |
| Row with avatar | avatar (36px circle) + name + meta in flex row | avatar `var(--r-full)`, name `var(--text-sm) 600`, meta `var(--text-xs) var(--text-3)` |
| Row with rank number | rank `var(--text-xs) var(--text-3)` + content | rank fixed 16px width |
| Primary text | strong link | `var(--text-sm)`, `font-weight: 600`, `color: var(--text-1)`, hover `var(--brand)` |
| Secondary/meta text | span | `var(--text-xs)`, `color: var(--text-3)` |
| Tag chips | `<a class="bn-sbar-tag">` | `var(--text-xs)`, inline-flex, `padding: var(--s1) var(--s2)`, `border-radius: var(--r-sm)`, `background: var(--bg-subtle)` |
| Stats row | two stat blocks side by side | value `var(--text-lg) 700`, label `var(--text-xs) uppercase var(--text-3)` |
| Action link | `<a>See all</a>` at bottom | `var(--text-sm)`, `color: var(--brand)`, `font-weight: 500` |

**Already implemented:**
- [x] BuddyNext sidebar (`partials/sidebar.php`) — native `bn-sidebar-card`
- [x] Jetonomy sidebar (`partials/sidebar.php`) — detects BuddyNext, outputs `bn-sidebar-card` when active, `jt-card` when standalone
- [ ] WPMediaVerse sidebar — needs same dual-output approach if/when MVS adds sidebar widgets
- [ ] Jetonomy Pro sidebar widgets (messaging, analytics) — must adopt `bn-sidebar-card` skeleton

### Completed (Wave 1 — 2026-03-25)

- [x] WPMediaVerse shell wrap — all MVS archive/single/taxonomy pages inside BN hub shell + sidebar
- [x] Jetonomy shell wrap — all JT pages inside BN hub shell + sidebar
- [x] Unified nav consistent on ALL 57 routes (mu-plugin whitelist fixed)
- [x] SVG icons for Media + Discussions nav items (wp_kses_post fix)
- [x] Messages rewrite — thin wrapper embedding MVS chat (-2,249 lines)
- [x] Virtual pages — no backing WP pages needed (BuddyPress technique)
- [x] Media upload in composer — Photo button → MVS REST upload → media_ids in bn_posts
- [x] Composer action buttons (Photo/Poll/Link) + Cancel in expanded view
- [x] Max 5 media per post enforcement
- [x] Hashtag page Like/Comment/Share/Save actions wired to correct REST endpoints

### Pending (priority order)

**P0 — Zero non-functional buttons (user experience)**
- [ ] **BLOCK PC** — Remove inline JT post cards from `hashtags/feed.php` (source of broken Like/Comment buttons)
- [ ] **BLOCK PC** — Convert `blocks/activity-feed.php` to use shared partial
- [ ] **BLOCK PC** — Full action audit: visit every page, click every button, verify REST succeeds

**P1 — Unified composer (consistency)**
- [ ] **BLOCK MC** — Extract composer into `partials/composer.php` shared partial
- [ ] **BLOCK MC** — Verify spaces composer has Photo/Poll/Link footer + Cancel + media preview

**P2 — Hashtag/Tag bridge (dedicated integration)**
- [ ] **BLOCK HT** — Add `Tag::list_by_tag()` to Jetonomy
- [ ] **BLOCK HT** — Add `buddynext_hashtag_related_discussions` filter in `hashtags/feed.php`
- [ ] **BLOCK HT** — JetonomyBridge hooks filter, renders separate "Related Discussions" section

**P3 — Navigation & menus**
- [ ] **BLOCK MN** — WP Menu System meta box for all BuddyNext/MVS/JT URLs
- [ ] **BLOCK L2** — Level 2 context nav bar per section

**P4 — Profile & space deep integration**
- [ ] Profile tabs — Media tab (MVS), Discussions tab (JT) on `/members/{slug}/`
- [ ] Space tabs — Media tab, Forum tab on `/spaces/{slug}/`
- [ ] Media search indexing — MVS media in `bn_search_index`
- [ ] Feed card rendering — `media_share` + `forum_post` types in post-card partial

---

### BLOCK HT — Hashtag ↔ Tag Bridge (Dedicated Integration)

**Problem:** The hashtag feed template (`hashtags/feed.php`) has a raw SQL query against `wp_jt_posts` to show Jetonomy discussions alongside BuddyNext posts. This is wrong:
1. Different schemas — `jt_posts.author_id` not `user_id` (causes DB error)
2. Different tag systems — `bn_hashtags` vs `jt_tags` (separate data)
3. Tight coupling — BuddyNext template shouldn't know Jetonomy's internals

**Principle:** Each plugin owns its own data. Cross-plugin content flows through bridge APIs only.

**Architecture:**

```
BuddyNext (owner)              Jetonomy (provider)
─────────────────               ───────────────────
bn_hashtags                     jt_tags
bn_post_hashtags                jt_post_tags

HashtagService                  Tag model
  get_feed($slug)                 list_by_tag($slug)
  get_trending()                  list_popular()

hashtags/feed.php               (no template involvement)
  queries bn_posts only
  fires filter for related

JetonomyBridge (in BuddyNext)
  hooks buddynext_hashtag_related_discussions
  calls Jetonomy\Models\Tag::list_by_tag()
  returns structured array
```

**Plugin-level changes:**

| Plugin | File | Change |
|--------|------|--------|
| **Jetonomy** | `includes/models/class-tag.php` | Add `list_by_tag($slug, $limit)` public static method — returns posts with that tag, using Jetonomy's own schema |
| **Jetonomy** | `includes/models/class-tag.php` | Add `exists($slug)` method — check if a tag exists in `jt_tags` |
| **BuddyNext** | `templates/hashtags/feed.php` | Remove ALL raw `jt_posts` queries. Add `apply_filters('buddynext_hashtag_related_discussions', [], $hashtag_slug)` |
| **BuddyNext** | `includes/Bridges/JetonomyBridge.php` | Hook `buddynext_hashtag_related_discussions` — call `Tag::list_by_tag()`, return structured data |
| **BuddyNext** | `templates/hashtags/feed.php` | Render related discussions in a separate labeled section (if filter returns data) |

**Data flow:**

```
User visits /activity/hashtag/buddynext/

1. BuddyNext queries bn_posts via bn_post_hashtags WHERE slug = 'buddynext'
   → Renders post cards (shared partial)

2. BuddyNext fires: apply_filters('buddynext_hashtag_related_discussions', [], 'buddynext')

3. JetonomyBridge hooks it:
   - Calls Tag::list_by_tag('buddynext', 5)
   - Jetonomy queries jt_posts via jt_post_tags WHERE tag.slug = 'buddynext'
   - Returns: [{id, title, slug, space_slug, reply_count, vote_score, author_name}]

4. Template renders "Related Discussions" section with JT data
   - Each item links to /discussion/s/{space}/t/{slug}/
   - "View all" links to /discussion/tag/buddynext/
   - Section doesn't render when Jetonomy is inactive (filter returns [])
```

**No raw cross-plugin SQL. No schema assumptions. Clean bridge API.**

---

### BLOCK PC — Post Card + Action Consistency

**Audit result (2026-03-25):** 6 of 7 templates already use shared `partials/post-card.php`.

| Template | Shared partial | Store | Actions work | Composer | Media upload |
|---|:---:|---|:---:|:---:|:---:|
| `feed/home.php` | Yes | `buddynext/post-card` | Yes | Yes | Yes |
| `feed/explore.php` | Yes | `buddynext/post-card` | Yes | No | No |
| `profile/view.php` | Yes | `buddynext/post-card` | Yes | No | No |
| `spaces/home.php` | Yes | `buddynext/post-card` | Yes | Yes | Yes |
| `hashtags/feed.php` | Yes (BN posts) | `buddynext/post-card` | Yes | No | No |
| `search/results.php` | Yes | `buddynext/post-card` | Yes | No | No |
| `blocks/activity-feed.php` | **No — inline** | `buddynext/post-card` | **No** | No | No |

**Remaining issues:**
- [ ] `hashtags/feed.php` has EXTRA inline HTML for Jetonomy discussion cards (raw `jt_posts` query) — these render non-functional Like/Comment buttons. Remove this section (BLOCK HT replaces it with bridge filter).
- [ ] `blocks/activity-feed.php` has inline post card with display-only counts (no interactive actions). Convert to shared partial or add action support.
- [ ] Audit ALL pages at 1280px: click every Like/Comment/Share/Save button, verify REST call succeeds. Zero non-functional buttons allowed.

**The `buddynext/post-card` store is the single source of truth for post actions.** Any template that shows posts must use the shared partial. Period.

---

### BLOCK MC — Unified Composer Partial

**Audit result (2026-03-25):** Both `feed/home.php` and `spaces/home.php` already have composers with file upload support.

| Template | Composer | Photo button | MVS upload | Poll | Link | Cancel | Privacy |
|---|:---:|:---:|:---:|:---:|:---:|:---:|:---:|
| `feed/home.php` | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| `spaces/home.php` | Yes | Yes | Yes | Yes | Yes | Needs check | Needs check |

**Problem:** The composer HTML is duplicated in both templates (~80 lines each). Changes made to one (like adding the Photo button footer) don't automatically appear in the other.

**Solution:** Extract into a shared partial `partials/composer.php` that both pages include.

- [ ] Create `templates/partials/composer.php` — accepts `space_id` (null for activity feed) via context
- [ ] `feed/home.php` includes it with `['space_id' => null]`
- [ ] `spaces/home.php` includes it with `['space_id' => $space_id]`
- [ ] Composer context passes `space_id` to REST POST body (space-scoped posts)
- [ ] `mvsRestBase` included in context for MVS media upload
- [ ] CSS for composer moves to `bn-feed.css` (shared, not inline)
- [ ] Verify: activity post + space post + photo post all work end-to-end

---

### Standalone Safety

Each plugin must work independently without BuddyNext:
- WPMediaVerse: renders its own pages with `get_header()` / `get_footer()` when BuddyNext absent
- Jetonomy: renders its own community pages with own nav when BuddyNext absent
- All bridge hooks guarded by `class_exists()` — zero coupling when counterpart is deactivated

---

## BLOCK BX — BuddyX Full-Width Canvas Integration

**Goal:** All BuddyNext, Jetonomy, and WPMediaVerse pages render with the SAME visual flow: BuddyX provides a full-width canvas (no container, no sidebar), and each plugin manages its own layout, nav, and sidebar on that canvas. Navigating between any community page — /activity/, /community/, /explore/ — must feel like one seamless product.

**Why this exists:**
- BuddyX `header.php` and `footer.php` wrap content in `<div class="container">` by default. Community plugin pages need full-width treatment so their own layout takes over.
- BuddyX `page.php` renders a page title, breadcrumb, and sidebar for shortcode-based pages (e.g. /explore/). These must be suppressed.
- Each plugin must signal "full-width intent" to BuddyX using a dedicated body class: `bn-page` (BuddyNext), `jt-page` (Jetonomy), `mvs-page` (WPMediaVerse).
- BuddyX checks for these classes in `header.php` + `footer.php` and skips the container wrapper. Each plugin then renders its own full-width layout.
- This must work in three standalone modes (BuddyNext alone, Jetonomy alone, WPMediaVerse alone) and in the combined stack.

**Architecture decision:**
- BuddyX owns the "is full-width?" check — it checks body classes, never plugin-specific logic.
- Each plugin adds its own body class before `get_header()` fires so BuddyX detects it on the very first `body_class` call.
- For `template_redirect` pages (BuddyNext hub routes, Jetonomy community pages, WPMediaVerse pages): body class added inside `dispatch_hub_template()` / `Template_Loader::render()` / WPMediaVerse template loader.
- For shortcode-rendered hub pages (regular WP pages with `[buddynext_*]` shortcodes): body class added via `PageRouter::maybe_add_hub_body_class()` on the `wp` action hook (after query resolves, before template loads).
- The `buddyx_is_full_width_page` filter is the escape hatch for cases where a body class can't be added (e.g. WPMediaVerse pages that don't call our code).

### Files Changed

| File | Plugin | What | Why |
|------|--------|------|-----|
| `wp-content/themes/buddyx/header.php` | BuddyX theme | Extended container-skip logic to check `bn-page`, `jt-page`, `mvs-page` body classes and `buddyx_is_full_width_page` filter | Container was only skipped for built-in template classes; community plugin pages were always wrapped |
| `wp-content/themes/buddyx/footer.php` | BuddyX theme | Same container-skip logic as header | Container close `</div>` must be skipped if header skipped the open |
| `wp-content/themes/buddyx/page-templates/community-full-width.php` | BuddyX theme | New WP page template (`Template Name: Community Full Width`) — no sidebar, no page title, no breadcrumb; renders `the_content()` at full width | Shortcode hub pages (BuddyNext pages assigned in PageSetup) need a template that suppresses BuddyX page chrome; assign this template to all hub pages |
| `wp-content/plugins/buddynext/includes/Bridges/BuddyXBridge.php` | BuddyNext | New bridge class; hooks `buddyx_is_full_width_page` to detect WPMediaVerse pages by option IDs | WPMediaVerse doesn't add a body class yet; BuddyXBridge checks `mvs_page_*` options as fallback |
| `wp-content/plugins/buddynext/includes/Core/Plugin.php` | BuddyNext | Added `BuddyXBridge` instantiation in `buddynext_load_bridges` callback | Bridge must register before `wp` action fires |
| `wp-content/plugins/buddynext/includes/Core/PageRouter.php` | BuddyNext | Added `maybe_add_hub_body_class()` on `wp` action; adds `bn-page` + `bn-hub-{name}` + `no-sidebar` body classes for shortcode-rendered hub pages | `/explore/`, `/activity/`, `/members/` etc. are regular WP pages served via `page.php` — `template_redirect` never fires for them, so body class must be added earlier |
| `wp-content/plugins/jetonomy/includes/class-template-loader.php` | Jetonomy | Added `jt-page` body class filter before `get_header()` | BuddyX needs to see `jt-page` in `body_class` to skip container on all Jetonomy community pages |
| `wp-content/themes/buddyx/page.php` | BuddyX theme | Added community page guard at top: when `bn-page`, `jt-page`, or `mvs-page` is in body class, skip sub-header/breadcrumb, skip all sidebars, and output only `the_content()` | For shortcode-rendered hub pages (e.g. /explore/), `page.php` normally renders page title, breadcrumb, and sidebars. This suppresses all BuddyX page chrome so the plugin's own layout takes over at full width. |

### Remaining Tasks

- [x] **Fix `maybe_add_hub_body_class()` hub_options map** — replaced static 7-hub map with `wp_load_alloptions()` scan of all `buddynext_page_*` options dynamically; future hubs added via PageSetup work automatically.
- [x] **Suppress BuddyX page chrome on shortcode hub pages** — modified `page.php` to check for `bn-page`/`jt-page`/`mvs-page` body classes; when present, skips `buddyx_sub_header` (title/breadcrumb), skips all sidebars, and outputs only `the_content()`. The `community-full-width.php` template remains available as an explicit-assignment alternative for edge cases.
- [x] **WPMediaVerse `mvs-page` body class** — added `maybe_add_mvs_body_class()` to `WPMediaVerse\Core\TemplateLoader`; hooks at `wp` action; detects CPT pages via WP conditional tags and shortcode pages (e.g. dashboard) via `mvs_page_*` alloptions scan. `BuddyXBridge` filter fallback retained for Pro-only pages.
- [ ] **BuddyNext subnav on WPMediaVerse pages** — `WPMediaVerseBridge` needs a hook into WPMediaVerse template output to inject BuddyNext nav (equivalent to `jetonomy_before_content` hook used for Jetonomy pages).
- [x] **Browser-verify all three scenarios** — BuddyNext `/activity/` (template_redirect path), `/explore/` (shortcode path), Jetonomy `/community/`, WPMediaVerse `/media/` archive — all confirmed with consistent full-width layout, no BuddyX page chrome (no title/breadcrumb/sidebar); `mvs-page no-sidebar` body class verified live.

### Completed Tasks

- [x] BuddyX `header.php` — extended full-width check: `bn-page`, `jt-page`, `mvs-page`, `buddyx_is_full_width_page` filter
- [x] BuddyX `footer.php` — same extended check
- [x] BuddyX `community-full-width.php` page template created
- [x] `BuddyXBridge.php` created — `buddyx_is_full_width_page` hook for WPMediaVerse
- [x] `Plugin.php` — BuddyXBridge wired in bridges callback
- [x] `PageRouter::maybe_add_hub_body_class()` — `wp` action hook, adds body class for shortcode hub pages
- [x] `Jetonomy Template_Loader` — `jt-page` body class added before `get_header()`
- [x] `BuddyX page.php` — community page guard: skips title/breadcrumb/sidebar when `bn-page`/`jt-page`/`mvs-page` in body class
- [x] `PageRouter::maybe_add_hub_body_class()` — upgraded to dynamic `wp_load_alloptions()` scan; covers all current and future hub pages
- [x] `WPMediaVerse TemplateLoader::maybe_add_mvs_body_class()` — `wp` action hook; CPT pages via WP conditional tags, shortcode pages via `mvs_page_*` alloptions scan
- [x] Browser-verified: `/media/` archive = `mvs-page no-sidebar`; alloptions scan confirmed for dashboard page ID 38

---

### BLOCK DT — Unified Design Token Bridge (BuddyNext as Master)

**Spec:** BuddyNext owns the design token layer. Jetonomy's `--jt-*` and WPMediaVerse's `--mvs-*` tokens must reference BuddyNext's CSS custom properties first in their `var()` chains, with their own fallbacks preserved. Dark mode flows automatically via the CSS cascade — BuddyNext's `[data-theme="dark"]` overrides the underlying tokens (`--bg`, `--text-1`, etc.) that `--jt-*` and `--mvs-*` reference.

**Files:**
- `jetonomy/assets/css/jetonomy.css` — `:root, .jt-app` token block
- `wpmediaverse/assets/css/frontend.css` — `:root` token block + dark mode selector
- `jetonomy/CLAUDE.md` — Recent Changes
- `wpmediaverse/CLAUDE.md` — Recent Changes

**Architecture:**
- Jetonomy: `--jt-accent: var(--brand, var(--wp--preset--color--primary, #3B82F6))` — BuddyNext first, WP theme.json second, hardcode last
- WPMediaVerse: `--mvs-primary: var(--brand, #0073aa)` — BuddyNext first, hardcode fallback
- WPMediaVerse dark mode: `@media (prefers-color-scheme: dark) { :root:not([data-theme="dark"]) { ... } }` — OS dark mode only when BuddyNext is NOT controlling it
- Jetonomy `.jt-dark` selector unchanged — only applies when BuddyNext is absent; when BuddyNext is active, `[data-theme="dark"]` overrides the underlying tokens automatically

**Token mapping:**

| BuddyNext token | Jetonomy `--jt-*` | WPMediaVerse `--mvs-*` |
|---|---|---|
| `--brand` | `--jt-accent` | `--mvs-primary` |
| `--brand-hover` | — | `--mvs-primary-hover` |
| `--bg` | `--jt-bg` | `--mvs-bg` |
| `--bg-subtle` | — | `--mvs-surface` |
| `--bg-hover` | — | `--mvs-surface-2` |
| `--text-1` | `--jt-text` | `--mvs-text` |
| `--text-2` | — | `--mvs-text-secondary` |
| `--text-3` | — | `--mvs-text-muted` |
| `--border` | — | `--mvs-border` |
| `--border-soft` | — | `--mvs-border-light` |
| `--font-body` | `--jt-font` | — |
| `--font-display` | `--jt-font-heading` | — |
| `--green` | `--jt-success` | `--mvs-success` |
| `--green-bg` | `--jt-success-light` | — |
| `--amber` | `--jt-warn` | — |
| `--amber-bg` | `--jt-warn-light` | — |
| `--red` | `--jt-danger` | `--mvs-danger` |
| `--red-bg` | `--jt-danger-light` | — |
| `--r-sm` | `--jt-radius-sm` | `--mvs-radius-sm` |
| `--r-md` | `--jt-radius` | `--mvs-radius-md` |
| `--r-lg` | `--jt-radius-lg` | `--mvs-radius-lg` |
| `--r-full` | `--jt-radius-full` | `--mvs-radius-pill` |

- [x] Jetonomy `--jt-font` → `var(--font-body, var(--wp--preset--font-family--body, inherit))`
- [x] Jetonomy `--jt-font-heading` → `var(--font-display, var(--wp--preset--font-family--heading, inherit))`
- [x] Jetonomy `--jt-accent` → `var(--brand, var(--wp--preset--color--primary, #3B82F6))`
- [x] Jetonomy `--jt-text` → `var(--text-1, var(--wp--preset--color--contrast, #1a1a1a))`
- [x] Jetonomy `--jt-bg` → `var(--bg, var(--wp--preset--color--base, #ffffff))`
- [x] Jetonomy `--jt-radius` → `var(--r-md, var(--wp--custom--border-radius, 8px))`
- [x] Jetonomy `--jt-radius-sm/lg/full` → `var(--r-sm/lg/full, calc(...))`
- [x] Jetonomy `--jt-success/warn/danger` → `var(--green/amber/red, #hex)`
- [x] Jetonomy `--jt-success-light/warn-light/danger-light` → `var(--green-bg/amber-bg/red-bg, #hex)`
- [x] WPMediaVerse all `--mvs-*` tokens → BuddyNext-first `var()` chains
- [x] WPMediaVerse dark mode selector → `:root:not([data-theme="dark"])`
- [x] Jetonomy CLAUDE.md updated
- [x] WPMediaVerse CLAUDE.md updated

---

### BLOCK NAV — Unified Navigation System (BuddyNext as Master)

**Goal:** One navigation bar across all three plugins. BuddyNext renders the subnav, bridges inject plugin-specific items, each plugin works standalone without fatal errors.

**Architecture:**
- BuddyNext `shell/rail.php` is the primary nav surface — renders Activity, Explore, Members, Spaces, [bridge items], Notifications, Messages
- `buddynext_rail_items` filter allows bridges to inject items (Discussions, Media)
- Each bridge renders BuddyNext nav on its plugin's pages via `{plugin}_before_content` hook
- Each bridge suppresses the plugin's own nav when BuddyNext is active
- When BuddyNext is absent, each plugin renders its own standalone nav (or none)

**Nav bar order:**
Feed | Members | Spaces | Discussions (Jetonomy) | Media (WPMediaVerse) | Notifications | Messages | Dark toggle

**Files changed:**

| File | Plugin | Change |
|---|---|---|
| `includes/Bridges/WPMediaVerseBridge.php` | BuddyNext | Added `inject_media_nav_item()` + `render_buddynext_nav_on_mvs()` |
| `includes/Bridges/JetonomyBridge.php` | BuddyNext | Already done — `inject_discussions_nav_item()` + `render_buddynext_nav_on_jetonomy()` |
| `templates/explore.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `templates/dashboard.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `templates/media-single.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `templates/album.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `templates/collection.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `templates/profile-edit.php` | WPMediaVerse | Added `do_action('mvs_before_content')` |
| `includes/functions.php` | Jetonomy | Added `base_url()` helper — reads `base_slug` from settings |
| 25 template/include files | Jetonomy | Replaced hardcoded `/community` with `\Jetonomy\base_url()` |

**Standalone safety:**

| Scenario | Behavior |
|---|---|
| BuddyNext + Jetonomy + MVS | BuddyNext nav on all pages; Discussions + Media items injected |
| BuddyNext + Jetonomy | BuddyNext nav; Discussions injected; no Media item |
| BuddyNext + MVS | BuddyNext nav; Media injected; no Discussions item |
| BuddyNext alone | BuddyNext nav; no extra items |
| Jetonomy alone | Jetonomy's own nav bar (Community, Search, Leaderboard, Messages) |
| MVS alone | No subnav (media-focused pages, no community nav needed) |
| All plugins off | Each plugin's templates work independently |

- [x] WPMediaVerse `mvs_before_content` action hook added to all 6 templates
- [x] WPMediaVerseBridge: `inject_media_nav_item()` — SVG icon, archive URL, active via REQUEST_URI
- [x] WPMediaVerseBridge: `render_buddynext_nav_on_mvs()` — renders `partials/nav` on MVS pages
- [x] JetonomyBridge: active state already reads dynamic `base_slug` from settings
- [x] Jetonomy `base_url()` helper + 30 hardcoded `/community` references replaced
- [ ] Browser-verify: BuddyNext nav appears on MVS `/media/` page with Media item active
- [ ] Browser-verify: BuddyNext nav appears on Jetonomy `/discussion/` with Discussions item active

### Completed Tasks

---

## Completion Blocks — Audit Pass (2026-03-22)

Identified via deep code audit. All BLOCK 1–12 code exists but the following modules are half-cooked. Each block must pass all 5 gates before being marked done.

---

### BLOCK 13 — Cron Handlers (new `CronService` class)

**Spec:** `docs/specs/features/06-notifications-email.md`, `02-activity-feed.md`
**Files:** `includes/Core/CronService.php` (new), `includes/Core/CronScheduler.php`, `includes/Core/Plugin.php`
**Dependencies:** None — all required services already exist

7 cron jobs are scheduled by `CronScheduler` but have zero handler wiring. Every job fires and does nothing.

- [x] `buddynext_daily_digest` — query users with `email_freq = daily` from `bn_notification_prefs`, batch send digest email via `EmailSender`
- [x] `buddynext_weekly_digest` — same for `email_freq = weekly`
- [x] `buddynext_cleanup_tokens` — `DELETE FROM bn_verify_tokens WHERE expires_at < NOW()`
- [x] `buddynext_cleanup_notifications` — `DELETE FROM bn_notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)`
- [x] `buddynext_trending_hashtags` — recount `post_count` on `bn_hashtags` from `bn_post_hashtags` for last 7 days
- [x] `buddynext_recount_stats` — recount `reaction_count` + `comment_count` on `bn_posts` from child tables
- [x] `buddynext_publish_scheduled` — publish `bn_posts` with `status = scheduled` and `scheduled_at <= NOW()`
- [x] Wire all 7 via `add_action()` in `CronScheduler::init()` (or `CronService` registered in `Plugin.php`)
- [x] Add `every_five_minutes` schedule consolidation — `OutboundWebhookService` uses duplicate schedule; migrate to `buddynext_5min`
- [x] Add `buddynext_webhook_retry` to `CronScheduler::clear_events()` so it unschedules on deactivation

---

### BLOCK 14 — Onboarding Module Completion

**Spec:** `docs/specs/features/10-onboarding-setup-wizard.md`
**Files:** `includes/Onboarding/OnboardingService.php`, `includes/Onboarding/InviteService.php`, `includes/Core/CronScheduler.php`, `includes/Notifications/EventListener.php`, `includes/Admin/Members/InviteManager.php` (new), `includes/Core/Installer.php`
**Dependencies:** BLOCK 13 (CronService pattern) — can run parallel if CronScheduler wiring done separately

- [x] `OnboardingService::save_step()` steps 3+4 — step 3 calls `SpaceMemberService::join()` for each submitted space ID; step 4 calls `FollowService::follow()` for each submitted user ID
- [x] Nudge email cron: on `user_register`, schedule `wp_schedule_single_event(+24h, 'bn_onboarding_nudge_24h', [$user_id])` and `wp_schedule_single_event(+72h, 'bn_onboarding_nudge_72h', [$user_id])`
- [x] `bn_onboarding_nudge_24h` handler: check `OnboardingService::is_complete($user_id)` — if false, send `bn.onboarding_nudge` email
- [x] `bn_onboarding_nudge_72h` handler: same check, send final nudge email
- [x] Cancel both nudges on `buddynext_onboarding_completed`: `wp_clear_scheduled_hook('bn_onboarding_nudge_24h', [$user_id])`
- [x] `InviteService::create()` — after DB insert, call `EmailSender::send($email, 'bn.bulk_invite', ['token', 'first_name', 'invite_url'])`
- [x] `InviteService::resend(int $invite_id)` — regenerate token, update `expires_at`, resend email
- [x] `InviteService::send_invite_email(array $invite)` — private helper, builds invite URL from token + site URL
- [x] Seed `bn.bulk_invite` email template in `Installer::seed_email_templates()` and `EmailEditor` catalogue
- [x] `Admin/Members/InviteManager.php` (new) — tab on Members admin page: CSV upload form, pending invite table (email, status, expires_at, resend button), `admin_post_bn_bulk_invite` handler, `admin_post_bn_resend_invite` handler, nonce on every action

---

### BLOCK 15 — Moderation Module Completion

**Spec:** `docs/specs/features/09-moderation.md`
**Files:** `includes/Moderation/ModerationService.php`, `includes/REST/Controllers/ModerationController.php`, `includes/Notifications/EventListener.php`
**Dependencies:** None

- [x] Fix `buddynext_user_suspended` hook signature: standardize `suspend()` to fire `do_action('buddynext_user_suspended', $user_id, $actor_id, $reason, $expires_at)` matching `EventListener::on_user_suspended()` signature
- [x] `ModerationService::decide_appeal()` — add `do_action('buddynext_appeal_resolved', $appeal_id, $user_id, $decision)` after DB update; fetch `user_id` from `bn_appeals` within the method
- [x] Consolidate unsuspend to single REST path: remove `POST /users/{id}/unsuspend` route + callback from `ModerationController`; `DELETE /users/{id}/suspend` must call `ModerationService::unsuspend_user()` (audits lifted_at/lifted_by + fires hook)
- [x] Fix `EventListener::on_user_warned()` — change `'type' => 'user_warned'` to `'type' => 'bn.user_warned'`
- [x] Fix `EventListener::on_appeal_submitted()` — change `'type' => 'appeal_submitted'` to `'type' => 'bn.appeal_submitted'`
- [x] Wire 3 missing outbound webhook events in `EventListener::init()`: `buddynext_ability_granted` → `on_webhook_ability_granted()`, `buddynext_ability_revoked` → `on_webhook_ability_revoked()`, `buddynext_user_verified` → `on_webhook_member_verified()`

---

### BLOCK 16 — Asset Layer Completion

**Spec:** `docs/specs/features/11-gutenberg-blocks.md`, `20-theme-integration.md`
**Files:** `assets/css/` (6 new files), `assets/js/blocks.js` (new), `assets/css/blocks.css` (new)
**Dependencies:** None — pure file creation

All missing CSS/JS files registered by `AssetService` but absent on disk.

- [x] `assets/css/bn-base.css` — CSS custom property token declarations (`:root` + `[data-theme="dark"]` blocks from CLAUDE.md), reset/base rules; this is the dependency for all other BN CSS handles
- [x] `assets/css/bn-spaces.css` — space directory + space home component styles
- [x] `assets/css/bn-notifications.css` — notification list + bell dropdown styles
- [x] `assets/css/bn-search.css` — search results page styles
- [x] `assets/css/bn-gamification.css` — leaderboard + badge display styles
- [x] `assets/css/bn-moderation.css` — moderation queue + report card styles
- [x] `assets/js/blocks.js` — WordPress Interactivity API store for block editor preview interactions; referenced in all 17 `block.json` `"editorScript"` — without this file all blocks fail in the editor
- [x] `assets/css/blocks.css` — block frontend stylesheet referenced in all 17 `block.json` `"style"` — without this, block styles 404 sitewide
- [x] Each CSS file must have: design token vars used throughout, `@media (max-width: 640px)` mobile block, `[data-theme="dark"]` block

---

### BLOCK 17 — Code Quality + Minor Fixes

**Files:** `includes/Admin/NavManager.php`, `includes/Profile/MemberDirectoryService.php`, `includes/Outbound/OutboundWebhookService.php`
**Dependencies:** None

- [x] `NavManager.php:721` — write the `render_hub_page_assignments()` method body (page-picker for hub pages that have no dedicated tab — Members, Auth)
- [x] `MemberDirectoryService` — replace `0 AS mutual_connection_count` hardcoded stub with a real correlated subquery counting mutual accepted connections between the viewer and each result user
- [x] `OutboundWebhookService::init()` — remove inline `cron_schedules` filter (use `buddynext_5min` from `CronScheduler` instead of `every_five_minutes`)

---

### Dependency Order for Completion Blocks

```
BLOCK 13 (Cron Handlers) — independent, no deps
BLOCK 14 (Onboarding)    — touches EventListener; run after BLOCK 15
BLOCK 15 (Moderation)    — touches EventListener; run first among 14+15
BLOCK 16 (Assets)        — fully independent
BLOCK 17 (Code Quality)  — fully independent
```

**Execution order:** 13 → 15 → 14 → 16 + 17 parallel

---

## Environment

| Setting | Value |
|---------|-------|
| Local URL | http://forums.local |
| Auto-login | `?autologin=1` |
| WP-CLI path | `/Users/varundubey/Local Sites/forums/app/public` |
| Debug log | `wp-content/debug.log` |
| Plugin dir | `wp-content/plugins/buddynext/` |
