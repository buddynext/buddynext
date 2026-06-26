# CLAUDE.md — BuddyNext

> **RESUMING WORK?** Start with [`docs/qa/RESUME.md`](docs/qa/RESUME.md) — open tasks, environment quirks, resumption recipe. Last session summary: [`docs/qa/SESSION-2026-05-22.md`](docs/qa/SESSION-2026-05-22.md).
>
> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory — 135 REST endpoints, 39 tables, 21 capabilities, 55 services, 548 plugin-own hooks fired. Use this before grepping. See also [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`audit/CODE_FLOWS.md`](audit/CODE_FLOWS.md), [`audit/ROLE_MATRIX.md`](audit/ROLE_MATRIX.md). Refresh after non-trivial changes.

## What Is BuddyNext

Enterprise-grade social community platform for WordPress (free + pro). Owned by Wbcom Designs.

- **Site URL (local):** http://buddynext-dev.local
- **Plugin path:** `wp-content/plugins/buddynext/`
- **Namespace:** `BuddyNext\*` (free) / `BuddyNextPro\*` (pro)
- **REST namespaces:** `buddynext/v1` (free) · `buddynext-pro/v1` (pro)
- **Bootstrap hook:** `plugins_loaded:15` → `BuddyNext\Core\Plugin::init()` → fires `buddynext_loaded`
- **PHP:** 8.1+ · **WP:** 6.9+ (Abilities API required)

---

## Recent Changes

| Date | Branch | Change |
|---|---|---|
| 2026-06-25 | 1.0.3 | Profile media uploads + albums + fixes. New `includes/Media/MediaController.php` adds owner-gated `buddynext/v1` endpoints (`POST/GET/DELETE /me/media`, `GET /users/{id}/media`, album list/create/detail/add/remove/update/delete/reorder), consuming WPMediaVerse through the `MediaClient` service seam only (no engine REST, no MV css/js on BN screens). New `buddynext/media` (upload) + `buddynext/media-albums` islands, `templates/partials/media-tab.php` + `media-upload-composer.php`, `assets/css/bn-media-upload.css`, and a shared `assets/js/media/upload-core.js` (unified validation + 256px `makeThumb` reused by the Media tab, feed composer, and DM preview). `Galleries` gains album read helpers; `MediaClient` gains `albums()`/`privacy()` accessors + `default_video_poster()`. Owner-only writes; private albums hidden in list AND 404 on the detail endpoint for non-owners. Also fixed: display_name reverting to the login slug on blur (controlled input in `profile-edit-hero.php` + `syncNameField`), and posterless videos showing a black tile (`MediaUrlResolver` poster fallback). Two WP Interactivity lessons applied: seed EVERY context key in the initial `data-wp-context` (proxies don't track keys added after hydration) and `data-wp-bind--value` does not drive a `<select>` (set imperatively). |
| 2026-06-25 | 1.0.3 | Scale audit on a 100k-user Docker lab under the Reign host theme. Fixed 7 bugs (commit `bf7f6811`): comment edit/delete by non-owner 500→403 (+404/400); member-directory result cache now busts on block/unblock via a per-viewer version salt (`MemberDirectoryService`); announcement dismiss/end now bust the page-1 home-feed cache (`FeedService::flush_home_cache`/`flush_all_home_caches`); type-scoped search normalizes plural→singular `object_type` (`SearchService::normalize_object_type`); appeal decide writes both audit column pairs; member warning writes a single `bn_mod_log` row; all `buddynext_post_created` listeners default trailing params so a 2-arg `do_action` can't fatal. Added regression tests (full free suite 1194 passing) and refreshed 9 `docs/journeys/` runbooks to match 1.0.3. |

---

## Product Scope & Validity Bar — Judge Every Request Against This

**Model = mainstream social: Facebook, X (Twitter), LinkedIn.** We are NOT building a complex or niche community. If a request adds complexity those platforms don't have, it is out of scope by default. (The UX-parity bar is separately noted under *Premium UX* and *Design System Tokens* — this section is about deciding what is a real defect / in-scope ask in the first place.)

**The bar for whether a bug report, feature ask, or QA card is VALID is two questions — not what QA prefers:**

1. Would a **mass end user (member)** genuinely expect this, or be broken/confused by the current behavior?
2. Would the **typical site owner** — one of 1000s of installs — expect this fixed or changed?

Those two expectations are the boundary. **QA / tester cards are suggestions to be checked, not verdicts.** A card may be:

- **Invalid** — a subjective nitpick no real member or owner would notice, OR behavior that actually matches what Facebook/X/LinkedIn do (so the current behavior is already correct).
- **Duplicate** — already filed or already covered by another card/fix.
- **Not aligned with the plan** — asks for complexity beyond a mainstream-social product.

Only what genuinely falls short of mass end-user / site-owner expectation becomes a real Bug.

**When triaging Possible Bug → Bugs:** verify every code-claim card against the current code (don't trust the report); reject by-design / already-fixed / duplicate cards with concrete evidence (file:line or commit); label each valid bug with a `[Type]` prefix in the title (`[Functional]` / `[UI]` / `[Security]` / `[Data]` / `[Nav]` / `[Perf]` / `[A11y]` …); never over-build beyond what the model platforms do. See also the QA role + "cards are suggestive" guidance in the session memory.

### Cards are entry points — verify the whole screen + flow, not the one line

A QA/Basecamp card is a **random entry point**, not the scope. The real job is to open the screen the card points at and verify the **entire surface + its code flow end-to-end** — every state, the wiring behind each control, the language, the presentation, the empty/error/loading states — to **premium, Facebook/Instagram-grade quality**. We are building what a member expects a modern social community to be (own your community, but at the polish of FB/IG/X/LinkedIn): clean copy, proper spacing/alignment, real-time-feeling UX, and **fully-wired functionality that holds up at large-community scale**.

Practical consequences:
- **Build the complete flow, never a half page.** If a card names one field on a multi-step flow (e.g. an appeal form, onboarding step, checkout), wire the whole flow so it actually works — don't ship a fragment that looks done but dead-ends.
- **While on a screen for one card, fix what's obviously substandard on it** (copy, alignment, missing wiring, dead links, broken states) even if the card didn't list it — that's the point of the entry-point model. (Stay within the mainstream-social scope above; don't invent niche features.)
- **Verify behaviour, not just render:** click every control, confirm the server side fires, check 390px + dark + empty/error states on member-facing surfaces.

---

## Developer-Friendly from Day 1 — Boundary Skills + Local Tooling

BuddyNext leans on two canonical skills for engineering standards. They are the source of truth — this file mirrors them where useful but never duplicates their rules.

| Skill | What it owns |
|---|---|
| `/wp-plugin-development` | Hook ownership, REST patterns, DB schema, security (nonces + caps), admin UI conventions, Lucide icon rule, escape/sanitize rules, enqueue + inline-style patterns, PHPDoc + WPCS sniff config. |
| `/ux-audit` | Token + primitive compliance, cross-plugin duplication detection, the per-plugin audit script, the naming contract (`.bn-*` prefix + attribute API). |

**Invoke them when relevant.** When writing a REST controller, ask the `/wp-plugin-development` skill what it requires. When adding a new component or CSS token, ask `/ux-audit`. The v2 design source (`docs/v2 Plans/`) is BuddyNext's specialisation on top of the `/ux-audit` foundation.

**Admin UI uniformity (options, nav icons, cards):** the normative standard lives at [`docs/standards/admin-ui-uniformity.md`](docs/standards/admin-ui-uniformity.md) (v1.0). One input look for every option (add fields via `AdminPageBase::render_*_row()`; the `.bn-admin-hub` baseline + `--bn-a-input-*` / `--bn-a-focus-ring` tokens converge any control); every nav section/tab carries a vendored Lucide icon via `IconService`; content cards share the kicker + clamped-title/snippet + footer anatomy with a `min-height` floor. Follow it and new admin screens are uniform by construction.

**Frontend interactivity & client-side navigation:** the normative standard lives at [`docs/standards/frontend-interactivity.md`](docs/standards/frontend-interactivity.md) (v1.0; reference impl Jetonomy 1.5.0). All frontend REST goes through the shared `restFetch` client (`assets/js/shell/rest-client.js`); imperative init is bound via `onNavReady()` (`assets/js/shell/nav-init.js`) so it survives a client-side swap; the router region + navigate action live in `assets/js/shell/navigate.js` and `templates/shell/hub-shell.php` behind the `buddynext_client_nav_enabled` filter (default off — staged activation per surface). See [`docs/plans/archive/frontend-interactivity-adoption.md`](docs/plans/archive/frontend-interactivity-adoption.md) for the full plan + status.

**Scale, caching & REST boundary (free + pro):** three normative standards govern large-site readiness — [`docs/standards/CACHING.md`](docs/standards/CACHING.md) (per-service `CACHE_GROUP`/`CACHE_TTL` + key-based bust; **cache by access frequency, not by existence**; Pro converges on `buddynextpro_<domain>`), [`docs/standards/DATA-AT-SCALE.md`](docs/standards/DATA-AT-SCALE.md) (no autoload bloat, sargable filters, bounded reads, AS-batched high-volume writes, keyset pagination), and [`docs/standards/REST-API-BOUNDARY.md`](docs/standards/REST-API-BOUNDARY.md) (100% REST, CI-gated). The triaged, code-verified change list to bring both repos to standard is [`docs/plans/scale-readiness-change-index.md`](docs/plans/scale-readiness-change-index.md) (DO-NOW/DEFER/SKIP); rationale in [`docs/plans/scale-readiness-100k.md`](docs/plans/scale-readiness-100k.md). These are portable Wbcom standards — apply them uniformly across free and pro.

### Local tooling (vendored in this repo — run from the repo root)

| Command | Purpose |
|---|---|
| `bin/check.sh` | Full CI-parity gate: PHP lint, WPCS, PHPStan level 5, UX audit. Run before pushing. |
| `bin/check.sh --staged` | Same gate scoped to staged files only — fast pre-commit signal. |
| `bin/check.sh --skip-audit` | Skip the UX audit step (useful when iterating on PHP only). |
| `bin/ux-audit.sh` | Standalone UX audit (token + primitive compliance, inline-style/script detection). Vendored from `/ux-audit`'s `templates/ux-audit.sh`. |

### Pre-commit hook (one-time setup per clone)

```bash
git config core.hooksPath .githooks
```

`.githooks/pre-commit` runs `bin/check.sh --staged --skip-audit`. Use `git commit --no-verify` only in emergencies.

### Quality gates anchored to skills

| Gate | Source skill | How to run |
|---|---|---|
| WPCS clean | `/wp-plugin-development` Part 8 | `vendor/bin/phpcs` or `mcp__wpcs__wpcs_check_file` |
| PHPStan level 5 | `/wp-plugin-development` | `vendor/bin/phpstan analyse` |
| Token + primitive compliance | `/ux-audit` | `bin/ux-audit.sh` |
| No raw hex / px / font-family outside `:root` | `/ux-audit` | `bin/ux-audit.sh` F3 rule |
| No inline `<style>` / `<script>` in PHP | `/ux-audit` | `bin/ux-audit.sh` F1 + F2 rules |
| No native `alert()` / `confirm()` | `/ux-audit` | `bin/ux-audit.sh` F8 rule |
| v2 token + primitive vocabulary | This repo + `docs/v2 Plans/` | `bin/ux-audit.sh` + 6 uniformity gates in `docs/v2 Plans/PLAN.md` Part 4 |
| 100% REST frontend (no admin-ajax) | This repo + `/wp-plugin-development` | `bin/check-rest-boundary.sh` — see `docs/specs/REST-FRONTEND-CONTRACT.md` |

If a section below conflicts with one of the boundary skills, the skill wins — file an issue and the matching section here gets corrected.

---

## Non-Negotiable Standards — Read Before Every Task

### 1. Enterprise Code Quality — No Shortcuts

- Every file must pass WPCS before committing. Run `mcp__wpcs__wpcs_check_file` on every PHP file you write or modify.
- Every class must pass PHPStan level 5+. Run `mcp__wpcs__wpcs_phpstan_check` after writing new classes.
- No `@todo`, no stub implementations, no `/* TODO */` — ship complete code or don't ship.
- **Zero AI markers** — no `// Generated by`, no `// AI-assisted`, no `// Claude`, no `// This code was...`, no `@generated`. Code reads as if written by a senior WordPress engineer. No exceptions.
- No `echo` in production paths — use `wp_send_json_*`, templates, or REST responses.
- All DB queries use `$wpdb->prepare()`. Zero raw interpolation.
- All nonces validated on every state-changing request.
- Capabilities checked on every admin and REST endpoint.
- Sanitize input at entry. Escape output at exit. Always.

### 2. WPCS + PHPStan — how to run

Commands live in the **Quality gates** table above (`mcp__wpcs__wpcs_check_file` / `vendor/bin/phpcs`, `vendor/bin/phpstan analyse`, `bin/check.sh --staged`). Run them on every PHP file you touch and before every commit; fix all errors and warnings before proceeding — it is part of Done. Full sniff config + REST/security patterns: `/wp-plugin-development` Part 8.

### 3. Test-Driven Development — Mandatory

Write the failing test FIRST. Then write the implementation. Then make it pass.

```
vendor/bin/phpunit tests/[Area]/[ClassTest].php --testdox
```

Never mark a task as complete unless tests pass.

### 4. Premium UX — Non-Negotiable

**v2 is the only design source.** Every template renders against the v2 prototypes in `docs/v2 Plans/v2/` and the canonical tokens + primitives in `docs/v2 Plans/tokens.css`. The previous brainstorm mockups have been deleted from the repo — do not reference them, do not extrapolate from them. See `docs/v2 Plans/PLAN.md` for the surface-to-prototype map + composition rules for surfaces v2 doesn't prototype.

- Tokens (colour / type / spacing / dark mode): see the **Design System Tokens** section — author with `--bn-*` only, never raw hex/px.
- Mobile: every member-facing layout ≤640px must be tested full-width, no horizontal scroll (the global CLAUDE.md 390px verify rule applies — verify in the same commit, not later).
- Interactions: hover / focus rings / loading states all per the v2 prototype.

### 5. No Emoji — Ever

BuddyNext targets premium UX on par with Notion, Asana, LinkedIn, and Facebook. Emoji are a legacy pattern incompatible with that bar.

**Rules:**
- **Never** use Unicode emoji characters (😀 🔗 👤 ✅) anywhere — PHP, JS, CSS, HTML, or comments.
- **Never** use HTML entities that render emoji (`&#128100;`, `&#x1F4BB;`, `&#x26A0;&#xFE0F;`).
- **Always** use SVG icons from `assets/icons/` via:
  - Templates: `buddynext_icon( 'icon-name' )` — echoes inline SVG
  - PHP classes: `echo \BuddyNext\Core\IconService::render( 'icon-name' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped`
  - JS status hints: use CSS class-based colored text — no emoji in `textContent`
- **Adding new icons:** Drop a Lucide-style SVG (no width/height, `stroke="currentColor"`, `viewBox="0 0 24 24"`) into `assets/icons/<slug>.svg`.
- **55+ icons already in `assets/icons/`** — check before creating a new one.
- `IconService::render()` returns `wp_kses()`-sanitized markup — always safe to echo.

### 6. Subsystem-First — Build the Inventory Matrix Before Coding

Most "we missed it" bugs are not hard bugs — they are **cells in a grid nobody enumerated**. Before changing any subsystem (email, auth, notifications, settings, routing, any list/grid/data method), build the inventory FIRST, then write code that fills every cell. Symptom-by-symptom fixing across turns is patchwork; if you catch yourself touching the same subsystem a third time, stop and build the matrix.

1. **Enumerate by grep, never from memory.** List every surface / entry point (rows) and every contract (columns). The miss is always the row or column nobody wrote down.
   - *Rows* must include **every entry point for the same feature**, not just the obvious one — e.g. a password reset fires from the REST endpoint **and** `wp-login.php` **and** programmatic `retrieve_password()`; an email sends from a notification, a cron digest, an admin test, and a live trigger.
   - *Columns* are the contracts each row must satisfy — e.g. for email: branded `brand_wrap` shell, From/Reply-To identity, links built via `PageRouter::*_url()` (not hand-rolled query args), the **setting read path** (empty-string option vs absent option), and **preview matches the real send**.
2. **Trace each contract end-to-end, both directions.** Every setting: *set → stored → read → applied*. Every link: *builder → route → resolves?*. Run `/wp-contract-audit` — "read never applied", "saved but not used", "key mismatch" are exactly what it catches. This is also where the `audit/manifest.json` inventory pays off (reuse, don't re-grep).
3. **Write "Definition of Done" as a checklist from the grid BEFORE coding.** Code fills cells; it does not chase whatever was last visible in the browser.
4. **One verification pass hits every cell** — not just the happy path. Always include: the empty-option / fresh-install site, the **secondary** entry point, the admin preview, and a real end-to-end send (Mailpit at `http://localhost:10030/` for email). The big-site checklist in the user's global CLAUDE.md is the data-layer version of this same rule.

---

## File Placement Rules — Where Every New File Goes

These rules are enforced on every PR. When in doubt, follow the pattern already in the nearest domain folder.

### Domain Principle

Every feature domain owns its full stack in one folder:

```
includes/{Domain}/
  {Domain}Service.php        ← business logic
  {Domain}Controller.php     ← REST endpoints
  {Domain}Listener.php       ← WordPress hooks (implements ListenerInterface)
```

If a new file's name starts with the domain prefix, it goes in that domain folder. If it doesn't, pick the domain whose description best matches the file's responsibility.

### Mandatory Placement Rules

| File type | Belongs in | Example |
|---|---|---|
| Outbound webhook service, controller, listener | `Outbound/` | `OutboundWebhookService` |
| Content moderation logic (banned words, rate limits, safeguards) | `Moderation/` | `SafeguardService` |
| REST controller for a domain | Same folder as its Service | `MemberTypeController` → `MemberTypes/` |
| Bridge adapter classes | `Bridges/` with `Bridge` suffix | `JetonomyBridge.php` |
| Bridge listener classes | `Bridges/` with `BridgeListener` suffix | `JetonomyBridgeListener.php` |
| Admin-only UI helpers | `Admin/{SubPage}/` not `Admin/Helpers/` | `MemberDisplay` → `Admin/Members/` |
| Directory/listing service | `Profile/` if it queries `WP_User_Query`; `Search/` only if it queries `bn_search_index` | `MemberDirectoryService` → `Profile/` |
| Cron job runner | `Core/CronService.php` — no `Handlers` suffix | — |

### Listener Convention

Every class that ends in `Listener` **must**:

1. `implement BuddyNext\Contracts\ListenerInterface`
2. Expose `public function register(): void` (not `init()`)
3. Be wired in `Plugin::init()` as `( new XxxListener() )->register()`

Never use `init()` on a listener. The only classes that use `init()` are Services and Admin registrars.

### Bridge Naming Convention

```
Bridges/JetonomyBridge.php          class JetonomyBridge          ← adapter (no alias needed in Plugin.php)
Bridges/JetonomyBridgeListener.php  class JetonomyBridgeListener  ← hook registrar
```

Never name a bridge adapter `class Jetonomy` — it reads like the external plugin class.

### Tests Mirror Source

```
includes/Feed/PostController.php           →  tests/Feed/PostControllerTest.php
includes/SocialGraph/FollowController.php  →  tests/SocialGraph/FollowControllerTest.php
```

`tests/REST/` must stay empty. All controller tests live in the controller's domain folder.

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| PHP | 8.1+ strict types everywhere |
| WordPress | 6.9+ |
| Autoloader | Hand-written PSR-4 (`BuddyNext\` → `includes/`) in `buddynext.php`; runtime never touches Composer. `vendor/` is dev-only and gitignored. |
| Architecture | DI Service Container (same pattern as WPMediaVerse) |
| Permissions | WordPress Abilities API — `buddynext_can( $user_id, 'ability-slug' )` |
| Frontend reactivity | WordPress Interactivity API — no React, no build step |
| Async jobs | Action Scheduler (bundled Phase 6+) |
| REST API | `buddynext/v1` (free) + `buddynext-pro/v1` (pro) |
| Real-time (free) | REST polling — 5s active, adaptive |
| Real-time (pro) | WebSocket via Soketi (Phase P3) |
| Tests | PHPUnit 9 + WP test suite |
| Code quality | WPCS (WordPress standard) + PHPStan level 5 |
| Templates | PHP, theme-overridable via `{theme}/buddynext/` |
| CSS | Custom properties (tokens) — no Tailwind, no Bootstrap |
| Fonts | Inter (body) + Plus Jakarta Sans (display) — Google Fonts |

---

## Design System Tokens

CSS variables are the single source of truth — never hardcode px, hex, or font
values. The system is **`--bn-*` prefixed and OKLCH-based**: a single `--bn-hue`
cascades into the full accent ramp, so re-theming is one hue change. `TokenService`
(`includes/Theme/TokenService.php`) injects the values inline on the `bn-base`
handle. **Canonical definitions live in `assets/css/bn-base.css` and
`docs/v2 Plans/tokens.css` — read those for exact values. Do NOT paste a token
table here; it drifts out of sync (that drift is exactly why this section was
rewritten).**

Token families (all `--bn-` prefixed):
- Surfaces: `--bn-bg`, `--bn-canvas`, `--bn-surface`, `--bn-sunken`, `--bn-raised`
- Ink (text): `--bn-ink`, `--bn-ink-2`, `--bn-ink-3`, `--bn-ink-4`
- Lines/focus: `--bn-line`, `--bn-line-faint`, `--bn-line-strong`, `--bn-ring`
- Accent ramp (OKLCH from `--bn-hue`): `--bn-accent`, `--bn-accent-50…900`, `--bn-accent-fg`
- Semantic: `--bn-success(-bg)`, `--bn-danger(-bg)`, `--bn-info(-bg)`
- Integration accents: `--bn-jetonomy(-bg)`, `--bn-media(-bg)`, `--bn-paid(-bg)`, `--bn-events(-bg)`
- Type: `--bn-font-{body,display,ui,mono}`, `--bn-text-{2xs…4xl,base,md}`, `--bn-fw-{normal…extrabold}`, `--bn-leading-{tight,snug,normal,body}`
- Spacing (4px grid): `--bn-s1 … --bn-s16` · Radius: `--bn-r-{sm,md,lg,xl,full}` · Shadow: `--bn-shadow-{xs,sm,md,lg}`

**Dark mode** flips tokens under `[data-bn-theme="dark"]`, `[data-theme="dark"]`,
or `[data-bx-mode="dark"]` (the last bridges BuddyX/Reign's `.bx-color-mode`
toggle so BN follows the host theme). Verify dark via the real theme toggle, not
a hand-set attribute.

Bare-named aliases (`--bg`, `--text-1`, `--s4`…) exist only for back-compat —
always author with the `--bn-*` names. `bin/ux-audit.sh` (gate F3) rejects raw
hex/px and non-`--bn-` token use. Full component library:
`docs/v2 Plans/style-guide.html` (canonical).

---

## CSS & JS Coding Standards — Non-Negotiable

### CSS Token Rules

**The golden rule: never write a hardcoded px, hex, or font-family value in any CSS file.**

| What you need | How to write it |
|---------------|-----------------|
| Font size | `var(--text-sm)`, `var(--text-base)`, etc. |
| Font weight | `var(--fw-semibold)`, `var(--fw-bold)`, etc. |
| Line height | `var(--leading-body)`, `var(--leading-normal)`, etc. |
| Letter spacing | `var(--ls-tight)`, `var(--ls-normal)`, etc. |
| Colors | `var(--bg)`, `var(--text-1)`, `var(--brand)`, etc. |
| Spacing | `var(--s1)` through `var(--s16)` (4px grid) |
| Border radius | `var(--r-sm)` through `var(--r-full)` |
| Font family | `var(--font-body)` or `var(--font-display)` |

**Where tokens come from:**
- `TokenService` (`includes/Theme/TokenService.php`) injects all `--text-*`, `--fw-*`, `--leading-*`, `--ls-*`, `--bg`, `--text-1`, `--brand`, `--s*`, `--r-*` tokens via `wp_add_inline_style('bn-base')`.
- `theme.json` registers the preset slugs so block themes can override via child theme.
- `bn-base.css` defines `--bn-text-*` as **aliases** to the global tokens: `--bn-text-base: var(--text-base)`.

**CSS file `:root` blocks — allowed vs forbidden:**

```css
/* ✅ ALLOWED — component-specific tokens not in the global system */
:root {
  --bn-shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
  --bn-transition: 0.14s ease;
}

/* ✅ ALLOWED — aliasing global tokens for a local shorthand */
:root {
  --bn-text-base: var(--text-base); /* alias, not hardcode */
}

/* ❌ FORBIDDEN — hardcoded typography/color/spacing */
:root {
  --bn-text-base: 15px;   /* ← never do this */
  --bn-bg: #ffffff;       /* ← never do this */
  --bn-s4: 16px;          /* ← never do this */
}
```

**Font loading** — Inter and Plus Jakarta Sans must be loaded in `AssetService`. Never import from Google Fonts in CSS files. The `--font-body` and `--font-display` tokens carry the full stack including system-font fallbacks.

### CSS File Structure

Every `assets/css/bn-{feature}.css` file must follow this order:

```css
/* 1. File header comment — describes what this file covers */

/* 2. :root block — ONLY component-specific tokens (shadows, transitions)
      and --bn-* aliases to global tokens. No hardcoded values. */
:root { ... }

/* 3. dark-mode overrides only — match the canonical triggers */
[data-bn-theme="dark"], [data-theme="dark"], [data-bx-mode="dark"] { ... }

/* 4. Component rules — desktop-first */
.bn-component { ... }

/* 5. Mobile at end — @media (max-width: 640px) for every layout section */
@media (max-width: 640px) { ... }
```

### JavaScript / Interactivity API Rules

- **All JS stores** use ES module syntax with `import { store, getContext } from '@wordpress/interactivity'`.
- **Store namespace** always `buddynext/{feature-name}` — e.g. `buddynext/feed`, `buddynext/follow-button`.
- **No window globals** — never `window.wp.interactivity.store(...)`.
- **No inline `<script>` in templates** — all JS must be in `assets/js/{feature}/store.js` and loaded via `AssetService`.
- **REST calls in stores** use `fetch()` with the `restUrl` and `restNonce` context values passed from PHP via `data-wp-context`.
- **Computed state** for all class/text bindings — never inline ternaries in `data-wp-bind` attributes.
- **No jQuery** — Interactivity API + vanilla fetch only.

### Adding a New CSS/JS Bundle

1. Create `assets/css/bn-{feature}.css` and `assets/js/{feature}/store.js`.
2. Register both in `AssetService::register_assets()`.
3. Enqueue in `PageRouter::enqueue_hub_assets()` for the relevant hub case.
4. Store shares the `restNonce` and `restUrl` context — pass them from the template via `data-wp-context`.

---

## UI Design References

v2 prototypes in `docs/v2 Plans/v2/` are the only design source. Each surface below maps to its prototype; surfaces without a prototype are composed from v2 primitives per the rules in `docs/v2 Plans/PLAN.md` Part 3.

| Surface | v2 prototype |
|---|---|
| `templates/feed/home.php` | `docs/v2 Plans/v2/home-feed.html` |
| `templates/feed/explore.php` | `docs/v2 Plans/v2/explore-feed.html` |
| `templates/profile/view.php` | `docs/v2 Plans/v2/user-profile.html` |
| `templates/directory/members.php` | `docs/v2 Plans/v2/member-directory.html` |
| `templates/spaces/directory.php` | `docs/v2 Plans/v2/spaces-directory.html` |
| `templates/spaces/home.php` | `docs/v2 Plans/v2/space-home.html` |
| `templates/messages/list.php` | `docs/v2 Plans/v2/dm-list.html` |
| `templates/messages/thread.php` | `docs/v2 Plans/v2/dm-thread.html` |
| `templates/notifications/index.php` | `docs/v2 Plans/v2/notifications.html` |
| `templates/search/results.php` | `docs/v2 Plans/v2/search-results.html` |
| `templates/onboarding/index.php` | `docs/v2 Plans/v2/onboarding.html` |
| Admin chrome (every BN admin page) | `docs/v2 Plans/v2/admin.html` |
| Hub navigation index | `docs/v2 Plans/v2/index.html` |
| Mobile responsive shell | `docs/v2 Plans/v2/mobile.html` |
| All other surfaces (profile edit, space settings, hashtag feed, auth, moderation queue, Pro admins, etc.) | Compose from `docs/v2 Plans/tokens.css` primitives — see `docs/v2 Plans/PLAN.md` Part 3 |

---

## Plugin Architecture

### Bootstrap Chain
```
plugins_loaded (priority 10) → WPMediaVerse, Jetonomy (addons)
plugins_loaded (priority 15) → BuddyNext\Core\Plugin::init() → buddynext_loaded
plugins_loaded (priority 20) → BuddyNext Pro hooks
                              → Bridge classes (if addons active)
```

### Service Container
Same DI pattern as WPMediaVerse:
```php
// Bind
$container->bind( 'social_graph', fn() => new \BuddyNext\SocialGraph\FollowService() );

// Resolve (singleton)
$container->get( 'social_graph' );

// Global helper
buddynext_service( 'social_graph' );
```

### Permission System
Single entry point for every gate:
```php
buddynext_can( $user_id, 'view-space', [ 'space_id' => $space_id ] )
buddynext_can( $user_id, 'post-in-feed' )
buddynext_can( $user_id, 'send-dm', [ 'recipient_id' => $recipient_id ] )
```

### Abilities Registration
All abilities registered at boot via WordPress Abilities API:
```php
wp_register_ability( 'buddynext-view-space',   [ 'label' => 'View Space' ] );
wp_register_ability( 'buddynext-post-in-feed', [ 'label' => 'Post in Feed' ] );
// etc.
```

---

## Licensing & Updates (EDD SL SDK)

The EDD Software Licensing SDK is vendored at `libs/edd-sl-sdk/` (committed, ships in the zip). It is the **single source of truth for the whole product family**: Pro requires the same file from Free's path and registers its own product on the same `edd_sl_sdk_registry` hook. Never copy the SDK into Pro.

| | Free | Pro |
|---|---|---|
| EDD store | https://wbcomdesigns.com | https://wbcomdesigns.com |
| Item ID | **1664401** | **1664402** |
| License key | Preset `buddynext9a3c7e1d5f2b8a4c6e0d9b7f1a2c8e55` (lifetime, unlimited activations) — auto-activated on `admin_init`, stored in `buddynext_license_key`; `buddynext_preset_activated` marks success | Customer's paid key — entered in Settings > License, stored in `buddynext-pro_license_key` |

**Rules (owner decisions, 2026-06-12):**

1. **License gates updates ONLY. Never gate functionality on license state.**
2. Wiring lives at the bottom of `buddynext.php`: registry registration + SDK require + preset auto-activation. Pro's side lives in `buddynext-pro.php` + `includes/License/` in the Pro repo.
3. The Settings > License tab (`includes/Admin/Settings.php::render_license_tab()`) registers only while Pro is active and fires `buddynext_admin_license_tab_content` for Pro's activate/deactivate form. It renders OUTSIDE the options.php form wrapper — the license form posts directly and is handled on `admin_init` by Pro.
4. Option names follow the SDK convention `{registry id}_license_key` / `{registry id}_license` (`buddynext_*` for free, `buddynext-pro_*` for Pro).
5. All runtime third-party code (Action Scheduler, EDD SL SDK) is committed under `libs/` and loaded by direct `require_once`; the plugin's own classes load via a hand-written PSR-4 autoloader in `buddynext.php`. `vendor/` holds dev tooling only and is gitignored. The repo is deps-complete on checkout, so customers never run a build command and the release build needs no `composer install`.

---

## Database Tables

`bn_*` tables, all created in `Installer::run()` (Free) / Pro's installer via `dbDelta()`. **`audit/manifest.json` is the authoritative live inventory** — trust it over any count here. The lists below are the schema by phase.

### Free Tables
| Table | Phase |
|-------|-------|
| `bn_follows` | 2 — Social Graph |
| `bn_connections` | 2 — Social Graph |
| `bn_blocks` | 2 — Social Graph |
| `bn_posts` | 3 — Activity Feed |
| `bn_poll_options` | 3 — Activity Feed |
| `bn_poll_votes` | 3 — Activity Feed |
| `bn_bookmarks` | 3 — Activity Feed |
| `bn_shares` | 3 — Activity Feed |
| `bn_spaces` | 5 — Spaces |
| `bn_space_members` | 5 — Spaces |
| `bn_space_categories` | 5 — Spaces |
| `bn_hashtags` | 7 — Hashtags |
| `bn_post_hashtags` | 7 — Hashtags |
| `bn_hashtag_follows` | 7 — Hashtags |
| `bn_search_index` | 4 — Search |
| `bn_profile_fields` | 4 — Profiles |
| `bn_profile_values` | 4 — Profiles |
| `bn_notifications` | 6 — Notifications |
| `bn_notification_prefs` | 6 — Notifications |
| `bn_email_templates` | 6 — Email |
| `bn_email_log` | 6 — Email |
| `bn_verify_tokens` | Auth |
| `bn_reactions` | 7 — Reactions |
| `bn_comments` | 7 — Comments |
| `bn_reports` | 8 — Moderation |
| `bn_mod_log` | 8 — Moderation |
| `bn_user_strikes` | 8 — Moderation |
| `bn_activity_log` | 1 — Core |

DM tables live in WPMediaVerse (`mvs_conversations`, `mvs_messages`, etc.) — BuddyNext is UI layer only for DM.

### Pro Tables
`bn_membership_tiers`, `bn_subscriptions`, `bn_ai_signals`, `bn_analytics_events`, `bn_email_campaigns`, `bn_campaign_recipients`, `bn_drip_sequences`, `bn_drip_enrollments`, `bn_mod_rules`, `bn_mod_appeals`, `bn_member_labels`, `bn_member_label_assignments`

---

## Development Phases

| # | Phase | Key Deliverables |
|---|-------|-----------------|
| 1 | Core Foundation | Bootstrap, Container, 28 tables, `buddynext_can()`, Abilities API, webhook |
| 2 | Social Graph | Follows, connections, blocks/mutes, privacy model |
| 3 | Activity Feed | Posts, polls, reactions, shares, bookmarks, pagination |
| 4 | Profiles + Search | Profile fields (repeaters), member directory, unified search index |
| 5 | Spaces | Sub-spaces, roles, categories, settings, moderation |
| 6 | Notifications + Email | In-app notifications, email system, digest, templates |
| 7 | Reactions + Comments + Hashtags | Emoji reactions, threaded comments, hashtag registry |
| 8 | Moderation | Report queue, strikes, admin review, appeal workflow |
| 9 | Direct Messaging | BuddyNext UI → WPMediaVerse engine bridge |
| 10 | Bridges | WPMediaVerse, Jetonomy, WBGamification, Career Board |
| 11 | Gutenberg Blocks + Onboarding | All core blocks, setup wizard |

**Pro phases (run parallel after Phase 3):**
- P1: Stripe Membership
- P2: AI Engine
- P3: WebSocket Real-time
- P4: Mobile App
- P5: Analytics
- P6: White-label

---

## WP-CLI Commands

```bash
# Always use --path (this machine's local site)
wp --path="/Users/vapvarun/Local Sites/buddynext-dev/app/public" <command>

# Activate for dev
wp --path="..." plugin activate buddynext

# Run migrations manually
wp --path="..." eval 'BuddyNext\Core\Installer::run(); echo "done\n";'

# Check tables
wp --path="..." db tables --all-tables | grep bn_
```

---

## File Naming Conventions

| Type | Convention | Example |
|------|-----------|---------|
| Classes | PascalCase, one per file | `FollowService.php` |
| Templates | kebab-case | `home-feed.php` |
| Partials | `partial-*.php` | `partial-post-card.php` |
| Assets | kebab-case | `bn-feed.css`, `bn-feed.js` |
| Tests | `ClassTest.php` | `FollowServiceTest.php` |
| REST controllers | `[Feature]Controller.php` | `FeedController.php` |

---

## Key Integration Hooks (Cross-Plugin)

### BuddyNext fires → Addons listen
```php
do_action( 'buddynext_user_followed',          $follower_id, $following_id );
do_action( 'buddynext_user_unfollowed',        $follower_id, $following_id );
do_action( 'buddynext_connection_requested',   $connection_id, $requester_id, $requestee_id );
do_action( 'buddynext_connection_accepted',    $connection_id, $requester_id, $requestee_id );
do_action( 'buddynext_connection_declined',    $connection_id, $requester_id, $requestee_id );
do_action( 'buddynext_connection_withdrawn',   $connection_id, $requester_id, $requestee_id );
do_action( 'buddynext_block',                  $blocker_id, $blocked_id );
do_action( 'buddynext_unblock',                $blocker_id, $blocked_id );
do_action( 'buddynext_post_created',           $post_id, $user_id, $type );
do_action( 'buddynext_post_deleted',           $post_id, $user_id );
do_action( 'buddynext_reaction_added',         $reaction_id, $post_id, $user_id, $emoji );
do_action( 'buddynext_reaction_removed',       $post_id, $user_id, $emoji );
do_action( 'buddynext_comment_created',        $comment_id, $post_id, $user_id );
do_action( 'buddynext_comment_updated',        $comment_id, $post_id, $user_id );
do_action( 'buddynext_comment_deleted',        $comment_id, $post_id, $user_id );
do_action( 'buddynext_space_created',          $space_id, $user_id );
do_action( 'buddynext_space_member_joined',    $user_id, $space_id, $role );
do_action( 'buddynext_space_member_left',      $user_id, $space_id );
do_action( 'buddynext_space_member_removed',   $user_id, $space_id, $removed_by );
do_action( 'buddynext_space_join_approved',    $user_id, $space_id );
do_action( 'buddynext_member_type_assigned',   $user_id, $new_slug, $old_slug );
do_action( 'buddynext_member_type_removed',    $user_id, $removed_slug );
do_action( 'buddynext_member_type_created',    $type_id, $type_data );
do_action( 'buddynext_member_type_deleted',    $type_id, $slug );
do_action( 'buddynext_report_created',         $report_id, $reporter_id, $object_type, $object_id );
do_action( 'buddynext_user_warned',            $user_id, $message, $warned_by );
do_action( 'buddynext_user_suspended',         $user_id, $reason, $duration_days, $hide_content );
do_action( 'buddynext_user_unsuspended',       $user_id );
do_action( 'buddynext_user_shadow_banned',     $user_id );
do_action( 'buddynext_user_shadow_ban_removed', $user_id );
do_action( 'buddynext_appeal_submitted',       $user_id, $appeal_id );
do_action( 'buddynext_appeal_resolved',        $appeal_id, $decision );
do_action( 'buddynext_space_user_banned',      $space_id, $user_id, $banned_by );
do_action( 'buddynext_space_user_unbanned',    $space_id, $user_id );
do_action( 'buddynext_onboarding_completed',   $user_id );
do_action( 'buddynext_notification_created',   $notification_id, $user_id, $type );
```

### Addons fire → BuddyNext listens
```php
// WPMediaVerse
mvs_message_sent( $message_id, $conv_id, $sender_id, $recipient_ids )
mvs_buddynext_active → return true  // BuddyNext hooks this filter
mvs_can_send_message → checks bn_blocks

// Jetonomy
jetonomy_after_create_post( $post_id, ... )
jetonomy_after_create_reply( $reply_id, ... )

// WBGamification
wb_gamification_badge_awarded( $user_id, $badge_id )
wb_gamification_level_changed( $user_id, $old, $new )

// Career Board
wcb_job_created( $job_id, $request )
wcb_application_submitted( $app_id, $job_id, $candidate_id )
```

---

## Spec Files Reference

All specs locked as of 2026-03-20. Source of truth for every feature decision.

```
docs/specs/features/
├── 00-architecture.md       ← Bootstrap, data flow, hook names
├── 01-social-graph.md
├── 02-activity-feed.md
├── 03-spaces.md
├── 04-member-directory-search.md
├── 05-user-profiles.md
├── 06-notifications-email.md
├── 07-direct-messaging.md   ← WPMediaVerse owns engine, BuddyNext is UI
├── 08-reactions-comments.md
├── 09-moderation.md
├── 10-onboarding-setup-wizard.md
├── 11-gutenberg-blocks.md
├── 12-wbgamification-bridge.md
├── 13-jetonomy-bridge.md
├── 14-wpmediaverse-bridge.md
├── 15-career-board-bridge.md
├── 16-admin-settings.md
├── 17-roles-permissions.md
├── 18-hashtags.md
├── 19-database-scale.md
├── FREE-VS-PRO.md
└── P1–P6 Pro specs
```

---

## Definition of Done (Per Phase)

A phase is Done when ALL of:

- [ ] All PHP files pass WPCS (`mcp__wpcs__wpcs_check_directory`)
- [ ] PHPStan level 5 passes (`mcp__wpcs__wpcs_phpstan_check`)
- [ ] All unit tests pass (`vendor/bin/phpunit`)
- [ ] Templates match HTML mockups (verified in browser at `http://buddynext-dev.local`)
- [ ] Dark mode works on all new templates
- [ ] Mobile layout works at 390px viewport
- [ ] `wp rewrite flush` runs clean after activation
- [ ] DB tables created correctly (verified via `wp db tables`)
- [ ] CLAUDE.md "Recent Changes" table updated

---

## Recent Changes

| Date | Phase | Type | Description |
|------|-------|------|-------------|
| 2026-06-15 | header-user-section | feature | Reusable zero-JS logged-in header section (BuddyNext\Header\HeaderUserSection): notification bell + messages icon + avatar with a CSS-only (:focus-within) profile dropdown (quick links + log out). Shipped as the buddynext/header-user-menu block (block-based widget), the [buddynext_user_menu] shortcode, and buddynext_header_{notification_bell,messages_bell,user_menu}() helpers. assets/css/bn-header.css enqueued site-wide only when logged in; no JS file. Reign theme gains a parallel BN elseif branch in template-parts/header-icons/{notification,message,user-menu}.php + header/header-mobile.php (BuddyPress branch untouched; BP/BN mutually exclusive at runtime). |
| 2026-06-15 | header-user-section | fix | bn-base.css dark tokens now also trigger on [data-bx-mode="dark"] (the Wbcom sibling-theme color-mode toggle used by BuddyX/Reign), so every BN surface follows the host theme's dark toggle instead of only BN's own [data-bn-theme]/[data-theme]. |
| 2026-06-15 | header-user-section | feature | Header dropdown links are now filterable via `buddynext_header_user_menu_links` (label/url/icon rows; Log Out always appended + result normalized so a bad filter can't break markup). Reign ships a dedicated compat module inc/plugins-support/buddynext/reign-buddynext-functions.php (loaded from functions.php when BUDDYNEXT_VERSION is defined and BuddyPress is inactive): guarantees the bell/messages/user-menu header icons are present and feeds the site's assigned "User Profile" (menu-2) nav menu into the BN dropdown as a real, admin-controlled menu (falls back to BN defaults). bn-header.css reserves a 20px icon column so icon-less menu items still align. |
| 2026-06-14 | career-board-int | refactor | CareerBoardBridge moved Free→Pro (jobs = application layer); Free no longer registers it. Pro registers it on the buddynext_load_bridges seam. Added PostService::delete_by_link so the bridge never queries bn_posts directly. |
| 2026-06-14 | career-board-int | fix | Career Board bridge corrected to real hook signatures (verified against wp-career-board source): guard on wcb_run (was nonexistent wcb_get_job/WCB_Career_Board); on_job_created reads from the job post (hook passes WP_REST_Request, not an array); status_changed/withdrawn resolve candidate (_wcb_candidate_id meta) and employer (job post_author) since the hooks omit them. |
| 2026-06-14 | notifications-flow | refactor | NotificationController extends REST/BaseRestController and is now $wpdb/usermeta-free (channel + space-pref data access moved into NotificationPrefService::get_channel_prefs/set_channel_prefs/list_space_notification_prefs) |
| 2026-06-14 | notifications-flow | feature | Added NotificationService::get (canonical hydrated row); Pro PushDispatcher reads it instead of querying bn_notifications directly |
| 2026-06-14 | social-graph-flow | refactor | FollowController, ConnectionController, BlockController extend REST/BaseRestController |
| 2026-06-14 | social-graph-flow | feature | Added relationship-inspection endpoints: GET /users/{id}/follow/status, /connection/status, /mutual-connections, /account-type, /me/follow-requests/count (bulk-status endpoints deferred — directory returns relationship data server-side) |
| 2026-06-14 | social-graph-flow | docs | Documented that Pro FunnelService reads Free analytics tables (incl. bn_follows) directly by design (read-only funnel aggregates, not routed through services) |
| 2026-06-14 | spaces-flow | refactor | SpaceController is now $wpdb-free: ban/unban/remove delegate to SpaceMemberService, transfer to new SpaceService::transfer_ownership, join ban-check to is_banned_from_space |
| 2026-06-14 | spaces-flow | fix | Reconciled the removal hook: SpaceMemberService::remove() fires the canonical buddynext_space_member_removed (consumed by WidgetListener), not the orphan buddynext_member_removed_from_space; controller no longer double-fires ban/unban/remove hooks |
| 2026-06-14 | spaces-flow | refactor | SpaceController + SpaceCategoryController extend REST/BaseRestController |
| 2026-06-14 | spaces-flow | feature | Added PUT /space-categories/{id} (edit category) and POST /spaces/{id}/join/cancel (withdraw pending request) |
| 2026-06-14 | spaces-flow | refactor | Pro BrandService + RealtimeAssets read bn_spaces via Free SpaceService (get/get_by_slug); PaywallIntegration required_ability read kept (Pro-only column, documented) |
| 2026-06-14 | profile-flow | refactor | ProfileController, MemberTypeController, MemberDirectoryController extend REST/BaseRestController (local require_auth/require_admin removed; MemberType keeps can_set_user_type) |
| 2026-06-14 | profile-flow | feature | Added DELETE /users/{id}/avatar (admin) — closes the admin avatar-removal gap |
| 2026-06-14 | profile-flow | feature | Added ProfileService::get_field_key; Pro AdvancedFieldRenderer resolves field keys through it instead of querying bn_profile_fields directly |
| 2026-06-14 | profile-flow | note | Deferred: no post-upload cover focal-point adjustment endpoint (focal set on upload only); low priority |
| 2026-06-14 | feed-flow | refactor | bn_posts counter writes (comment/reaction/share) + author lookups consolidated onto PostService::increment_counter/decrement_counter/get_author_id; routed from Comment/Reaction services + WPMediaVerse bridge |
| 2026-06-14 | feed-flow | fix | Counter decrement used GREATEST(0, col-1) which underflows UNSIGNED columns; now GREATEST(1, col)-1 |
| 2026-06-14 | feed-flow | refactor | FeedController announcement reads/writes moved to PostService::get_announcement/end_announcement (FeedController is $wpdb-free) |
| 2026-06-14 | feed-flow | refactor | 8 Feed/Comments/Reactions controllers extend REST/BaseRestController; require_moderator promoted to base |
| 2026-06-14 | feed-flow | feature | PostService gains set_schedule/clear_schedule/mark_published/get_posts_by_status; Pro scheduled-posts writes route through it (no direct bn_posts writes from Pro) |
| 2026-06-13 | moderation-flow | refactor | ModerationController is now fully $wpdb-free — content-warning, appeals-list, and space-ban-list reads moved into ModerationService / SpaceMemberService |
| 2026-06-13 | moderation-flow | feature | Added REST/BaseRestController (shared require_auth/require_admin); ModerationController extends it |
| 2026-06-13 | moderation-flow | feature | Added app-readiness read endpoints: GET /me/appeals, /users/{id}/warnings, /users/{id}/shadow-ban, /users/{id}/suspensions |
| 2026-06-13 | moderation-flow | fix | GET /posts/{id}/content-warning read a phantom content_warning_text column (404'd every post); removed it |
| 2026-06-13 | moderation-flow | fix | GET /spaces/{id}/bans ordered by a non-existent id column (returned empty); order by created_at. ban_from_space() stored null into NOT NULL banned_by; store 0 |
| 2026-06-13 | moderation-flow | refactor | Consolidated log_warning() into warn(); Pro BulkModAdmin reads the queue via ModerationService::get_queue() instead of raw SQL |
| 2026-06-12 | — | feature | Licensing: vendored EDD SL SDK at libs/edd-sl-sdk (family-wide single source); registered item 1664401 with preset auto-activated key in buddynext.php; Settings > License tab (Pro-only, fires buddynext_admin_license_tab_content); updates only — no feature gating |

> Older entries (2026-03-21 → 2026-06-12: initial build through the REST controller-refactor wave) are recoverable via `git log` and `audit/manifest.json`. This table is capped to the current architectural state forward — do not re-expand it with commit-level rows that git already records.
