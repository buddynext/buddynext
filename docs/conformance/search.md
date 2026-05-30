# Conformance Dossier — Search (Free)

**Feature:** Search (unified cross-content search + member directory)
**Repo:** buddynext (Free)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Locked)
**Journey ref:** `docs/journeys/search.md`, `docs/v2 Plans/v2/search-results.html`
**Date:** 2026-05-31

---

## Verdict: usable-leave-as-is

The core member/visitor search journey is wired end-to-end on both the web
surface (server-rendered) and the REST surface. Two confined gaps exist on
secondary entry points; neither stops the primary journey, so the default
verdict stands and the refactor plan is intentionally minimal (optional).

---

## Journey chain

Primary web journey: a member opens Search from the nav, types a query, gets
grouped privacy-aware results, and acts on them inline.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Nav "Search" link → `/activity/search/` | ui | wired | `includes/Core/Plugin.php:536-538` (nav item → `PageRouter::search_url()`); `includes/Core/PageRouter.php:1326-1327` |
| Rewrite `/activity/search/` → search template | rest/route | wired | `includes/Core/PageRouter.php:942-946` (rewrite), `:791-792` (`search/results.php`) |
| Search hero form (input `name=q` + submit) | ui | wired | `templates/parts/search-hero.php:66-87` |
| Results template reads `q`/`type`/`date`/`sort`, runs FULLTEXT queries | service/db | wired | `templates/search/results.php:34-228` (members/posts/spaces/hashtags/media MATCH…AGAINST against `bn_search_index`) |
| Viewer-aware exclusion (blocks, suspended, shadow-banned) | service/db | wired | `templates/search/results.php:92-115` (usermeta `bn_suspended`/`bn_shadow_banned` + `bn_blocks`) |
| Grouped result sections + type tabs render | ui | wired | `templates/search/results.php:344-467`; parts `search-type-tabs.php`, `search-result-section-*.php` |
| Date / Sort refine (full reload) | store | wired | `templates/search/results.php:498-545` (`data-wp-on--change`), `assets/js/search/store.js:44-60` |
| Inline Follow / Join from a result | store/rest | wired | `templates/parts/search-result-section-members.php:183-193` → `assets/js/search/store.js:6-42` → `users/{id}/follow`, `spaces/{id}/members` |
| Content kept searchable (post/space/user index writes) | service/db | wired | `includes/Search/SearchIndexListener.php:32-50` (hooks), `:146-228` (async index/deindex); upsert `includes/Search/SearchService.php:39-72` |
| FULLTEXT key present on index table | db | wired | `includes/Core/Installer.php:48,686` (`ft_search` on title,content) |
| Activation / batch reindex | service | wired | `includes/Core/Installer.php:60` → `SearchService::schedule_reindex_all()`; `includes/Search/SearchIndexListener.php:251-342` |

REST journey (app / REST clients): `GET /buddynext/v1/search` (grouped when no
`type`, paginated flat list when typed), viewer-aware, with `q`/`type`/
`per_page`/`page`. Wired — `includes/Search/SearchController.php:30-180`,
`includes/Search/SearchService.php:93-462`. Empty `q` → 400
(`SearchController.php:152-158`). Driver filter + query-args filter + results
filter + `buddynext_search_performed` action all present
(`SearchService.php:223,327,449`).

---

## First break

none — journey complete (primary web journey and REST search journey both
complete). The two items below are on secondary entry points / admin tooling.

---

## UX gaps

1. **search-bar block submits `bn_q`, results page reads `q`** — severity:
   medium, confidence: confirmed-in-code. The reusable Search Bar block posts a
   GET form to `/activity/search/` with input `name="bn_q"`
   (`templates/blocks/search-bar.php:25,38`). The search results template reads
   only `$_GET['q']` (`templates/search/results.php:34`); `bn_q` is never
   registered as a query var nor read anywhere. A user who searches via that
   block lands on an empty search page. The primary nav entry is unaffected
   (it lands on `/activity/search/` with an empty hero the user then fills with
   `q`), so the core journey still works.

2. **Documented admin reindex REST route is absent** — severity: low,
   confidence: confirmed-in-code. `docs/journeys/search.md:129-146` and its REST
   surface table specify `POST /buddynext/v1/search/index/{type}`
   (`SearchController::reindex()`, `manage_options`). `SearchController`
   registers only `GET /search` and `GET /members`
   (`includes/Search/SearchController.php:30-137`) — no `reindex()` method or
   `/search/index/{type}` route exists. Reindexing still happens automatically
   (activation cron → `buddynext_reindex_all`,
   `includes/Core/Installer.php:60`, `SearchIndexListener.php:251`), and live
   indexing is event-driven, so the search index stays correct without the
   endpoint. Journey steps 12-13 are not walkable as written; this is a doc/code
   mismatch on an admin maintenance convenience, not a member-facing break.

3. **Live entry URL mismatch (`/search` vs `/activity/search/`)** — severity:
   low, confidence: needs-live-verification. The canonical search URL built by
   the code is `/activity/search/` (`PageRouter::search_url()` →
   `activity_url() . 'search/'`, `PageRouter.php:1326-1327`). The supplied
   live-walk URL is `http://buddynext-dev.local/search`. No top-level `/search`
   rewrite was found in `register_*_rules()`. The human walk should use
   `/activity/search/` (or the activity slug configured for the site) unless a
   `/search` alias is added elsewhere; verify in the browser.

---

## Minimal refactor plan (optional — does not block the journey)

1. In `templates/search/results.php:34`, accept `bn_q` as a fallback for `q`
   (`$raw_query = $_GET['q'] ?? $_GET['bn_q'] ?? ''`), OR change
   `templates/blocks/search-bar.php:38` input `name` from `bn_q` to `q`. One
   line; closes gap #1. Prefer renaming the block input to `q` so a single
   query param name is used everywhere.
2. Either add the documented `POST /buddynext/v1/search/index/{type}` route
   (admin-only, enqueues `buddynext_reindex_all`) to `SearchController`, or
   update `docs/journeys/search.md` steps 12-13 + REST surface to match the
   actual reindex mechanism (activation cron / WP-CLI). Doc-or-code, not both.

---

## Live-walk URL

`http://buddynext-dev.local/search` (canonical built route is
`/activity/search/` — see UX gap #3; walk that if `/search` 404s).
