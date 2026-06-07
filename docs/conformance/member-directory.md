# Conformance Dossier — Member Directory

**Feature:** Member Directory (free repo)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Member Directory surface) + journey `docs/journeys/member-directory.md`
**V2 mockup:** `docs/v2 Plans/v2/member-directory.html`
**Live-walk URL:** http://buddynext-dev.local/members
**Verified:** 2026-05-31 (static read-only trace; no browser)

---

## Verdict: usable-leave-as-is

> **Resolution (2026-06-07).** The grid/list view toggle is now wired and the list view is built + browser-verified. Added the `.bn-md-filters__view` toggle buttons (bound to the already-wired `setGridView`/`setListView` store actions), a `.bn-md-card__identity` wrapper that is `display:contents` in grid (grid layout unchanged) and a flex column in list, and the `.bn-md-grid.is-list` row layout CSS. Verified in-browser: grid → 5-col unchanged, list → single-column rows, aria-pressed + localStorage persistence both correct. `templates/parts/member-directory-filter-bar.php`, `templates/parts/member-card.php`, `assets/css/bn-members.css`.

The core happy-path journey (land on `/members` → browse cards → search/filter/sort →
follow/connect/accept-decline → moderate via kebab) is **wired end-to-end** across
ui → store → rest → service → db. Every interactive control resolves to a registered
Interactivity API store action that calls an existing REST route backed by
`MemberDirectoryService`. One spec affordance — the **card/list view toggle** — has working
JS + CSS but no UI control rendered in any template, so it is currently unreachable. That is
the only proven gap and it does not stop the journey (the directory is fully usable in grid view).

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/members` resolves to the directory template | rest (route) | wired | `includes/Core/PageRouter.php:846` returns `directory/members.php`; people-hub case at `:657-665` |
| Members bundle (`@buddynext/members` store) enqueued on the people directory | store | wired | `PageRouter.php:663` `$assets->enqueue('members')`; module map `includes/Core/AssetService.php:318` `'@buddynext/members' => 'members/store'`, registered/enqueued at `:354,:377` |
| Server-rendered grid of member cards (first paint, SEO, no-JS) | ui | wired | `templates/directory/members.php:208-211` `WP_User_Query`; grid part included `:592-606`; cards in `templates/parts/member-card.php` |
| Reactive filter bar bound to store (search/sort/relation/type/online) | store | wired | `templates/parts/member-directory-filter-bar.php:158,172,190,201,140` `data-wp-on--*`; actions in `assets/js/members/store.js:440,452,459,473,501` |
| Search matches name/login + privacy-safe searchable-field mirrors | service | wired | `MemberDirectoryService.php:193-220` (REST path) and `:438-472` `matching_user_ids()` (server render); template uses it at `members.php:154` |
| REST list endpoint returns shaped, paginated cards | rest | wired | `MemberDirectoryController.php:106-177` → `MemberDirectoryService::list_members()` `:54` |
| Cursor pagination (keyset, per-sort) | service | wired | `MemberDirectoryService.php:242-273` cursor WHERE, `:565-635` encode/decode |
| Member-type filter (pills + select) | service | wired | `bn_member_type` usermeta `MemberDirectoryService.php:227-232`; server render `members.php:187-195`; pills `member-directory-tabs.php` |
| Follow / Connect / Accept / Decline inline, optimistic, no reload | store | wired | card buttons `member-card.php:269,281,305,312`; actions `store.js:544,570,621,639`; routes `/users/{id}/follow`, `/connect`, `/connect/accept`, `/connect/decline` in `FollowController.php` / `ConnectionController.php` |
| Kebab → Mute / Block / Report via shared modals | rest | wired | `member-card.php:349,356,362`; `store.js:665,691,697` + modal openers `:718,:764`; routes `/users/{id}/mute`, `/block`, `/reports` exist |
| Viewer-aware exclusions: blocked (bidirectional), suspended, shadow-banned, dir opt-out | db | wired | `MemberDirectoryService.php:149-191` NOT EXISTS on `bn_user_suspensions`, `bn_shadow_banned`, `bn_blocks`, `bn_privacy_show_in_directory`; server mirror `members.php:118-134` |
| Computed card data: avatar, online dot, follower + mutual counts | db | wired | `MemberDirectoryService.php:89` follower subquery, `:349-387` batched mutual counts, `:400` online via BlockService |
| Card / list view toggle (spec "Display") | ui | missing | store has `setGridView`/`setListView`/`applyViewClass` `store.js:435-436,51-60`; CSS keys on `.bn-md-grid.is-list`; but **no template renders a `data-view` / `actions.setGridView` control** (hero `view_mode` is "Reserved for future" — `member-directory-hero.php:17`) |

---

## First break

**none — journey complete.** The core happy path (browse → search/filter/sort →
follow/connect/moderate, viewer-aware) has no broken or missing link. The only gap
(view toggle) is an unreached enhancement, not a break in the path.

---

## UX gaps

1. **Card/list view toggle is unreachable from the UI** — severity low, confirmed-in-code.
   List-view machinery exists end to end (store `setGridView`/`setListView`
   `assets/js/members/store.js:435-436`, `applyViewClass` `:51-60`, localStorage persistence
   `:37-49`, `.bn-md-grid.is-list` styling), but no template emits the toggle buttons the
   store binds to (`.bn-md-filters__view .bn-btn[data-view]`). `member-directory-hero.php:17`
   documents `view_mode` as "Reserved for future grid/list view modes." Spec
   `04-member-directory-search.md:26` lists "Card view + list view toggle" under Display.
   Directory is fully usable in grid view; only the alternate layout is inaccessible.

2. **Spec filters not surfaced: "skills", "2nd-degree connections"** — severity low,
   confirmed-in-code. Spec lines 19-20 list a skills filter and a 2nd-degree connection
   option. Service supports `connection_status` of `connections` / `everyone` only
   (`MemberDirectoryService.php:120-131`); no 2nd-degree path. Skills are reachable via
   free-text search over searchable-field mirrors, not a discrete filter control. Does not
   block the journey.

3. **Unified cross-content search (grouped results) is a separate surface** — severity low,
   needs-live-verification. Spec "Unified Search — Grouped Results" (lines 33-61,
   `bn_search_index`) is a distinct feature from the directory traced here; the journey routes
   user search through `SearchController` separately. Out of scope for the `/members` verdict;
   flagged so it is not assumed covered by this dossier.

---

## Minimal refactor plan

Optional, not required for the journey to be usable. Reuses existing working JS/CSS.

1. Render the view-toggle control so the already-built list view becomes reachable: add two
   buttons in the filter strip (or hero) wired to existing store actions —
   `data-view="grid" data-wp-on--click="actions.setGridView"` and
   `data-view="list" data-wp-on--click="actions.setListView"`, with `aria-pressed` bound to
   `state.isGridPressed` / `state.isListPressed` (defined at `assets/js/members/store.js:369-370`).
   No JS/CSS change needed; handlers and `.bn-md-grid.is-list` already exist. Use
   `buddynext_icon('grid-2x2')` / `buddynext_icon('list')` (no emoji).

(Gaps 2 and 3 are spec-completeness items for a future wave, not directory-journey fixes.)

---

## Notes for the human browser walk

- Seed members (incl. `member1`/`member2`) first — an empty directory hides the wired cards/actions.
- Walk `/members`: confirm grid renders server-side; type in search (250 ms debounce, no reload);
  change sort; toggle "Online only"; click a member-type pill/select.
- Logged in: Follow flips to "Following" with a toast; Connect → "Requested"; kebab → Block
  removes the card; Report posts and toasts.
- Confirm the absence of a grid/list toggle button (gap 1) — list view is built but has no control.
