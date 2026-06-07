# Conformance: Hashtags (free)

**Spec ref:** `docs/specs/features/18-hashtags.md` (Locked, 2026-03-19)
**Journey ref:** `docs/journeys/hashtags.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The Hashtags feature is fully wired end-to-end for both the web journey and the
app/REST journey. A member can: write `#tags` in a post → tags auto-extract and
register → tap a clickable hashtag in any rendered post → land on the
`/activity/hashtag/{slug}/` feed page → follow/unfollow → see trending tags on
Explore and in the Trending Hashtags block → use `#` autocomplete in the
composer. Every UI control is bound to an Interactivity API store action that
hits a real, registered REST endpoint with a nonce. No usability break found.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Write `#tag` in post → async extraction scheduled | service | wired | `includes/Hashtags/HashtagListener.php:55` (`buddynext_post_created`), `:169` (AS dispatch / inline fallback) |
| Extract + register + link in pivot, recompute counts | service/db | wired | `includes/Hashtags/HashtagService.php:183` (extract), `:212` (sync upsert + `bn_post_hashtags` + post_count) |
| Listener registered at boot | service | wired | `includes/Core/Plugin.php:195` |
| Rendered post content linkifies `#tag` → feed URL | ui | wired | `buddynext.php:309` → `home_url('/activity/hashtag/{slug}/')`; emitted via `buddynext_format_content()` in `templates/parts/post-body.php:109,125` |
| Rewrite rule maps `/activity/hashtag/{tag}/` → feed hub | rest | wired | `includes/Core/PageRouter.php:960` |
| Hashtag feed page renders hero, posts, sidebars | ui | wired | `templates/hashtags/feed.php:252`, `templates/parts/hashtag-hero.php:85` |
| Follow toggle button → store action → REST | store | wired | hero button `data-wp-on--click="actions.toggleFollowHashtag"` (`hashtag-hero.php:106`); action `assets/js/hashtags/store.js:19` POST/DELETE `hashtags/{slug}/follow` with `X-WP-Nonce` |
| Follow/unfollow endpoint + follower_count | rest | wired | `includes/Hashtags/HashtagController.php:82` (route, `is_user_logged_in`, 404 on unknown), `HashtagService.php:339`/`:374` |
| Hashtags store module enqueued on feed page | ui | wired | `includes/Core/PageRouter.php:630` enqueues feed+hashtags; `includes/Core/AssetService.php:323,373` |
| Composer `#` autocomplete → REST | store | wired | `assets/js/feed/store.js:2301` typeahead → `:2361` GET `hashtags/autocomplete`; attached `:2167`; endpoint `HashtagController.php:57` |
| Trending list (Explore + block) | rest | wired | `templates/feed/explore.php:93`; block `templates/blocks/trending-hashtags.php:44`; `HashtagController.php:37` → `HashtagService::get_trending()` (24h window, `:520`) |
| Unified search surfaces hashtags | ui | wired | `templates/parts/search-result-section-hashtags.php:114` links to feed |
| Hashtag feed privacy (public-only) | db | wired | `HashtagService::get_feed()` `WHERE p.privacy='public' AND p.status='published'` (`:144`); template queries enforce same (`templates/hashtags/feed.php:107,127,146`) |

---

## First break

none — journey complete.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Journey doc step 12 calls `GET /buddynext/v1/hashtags?q=buddy` (bare list with `?q=`), but no bare `/hashtags` route is registered — only `/hashtags/autocomplete`, `/hashtags/trending`, `/hashtags/{slug}`. A bare `?q=buddy` is captured by the `/{slug}` wildcard and 404s. Journey-doc/REST naming drift, not a web break: composer autocomplete and search both call the correct `/hashtags/autocomplete`. | low | confirmed-in-code | `includes/Hashtags/HashtagController.php:37-128`; journey `docs/journeys/hashtags.md:146` |
| Spec lists `bn_hashtags.is_banned` column, but banning is implemented via the `buddynext_banned_hashtags` option (extraction-time strip), not a column read. Functionally satisfies "banned tags stripped on extraction" but diverges from the documented data model. No user-facing impact. | low | confirmed-in-code | `HashtagService.php:57,193`; spec `docs/specs/features/18-hashtags.md:87` |

Neither gap stops a real user completing the core happy path.

---

## Minimal refactor plan

EMPTY — feature is usable; leave as-is. The two gaps are documentation/data-model
drift, not usability breaks; per the prime directive they are not grounds for
rewriting working code. Cheapest optional fix is correcting journey step 12 to
reference `/hashtags/autocomplete?q=` (no code change).

---

## Notes for the live walk

- Entry: http://buddynext-dev.local/activity — create a post with `#buddynext`, then click the rendered `#buddynext` link in the post card.
- Async extraction: on local (no Action Scheduler) it runs inline via `do_action_ref_array` fallback (`HashtagListener.php:172`), so tags appear immediately.
- Seed several public posts across two members using the same tag before checking `/activity/hashtag/buddynext/` and Explore trending; trending uses a rolling 24h window.
- Follow button state is optimistic + rolls back on REST failure; confirm persistence by reloading (re-reads `bn_hashtag_follows`).
