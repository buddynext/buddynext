# BuddyNext v2 — Template refactor plan (modular, long-term)

## The contract every refactored template obeys

These are the rules. Once a template lands under this contract, every future change to it stays inside the contract.

### 1. Shell ownership

`PageRouter::dispatch_hub_template()` emits a full HTML document and renders the inner template inside `templates/shell/hub-shell.php`. The shell provides:

- Top bar (`templates/shell/topbar.php`)
- Left rail nav (`templates/shell/rail.php`)
- Main column (`<main class="bn-app__main">` — where the inner template renders)
- Optional right column (`templates/shell/right-sidebar.php` — auto-rendered when anything hooks `buddynext_right_sidebar`)

**Inner templates DO NOT own:** the topbar, the rail, the page chrome, the 2-column grid, theme header/footer.

**Inner templates DO own:** the content of the main column and (optionally) the right sidebar content via the `buddynext_right_sidebar` action hook.

### 2. v2 prototype is canonical

Every inner template renders the markup pattern of its v2 prototype in `docs/v2 Plans/v2/*.html`. When the prototype shows `.composer > .bn-avatar + .composer-input > textarea + .composer-tools`, that's the structure.

Translation rule: `<div class="composer">` in the prototype becomes `<div class="bn-composer">` in PHP (prefix everything with `bn-`). All `.bn-*` classes match the v2 attribute API + token system already in `bn-base.css`.

### 3. Hooks + filters on every reusable boundary

| Where | Hook |
|---|---|
| Before main content | `do_action( 'buddynext_{hub}_before' )` |
| After main content | `do_action( 'buddynext_{hub}_after' )` |
| Sidebar content slot | `do_action( 'buddynext_right_sidebar', $hub )` |
| Data shaping | `apply_filters( 'buddynext_{hub}_data', $data )` |
| Per-component args | `apply_filters( 'buddynext_part_{name}_args', $args )` |

Pro, bridges, and child themes extend via hooks — never by copying templates.

### 4. Modular partials (`templates/parts/*`)

The 6 shipped partials (`empty-state`, `pagination`, `sidebar-card`, `section-head`, `stat-strip`, `filter-strip`) are the building blocks. Templates compose them. Each partial accepts `$args` and fires its own before/after action hooks + args filter.

When the same chunk appears in 2+ templates, factor it into a new partial. Document in `docs/specs/TEMPLATE-PARTS.md`.

### 5. v2 attribute API only

All buttons, inputs, badges, avatars, cards use `data-variant` / `data-tone` / `data-size` / `data-presence` attributes. Never the legacy `.bn-btn-primary` / `.bn-btn-sm` BEM modifier classes for new code (the old class-based variants still resolve for backward-compat, but new templates use the attribute API).

### 6. REST-only

No `admin-ajax.php`. Every fetch in JS stores hits `wp-json/buddynext/v1/*`. `bin/check-rest-boundary.sh` enforces.

### 7. Responsive 100% mandatory

Every template renders correctly at:
- Desktop (1440px / 1920px)
- iPad (820px portrait, 1180px landscape)
- Mobile (390px portrait)

Logical properties (`margin-inline-*`, `padding-inline-*`, `inset-inline-*`) throughout. The shell already collapses the left rail at ≤ 768px and the right sidebar at ≤ 1024px — inner templates just need to flow correctly inside the main column at every width.

### 8. Theme-agnostic

No reference to BuddyX / Reign / Astra / any specific theme. Templates render the same under TT3, BuddyX, Reign, Twenty Twenty-Five — because the shell now owns the entire document and theme containers are never in scope.

### 9. Boundary skills win

`/wp-plugin-development` for PHP/WP rules (security, escape/sanitize, hooks, REST). `/ux-audit` for tokens + primitives. When this plan disagrees with either skill, the skill wins — file an issue and update this doc.

## Template → v2 prototype map

| Inner template | v2 prototype | Status |
|---|---|---|
| `templates/feed/home.php` + `templates/partials/composer.php` | `v2/home-feed.html` | TARGET |
| `templates/feed/explore.php` | `v2/explore-feed.html` | TARGET |
| `templates/partials/post-card.php` | `v2/post-detail.html` + `v2/home-feed.html` post cards | TARGET |
| `templates/profile/view.php` | `v2/user-profile.html` | TARGET |
| `templates/directory/members.php` + `templates/blocks/member-card.php` | `v2/member-directory.html` | TARGET |
| `templates/spaces/directory.php` + `templates/blocks/space-card.php` | `v2/spaces-directory.html` | TARGET |
| `templates/spaces/home.php` | `v2/space-home.html` | TARGET |
| `templates/messages/list.php` + `templates/messages/thread.php` | `v2/dm-list.html` + `v2/dm-thread.html` | TARGET |
| `templates/notifications/index.php` | `v2/notifications.html` | TARGET |
| `templates/search/results.php` | `v2/search-results.html` | TARGET |
| `templates/onboarding/index.php` | `v2/onboarding.html` | TARGET |

## How to refactor a template (the playbook)

1. **Open the v2 prototype** in `docs/v2 Plans/v2/`. Read it end-to-end.
2. **Open the current PHP template**. Identify chrome to drop (own 2-column wrapper, own nav include, own page-frame) and content to keep (variable setup, REST data fetching, conditional branches).
3. **Drop the chrome.** Templates no longer render `bn-hub-shell` / `partials/nav.php` / any internal multi-column grid — the shell handles all of that.
4. **Match the v2 markup.** Replace existing class names with the BN-prefixed v2 vocabulary. Use the attribute API.
5. **Hook sidebar content.** If the template had a sidebar, register a callback on `buddynext_right_sidebar` action. The shell renders the column automatically when anything is hooked.
6. **Compose with `parts/*`.** Empty states, pagination, sidebar cards, stat strips, filter strips, section heads — pull from `templates/parts/`.
7. **Verify in Playwright.** Desktop 1440 + iPad 820 + mobile 390. Take screenshots; check for visual breakage.
8. **Walk the 6 uniformity gates** (PLAN.md Part 4). Run `bin/check.sh --skip-audit` + targeted audit grep.
9. **Commit + push.** No co-author lines. Direct-to-master.

## Long-term maintenance

- When a new hub is added, the inner template must obey this contract — no exceptions.
- When the shell evolves, it must keep the 4 inner-template extension points (main column, before/after main, right sidebar action, hub name in body class) backward-compatible.
- When a new v2 prototype lands in `docs/v2 Plans/v2/`, file a task to migrate the corresponding inner template.
- Cross-plugin (Pro, bridges) extends through hooks, never by copying templates. If something can't be done via a hook, file a Free seam (lands in `docs/specs/HOOKS.md`) — then Pro hooks it.

## Per-template work allocation (this sweep)

Each template is independent. Worktree-isolated parallel agents handle them concurrently. Each agent:

1. Opens the v2 prototype.
2. Refactors the inner template per the playbook above.
3. Drops own chrome / 2-col wrapper / nav include.
4. Hooks sidebar via `buddynext_right_sidebar`.
5. Sweeps the corresponding `bn-{feature}.css` rules to match the new markup.
6. Verifies WPCS + PHPStan + ux-audit on its scope.
7. Commits + pushes.

Targets per agent:

| Agent | Templates | CSS | v2 prototype |
|---|---|---|---|
| 1 | `feed/home.php` + `partials/composer.php` | `bn-feed.css` (composer + main feed) | `v2/home-feed.html` |
| 2 | `feed/explore.php` | `bn-feed.css` (explore-scoped) | `v2/explore-feed.html` |
| 3 | `partials/post-card.php` | `bn-feed.css` (post-card-scoped) | `v2/post-detail.html` + `v2/home-feed.html` |
| 4 | `profile/view.php` | `bn-profile.css` | `v2/user-profile.html` |
| 5 | `directory/members.php` + `blocks/member-card.php` | `bn-members.css` | `v2/member-directory.html` |
| 6 | `spaces/directory.php` + `spaces/home.php` + `blocks/space-card.php` | `bn-spaces.css` | `v2/spaces-directory.html` + `v2/space-home.html` |
| 7 | `messages/list.php` + `messages/thread.php` | `bn-messages.css` | `v2/dm-list.html` + `v2/dm-thread.html` |
| 8 | `notifications/index.php` | `bn-notifications.css` | `v2/notifications.html` |
| 9 | `search/results.php` | `bn-search.css` | `v2/search-results.html` |
| 10 | `onboarding/index.php` | `bn-onboarding.css` | `v2/onboarding.html` |

Agents 4-10 are higher-volume but lower-priority than 1-3 (which the activity page user verification depends on). Suggested order: 1 + 3 + 4 in first wave, others in second wave.

---

**Status**: Plan written 2026-05-21. Shell takeover + hub-shell buffer landed in commits `f67c6c4` + `27dfeeb`. First-wave agent dispatch next.
