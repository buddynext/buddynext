# Conformance Dossier — Hashtags (Free)

**Feature:** Hashtags
**Repo:** free
**Spec ref:** `docs/specs/features/18-hashtags.md` (Locked, 2026-03-19) + `docs/journeys/hashtags.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Date:** 2026-05-31

## Verdict

**usable-leave-as-is**

The hashtag journey is wired end-to-end for both the web journey and the REST/app client. Extraction, registry/pivot maintenance, follow toggle, trending, autocomplete, the dedicated hashtag feed page, in-content `#tag` linkification, and the Trending block are all present and bound. No journey break found by reading code.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member posts content with `#tags`; extraction scheduled | service | wired | `includes/Hashtags/HashtagListener.php:55` (`buddynext_post_created` → dispatch), `:169` (AS or inline fallback) |
| Hashtags extracted + auto-created in `bn_hashtags`, pivot rows in `bn_post_hashtags`, counts recomputed | db | wired | `includes/Hashtags/HashtagService.php:183` (extract), `:212` (sync upsert + pivot + post_count) |
| `#tag` inside rendered post becomes clickable link to feed | ui | wired | `templates/parts/post-body.php:108-359` render via `buddynext_format_content`; `buddynext.php:309-317` linkifies `#tag` → `/activity/hashtag/{slug}/` |
| Composer `#` typeahead suggests hashtags | ui→rest | wired | `assets/js/feed/store.js:2231-2295` calls `/hashtags/autocomplete`; `HashtagController.php:57` route |
| Hashtag feed page renders at `/activity/hashtag/{slug}/` | ui | wired | `PageRouter.php:938` rewrite, `:789` → `hashtags/feed.php`; `templates/hashtags/feed.php` composes hero + posts + sidebar |
| Follow / unfollow hashtag from feed page | ui→store→rest | wired | `templates/parts/hashtag-hero.php:106` `data-wp-on--click="actions.toggleFollowHashtag"`; `assets/js/hashtags/store.js:19` calls `POST/DELETE /hashtags/{slug}/follow`; `HashtagController.php:82` route; `HashtagService.php:339` follow + follower_count |
| Trending list (Explore + block) | service→ui | wired | `HashtagService.php:520` get_trending (24h rolling window); `templates/blocks/trending-hashtags.php:21` renders via service |
| Public-only privacy enforced in feed | service | wired | `HashtagService.php:144-146` + `templates/hashtags/feed.php:106-107` (`status='published' AND privacy='public'`) |
| Bridge content (media/discussion/job) indexed | service | wired | `HashtagListener.php:58` (`buddynext_index_hashtags` → async); bridges in `includes/Bridges/` fire it |
| REST surface (list/trending/autocomplete/follow/get) | rest | wired | `HashtagController.php:36-128`; registered in `includes/REST/Router.php:81`; listener bootstrapped in `includes/Core/Plugin.php:195` |

## First break

none — journey complete

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Spec text says feed lives at `/hashtag/{slug}/` but the implemented route (and every internally generated link) is `/activity/hashtag/{slug}/`. This is internally consistent — links from `buddynext_format_content`, `PageRouter::hashtag_feed_url`, and the JS store all point at the activity-prefixed path — so no user ever hits a dead URL. Only a literal browser visit to the bare `/hashtag/{slug}/` would 404. Cosmetic spec/URL-shape drift, not a journey break. | low | confirmed-in-code | spec `docs/specs/features/18-hashtags.md:26` vs `buddynext.php:313`, `PageRouter.php:1316`, `PageRouter.php:938` |
| Plain REST list endpoint `GET /hashtags?q=` is walked in the journey doc, but the controller exposes `GET /hashtags/autocomplete?q=` instead (no bare `/hashtags` collection route). Autocomplete covers the search-by-prefix need for both web and app; a bare collection list is not reached by any UI. App/REST clients expecting `GET /hashtags` per the journey doc would 404. | low | confirmed-in-code | `docs/journeys/hashtags.md:209` vs `HashtagController.php:36-128` (no `/hashtags` collection route) |

## Minimal refactor plan

EMPTY — usable, leave as-is. The two items above are spec/doc drift of low severity; no code change is required to complete the user journey. If documentation accuracy is desired, update the spec/journey docs to reference `/activity/hashtag/{slug}/` and `GET /hashtags/autocomplete` rather than altering working code.

## Notes for the human browser walk

- Visit `/activity/hashtag/buddynext/` (not `/hashtag/buddynext/`).
- Confirm follow toggle flips label + persists (check `bn_hashtag_follows` and `follower_count`).
- Type `#bud` in the composer to confirm the autocomplete dropdown hits `/hashtags/autocomplete`.
- Confirm `#tags` in a rendered post are clickable and land on the feed page.
- Seed several public posts across 2 users in the last 24h before judging the Trending block — trending uses a rolling 24h window (`HashtagService.php:538`), so an empty/old dataset will legitimately show "Nothing trending yet".
