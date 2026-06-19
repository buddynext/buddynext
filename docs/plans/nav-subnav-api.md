# BuddyNext Nav / Sub-Nav API — Final Plan

**Goal:** one declarative contract for *every* navigation surface, so a developer
or bridge **registers** a nav item and the system renders it correctly and
consistently — no more hand-edited per-template HTML, no more "Discussions became
a link" drift. The **same** registry + the **same** renderer drive **Member
(profile) and Space** — that is the core requirement.

Status: PROPOSED (awaiting build). Supersedes the per-surface patching approach
and is the proper delivery vehicle for card 2 (`10003376315`, unified tab/sub-nav).
**Clean cut: BuddyNext is unreleased, so the 6 legacy nav filters are deleted
(no back-compat adapters) — every caller is in-repo and migrates in-build.**

---

## 1. The problem (today)

Navigation is injected through ~6 unrelated filters with different array shapes
and **zero shared semantics** (no notion of layer / nav-vs-count / active
convention):

| Filter | Surface | Item shape | Renderer |
|---|---|---|---|
| `buddynext_rail_items` | global rail | `key,label,url,icon,badge` | `templates/shell/rail.php` |
| `buddynext_part_profile_tab_bar_args` | profile tabs | `slug,label,count,href?,in_bar` | `parts/profile-tab-bar.php` |
| `buddynext_profile_extra_data` | profile stats | `label,value,(wp_on_click,data_tab)` | `parts/profile-stats-strip.php` |
| `buddynext_space_tabs` | space tabs | `slug,label,url,count?` | `parts/space-tab-bar.php` |
| `buddynext_part_profile_stats_strip_args` | profile stats | full args | `parts/profile-stats-strip.php` |
| `buddynext_context_nav` | context nav | `label,url` | `templates/partials/nav.php` |

Consequences: Member and Space each render their tab bar + stat strip with
**separate markup**; integrations (Jetonomy, MediaVerse, Gamification) place items
by hand; active-state markers diverge (4 accepted in `bn-base.css:2499-2502`); and
the same item can render as a tab in one place and a stray link in another.

`includes/Nav/NavOverrides.php` already normalises the *override* (hide/reorder)
side across rail/profile/space — proof the three surfaces belong under one model.
This plan extends that to the *declaration + render* side.

---

## 2. The model — one item, four layers, two surfaces

### 2.1 Layers (the relationship you asked about)

Every nav item belongs to exactly one **layer**. The layer determines where it
renders and how it behaves — identical rules for Member and Space:

- **`primary`** — the **one** content navigation (profile tabs / space tabs).
  Renders as `.bn-tab`, `aria-selected` active state, reactive panel swap with an
  `<a href>` no-JS fallback, an optional **count badge**. This is the only place a
  content section is navigated.
- **`metric`** — the stats / sub row. **Display-only counts** (Followers /
  Following / Connections; space Members / Posts / Created). A metric is never a
  second navigation. It may carry a single canonical `url` (e.g. a Followers list
  that has no tab), but the whole row behaves uniformly — no "some link, some
  don't". A metric that *duplicates* a `primary` tab is rejected at registration
  (the badge on the tab is the count's home). **Fully extensible:** any bridge or
  plugin contributes a stat with `register_nav([ layer => 'metric' … ])` — same
  add / reposition / remove / ordering as tabs — with optional `delta`/`trend` for
  the week-over-week chip (e.g. Gamification → Points/Level, a new plugin → its
  own stat). Stats are first-class, not a special case.
- **`rail`** — the global left nav (Feed, Members, Spaces, Discussions, Media…).
- **`context`** — contextual / utility nav (the kebab "more" actions, breadcrumb
  context). Same contract, rendered by the context renderer.

### 2.2 Surfaces

A surface is the page family the item lives on: **`global`** (rail/context),
**`profile`** (Member), **`space`** (Space). Member and Space use the **same**
item schema, the **same** registry call, and the **same** renderer parts — they
differ only by the `surface` value and the context object passed in.

### 2.3 The item contract (one schema for all)

```php
[
  'id'         => 'discussions',      // unique within (surface, layer)
  'surface'    => 'profile',          // global | profile | space
  'layer'      => 'primary',          // primary | metric | rail | context
  'parent'     => null,               // null = top-level; or a primary item id → sub-nav
  'label'      => __( 'Discussions', 'buddynext' ),
  'count'      => 10,                 // int OR callable(NavContext):int — lazy, only run if shown
  'icon'       => 'message-circle',   // Lucide slug (rail/context; optional on tabs)
  'tab'        => 'discussions',      // in-page reactive target (primary/sub), OR…
  'url'        => null,               // …a real route (rail/context/metric-with-list)
  'capability' => null,               // cap gate via buddynext_can(); null = public
  'condition'  => null,               // callable(NavContext):bool — own-profile-only, is-member,
                                      // is-mod, viewer!=self … expresses what a cap string can't
  'hide_empty' => false,             // true → omit the item when its count resolves to 0
  'priority'   => 50,                 // default order (lower = earlier); core uses 10..90
  'before'     => null,               // optional anchor: place before this item id …
  'after'      => null,               // … or after this item id (wins over priority)
  'delta'      => null,               // metric-only: week-over-week chip text, e.g. '+120'
  'trend'      => null,               // metric-only: 'up' | 'down' | 'flat' (chip tone)
  'active'     => null,               // optional callable(NavContext):bool active override
]
```

Why both `capability` AND `condition`: a cap string ("manage-space") can't say
"only on my own profile", "only if the viewer is a member", or "hide from the
subject themselves". `condition` receives the full `NavContext` (surface,
subject_id, viewer_id, role) and returns bool — that's how own-only tabs
(Scheduled), role-gated tabs (space Moderation), and viewer-only controls are
expressed without leaking logic into templates. Both must pass.

Rules enforced by the registry (so items can't render wrong):
- `primary` items must supply `tab` (reactive) — `url` is auto-derived as the
  no-JS fallback. They MUST NOT also be registered as a `metric`.
- `metric` items are render-only; if a `metric` shares an `id` with a `primary`
  item on the same surface, the metric is dropped (dedupe by contract — this is
  what kills the Posts/Discussions double-row).
- `rail`/`context` items require `url` + `icon`.
- A `parent` must reference an existing `primary` item on the same surface; the
  child inherits the parent's surface and renders in the sub-nav layer (§2.5).
- Items failing `capability` are removed before render.

### 2.4 Ordering (deterministic, three levers, no priority wars)

Order is resolved once, per layer, in this precedence:
1. **`before` / `after` anchors** — `[ 'after' => 'media' ]` pins an item right
   after `media` regardless of priority. The single explicit way to say "put my
   tab next to that one"; integrations use this instead of guessing numbers.
2. **`priority`** — the default fallback. Core built-ins occupy a reserved band
   (10, 20, … 90) so an integration can drop in at 25 and land between two core
   items without colliding. Lower = earlier.
3. **Registration order** — stable tiebreak for equal priority + no anchor.

Then **admin overrides win last**: `NavOverrides` (the existing hide/reorder
layer) is applied on the resolved list, so a site owner's drag-reorder always
beats code defaults. One predictable order; core, integrations, and owners each
have a lever that doesn't fight the others.

### 2.5 Sub-nav (nesting under a primary tab)

A primary tab may own a **second-level nav** — e.g. Member ▸ **About** ▸
Overview / Work / Education / Contact, or a Space tab with its own sub-sections.
This is just items with `parent` set:

```php
buddynext_register_nav([ 'id' => 'about-work', 'surface' => 'profile',
  'layer' => 'primary', 'parent' => 'about', 'label' => __( 'Work', 'buddynext' ),
  'tab' => 'about-work', 'after' => 'about-overview' ]);
```

- The registry nests children under their `parent`, ordered by the same §2.4
  rules among siblings.
- **Render:** `parts/nav-bar.php` draws the top-level primary tabs; when the
  active tab has children, a `--sub` variant of the same `.bn-tab` component (or
  `.bn-segment` for filter-style sub-nav) renders inside that tab's panel — one
  component, one active convention, at both levels. No new template is needed to
  add a sub-nav in future; you register child items.
- Depth is capped at one sub-level by design (tab → sub-tab); deeper nesting is a
  smell and is rejected at registration. Metric/rail/context layers stay flat.

#### Reference pattern — relationships (Network) vs content

The relationship lists (mutual Connections / Followers / Following) are ONE
category, so they group under a single `network` primary tab with three children,
**not** three top-level tabs cluttering the bar next to the content tabs. The
counts live in the `metric` row and jump into the right sub-tab — mirroring X's
followers page (Followers/Following sub-tabs) and LinkedIn's grouped Network.

```php
// metric pills (row 1) — counts whose `tab` points at the child target; the
// renderer activates the parent (network) + that sub-tab. No extra field needed.
register_nav([ 'surface'=>'profile','layer'=>'metric','id'=>'followers',
  'label'=>__('Followers','buddynext'),'count'=>$f,'tab'=>'net-followers' ]);
// … 'following' → 'net-following', 'connections' → 'net-connections'

// one primary tab + three sub-tabs
register_nav([ 'surface'=>'profile','layer'=>'primary','id'=>'network',
  'label'=>__('Network','buddynext'),'tab'=>'network' ]);
register_nav([ 'surface'=>'profile','layer'=>'primary','parent'=>'network',
  'id'=>'connections','label'=>__('Connections','buddynext'),'tab'=>'net-connections' ]);
register_nav([ 'surface'=>'profile','layer'=>'primary','parent'=>'network',
  'id'=>'followers','label'=>__('Followers','buddynext'),'tab'=>'net-followers' ]);
register_nav([ 'surface'=>'profile','layer'=>'primary','parent'=>'network',
  'id'=>'following','label'=>__('Following','buddynext'),'tab'=>'net-following' ]);
```

Result: primary bar = `Posts · Replies · Media · Likes · Network · Discussions`,
Network owns `Connections / Followers / Following` as sub-tabs, and the metric
counts deep-link in. Adding a 4th relationship type later is one more
`register_nav(parent:network)` — no template change. The same pattern serves a
Space "Members" tab sub-navving into Owners / Mods / Members.

---

## 3. Architecture

```
includes/Nav/
  NavItem.php        ← value object: validates + normalises one item (the contract)
  NavRegistry.php    ← collects items per (surface, layer) for a NavContext;
                       applies capability gate, priority sort, primary/metric dedupe
  NavContext.php     ← { surface, subject_id, viewer_id, role, extra } passed to providers
  NavRenderer.php    ← turns a resolved layer into markup via the canonical parts
  NavOverrides.php   ← (exists) admin hide/reorder, now feeds the registry not raw filters
  providers/         ← core built-ins per surface (ProfileNav, SpaceNav, RailNav)
```

### 3.1 Registration API (developers + bridges)

```php
// Imperative (preferred for integrations):
buddynext_register_nav([
  'id' => 'discussions', 'surface' => 'space', 'layer' => 'primary',
  'label' => __( 'Discussions', 'jetonomy' ), 'tab' => 'discussions',
  'capability' => null, 'priority' => 60,
]);

// Or via the single unified filter (replaces all 6):
add_filter( 'buddynext_nav_items', function ( array $items, NavContext $ctx ) {
    if ( 'space' === $ctx->surface && /* space has a forum */ ) {
        $items[] = [ /* …item… */ ];
    }
    return $items;
}, 10, 2 );
```

### 3.2 Render API (templates)

Member and Space call the **identical** helpers — only the context differs:

```php
// templates/profile/view.php
$nav = buddynext_nav( new NavContext( 'profile', $user_id, $current_user_id ) );
buddynext_get_template( 'parts/nav-metrics.php', [ 'items' => $nav->layer( 'metric' ) ] );
buddynext_get_template( 'parts/nav-bar.php',     [ 'items' => $nav->layer( 'primary' ) ] );

// templates/spaces/home.php
$nav = buddynext_nav( new NavContext( 'space', $space_id, $current_user_id, [ 'role' => $role ] ) );
buddynext_get_template( 'parts/nav-metrics.php', [ 'items' => $nav->layer( 'metric' ) ] );
buddynext_get_template( 'parts/nav-bar.php',     [ 'items' => $nav->layer( 'primary' ) ] );
```

Two shared parts, used by BOTH surfaces (this is "same for Member and Space"):
- **`parts/nav-bar.php`** — the canonical `.bn-tabs` / `.bn-tab` underline bar:
  `aria-selected` active state, count badge, reactive `data-wp-on--click` +
  `<a href>` fallback. (Replaces the bespoke `profile-tab-bar.php` and
  `space-tab-bar.php` markup.)
- **`parts/nav-metrics.php`** — the count-pill row: uniform pills, optional single
  list link per item, never a second nav. (Replaces the stat-strip markup.)

The renderer is the single enforcement point for card 2's consistency: one
component, one active convention, one nav model — for free, everywhere.

### 3.3 Public extension API (any developer, not just our bridges)

`buddynext_register_nav()` and the `buddynext_nav_items` filter are a **public,
documented, stable contract** — a third-party plugin uses the exact same seam our
own bridges do. Four operations, all programmatic, no template access:

```php
// 1. ADD a top-level tab on a surface
buddynext_register_nav([ 'surface'=>'profile','layer'=>'primary',
  'id'=>'badges','label'=>__('Badges','acme'),'tab'=>'badges','after'=>'likes' ]);

// 2. ADD a sub-nav under any existing tab (theirs or core's)
buddynext_register_nav([ 'surface'=>'profile','layer'=>'primary','parent'=>'about',
  'id'=>'about-skills','label'=>__('Skills','acme'),'tab'=>'about-skills' ]);

// 3. REPOSITION or EDIT an existing item (incl. core items) — the filter receives
//    the fully-resolved list and may mutate it (move / relabel / re-gate)
add_filter( 'buddynext_nav_items', function ( array $items, $ctx ) {
    return buddynext_nav_move( $items, 'discussions', [ 'before' => 'media' ] );
}, 20, 2 );

// 4. REMOVE an item you don't want surfaced
add_filter( 'buddynext_nav_items', fn( $i, $c ) => buddynext_nav_remove( $i, 'likes' ), 20, 2 );

// 5. ADD a STAT (metric layer) — same API, e.g. our Gamification bridge or any
//    plugin contributing a profile/space stat. Optional delta/trend for the
//    week-over-week chip. Reposition/remove a stat with the same helpers.
buddynext_register_nav([ 'surface'=>'profile','layer'=>'metric','id'=>'points',
  'label'=>__('Points','wb-gamification'),'count'=>$pts,'delta'=>'+120','trend'=>'up',
  'after'=>'connections' ]);
```

Helpers (`buddynext_nav_move`, `buddynext_nav_remove`, `buddynext_nav_set`) keep
third-party code declarative and ordering-safe (they re-resolve §2.4 after the
mutation). Because everything funnels through the registry, an external dev can
never produce an inconsistent render — a `metric` they add still can't navigate,
their sub-nav still renders with the one canonical component, etc.

**Evolvability:** the item schema is additive — unknown keys are ignored, so new
optional fields (e.g. a future `badge_tone`, a new `layer`) ship without breaking
existing registrations. The registry + the single filter are the only seam, so we
keep improving the Nav API (new layers, richer sub-nav, per-role variants) behind
a stable surface. Versioned in `docs/standards/nav-api.md` once Wave 0 lands.

### 3.4 Registration timing, render targets, a11y

- **When to register:** core providers + bridges register on a dedicated
  `buddynext_register_nav` action fired once after `plugins_loaded` and before any
  nav renders. Items are resolved lazily per request (so `count`/`condition`
  callables see the live `NavContext`), then memoised per context.
- **Render targets (one registry, several projections):** desktop rail, **mobile
  bottom-bar**, profile/space primary tabs + sub-nav, metric row, context nav.
  Mobile is the rail layer projected to the bottom bar (it already exists as
  `NavOverrides::apply_mobile_items`) — same items, fewer shown, no separate
  declaration. Adding a rail item surfaces it on desktop AND mobile.
- **Accessibility (baked into the shared renderer, not per caller):** primary +
  sub bars are `role="tablist"`/`role="tab"` with `aria-selected`,
  `aria-controls` to the panel, and roving-tabindex arrow-key movement; rail =
  `nav` landmark; metric row = `role="list"`. i18n: labels are `__()`; RTL +
  dark inherit from tokens. A caller gets correct a11y for free.

### 3.5 Site-owner control (no code)

The site owner manages the SAME registry output from wp-admin — and it covers
**every** item including bridge-added ones, because overrides apply to the
resolved list, not to source filters:

- **Reorder** (drag) any layer incl. sub-nav; **hide/show** any item;
  **rename** a label (white-label); these persist via `NavOverrides` and win last
  in §2.4 ordering.
- A bridge's tab/stat appears in the admin reorder UI automatically (it's just a
  registry item) — the owner can hide Discussions, move Media, rename "Network",
  etc., without touching code.
- "Reset to default" restores the code-declared order/visibility. Per-surface
  (global rail, profile, space) scopes already exist in `NavOverrides`.

---

## 4. How Member and Space converge (worked example)

| | Member (profile) | Space |
|---|---|---|
| `metric` layer | Followers · Following · Connections | Members · Posts · Created |
| `primary` layer | Posts · Replies · Media · Likes · **Discussions** | Feed · Members · About · Moderation · **Discussions** |
| Discussions added by | Jetonomy → `register_nav(surface:profile, layer:primary)` | Jetonomy → `register_nav(surface:space, layer:primary)` |
| Discussions count | badge on the Discussions **tab** | badge on the Discussions **tab** |
| Rendered by | `parts/nav-bar.php` + `parts/nav-metrics.php` | the **same** two parts |

Same contract, same renderer, same active-state, same nav model — the only
difference is the `surface` string and the context. The Discussions-as-link class
of bug becomes structurally impossible: a `metric` can't navigate, and a
`primary`-vs-`metric` duplicate is dropped by the registry.

---

## 5. Build waves (clean cut — pre-release, NO back-compat adapters)

BuddyNext is unreleased, so the 6 legacy filters are **deleted**, not wrapped.
Every caller is in-repo (core + our own bridges), so each surface migration
converts its providers + integrations and removes its old filter in the same
wave. One nav system, no parallel legacy path.

- **Wave 0 — contract + registry (no UI change).** `NavItem`, `NavContext`,
  `NavRegistry`, `buddynext_register_nav()`, `buddynext_nav()`, and the single
  `buddynext_nav_items` filter. Unit-tested (validation, capability gate, priority
  sort, primary/metric dedupe). Tighten `bn-base.css:2499-2502` to a single
  `aria-selected` active convention (card 2 Wave 0). Nothing renders from it yet.
- **Wave 1 — shared renderer parts.** Build `parts/nav-bar.php` +
  `parts/nav-metrics.php` from the canonical `.bn-tabs` component (one component,
  one active convention, one nav model). `nav-bar.php` renders top-level primary
  tabs AND the one-level sub-nav (§2.5) for an active tab that has children — the
  same component with a `--sub` variant, so adding a future sub-nav needs no new
  template.
- **Wave 2 — Profile (Member).** Add a `ProfileNav` provider (built-in tabs +
  relationship metrics). Convert the Jetonomy Discussions + Gamification profile
  injections to `buddynext_register_nav()`. **Delete** `buddynext_profile_extra_data`,
  `buddynext_part_profile_tab_bar_args`, `buddynext_part_profile_stats_strip_args`.
  Point `profile/view.php` at `buddynext_nav()` + the shared parts; the
  Posts/Discussions duplication is gone by the dedupe rule. Verify self / viewer,
  light + dark + 390px.
- **Wave 3 — Space.** Add a `SpaceNav` provider; convert the Jetonomy space-forum
  tab to `register_nav()`; **delete** `buddynext_space_tabs`. Point
  `spaces/home.php` at the same `buddynext_nav()` + the same shared parts. Verify
  across owner / moderator / member / non-member.
- **Wave 4 — Rail + Context.** Add a `RailNav` provider; convert the Jetonomy +
  MediaVerse rail injections and the context nav to `register_nav()`; **delete**
  `buddynext_rail_items` + `buddynext_context_nav`. Re-point `NavOverrides` to
  operate on the registry (not the raw filters). Remove the now-dead bespoke
  markup (`profile-tab-bar.php`, `space-tab-bar.php`, stat-strip branches).

After Wave 4 there is exactly one nav system; no legacy filter remains.

### 5.1 Migration completeness — nothing left behind (mandatory parity gate)

No old filter is deleted until the registry **provably** reproduces everything it
fed. Each surface migration follows: **inventory → register → assert parity →
then delete**.

1. **Inventory first.** Snapshot every item the current path emits for that
   surface, per viewer role (the data, not the markup): id/label, layer, order,
   count, visibility. For **Member** that is — primary tabs: Posts, Replies,
   Media, Likes, Scheduled (own-only), Discussions (Jetonomy), Achievements
   (Gamification); metrics: Followers, Following, Connections; owner-only inboxes:
   pending follow requests, pending connection requests; and the relationship
   list panels behind the counts. For **Space**: Feed, Members, About, Moderation
   (mod/owner-only), Discussions (Jetonomy), plus the role-gated controls. The
   inventory is captured as a fixture.
2. **Register** the built-ins into the surface provider + migrate the integration
   injections to `register_nav()`.
3. **Assert parity** with a test: for each role (self/viewer; owner/mod/member/
   non-member) the registry's resolved item set === the captured fixture
   (same ids, order, counts, visibility). Card 2's own active-state + nav-model
   checks ride along.
4. **Only then delete** that surface's legacy filter.

This is the "nothing left behind for members" guarantee, encoded as a gate rather
than a hope — and it doubles as the regression suite as we keep improving the API.

---

## 6. Acceptance criteria

1. One registry resolves rail / profile / space / context from one item schema.
2. `parts/nav-bar.php` + `parts/nav-metrics.php` render **both** Member and Space.
3. A `metric` can never navigate; a `primary`/`metric` duplicate is dropped.
4. One active-state convention (`aria-selected`) site-wide; `bn-base.css` no longer
   accepts four markers.
5. Jetonomy Discussions appears as a `primary` **tab** (with badge) on both
   profile and space, and **not** as a stat-row link — by contract.
6. The 6 legacy nav filters are **deleted**; one registry + one filter
   (`buddynext_nav_items`) is the only extension point.
7. card 2's ~14 tab/sub-nav surfaces render through the shared renderer.
8. **Order** is deterministic: `before`/`after` anchors > `priority` bands >
   registration order, with admin `NavOverrides` winning last — verified for a
   core + integration item mix on both surfaces.
9. **Sub-nav** works by registering `parent` items (no template change), capped at
   one sub-level, rendered by the same component at both levels — proven with a
   throwaway "About ▸ Overview/Work" example on a profile.
10. **Any developer** can add / reposition / edit / remove any item — **tabs,
    stats (metrics), sub-nav, and core items** — purely through
    `buddynext_register_nav()` + `buddynext_nav_items` (no template access) and
    cannot produce an inconsistent render. A bridge adding a profile/space stat is
    a first-class, supported case. Public contract documented in
    `docs/standards/nav-api.md`.
11. **Parity gate (nothing left behind):** for every surface + role, the registry's
    resolved item set equals the pre-migration fixture before the legacy filter is
    deleted (§5.1). Encoded as a test, kept as the regression suite.
