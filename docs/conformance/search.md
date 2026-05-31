# Conformance Dossier — Search (Free)

**Feature:** Search (Member Directory + Unified Search)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Locked, 2026-03-19); cross-checked against `docs/journeys/search.md` and `docs/v2 Plans/v2/search-results.html`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/search (note: actual route is `/activity/search/` — see UX gaps)

---

## Summary

The unified search happy-path is fully wired end-to-end for BOTH the web journey and the REST/app journey. A member lands on the search page, types a term in a plain GET form, submits, and gets grouped results (Members / Posts / Spaces / Hashtags / Media) rendered server-side — no JS required. Results flow through the canonical `SearchService::search()`, which enforces public-visibility, block, suspension, and shadow-ban exclusions in SQL, fires the `buddynext_search_query_args` and `buddynext_search_results` seams, and falls back from FULLTEXT to LIKE in test environments. The index is maintained asynchronously by `SearchIndexListener` (registered in `Plugin.php`).

Two journey-doc / spec drifts exist (a documented REST reindex endpoint that is not implemented, and a stated entry URL that does not match the real route), but neither stops the core search journey.

---

## Journey chain (web happy-path: land → query → submit → grouped results)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Route `/activity/search/` → render `search/results.php` | rest (rewrite) | wired | `includes/Core/PageRouter.php:960` (rewrite), `:808-809` (template map) |
| Search hero: GET form with `name="q"`, submit button, `type` hidden field | ui | wired | `templates/parts/search-hero.php:66-87` |
| Type tabs (All/Members/Posts/Spaces/Hashtags/Media) as `?type=` links | ui | wired | `templates/parts/search-type-tabs.php:73-104`; tabs built `templates/search/results.php:324-349` |
| Members/Posts/Spaces resolved via `SearchService::search($q,$type,5,1,$viewer)` | service | wired | `templates/search/results.php:182-198` |
| FULLTEXT MATCH…AGAINST (LIKE fallback) over `bn_search_index`, public-only | db | wired | `includes/Search/SearchService.php:355-443` |
| Viewer-aware exclusions: blocks, suspensions, shadow-ban | service | wired | `includes/Search/SearchService.php:185-208` |
| Result sections render rows (member cards w/ Follow/Message, post/space/hashtag) | ui | wired | `templates/parts/search-result-section-members.php:54+`; sections invoked `templates/search/results.php:444-525` |
| Index populated on content create/update/delete (async) | service | wired | `includes/Search/SearchIndexListener.php:32-50`; registered `includes/Core/Plugin.php:192` |
| REST `GET /buddynext/v1/search` (grouped + typed, 400 on empty `q`) | rest | wired | `includes/Search/SearchController.php:34-241`; registered `includes/REST/Router.php:76` |
| REST `GET /buddynext/v1/search/members` (cursor directory) | rest | wired | `includes/Search/SearchController.php:105-176`, `294-317` |

---

## First break

none — journey complete. The core search happy-path (enter query → submit → privacy-aware grouped results) works at every layer for both web and REST clients.

---

## UX gaps (real, not nitpicks)

1. **Stated entry URL `/search` does not match the real route `/activity/search/`** — severity: low, confidence: confirmed-in-code. `PageRouter::search_url()` returns `activity_url() . 'search/'` (`includes/Core/PageRouter.php:1343-1344`) and the only registered rewrite is `^activity/search/?$` (`:960`). There is no `/search` rewrite. In-app nav links use `search_url()` so they are correct, but the bare `/search` URL the task/journey cites will not resolve unless a WP page or redirect exists at that slug. Needs live verification of whether a redirect/page covers the short URL.

2. **Journey doc Steps 12-13 reference a reindex REST endpoint that is not implemented** — severity: low, confidence: confirmed-in-code. The journey (`docs/journeys/search.md:129-146`, `:162`, REST surface `:195`) describes `POST /buddynext/v1/search/index/{type}` and `SearchController::reindex()` requiring `manage_options`. No such route or method exists in `SearchController` (`includes/Search/SearchController.php:34-176` registers only `/search` and `/search/members`). Reindexing is real but happens via activation (`includes/Core/Installer.php:60` → `schedule_reindex_all()`) and the `buddynext_reindex_all` action handler (`includes/Search/SearchIndexListener.php:251-342`), not via REST. Doc-vs-code drift, not a user-facing web break — the index self-maintains via lifecycle hooks. Confirm with product whether on-demand admin reindex over REST is required.

---

## Minimal refactor plan

EMPTY — verdict is usable-leave-as-is. The two gaps above are documentation/contract drifts and a URL-aliasing question, not breaks in the search journey. Recommended (non-blocking) follow-ups for the doc owner, NOT code rewrites:
- Reconcile `docs/journeys/search.md` Steps 12-13 + REST surface with reality (either drop the unimplemented `POST /search/index/{type}` from the journey, or open a separate request to add it).
- Confirm whether `/search` should redirect to `/activity/search/`; if intended, add the alias — but verify live first (a page or redirect may already exist outside the rewrite rules).

---

## Notes for the live walk

- Walk `/activity/search/?q=<term>` (canonical) AND the bare `/search` to confirm/deny gap #1.
- Seed content first: empty test accounts will show "No results" even though the journey is wired. Create a post via `POST /buddynext/v1/posts`, then confirm a row in `wp_bn_search_index` before asserting search returns it.
- Confirm `bn_search_index` has the `ft_search` FULLTEXT index on production MySQL (drives the FULLTEXT path vs LIKE fallback: `SearchService::has_fulltext_index()`).
