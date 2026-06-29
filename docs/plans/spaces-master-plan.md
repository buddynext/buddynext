# Spaces — 1.0.4 plan, status & handoff (single source of truth)

One file for the whole Spaces component: what's done, what's pending, how to verify, and the build-specs
for the remaining UI. Members work lives in `members-profile-improvements.md`. Both repos on `1.0.4`,
Free schema **v11**. Last updated 2026-06-29 (end of Spaces build session).

**Standing rules:** 100% app-ready (REST `buddynext/v1` is the primary surface; web UI is an Interactivity
client of the same REST, never a server-only POST). New per-space attribute = `bn_space_meta` row, never a
column or autoloaded option. Reuse canonical patterns (FieldType engine, Nav render seam, role-rank
threshold) — no dup. Verify per item: data-flow (DB) + browser, all states.

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
| **R1** Nav UX | ⏳ PENDING | breadcrumb + Sub-spaces section + Add-CTA |
| **R2** member panel → REST | ⏳ PENDING | convert legacy POST to Interactivity/REST |
| **R3** web field panel | ⏳ PENDING | render registered fields on settings screen |
| **R4** search-fold (optional) | ⏳ PENDING | public+searchable fields → `bn_search_index` |

All ✅/🟡 are phpcs (0 errors) + PHPStan L5 clean. Local DB already migrated to v11; reset
`buddynext_schema_version` + reload wp-admin to re-run a fresh migration.

---

## REMAINING — frontend panels (build + browser-verify next session)

Not shipped blind: these are Interactivity rewrites of currently-working surfaces; the REST they consume
is already built + proven, so they are pure UI.

### R1 — Nav UX: breadcrumb + "Sub-spaces" section + Add-CTA
- **Breadcrumb** on a sub-space home: `Parent ▸ This space` from `GET /spaces/{id}` `parent{id,name,slug}`.
  Link parent → `buddynext_space_url($parent.slug)`. Place in the space header / SpaceNav render seam.
- **"Sub-spaces" section** on a parent home: list children from `GET /spaces/{id}/subspaces` (reuse
  `bn-space-card`/`my-spaces` markup); show only when `subspace_count > 0`. Build as a **SpaceNav provider
  panel** (mirror `includes/Nav/Providers/SpaceNav.php` `render_*_panel`) — use the 1.0.4 render seam.
- **"Add sub-space" CTA**: visible to anyone who manages the parent (owner OR moderator —
  `permissions->can($uid,'buddynext-manage-space',['space_id'=>$parent])`). Opens the existing create
  modal pre-seeded with `parent_id` (note the modal's parent picker is owner-only via `owned_root_spaces` —
  allow managers).
- Verify: breadcrumb on a child; parent section + correct count; CTA only for managers; create-from-CTA
  sets `parent_id`; 390px + dark.

### R2 — member-management panel → REST/Interactivity
- Convert `templates/parts/space-settings-panel-members.php` (legacy full-page POST in `settings.php`) to an
  Interactivity store calling the existing `buddynext/v1` member routes (promote/demote/remove/ban/invite
  all exist), optimistic UI + toasts, aligned with the Nav render seam.
- Verify: each action fires REST, updates roster without reload, toast; multi-actor states; 390px + dark.

### R3 — web field-management panel
- Render the registered fields on the space settings screen from `GET /spaces/{id}` `fields[]` +
  `GET /spaces/fields`, saving via `POST /spaces/{id}/fields`. Reuse `FieldType::render_input()` server-side
  for initial markup, drive saves over REST. Replaces per-tab hardcoded forms for registered fields.
- Verify: 8 built-in fields render in their sections with current values; save persists to `bn_space_meta`;
  invalid value shows the per-field 422 inline; 390px + dark.

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
