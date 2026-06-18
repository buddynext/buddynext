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
- Pro admin convergence: IN PROGRESS (4 parallel refactor passes, WPCS-verified).
- Free minor cleanups (ProfileFieldsManager pill, 2 legacy modifier badges): pending.
- The shared canonical CSS already exists — this is a markup/class refactor only,
  presentation-only (no save/query/handler/nonce/option changes).
