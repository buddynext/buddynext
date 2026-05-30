# Conformance: Activity Feed

**Feature:** Activity Feed (Free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/activity-feed.md`
**Contracts checked:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The core happy-path journey — land on the home feed, compose a post (text / photo / poll / link),
react, comment, share, bookmark, vote on a poll, dismiss an announcement, paginate — is fully wired
end to end for the web journey. Every interactive control in the templates binds a `data-wp-on--*`
handler to a WP Interactivity API store action in `assets/js/feed/store.js`, and every store action
calls a registered `buddynext/v1` REST route whose controller delegates to a service that writes the
spec's tables. The feed script module + CSS are enqueued on the feed hub route. No journey-stopping
break was found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Land on `/activity`, feed renders (SSR posts, composer, tabs, announcement) | ui | wired | `templates/feed/home.php:319-592`; module enqueued at `includes/Core/PageRouter.php:613` |
| Home feed query (own + followed + joined spaces + followed hashtags), cursor keyset | service/db | wired | `templates/feed/home.php:181-242` (SSR); `includes/Feed/FeedService.php` `home_feed()`; reads `bn_posts`,`bn_follows`,`bn_space_members`,`bn_hashtag_follows` |
| Filter tabs (For you / Following / Spaces / Network) | ui→store→rest | wired | tabs `templates/feed/home.php:351-413` `actions.setFilter`; `store.js:1970-1996`; `GET /feed/home` `includes/Feed/FeedController.php:38-54` |
| Compose + publish post (text/photo) | ui→store→rest→service→db | wired | `templates/partials/composer.php:328-337` `actions.submit`; `store.js:1435-1503` `POST /posts`; `includes/Feed/PostController.php:94-128`; `PostService::create()` → `bn_posts` |
| Compose poll (options) | ui→store→rest→service→db | wired | composer poll inputs `composer.php:174-199`; `store.js:1458-1472` sends `options[]`; `PostController.php:105` passes `options`; `PollService` writes `bn_poll_options` |
| Compose link post | ui→store→rest→service | wired | `store.js:1448` `type:link`; `PostController.php:103` `parse_link_url()`; stored on `bn_posts.link_url` |
| React (emoji picker toggle → set) | ui→store→rest→service→db | wired | `templates/parts/post-actions.php:86-135` `toggleReactionPicker`/`setReaction`; `store.js:757-784` `POST /reactions/toggle`; writes `bn_reactions`, adjusts `reaction_count` |
| Comment (open → submit, optimistic append) | ui→store→rest→service→db | wired | `post-actions.php:137-156` `openComments`; comment form part `submitComment`; `store.js:935-994` `POST /comments` → `bn_comments` |
| Share (modal) | ui→store→rest→service→db | wired | `post-actions.php:158-171` `openShare` dispatches `bn:open-share-modal`; share-modal store `store.js:1852+` `POST /posts/{id}/share`; `ShareService` → `bn_shares` |
| Bookmark (toggle) | ui→store→rest→service→db | wired | `post-actions.php:173-185` `toggleBookmark`; `store.js:785-803` `POST/DELETE /posts/{id}/bookmark`; `BookmarkService` → `bn_bookmarks` |
| Vote on poll (inline) | ui→store→rest→service→db | wired | poll body part option buttons `votePoll`; `store.js:1008-1036` `POST /posts/{id}/vote`; `includes/Feed/PollController.php:31-40`; `PollService::vote()` → `bn_poll_votes` (UNIQUE), updates `vote_count` |
| Dismiss announcement | ui→store→rest→service | wired | `home.php:449-454` + `post-card.php:362-367` `dismissAnnouncement`; `store.js:1037`/`1628`; `POST /feed/announcements/{id}/dismiss` `FeedController.php:124-132,251-279` (user_meta) |
| Infinite scroll / load more | ui→rest→service | wired | trigger `home.php:504-529` `data-bn-infinite-feed`; `GET /feed/home/page` returns server-rendered cards `FeedController.php:96-112,295-315` |
| Empty-state (fresh user, per-filter) | ui | wired | `home.php:536-583` — curated CTA per filter, not a 404; matches journey edge case |
| Explore feed (public, guest-readable) | ui→rest→service | wired | `templates/feed/explore.php`; `GET /feed/explore` `__return_true` `FeedController.php:66-74` |
| Privacy/visibility gating (followers/private/secret-space/blocked) | service | wired | `PostController.php:159-209` gates 1-4; feed SSR excludes suspended/shadow-banned `home.php:67-88` |

---

## First break

none — journey complete.

---

## UX gaps

(none journey-stopping)

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Post publish does a full `window.location.reload()` after success rather than prepending the new card optimistically. Functional and not a break, but a heavier UX than the v2 prototype implies. | low | confirmed-in-code | `assets/js/feed/store.js:1489` |

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## Notes for the live walk

- Seed data first: a fresh account shows the (correct) empty state, which can read as "broken." Walk
  with a followed user / joined space that has posts.
- The isolation mu-plugin can strip the plugin on front-end routes in local dev; if the feed renders
  in CLI/REST but is dead in the browser, check the whitelist before suspecting code.
- `link_meta` (OG unfurl) is populated async — may be null immediately after a link post is created.
