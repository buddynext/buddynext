# Conformance: Multi-pin Posts (Pro)

**Feature:** Multiple pinned posts (raise pin cap from 1 → 10 per scope)
**Repo:** buddynext-pro
**Spec ref:** `buddynext/docs/specs/features/02-activity-feed.md` — "Pro: Multiple pinned posts (up to 10 per space or profile)"
**Journey doc:** `buddynext-pro/docs/journeys/multi-pin-posts.md`
**Live-walk URL:** http://buddynext-dev.local/activity

---

## Verdict: partial-needs-wiring

The Pro backend extension is correctly built and wired. The Free pin REST endpoint and service correctly consume the filter. The pin UI control exists and is bound to a store action. **But the per-post Interactivity context omits `isPinned`, so the kebab "Unpin" control can never send a DELETE — it always POSTs.** A web user cannot unpin a post from the UI, which breaks the "unpin to free a slot" leg of the journey (Part 4). The feature is fully usable for REST/app clients (which call DELETE directly).

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Pro registers pin-limit filter at boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:190` (`ProPinService::register()` in `wire_extensions()`) |
| 2 | Filter raises cap 1 → 10, never lowers | service | wired | `buddynext-pro/includes/Feed/ProPinService.php:44,67` (`add_filter('buddynext_post_pin_limit',…,10,3)`; `max($limit,10)`) |
| 3 | Free `PostService::pin()` reads the filter & enforces cap | service | wired | `buddynext/includes/Feed/PostService.php:366` (apply_filters), `:382` (count ≥ limit → WP_Error `pin_limit_reached`) |
| 4 | Pin state persisted on `bn_posts.is_pinned` | db | wired | `buddynext/includes/Core/Installer.php:402` (`is_pinned TINYINT(1)`); `PostService.php:393` update |
| 5 | REST `POST/DELETE /posts/{id}/pin` exists, auth-gated | rest | wired | `buddynext/includes/Feed/PostController.php:72-84`; handlers `:279,:298` |
| 6 | Post card emits per-post Interactivity context | ui | broken | `buddynext/templates/partials/post-card.php:322-348` — context has `postId`, `reactNonce`, `restUrl` but **no `isPinned`** |
| 7 | Kebab "Pin/Unpin" control bound to store action | ui | wired | `buddynext/templates/parts/post-options-menu.php:103` (`data-wp-on--click="actions.pinPost"`); `can_pin` set `post-card.php:219` |
| 8 | `pinPost` action toggles + calls REST | store | broken | `buddynext/assets/js/feed/store.js:902-918` — reads `ctx.isPinned` (undefined) → `method: prev ? 'DELETE':'POST'` is **always POST**; unpin impossible from UI |
| 9 | Limit-reached feedback to user | store | broken | `store.js:913-915` — on non-ok response, silently rolls back `isPinned`, **no toast/error** shown |

---

## First break

**Step 6 / Step 8** — `buddynext/templates/partials/post-card.php:322-348` omits `isPinned` from `data-wp-context`. The earliest broken link: the store action at `assets/js/feed/store.js:904` reads `ctx.isPinned` as `undefined`, so the DELETE branch is unreachable and web users cannot unpin.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|-----------|----------|
| Web user cannot unpin a post from the kebab menu — action always POSTs because `ctx.isPinned` is undefined; button shows "Unpin" but re-pins | high | confirmed-in-code | `store.js:904-908` vs `post-card.php:322-348` (no `isPinned` key) |
| Hitting the 10-pin cap gives no feedback — action silently reverts the optimistic toggle with no toast/error, so the user sees the post quietly un-pin itself | medium | confirmed-in-code | `store.js:913-915` |
| Pins are always profile-scope: REST `pin_post` calls `pin($post_id,$user_id)` without `$space_id`, so the per-space cap path in `PostService::pin()` is never reached from the web/REST surface (spec promises "per space or profile") | medium | confirmed-in-code | `PostController.php:282` (no 3rd arg) vs `PostService.php:348,371` |

App/REST clients are unaffected by the first two gaps — they call DELETE directly and read the structured WP_Error. The scope gap (#3) affects all clients equally.

---

## Minimal refactor plan

1. `buddynext/templates/partials/post-card.php` — add `'isPinned' => $is_pinned,` to the `data-wp-context` array (~line 347), reusing the existing `$is_pinned` computed at line 57. This alone fixes the unpin journey.
2. `buddynext/assets/js/feed/store.js` `pinPost()` (~line 913) — on `!res.ok`, surface the server message via `bnToast(... , {tone:'danger'})` (mirror the pattern already used by the comment-pin handler at `store.js:408`) so the 10-cap rejection is visible.
3. (Optional, spec-alignment) `buddynext/includes/Feed/PostController.php:282` — thread a `space_id` request param into `->pin($post_id, $user_id, $space_id)` so space-scope pins enforce a per-space cap as the spec describes. Lower priority; does not block the core "up to 10" journey.

Steps 1–2 are the minimum to make the web journey 100% usable and reuse existing working code (the service, REST, filter, and toast helper all already work).
