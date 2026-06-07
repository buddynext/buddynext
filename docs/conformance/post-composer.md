# Conformance — Post Composer & Posts

**Feature:** Post Composer & Posts (repo: free)
**Spec ref:** `docs/specs/features/02-activity-feed.md` (Locked 2026-03-19); UX intent cross-checked against `docs/v2 Plans/v2/post-detail.html`; contracts `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `17-roles-permissions.md` (visibility).
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is
**Date:** 2026-05-31 (re-verified)

## Core happy path

A logged-in member opens `/activity`, types into the always-visible composer, optionally
attaches media / makes it a poll / picks a privacy level / schedules it, clicks **Share**, and
the new post is created via REST and appears in the home feed. The post is then readable as a
card and at its own `/p/{id}/` permalink with reactions and an auto-expanded comment thread.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/activity` dispatches home feed + enqueues the feed bundle | service | wired | `includes/Core/PageRouter.php:630` (`$assets->enqueue('feed')`) |
| Home template renders the composer partial | ui | wired | `templates/feed/home.php:337` (`buddynext_get_template('partials/composer.php', …)`) |
| Composer textarea + Share bound to Interactivity store | ui | wired | `templates/partials/composer.php:83` (`data-wp-interactive="buddynext/post-composer"`), `:148` (`actions.onInput`), `:367` (`actions.submit`) |
| Store feature module registered + enqueued | store | wired | `includes/Core/AssetService.php:314-315` (`@buddynext/feed => feed/store`), `:377` (`wp_enqueue_script_module`) |
| `submit` builds payload (content/privacy/type/media/poll/schedule), POSTs `/posts` with `X-WP-Nonce` | store | wired | `assets/js/feed/store.js:1491-1568`; fetch at `:1540`; poll `:1514-1529`; schedule `:1534-1537`; media `:1508-1513` |
| REST route `POST buddynext/v1/posts` registered, auth-gated | rest | wired | `includes/Feed/PostController.php:38-46`, `:346-356` (`require_auth`); registered `includes/REST/Router.php:68` |
| Controller sanitizes + delegates to service | rest | wired | `includes/Feed/PostController.php:94-128` (`create_post`) |
| Service validates type/poll/announcement/suspension/safeguard, writes row, fires `buddynext_post_created`, parses @mentions | service | wired | `includes/Feed/PostService.php:71-218` |
| Row persisted in `bn_posts` (+ `bn_poll_options` for polls) | db | wired | `includes/Feed/PostService.php:152-170` (insert), `:620-646` (`insert_poll_options`) |
| New post read back into feed (Pro AI-rank rebind on SSR; defensive inline fallback) | service | wired | `templates/feed/home.php:155-190` (FeedService::home_feed), `:194-253` (inline keyset fallback) → renders `partials/post-card.php` `:503` |
| Post card renders content/media/poll/link + react/comment/share/edit/delete/pin wiring | ui/store | wired | `templates/partials/post-card.php:217-224` (caps), `:320` (`buddynext/post-card`), `:343` (`commentsOpen => 'single' === $context`); store handlers `assets/js/feed/store.js:628` |
| Permalink detail `/p/{id}/` with auto-expanded thread + server-side visibility gates | ui/service | wired | `templates/feed/single-post.php:140-198` (card, `context='single'`), `:42-101` (block/secret-space/followers/private/suspended gates mirroring `PostController::get_post` `:147-217`) |
| Drafts persist locally; optional cross-device cloud sync | store/rest | wired | `assets/js/feed/store.js:1475` (`scheduleDraftSave`); `POST/GET/DELETE /me/drafts` `includes/Feed/ComposerDraftController.php:48-130` (registered `Router.php:72`) |

## First break

none — journey complete. Every link from the composer UI through the Interactivity store, the
`POST /posts` REST route, `PostService::create()`, the `bn_posts` write, feed render, post-card
actions, and the `/p/{id}/` detail page is present and bound. All REST calls use the `X-WP-Nonce`
header generated with `wp_create_nonce('wp_rest')` under `buddynext/v1`, matching
`REST-FRONTEND-CONTRACT.md`. The home feed routing through the container-bound `FeedService` with
a defensive inline chronological fallback (`home.php:148-253`) directly defends against the
isolation-mu-plugin failure mode — a strength, not a gap.

## UX gaps (none block the journey)

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| `link` post type has no dedicated composer tool button; `actions.openLink()` exists but nothing invokes it. Link posts still work (URL pasted into content; server auto-fetches OG meta when `link_url` is set), so this is a missing convenience control, not a broken path. | low | confirmed-in-code | `store.js:1467-1471` (`openLink` defined); `composer.php:226-280` tools row has image/poll/event/schedule only; OG auto-fetch `PostService.php:143-145,732-776` |
| Composer publish does a full `window.location.reload()` instead of optimistically prepending the new card or using the spec's "X new posts" bar. Functionally correct; heavier than spec-ideal. | low | confirmed-in-code | `store.js:1555` |
| Schedule tool renders in the Free composer though spec marks Schedule as Pro. In Free, `create()` stores `scheduled_at` but keeps `status='published'`; the feed query hides future-dated rows via `(p.scheduled_at IS NULL OR p.scheduled_at <= NOW())`, so a Free user still gets correct hidden-until-time behavior. Pro's ScheduledPostsIntegration refines it. Not a break. | low | confirmed-in-code | `composer.php:253-260`; `PostService.php:149,166`; `home.php:203` |
| `voice_room` is submitted by `submitVoice` but is not in `PostService::ALLOWED_TYPES`, so a raw submit would be rejected `invalid_post_type`. Path is Pro-gated (no Free composer button triggers it), hence unreachable from the Free UI. | low | needs-live-verification | `PostService.php:36-48` (allows `event`, not `voice_room`); `store.js:1624-1664`; Pro tool slot `composer.php:271-279` |

## Minimal refactor plan

(empty — usable-leave-as-is)

The gaps above are convenience/polish or Pro-gated unreachable code, none stop a member from
composing, publishing, viewing, or interacting with a post. Per the prime directive, no refactor
of working code is proposed.
