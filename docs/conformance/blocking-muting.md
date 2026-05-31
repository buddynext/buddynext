# Conformance: Blocking & Muting

**Feature:** Blocking & Muting (repo: free)
**Spec ref:** `docs/specs/features/01-social-graph.md` (Block/Mute section + Data Stored `bn_blocks`)
**Journey ref:** `docs/journeys/social-graph.md` (Part 3: Block / Unblock + Mute edge case)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/members/varundubey/

## Verdict: usable-leave-as-is

The block / mute / restrict journey is wired end-to-end for BOTH the web UI (Interactivity API) and REST/app clients. UI controls on the member profile reach store actions that call the registered REST routes, which delegate to `BlockService`, which writes `bn_blocks` and fires the spec hooks. An out-of-profile management surface (profile settings) closes the unblock/unmute loop. No usability break found.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Profile context + state seed (isBlocked/isMuted/isRestricted, restNonce, profileUserId, peopleUrl) | ui | wired | `templates/profile/view.php:317-344` (state computed from `bn_blocks` at `:71-73`) |
| More-menu Mute/Restrict/Block buttons, gated to logged-in non-owner | ui | wired | `templates/parts/profile-hero.php:407` (gate), `:501-516` (buttons) |
| Block confirmation modal (destructive guard) | ui | wired | `templates/profile/view.php:521`, `templates/partials/block-confirm-modal.php:73-79` |
| toggleMute (POST/DELETE toggle, X-WP-Nonce) | store | wired | `assets/js/profile/store.js:1216-1234` |
| toggleRestrict | store | wired | `assets/js/profile/store.js:1236-1258` |
| toggleBlock -> confirmBlock (POST) / doUnblock (DELETE) | store | wired | `assets/js/profile/store.js:1261-1298`, `:1494-1510` |
| Routes registered + booted | rest | wired | `includes/SocialGraph/BlockController.php:32-113`; `includes/REST/Router.php:67` |
| Auth gate (is_user_logged_in) | rest | wired | `includes/SocialGraph/BlockController.php:255-265` |
| block/unblock/mute/unmute/restrict service ops | service | wired | `includes/SocialGraph/BlockService.php:46-248` |
| Hooks buddynext_block / buddynext_unblock | service | wired | `includes/SocialGraph/BlockService.php:76,110` |
| bn_blocks write with type column (block/mute/restrict); ON DUPLICATE KEY UPDATE upgrade; INSERT IGNORE preserve | db | wired | `includes/SocialGraph/BlockService.php:57-65,136-143,197-204` |
| Block-prevents-follow guard (spec edge) | service | wired | `includes/SocialGraph/FollowController.php:120`, `includes/SocialGraph/FollowService.php:74-80` |
| Manage blocked/muted/restricted list + unblock/unmute (closes loop) | ui+store | wired | `templates/profile/edit.php:547-579`, `assets/js/social/relation-remove.js:31-70` |
| GET me/blocked / me/muted / me/restricted (app clients) | rest | wired | `includes/SocialGraph/BlockController.php:186-203,243-248` |

## First break

None — journey complete.

## UX gaps

None confirmed in code. The web journey, REST/app journey, destructive-action confirmation, and the management/undo surface are all present and bound. The block modal's "unblock from your settings" promise is fulfilled by the relation list in `templates/profile/edit.php`.

## Notes (non-blocking, not gaps)

- `restrict` (Instagram-style soft block) is fully implemented (service, REST, profile-hero UI, settings list) though it is beyond the locked spec's Block/Mute scope. It does not interfere with the spec journey.
- Profile state in `view.php:71-73` reads `bn_blocks` directly via `$wpdb` rather than the cached `BlockService`. Functionally correct; a marginal-cleanliness item only — explicitly NOT a refactor target per the prime directive.

## Minimal refactor plan

(empty — usable-leave-as-is)
