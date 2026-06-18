# Admin Navigation & Discoverability Plan

> Status: PLAN (for review). Goal: make BuddyNext's large admin surface (~31 tabs across Free + Pro, hundreds of options) easy to find + navigate — without forcing a heavy onboarding. Performance-first (see [[performance-first-fast-community]]): prebuilt cached index, client-side filtering, zero new cron.

## What already exists (extend, don't rebuild)
- **AdminHub IA** — every admin tab is registered via `AdminHub::register_tab( section, slug, label, render, args )` with `subtitle` (one-line description), `icon`, `group`, `section`, and a `tab_url()` resolver. This registry is the single source of truth for "what exists and where."
- **Search scaffold** — Settings already renders a `data-bn-admin-search` input ("Search settings, Cmd/Ctrl+K") wired in `assets/js/admin/settings.js` + `assets/css/bn-admin-hub.css`. Currently scoped to the settings page, not global.
- **SetupWizard** — first-run admin onboarding (keep for first-run only).
- **Self-descriptive UX** — every control already ships a label + hint ([[self-descriptive-admin-ux]]).

## Design principles
- **Discoverable + searchable beats a forced wizard.** Step-by-step onboarding has low completion and adds friction; invest in search + a goal map + good in-context hints.
- **Auto-generated from the AdminHub registry** so the guide/search never drift from reality.
- **Fast**: build the index once per request from the in-memory registry (no DB walk per keystroke), cache it, filter client-side. No cron, minimal JS.

## Scope — three parts

### 1. Global command palette (Cmd/K) — highest ROI
- A global overlay reachable from ANY BuddyNext admin page (keyboard `Cmd/Ctrl+K` + a header search affordance), promoting the existing per-page search to site-wide.
- **Index source (v1):** `AdminHub::get_all_tabs()` → each entry = { label, subtitle, section label, url, icon, keywords }. ~31 entries — tiny.
- Typing filters client-side; Enter / click navigates to `tab_url()`. Arrow-key nav, recent/most-used optional later.
- Built server-side once into a JSON blob localized to the palette script (or a tiny REST `GET /admin/nav-index` cached in a transient, busted when tabs change). No per-keystroke server calls.
- **v2 (deep search):** index individual setting FIELDS (label, hint, keywords, owning tab, anchor id) so a query like "stripe key" jumps straight to that field and highlights it. Requires fields to expose searchable metadata — either a light `buddynext_settings_index` filter each settings page contributes to, or scrape rendered field labels into the index at build time.

### 2. "What you can do where" guide page — goal-oriented map
- A single **Guide / Start here** page (its own AdminHub tab) that is a directory, not a wizard:
  - **By goal:** "Monetize your community → Membership (Monetization)", "Moderate content → Moderation / Auto-moderation", "Brand it → White-label / Appearance", "Connect plugins → Integrations", "Set up email → Notifications/Email", "Grow → Onboarding/Engagement"... each row links to the exact tab.
  - **All settings index:** every AdminHub section with its tabs + subtitles + links, grouped — auto-generated from the registry so it stays in sync.
- Optional: a short "Recommended next steps" strip driven by config state (e.g. "No payment gateway configured yet → set one up"), reusing existing recommendation logic where present.

### 3. Contextual help (not a forced flow)
- Keep `SetupWizard` for first-run only.
- Surface AdminHub `subtitle` consistently as the sub-header on every tab (mostly already there) + ensure every field has a hint (enforce [[self-descriptive-admin-ux]] / [[no-empty-options-default]]).
- A small persistent "?" on each section header that deep-links to the relevant Guide section. Dismissible per-user tips (usermeta), no nags.

## Data model / build
- `AdminNavIndex` service (Free, `BuddyNext\Admin\AdminNavIndex`): builds the nav index from `AdminHub` (+ the future field-level `buddynext_settings_index` filter). Cached in a transient keyed by a registry hash; rebuilt only when tabs/fields change (registration happens on `admin_init`, so build lazily + cache). No cron.
- Reuse `assets/js/admin/settings.js` search code; extract the palette into a shared `assets/js/admin/command-palette.js` enqueued on all BN admin pages.

## Phasing
- **P1** — Global Cmd/K palette over AdminHub tabs/sections (reuse the existing scaffold + registry). Ships the "find any screen fast" win.
- **P2** — Guide / Start-here page auto-generated from AdminHub (goal map + all-settings index).
- **P3** — Deep field-level search (per-field metadata + anchors + highlight-on-arrival).
- **P4** — Contextual "?" deep-links + dismissible tips; state-aware "recommended next steps".

## Locked decisions (owner, 2026-06-18)
- **Timing:** QUEUED — build after the membership polish + the cron/AS audit are done. Do not start before then.
- **v1 search scope:** tabs/sections only (P1). Per-field deep search is P3.
- (Remaining choices below default to the recommendations.)

## Open decisions (for owner)
1. **v1 palette scope:** tabs/sections only (fast to ship) vs tabs + setting fields (more useful, needs field metadata). Recommend tabs-only for P1, fields in P3.
2. **Guide surface:** a dedicated top-level "Guide" tab vs a panel on the existing dashboard/overview. Recommend a dedicated tab (also the Cmd/K "Help" target).
3. **Auto-generate the guide from AdminHub** (recommended, stays in sync) vs a hand-curated goal map (nicer copy, drifts). Hybrid: auto for the all-settings index, curated for the by-goal rows.
4. Keep the first-run **SetupWizard** as-is, or fold it into the new Guide? Recommend keep separate (first-run vs always-available reference).

## Non-goals / guardrails
- No forced multi-step onboarding gating the admin.
- No new cron / heavy background indexing (prebuilt + cached, client-side filter).
- No duplicate IA: the AdminHub registry remains the single source of truth.
