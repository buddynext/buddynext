# Conformance: Advanced Search Filters (Pro)

**Feature:** Advanced Search Filters (BuddyNext Pro)
**Repo:** buddynext-pro (with buddynext Free dependency)
**Spec ref:** `docs/specs/features/04-member-directory-search.md`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/search
**Verdict:** partial-needs-wiring

---

## What was verified

The locked spec defines two surfaces (member directory with filters + unified
grouped search). Pro layers "advanced" member-search filters on top:
`tier_slug`, `space_id`, `member_label`, `joined_after`, `active_within_days`,
plus a saved-search feature. This audit traces whether a real user can apply a
Pro advanced filter and get filtered results, and whether saved searches are
reachable.

### Backend (Pro filter + Free service): WIRED and correct

- `AdvancedSearchFilters::register()` hooks `buddynext_search_query_args`.
  Registered for real at `buddynext-pro/includes/Core/Plugin.php:202`.
- `AdvancedSearchFilters::apply_pro_args()` sanitises and forwards the five
  advanced args — `buddynext-pro/includes/Search/AdvancedSearchFilters.php:89-135`.
- The Pro file header claims the consuming seam is "missing" (Free silently
  ignores the args). **That comment is now stale.** Free's `SearchService::search()`
  reads all five args and emits correct `EXISTS` subqueries against
  `bn_subscriptions`/`bn_membership_tiers`, `bn_space_members`,
  `bn_member_label_assignments`/`bn_member_labels`, `wp_users.user_registered`,
  and `bn_analytics_events` — `buddynext/includes/Search/SearchService.php:223-311`.
  The filter fires at line 223; the WHERE clauses are appended 255-311. This path
  works for any caller that routes through `SearchService::search()`.
- Saved-search CRUD service + value object are sound —
  `buddynext-pro/includes/Search/SavedSearchService.php`,
  `.../SavedSearch.php`.
- Saved-search REST controller registers 6 routes under `buddynext-pro/v1/me/saved-searches`
  and is wired at `buddynext-pro/includes/Core/Plugin.php:307`.
  `run_search()` correctly delegates to Free's `SearchService::search()`
  (`SavedSearchService.php:226-267`), so the advanced args stored in a saved
  search WILL apply when run through that endpoint.

### Front-end UI + store: MISSING / BROKEN

This is where the journey stops.

1. **No UI control for any of the five advanced filters.**
   - The `/search` aside exposes only Date + Sort radios —
     `buddynext/templates/search/results.php:472-546`.
   - The member directory filter bar exposes only: text search, member-type
     select, "online only", sort — `buddynext/templates/parts/member-directory-filter-bar.php:148-208`.
   - No tier / space / member-label / joined-after / active-within control exists
     on either surface.

2. **No saved-search UI of any kind.** A repo-wide grep for
   `saved-search|saved_search|savedSearch` across Pro returns only backend files
   (Service, Controller, Installer, Plugin). Pro ships zero front-end templates
   and zero JS for search. The 6 saved-search REST routes are reachable only by
   an API client — **api-only**.

3. **The front-end `/search` page bypasses the filter seam entirely.**
   `templates/search/results.php` runs its **own raw SQL** directly against
   `bn_search_index` (`results.php:131-221`) and never calls
   `SearchService::search()`. Therefore `apply_filters('buddynext_search_query_args', …)`
   **never fires on the web search page**. Even if a UI added `?tier_slug=…` to
   the URL, the front-end search page would ignore it. The seam only runs via
   the REST `/search` controller (`buddynext/includes/Search/SearchController.php:149-180`,
   which does call `SearchService::search()`).

4. **The public REST `/search` endpoint does not accept the Pro keys.**
   Its registered `args` are only `q`, `type`, `per_page`, `page` —
   `SearchController.php:38-62`. The advanced keys are not in the arg schema and
   `search()` only passes `q/type/per_page/page` into `SearchService::search()`,
   so even an app/REST client cannot pass the advanced filters through the public
   search endpoint. (They can only reach the seam indirectly via a stored saved
   search executed through `…/saved-searches/{id}/run`.)

5. **Member directory does not use the seam at all.** `MemberDirectoryService`
   contains no `apply_filters('buddynext_search_query_args')` call and no Pro-arg
   handling (grep clean). The directory store JS sends only
   `search/sort/relation/member_type/online/per_page` —
   `buddynext/assets/js/members/store.js:71-80`. So the spec's member-directory
   filters (location, skills, space, connection-status, online) are Free-only and
   the Pro advanced member filters never touch the directory.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member opens /search, sees advanced-filter controls (tier/space/label/joined/active) | ui | missing | templates/search/results.php:472-546 (only Date+Sort); member-directory-filter-bar.php:148-208 (no advanced controls) |
| UI store sends advanced filter params | store | missing | assets/js/members/store.js:71-80 (sends only search/sort/relation/member_type/online); no Pro search JS exists |
| Web /search request honours buddynext_search_query_args seam | rest | broken | templates/search/results.php:131-221 runs raw SQL, never calls SearchService::search(); seam never fires on web page |
| Public REST /search accepts advanced args | rest | missing | includes/Search/SearchController.php:38-62 registers only q/type/per_page/page |
| Pro filter merges advanced args | service | wired | buddynext-pro/includes/Search/AdvancedSearchFilters.php:89-135; registered Plugin.php:202 |
| Free SearchService applies advanced WHERE clauses | service | wired | buddynext/includes/Search/SearchService.php:223-311 |
| Advanced WHERE hits the right Pro tables | db | wired | SearchService.php:255-310 (bn_subscriptions/tiers, bn_space_members, bn_member_labels, users, bn_analytics_events) |
| Save a search from UI | ui | missing | no saved-search template/JS in buddynext-pro (grep clean) |
| Saved-search REST CRUD | rest | api-only | buddynext-pro/includes/Search/Controllers/SavedSearchController.php:72-173; wired Plugin.php:307 |
| Run a saved search applies advanced args | service | wired | SavedSearchService.php:226-267 → SearchService::search() |

---

## First break

**UI layer — no advanced-filter or saved-search controls exist on the web
search/directory surfaces** (`templates/search/results.php:472-546`,
`templates/parts/member-directory-filter-bar.php:148-208`; no Pro front-end
assets). A site member cannot apply any Pro advanced filter or save a search
through the browser. Compounded by the web `/search` page bypassing the filter
seam (`results.php:131-221`) and the public REST `/search` endpoint not
accepting the advanced keys (`SearchController.php:38-62`).

The backend (Pro filter + Free SearchService WHERE clauses + saved-search CRUD)
is built and correct; it is simply unreachable from any UI.

---

## UX gaps

1. **No web UI for the five advanced filters** — critical, confirmed-in-code.
   Evidence: templates/search/results.php:472-546; member-directory-filter-bar.php:148-208.
2. **No saved-search UI (save / list / rename / delete / run)** — critical,
   confirmed-in-code. The feature is API-only. Evidence: grep clean for
   saved-search in buddynext-pro front-end; SavedSearchController.php:72-173.
3. **Web /search page bypasses the filter seam** — high, confirmed-in-code.
   `buddynext_search_query_args` never fires on the rendered search page, so any
   URL-driven advanced filter would be silently dropped. Evidence:
   templates/search/results.php:131-221 vs SearchController.php:177.
4. **Public REST /search omits the advanced args from its schema** — high,
   confirmed-in-code. App/REST clients cannot pass advanced filters to the public
   search endpoint (only indirectly via a stored saved search). Evidence:
   SearchController.php:38-62.
5. **Stale Pro doc comment** — low, confirmed-in-code. The
   AdvancedSearchFilters header still says Free "silently ignores" the args; Free
   now consumes them. Misleading but not a runtime break. Evidence:
   AdvancedSearchFilters.php:28-33 vs SearchService.php:255-311.

---

## Minimal refactor plan (reuse existing working backend)

1. Make the web `/search` page route through `SearchService::search()` /
   `grouped_search()` instead of the inline raw SQL in
   `templates/search/results.php:131-221`, so `buddynext_search_query_args`
   actually fires on the page (single source of truth; SCALE-CONTRACT driver
   filter also only works through the service).
2. Add the five advanced filter keys to the public REST `/search` arg schema
   (`SearchController.php:38-62`) and forward them into the args array passed to
   the filter, so app/REST clients can use them. (REST-FRONTEND-CONTRACT parity.)
3. Add advanced-filter controls to the `/search` aside (and/or directory filter
   bar) bound to the existing Interactivity store — emit the five params the
   service already understands. Source option lists from existing services
   (tiers, spaces, member labels). Respect visibility per feature 17.
4. Add a minimal saved-search UI in the search hub bound to the existing 6
   `buddynext-pro/v1/me/saved-searches` routes (list / save / rename / delete /
   run). No new backend needed.
5. Update the stale header comment in `AdvancedSearchFilters.php:28-33` to reflect
   that Free now consumes the args.
