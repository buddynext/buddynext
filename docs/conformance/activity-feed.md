# Conformance: Activity Feed

**Feature:** Activity Feed (repo: free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/activity-feed.md`, `docs/v2 Plans/v2/home-feed.html`, `docs/v2 Plans/v2/explore-feed.html`
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The Activity Feed core happy-path is wired end to end for both the web journey (SSR templates + Interactivity API stores → REST → services → DB) and the app/REST journey (the same controllers). Every interactive control in the templates is bound to a store action that calls a real, registered REST endpoint backed by a service and the spec's DB tables. No usability break was found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Open /activity → SSR home feed renders | ui | wired | `templates/feed/home.php:331-513` (composer, filter tabs, feed list, post-card loop) |
| Home feed query (own + followed + spaces + followed hashtags), cursor paginated | service/db | wired | `home.php:153-190` uses `buddynext_service('feed')->home_feed()`; inline fallback `home.php:194-252`; `includes/Feed/FeedController.php:164-176` |
| Compose + publish a post | ui→store→rest | wired | composer `partials/composer.php`; `assets/js/feed/store.js:1491-1569` POST `/posts`; `includes/Feed/PostController.php:94-129` → `PostService::create` |
| Poll post create | store→rest→service | wired | store `submit()` sends `options[]` (`store.js:1514-1528`); `PostController.php:105` maps `options`; `PostService.php:83-176` inserts `bn_poll_options` |
| Poll vote | ui→store→rest→db | wired | `store.js:1020-1048` POST `/posts/{id}/vote`; `includes/Feed/PollController.php:31-39`; UNIQUE vote constraint per spec |
| Link post | store→rest | wired | composer type=link; `PostController.php:103,336-339` parses `link_url` |
| React (emoji toggle) | ui→store→rest→db | wired | button `parts/post-actions.php:87-194`; `store.js:761-784` POST `/reactions/toggle` |
| Comment | ui→store→rest→db | wired | `parts/post-actions.php:199-218`; `store.js:947-1019` POST `/comments` |
| Share | ui→store→rest→db | wired | `parts/post-actions.php:220-233`; `store.js:832-868` + share-modal `store.js:1918` POST `/posts/{id}/share` |
| Bookmark | ui→store→rest→db | wired | `parts/post-actions.php:235-247`; `store.js:785-803` POST/DELETE `/posts/{id}/bookmark` |
| Announcement banner + dismiss | ui→store→rest | wired | `home.php:452-467` + card bar `post-card.php:358-370`; `store.js:1049-1058` POST `/feed/announcements/{id}/dismiss`; `FeedController.php:301-329` |
| Infinite scroll (append next page) | ui→store→rest | wired | trigger `home.php:515-540` (`data-rest-url=/feed/home/page`); `store.js:1820-1907` IntersectionObserver → `FeedController::home_feed_page` SSR cards `FeedController.php:345-433` |
| "N new posts" pill | store→rest | wired | `store.js:2493-2574` 60s visibility-aware poll of `/feed/new-count`; `FeedController.php:205-216` |
| Filter tabs (For you/Following/Spaces/Network) | ui→store→rest | wired | `home.php:362-424` `buddynext/feed-tabs`; `store.js:2036`; counts `FeedService::home_feed_counts` |
| Explore feed (public, guest banner, infinite scroll) | ui→service | wired | `templates/feed/explore.php:220-360`; `FeedController.php:224-231,376-392` public callback |
| Privacy / visibility gates on single post | rest/service | wired | `PostController.php:131-217` (block list, secret-space, followers-only, private) |
| Space-feed secret-space gating | rest | wired | `FeedController.php:261-290` |

---

## First break

none — journey complete.

---

## UX gaps

None proven from code. Two non-blocking notes (both consistent with spec "Known limitations"):

- `link_meta` (Open Graph) is populated asynchronously, so a freshly created link post may render without a preview thumbnail momentarily — explicitly accepted in `docs/journeys/activity-feed.md` "Known limitations".
- The home-feed source blend and announcement/empty states depend on seeded data; on an empty test account the feed correctly shows per-filter empty states (`home.php:547-594`), not a break.

---

## Minimal refactor plan

Empty — feature is usable as-is. Do not rewrite working code.

---

## Notes for the live walk

- Journey curl examples use `poll_options` for poll creation; the shipped REST handler and UI both use the `options` key (`PostController.php:105`, `store.js:1528`). UI ↔ REST agree — no code change needed; the journey doc example is illustrative.
- Verify on a seeded account (member1/member2 + one open space) per journey preconditions; an empty account hides built behavior behind empty states.
