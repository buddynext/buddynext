# BuddyNext v2 — Frontend uniformity plan

## Boundary skills (read first)

This plan is BuddyNext's specialisation on top of two canonical Claude skills. They define the engineering and UX baseline; this document defines how BN applies them.

| Skill | What it owns |
|---|---|
| `/wp-plugin-development` | PHP / WordPress conventions: hook ownership, REST patterns, DB schema, security (nonces + caps), escape/sanitize, enqueue patterns, PHPDoc + WPCS sniff config. |
| `/ux-audit` | Token + primitive compliance, the per-plugin audit script (vendored at `bin/ux-audit.sh`), the cross-plugin naming contract (`.bn-*` prefix + attribute API). |

When a question arises that this plan doesn't answer, defer to the matching skill — not to invention. The 6 uniformity gates in Part 4 below are BN's expression of the `/ux-audit` baseline; they don't replace it.

## Premise

**v2 is the only design source.** The previous brainstorm mockups have been removed from the repo. No template, no admin page, no Pro screen pulls visual intent from any other source.

The v2 prototypes in `docs/v2 Plans/v2/` cover the major pages — feed, profile, directory, spaces, messages, notifications, search, onboarding, admin chrome. For every other surface (settings tabs, Pro admin pages, edge states), the rule is:

> Apply v2 tokens + v2 primitives. Don't invent a parallel design language.

There is no Tier B / Tier C / "extrapolate from old mockup" layer. Either a v2 prototype exists for the surface, or we build the surface from v2 primitives directly. The component vocabulary in `tokens.css` (`.bn-btn`, `.bn-input`, `.bn-badge`, `.bn-avatar`, `.bn-card`, `.bn-kbd`, `.bn-hr`, `.bn-ring`) is rich enough to compose every screen.

## Part 1 — v2 prototype map (the canon)

Each prototype is the single source of truth for its surface. When in doubt, open the HTML file and read the markup.

| BN surface | v2 prototype |
|---|---|
| Home feed (`templates/feed/home.php`) | `v2/home-feed.html` |
| Explore feed (`templates/feed/explore.php`) | `v2/explore-feed.html` |
| Post detail (single post view) | `v2/post-detail.html` |
| Member directory (`templates/directory/members.php`) | `v2/member-directory.html` |
| User profile view (`templates/profile/view.php`) | `v2/user-profile.html` |
| Spaces directory (`templates/spaces/directory.php`) | `v2/spaces-directory.html` |
| Space home (`templates/spaces/home.php`) | `v2/space-home.html` |
| DM list (`templates/messages/list.php`) | `v2/dm-list.html` |
| DM thread (`templates/messages/thread.php`) | `v2/dm-thread.html` |
| Notifications (`templates/notifications/index.php`) | `v2/notifications.html` |
| Search results (`templates/search/results.php`) | `v2/search-results.html` |
| Onboarding (`templates/onboarding/index.php`) | `v2/onboarding.html` |
| Mobile chrome (responsive shell across surfaces) | `v2/mobile.html` |
| Admin (top-level chrome) | `v2/admin.html` |
| Hub index / nav (universal navigation) | `v2/index.html` |
| Design system canon | `style-guide.html` |

## Part 2 — Surfaces without a v2 prototype

These render entirely from v2 tokens + primitives. No old mockup, no design carry-over.

### Front-end (no prototype, build from primitives)

- Profile edit
- Space settings / moderation / members
- Hashtag feed
- Auth: login, register, verify
- Moderation queue
- Gamification leaderboard
- Block-editor previews
- Community admin

### Admin sub-pages (no prototype, follow `v2/admin.html` chrome + primitives)

- Settings (the 10-tab area)
- Members / Member types / Profile fields / Avatar settings / Member edit
- Spaces admin
- Nav manager
- Integration hub
- Email editor
- Setup wizard

### Pro admin pages (no prototype, identical conventions to Free admin)

- Custom reactions
- Membership tiers (3 sub-pages)
- Broadcast email
- Drip sequences
- Moderation rules
- Bulk moderation
- Member labels
- Scheduled posts
- Analytics

### Email templates (constrained by HTML-email rendering)

The 16 templates in `bn_email_templates`. Inline-style only; web fonts unsafe. Use HEX snapshots of `--bn-canvas` / `--bn-ink` / `--bn-accent` at send time.

## Part 3 — Composition rules for surfaces v2 doesn't prototype

When building a screen v2 doesn't show, compose it from v2 primitives. The rules below are not "extrapolation" — they are the same rules every v2 prototype itself follows.

### Page chrome

- Front-end pages render inside the host theme's `<body>` with `bn-page` body class — no custom header/footer.
- Admin pages render inside WP's `wp-admin` chrome with a `<header class="adm-topbar">` matching `v2/admin.html`.
- Both surfaces stamp `data-bn-theme` + `data-bn-density` on `<html>` via `language_attributes` (already wired).

### Layout primitives

- **Single-column content** → wrap in `.bn-card` with `padding: var(--bn-s6)`.
- **Two-column (list + detail)** → see `v2/dm-list.html` + `v2/dm-thread.html`. Left rail `width: var(--bn-railw)` (260px), right pane fluid, `gap: var(--bn-s4)`.
- **Card grid** → see `v2/member-directory.html`. `display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: var(--bn-s4);`.
- **Tabs** → see `home-feed.html .feed-tabs`. Underline pattern with `border-bottom: 2px solid` flipping `transparent` / `var(--bn-accent)` on active.
- **Form rows** → label above input, `gap: var(--bn-s2)`, full-width `.bn-input`/`.bn-textarea`/`.bn-select`.

### Component vocabulary

| Need | Primitive |
|---|---|
| CTA | `.bn-btn[data-variant="primary"][data-size="md"]` |
| Secondary action | `.bn-btn[data-variant="secondary"]` |
| Destructive action | `.bn-btn[data-variant="danger"]` |
| Tertiary / cancel | `.bn-btn[data-variant="ghost"]` |
| AI-flavored CTA | `.bn-btn[data-variant="ai"]` (Free reserves this for Pro AI features) |
| Text input | `.bn-input` |
| Multi-line | `.bn-textarea` |
| Dropdown | `.bn-select` |
| Status pill (positive) | `.bn-badge[data-tone="success"]` |
| Status pill (warning) | `.bn-badge[data-tone="warn"]` |
| Status pill (error) | `.bn-badge[data-tone="danger"]` |
| Brand chip | `.bn-badge[data-tone="accent"]` |
| Jetonomy / WPMediaVerse cross-link | `.bn-badge[data-tone="jetonomy" \| "media"]` |
| Avatar (list) | `.bn-avatar[data-size="md"]` |
| Avatar (profile hero) | `.bn-avatar[data-size="2xl"]` |
| User-list with presence | `.bn-avatar[data-size="sm"][data-presence="online"]` |
| Card | `.bn-card` (add `[data-interactive]` for hover lift) |
| Divider | `.bn-hr` |
| Keyboard hint | `.bn-kbd` |
| Focus ring helper | `.bn-ring` |

### What's NOT in v2 primitives yet

Identified gaps — these need to be added to `bn-base.css` before the surface that consumes them is swept:

- **Toggle switch** (used in settings + Pro feature flags)
- **Modal / dialog shell** (used in confirm flows)
- **Toast container** (already in `bn-base.css` — confirm it follows v2 visual language)
- **Tab strip** (currently inline in `home-feed.html` — promote to a named primitive)
- **Tooltip** (used on icon-only buttons)
- **Stat tile** (used on Analytics + Integration hub)
- **Data table** (used on admin Members + Spaces + Pro broadcasts)
- **Drag-handle list row** (used on Profile fields + Member types + Mod rules)
- **Split-pane editor** (used on Email editor + Broadcast + Drip)
- **Progress bar / stepper** (used on Onboarding + Setup wizard)

The 10 missing primitives are added in **Phase 1** below before any template sweep starts.

## Part 4 — Uniformity gates

Every screen — prototyped or not — must pass all 6 gates before it's considered v2-uniform.

### Gate 1 — Token compliance

- [ ] No raw hex / px / font-family in CSS outside `:root`
- [ ] Every color reads from `--bn-*`
- [ ] Every spacing reads from `--bn-s1` … `--bn-s16`
- [ ] Every radius reads from `--bn-r-*`
- [ ] Every font reads from `--bn-font-ui` / `--bn-font-display` / `--bn-font-mono`

### Gate 2 — Component primitives

- [ ] Buttons use `.bn-btn[data-variant=...]`
- [ ] Inputs use `.bn-input` / `.bn-textarea` / `.bn-select`
- [ ] Badges use `.bn-badge[data-tone=...]`
- [ ] Avatars use `.bn-avatar[data-size=...]`
- [ ] Cards use `.bn-card`

### Gate 3 — Theme compliance

- [ ] Light and dark (`[data-bn-theme]`)
- [ ] Comfortable and compact (`[data-bn-density]`)
- [ ] Text-scale (`[data-bn-text="large"|"xlarge"]`)
- [ ] Dyslexia (`[data-bn-dyslexia="on"]`)
- [ ] `prefers-reduced-motion`

### Gate 4 — Responsive

- [ ] 390 / 768 / 1280 px viewports
- [ ] No horizontal scroll
- [ ] Logical properties (`margin-inline-start`, not `margin-left`)

### Gate 5 — Accessibility

- [ ] Visible `:focus-visible` via `var(--bn-ring)`
- [ ] Tap targets ≥ 40 px (34 px compact-density admin)
- [ ] AAA body-text contrast
- [ ] Icon-only buttons have `aria-label`
- [ ] Forms have `<label for>`

### Gate 6 — Theme-agnostic

- [ ] No reference to BuddyX / Reign / Astra
- [ ] CSS lives under `.bn-*` / `[data-bn-*]` selectors
- [ ] Renders the same under TT3, BuddyX, Reign, Twenty Twenty-Five

## Part 5 — Rollout sequence

### Phase 0 — Foundation (DONE)

Commits `2e81afb`, `de00823`, `031007e`.

- v2 token vocabulary canonical in `assets/css/bn-base.css`
- Legacy CSS aliased onto v2 source
- `data-bn-theme` / `data-bn-density` stamped on hub + admin html
- Initial primitive layer (`.bn-btn`, `.bn-input`, `.bn-badge`, `.bn-avatar`, `.bn-card`, `.bn-kbd`, `.bn-hr`)
- Private namespaces (`--bn-p-*`, `--bn-ob-*`, `--bn-a-*`) realiased onto v2 source

### Phase 1 — Complete the primitive layer (NEXT)

System polish + the 10 missing primitives from Part 3. Without these, the template sweep would invent ad-hoc patterns and break uniformity.

- [ ] OKLCH `@supports` hex fallback
- [ ] `color-mix()` fallback (same block)
- [ ] Fix compact density radius math
- [ ] Add `--bn-accent-300` / `-800` / `-900`
- [ ] `--bn-avatar-presence-border` indirection
- [ ] `prefers-contrast: more` block
- [ ] `forced-colors` block
- [ ] Toggle switch primitive
- [ ] Modal / dialog primitive
- [ ] Tab strip primitive
- [ ] Tooltip primitive
- [ ] Stat tile primitive
- [ ] Data table primitive
- [ ] Drag-handle list row primitive
- [ ] Split-pane editor primitive
- [ ] Progress bar / stepper primitive
- [ ] Audit + align toast container with v2 visual language

### Phase 2 — Sweep templates with a v2 prototype

Independent — parallel-safe. Each agent migrates one surface to its v2 prototype + passes the 6 gates.

| Agent | Surface | Prototype |
|---|---|---|
| 1 | Composer + post-card | `v2/home-feed.html` + `v2/post-detail.html` |
| 2 | User profile view | `v2/user-profile.html` |
| 3 | Member directory + card | `v2/member-directory.html` |
| 4 | Spaces directory + card | `v2/spaces-directory.html` |
| 5 | Space home | `v2/space-home.html` |
| 6 | Notifications | `v2/notifications.html` |
| 7 | DM list + thread | `v2/dm-list.html`, `v2/dm-thread.html` |
| 8 | Search results | `v2/search-results.html` |
| 9 | Onboarding | `v2/onboarding.html` |
| 10 | Explore feed | `v2/explore-feed.html` |

### Phase 3 — Build surfaces without a prototype

Compose from v2 primitives + composition rules in Part 3. Same parallel-safe dispatch.

- Profile edit
- Space settings / moderation / members
- Hashtag feed
- Auth (login / register / verify)
- Moderation queue
- Gamification leaderboard
- Community admin
- Block editor previews

### Phase 4 — Free admin sub-pages

All admin sub-pages adopt `v2/admin.html` chrome + Part 3 composition rules.

- Settings (10 tabs)
- Members / Member types / Profile fields / Avatar settings / Member edit
- Spaces admin
- Nav manager
- Integration hub
- Email editor
- Setup wizard

### Phase 5 — Pro admin pages

Same conventions as Phase 4. Customer must not perceive a Free / Pro chrome difference.

- Custom reactions, Membership × 3, Broadcast, Drip, Mod rules, Bulk mod, Member labels, Scheduled posts, Analytics

#### Modular template part layer

Phase 5 (and every Pro / bridge consumer) must compose from
`templates/parts/*.php` instead of forking hub templates. The full
catalogue + per-part hook contract lives in
[`docs/specs/TEMPLATE-PARTS.md`](../specs/TEMPLATE-PARTS.md).

Rules:

- Do not duplicate chrome. If a section uses an empty-state /
  pagination / sidebar card / section head / stat grid / filter strip,
  consume the part.
- Extend via `buddynext_part_{name}_args`,
  `buddynext_part_{name}_classes`, `buddynext_part_{name}_before`,
  `buddynext_part_{name}_after`.
- A new chunk that appears in 2+ surfaces becomes a new part —
  add it under `templates/parts/`, expose the four standard hooks,
  document it in `docs/specs/TEMPLATE-PARTS.md`.

### Phase 6 — Email templates

Inline-style refresh of the 16 seeded templates so HTML emails match v2 card visual language.

### Phase 7 — UX gate cleanup

With templates on v2 primitives, the remaining audit violations become idiomatic to fix:

- 19 F1 inline-style → CSS files using v2 tokens
- 11 F2 inline-script → enqueued JS modules
- ~22 Rule 14 `outline: none` → `var(--bn-ring)` replacements
- 10 RTL `margin-left/right` → logical properties
- 8 F8 `confirm()` / `alert()` → modal + toast primitives

## Part 6 — Definition of done

The migration is complete when:

1. Every BN surface passes all 6 gates.
2. `bin/ux-audit.sh` (vendored from `/ux-audit`) returns 0 block-severity violations.
3. `bin/check.sh` (full CI-parity gate: PHP lint + WPCS + PHPStan + UX audit) exits 0.
4. The plugin renders identically (modulo intentional hue rotation) under TT3, BuddyX, Reign, Twenty Twenty-Five.
5. Light / dark + comfortable / compact all flip coherently across every surface.
6. A whitelabel rebrand by flipping `--bn-hue` produces a coherent palette across Free + Pro + every admin page.

## Part 7 — How to apply this plan

When working on any BN frontend task:

1. **Open the v2 prototype** if one exists for the surface.
2. If no prototype exists, **compose from Part 3 primitives + composition rules**.
3. **Walk all 6 gates** before marking done.
4. **Do not consult any pre-v2 mockup source** — they no longer exist in the repo.

If a question about a surface arises that this plan doesn't resolve, the answer is: open `tokens.css` + the closest v2 prototype, and follow what they show. Don't invent.

---

**Status**: Phase 0 complete. Phase 1 ready to start. v2 is canon.
