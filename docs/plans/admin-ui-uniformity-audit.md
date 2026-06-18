# Admin UI Uniformity Audit (code-level)

> Goal: every admin screen uses the SAME shared component classes — no bespoke
> or WordPress-native markup. Audited 2026-06-18 by grepping all admin PHP.
> Verified in code (php -l + WPCS), not by per-screen screenshots.

## Canonical component vocabulary (the "v2 attribute API")
Defined in Free `assets/css/bn-base.css` (shared, loaded in admin via `bn-base`
dependency) + the admin chrome in `bn-admin*.css`. Gold-standard example:
Free `includes/Admin/Members.php`.

| Component | Canonical markup |
|---|---|
| Button | `<button class="bn-btn" data-variant="primary\|secondary\|danger">` |
| Status badge | `<span class="bn-badge" data-tone="success\|warn\|danger\|neutral\|info">` |
| Section card | `AdminPageBase::open_section()` → `.bn-settings-section` (+ `.bn-ss-header/-body`) |
| Field rows | `render_toggle_row / render_text_row / render_select_row / …` |
| List/queue table | `.bn-admin-hub__content table` (uniform header/row/hover; excludes WP `.form-table`) |
| Page chrome | `.bn-admin-hub` (brand bar + unified left nav panel + content) |
| Pagination | `AdminPageBase::render_pagination()` |

Tone map (status → tone): active/enabled/sent/published/connected/on → success ;
pending/scheduled/sending/draft/queued/trialing/past_due → warn ;
expired/cancelled/disabled/failed/removed/suspended → danger ; otherwise neutral.

## Audit result
- **Free admin — CANONICAL.** Members, Settings, Spaces, ModerationQueue, EmailEditor,
  NavManager, Invite/MemberTypes/MemberDisplay all use `.bn-badge[data-tone]` + the
  card/field helpers. (Minor: `ProfileFieldsManager` uses a `bn-pf-type-pill`;
  two legacy `.bn-badge-active/-suspended` modifier usages remain → migrate to `data-tone`.)
- **Pro admin — DIVERGENT (the "here and there").** Fixing:
  - WP-native buttons (`button button-primary/secondary/small/link-delete`) in 10 screens:
    Broadcast, Drip, Scheduled, ModRules, BulkMod, AIMod, MemberLabels, Push, Realtime,
    Membership → `class="bn-btn" data-variant="…"`.
  - Bespoke status pills → `.bn-badge[data-tone]`: MembershipAdmin (`bnpro-status-pill`,
    plan cards), AiClientBridge (`bn-ai-status`), ProfileViewsWidget (`*-pills`).
  - Raw user IDs in tables (e.g. Subscriptions shows `539`) → user display name.

## Fix status
- Pro admin convergence: DONE (4 parallel refactor passes, WPCS-verified, committed).
- Free legacy badges: DONE — `Spaces.php` + `MemberTypesManager.php` Yes/No/On/Off
  pills and `ProfileFieldsManager` field-type pill all migrated to
  `bn-badge[data-tone]` (Yes/On → success, No/Off/type → neutral). An explicit
  `data-tone="neutral"` alias was added to `bn-base.css` so the documented API is
  real. Role badges (`bn-badge-role-*`, MemberDisplay) intentionally kept — a
  role-colour taxonomy, not a status divergence.
- Dead CSS removed: `.bn-badge-active/-suspended/-open/-private/-secret`
  (bn-admin.css) and `.bn-pf-type-pill` (bn-admin-members.css) — zero remaining
  references; no duplicate/dead selectors left (verified by sweep).
- Section-spacing uniformity: `.bn-av-section-desc` was defined only in the
  page-specific `bn-admin-members.css`, so Tools / Appearance / Roles / Demo (which
  don't load it) fell back to the browser's default `<p>` margin — the visible
  "gap" / "two different patterns". Moved into the always-loaded `bn-admin.css`
  (single definition) so every tab shares identical section spacing.
- Buttons: DONE — full migration of every Free admin button to the v2
  `bn-btn[data-variant]` API (primary/secondary/ghost/danger + `data-size="sm"`),
  including the canonical `render_save_bar()`, the General "recommended" banner,
  Tools/Appearance/Roles/Demo/Members/Invites/Avatar/ModerationQueue and the
  ModerationQueue pager + `action_form()` helper. `submit_button()` calls became
  real `<button type="submit">`; the dropped `name="submit"` is unused (no handler
  reads it). All JS hooks preserved (`bn-cancel-add-tab`, `data-bn-pf-toggle*`,
  `#bn-pick-avatar/#bn-pick-cover`) and all multi-submit `name`/`value` keys
  (`bn_reset`, `bn_remove_default_avatar/cover`) intact. Only WP-native button kept:
  `NavMenuMetabox` (WP core nav-menu JS depends on `.button.submit-add-to-menu`).
  Dead `.bn-btn-save` / `input.bn-btn-save.button-primary` CSS removed. bn-base.css
  legacy `.bn-btn-primary/-secondary/-ghost/-danger` classes kept (frontend uses
  them via spaces store.js).
- Member Types tab reordered to match Spaces → Categories: "Defined Types" list
  first, then the add/edit form below, anchored `#bn-member-type-form` (row Edit
  links scroll to it).
- Section spacing: `.bn-av-section-desc` moved to the always-loaded `bn-admin.css`
  so Tools/Appearance/Roles/Demo no longer fall back to the browser's default
  `<p>` margin (the "two different patterns" gap). Verified `margin-top: 0`.
- Nav panel scrollbar made subtle (hover-revealed, `scrollbar-gutter: stable`).
- All changes presentation-only (no save/query/handler/nonce/option changes).
  Verified: phpcs 0 errors / 0 warnings on all 14 files; browser-confirmed a
  Settings-API save ("Settings saved.") and an admin-post action ("flushed")
  both work end-to-end through the converted buttons.
