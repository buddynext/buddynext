# Release 1.0.4 — Spaces work handoff (verify in fresh session)

This captures the state of the Spaces 1.0.4 effort at the end of the build session so a fresh session
can verify everything and finish the remaining frontend panels. Master plan: `release-1.0.4.md`.

**Branch/version:** both repos on `1.0.4` (Free HEAD was `15d361c4` at session start). Free schema
bumped **v10 → v11**. Version constants bumped to 1.0.4 in both repos.

**Local DB state:** the v11 migration has already run on `buddynext.local` (schema_version=11,
`bn_space_meta` exists in canonical shape, the 8 options migrated to meta on space 42 then cleaned up).
For a clean re-verify, reset `buddynext_schema_version` to a lower value and reload wp-admin to re-run.

---

## DONE + verified end-to-end (DB + REST + browser, this session)

| Task | What shipped | Verified |
|---|---|---|
| **T5** | `KEY parent (parent_id)` on `bn_spaces`, `KEY space_status (space_id,status,joined_at)` on `bn_space_members`; schema v11 | EXPLAIN: roster uses `space_status` (no filesort), sub-space lookup uses `parent` (covering) |
| **T7 foundation** | `bn_space_meta` table + `$wpdb->bn_spacemeta` alias + `get/add/update/delete_space_meta()` + `SpaceFieldRegistry` + `buddynext_register_space_field()` + `FieldType::display_text()/rest_value()` | meta quartet round-trip proven via temp harness |
| **T7 Pro-collision** | `Installer::maybe_reshape_space_meta()` converges Pro's bespoke `(id,space_id)` table to canonical `(meta_id,bn_space_id)` BEFORE dbDelta | seeded legacy row → reshaped, `space_id`→`bn_space_id`, zero data loss |
| **T7 REST app layer** | `GET /spaces/fields`, `POST /spaces/{id}/fields` (manage-gated, atomic, 422 on invalid), `GET /spaces/{id}` returns `fields[]` (value+display, visibility-filtered) | REST read/write/validate/401 all green |
| **T8** | 8 built-in options registered as core fields via `CoreSpaceFields`; all 10 readers + writers moved to `get_space_meta()`/`buddynext_get_space_field()`; `FeedService` `alloptions` scan → indexed meta query; `Installer::maybe_migrate_space_options()` migrates + deletes options; `SpaceService::delete()` clears meta | migration (options→meta, deleted), typed REST read/write, 422 validation, FeedService query, web settings render — all proven |
| **T7-Pro** | Pro `Installer` `CREATE TABLE bn_space_meta` removed; `BrandService` raw SQL → `get/update_space_meta()`; bespoke cache + upsert removed | `save_space_brand(42,hue:120)` → Free `bn_space_meta` → read back |
| **T10 app layer** | `SpaceService::get_subspaces/count_subspaces/parent_summary`; `GET /spaces/{id}/subspaces`; `GET /spaces/{id}` returns `parent{}` + `subspace_count`; directory `roots_only` filter (SSR + REST parity) | create→list→breadcrumb→root-filtered browse→search all proven; EXPLAIN uses `parent` index |
| **T10 move/detach** | `SpaceService::update()` accepts `parent_id` via `validate_parent_move()` (depth, cycle, cap, manage-new-parent, has-children guards); `update_space` forwards `parent_id` + respects error status | **code-verified only** (lint/phpcs/phpstan) — browser-verify next session |
| **T11 admin** | `Admin/Spaces.php`: Pending column (batched count, no N+1), Archive/Unarchive action + `handle_archive` + nonce, "Sub-space of X" hierarchy label (batched parent names) | **code-verified only** (lint/phpcs/phpstan) — browser-verify next session |

All DONE rows are phpcs (0 errors) + PHPStan L5 clean.

---

## REMAINING — frontend panels (build + verify in fresh session)

These were deliberately NOT shipped blind because they are Interactivity rewrites of currently-working
surfaces; building them without browser iteration risks regressing working features. Each has a precise
build-spec below. The REST app layer they consume is already built + proven, so these are pure UI.

### R1 — T10 Nav UX: breadcrumb + "Sub-spaces" section + Add-CTA
- **Breadcrumb** on a sub-space home: render `Parent ▸ This space` from `GET /spaces/{id}`'s new
  `parent{id,name,slug}`. Place in the space header (`templates/parts/space-header.php` or via the
  SpaceNav render seam). Link parent name to `buddynext_space_url($parent.slug)`.
- **"Sub-spaces" section** on a parent home: a panel listing children from `GET /spaces/{id}/subspaces`
  (reuse the `bn-space-card`/`my-spaces` block markup). Show only when `subspace_count > 0`. Build it as
  a **SpaceNav provider panel** (mirror `includes/Nav/Providers/SpaceNav.php` `render_*_panel`) so it
  uses the 1.0.4 render seam, NOT a hand-rolled template.
- **"Add sub-space" CTA**: visible to a member who manages the parent (owner OR moderator — check
  `permissions->can($uid,'buddynext-manage-space',['space_id'=>$parent])`). Opens the existing
  create-space modal pre-seeded with `parent_id`. Note the existing modal parent picker is fed from
  `owned_root_spaces` (owner-only) — the CTA path should allow managers too.
- **Verify:** breadcrumb renders on a child; parent shows the section + correct count; CTA only for
  managers; create-from-CTA sets `parent_id`; 390px + dark.

### R2 — T12 member-management panel → REST/Interactivity
- Convert `templates/parts/space-settings-panel-members.php` (legacy full-page POST in
  `templates/spaces/settings.php`) to an Interactivity store calling the existing REST member routes
  (promote/demote/remove/ban/invite all already exist under `buddynext/v1`), with optimistic UI + toasts,
  matching every other space control. Align with the Nav render seam.
- **Verify:** each action fires the REST call, updates the roster without reload, shows a toast; multi-actor
  states (already-removed) handled; 390px + dark.

### R3 — T7 web field-management panel (the website consumer of the field REST)
- Render the registered space fields on the space settings screen by consuming
  `GET /spaces/{id}` `fields[]` + `GET /spaces/fields`, saving via `POST /spaces/{id}/fields`. An
  Interactivity store; reuse `FieldType::render_input()` server-side for the initial markup, then drive
  saves over REST. This replaces per-tab hardcoded forms for registered fields.
- **Verify:** the 8 built-in fields render in their sections (permissions/moderation/notifications/
  integrations) with current values; save persists to `bn_space_meta`; invalid value shows the per-field
  422 error inline; 390px + dark.

### R4 — T10 search-fold (optional, smaller)
- Fold public + searchable space fields into `bn_search_index` via `SearchService::index()` so a developer
  field marked `searchable:true,visibility:public` becomes discoverable. Hook the field save path.

---

## Gap-audit findings (double-check of Spaces fixes vs plan, 2026-06-29)

Ran a "saved-but-not-applied" / missed-reader / orphan-field sweep across all Spaces changes.

**Fixed during the audit (in-scope — completed the fields T8 made prominent):**
- ✅ **`who_can_invite` was saved but never enforced** — `SpaceMemberService::can_invite()` hardcoded
  owner/mod/admin and ignored the setting. Now reads `who_can_invite` (members|mods|owner) via the same
  role-rank threshold model as `who_can_post`. Default `mods` preserves prior behavior.
- ✅ **`default_notification_pref` was saved but never applied** — now seeded from the space setting on
  ALL membership-creation paths via one shared `default_notification_pref()` helper (no dup):
  `join()` (active), `request_join()` (pending), `invite()` (invited). `approve_request` / invite-accept
  promote an existing row, so the pref carries over. The ban-record INSERT correctly does NOT seed it.
  Default `all` preserves prior behavior.

**Documented — out of Free T8 scope or minor (NOT regressions; decide for 1.0.4 or later):**
- ⏳ **Pro paywall per-space options** — `MembershipAdmin` still writes `bn_space_{id}_paywall_cta_url`/
  `_paywall_cta_label`/`_paywall_description` as options (autoload=false). Not part of Free's 8; should
  migrate to `bn_space_meta` for one-store consistency (Pro follow-up, like T7-Pro did for brand).
- ⏳ **Sub-space `total` vs visible** — `GET /spaces/{id}/subspaces` `total` = `count_subspaces()` (all
  non-archived) while the list hides secret children from non-members. A non-member could see a count
  higher than the cards shown. Make the count visibility-aware if it matters (rare: needs secret children).
- ⏳ **Archive success notice** — `handle_archive` redirects `?archived=1` but the admin renders no
  matching notice (only `?deleted=1` has one). Cosmetic; add an archived/unarchived notice.
- ⏳ **Directory composite index (S3)** — deferred per plan (EXPLAIN-gated). The roots-only directory sort
  still filesorts on `member_count`; confirm acceptable on a seeded 5k-space lab, add `KEY directory` only
  if EXPLAIN shows it helps.

**Verified clean (no gap):** zero remaining `bn_space_` option reads/writes in Free (only the back-compat
`buddynext_space_option_suffixes` filter loop remains, empty default); all 8 fields migrated; `who_can_post`
enforced (SpacePostGuard); delete clears meta; Pro has no raw `bn_space_meta` left.

## Full verification checklist for the fresh session

1. **Fresh-migration test:** on a DB at schema < 11 (or reset the option), load wp-admin → confirm
   `bn_space_meta` created canonical, the 8 options migrate to meta + delete, `alloptions` has zero
   `bn_space_*` rows. (`maybe_reshape_space_meta` + `maybe_migrate_space_options`.)
2. **T10 move/detach (R-code, verify now):** `PUT /spaces/{child} {parent_id:0}` detaches; `PUT
   {parent_id:<root>}` moves; a move that would exceed depth / form a cycle / hit the cap / lacks manage
   returns 422/403 with the right message; a space WITH children can't be nested.
3. **T11 admin (verify now):** admin Spaces list shows the Pending column (correct counts), Archive →
   row shows Archived + Unarchive, "Sub-space of X" under a child; archive is non-destructive (content
   intact); bulk + delete still work.
4. **Build + verify R1–R3** per their specs.
5. **Release gate:** `/wp-contract-audit` clean; full WPCS/PHPStan/UX-audit; manifest refresh both repos;
   readme changelog (action-prefix) + lockstep Free/Pro bodies; Docker pristine-install smoke of both zips.

## New symbols added this session (for the manifest refresh)
- Free fns: `get/add/update/delete_space_meta`, `buddynext_register_space_field`, `buddynext_get_space_field`
- Free classes: `Spaces\SpaceFieldRegistry`, `Spaces\CoreSpaceFields`
- Free REST: `GET /spaces/fields`, `POST /spaces/{id}/fields`, `GET /spaces/{id}/subspaces`; `GET/PUT /spaces/{id}` extended
- Free service: `SpaceService::get_subspaces/count_subspaces/parent_summary/validate_parent_move`; `FieldType::display_text/rest_value`
- Schema: `bn_space_meta` table; `bn_spaces.parent` + `bn_space_members.space_status` indexes (v11)
- Installer: `maybe_reshape_space_meta`, `maybe_migrate_space_options`, `table_exists`, `index_exists`
