# Conformance — Pro Advanced Search Filters

**Feature:** Advanced Search Filters (repo: buddynext-pro)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Member Directory → Filters; Search Architecture seam)
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `features/17-roles-permissions.md` (visibility), `FREE-PRO-CONTRACT.md` seam #12
**Live-walk URL:** http://buddynext-dev.local/search
**Verdict:** usable-leave-as-is

---

## What was traced

Pro extends Free's unified search with five optional member filters —
`tier_slug`, `space_id`, `member_label`, `joined_after`, `active_within_days` —
via the `buddynext_search_query_args` filter seam (FREE-PRO-CONTRACT seam #12),
and supplies the tier/space/label dropdown option lists via
`buddynext_search_filter_options`.

Happy path verified: a member on `/search` opens the "Advanced member filters"
card, selects a tier / space / label and/or sets joined-after / active-within,
clicks **Apply filters** (plain GET reload, no JS required), and results narrow
by those filters. App/REST clients pass the same five params to
`GET /buddynext/v1/search`.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/search` route renders results template | ui | wired | `buddynext/includes/Core/PageRouter.php:808-809` |
| Advanced-filter `<form method=get>` with tier/space/label/joined/active controls | ui | wired | `buddynext/templates/search/results.php:628-731` |
| Option lists populate dropdowns via `buddynext_search_filter_options` | store | wired | `buddynext/templates/search/results.php:108-124`; provider `buddynext-pro/includes/Search/AdvancedSearchFilters.php:83-150` |
| Submit → `$_GET` keys read & validated into seam args | service | wired | `buddynext-pro/includes/Search/AdvancedSearchFilters.php:192-244` |
| Pro filter registered on every (non-admin-gated) boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:203` via `wire_extensions()` at `:93` |
| SearchService emits EXISTS WHERE clauses per arg (user scope) | service | wired | `buddynext/includes/Search/SearchService.php:274-334` |
| Filtered query runs (FULLTEXT or LIKE fallback) on `bn_search_index` | db | wired | `buddynext/includes/Search/SearchService.php:355-443` |
| REST `GET /search` forwards same five params via request-scoped closure | rest | wired | `buddynext/includes/Search/SearchController.php:71-101,209-216,254-283` |
| Saved-search card (save/run/delete) | ui+store | wired | template `:740-790` bound to `assets/js/search/store.js:79,118,139,169`; Pro `buddynext-pro/includes/Search/Controllers/SavedSearchController.php` |

---

## First break

None — journey complete. The web filter form, the Pro seam registration, the
$_GET → seam path, the SearchService WHERE emission, and the REST forwarding are
all wired and mutually consistent. The core web filter apply needs no JS.

---

## UX gaps (none stop the journey)

- **Tier / space / label dropdowns render only when Pro populates the option
  lists** (`results.php:641,661,681`). Intentional graceful degradation: Pro
  inactive shows a hint (`:715-718`) and the generic joined/active controls still
  work. Severity low; confirmed-in-code. Evidence:
  `buddynext/templates/search/results.php:124,641,661,681,715-718`.
- **`active_within_days` / `joined_after` fire EXISTS subqueries against
  `bn_analytics_events` / `wp_users` per search.** Scoped to `user` type and gated
  behind the FULLTEXT primary match; within SCALE-CONTRACT for typical directories
  but worth a live check on very large member tables. Severity low;
  needs-live-verification. Evidence:
  `buddynext/includes/Search/SearchService.php:315-333`.
- **Advanced filters require a non-empty query and apply on `/search` only, not
  the empty-query member-directory browse.** The spec frames tier/space/label/etc.
  as *Member Directory* filters, but the canonical directory list
  (`SearchController::list_members` → `MemberDirectoryService::list_members`,
  `buddynext/includes/Search/SearchController.php:294-317`) does not invoke the
  `buddynext_search_query_args` seam. The Pro filters run only through
  `SearchService::search()`, which the web page reaches only when `q` is non-empty
  (`buddynext/templates/search/results.php:141,182-186`). Same on REST: honoured on
  `GET /search`, not on `GET /search/members`. Does not break the traced `/search`
  journey; it is a spec-vs-product scoping question. Severity medium;
  confirmed-in-code. Evidence: `buddynext/includes/Search/SearchController.php:294-317`.

## Visibility / privacy

Results are constrained to `visibility = 'public'`, exclude blocked users
(`SearchService.php:185-197`) and suspended / shadow-banned authors (`:199-208`).
The space filter only offers spaces the viewer already belongs to
(`AdvancedSearchFilters.php:122-147`). Satisfies the privacy-aware requirement and
roles-permissions visibility contract.

---

## Minimal refactor plan

None — usable end-to-end on both web and REST surfaces. Do not rewrite. Optional
(not required for usability): live walk on a large seeded member table to confirm
the `active_within_days` / `joined_after` subqueries stay within SCALE-CONTRACT.
