# BuddyNext — Full QA + Stress Test Report

Run against the local `buddynext-dev` site (PHP 8.2, 18 seeded members). Covers
the skills-plan static suite (contract audit, WPCS, PHPStan, wppqa bug-finder),
a runtime boot/smoke, and a REST stress/load pass.

## Summary

| Gate | Result |
|---|---|
| Contract audit (free ↔ pro) | Clean — 0 errors, 0 warnings (75 baselined) |
| PHPStan (level 5) | **31 → 0** errors — real fixes + documented externals |
| WPCS (changed code) | 0 errors in all code touched this pass |
| wppqa REST↔JS contract | 152 passed, 0 failed |
| wppqa wiring | 42 flagged — all verified false positives |
| wppqa dev-rules | 2 real `confirm()` fixed; 11 remaining all false positives |
| Runtime smoke | Active + boots; 192 REST routes; 5 key pages 200/no-fatal |
| Stress / load | Members N+1 **99 → 19 queries (flat)**; 100/100 200 under load |

## Static analysis

### PHPStan — 31 → 0 (real fixes, not blanket-baselined)
- **AdminHub tab registry (10 errors):** the `$tabs` `@var`, `get_tabs()` return,
  and `render_sidebar()` `@param` declared a narrow `{label,render,cap}` shape
  while `register_tab()` actually stores `position/layout/badge/icon/group/order`.
  Fixed with a `@phpstan-type BnAdminTab` alias — resolved the offset-access,
  return-type, and `if/booleanAnd.alwaysFalse` cluster (the group-eyebrow code
  was live, only the type hid it).
- **ProfileFieldsManager (2):** removed dead `FIELD_TYPES` / `CHOICE_TYPES`
  constants (superseded by `field_type_matrix()` / `field_types()`).
- **WP_CLI (12) + `buddynext_space_url` (1):** added a minimal `WP_CLI` stub and
  the root-file helper stub to `phpstan-bootstrap.php` (both live outside the
  analysed `includes/` tree).
- **WPMediaVerse bridge (3 stale ignores):** removed — `MVS_PLUGIN_DIR` is no
  longer referenced and the `defined()`-guarded constants no longer error.
- **MediaClient (2) + AssetIsolation (1):** baselined with reasons — external
  WPMediaVerse class guard and a defensive `is_string()` on filter output.

### WPCS
- All code changed this pass is WPCS-clean. `AdminHub.php` was additionally
  improved 35 → 7 (phpcbf, formatting only); the 7 remaining and the
  ProfileFieldsManager sanitization notices are pre-existing false positives
  (input runs through `wp_unslash()` + a custom `sanitize_field_options()`).

## wppqa bug-finder

- **REST↔JS contract:** clean (152/0) — no envelope-shape drift.
- **Wiring (42 "half-wired"):** all false positives. The check matches admin POST
  field `name="bn_*"` attributes against `templates/`, but the real option keys
  are `buddynext_*` and consumers live in the service/theme layer (verified:
  logo→rail, custom CSS + default theme→Theme\Appearance, white-label→AdminHub).
- **dev-rules (13 → 11):**
  - **Fixed:** 2 native `window.confirm()` (Rule 10) — admin webhook removal now
    uses `window.bnConfirm()`; messages `leaveGroup` now `yield bnConfirm()` from
    `shell/dialog.js` (registered `@buddynext/messages` as a shell-dialog consumer).
  - **Remaining 11 (all false positives):** 6 nonce-without-cap in
    `spaces/settings.php` (page-gated by `buddynext_can('manage-settings')` at the
    top), 4 in `ProfileFieldsManager` (each handler does `current_user_can()`
    immediately before the nonce), 1 `$_GET` iteration on a read-only search form
    (per-key `sanitize_key`/`sanitize_text_field`).

## Runtime smoke
- Plugin active, boots clean (`buddynext_service()` present).
- All changed PHP files: no syntax errors.
- REST: `buddynext/v1` + `buddynext-pro/v1` registered, **192 routes**.
- Frontend `/`, `/members/`, `/messages/`, `/activity/`, `/spaces/`: HTTP 200, 0 fatals.

## Stress / load

### N+1 fix — member directory (the headline finding)
`GET /buddynext/v1/members?per_page=20` issued **99 queries for 20 members**
(~5/member): per-row `get_user_by`, `get_user_meta`, `is_following`,
connection `status`, `is_blocking_either`, plus `get_avatar_url` (user lookup)
and `is_user_online` (restrict check) inside the directory service.

Fixed by priming once per page instead of per row:
- `MemberDirectoryService`: `cache_users()` + `update_meta_cache('user', $ids)`
  before the row map; `BlockService::prime_restricted_cache()` to warm the
  viewer↔peer restrict cache.
- `MemberDirectoryController`: pre-fetch the viewer's following set, and two new
  batch helpers — `ConnectionService::statuses_for()` and
  `BlockService::blocking_either_map()` — replace the per-row calls.

| | per_page=10 | per_page=20 | per_page=50 |
|---|---|---|---|
| Queries (after) | 18 | 13 | 19 |
| Before (per_page=20) | — | **99** | — |

Query count is now **flat** — verified at 217 members (12× volume) it stays
~13-19 regardless of page size (was scaling ~5/member). Behavior verified
identical to the per-pair path: all `is_following` values and direction-aware
connection states (pending-sent / pending-received / accepted) match ground truth.

### Concurrency
100 requests at 25-way concurrency per endpoint (members, spaces, search,
space-categories): **100/100 HTTP 200, zero errors**, p50 ~0.35s / p95 ~0.40s /
max <0.5s — flat tail, no lock contention.

### Other endpoints
All other hot reads are already lean (spaces 1 query, categories 1, suggestions
4, search 20). `GET /reactions` returns HTTP 400 on a bare call — **correct**:
`object_type` + `object_id` are `required` params.

## Follow-ups (not blocking)
- Browser-verify the two `confirm()` → modal swaps in situ (admin webhook remove;
  messages leave-group) — both reuse already-proven `bnConfirm` helpers.
- Pre-existing WPCS formatting/i18n in `AdminHub` (7) and a sanitization false
  positive in `ProfileFieldsManager` remain; cosmetic, out of this pass's scope.
