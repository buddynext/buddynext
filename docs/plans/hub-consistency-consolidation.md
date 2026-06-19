# Hub-Page Consistency — Consolidation Roadmap

Replaces the "200+ micro-cards (2px here, 3px there)" approach with a small set of
**shared-component consolidations**. Pixel patches never converge; one layout
language applied via shared parts fixes whole classes of drift at the source.

## Canonical standard (reference: profile)

Derived from `templates/profile/view.php` + `parts/profile-hero.php` +
`shell/hub-shell.php` + `parts/nav-bar.php`:

1. Inner template renders into the shell's `.bn-app__main`; registers the right
   sidebar via `add_action('buddynext_right_sidebar', …)`. It does NOT own its own
   page grid / `<aside>`.
2. ONE shared entity hero/header part, reused across every sub-page of an entity.
3. Tab nav via the shared `parts/nav-bar.php` (`.bn-tabs`/`.bn-tab`, registry-driven,
   `aria-selected`) — byte-identical per sub-page.
4. Spacing/dimensions via `--bn-s*` / `--bn-r*` tokens (NO hardcoded px).
5. `.bn-btn` / `.bn-tab` / `.bn-card` on interactive elements — never bare
   `<a>`/`<button>` (host-theme bleed risk).

## Audit headline

- **Dimension (4) is already fully conformant** — no real hardcoded px in any of the
  15 audited hub templates (the only `px` hits are inside comments). The pixel-card
  backlog was attacking a non-problem.
- **Dimension (5) is nearly conformant** — the only true bare-anchor offender is
  `feed/single-post.php` breadcrumbs.
- All real drift is in **(1)/(2)/(3)**: pages hand-roll headers, tab/filter bars, and
  page grids instead of consuming shared parts.

## Consolidations (priority order)

### C1 — One shared page header / entity hero
~6 pages hand-roll a header instead of sharing one:
- `spaces/settings.php` + `spaces/admin.php` — each hand-rolls `.bn-sh-header`
  (3rd/4th copy of the space hero) and never calls `parts/space-header.php`.
- `feed/bookmarks.php` (`.bn-bookmarks__header`), `feed/explore.php`
  (`.bn-explore-hero`), `gamification/leaderboard.php` (`.bn-lb-header`).
- Bespoke (already parts, but each its own): `notifications-hero.php`,
  `search-hero.php`, `hashtag-hero.php`.
**Fix:** introduce `parts/hub-header.php` (title + lead + optional stat strip +
optional action slot). Route the hand-rolled headers through it. Route
`spaces/settings.php` + `admin.php` through `parts/space-header.php` with an
`active_tab` arg so the shared nav lights the right tab. Collapses 3–4 space hero
copies into 1.

### C2 — One tab/filter vocabulary
Five vocabularies do the same job: `nav-bar.php` (standard), `feed-filter-tabs`,
`explore-filter` pills, `notifications-filter-bar`, `search-type-tabs`.
**Fix:** migrate the count-pill filter bars (home, notifications, search) onto
`.bn-tabs`/`.bn-tab` via a thin `parts/filter-tabs.php`, so font/focus-ring/
overflow-scroll/active convention are identical. `explore.php` pills are the biggest
outlier. Also replace the hand-rolled `.bn-subnav` in `spaces/moderation.php`
(L210-222) with `parts/nav-subnav.php`.

### C3 — Shell owns the page grid + sidebar
`hashtags/feed.php` and `gamification/leaderboard.php` build an in-template `<aside>`
AND register `buddynext_right_sidebar` → competing/double sidebars, bypassing
`hub-shell.php`. `search/results.php` rolls its own `.bn-search-layout` 2-col grid.
**Fix:** move aside content into `buddynext_right_sidebar` widgets; let the shell own
the grid (the contract every feed/notifications page already follows).

### C4 — Shared breadcrumb part (theme-bleed)
`feed/single-post.php` breadcrumb anchors (L164, L170) are bare `<a>`.
**Fix:** `parts/breadcrumb.php` using `.bn-crumb`/`.bn-crumb__link`.

### C5 — Unify the two directory design languages
`spaces/directory.php` (`bn-sd-*`) and `directory/members.php`
(`member-directory-*`/`bn-md-*`) are parallel hero+filter+grid+card families.
`spaces/members.php` hand-rolls its own member list instead of the shared
`filter-strip.php`/`pagination.php`/`member-card.php`.
**Fix:** extract a shared `directory-shell` (hero + filter-strip + results grid +
pagination); have both directories + the space members tab consume it.

### C6 — Tokenize space avatar tones
`spaces/home.php` emits a hardcoded `#hex` avatar palette inline (`bn_sh_avatar_color`,
L62; inline styles L370/425/468). `directory.php` already uses `data-tone` OKLCH
tokens for the same job.
**Fix:** drop the hex palette; switch to `data-tone`.

## Exemptions (by design — do NOT force into the shell)
- `messages/native.php` — deliberate edge-to-edge two-pane app surface (`data-bn-main-edge`).
- `onboarding/index.php` — standalone full-bleed wizard.

## Execution notes
- Each consolidation is browser-verified on every affected hub page at desktop +
  390px + dark before it's considered done.
- Order C1 → C2 → C3 delivers the most visible "consistent flow across hub pages"
  first; C4/C6 are quick wins; C5 is the largest (own pass).
