# BuddyNext Changelog

## Unreleased

### Feed (true production)

Live-walk findings on `buddynext-dev.local` as `varundubey` exposed three
truly missing Feed surfaces — no single-post permalink, no infinite scroll,
no bookmarks hub. Pinning down each:

- **A1 — Single-post permalink page (`/p/{id}/`).** New
  `templates/feed/single-post.php` renders a dedicated detail surface
  (breadcrumb, full post card, expanded comment region). Server-side
  visibility gates mirror `PostController::get_post()` — blocks, secret-
  space membership, followers-only, private, and suspended/shadow-banned
  authors all 404 silently so existence is never leaked. New
  `includes/Feed/SinglePostMeta.php` emits OG/Twitter/canonical head meta
  tags + a richer `<title>`; private/restricted posts also get a
  `noindex,nofollow` robots tag. `PageRouter::post_url()` is the canonical
  helper — every post-card timestamp now wraps in a permalink anchor, and
  the share modal's "Copy link" copies the new short URL form.
- **A2 — Infinite scroll (home + explore).** Replaced the prior
  `window.location` redirect hack with a proper IntersectionObserver
  sentinel that calls two new server-rendered HTML endpoints
  (`/buddynext/v1/feed/home/page`, `/buddynext/v1/feed/explore/page`).
  `FeedController::render_items_html()` re-uses the canonical
  `partials/post-card.php` so appended cards are byte-identical to
  first-paint. The DOMParser-based append path is inert (no script
  execution per HTML5 spec) and keeps WPCS escape-on-output guarantees.
  When the API returns `next_cursor: null` the sentinel swaps for a
  quiet "You've reached the end" marker; fetch errors swap in an inline
  Retry button.
- **A3 — Bookmarks hub (`/me/bookmarks/`).** New
  `templates/feed/bookmarks.php` lists saved posts with full cards and
  cursor pagination. The same five visibility gates are re-applied at
  read time so unfollowing an author or losing space membership
  immediately hides their bookmarked post. The Bookmarks entry now sits
  in the left-rail "You" group. `GET /buddynext/v1/me/bookmarks` is
  backward compatible (default still returns `{ids: int[]}`) — pass
  `?expand=posts` for the new hydrated paginated shape.

Tests added: `tests/Core/PageRouterTest.php` (post + bookmarks helpers
+ rewrite rules), `tests/Feed/SinglePostMetaTest.php`,
`tests/Feed/InfiniteScrollPageTest.php`,
`tests/Feed/BookmarkControllerExpandTest.php`.

Defer / fixme (tracked in `docs/qa/PRODUCTION-READINESS.md`):

- **A4 — Comment threading UI (Reply / Like / Edit / Pin / Report).** The
  CommentService already supports `parent_id`, `is_edited`, soft-delete,
  and pin; CommentController accepts `parent_id`. What's missing is the
  per-comment UI scaffold (comment-tree + comment-card partials, store
  actions). Branch is a contained next step — wire-up is ~1 day of work,
  no schema changes.
- **B — Composer drafts.** Local-storage debounced auto-save planned;
  the composer state object is already JSON-serialisable so the seam is
  minimal. Server-sync (`POST /me/drafts`) defers to a Pro/Phase 2 branch.

### Profile (true production)

Findings from the live walk on `buddynext-dev.local` as `varundubey`
closed the gaps that kept the Profile surfaces from clearing the bar
their production-readiness row claimed.

- **A1 — Owner-gate leak closed.** `templates/profile/view.php` and
  `templates/partials/profile-actions.php` now strictly gate the owner
  action bar (Edit Profile / Edit Avatar / Edit Cover) behind
  `$is_own_profile`. Previously `current_user_can('edit_users')` also
  unlocked the bar, but the buttons all point at the viewer's own
  `/edit/` page via `get_edit_profile_url()`, so an admin viewing
  another member saw stale UI linking back to their own edit page.
  Admins continue to edit other users via the WP admin toolbar.

- **A2 — Connection Accept/Decline reactive hide.** Each button in
  `templates/partials/connection-button.php` now carries its own
  `data-wp-bind--hidden="!state.showAcceptDecline"` plus the initial
  hidden attribute seeded from `$pending_recv`, so the buttons hide
  consistently on initial render and on state flips.

- **B1 + B2 — `/members/{slug}/followers/` and `/following/`.** New
  templates `templates/profile/followers.php` and
  `templates/profile/following.php` render paginated member cards
  (24/page) driven by `FollowService::followers()` /
  `FollowService::following()` with bidirectional block filtering.
  PageRouter:
  - new rewrite rules for both segments,
  - new template-dispatch cases under `case 'people'`,
  - new URL builders `followers_url()` and `following_url()`,
  - shared `profile_subroute_url()` helper consolidating the
    `bn_profile_slug → user_nicename → user-{ID}` fallback chain,
  - new `ROUTER_VERSION` sentinel + `maybe_flush_rewrites()` hook
    auto-flushes rules on deploys that change the rule set,
  - document titles "Followers · {name}" and "Following · {name}".

- **B3 — Stat cards link to their list pages.** The four hero stat
  cells (Posts / Followers / Following / Connections) are now real
  navigation. Posts is a button that fires `actions.setTab('posts')`;
  Followers / Following / Connections are anchors to their list URLs.
  CSS adds `.bn-pf-stat--link` with v2-token hover + focus-visible.

- **B4 — Tab URL sync.** `setTab` now pushes `?tab={slug}` into the
  URL via `history.pushState`. New `callbacks.initView` reads `?tab=`
  on load and registers a single popstate listener so the browser
  Back button reverses tab changes. The pre-existing broken
  `.bn-ptab` selector was replaced with the real `.bn-tab` class so
  `aria-selected` and `.active` actually toggle.

- **B5 — Privacy section.** New "Privacy" card between Community
  Interests and Notification preferences with three audience selects
  (`bn_privacy_see_email`, `bn_privacy_dm`, `bn_privacy_mention` —
  enum `everyone | members | connections | nobody`) plus three
  toggles (`bn_privacy_show_in_directory`,
  `bn_privacy_search_indexable`, and the Pro-mirrored
  `bn_pro_hide_profile_views`). `ProfileController::update_profile()`
  was extended to accept and persist these keys; the audience enums
  return a 422 with field-keyed errors on invalid values.

- **B5 — `actions.togglePref` shipped.** Previously every toggle in
  the edit page referenced an unimplemented action — silent noop.
  The profile store now implements an optimistic toggle that PUTs the
  single key to `/me/profile`, rolls back `aria-checked` on failure,
  and toasts the result.

- **B6 — Account section beef-up.**
  - Inline **change-email** flow → new `POST /auth/change-email`
    sanitizes + validates the address, stores it in `bn_pending_email`
    usermeta, and fires `buddynext_email_change_requested` so the
    VerificationListener can send the confirm-then-swap email.
  - Inline **change-password** flow with a 5-step strength meter →
    new `POST /auth/change-password` validates via
    `wp_check_password()`, blocks same-password-as-current, enforces
    an 8-character minimum, calls `wp_set_password()`, then
    `wp_set_auth_cookie()` so the current session survives.
  - **Sign out everywhere** → new `POST /auth/sign-out-everywhere`
    calls `WP_Session_Tokens::get_instance($user_id)->destroy_all()`
    and re-issues a cookie for the current device.
  - Notification email schedule cross-link → `notification_prefs_url`.

- **C1 — Notification preferences card footer.** The Notification
  preferences card now carries a footer with a brief description and
  a primary CTA "Open notification preferences" pointing at
  `notification_prefs_url`.

- **C2 — Share profile popover.** New Share button next to
  Follow / Connect / Message opens a popover with two actions: Copy
  link (uses `navigator.clipboard` with a textarea fallback for older
  browsers) and Share to feed (anchor that prefills the composer with
  a mention). Share + More menus are mutually exclusive.

- **C3 — Mobile responsive at 390px.** New `@media (max-width: 400px)`
  rule stacks the action row vertically, locks buttons to 100% width,
  pins the stats grid to 2x2, and tightens the timeline gap.

- **Tests (D).**
  - `tests/Profile/FollowersControllerTest.php` (new) — 200, shape,
    block filtering, anonymous viewer.
  - `tests/Profile/FollowingControllerTest.php` (new) — 200, shape,
    block filtering, empty list.
  - `tests/Profile/ProfileControllerPrivacyTest.php` (new) — happy
    path persistence, 422 on invalid audience enum, boolean toggles
    persist as `'1'` / `'0'`, notification pref keys persist.
  - `tests/Auth/AuthControllerPasswordTest.php` (new) — happy path,
    wrong current password 422, same-password 422, short password
    422, 401 anonymous.
  - `tests/e2e/profile/followers-following.spec.ts` (new) — desktop
    + mobile renders + 404 regression guard + stat card links.
  - `tests/e2e/profile/owner-gate.spec.ts` (new) — asserts no
    Edit Profile / Avatar / Cover bar on non-owner profiles, and
    confirms the bar still renders on own profile.
  - `tests/e2e/profile/edit.spec.ts` extended with three checks for
    the Privacy section, Account section, and the prefs CTA footer.

### Notification preferences UI (production)

- **New route** - `/settings/notifications/` is now a real route handled by `PageRouter`. A second alias `/notifications/preferences/` resolves to the same template. Both use the `notifications` hub with a new `bn_notif_section=prefs` query var; rewrite rules added in `PageRouter::register_notifications_rules()`.
- **Template** `templates/notifications/prefs.php` - v2 chrome with four sections:
  - **Channels** master toggles (in-app / email / push). Push row hidden unless `BuddyNextPro\Push\PushDispatcher` is loaded.
  - **Activity types** accordion grouped by `NotificationPrefCatalogue::grouped()` (Social graph, Feed activity, Spaces, Messages, Moderation, Growth and digests). Each row carries an in-app checkbox + an email-frequency chip-select (Immediate / Daily / Weekly / Off); types with `can_email=false` render an "In-app only" caption.
  - **Spaces you are in** - chip-select per joined space (`All activity` / `Mentions only` / `None`) saved instantly via `POST /me/space-notification-prefs` with optimistic UI + rollback.
  - **Quiet hours** - coming-soon placeholder with disabled time inputs and the user's WP timezone.
  - Reset to defaults confirmation modal stages every type back to catalogue defaults; commit requires Save.
- **Store** `assets/js/notifications/prefs-store.js` (new) - namespace `buddynext/notification-prefs`. Optimistic `setOnSite`, `setEmailFreq`, `setSpacePref`, `setChannel`, `saveAll` with diff-on-save + rollback to initial snapshot on REST 4xx/5xx. `bnToast` on every success / failure. Sticky save bar (mirrors Profile edit) with dirty / saving / saved labels + beforeunload guard.
- **REST surface** `includes/Notifications/NotificationController.php`:
  - `GET /me/notification-prefs` now merges catalogue defaults with stored rows so the response always carries one entry per type.
  - `PUT /me/notification-prefs` validates each entry's `email_freq` against `{immediate, daily, weekly, off}` and returns 422 with `params` on the first failure.
  - `GET + PUT /me/notification-channels` reads / writes the `bn_channel_prefs` usermeta map.
  - `GET /me/space-notification-prefs` lists joined spaces + each space's stored `notification_pref`.
  - `POST /me/space-notification-prefs` updates one space (logged-in + active-member gate; 422 on invalid pref value).
- **Catalogue service** `includes/Notifications/NotificationPrefCatalogue.php` (new) - single source of truth for the per-type metadata the prefs UI consumes (`slug`, `label`, `description`, `group`, `default_on_site`, `default_email_freq`, `can_email`). Filter `buddynext_notification_prefs_catalogue` lets Pro / bridge plugins register types. Wired into the container as `notification_pref_catalogue`.
- **Asset registration** - `bn-notification-prefs` stylesheet and `@buddynext/notification-prefs` script module registered in `AssetService`; `PageRouter::enqueue_hub_assets()` enqueues both when `bn_notif_section=prefs`.
- **Settings link** added to the `/notifications/` page header (next to "Mark all read") + the sidebar "Notification preferences" link now points at the new URL.
- **Tests**:
  - `tests/Notifications/NotificationPrefControllerTest.php` - GET returns one row per catalogue type with defaults, PUT persists partial maps, PUT returns 422 on invalid `email_freq`, GET 401 anonymous, channels GET + PUT happy path.
  - `tests/Notifications/NotificationPrefCatalogueTest.php` - every `bn.*` type handled by `NotificationMessageService::compose_single()` (excluding the dev-only `bn.test` and the `bn.space_join_approved` alias of `bn.space_request_approved`) has a catalogue row; grouped() returns the six known groups; resolve_for_user() overlays stored values; filter can add a type.

### Spaces (production)

- **Spaces directory (`/spaces/`)** — Apply submit dropped; filter bar is reactive (250 ms debounced live search + instant category select + type chip switch + sort chip-button popover). New Secret type chip (visible to viewer-owned secret memberships only). Skeleton grid while loading; recoverable error block with retry; empty state copy now reads "No spaces match - try widening" and ships a Reset filters CTA. Create-space CTA opens an inline modal (`templates/partials/create-space-modal.php`) with name + auto-derived slug + type (Open/Private/Secret) + category + description; submits to `POST /buddynext/v1/spaces`, surfaces field-level 422 errors, redirects to the new space on 201. REST list endpoint cleaned up: `?type=open|private|secret`, `?category_id=`, `?orderby=popular|active|newest|alphabetical`, `?q=`/`?search=`, `?per_page=` capped at 50.
- **Space home (`/spaces/{slug}/`)** — Hero ships cover (image or gradient fallback), name, type badge, member count, owner+mod avatars in the side widget. Action row now covers every join state plus a Notification-pref chip (bell) opening a popover with All / Mentions only / None (optimistic + rollback on failure), an Invite button for owners/moderators, and a Settings link for owners. Moderation tab visible only to owners/moderators. Members tab renders the full active-member grid with avatar + name + role chip + Follow chip. About tab passes the description through `wp_kses_post( wpautop() )` so admins can use safe markdown-ish formatting. Feed scoped to the space via the existing `FeedService` query.
- **Space settings (`/spaces/{slug}/settings/`)** — Section tabs rebuilt as General / Permissions / Members / Branding / Moderation / Integrations / Notifications / Danger zone. Permissions tab carries who-can-post (members/mods/owner), who-can-invite, require-join-approval, require-post-approval, and allow-member-posts toggles. Members list ships role chips and per-row Promote / Demote / Remove / Ban with optimistic UI + `bnToast` on success and rollback on failure. Branding tab shows a soft Pro upsell card; Pro P6.2 already renders the real per-space accent hue control via `buddynext_space_branding_settings`. Danger zone now ships Transfer ownership (active-member picker -> `POST /spaces/{id}/transfer`, demotes current owner, promotes target, updates `bn_spaces.owner_id` with cache invalidation) plus Delete space behind a two-step gated modal (`templates/partials/space-delete-confirm-modal.php`) that requires the user to type the exact space name; the DELETE call carries an `X-BN-Confirm-Space-Name` header the controller re-verifies (422 on mismatch).
- REST surface additions (`includes/Spaces/SpaceController.php`):
  - `GET /spaces` accepts `?type=`, `?category_id=`, `?orderby=` (popular / active / newest / alphabetical aliases), `?q=`, `?per_page=` capped at 50.
  - `POST /spaces` validates name (1..100), slug, type enum, description (..160) and returns 422 with `params` on validation failure; slug_taken is surfaced as 422 against the slug field.
  - `GET` + `POST /spaces/{id}/notification-pref` for per-user preferences.
  - `PUT /spaces/{id}/permissions` for the new Permissions tab.
  - `POST /spaces/{id}/transfer` aliases the existing transfer-ownership endpoint.
  - `DELETE /spaces/{id}` honors the `X-BN-Confirm-Space-Name` header.
- Service additions (`includes/Spaces/SpaceMemberService.php`):
  - `set_notification_pref()` + `get_notification_pref()` write/read `bn_space_members.notification_pref` with cache invalidation.
  - `cancel_request()` removes a pending join row and fires `buddynext_space_join_request_cancelled`.
- Store additions (`assets/js/spaces/store.js`):
  - Directory: `applyFilter`, `setType`, `setSort`, `toggleSortPopover`, `resetFilters`, `openCreate`, `closeCreate`, `submitCreate`.
  - Space home: `setNotificationPref` + `toggleNotifPopover` (optimistic + rollback + outside-click close), `setTab`, `openInviteModal`.
  - Settings: `setMemberRole`, `kickMember`, `banMember`, `transferOwnership` + `openTransferOwnershipModal`, `openDeleteSpaceConfirm` + `deleteSpaceConfirmed` (name gate), `saveGeneral`, `savePermissions`.
- New tests:
  - `tests/Spaces/SpaceControllerTest.php` - 10 additional cases covering 422 on invalid type, 422 on duplicate slug, type enum filter, per_page cap, sort alias, notification-pref happy + invalid, role transition, transfer, delete-with-matching-header, delete-with-mismatched-header, permissions guard.
  - `tests/Spaces/SpaceMemberServiceTest.php` - 5 additional cases covering notification-pref happy + invalid + non-member + cancel-request cache busting + change-role cache invalidation.

### Social Graph (production)

- **Social Graph (production)** — Member directory rebuilt as a reactive store with debounced live search, member-type pill row, instant sort + relation tab switching, loading skeleton, recoverable error state, and empty state with a Reset filters CTA. Follow / Connect chips on every card use optimistic UI with rollback + toast on REST 4xx/5xx. Per-card kebab menu surfaces Mute, Block, and Report through the same modal partials the profile view uses. Page title renders as `Members · {site name}` via the `document_title_parts` filter.
- `templates/directory/members.php` drops the Apply submit, adds `.bn-md-pill-row` fed by `MemberTypeService`, wires the search input to a 250 ms debounced `actions.handleSearchInput`, renders reactive skeleton / error / empty / grid blocks bound to `state.loading` / `state.hasError` / `state.showEmpty` / `state.gridHidden`, and adds the per-card overflow menu (Mute / Block / Report). Cross-surface block and report modal markup is rendered outside the Interactivity root so the directory store opens them imperatively via `[hidden]` toggles + bound DOM handlers.
- `assets/js/members/store.js` rewritten end-to-end: reactive filter state (search / sort / relation / member type), `refresh( ctx )` REST round-trip with skeleton + error states, browser URL kept in sync via `history.replaceState`, per-card optimistic toggleFollow / toggleConnection / acceptConnection / declineConnection / toggleMute with full rollback + `bnToast` success/failure copy, openBlock + openReport routing into the shared modals, kebab outside-click auto-close.
- New REST endpoint `GET /buddynext/v1/members` (see `includes/Profile/MemberDirectoryController.php`) returns a shaped payload per row: display_name, handle, avatar_url, bio_excerpt, profile_url, messages_url, member_type label, follow + connection state, mutual_count, is_online. Validates sort / relation enums, supports cursor pagination via the existing `MemberDirectoryService::list_members()`, and respects suspend / shadow-ban / bidirectional block filtering.
- `templates/partials/follow-button.php` upgraded to a 5-state reactive button (`unfollowed` / `following` / `pending` / `blocked` / `self`) driven by `data-wp-bind--data-state`, `data-wp-bind--class`, and `data-wp-bind--aria-pressed`. `blocked` + `self` short-circuit in PHP so the bound element never renders. New `assets/js/social/follow-store.js` ships the production toggle: optimistic flip + REST round-trip + toast on success/failure + rollback on 4xx/5xx + sidebar widget cache invalidated server-side by the existing WidgetListener.
- `templates/partials/connection-button.php` and the same `social/follow-store.js` now surface all five connection states (`none` / `pending-sent` / `pending-received` / `accepted` / `blocked`) with the right CTA per state. Each action runs optimistically with rollback + toast on failure.
- Feed surface: comment menu gains a Report button (visible to non-owners), wired to the same `/reports` endpoint with a `bnPrompt` modal + success / failure toast. Post-card Report flow now emits a success toast on submit and a danger toast on failure.
- `assets/css/bn-members.css` adds `.bn-md-pill-row`, skeleton, error, empty-action, and per-card kebab menu styles. All tokens come from `--bn-*`.
- `includes/Core/AssetService.php` registers a new `@buddynext/social-buttons` script module (powered by `assets/js/social/follow-store.js`) declared as a shell-dialog consumer; `PageRouter::enqueue_hub_assets()` enqueues it on every BN hub so the standalone Follow / Connect partials (sidebar widgets, blocks, etc.) load their reactive store on the frontend. `@buddynext/members` joins the shell-dialog consumer list.
- New tests:
  - `tests/SocialGraph/FollowServiceToggleTest.php` — toggle cycle, idempotency, cache invalidation on toggle, action emission count.
  - `tests/SocialGraph/ConnectionServiceTest.php` — five additional cases covering the 5-state machine (`none → pending-sent → pending-received → accepted`, `pending-sent → withdrawn → none`, `pending-received → declined`, `accepted → disconnected → none`, plus authorisation guard on `accept_request`).
  - `tests/Profile/MemberDirectoryServiceTest.php` — search filter, alphabetical sort, most-active sort, member-type meta scope, connections-relation scope.

### Hashtags + Search + DM bridge (production)

- **DM bridge detection hardened** (Messages, row 12). `templates/messages/list.php` now detects WPMediaVerse via three independent signals: the canonical `WPMediaVerse\Core\Plugin` class, the `MVS_VERSION` constant, or any listener on the `buddynext_render_messages` action. A single upstream rename or load-order quirk no longer collapses the messages page into the "WPMediaVerse required" empty state. `mu-plugins/buddynext-early-router.php` whitelist now spells out the WPMediaVerse Free / Pro / hyphenated slug variants explicitly so plugin-isolation on BN routes can never strip the DM engine.
- **Hashtag feed polish** (row 3). Sort tab labelled "Following" renamed to "Following only" to disambiguate from the follow-toggle. Follow / unfollow actions now emit success + error toasts via `window.bnToast`. The Related hashtags sidebar gains a per-row Follow chip wired to the same `actions.toggleFollowHashtag` action; the store now prefers the clicked button's `data-hashtag` attribute over the page context so chips work in any list. Context-state updates are scoped to the page-level hashtag so sidebar toggles do not desync the header.
- **Explore facet wiring** (row 2). The All / People / Posts / Spaces / Media chips on `/activity/explore/` previously had no handler attached because `setFilter` lived in the `buddynext/feed-tabs` store while the chips bind to `buddynext/feed`. Added `setFilter` + `onSearch` to the `buddynext/feed` namespace so chips route to the unified search results page with the matching facet (`/search/?type=members|posts|spaces|media|hashtags`). Trending-tag chips (`tag:foo`) route to the hashtag feed. The explore search input now fires Enter to navigate to `/search/?q=`.
- **Search results page** (row 17). Added Hashtags and Media facets to the type tabs + result list. Hashtag facet renders the bn_hashtags slug match list with post counts. Media facet renders posts that have non-empty `media_ids`. Allowed-tab list extended to `media`. Total result count now includes hashtag + media counts.
- Tests: existing `tests/Bridges/WPMediaVerseBridgeTest`, `tests/Hashtags/HashtagServiceTest`, `tests/Search/SearchControllerTest`, and `tests/Search/SearchServiceTest` continue to pass; bridge test is structural and unaffected by the multi-signal detection; search tests do not assert on the new facets.
### Onboarding + Auth (production)

- **Onboarding wizard (`/onboarding/`)** — visual `.bn-progress` bar above the step header that fills step-to-step driven by the Interactivity store (`state.progressPercent` + `state.progressWidth`); numbered stepper now reflects `is-active` / `is-done` reactive classes. Step 1 (Profile) ships avatar upload + display name (prefilled, 2+ char gate) + username with slug-check + bio textarea. Step 2 (Interests) — multi-select tag grid for the 12-tag spec catalogue (Web Dev, Design, AI & ML, Startups, Marketing, Data, Product, Writing, Open Source, Gaming, Music, Photography), Continue gated on 1+ selection. Step 3 (Spaces) — suggested-spaces card list with inline Join + optimistic UI + rollback on failure. Step 4 (People) — suggested-follows grid with inline Follow + optimistic UI + rollback. Back / Skip / Continue / Finish actions match v2 spec — Back hidden on step 1, Finish on step 4 surfaces a Saving spinner. Every action emits a toast on success and renders an inline alert + per-field message on failure.
- `assets/js/onboarding/store.js` rewritten as a real reactive store — `state.currentStep`, `state.progressPercent`, derived `state.isStep1..4`, `state.continueDisabledStep1`, `state.continueDisabledStep2`, `state.interestCountLabel`, plus actions `nextStep`, `prevStep`, `skipStep`, `setDisplayName`, `setBio`, `checkUsername`, `selectInterest`, `joinSuggestedSpace`, `followSuggestedUser`, `triggerAvatarUpload`, `handleAvatarUpload`, `finish`. `finish` persists step-1 fields via PUT /me/profile, then POSTs `/me/onboarding/complete` and redirects to `/activity/` on success; toast + inline-alert + stay-on-screen on 4xx/5xx.
- **Login (`/login/`)** rewritten to a slim REST-driven form — Welcome-back hero + brand logo, email/username + password + Remember-me + Forgot-password link + Sign-in primary button. Submit posts to `POST /buddynext/v1/auth/login`, returns `{ success, user_id, redirect_to }` or `{ success: false, message }`. Loading state on submit (disabled inputs + spinner copy on the button). Errors surface inline above the form via `state.error` without a page reload. Optional social SSO row when the `buddynext_auth_social_providers` filter returns providers. "New here? Create an account" link to `/signup/`.
- **Signup (`/signup/`)** — new dedicated template + Interactivity store. Form has email + username (with the existing slug-check endpoint) + password with strength meter (`.bn-progress` 0-4 levels) + Terms checkbox + Create-account button. Inline validation per field with red outline (`aria-invalid` bound to per-field error state) and a server-side validation pass that returns 422 + `{ data: { fields: { email, user_login, password, terms_agreed } } }`. On success redirects to the verify-email page when email-verification is enabled, otherwise to `/onboarding/`.
- **Verify-email (`/verify-email/`)** — three reactive states driven by `?bn_verified=0|1` + the optional `?email=` query arg. Pending: "Check your inbox at {email}" + Resend button + indeterminate progress bar. Success: "Email verified — welcome!" + Continue CTA → `/onboarding/`. Error: "This link expired or is invalid." + Request-a-new-link button. Both resend + request-new-link actions hit `POST /auth/verify/resend` and surface a feedback chip + toast.
- **Auth shell** — new `templates/shell/auth-shell.php` slim centered single-column shell (NOT the rail + main + sidebar feed shell). PageRouter routes the `auth` hub through it. `bn-auth.css` ships `.bn-app--auth` centering + `.bn-auth-field--check` row + `.bn-auth-link` styles + `[aria-invalid]` red outline.
- New REST routes (all under `buddynext/v1`):
  - `POST /auth/login`           — email/username + password + remember + redirect_to → returns `{ success, user_id, redirect_to }` or `WP_Error` (400 missing creds, 401 invalid).
  - `POST /auth/register`        — email + user_login + password + terms_agreed → returns `{ success, user_id, redirect_to }` or 403 (closed) / 422 (per-field errors).
  - `POST /me/onboarding/step`   — save step data + advance.
  - `POST /me/onboarding/skip`   — skip the wizard, mark complete.
  - `POST /me/onboarding/complete` — finish the wizard with interests + spaces + user_ids payload, returns `{ completed, redirect_to }`.
  - `POST /me/interests`         — save interest labels list independently.
  - Existing `/auth/verify/resend` + `/auth/verify/status` retained.
- New rewrite rules: `/signup/` (configurable via `buddynext_slug_signup`) and `/verify-email/` (`buddynext_slug_verify`) routed through the auth hub via the new `bn_auth_action` rewrite tag. Logged-in users are redirected away from `login` + `signup`, but the verify-email page stays accessible because an unverified user must still see the "check your inbox" state.
- `VerificationListener::handle_verify_request()` now redirects to the verify-email page (instead of home) so the success / error states render on the right surface.
- New tests: `tests/Auth/AuthControllerTest.php` gains login + register coverage (route registration, missing-creds 400, bad-password 401, valid-credentials 200, email-resolution, registration-closed 403, field-level 422 validation, duplicate-email 422, successful registration 200). New `tests/Onboarding/OnboardingControllerTest.php` covers all four routes — registration, auth gating, step advance, skip-marks-complete, complete-marks-complete + stores interests, double-finish fires action only once, interest-save endpoint persists labels. 39/39 PHPUnit tests pass in the Auth + Onboarding scope. 909/909 in the full suite.
- New Script Modules registered for the three new Interactivity stores: `@buddynext/auth-login` → `assets/js/auth/login-store.js`, `@buddynext/auth-signup` → `assets/js/auth/signup-store.js`, `@buddynext/auth-verify` → `assets/js/auth/verify-store.js`. The old classic `bn-auth-verify` script registration is removed — verify-store is now a real Interactivity module that gets enqueued by hub-action.

### Feed (production)

- **Feed (production)** — Composer gains Event, Voice, AI tools + chip privacy selector. Home feed gains For you / Following / Spaces / Network filter tabs with per-tab counts + empty states. Post card gains Share action with Repost / Quote / Copy-link modal. Sidebar widgets gain per-row Follow chips + This-week caption + unread-space dots.
- `templates/partials/composer.php` — five composer tools (Image, Poll, Event, Voice, AI helper) plus a chip-style privacy popover (Public / Followers / Only me) replace the native `<select>`. Inline error band + retry CTA + disabled textarea while submitting.
- New partials `composer-event-modal.php`, `composer-voice-modal.php`, `composer-ai-modal.php`, `share-modal.php` rendered in the home feed shell.
- `FeedService::home_feed( $user_id, $cursor, $per_page, $filter )` accepts `for-you | following | spaces | network`. New `FeedService::home_feed_counts()` powers per-tab badges (24h window). New REST routes `GET /buddynext/v1/feed/home?filter=` (enum-validated) and `GET /buddynext/v1/feed/counts`. New `PostService` types `event` + `voice_room`.
- `WidgetService::suggested_follows()` returns `follow_status` per row (`unfollowed | requested | following`); cache key bumped to invalidate. Per-row Follow chip wired via the existing `buddynext/follow-button` store. Trending Topics gains a "This week" caption. Your Spaces shows an unread-count dot when `bn_space_members.unread_count` is non-zero (column-detected; no-op gracefully when the column is absent). Each widget renders an empty state ("No trending topics yet" / "We'll suggest people once you've completed onboarding" / "Join your first space").
- Three new icons: `mic.svg`, `sparkles.svg`, `bar-chart-2.svg`.
- New CSS for chip privacy popover, composer error band, composer modals, home-feed filter tabs, feed skeleton, share modal, sidebar caption/empty/unread dot.
- New tests: `tests/Feed/FeedServiceTest` covers each filter (`for-you`, `following`, `spaces`, `network`), unknown-filter fallback, and counts shape. `tests/Feed/FeedControllerTest` covers `?filter=` enum + `/feed/counts` shape + auth.

### Notifications (production)

- Every notification type now renders human-readable copy via the new `NotificationMessageService`. The fallback "memberX sent you a notification" string is gone. Exhaustive message map is documented in `docs/specs/NOTIFICATION-MESSAGES.md` covering 30+ types across social graph, feed activity, spaces, messages, moderation, growth, and bridges. Adding a new type now requires adding a switch case + spec row + test — no template changes.
- `NotificationMessageService::compose()` is the single source of truth used by `templates/notifications/index.php`, `GET /me/notifications` (response now includes `message`, `url`, `icon`, `tone`, `label`, `actor_name`), the nav-dropdown partial, and the email-token resolver.
- Group-collapse copy uses `_n()` so "X and N others" renders correctly in every plural form.
- Empty / error states — each filter tab (All / Unread / Mentions / Comments / Reactions / People / Spaces / Messages) renders its own empty copy + emblem + CTA. A REST-failure error block surfaces a retry button.
- Unread badge reactive — Interactivity store exposes `state.unreadCount`, `state.unreadLabel`, `state.badgeHidden` with 99+ cap. Mark-as-read / mark-all-read use optimistic UI with rollback toast on 4xx. Mobile nav badge now uses the same store.

### Profile (production)

- **Profile (production)** - Edit profile now has master Save with sticky save bar, dirty-state guard, field-level validation. View renders all 25 saved fields incl. Work/Education timelines + social chips + interests tag cloud. Other-user view gets Follow / Connect / Message / More-options menu with Mute / Block / Report wired.
- `templates/profile/edit.php` wraps every section in a single `<form data-wp-on--submit=actions.saveProfile>`; sticky save bar renders dirty / saving / saved status pills; display name flagged red on blur when blank; website + social URL fields show inline error text and a red outline when invalid; beforeunload guard prevents accidental navigation while dirty; page title is now `Edit Profile : {display_name}` via the document_title_parts filter wired in PageRouter.
- `assets/js/profile/store.js` ships a real `saveProfile()` flow that POSTs the full payload, handles 200 / 422 / 5xx with field-level error mapping; adds `markDirty`, `validateField`, `confirmCancel`, plus the report-modal and block-confirm modal actions (`openReport`, `setReportReason`, `setReportNotes`, `submitReport`, `confirmBlock`, `closeBlockConfirm`); follow / unfollow / connect / mute / block now use optimistic UI with rollback on REST 4xx/5xx; every action emits a toast via the shared `bnToast` helper.
- `includes/Profile/ProfileController.php::update_profile()` now validates the payload before persistence, returns `{ saved: false, errors: { field: message } }` with status 422 on validation failure, normalises bare-host URLs by prefixing https://, caps long-form fields at sensible lengths (bio 1000 / headline 160 / location 120 / pronouns 40), and round-trips the saved profile in the 200 response payload.
- `templates/profile/view.php` renders inline Work Experience and Education timeline cards, a Community Interests tag cloud linking to `/activity/hashtag/{slug}/`, brand-coloured Social Link chips below the hero meta row (Twitter / LinkedIn / GitHub / Instagram / YouTube), and pulls the website link rel attribute to `nofollow noopener noreferrer ugc` per spec.
- New partials `templates/partials/report-modal.php` and `templates/partials/block-confirm-modal.php` replace any native `confirm()` / `alert()` paths for the destructive Block flow and the category-driven Report flow; report POSTs to `/buddynext/v1/reports` with `{ object_type: 'user', object_id, reason, notes }`.
- `tests/Profile/ProfileControllerTest.php` adds full-payload happy path, blank display_name 422, invalid website URL 422, empty website passthrough, and protocol-less URL normalisation tests (10 tests / 24 assertions).
- `includes/Core/AssetService.php` registers `@buddynext/profile` as a shell-dialog consumer so the store can import `bnToast` cleanly.

### Testing

- Added Playwright e2e suite under `tests/e2e/` covering every BN user journey across desktop, iPad, and mobile viewports. Run `npm run test:e2e`. See `docs/qa/JOURNEYS.md` for the journey catalogue (67 journeys grouped by role) and `docs/qa/HOW-TO-RUN.md` for the runbook. New devDeps: `@playwright/test`, `typescript`, `@types/node`.

### Shell

- **BREAKING (shell)** — Theme `get_header()` / `get_footer()` now render on every BN-mapped slug. The shell-takeover mode and `buddynext_render_with_theme_chrome` filter introduced in 0.3.0-beta1 are removed. `.bn-app` bursts to 100vw inside the theme so content stays edge-to-edge. The host theme always owns DOCTYPE / `<html>` / `<head>` / `wp_head()` / `<body>` / `wp_body_open()` / `wp_footer()` / `</html>`; BuddyNext only renders the `.bn-app` canvas between them.
- Removed the BN topbar from the hub shell. The active theme's `get_header()` is now the only top navigation; the v2 wireframes always intended this (the `chrome.js` injection in `docs/v2 Plans/v2/*.html` maps to the host theme header in production). `templates/shell/topbar.php` is deleted, and the corresponding `.bn-app__topbar*`, `.bn-app__brand*`, `.bn-app__search*`, `.bn-app__font-scale*`, and `.bn-app__icon-btn` rules are removed from `assets/css/bn-shell.css`. `--bn-topbar-h` is set to `0` so existing `calc()` expressions in feature stylesheets keep working. The shell now renders only rail + main + (optional right sidebar) + mobile bottom nav. The mobile bottom tab bar (`.bn-mobile-nav` from `templates/partials/nav.php`, the 5-item Feed / Spaces / + / Alerts / Profile bar from `docs/v2 Plans/v2/mobile.html`) is rendered by `hub-shell.php` on every BN hub so per-hub templates no longer need to include it.

## 0.3.0-beta1 — 2026-05-21

### Architecture (locked + extension-ready)

- **5-layer modular architecture** documented in `docs/specs/MODULAR-ARCHITECTURE.md`. Core / Bridges / Features / UI / Composition. Every Layer 2 feature is a self-contained folder with the canonical 4-file shape: `Service.php` / `Controller.php` / `Listener.php` / `Cache.php`.
- **Shell inside theme chrome** — PageRouter wraps every BN-mapped slug with the active theme's `get_header()` / `get_footer()`. The `.bn-app` canvas bursts to 100vw so content stays edge-to-edge regardless of the theme's content container. Right sidebar auto-detected via `has_action('buddynext_right_sidebar')`.
- **FeatureRegistry + Settings → Features tab** — site owners pick which Layer 2 features are active. Three tiers: mandatory (always on), default-on (toggleable), opt-in (off until enabled). 19 features catalogued. Third-party plugins extend via `apply_filters('buddynext_features', $catalog)`.
- **Plug-and-play model** — every feature opts in via `apply_filters('buddynext_feature_{slug}', true)`. `Container::has()` lets callers detect sibling features. Templates degrade gracefully when a feature is disabled.
- **7 extension surfaces** documented: new Feature module / hooks + filters / container rebinding / template parts override / `buddynext_right_sidebar` action / hub `_before` / `_after` hooks / REST namespace separation.

### Scale contract (100k × 100k)

- `docs/specs/SCALE-CONTRACT.md` codifies the 10 non-negotiable rules for the target scale.
- **Sidebar widgets** — Service + Cache + Listener pattern. Trending hashtags (60s TTL), suggested follows (300s TTL, per-user), joined spaces (300s TTL, per-user). Cache-bust hooks on 9 domain events.
- **FeedCache** — first-page home feed wrapped in 30s TTL. Page-2+ bypasses (cursor-paginated). Listener busts the writer's keys on `buddynext_post_created` / `_deleted`.

### Frontend (v2 design system, all hubs swept)

- Every BN hub (activity, explore, members, spaces directory + home, notifications, profile view + edit + connections, messages, search, hashtag feed, onboarding, leaderboard, moderation queue, community admin, blocks) chrome-stripped onto the shell. Sidebar widgets hooked via `buddynext_right_sidebar` action.
- `assets/css/bn-base.css` is the canonical v2 token source. Single `--bn-hue` rotates the whole palette (OKLCH).
- v2 attribute API across all primitives: `.bn-btn[data-variant]`, `.bn-input`, `.bn-textarea`, `.bn-select`, `.bn-badge[data-tone]`, `.bn-avatar[data-size+data-presence]`, `.bn-card`, `.bn-tabs` + `.bn-tab[aria-selected]`, `.bn-modal`, `.bn-toggle`, `.bn-stat`, `.bn-stepper`, `.bn-progress`.
- Composer single-state matching `v2/home-feed.html` — avatar + textarea + tools row (icons + privacy + Share).
- Post card uniform: head row + body + reaction chips + action row, all post types preserved.
- Mobile 44px tap targets (style-guide rule 04). Density + text-scale + dyslexia modes via `[data-bn-*]` attributes.
- Sidebar widgets gap (16px), main / right padding parity, post-card spacing — no double-spacing.

### Tooling + boundary skills

- Vendored `bin/ux-audit.sh` (from `/ux-audit` skill).
- `bin/check.sh` — full CI-parity gate: PHP lint + WPCS + PHPStan level 5 + REST-boundary + UX audit.
- `bin/check-rest-boundary.sh` — fails on any `admin-ajax.php` surface. 100% REST frontend enforced.
- `.githooks/pre-commit` — runs the staged-files slice of `bin/check.sh`.
- `bin/ux-audit.sh` audit dropped from 97 → ~12 block-severity violations (remaining are in v2 mockup HTML, expected).

### Tests

- 33 new architecture tests: FeatureRegistry (11), Container (6), Sidebar/WidgetService (6), Feed/FeedCache (5).
- Full suite: 747 tests, 1285 assertions, all OK with 21 pre-existing skips.

### Pro v0.4.0 (sibling repo)

- **P1.1 Stripe SDK + webhook controller** (`98e9975` in Pro). REST `POST /buddynext-pro/v1/stripe/webhook` with signature validation. Subscription events upsert `bn_subscriptions`. 9 new tests.
- **P2.2 AI moderation classifier** (`f219048`). Hooks Free's `buddynext_safeguard_check` filter. Provider: OpenAI / Anthropic / local heuristic. Admin settings + REST test endpoint. 15 new tests.
- **P3.1 Soketi WebSocket bootstrap** (`97f14fa`). Pusher-protocol client + RealtimeDispatcher (5 events) + REST auth handshake + admin. 23 new tests.
- **P4.1 FCM push** (`7225e38`). `bn_push_tokens` table + JWT auth via service account + PushDispatcher (hooks `buddynext_notification_created`) + admin. 24 new tests.
- Pro test suite: 351 → 384 tests, 1 pre-existing failure.

### Documentation

- `docs/v2 Plans/PLAN.md` — surface-to-prototype map + 6 uniformity gates + 9-rule contract + rollout phases.
- `docs/v2 Plans/REVIEW.md` — engineering review of v2 (browser support, accessibility, whitelabel correctness).
- `docs/v2 Plans/TEMPLATE-REFACTOR-PLAN.md` — long-term per-template refactor contract.
- `docs/specs/MODULAR-ARCHITECTURE.md` — 5-layer model + plug-and-play + 7 extension surfaces.
- `docs/specs/SCALE-CONTRACT.md` — 100k × 100k binding rules.
- `docs/specs/TEMPLATE-PARTS.md` — reusable partial library.
- `docs/specs/REST-FRONTEND-CONTRACT.md` — REST-only boundary contract.
- `docs/specs/REST-INVENTORY.md` — 113 captured REST routes.

### Removed

- `docs/superpowers/brainstorm/14544-1773947712/` legacy mockups (50 files, ~22k lines). v2 Plans is the only design source.
- Legacy `:root` alias block in `bn-feed.css` that created circular token references on hubs loading multiple stylesheets.
- All `wp_ajax_*` registrations from frontend code paths. The last surface (NavManager slug-check) migrated to `GET /buddynext/v1/admin/slug-check`.

## 0.2.0 — 2026-05-20

Beta dogfooding release. Baseline before the v2 architecture sweep.
