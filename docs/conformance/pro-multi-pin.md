# Conformance: Pro — Multiple Pinned Posts

**Feature:** Multi-pin Posts (raise per-scope pin cap from 1 → 10 for Pro sites)
**Repo:** buddynext-pro
**Spec ref:** `/Users/vapvarun/dev/repos/buddynext/docs/specs/features/02-activity-feed.md` (Pro: "Multiple pinned posts (up to 10 per space or profile)"); contract `FREE-PRO-CONTRACT.md` §4 filter #3 (`buddynext_post_pin_limit`)
**Journey doc:** `/Users/vapvarun/dev/repos/buddynext-pro/docs/journeys/multi-pin-posts.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## What the feature is

This is a filter-only Pro extension. Pro ships **no** new tables, REST routes, templates, or admin screens for pinning. It raises Free's `buddynext_post_pin_limit` from 1 to 10. The entire pin UX — button, store action, REST endpoint, service enforcement, DB column — lives in Free and is already wired. Pro's only job is to return a higher cap from one filter, and Free's `PostService::pin()` enforces it.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | Member opens post options menu, clicks Pin | ui | wired | `buddynext/templates/parts/post-options-menu.php:103-104` — button `data-wp-on--click="actions.pinPost"`, label toggles Pin/Unpin from `is_pinned` |
| 2 | Store action toggles pin, fires REST POST/DELETE, surfaces error message | store | wired | `buddynext/assets/js/feed/store.js:902-931` — `pinPost()` picks POST (pin) vs DELETE (unpin), rolls back optimistic state, toasts `data.message` on non-ok (so `pin_limit_reached` text reaches the user) |
| 3 | REST routes for pin/unpin | rest | wired | `buddynext/includes/Feed/PostController.php:70-85` — POST + DELETE `/buddynext/v1/posts/{id}/pin`, auth-gated |
| 4 | Free service reads filter, counts pins per scope, rejects at cap | service | wired | `buddynext/includes/Feed/PostService.php:394-416` — `apply_filters('buddynext_post_pin_limit', 1, $space_id, $user_id)`, scope-aware COUNT, `WP_Error('pin_limit_reached')` when `$pinned_count >= $pin_limit` |
| 5 | Pro raises cap to 10 via the filter | service | wired | `buddynext-pro/includes/Feed/ProPinService.php:43-68` — `register()` adds filter at prio 10/3; `pro_pin_limit()` returns `max($limit, 10)` (never lowers a higher value) |
| 6 | Pro filter actually registered at boot | service | wired | `buddynext-pro/includes/Core/Plugin.php:191` — `\BuddyNextPro\Feed\ProPinService::register();` inside `wire_extensions()` |
| 7 | Pinned state persists and is read back | db | wired | `bn_posts.is_pinned` updated at `buddynext/includes/Feed/PostService.php:419-425`; rendered via `buddynext/templates/partials/post-card.php:57,309,331` (`isPinned` into Interactivity context) |

---

## First break

none — journey complete. Both the web journey (template → Interactivity store → REST → service → DB) and the app/REST journey are wired. Pro's cap-raise is registered unconditionally in `wire_extensions()` and consumed by Free's enforcement path.

---

## UX gaps

None proven in code. Notes for the human's live walk (not blockers):

- The journey doc's verification SQL assumes `is_pinned` lives on `wp_bn_posts`. Confirmed correct — Free uses the `is_pinned` column, not a separate table (`PostService.php:403-404`, `:419-425`).
- The pin cap is enforced per scope by `user_id` + `space_id IS NULL` (profile) or `space_id = N` (space). Profile-scope and space-scope both honored (`PostService.php:399`). No code break; live walk can confirm the 11th-pin rejection toast.

---

## Minimal refactor plan

Empty — feature is usable as-is. Do not modify working code.

---

## Notes

- App/REST clients reach the identical endpoint (`PostController.php:70-85`); the cap applies uniformly server-side, so app usability matches web.
- Integration coverage exists: `buddynext-pro/tests/Feed/ProPinServiceTest.php` (10 pins succeed, 11th fails; skips if Free absent).
