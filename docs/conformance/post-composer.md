# Conformance — Post Composer & Posts

**Feature:** Post Composer & Posts (free)
**Spec ref:** docs/specs/features/02-activity-feed.md (Locked, 2026-03-19)
**UX intent:** docs/v2 Plans/v2/post-detail.html
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Summary

The core happy path — a logged-in member opens /activity, types into the always-visible
composer, optionally adds media / poll / privacy, clicks Share, the post is created via
REST, and is then viewable in the home feed and at its `/p/{id}/` permalink with
react/comment/share/pin/edit/delete — is wired end to end across all five layers. UI
controls bind to the `buddynext/post-composer` Interactivity store, which POSTs to
`buddynext/v1/posts`, which calls `PostService::create()`, which writes `bn_posts` (and
`bn_poll_options` for polls). Routes are registered (REST/Router.php) and the feed module
is enqueued on both the activity hub and the single-post page (PageRouter::enqueue_hub_assets).

No journey break found. The only observations are minor polish items, not usability blocks.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Composer renders on /activity (avatar, textarea, tools, privacy chip, Share) | ui | wired | templates/feed/home.php:326; templates/partials/composer.php:82 |
| Composer bound to Interactivity store; submit/poll/media/privacy actions | store | wired | templates/partials/composer.php:83,333; assets/js/feed/store.js:1212 |
| Share click → POST /posts with content, type, privacy, media_ids, options | store→rest | wired | assets/js/feed/store.js:1445-1480 |
| POST /posts route registered, auth-gated | rest | wired | includes/Feed/PostController.php:42-46; includes/REST/Router.php:67 |
| create_post sanitizes + delegates to service | rest→service | wired | includes/Feed/PostController.php:94-128 |
| PostService::create validates type/poll/announcement, safeguard, inserts row | service→db | wired | includes/Feed/PostService.php:70-189 |
| Poll options written to bn_poll_options atomically | db | wired | includes/Feed/PostService.php:156-158,515-540 |
| buddynext_post_created + @mention actions fire | service | wired | includes/Feed/PostService.php:167-187 |
| New post appears in home feed (own + followed + spaces + hashtags) | db→ui | wired | templates/feed/home.php:183-229,458-502 |
| Post card renders with react/comment/share/bookmark/pin footer | ui | wired | templates/partials/post-card.php:320-538 |
| Permalink /p/{id}/ deep-links with visibility gates + comment thread | ui | wired | templates/feed/single-post.php:40-198 |
| Edit / delete / pin own post over REST | rest→service→db | wired | includes/Feed/PostController.php:225-309; includes/Feed/PostService.php:242-433 |
| Composer draft autosave/restore (localStorage + optional cloud sync) | store→rest | wired | includes/Feed/ComposerDraftController.php:48-130; assets/js/feed/store.js:1176-1212 |
| Feed module enqueued on activity + post hubs | infra | wired | includes/Core/AssetService.php:373-378; includes/Core/PageRouter.php:611-632 |

---

## First break

none — journey complete.

---

## UX gaps (minor, non-blocking)

1. **Publish does a full `window.location.reload()` instead of optimistic prepend.**
   Severity: low. Confidence: confirmed-in-code (assets/js/feed/store.js:1489). The new
   post does appear (reload re-runs the feed query), so the journey completes. The spec's
   "X new posts" bar (02-activity-feed.md:83) is a separate feed-refresh affordance and is
   not a composer-publish requirement; no contract prohibits the reload. Polish only.

2. **Composer poll UI is capped at 4 option inputs in markup; spec allows up to 5.**
   Severity: low. Confidence: confirmed-in-code (templates/partials/composer.php:187-198
   renders 4 `.bn-composer__poll-option` inputs; PostService accepts up to 5 at
   includes/Feed/PostService.php:89-94). A 5th option cannot be entered from the web
   composer, but 2–4 polls — the common case — work fully. Web-only gap; REST clients can
   send 5. Not a journey break.

Both items are needs-no-action for the locked happy path. Listed for completeness per
the no-skipping-known-issues rule; neither stops a member from composing and publishing.

---

## Minimal refactor plan

None — usable, leave as is. (The two gaps above are optional polish, not wiring fixes; do
not rewrite working publish/feed code for them.)
