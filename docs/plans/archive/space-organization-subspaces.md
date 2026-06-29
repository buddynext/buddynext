> **MERGED → `docs/plans/spaces-master-plan.md` (Layers 1-3).** Use the master plan as the source of
> truth. One correction this doc got wrong (from the stale feature-audit): **category admin CRUD already
> exists** (`Admin/Spaces.php` Categories subtab) — it is NOT CLI-only. The master plan reflects that.

# Plan: Space Organization (Categories) + Sub-spaces — finish the member-facing flow at scale

Status: **PLAN** (no code yet). Free. Closes the two open Spaces gaps from `docs/feature-audit.md`
(sub-spaces, category management) and reframes them around the real requirement: **spaces are for
members — members organise them, not the CLI.**

Scale envelope this plan is weighted for: **10,000 installs × up to 50,000 members per install.**
That means: any single space can hold ~50k members; a site can hold thousands of spaces and their
sub-spaces; and every default must be sane on a fresh install with zero owner configuration. No
query, count, or list in this plan is allowed to scan a 50k-row set unbounded, and **no flow may
require wp-admin or WP-CLI** — owners and members do everything from the front end.

> Correction to `feature-audit.md:845`: that finding is **stale**. Sub-space *creation* is now fully
> wired — `SpaceController::create_space()` reads `parent_id` (`SpaceController.php:617`) with a
> manage-parent permission check, `SpaceService::create()` enforces the 2-level depth limit + a
> configurable per-parent sub-space cap (`SpaceService.php:244-309`), and the create-space modal ships
> a parent picker + category picker (`templates/partials/create-space-modal.php:128-167`). The gap is
> no longer "can't create" — it is **discovery, navigation, inheritance, category curation, and the
> missing indexes that make all of the above scan at 50k.**

---

## Guiding principles (judge every line item against these)

0. **Sub-spaces are an organisation/navigation layer ONLY — membership is per-space.** A member's
   relationship to any space is based purely on **what they have joined**. There is no inherited
   membership, no "subset of parent" roster, no privacy cascade, and no parent-feed rollup. `parent_id`
   exists so a child can be *grouped under* and *navigated from* a parent — nothing more. This keeps the
   data model flat (each space stands alone), keeps every query single-`space_id`, and removes the
   entire class of inheritance/cascade complexity at 50k members. **This is the simplification that
   governs the rest of the plan.**
1. **Member-first usability.** A member who owns/moderates a space organises it from the space itself
   (front end). Sub-spaces and category assignment are member actions, not admin chores. (Aligns with
   the standing rule: all entity creation stays on the front end; admin = manage/moderate/delete only.)
2. **No CLI, ever, for routine config.** Today a site owner can only create a space **category** via raw
   REST/CLI (`feature-audit.md:846`). That is the single hardest blocker in this plan and is fixed first.
3. **Mainstream-social scope.** Facebook Groups / LinkedIn model: one level of nesting, a flat category
   list for discovery. No deep trees, no tag clouds, no per-category permission matrices.
4. **Scale is baseline, not a follow-up.** Every list/count/roster below ships paginated + indexed +
   cached on day one (the big-site checklist), because a 50k-member space is the design point, not an edge.
5. **Reuse the substrate.** Per-space attributes that aren't hot directory columns go through
   `bn_space_meta` (see `space-custom-fields.md`) — never a new autoloaded `wp_option` per space.

---

## Current state (verified in code, 2026-06-29)

| Capability | State | Evidence |
|---|---|---|
| `bn_spaces.parent_id` column | ✅ exists | `Installer.php:962` |
| `bn_spaces.category_id` column | ✅ exists, `KEY category` | `Installer.php:961,978` |
| Create sub-space (service) | ✅ depth guard + per-parent cap | `SpaceService.php:244-309` |
| Create sub-space (REST + perms) | ✅ reads `parent_id`, manage-parent check | `SpaceController.php:617-629` |
| Create sub-space (UX) | ✅ parent picker in modal | `create-space-modal.php:149-167` |
| Owner's eligible parents | ✅ `owned_root_spaces()` (parent_id IS NULL) | `SpaceService.php:1069-1090` |
| Category data + counts | ✅ `SpaceCategoryService::get_all_with_counts()` | `SpaceService.php:1102-1110` |
| Category CRUD (REST) | ✅ create/edit/delete, `manage_options` gated | `SpaceCategoryController` |
| **Category management UI** | ❌ **none — REST/CLI only** | `Admin/Spaces.php` (directory + delete only) |
| **Sub-space discovery/nav** | ❌ hydrate omits children; no breadcrumb; no child list | `SpaceService.php:1254-1272` |
| Roster inheritance ("subset of parent") | ⛔ **dropped by design** — membership is per-space | principle 0 |
| Parent feed rollup | ⛔ **dropped by design** — feeds stay single-`space_id` | principle 0 / S5 |
| **Index on `parent_id`** | ❌ **missing** (scan risk) | `Installer.php:975-979` |
| **Composite roster index** `(space_id, status)` | ❌ **missing** (scan risk) | `Installer.php:989-991` |

---

## Scale audit — the parts that break at 50k (fix these or the features scan)

### S1 — `bn_spaces.parent_id` is unindexed → every sub-space lookup is a full table scan  *(P0)*
Listing a parent's children, counting them for the cap, the breadcrumb, the directory's
"hide children from the top level" filter — all run `WHERE parent_id = ?`. Current keys are
`slug`, `owner`, `category`, `is_archived` only. On a site with thousands of spaces this is a scan
**on every space-home render**.
- **Fix:** add `KEY parent (parent_id)` to `bn_spaces`. Schema-only, additive, `dbDelta` + a bumped
  `Installer` version. (The cap-count query at `SpaceService.php:277` becomes index-backed too.)

### S2 — Roster has no `(space_id, status)` composite → a 50k-member roster page scans 50k rows  *(P0)*
`bn_space_members` PK is `(space_id, user_id)`; the roster query is `WHERE space_id = ? AND
status = 'active' ORDER BY joined_at`. MySQL uses the PK prefix for `space_id` then **filters status
row-by-row** across all members. At 50k members that is a 50k-row scan per page, every page.
- **Fix:** add `KEY space_status (space_id, status, joined_at)` so roster pagination is index-ordered
  and keyset-friendly. Removes the filesort + scan.

### S3 — Directory filter+sort composite  *(P1)*
Directory queries filter `is_archived = 0 [+ type] [+ category_id] [+ parent_id IS NULL]` and sort by
`created_at | member_count | name`. Single-column keys can't serve filter+sort together.
- **Fix:** evaluate `KEY directory (is_archived, parent_id, category_id)` (leftmost = the always-present
  predicates). Sort stays a bounded filesort over the filtered set; acceptable because the directory is
  keyset-paginated (page size ≤ 24). Confirm with `EXPLAIN` on a seeded 5k-space set before adding —
  don't add an index the planner won't pick.

### S4 — Counts are denormalized or bounded, never `COUNT(*)`-per-row  *(P0 discipline)*
- Per-space `member_count` already denormalized (`bn_spaces.member_count`) — reuse, don't re-`COUNT`.
- **Sub-space count** per parent: store a denormalized `subspace_count` on the parent OR accept a single
  index-backed `COUNT(*) WHERE parent_id = ?` (cheap once S1 lands, and bounded by the per-parent cap).
  Decision: **no new column** — the cap (default small, e.g. ≤ 20) makes the indexed count trivially
  bounded. Cache it on the parent's space cache row.
- **Category counts** (`get_all_with_counts`): one grouped `COUNT` over an indexed `category_id`,
  cached under the spaces cache group, busted on space create/delete/category-change. Never per-row.

### S5 — Parent feed rollup: NOT BUILT (simplification)
A parent feed does **not** aggregate child posts. Every space feed stays a single-`space_id` query on
the existing `space_feed (space_id, status, created_at)` index — the fastest possible shape. No `IN()`
fan-in, no child-set bounding logic, nothing to cache-invalidate across the tree. This is a direct
consequence of principle 0 (membership/content is per-space based on what you joined).

### S6 — No per-space autoloaded options  *(P0 discipline — existing portfolio rule)*
Any new per-space toggle introduced here (e.g. "show child posts in parent feed",
"inherit parent privacy") is a `bn_space_meta` row or a `bn_spaces` column — **never**
`update_option("bn_space_{$id}_…")`. Reaffirms the `scale-readiness-by-domain.md` Spaces P0.

---

## Workstream A — Space Organization (Categories) without CLI  *(ship first; unblocks owners)*

**Problem:** categories drive directory discovery, the create-space modal consumes them, but there is
**no UI to create/edit/order them** — only REST/CLI. A site owner cannot curate discovery without a
developer. This is the user's stated red line.

**Decision — where category management lives:** site-wide categories are a **site taxonomy**, so the
authoritative editor is a proper **wp-admin screen** (no CLI), consistent with the admin-settings IA.
Members do **not** invent global categories (that would sprawl at 10k sites); members *assign* an
existing category to their space from the front end (already in the modal). This keeps discovery clean
(principle 3) while removing the CLI dependency (principle 2).

A. **Admin: Spaces → Categories screen** (the no-CLI fix)
   - Full CRUD over `bn_space_categories` (name, slug, description, color, text_color, icon, show_in_dir,
     sort_order) backed by the **existing** `SpaceCategoryController` REST — no new data layer.
   - Drag-or-numeric `sort_order`, `show_in_dir` toggle, live count per category (cached, S4).
   - Built on `AdminPageBase` render helpers + the admin uniformity standard; single-scheme admin.
   - Self-descriptive: each field labelled + hinted (no naked inputs).

B. **Admin: Spaces directory screen gaps** (`feature-audit.md:847`)
   - Add **pending-requests** column (uses existing `count_pending_joins`, cached) and an **archive**
     action (service `archive()`/`unarchive()` already exist) alongside delete. Archive ≠ delete.

C. **Member/owner front end (already mostly there)**
   - Confirm the create/edit-space modals show the category picker for every member who can create
     (they do) and that an owner can re-categorise their space from Space Settings → General.

D. **Scale/REST for A–C**
   - Category list endpoint returns the full (small, bounded) set cached under the spaces group.
   - Counts via S4. No autoloaded options (S6). Slug unique already enforced.

---

## Workstream B — Sub-spaces: discovery, navigation, inheritance  *(the member experience)*

Creation works; the **member never sees the result**. A sub-space today is an orphan — no breadcrumb,
no listing on the parent, no roster relationship. This workstream makes sub-spaces a first-class,
navigable, member-organised structure.

B1. **Expose children in the data layer (bounded + cached)**
   - `SpaceService::get_subspaces( int $parent_id, int $limit, int $offset )` — `WHERE parent_id = ?
     AND is_archived = 0`, index-backed by S1, `LIMIT` always, ordered by `member_count DESC` (most
     active first) then name. Cached per parent under the spaces cache group; busted on child
     create/archive/delete/move.
   - `hydrate()` gains an optional, lazily-attached `parent` summary (id, name, slug) for the breadcrumb
     — a single cached `get()` on `parent_id`, not a join on every list row.
   - Decision: **do not** embed the full children array in every `GET /spaces/{id}` (bloats the hot row).
     Children come from a dedicated bounded endpoint (B3).

B2. **Member-facing UX — make sub-spaces visible and navigable**
   - **Breadcrumb** on a sub-space home: `Parent ▸ This sub-space` (parent summary from B1).
   - **"Sub-spaces" section/tab** on a parent space-home listing its children as space-cards (the
     existing `bn-space-card`), with member counts and join state. Paginated if a parent ever exceeds
     one page (cap usually prevents this, but the list is paginated regardless — big-site checklist).
   - **"Add sub-space" CTA** visible only to a member who manages the parent (owner/mod) — opens the
     existing create modal pre-seeded with `parent_id`. This is the member-organises-their-space moment.
   - **Directory hygiene:** the top-level spaces directory shows **root spaces only** (`parent_id IS
     NULL`) so 50k spaces don't flatten into one list; children are discovered via their parent.
     (Search may still surface a sub-space directly — confirm.)

B3. **REST readiness (data calls)**
   - `GET /spaces/{id}/subspaces?page=&per_page=` → bounded, cached, permission-aware (a secret child is
     hidden from a non-member exactly like a top-level secret space).
   - Confirm `GET /spaces/{slug}` resolves a sub-space directly (deep link / breadcrumb back-nav).
   - All through `restFetch` + a real `permission_callback`; `check-rest-boundary.sh` stays green.

B4. **No inheritance — membership is per-space (the locked simplification).**
   This supersedes spec `03-spaces.md:35-37` ("inherit by default"). The relation is grouping only:
   - **Membership** — each space (parent or child) is joined independently. You are a member of exactly
     the spaces you joined; joining a parent does **not** join you to its children, and vice versa.
   - **Visibility / access** — evaluated per-space on the space's own `type` and your own membership of
     *that* space. A secret child is hidden from a non-member of the child even if they belong to the
     parent. No parent-derived access.
   - **Privacy `type`** — a sub-space picks its own type at create time (the create modal's existing
     type selector). For convenience the modal MAY pre-select the parent's type as a default the owner
     can change — this is a one-time UI default, **not** a stored relation or a runtime inheritance.
   - **Leave/delete** — leaving or deleting a parent does nothing to a child's roster; archiving a
     parent leaves children intact (they simply lose their grouping until reassigned). No cascade.
   - **Paywall (Pro)** — out of scope here; with no inheritance there is nothing for Free to gate.

B5. **Move/detach a sub-space** (owner action, front end)
   - Space Settings → "Move under…" (pick from `owned_root_spaces`, re-using the depth guard) and
     "Detach to top level". Both are a single `parent_id` update + cache bust of old parent, new parent,
     and the space itself. Re-validate depth + cap on move (same guards as create).

---

## REST surface delta (summary)

| Method | Route | Status | Notes |
|---|---|---|---|
| POST | `/spaces` (with `parent_id`) | ✅ exists | keep |
| GET | `/spaces/{id}/subspaces` | **new** | bounded, cached, perm-aware (B3) |
| PUT | `/spaces/{id}` (set/clear `parent_id`) | **extend** | move/detach + re-validate (B5) |
| GET/POST/PUT/DELETE | `/space-categories` | ✅ exists | back the admin screen (A); no new data layer |

No new tables. One additive index migration (S1, S2, optionally S3). All else is service methods,
REST routes, templates, and one admin screen.

---

## Schema deltas (additive, `dbDelta`, version-bumped)

```
ALTER bn_spaces        ADD KEY parent (parent_id)                 -- S1
ALTER bn_space_members ADD KEY space_status (space_id, status, joined_at)  -- S2
ALTER bn_spaces        ADD KEY directory (is_archived, parent_id, category_id)  -- S3 (only if EXPLAIN confirms)
```

No column adds. Inheritance flags ride `bn_space_meta` (S6), not new columns.

---

## Caching plan (reuse `SpaceService::CACHE_GROUP` / `CACHE_TTL`)

| Read | Key | Busted on |
|---|---|---|
| children list per parent | `subspaces_{parent_id}_{page}` | child create/archive/delete/move |
| subspace count per parent | folded into the parent `space_{id}` row | same |
| category list + counts | `categories_with_counts` | space create/delete/recategorise, category CRUD |
| parent breadcrumb summary | reuses `space_{parent_id}` | parent update |

Cache by access frequency (CACHING.md): space-home and directory are hot → cache; admin category editor
is cold → no special cache.

---

## Definition of Done (every box, before "done")

- [ ] **No CLI** anywhere in the category or sub-space flow — verified by doing every step in the browser.
- [ ] Admin Spaces → Categories CRUD works end-to-end; counts + ordering + show_in_dir respected.
- [ ] Admin Spaces directory has pending-requests column + archive action.
- [ ] A member who manages a parent can create, see, navigate to, and move a sub-space entirely from the
      front end; breadcrumb + parent "Sub-spaces" section render.
- [ ] Top-level directory shows root spaces only; children reachable via parent and via search.
- [ ] **Membership is per-space** — verified that joining a parent does not join a child (or vice
      versa), a secret child stays hidden from a parent-only member, and leaving/archiving a parent does
      not touch any child roster. No inheritance, no cascade, no parent-feed rollup anywhere.
- [ ] Indexes S1+S2 (and S3 if `EXPLAIN`-confirmed) added; `EXPLAIN` on a **seeded 5,000-space /
      50,000-member** lab shows index use, zero filesort on roster, zero full scans on `parent_id`.
- [ ] Every new list paginated + cached + busted; every count denormalized or index-bounded; no
      autoloaded per-space option introduced.
- [ ] All data via `buddynext/v1` REST through `restFetch` with real `permission_callback`;
      `check-rest-boundary.sh` green. Secret sub-spaces hidden from non-members at every surface.
- [ ] WPCS + PHPStan L5 + UX audit green; dark mode + 390px verified on the new member surfaces.

## Out of scope (don't scope-creep)

- Deeper than one level of nesting (depth guard already forbids it).
- **Any membership/access inheritance between parent and child** — relation is grouping only;
  membership is per-space based on what the member joined (principle 0).
- Parent-feed rollup of child posts (S5 — not built).
- Parent→child membership cascade of any kind (none, not even reactive).
- Member-created **global** categories (sprawl); members only *assign* existing ones.
- Pro paywall inheritance (with no inheritance, nothing to gate).
- Per-category permissions / private categories (not a mainstream-social pattern).

---

## Build order (each phase independently shippable)

1. **Scale indexes S1+S2** (tiny, unblocks everything, zero UX) → ship.
2. **Workstream A** (category admin screen + directory archive/pending) → ship; owners unblocked off CLI.
3. **Workstream B1+B2+B3** (children data + member nav/discovery + REST) → ship; sub-spaces become real.
4. **Workstream B5 move/detach** → ship; reorganisation (re-parent / detach to top level).
5. Re-run the seeded-lab `EXPLAIN` + big-site checklist as the release gate.
