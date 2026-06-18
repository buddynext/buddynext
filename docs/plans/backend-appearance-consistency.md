# Backend Appearance — Premium UX Bar (full settings, ux-foundation contract)

> Status: LOCKED (owner decisions 2026-06-18). Scope: **presentation only**.
> Sequence: **by section, Free + Pro together**, worst offenders first.
> Ambition: **set a new premium UX bar** consistent with the whole Wbcom portfolio.

**Goal:** Bring all 32 BuddyNext admin screens (Free + Pro) up to the `ux-foundation`
contract — the portfolio-wide visual + interaction standard — so the backend reads
as one premium product (Linear/Stripe/Notion tier) and matches our other plugins.
Plan the **entire settings surface together**; drop **no** existing feature.

## Governing references (do not invent a parallel system)
- **Contract:** the `ux-foundation` skill — the 16 admin rules, the 6 page patterns,
  the 3-layer tokens, the 6-primitive component vocabulary, responsive/a11y/dark/RTL.
  Every screen is judged against its pre-merge checklist.
- **Code reference:** **Jetonomy (`jt-`)** — the canonical Wbcom admin implementation
  (Settings A, Dashboard B, List C, Wizard E). Check Jetonomy first for any pattern.
- **Visual target (Overview):** the existing `docs/v2 Plans/v2/admin.html` already sets
  a strong bar (sidebar + KPI cards + activity chart + health gauge + top-spaces table
  + quick actions). Keep it; extend the same language to the other screen types.
- **Structure (already built):** `AdminHub::TAB_PLACEMENT` — the full IA is done: every
  tab arranged into 11 visible sections, capped at 5 each. This is the no-drop map.

## No-feature-drop guarantee
Scope is **presentation only** — controls are re-skinned, never removed — so features
cannot be lost by definition. The completeness backstop is the IA inventory below:
every section/tab must have a home in the new language. Cross-check each converted
screen against `TAB_PLACEMENT` and `audit/manifest.json` before marking the section done.

### The full admin surface (11 sections — the inventory)
| Section | Tabs (Pro*) |
|---|---|
| Settings | General · Appearance · Navigation |
| Platform | Features · Integrations · Tools · Webhooks |
| Members | Directory · Labels* · Registration · Roles · Privacy |
| Spaces | Directory · Space settings |
| Engagement | Insights (+Pro analytics) · Social · Reactions* |
| Notifications | Notifications · Email · Templates |
| Realtime & Push* | Realtime · Push · Push prefs |
| Campaigns* | Broadcasts · Drip · Scheduled · AI Feed |
| Moderation | Settings · Pending · Reports · Suspensions · Appeals · Bulk |
| Auto-Moderation* | Rules · AI |
| Monetization* | Tiers · Subscriptions · Stripe · License |

(White-label is intentionally retired/hidden — do not resurface it.)

## BuddyNext screens → the 6 ux-foundation page patterns
- **A — Settings (sidebar + cards):** every `settings:*` tab (General, Appearance,
  Navigation, Features, Integrations, Tools, Roles, Privacy, Social, Reactions,
  Notifications, Email, Realtime, Push, Moderation settings, …). The bulk of the work.
- **B — Dashboard (KPI grid + recent activity):** the Overview (`admin.html`) +
  Insights/Analytics.
- **C — List table (filter row + list):** Members Directory, Spaces Directory,
  Moderation Pending/Reports/Suspensions/Appeals/Bulk, Broadcasts, Drip, Scheduled,
  Member Labels, Mod Rules, Subscriptions, Email Templates.
  **Shared pagination:** every Pattern C list uses ONE pagination primitive
  (`.bn-pager` — result range "1–20 of N", rows-per-page select, windowed prev/next
  with ellipsis). Single component, single place — members, spaces, reports, logs,
  subscriptions, broadcasts, drip, scheduled all consume it (never a bespoke pager).
  Maps to the big-site contract: `LIMIT`+`OFFSET` + `COUNT(*)` + prev/next.
  Demonstrated in `admin-app.html` (`renderPager()`).
- **D — Editor (form + meta sidebar):** Add/Edit Membership tier, Add/Edit Broadcast,
  Add/Edit Drip sequence, Add/Edit Mod rule, Member edit.
- **E — Wizard:** the first-run SetupWizard.
- **F — CPT meta box:** NavMenuMetabox.

## Gaps to close vs the contract (found 2026-06-18)
1. **Rule 9 — single source-of-truth CSS.** BuddyNext ships 7 admin CSS files
   (`bn-admin.css`, `-hub`, `-dialogs`, `-email`, `-members`, `-nav`, `-taxonomy`).
   Consolidate toward one `bn-ui.css` admin vocabulary (or a small, documented set)
   so styles can't drift.
2. **Component vocabulary split.** Two card idioms coexist: `.bn-settings-section` /
   `.bn-ss-*` (AdminPageBase `open_section()`) and `.bn-card` (white-label/membership).
   Converge on the ux-foundation set `.bn-page / -card / -btn / -input / -badge /
   -empty`; make `AdminPageBase` helpers emit those classes (alias the old ones during
   migration). One card, one button ladder, one input, one badge, one empty state.
3. **Raw-WP markup screens (Tier C):** `BroadcastAdmin`, `BulkModAdmin`, `DripAdmin`,
   `MembershipAdmin`, `ModRulesAdmin` use `.wrap`/`form-table`/`postbox`/`card` —
   convert to the primitives + patterns above.
4. **Hand-rolled card HTML (Tier B):** AppearanceTab, RolesTab, ToolsTab, Insights,
   ModerationQueue, the Members sub-managers — route through the shared helpers so
   spacing/markup is identical, not duplicated.

(Already compliant: sidebar nav — Rule 1; Lucide icons via `IconService` — Rule 5;
no-emoji; AdminHub branded chrome — Rule 2. Spot-check, don't rebuild.)

## Phase 0 — lock the language on a flagship (before mass rollout)
The visual bar exists (`admin.html`); Phase 0 makes the *code* primitives match the
contract and nails every screen TYPE once.
1. **Reconcile the component vocabulary** in code: define the single `bn-ui.css`
   primitives (`.bn-page/-card/-btn/-input/-badge/-empty`) per ux-foundation +
   3-layer tokens (Layer 1 theme.json → Layer 2 `--bn-*` → component locals). Map
   `.bn-settings-section`→`.bn-card`. Compare against Jetonomy's `jt-ui.css`.
2. **Build three flagship screens** to the new bar so all patterns are proven:
   - Pattern B — **Settings Overview / Dashboard** (extend `admin.html` language in code).
   - Pattern A — one **Settings tab** (General) using the field-row vocabulary + sticky save bar.
   - Pattern C — one **list screen** (Moderation Pending) with filter row + `.bn-table` + empty state.
3. **Owner sign-off** at 390/430/768/1024/1440px + dark + RTL. Lock. Then D-rollout.

## Rollout — by section, worst offenders first
Each section = one unit: convert its screens to the locked primitives + correct
pattern, then verify the whole section before the next.
1. **Moderation** (Tier C: ModRules, BulkMod) + Auto-Moderation
2. **Campaigns / Email** (Tier C: Broadcast, Drip) + Notifications
3. **Monetization** (Tier C: Membership) + License
4. **Members & Profiles**
5. **Spaces**
6. **Settings · Platform** (General, Appearance, Navigation, Features, Integrations, Tools, Webhooks)
7. **Engagement** (Insights/Analytics, Social, Reactions)
8. **Realtime & Push**
9. **Overview/Dashboard** final polish + cross-section visual pass

## Per-screen workflow
1. Browser "before" (1280 + dark). 2. Pick the pattern (A–F). 3. Replace raw/hand-rolled
markup with the locked primitives + tokens; move any one-off CSS into `bn-ui.css`.
4. `bin/check.sh` (lint/WPCS/PHPStan/UX audit) + `bin/ux-audit.sh`. 5. Browser-verify at
390/430/768/1024/1440 + dark + RTL; screenshot "after". 6. Smoke the form (saves still
work — presentation only). 7. Done only when 4–6 pass (verify-per-item).

## Verification gates (per section)
- `ux-foundation` pre-merge checklist (tokens / components / responsive / a11y / dark /
  RTL / forbidden patterns) fully ticked.
- `wppqa` plugin-dev-rules gate clean (the 16 rules are block gates).
- Five-viewport screenshots + dark + RTL attached; no functional regression.

## Out of scope (separate plans — do not fold in)
- IA reorg — already built (`AdminHub::TAB_PLACEMENT`); nothing to do here.
- Field/content completeness (labels/hints/empty-content, read-but-not-applied options)
  → `docs/plans/backend-settings-completeness-audit.md`.
- Any behaviour/functional change. Markup + styling only.
