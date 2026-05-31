# Conformance — Activity Feed (Free)

**Feature:** Activity Feed (Free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/activity-feed.md`, `docs/v2 Plans/v2/home-feed.html`, `docs/v2 Plans/v2/explore-feed.html`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The Activity Feed is fully wired for both the web journey and the REST/app journey.
Every happy-path step in the journey doc and every core post type in the locked spec
has a real UI control bound to an Interactivity API store action that calls a
registered REST endpoint, which delegates to a service that writes the spec'd tables.

The journey doc is written as a REST walk, but the prime-directive question — "is
there a UI control reaching each endpoint?" — resolves YES for all of them. No
api-only gaps were found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Open `/activity` → home feed renders SSR | ui | wired | `includes/Core/PageRouter.php:805-813` resolves hub→`feed/home.php`; `templates/feed/home.php:469-513` renders cards |
| Feed bundle (store + CSS) loads on hub | store | wired | `PageRouter.php:628-630` (`case 'feed': $assets->enqueue('feed')`); `AssetService.php:373-377` enqueues `@buddynext/feed` module |
| Home feed query (own + followed + spaces + hashtags) | service | wired | `home.php:155-190` calls `FeedService::home_feed()`; inline keyset fallback `home.php:194-253` |
| Compose text post → Share | ui→store→rest | wired | `partials/composer.php:328-337` `actions.submit`; `store.js:1488/1553/1594` `POST /posts`; `PostController.php:38` route; `PostService::create():71` → `bn_posts` |
| Compose poll post | ui→service | wired | composer poll inputs `composer.php:174-199`; `PostService.php:82,167-168` `insert_poll_options()` → `bn_poll_options` |
| Compose link post (auto OG) | service | wired | `PostService.php:119,134-136` stores `link_url` + async `link_meta` |
| Vote on poll | ui→store→rest | wired | `store.js:1020-1027` `actions.votePoll` → `POST /posts/{id}/vote`; `PollController.php:33`; UNIQUE on `bn_poll_votes` |
| React (emoji picker) | ui→store→rest | wired | `parts/post-actions.php:185` `actions.setReaction`; `store.js:773` `POST /reactions/toggle` → `bn_reactions` |
| Comment | ui→store→rest | wired | `post-actions.php:208` `actions.openComments`; `store.js:947-992` `actions.submitComment` → `POST /comments` → `bn_comments` |
| Share | ui→store→rest | wired | `post-actions.php:226` `actions.openShare`; `store.js:856`/`1892` `POST /posts/{id}/share` → `bn_shares` |
| Bookmark (toggle POST/DELETE) | ui→store→rest | wired | `post-actions.php:240` `actions.toggleBookmark`; `store.js:785-794` method switch on state; `BookmarkController.php:41,46` → `bn_bookmarks` |
| Announcement banner + dismiss | ui→store→rest | wired | `home.php:452-467` `actions.dismiss`; `store.js:1052`/`1649` `POST /feed/announcements/{id}/dismiss`; `FeedController.php:251` |
| Filter tabs (For you/Following/Spaces/Network) | ui→store→rest | wired | `home.php:362-424` `actions.setFilter`; `store.js:1982` (`buddynext/feed-tabs`); `FeedController.php:46-52` enum-validated |
| Infinite scroll (cursor) | ui→store→rest | wired | `home.php:515-540` `data-bn-infinite-feed`; `store.js:1768-1795` → `/feed/home/page`; `FeedController.php:96-112,295-315` renders identical cards |
| Explore feed (public, guest-safe) | ui→service | wired | `PageRouter.php:805` → `feed/explore.php`; `explore.php:204,360-363` infinite scroll → `/feed/explore/page` (public, `FeedController.php:69-74,326`) |
| Empty home feed → curated empty state, not 404 | ui | wired | `home.php:547-593` per-filter empty states with CTA |

---

## First break

none — journey complete.

---

## UX gaps

None confirmed in code that break the journey.

Notes (not journey breaks):
- `link_meta` (OG preview) is populated asynchronously; a freshly posted link card may
  render without thumbnail/title at first paint. Documented as intended in the journey
  doc "Known limitations"; the card degrades gracefully (`post-card.php:298-302`).
- Bridge post types (`media`, `discussion`, `job`) only render when the respective
  plugin is active (spec line 41) — not verifiable in the Free repo alone and not part
  of the Free happy path.

---

## Minimal refactor plan

Empty. The feature is usable end-to-end; no rewiring required.

---

## Verification confidence

All statuses are `confirmed-in-code`: UI control → store action → REST route → service
→ table was read for each step. The only items left to a live walk are the async
OG-preview timing and bridge post types (require Pro / addon plugins active), neither
of which is on the Free web journey.
