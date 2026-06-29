# Spaces — 1.0.4 plan, status & handoff (single source of truth)

One file for the whole Spaces component: what's done, what's pending, how to verify, and the build-specs
for the remaining UI. Members work lives in `members-profile-improvements.md`. Both repos on `1.0.4`,
Free schema **v11**. Last updated 2026-06-29 (end of Spaces build session).

**Standing rules:** 100% app-ready (REST `buddynext/v1` is the primary surface; web UI is an Interactivity
client of the same REST, never a server-only POST). New per-space attribute = `bn_space_meta` row, never a
column or autoloaded option. Reuse canonical patterns (FieldType engine, Nav render seam, role-rank
threshold) — no dup. Verify per item: data-flow (DB) + browser, all states.

---

## NEW TASKS — 2026-06-29 usability pass (running tracker, keep updated for testing)

Owner-driven usability items raised during review. Each must be tested 1-by-1. Status: ✅ done+verified ·
🟡 in progress · ⏳ pending.

| # | Task | State | Test |
|---|---|---|---|
| U1 | **Breadcrumb below the title** (was above). Keeps the title at the same vertical position as a root space; 8px gap. `space-hero.php` + `.bn-sh-hero__breadcrumb` margin. | ✅ done + browser-verified | On a sub-space: breadcrumb renders below the H1, comfortable gap, parent links; absent on a root. |
| U2 | **My Spaces: separate "managed" from "joined"** — REST-first + pretty URLs. Service `member_role` filter + `viewer_role`; REST `GET /spaces?membership=managed\|joined`. Web: pretty routes `/spaces/mine/` (sectioned managed+joined, each capped 12 with "View all") and `/spaces/mine/managed\|joined/` (paginated bucket) via rewrite rules; reusable `parts/space-directory-card.php`; All/My chips are pretty links, categories hidden on the My view. | ✅ done + browser-verified | `/spaces/mine/` shows 2 sections (verified: manage=2, joined=3); `/spaces/mine/managed/` flat grid; `/spaces/` unchanged; REST `membership=` filter verified. Follow-ups below. |
| U6 | **Hero title wrap fix (space)** — a two-word space name (e.g. "Design Critique") was crushing into a stacked column because `.bn-sh-hero__info` had `min-width:0` (collapsed under the action buttons on the same row). Set `min-width: min(100%, 16rem)` so the title takes priority and the actions wrap below when tight. | ✅ done + browser-verified | Title renders on one line (info 441px); actions wrap below on a narrow head; mobile unaffected. |
| U7 | **Hero title wrap fix (member profile)** — same class on `/members/{slug}/`: the `.bn-pf-head` 3-col grid (avatar\|id\|actions) crushed the name to ~52px because the "Member of" rail narrows the main column to ~605px even at a 1280 viewport (viewport media queries don't catch it). Made `.bn-pf-hero` a size container and stack the head via `@container (max-width: 720px)`. | ✅ done + browser-verified | Name on one line (id 557px), actions stacked below; owner cover/avatar edit buttons still positioned correctly. |
| U3 | **Custom field → promote to a space tab** (owner-curated persistent content tab, parity with Feed/Members). Eligible types = textarea/url (no richtext type exists); auto label + icon (link\|file-text). Owner promotes per space via a "Show as a tab" toggle in the Custom fields panel (saved with the field values over `POST /spaces/{id}/fields {tabs:[]}`); injected via `buddynext_nav_items`; `parts/space-field-tab.php` renders url→CTA, textarea→content; visibility-respected; empty tabs hide from members, show to managers with an add-content nudge. | ✅ done + browser-verified | Verified: promote→tab at `/spaces/{slug}/field-{key}/` renders; guest sees public field tab not members-only; toggle off→save→tab vanishes (meta updated); 390px/dark clean. |
| U4 | **Display custom fields on About** — non-core fields with a value, visibility-filtered, that are NOT promoted to a tab, render in an About "Details" `<dl>` (`SpaceNav::render_about_panel` resolves them; `space-about-panel.php` renders; url→link, others→`display_text`). | ✅ done + browser-verified | Verified: About "Details" shows "Accepting new members: Yes" (boolean) + "Skill level: Advanced" (select); a tab-promoted field is excluded (no duplication); core settings fields never appear. |
| R4 | Search-fold: public+searchable space fields → `bn_search_index`. | ⏳ optional/deferred | A `searchable:true,visibility:public` field's value is findable in search. |
| F1 | **DRY follow-up:** the flat directory grid (`/spaces/` All view) still uses the inline card; only the sectioned My Spaces view uses the new `parts/space-directory-card.php`. Convert the flat grid to the part too. | ⏳ follow-up | Both views render from one card source. |
| F2 | Sidebar "Your spaces" widget still mixes managed/joined — give it the same managed-first / split treatment. | ⏳ follow-up | Sidebar groups or leads with managed. |

---

## STATUS

| Item | State | Notes |
|---|---|---|
| **T5** roster + parent indexes (v11) | ✅ DONE + verified | EXPLAIN: roster→`space_status` (no filesort), sub-space→`parent` (covering) |
| **T7** `bn_space_meta` foundation | ✅ DONE + verified | table + `$wpdb->bn_spacemeta` alias + `get/add/update/delete_space_meta()` + `SpaceFieldRegistry` + `buddynext_register_space_field()` + `FieldType::display_text/rest_value` |
| **T7** Pro-collision reshape | ✅ DONE + verified | `Installer::maybe_reshape_space_meta()` converges Pro's `(id,space_id)` → canonical `(meta_id,bn_space_id)` BEFORE dbDelta; zero data loss |
| **T7** REST app layer | ✅ DONE + verified | `GET /spaces/fields`, `POST /spaces/{id}/fields` (manage-gated, atomic, 422), `GET /spaces/{id}` returns `fields[]` |
| **T8** 8 built-in options → core fields | ✅ DONE + verified | `CoreSpaceFields`; 10 readers+writers → meta; `FeedService` alloptions scan → indexed query; options→meta migration; autoload P0 closed |
| **T7-Pro** Pro brand → Free API | ✅ DONE + verified | Pro CREATE removed; `BrandService` raw SQL → `get/update_space_meta()`; round-trip proven |
| **T10** app layer | ✅ DONE + verified | `get_subspaces/count_subspaces/parent_summary`; `GET /spaces/{id}/subspaces`; breadcrumb + `subspace_count` on `GET /spaces/{id}`; directory `roots_only` (SSR+REST) |
| **T10** move/detach | ✅ DONE + browser-verified | `PUT /spaces/{id}` `parent_id` via `validate_parent_move()`. Live REST run 2026-06-29: move 43→42 (200, breadcrumb `parent{42}` populates, `subspace_count`=1); self-parent/depth-3/has-children/parent-not-found all return 422 with correct codes; detach→root (200, parent null). DB restored. |
| **T11** admin completeness | ✅ DONE + browser-verified | Live run 2026-06-29: PENDING column renders, "Sub-space of Design Critique" label shows on the child row, Archive→ "Archived" badge + Unarchive action, Unarchive round-trips. **Known cosmetic gap confirmed live: no admin success notice after the `?archived=1` redirect** (see follow-ups). |
| **Gap-audit** enforcement fixes | 🟡 DONE — code-verified | `can_invite()` honors `who_can_invite`; `default_notification_pref` seeded on join/request/invite (shared helper) — **browser-verify** |
| **R1** Nav UX | ✅ DONE + browser-verified | breadcrumb (sub-space hero) + Sub-spaces rail card + manager Add-CTA → fixed-parent create modal. See "R1 — as built" below. |
| **R2** member panel → REST | ✅ DONE + browser-verified | settings Members panel converted to the `buddynext/space-members` store. See "R2 — as built" below. |
| **R3** web field panel | ✅ DONE + browser-verified | additive "Custom fields" settings panel for developer-registered (non-core) fields. See "R3 — as built" below. |
| **R4** search-fold (optional) | ⏳ PENDING | public+searchable fields → `bn_search_index` |

All ✅/🟡 are phpcs (0 errors) + PHPStan L5 clean. Local DB already migrated to v11; reset
`buddynext_schema_version` + reload wp-admin to re-run a fresh migration.

---

## REMAINING — frontend panels (build + browser-verify next session)

Not shipped blind: these are Interactivity rewrites of currently-working surfaces; the REST they consume
is already built + proven, so they are pure UI.

### R1 — Nav UX — AS BUILT (2026-06-29, browser-verified)
Shipped as server-render (SSR reads the same SpaceService data the REST exposes — no client fetch needed).
- **Breadcrumb** — `templates/parts/space-hero.php`. Renders `Parent > This space` above the `<h1>` only
  when the space has a parent, via `SpaceService::parent_summary($parent_id)` (visibility-scoped → a parent
  the viewer can't see resolves null and the crumb is omitted, never leaked). Parent link =
  `buddynext_space_url($slug)`. Tokens only (dark-safe), 16ch ellipsis truncation. CSS `.bn-sh-hero__breadcrumb`
  in `bn-spaces.css`. Verified: present on a child (links to parent), **absent** on a root.
- **Sub-spaces card** — `templates/parts/space-sidebar.php`, registered on `buddynext_right_sidebar` via
  `parts/sidebar-card.php` (id `space-subspaces`, icon `layers`). **Deviation from the original "feed panel"
  wording, intentional:** placed in the persistent right rail (uniform across every space tab) rather than the
  Feed tab body — matches the Discord/Notion expectation that sub-spaces are persistent navigation, and is
  more discoverable. Same render-seam philosophy (self-contained, resolves from `space_id`). Lists
  `get_subspaces()` children (visibility-scoped; uses the **visible** list, sidestepping the secret-child
  count discrepancy) as linked rows: square `.bn-avatar` emblem + name + `_n()` member count. Only a root
  space gathers the list (depth-2 cap → a sub-space never shows it). Verified: parent rail shows the child
  with correct count.
- **Add-CTA + fixed-parent modal** — manager-only (`buddynext-manage-space` AND the
  `buddynext_space_allow_sub` toggle), shown on a childless root too (so the first sub-space is discoverable,
  with an empty hint). `create-space-modal.php` gained a `$fixed_parent` path: title becomes "Create a
  sub-space", the parent picker is replaced by a locked `Sub-space of <name>` chip + a hidden `parent_id`,
  and `submitCreate` (which already reads `[name="parent_id"]`) carries it. The modal is rendered once at the
  rail and **wrapped in its own `data-wp-interactive="buddynext/spaces"` region** (the partial has no wrapper;
  without this the modal's own `submitCreate` action never binds — caught in browser test). Verified end to
  end: CTA opens locked modal → create → REST sets `parent_id` → redirect to child → breadcrumb + parent rail
  both update. WPCS + UX-audit clean; 390px no-overflow; dark token-flip confirmed. (Note: the right rail is
  desktop/tablet-only by existing template design — all rail cards, not just this one, are hidden < the rail
  breakpoint; mobile parent context is carried by the always-visible hero breadcrumb. A mobile sub-space
  surface is a template-wide concern, out of R1 scope.)

### R2 — member-management panel → REST/Interactivity — AS BUILT (2026-06-29, browser-verified)
Converted `templates/parts/space-settings-panel-members.php` from 5 server POST forms to the **existing**
`buddynext/space-members` Interactivity store (the same store the Members tab uses — no new store).
- **Reused** `removeMember` + `changeRole`; **added** `banMember` (`POST /spaces/{id}/bans {user_id}`) and
  `inviteMember` (`POST /spaces/{id}/invite {identifier}`) to `assets/js/space-members/store.js`, plus their
  i18n keys in `AssetService::i18n_space_members`. Invite is bound to the form's `submit` so Enter and the
  button both work; `preventDefault` stops the native POST; success clears the field + toasts (no reload,
  since an invite doesn't change the active roster); role/remove/ban reload (roster changes).
- Panel wrapped in `data-wp-interactive="buddynext/space-members"` with `spaceId` + `restNonce` context;
  each button carries `data-user-id` (+ `data-role` for promote/demote). Owner row has no actions.
- `PageRouter` now enqueues the `space-members` module on the **settings** sub-page too (was members-tab
  only) — without it the buttons render but never hydrate.
- Removed the legacy POST handler in `templates/spaces/settings.php` (and the now-dead `$save_error_message`
  + `invite_sent` notice) — the panel is a pure REST client like the rest of the app layer.
- Verified live on Design Critique (admin): promote→Moderator→demote→Member; remove (confirm→gone);
  ban (confirm→removed + `bn_space_bans` row); invite `bn_demo_jonas_berg`→`invited` row + field cleared;
  empty-invite→validation, no POST. Zero legacy forms in the DOM. WPCS + PHPStan-L5 clean; 390px no-overflow;
  dark inherited. Seed roster restored after testing (10 active, 0 bans).

### R3 — web field-management panel — AS BUILT (2026-06-29, browser-verified)
**Scope decision (owner, 2026-06-29): additive, not a replacement.** The 8 built-in fields keep their
polished bespoke tabs (no regression risk); R3 adds a registry-driven surface for the fields that have NO UI
today — third-party developer-registered space fields (the P-B "developer-friendly" deliverable).
- **`core` flag** added to `SpaceFieldRegistry::register()`; `CoreSpaceFields` marks its 8 as `core=true`.
  New `get_custom_fields()` returns only non-core fields (empty on a stock install).
- **New panel** `templates/parts/space-settings-panel-fields.php` renders each custom field via
  `Profile\FieldType::render_input()` (booleans render their own label; others get a `<label for>` + hint +
  required marker + inline error slot). Wrapped in a `buddynext/space-fields` interactive region.
- **New store** `assets/js/space-fields/store.js` (`saveFields`): collects inputs by shape
  (checkbox→'1'/'0', radio, multiselect, value), `POST /spaces/{id}/fields {fields}`, success toast, and
  per-field inline errors from the 422 `{errors:{key:msg}}` body. i18n in `AssetService::i18n_space_fields`;
  module registered + `enqueue('space-fields')` on the settings sub-page.
- **Settings screen** (`templates/spaces/settings.php`): a "Custom fields" tab is spliced in **only when
  `get_custom_fields()` is non-empty** (the typical owner with none never sees an empty tab), slotted before
  Danger zone; panel added to the dispatch map.
- Verified live (temp-registered `meeting_url` url + `skill_level` select): tab appears only with custom
  fields; both render with labels/hints; save persists to `bn_space_meta`; an invalid select option shows the
  per-field 422 inline ("Please choose a valid option for Skill level."); the 8 core fields never appear here.
  WPCS + PHPStan-L5 clean; 390px no-overflow; dark inherited. Temp field + test meta cleaned up after.

### R4 — search-fold (optional, smaller)
- Fold public + searchable space fields into `bn_search_index` via `SearchService::index()` so a developer
  field `searchable:true,visibility:public` becomes discoverable. Hook the field save path.

---

## Gap-audit findings (2026-06-29 double-check)

**Fixed (closed pre-existing "saved-but-not-applied" bugs):**
- ✅ `who_can_invite` — `SpaceMemberService::can_invite()` now honors it (members|mods|owner) via the same
  role-rank model as `who_can_post`; was hardcoded owner/mod/admin. Default `mods` preserves behavior.
- ✅ `default_notification_pref` — seeded on ALL creation paths (`join`/`request_join`/`invite`) via one
  shared `default_notification_pref()` helper; ban-record INSERT excluded. Default `all` preserves behavior.

**Documented follow-ups (not regressions — decide for 1.0.4 or later):**
- ⏳ Pro `MembershipAdmin` `bn_space_{id}_paywall_*` options → migrate to `bn_space_meta` (Pro follow-up).
- ⏳ Sub-space `total` counts secret children a non-member can't see (rare count discrepancy).
- ⏳ `handle_archive` redirects `?archived=1` but no matching admin success notice (cosmetic) — **confirmed live 2026-06-29** (archive/unarchive both work; just no `add_settings_error`/notice on return).
- ⏳ Directory composite index (S3) deferred — EXPLAIN-gate on a seeded 5k-space lab before adding.

**Verified clean:** zero remaining `bn_space_` option reads/writes in Free (only the empty back-compat
`buddynext_space_option_suffixes` filter); all 8 fields migrated + have consumers; `delete()` clears meta;
Pro has no raw `bn_space_meta` left.

---

## Verification checklist (fresh session)

1. **Fresh migration:** DB at schema < 11 → load wp-admin → `bn_space_meta` created canonical, 8 options
   migrate to meta + delete, zero `bn_space_*` autoload rows. (`maybe_reshape_space_meta` + `maybe_migrate_space_options`.)
2. **T10 move/detach:** `PUT /spaces/{child} {parent_id:0}` detaches; `{parent_id:<root>}` moves; depth/
   cycle/cap/no-manage → 422/403 with the right message; a space WITH children can't be nested.
3. **T11 admin:** Pending column counts; Archive → badge + Unarchive; "Sub-space of X" under a child;
   archive non-destructive; bulk + delete still work.
4. **Gap fixes:** owner sets `who_can_invite=members` → a regular member can invite; set `=owner` → only
   owner; a space with `default_notification_pref=none` → a new member's `bn_space_members.notification_pref`
   is `none`.
5. **Build + verify R1–R3.**
6. **Release gate:** `/wp-contract-audit` clean; WPCS/PHPStan/UX-audit; manifest refresh (both repos);
   readme changelog (action-prefix) + lockstep Free/Pro bodies; Docker pristine-install smoke of both zips.

---

## New symbols this release (for manifest refresh)
- Free fns: `get/add/update/delete_space_meta`, `buddynext_register_space_field`, `buddynext_get_space_field`
- Free classes: `Spaces\SpaceFieldRegistry`, `Spaces\CoreSpaceFields`
- Free REST: `GET /spaces/fields`, `POST /spaces/{id}/fields`, `GET /spaces/{id}/subspaces`; `GET/PUT /spaces/{id}` extended
- Free service: `SpaceService::get_subspaces/count_subspaces/parent_summary/validate_parent_move`;
  `SpaceMemberService::default_notification_pref` (+ `can_invite` now reads the setting); `FieldType::display_text/rest_value`
- Schema: `bn_space_meta` table; `bn_spaces.parent` + `bn_space_members.space_status` indexes (v11)
- Installer: `maybe_reshape_space_meta`, `maybe_migrate_space_options`, `table_exists`, `index_exists`
