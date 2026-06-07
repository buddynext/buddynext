# Conformance: Search (Free)

**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/search.md`
**Mockup:** `docs/v2 Plans/v2/search-results.html`
**Live-walk URL:** http://buddynext-dev.local/search  *(see UX gap #2 — canonical route is `{activity-base}/search/`)*

## Verdict

**usable-leave-as-is** — both the web journey (server-rendered search page) and the app/REST journey (`GET /buddynext/v1/search`) are complete and viewer-aware. No journey-breaking defect was proven in code. Two items need live verification (URL slug, reindex REST route absence) and are flagged below; neither blocks the happy path.

## Core happy-path journey

A member/visitor enters a query, lands on the search page, sees grouped results (members, posts, spaces, hashtags, media), and results respect blocks + shadow-bans.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| 1. Member types query in on-page search box (GET form, `name=q`, submits to self) | ui | wired | `templates/parts/search-hero.php:66-87` |
| 2. `/{activity}/search/` route resolves to the search template | rest | wired | `includes/Core/PageRouter.php:965-969` (rewrite) → `:814-815` (`search/results.php`) |
| 3. Template runs query, tabs `all/members/posts/spaces/hashtags/media` | ui | wired | `templates/search/results.php:44-53,141-266` |
| 4. Members/posts/spaces resolved via canonical `SearchService::search()` (seam fires) | service | wired | `templates/search/results.php:161-198` → `includes/Search/SearchService.php:166-234` |
| 5. FULLTEXT `MATCH … AGAINST` (LIKE fallback for temp tables) | db | wired | `includes/Search/SearchService.php:355-443,504-516` |
| 6. Viewer-aware exclusions: blocks (`bn_blocks`) + suspensions + shadow-ban (`bn_user_suspensions`, usermeta `bn_shadow_banned`) | service/db | wired | `includes/Search/SearchService.php:185-208` |
| 7. Date / sort filters (radio controls; GET hrefs + IAPI store) | ui+store | wired | `templates/search/results.php:544-612`; `assets/js/search/store.js:51-69` |
| 8. `/` keyboard shortcut focuses search from any page; store enqueued on search page | store | wired | `assets/js/search/store.js:202-218`; `includes/Core/PageRouter.php:639-640` |
| 9. Indexing: post/user/space create/update/delete hooked, async via Action Scheduler | service | wired | `includes/Search/SearchIndexListener.php:34-49` |
| 10. App/REST query `GET /buddynext/v1/search?q=` (grouped default; typed when `type` set) | rest | wired | `includes/Search/SearchController.php:34-103,188-241`; registered `includes/REST/Router.php:76` |
| 11. Member directory `GET /buddynext/v1/search/members` (filters, cursor pagination) | rest | wired | `includes/Search/SearchController.php:105-176,294-317` |
| 12. Empty query → 400 (not 500) | rest | wired | `includes/Search/SearchController.php:191-197` |

## First break

**none — journey complete.** Both the web page and the REST surface complete end-to-end. The two flagged items below are verification/spec-coverage notes, not breaks in the core happy path.

## UX gaps

1. **Reindex REST route `POST /search/index/{type}` (journey Part 5) does not exist.** The controller registers only `GET /search` and `GET /search/members`; there is no `register_rest_route` for a reindex trigger anywhere in `includes/` (`SearchController.php:34-176`; confirmed no `search/index` match repo-wide). Reindex is instead driven by hooks/Action Scheduler (`SearchIndexListener::handle_reindex_all`, `SearchService::schedule_reindex_all`, the `buddynext_reindex_all` action) — so reindexing works operationally, but the admin REST endpoint the journey doc walks is absent. *Severity: low (admin-only convenience; reindex still functions via activation + the async action). Confidence: confirmed-in-code.*

2. **Live-walk URL `/search` vs canonical `/{activity-base}/search/`.** `PageRouter::search_url()` builds the URL under the activity hub slug (`PageRouter.php:1349-1355`, `activity_url():1320-1321`) and the only rewrite rule is `^{activity}/search/?$` (`:965-969`). A bare `/search` only resolves if the activity base is configured to root. *Severity: low. Confidence: needs-live-verification (depends on `buddynext_slug_activity` option on this site).*

3. **No Search item in the left rail / primary nav.** `templates/shell/rail.php:70-114` lists Activity/Explore/Members/Spaces/Notifications/Messages — no Search entry. Discoverability relies on the on-page search box, the `/` keyboard shortcut, and any theme/topbar link. The v2 mockup (`search-results.html`) is page-centric and does not depict a global topbar search, so this matches the mockup. *Severity: low. Confidence: confirmed-in-code (rail has no search link); whether a topbar/theme provides one is needs-live-verification.*

## Minimal refactor plan

None required for usability. Optional, only for journey-doc/spec parity (not a usability fix — do not rewrite working code):

1. If the journey doc's reindex REST endpoint is intended to ship, add a thin `POST /buddynext/v1/search/index/{type}` route to `SearchController` (cap: `manage_options`) that calls the existing `buddynext_reindex_all` action / `SearchIndexListener::handle_reindex_all` — reuse, do not reimplement. Otherwise, correct the journey doc to describe the hook/Action-Scheduler reindex path.
2. Reconcile the journey/live-walk URL with `PageRouter::search_url()` (use `/{activity}/search/` in the doc, or confirm the site's activity base).

## Notes

- App/REST clients get the full search journey independent of the web page; the `q` param is required and validated server-side.
- Pro advanced member filters and saved searches degrade gracefully when Pro is inactive (`SearchService.php:262-334`; `templates/search/results.php:108-124,715-792`) — no fatal, no Pro-table reference.
