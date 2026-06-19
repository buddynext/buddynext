# BuddyNext Navigation — canonical components & IA (long-term contract)

One nav system. Three independent visual components, three class namespaces, **zero
shared inheritance** — a change to one can never bleed into another. Every surface
(profile, space, future) and every integration (our bridges + 3rd-party plugins)
uses these through the **Nav API** (`buddynext_register_nav()` / `buddynext_nav()`),
never bespoke markup.

## The three components

| # | Role | Class namespace | Render part | Active style |
|---|------|-----------------|-------------|--------------|
| 1 | **Stats** (counts row) | `.bn-nav-metrics` / `.bn-nav-metric` (`__value` `__label` `__delta`) | `parts/nav-metrics.php` | display-only; deep-links via `tab`, never a 2nd nav |
| 2 | **Primary nav** (sections) | `.bn-tabs` / `.bn-tab` (`__label` `__count`) | `parts/nav-bar.php` | underline + accent tint, full-width rail |
| 3 | **Sub-nav** (one level) | `.bn-subnav` / `.bn-subnav__item` (`__label` `__count`) | `parts/nav-subnav.php` | **text toggle** — accent + semibold, no box, no underline, no rail |

`.bn-navgroup` wraps **primary + its sub-nav** as one unit so a page-level stack
gap can't detach the sub-nav as its own section.

### Rules that keep it conflict-free
- **Sub-nav is NOT a `.bn-tab`.** It has its own namespace so it never inherits the
  primary tab's theme-proof border, underline, hover, or active styles.
- **All interactive states scoped under `.bn-app`** (`:hover`/`:focus`/`:active`/
  `[aria-selected]`) so the host theme's bare `<button>` chrome can't bleed in.
  Active wins over hover (an active item hovered stays accent).
- **Tokens only** (`--bn-*`) → dark-safe + RTL-safe for free.
- Feature tab bars that need a variant compose on the base: `class="bn-tabs bn-{feature}-tabs"`
  (modifier adds spacing only — never re-implements the tab).

## How layers map to the Nav API

A registered item's `layer` selects the component:

```php
buddynext_register_nav([
  'id' => 'courses', 'surface' => 'profile', 'layer' => 'primary',
  'label' => __( 'Courses', 'learnomy' ), 'tab' => 'courses', 'icon' => 'graduation-cap',
  'condition' => fn($c) => /* has courses */,
  'count' => fn($c) => /* completed count */,
]);
```

- `layer => 'metric'` → stats row. `layer => 'primary'` → primary tab. A primary
  item with `parent => '<id>'` → a sub-nav child of that parent (one level).
- A `metric` whose id duplicates a top-level `primary` is dropped (the badge on the
  tab is the count's home) — kills double-nav structurally.

## Integration placement (Member surface — LinkedIn-minimum)

Integrations are a **community layer on top** of the standalone plugin (never a
takeover). On the member profile they surface **credentials / outcomes**, not
activity churn:

| Integration | Plugin | Today | Nav-API path |
|---|---|---|---|
| Discussions | Jetonomy | primary **tab** (count) via `register_nav` ✅ | `layer:primary` |
| Achievements | WB Gamification | primary **tab** (gated on standing) via `register_nav` ✅ | `layer:primary` |
| Jobs / Resume | Career Board (Pro) | profile **Suite panel** (`buddynext_member_suite_panels`) | optional `layer:primary` tab if we want it first-class |
| Courses / Certificates | Learnomy (Pro) | profile **Suite panel** | optional `layer:primary` tab ("Courses") |
| Listings | Listro | not built (no bridge yet) | `layer:primary` tab when bridged |

So the integration nav is **not gone** — Jetonomy + Gamification are already first-
class profile tabs through the Nav API, and Career Board + Learnomy already render
member credential panels via the Suite-panel seam. Promoting any of them to a
first-class profile tab (or a Network-style parent + sub-nav) is now a one-line
`register_nav()` — no template work.

## Global rail (app sections) — Jobs / Courses / Listings

Top-level destinations (Jobs, Courses, Listings, Discussions, Media) belong on the
**left rail**, currently via `buddynext_rail_items` (Jetonomy → Discussions,
WPMediaVerse → Media). **Wave 4** folds the rail into the same registry
(`layer:rail`), after which Career Board / Learnomy / Listro register their global
section with the same `register_nav()` call. Adding a rail item also surfaces it on
the mobile bottom bar (one declaration, both targets).

## Build status

- **Profile** — on the Nav API (stats + primary + Network sub-nav). ✅
- **Space (Wave 3)** — `SpaceNav` provider + repoint `spaces/home.php` at the same
  parts. Pending.
- **Rail + Context (Wave 4)** — `RailNav` provider; convert `buddynext_rail_items` +
  `buddynext_context_nav`; this is where Jobs/Courses/Listings rail entries land.
  Pending.
