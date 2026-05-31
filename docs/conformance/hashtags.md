# Conformance: Hashtags

**Feature:** Hashtags (repo: free)
**Spec ref:** `docs/specs/features/18-hashtags.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/hashtags.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-minor-polish

---

## Summary

The hashtag feature is fully wired end-to-end for both the web journey and the
app/REST journey. Inline `#tags` in post content render as clickable links to
the hashtag feed page; the hashtag feed page renders posts, stats, and a
follow toggle bound to the REST endpoint via the Interactivity API store; the
Gutenberg Trending Hashtags block and the hashtag-feed sidebar/related chips
all navigate to the correctly-registered route. Extraction, sync, follow,
trending, and autocomplete are all implemented at the service + REST layer.

The single real defect found is a **secondary surface**: the legacy classic
`TrendingHashtagsWidget` links to `/hashtag/{name}/` — a path that is **not**
registered. The registered rewrite is `^activity/hashtag/([^/]+)` (see
`PageRouter.php:955`), so this classic widget's links 404. This is not on the
primary happy path (which uses inline links + the Gutenberg block, both
correct), so the verdict stays usable with minor polish.

---

## Journey chain

Core happy path: member reads a post on `/activity` -> taps an inline `#tag` ->
lands on the hashtag feed -> follows the hashtag -> followed-hashtag content can
appear in home feed. Plus the REST surface walked by the journey doc.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member creates post w/ `#tags` (REST `POST /posts`) fires `buddynext_post_created` | rest/service | wired | `includes/Hashtags/HashtagListener.php:55` |
| 2 | Extraction dispatched async (Action Scheduler) or inline fallback | service | wired | `HashtagListener.php:81,169-175` |
| 3 | `#tag` slugs extracted, lowercased, banned stripped | service | wired | `HashtagService.php:183-199` |
| 4 | Upsert `bn_hashtags`; pivot `bn_post_hashtags`; `post_count` recomputed | service/db | wired | `HashtagService.php:212-330` |
| 5 | Inline `#tag` rendered as clickable link on `/activity` | ui | wired | `buddynext.php:308-317`; used `templates/parts/post-body.php:109,125,166` |
| 6 | Route `/activity/hashtag/{slug}/` resolves to feed template | rest(routing) | wired | `PageRouter.php:955-956`,`806-807`,`633` |
| 7 | Feed template renders hero, posts, follow button, sort tabs | ui | wired | `templates/hashtags/feed.php:252-358`; `templates/parts/hashtag-hero.php:99-152` |
| 8 | Follow button bound to store action calling REST | store | wired | `hashtag-hero.php:106`; `assets/js/hashtags/store.js:19-96` |
| 9 | REST `POST/DELETE /hashtags/{slug}/follow` toggles + updates `follower_count` | rest/service/db | wired | `HashtagController.php:82-111,164-196`; `HashtagService.php:339-401` |
| 10 | REST `GET /hashtags/trending` ordered list (24h weighted) | rest/service | wired | `HashtagController.php:137-142`; `HashtagService.php:520-553` |
| 11 | REST `GET /hashtags/{slug}` hashtag detail | rest/service | wired | `HashtagController.php:204-213`; `HashtagService.php:482-508` |
| 12 | REST `GET /hashtags/autocomplete?q=` composer suggestions | rest/service | wired | `HashtagController.php:57-80,150-156`; `HashtagService.php:442-474` |
| 13 | Controller + listener + service registered at boot | rest | wired | `REST/Router.php:82`; `Core/Plugin.php:195,657` |
| 14 | Gutenberg Trending block links to feed (primary trending surface) | ui | wired | `templates/blocks/trending-hashtags.php:44` |
| 15 | Privacy: feed surfaces public+published posts only | service | wired | `HashtagService.php:144-146`; `templates/hashtags/feed.php:106-107,145-146` |
| 16 | Legacy classic `TrendingHashtagsWidget` link target | ui | broken | `includes/Widgets/TrendingHashtagsWidget.php:65` (`/hashtag/{name}/` unregistered -> 404) |

---

## First break

None on the primary happy path -- journey complete. The earliest defect is at
step 16, a **secondary surface** (classic WP sidebar widget), not the core
flow: `includes/Widgets/TrendingHashtagsWidget.php:65` builds links to
`/hashtag/{name}/` which 404 because the registered rewrite requires the
`activity/` prefix (`PageRouter.php:955`). It also links by `name` rather than
`slug`.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|-----------|----------|
| Classic `TrendingHashtagsWidget` links 404 (wrong path `/hashtag/{name}/` vs registered `/activity/hashtag/{slug}/`; uses `name` not `slug`) | medium | confirmed-in-code | `includes/Widgets/TrendingHashtagsWidget.php:64-66` vs `includes/Core/PageRouter.php:955` |
| Composer `#` autocomplete dropdown UI not located in feed composer (REST endpoint exists/works; no web consumer found in static read) | low | needs-live-verification | `HashtagController.php:57-80` present; no consumer in `assets/js/feed/store.js` / composer parts |

Note on autocomplete: the spec lists composer `#` autocomplete. The REST
endpoint is fully implemented and serves app/REST clients. A web-composer
dropdown consumer was not located in static read; flagged
needs-live-verification, not broken. It does not block the documented happy
path.

---

## Minimal refactor plan

1. In `includes/Widgets/TrendingHashtagsWidget.php:64-66`, replace the
   hand-built `home_url( '/hashtag/' . rawurlencode( $row->name ) )` with
   `\BuddyNext\Core\PageRouter::hashtag_feed_url( $row->slug )` and select
   `slug` (not `name`) in the widget query so links resolve and match the
   canonical route. Reuses the helper already used by the Gutenberg block
   (`templates/blocks/trending-hashtags.php:44`).
