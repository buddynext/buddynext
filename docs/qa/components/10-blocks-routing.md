# QA — Gutenberg Blocks (18) & Frontend routing (free)

**Manifest refs:** tables: none own (blocks/router read existing domain tables + `wp_posts` for hub pages) · REST routes: blocks are server-rendered (`render.php` per block); router owns none — it dispatches templates · services: BlockRegistrar (17 in manifest + `bn-header-user-menu` added 2026-06-15 = **18 live**), PageRouter (hubs, rewrites, slug resolver, URL builders), ShortcodeService (7 hub shortcodes) · capabilities: per-surface (login-required hubs gate guests; admin hubs gate `manage_options`)
**Cross-ref (no dup):** JOURNEYS J-01 (anon landing / hub resolve) · J-02 (anon directory) · J-64..J-67 (shell/chrome regression — header, hub-shell, auth-shell, mobile bottom bar) · FLOW-TEST-MATRIX O-rows for admin nav · M-rows for member hub navigation
**Admin location:** Frontend/structural component. Page + slug assignment lives in Settings → Nav Manager (`buddynext_page_*` / `buddynext_slug_*`); router auto-flush sentinel is `buddynext_router_version`. Blocks insert via the WP block editor (block category: BuddyNext).

> **CONFIRMED LIVE VALUES (this site).** Router version `buddynext_router_version` = **`2026-06-14-pretty-profile-tabs`** (matches `PageRouter::ROUTER_VERSION` — no pending flush). 18 `block.json` files exist under `blocks/` (manifest lists 17; `bn-header-user-menu` was added 2026-06-15 and is not yet in the generated manifest — **manifest drift to flag**). Page + slug options confirmed below.

---

## 1. Backend settings & options (justify each)

This component's "settings" are the page-ID, slug, and router-version options that PageRouter and the block render paths depend on. All confirmed live via `wp option get`.

### Hub page-ID assignments (`buddynext_page_*`, set by wizard step 6 / PageSetup / Nav Manager)

| Option key | Tab / location | Confirmed live value | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_page_activity` | Nav Manager | `5` | WP page backing the Activity/Feed hub | Yes | — |
| `buddynext_page_people` | Nav Manager | `6` | WP page backing the People hub | Yes | — |
| `buddynext_page_spaces` | Nav Manager | `7` | WP page backing the Spaces hub | Yes | — |
| `buddynext_page_messages` | Nav Manager | `8` | WP page backing the DM hub | Yes | DM hub renders dependency notice without WPMediaVerse (component 08) |
| `buddynext_page_notifications` | Nav Manager | `9` | WP page backing the Notifications hub | Yes | — |
| `buddynext_page_auth` | Nav Manager | `10` | WP page backing the Auth hub | Yes | — |
| `buddynext_page_feed` | Nav Manager | `11` | Separate "feed" page (alias of activity) | Questionable | **Distinct from `buddynext_page_activity` (5).** Two different page IDs both claim the feed surface — confirm which one PageRouter actually resolves; risk of split/undefined behavior (also flagged in component 11) |
| `buddynext_page_members` | Nav Manager | `12` | WP page for member directory | Yes | Overlaps the `people` hub (6) — confirm members-vs-people page roles don't collide |
| `buddynext_page_profile` | Nav Manager | `13` | WP page for member profile | Yes | — |

### Hub slug assignments (`buddynext_slug_*`)

| Option key | Tab / location | Confirmed live value | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_slug_activity` | Nav Manager | `activity` | URL slug for activity hub (`/activity/`) | Yes | — |
| `buddynext_slug_people` | Nav Manager | `members` | URL slug for People hub (`/members/`) | Yes | Slug `members` while hub is internally `people` — naming mismatch is intentional but worth a doc note |
| `buddynext_slug_spaces` | Nav Manager | `spaces` | URL slug for Spaces hub (`/spaces/`) | Yes | — |
| `buddynext_slug_messages` | Nav Manager | `messages` | URL slug for DM hub (`/messages/`) | Yes | — |
| `buddynext_slug_notifications` | Nav Manager | `notifications` | URL slug for notifications hub | Yes | — |
| `buddynext_slug_auth` | Nav Manager | `login` | URL slug for auth hub (`/login/`) | Yes | — |

> Slugs for `onboarding`, `settings`, `moderation`, `post` use code defaults (`buddynext_slug_onboarding` default `onboarding`, etc.) and are not overridden on this site — **(verify)** whether they have stored option rows or fall through to defaults.

### Router version sentinel

| Option key | Tab / location | Confirmed live value | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_router_version` | (no UI — written by PageRouter) | `2026-06-14-pretty-profile-tabs` | Auto-flush sentinel: when it differs from `ROUTER_VERSION`, `maybe_flush_rewrites()` re-registers rules + flushes on next `init` | Yes | Matches code constant → no pending flush. If a hub/rewrite changes without bumping `ROUTER_VERSION`, new rules silently 404 until a manual `wp rewrite flush` |

---

## 2. Frontend functions (function-by-function)

### Blocks (18 server-rendered — confirmed via `find blocks/ -name block.json`)

| Function (block) | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `buddynext/activity-feed` | any page | server-render → feed store | Renders feed; reuses `partials/post-card.php`; WP Interactivity API store `buddynext/feed` |
| `buddynext/connection-button` | profile / member card | server-render | All 5 connection states (null/pending-sent/pending-received/accepted/blocked); store `buddynext/connection-button` |
| `buddynext/follow-button` | profile / member card | server-render | Block guard, reactive count; store `buddynext/follow-button` |
| `buddynext/header-user-menu` | site header (logged in) | server-render, **zero-JS** | **NEW 2026-06-15** — notification bell + messages icon + avatar with CSS-only (`:focus-within`) dropdown; links filterable via `buddynext_header_user_menu_links`; not in manifest's 17 (drift) |
| `buddynext/login-form` | any page | server-render | Login form; POST to WP auth; field `user_pass` |
| `buddynext/member-card` | any page | server-render | Single member card with follow/connect actions |
| `buddynext/member-directory` | any page | server-render | Member grid/list; respects suspended/shadow-ban exclusion |
| `buddynext/my-spaces` | any page (logged in) | server-render | Current user's joined spaces |
| `buddynext/notification-bell` | header / any page | server-render | Unread count badge; reads `recipient_id` unread query |
| `buddynext/post-composer` | any page (logged in) | server-render → feed store | Composer; store `buddynext/post-composer` (submit, privacy, onInput) |
| `buddynext/profile-completion-bar` | profile | server-render | Profile completeness % bar |
| `buddynext/profile-fields` | profile | server-render | Renders profile field groups |
| `buddynext/profile-header` | profile | server-render | Header with `showStats` + `showActions` boolean attrs (default true) |
| `buddynext/registration-form` | any page | server-render | Signup form; respects reg mode |
| `buddynext/search-bar` | any page | server-render | Search input → `/activity/search/` or results route |
| `buddynext/space-card` | any page | server-render | Single space card with join state |
| `buddynext/space-directory` | any page | server-render | Space grid; `type='open'` visibility filter |
| `buddynext/trending-hashtags` | sidebar / any page | server-render | 24h trending hashtags list |

### Routing (PageRouter — hub + endpoint model, no backing-page content needed; virtual via `$wp_query->is_page = true` + `status_header(200)`)

| Function | Surface / route | Resolves to | Expected behaviour |
|---|---|---|---|
| Activity hub | `/activity/`, `/activity/explore/`, `/activity/hashtag/{tag}/`, `/activity/search/`, `/activity/leaderboard/` | `feed/home.php`, `feed/explore.php`, hashtag feed, search, leaderboard | Feed surfaces; conditionally enqueues `hashtags`/`search`/`gamification` bundles |
| Single post | `/p/{id}/` | `post` hub | Single post view; `feed` bundle |
| Bookmarks | `/me/bookmarks/` | feed bookmarks | Login-required |
| People / profile | `/members/{slug}/{action}/`, `/members/{slug}/`, `/members/{type-slug}/` (bottom priority) | `profile/view.php` (deep-links tab via `bn_profile_action`) / `profile/edit.php` (edit action) / `directory/members.php` | Slug → user_id via `bn_profile_slug` usermeta → `user-{id}` → `user_nicename`; member-type pill via bottom-priority rule |
| Spaces | `/spaces/`, `/spaces/{slug}/`, `/spaces/{slug}/{members,settings,moderation,admin}/` | `spaces/directory.php` / space home / sub-routes | Slug → space_id via `bn_spaces.slug` lookup; conditionally enqueues `moderation` |
| Messages | `/messages/`, `/messages/{id}/`, `/messages/requests/` | `messages/list.php`/`thread.php`/`requests.php` → `native.php` | Login-required (guests → `auth_url()`); dependency notice without WPMediaVerse (component 08) |
| Notifications | `/notifications/`, `/notifications/preferences/`, `/settings/notifications/` | notifications index / prefs | Login-required; conditionally enqueues `notification-prefs` + `bn-settings` CSS |
| Settings | `/settings/`, `/settings/{account,privacy,appearance,notifications}/` | settings tab (default `account`, validated against allowlist) | Login-required |
| Auth | `/login/` (+ signup/verify slugs) | `auth` hub via `auth-shell.php` | Logged-in users redirected away from login/signup (not verify); signup → login if `users_can_register` off |
| Onboarding | `/onboarding/` | `onboarding/index.php` via `auth-shell.php` | Login-required; if `bn_onboarding_complete` set & no `?redo=1` → redirect to activity (component 09) |
| Shell wrapping | every hub | `shell/hub-shell.php` (most) / `shell/auth-shell.php` (auth + onboarding) | `render_shell_with_theme_chrome()` = `get_header()` + template + `get_footer()`; htmx partial swap returns content-only when `HTTP_HX_REQUEST` header present |
| Slug-change flush | `update_option_buddynext_slug_{hub}` | `flush_on_slug_change()` (7 hubs) | Changing a slug option re-registers + flushes rewrites so the new URL resolves and old 404s |
| URL builders (static) | code | `activity_url()`, `profile_url()`, `space_url()`, `messages_url()`, `notifications_url()`, `settings_url()`, `auth_url()`, `onboarding_url()`, `member_type_url()`, etc. | Canonical URL construction — templates/blocks must use these, never hardcode paths |
| Shortcodes (7) | any page | `buddynext_activity`, `buddynext_people`, `buddynext_spaces`, `buddynext_messages`, `buddynext_notifications`, `buddynext_auth`, `buddynext_community_admin` | Hub shortcodes route via query vars to the same templates |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-BLK-001 | admin | backend | Block editor open on a page | Insert each of the 18 BuddyNext blocks one at a time | Every block renders a server-side preview (or static placeholder fallback) with no React Error #130; editorScript handle `buddynext-blocks-editor` resolves; no console errors | 1440px |
| QA-BLK-002 | member | frontend | Page with `buddynext/activity-feed` block published | View page as a logged-in member | Feed renders via `post-card` partial; Interactivity store `buddynext/feed` active (reaction/bookmark work); no JS errors | 1440px, 390px |
| QA-BLK-003 | member | frontend | Page with `buddynext/follow-button` + `buddynext/connection-button` | View; click follow | Follow count reactive; connection button shows correct state; store namespaces `buddynext/follow-button` / `buddynext/connection-button` (not legacy `buddynext/follow`) | 1440px |
| QA-BLK-004 | logged-in | frontend | `buddynext/header-user-menu` block (or `[buddynext_user_menu]`) in header | Hover/focus the avatar | CSS-only `:focus-within` dropdown opens (no JS); shows quick links + Log Out; bell + messages icons present; works keyboard-only | 1440px, 390px |
| QA-BLK-005 | admin | backend | `buddynext/profile-header` block, `showStats=false` | Set showStats false, showActions false; view | Stats + actions sections suppressed per attribute guards in `templates/blocks/profile-header.php` | 1440px |
| QA-BLK-006 | guest | frontend | `buddynext/member-directory` block on a public page | View as guest | Directory renders public members; suspended + shadow-banned users excluded; no fatal | 1440px, 390px |
| QA-BLK-007 | member | frontend | Default install | Navigate `/activity/` | Activity hub resolves via PageRouter (page ID 5, slug `activity`); `$wp_query->is_page` true; 200 status; feed + composer render; J-01 | 1440px, 390px |
| QA-BLK-008 | member | frontend | Member with `bn_profile_slug` set | Navigate `/members/{slug}/` then `/members/{slug}/connections/` | Slug resolves to user_id; profile view renders; `/connections/` deep-links the connections tab via `bn_profile_action` | 1440px, 390px |
| QA-BLK-009 | member | frontend | Default | Navigate `/spaces/` then `/spaces/{slug}/` | Directory then space home resolve; space slug → `bn_spaces.slug` lookup; no 404 | 1440px, 390px |
| QA-BLK-010 | guest | frontend | Logged out | Navigate `/messages/`, `/notifications/`, `/onboarding/`, `/me/bookmarks/` | Each login-required hub redirects guest to `/login/` (`auth_url()`) before template loads (J-64/J-65 chrome) | 1440px, 390px |
| QA-BLK-011 | logged-in | frontend | Logged in | Navigate `/login/` | Redirected away from auth hub to the feed (logged-in users skip login/signup, but not verify) | 1440px |
| QA-BLK-012 | admin | backend | Nav Manager | Change `buddynext_slug_people` from `members` to `community`; Save | `update_option_buddynext_slug_people` fires `flush_on_slug_change()`; `/community/{slug}/` resolves; old `/members/` 404s | 1440px |
| QA-BLK-013 | admin | backend | Router version matches code | `wp option get buddynext_router_version` | Returns `2026-06-14-pretty-profile-tabs` = `ROUTER_VERSION` → no pending flush; all rewrite rules live (`wp rewrite list \| grep bn_`) | n/a |
| QA-BLK-014 | member | frontend | Page with `[buddynext_spaces]` shortcode | View page | Shortcode routes via query vars to the spaces directory template — same output as `/spaces/`; no double-chrome | 1440px, 390px |
| QA-BLK-015 | member | frontend | Member-type seeded | Navigate `/members/{type-slug}/` | Bottom-priority member-type rule resolves; directory filtered to that type; pill links built via `member_type_url()` | 1440px |
| QA-BLK-016 | member | frontend | htmx-capable client | Request `/activity/` with `HX-Request: true` header | Returns template content only (no `get_header()`/`get_footer()` chrome) — partial swap path | n/a |
| QA-BLK-017 | member | frontend | Default | Navigate `/p/{id}/` for a valid post id | Single post hub resolves; `feed` bundle enqueued; post renders; invalid id → graceful 404/empty state | 1440px, 390px |
| QA-BLK-018 | member | frontend | All hubs | Walk feed → profile → spaces → messages → notifications → settings | `hub-shell.php` wraps non-auth hubs, `auth-shell.php` wraps auth + onboarding; header/footer chrome consistent; mobile bottom bar present at 390px (J-66/J-67) | 1440px, 390px |
| QA-BLK-019 | admin | backend | `buddynext_page_feed` (11) ≠ `buddynext_page_activity` (5) | Visit the feed surface and confirm which page backs it | Document actual resolution; if behavior splits between the two IDs, flag as a routing bug (see §4) | 1440px |
| QA-BLK-020 | guest | frontend | All 18 blocks placed on a public page | Load the page as a guest | Logged-out-appropriate output for each block (login/registration forms show; member-only blocks render empty/CTA state, never a fatal) | 1440px, 390px |

---

## 4. Site-owner expectations & suggestions

- **Manifest is stale — 18 blocks live, manifest lists 17.** `bn-header-user-menu` (added 2026-06-15) is on disk but absent from `audit/manifest.json` (generated 2026-06-07). Any QA/coverage tool keyed off the manifest count under-tests by one block. Refresh the manifest (`/wp-plugin-onboard --refresh`) so blocks = 18. Priority: high (contract integrity — inventory drift).

- **`buddynext_page_feed` (11) and `buddynext_page_activity` (5) are separate pages claiming the same surface.** With two distinct page IDs both representing the feed, resolution is ambiguous and a slug/page edit to one won't affect the other. Unify them (deprecate `buddynext_page_feed`, or make one an explicit alias) and add a Nav Manager note. Priority: high.

- **No admin-visible rewrite-health indicator.** The router auto-flushes only when `ROUTER_VERSION` is bumped. If a developer adds a rewrite without bumping the constant, the owner gets silent 404s with no clue why. Surface a "Routes healthy / flush needed" status (compare stored `buddynext_router_version` vs code) on the BuddyNext dashboard with a one-click flush. Priority: medium.

- **Slug `members` for an internally-named `people` hub, plus a separate `members` page (12) vs `people` page (6), is confusing.** Three near-synonymous concepts (people hub, members slug, members page) invite misconfiguration. Document the canonical mapping in Nav Manager and validate against collisions. Priority: medium.

- **Blocks have no per-block "requires login / requires WPMediaVerse" notice in the editor.** An owner placing `buddynext/my-spaces` or a messages-related block on a guest page sees an empty render with no explanation. Add an editor-side InspectorControls hint for member-only / dependency-gated blocks. Priority: low.

- **Hidden hubs (`settings`, `moderation`) and unoverridden slugs (`onboarding`/`post`) aren't surfaced in Nav Manager.** Owners can't rename `/settings/` or `/onboarding/` from the UI even though the slug options exist. Expose them (or document that they're code-default-only). Priority: low.

- **No guest-facing empty/CTA contract documented per block.** QA-BLK-020 has to discover each block's logged-out behavior by inspection. Define and document the expected guest state for all 18 blocks (login CTA vs empty vs hidden) so it's testable, not guessed. Priority: low.
