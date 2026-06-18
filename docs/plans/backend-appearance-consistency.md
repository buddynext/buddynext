# Backend Appearance — Premium UX Bar + Consistency Plan

> Status: LOCKED (owner decisions 2026-06-18). Scope: **presentation only**.
> Sequence: **by domain, Free + Pro together**. Worst offenders weighted earliest.
> Ambition (owner, 2026-06-18): **be creative — set a NEW UX bar.** This is a new
> product; the admin is part of the pitch. Not just "consistent" — best-in-class.

**Goal:** Define a premium, distinctive BuddyNext admin design language, then bring
all 32 admin screens (Free + Pro) up to it — so the backend feels like a modern SaaS
dashboard (Linear / Stripe / Notion / Vercel tier), not a WordPress options page.

**Architecture:** The `AdminPageBase` vocabulary (`open_section()` card +
`render_*_row()` fields + `bn-admin*.css` tokens) is the delivery mechanism — every
screen routes through it so the new language applies everywhere from one place. The
creative work is *elevating* that shared layer (Phase 0), then rolling it out
(D1–D8). Hand-rolled and raw-WP markup is deleted in the process. New polish lives in
the shared base/CSS, never per-screen.

**Reference:** today's `docs/v2 Plans/v2/admin.html` is the *starting* baseline. Phase 0
produces the elevated reference (an updated `admin.html` + any new tokens/primitives)
that becomes the new source of truth every screen is judged against.

---

## The new UX bar — creative direction (Phase 0 defines, D1–D8 apply)

Principles that make the admin feel premium rather than "WordPress settings":

- **Spacious, typographic hierarchy.** Generous whitespace, a clear type scale, strong
  section titles with quiet helper text. Density where data lives (tables), air where
  decisions are made (settings).
- **Soft elevation, not boxes.** Cards with subtle shadow + hairline borders + larger
  radius — depth instead of WordPress's flat grey `.postbox`.
- **Purposeful color.** Mostly neutral canvas; brand color reserved for primary action
  + active state. Semantic tones (green/amber/red) only for status. All via tokens.
- **Iconography everywhere.** Lucide SVGs via `IconService` lead every section header
  and nav item — never emoji, never icon-less walls of text.
- **First-class states.** Designed empty states (icon + one line + a primary action),
  skeleton loaders for async, inline success/error — no dead blank panels.
- **Micro-interactions.** Consistent hover/focus rings, smooth (150ms) transitions,
  clear keyboard focus — restrained, not flashy.
- **Dashboard patterns.** A real overview/landing (stat cards + recent activity +
  "next steps"), summary stat pills on list screens, sticky save bars on long forms.
- **Distinctly BuddyNext.** A small, recognisable signature (header treatment, accent,
  section rhythm) so the admin is ownable, not generic Bootstrap-admin.

These are delivered as elevated shared primitives (cards, section headers, stat cards,
tables, empty states, buttons, save bar) — defined once in Phase 0, reused by all.

## Phase 0 — design + lock the bar BEFORE mass rollout

Don't repaint 32 screens twice. First establish the language on a flagship, get
sign-off, then roll out.

1. **Audit the baseline** — screenshot the current admin chrome + 2–3 representative
   screens (a settings form, a list/table, the worst Tier-C screen) at 1280px + dark.
2. **Design the elevated language** — produce the new `admin.html` reference (chrome,
   card, section header, stat card, table, empty state, button set, save bar, tabs)
   using only tokens; add any new tokens/primitives to `tokens.css` + `bn-admin*.css`.
3. **Build a flagship screen for real** — pick ONE high-visibility screen (recommend
   the **Settings overview / dashboard** or **Membership**) and implement it fully to
   the new bar in code.
4. **Owner sign-off** — review the flagship at 1280px + 390px + dark. Lock the look.
   Only then do D1–D8 roll it out. (Re-do here is cheap; re-do across 32 screens is not.)

Output of Phase 0: the locked reference + the elevated `AdminPageBase` helpers/CSS
that D1–D8 simply consume.

## The standard — definition of a "conformant" screen (post-Phase-0)

A screen is DONE when all of these hold (this is the per-screen checklist):

1. **Chrome** — page lives under AdminHub; title + one-line subtitle via the Hub /
   `render_page_header()`; tabbed screens use `render_tab_bar()` + tab panels.
2. **Sections** — every group of controls is a `.bn-settings-section` card via
   `open_section()` / `close_section()`. No raw `.wrap`, `<table class="form-table">`,
   `.postbox`, or core `.card` markup.
3. **Fields** — every control rendered through the shared helper that fits it
   (`render_toggle_row`, `render_text_row`, `render_password_row`, `render_color_row`,
   `render_textarea_row`, `render_number_row`, `render_select_row`). No hand-written
   field-row HTML duplicating what a helper already emits.
4. **Actions** — primary save via `render_save_bar()`; buttons use the shared button
   vocabulary (`.bn-btn[data-variant]`), not bare `submit_button()` styling islands.
5. **Tables / lists** — list screens use the shared `.bn-table` styling; rows stack
   under 640px.
6. **Tokens only** — colors/spacing/typography from `--bn-*` / `--*` tokens; zero raw
   hex / px / font-family in markup or inline styles (passes `bin/ux-audit.sh`).
7. **Dark mode** — correct under `[data-bn-theme="dark"]` / `[data-bx-mode="dark"]`.
8. **Mobile** — usable at 390px (no horizontal scroll; controls stack).
9. **RTL** — `margin-inline-*` / logical properties, not `margin-left/right`.
10. **No inline `<style>` / `<script>`** in PHP (passes `bin/ux-audit.sh` F1/F2).
11. **Iconography** — each section header leads with a Lucide SVG via `IconService`;
    no emoji, no icon-less headers.
12. **States** — async surfaces have a skeleton/loading state; empty data shows a
    designed empty state (icon + one line + primary action), not a blank panel.
13. **Polish** — soft elevation (shadow + hairline + radius from tokens), consistent
    hover + visible keyboard focus rings, 150ms transitions; long forms get a sticky
    save bar; list screens get summary stat pills.

**Explicitly OUT of scope** (separate efforts — do not fold in):
- IA reorg / moving tabs into domain sections → `docs/plans/admin-navigation-discoverability.md` + the admin-settings-ia-reorg plan.
- Field/content completeness (label+hint, empty/loading/error states, settings that read-but-never-apply) → `docs/plans/backend-settings-completeness-audit.md`.
- Any functional/behaviour change. This epic changes markup + styling only.

---

## Current adoption tiers (from the 2026-06-18 survey)

- **Tier C — raw WP markup (worst, fix first):** `BroadcastAdmin`, `BulkModAdmin`,
  `DripAdmin`, `MembershipAdmin`, `ModRulesAdmin` (all Pro).
- **Tier B — hand-rolled `.bn-settings-section` HTML (looks close, drifts):**
  `AppearanceTab`, `RolesTab`, `ToolsTab`, `Insights`, `ModerationQueue`,
  `AvatarSettings`, `InviteManager`, `MemberEditForm`, `MemberTypesManager`,
  `ProfileFieldsManager`, `MembershipAdmin` (mixed).
- **Tier A — conformant (spot-check only):** `Settings`, `Members`, `Spaces`,
  `NavManager`, `AIAdmin`, `AIModAdmin`, `AnalyticsAdmin`, `CustomReactionsAdmin`,
  `MemberLabelsAdmin`, `PushAdmin`, `PushPrefsAdmin`, `RealtimeAdmin`,
  `ScheduledPostsAdmin`, `WhiteLabelAdmin`, `SpaceBrandAdmin`, `AdvancedFieldsAdmin`.

---

## Domain sequence (Free + Pro together; worst offenders first)

Each domain is one work unit: convert its screens to the standard, then browser-verify
the whole domain at 1280px + 390px + dark before moving on.

### D1. Moderation  *(has 2 Tier-C screens)*
- Free: `ModerationQueue` (Tier B)
- Pro: `ModRulesAdmin` (C), `BulkModAdmin` (C), `AIModAdmin` (A, spot-check)
- Tasks: convert ModRules + BulkMod from `form-table`/`.wrap` to `open_section()` +
  `render_*_row()` + `.bn-table`; de-dup ModerationQueue's hand-rolled cards onto helpers.

### D2. Email & Notifications  *(has 2 Tier-C screens)*
- Free: `EmailEditor`
- Pro: `BroadcastAdmin` (C), `DripAdmin` (C), `PushAdmin` (A), `PushPrefsAdmin` (A)
- Tasks: convert Broadcast + Drip to the card/field/table vocabulary; align EmailEditor;
  spot-check Push screens.

### D3. Monetization / Membership  *(1 Tier-C screen)*
- Pro: `MembershipAdmin` (C/mixed)
- Tasks: convert the plan-list + add/edit form fully onto `open_section()` +
  `render_*_row()` + `.bn-table`; remove `.wrap`/`form-table` islands. (Note: the tier
  card grid presentation from the membership epic stays — just rehome it in the system.)

### D4. Members & Profiles
- Free: `Members` (A), `MemberEditForm` (B), `MemberTypesManager` (B),
  `ProfileFieldsManager` (B), `AvatarSettings` (B), `InviteManager` (B)
- Pro: `MemberLabelsAdmin` (A), `AdvancedFieldsAdmin` (A)
- Tasks: route the five Tier-B sub-managers' hand-rolled cards through the helpers.

### D5. Spaces
- Free: `Spaces` (A)
- Pro: `SpaceBrandAdmin` (A)
- Tasks: spot-check + fix any drift; verify the per-space Brand tab matches.

### D6. Settings core, Tools, Roles, Appearance, Insights
- Free: `Settings` (A), `ToolsTab` (B), `RolesTab` (B), `AppearanceTab` (B),
  `Insights` (B)
- Tasks: de-dup the four Tier-B screens onto `open_section()` + `render_*_row()`
  (ToolsTab's hand-rolled sections incl. the new Background-tasks card).

### D7. Navigation
- Free: `NavManager` (A), `NavMenuMetabox` (its own metabox context)
- Tasks: spot-check; ensure the metabox uses tokens + dark mode.

### D8. Engagement / Feed / Realtime / Analytics  *(all Tier A — spot-check pass)*
- Pro: `CustomReactionsAdmin`, `ScheduledPostsAdmin`, `AIAdmin`, `RealtimeAdmin`,
  `AnalyticsAdmin`
- Tasks: spot-check each against the standard; fix only real drift.

---

## Per-screen workflow (repeat for every screen)

1. Open the screen in the browser (Playwright MCP) at 1280px — screenshot the
   "before".
2. Map its controls to the standard: which sections, which field helper per control.
3. Replace raw/hand-rolled markup with `open_section()` + `render_*_row()` +
   `render_save_bar()`; move any one-off CSS into the shared `bn-admin*.css` using
   tokens (never inline, never per-screen hex/px).
4. `bin/ux-audit.sh` on the file (tokens/inline/hex gate) + WPCS.
5. Browser-verify at 1280px + 390px + dark mode — screenshot "after"; confirm it
   matches `admin.html` and the rest of its domain.
6. Mark the screen done only when step 5 passes (verify-per-item rule).

After a domain's screens are all done: one domain-wide visual pass (do D's screens
look like each other?) before starting the next domain.

---

## Verification gates (per domain)
- `bin/check.sh` (PHP lint, WPCS, PHPStan, UX audit) clean on changed files.
- Browser: every changed screen at 1280px + 390px + dark, matching `admin.html`.
- No functional regression (settings still save — smoke each converted form once).

## Risks / guardrails
- **Presentation only** — if a screen's markup is entangled with behaviour, change
  markup without altering the save/handler path; smoke-test the form after.
- **No new component per screen** — a genuine gap becomes a new shared helper on
  `AdminPageBase` (or a shared partial), reused everywhere.
- **Pro extends Free** — Pro admin screens use Free's `AdminPageBase`; if a helper
  is missing for a Pro need, add it to Free's base, not a Pro copy.
