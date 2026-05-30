# Conformance: Blocking & Muting

**Feature:** Blocking & Muting (repo: free)
**Spec ref:** docs/specs/features/01-social-graph.md (§ Block / Mute), cross-checked against docs/journeys/social-graph.md (Part 3 + Mute edge case) and docs/specs/REST-FRONTEND-CONTRACT.md
**Date:** 2026-05-31

## Verdict

**usable-leave-as-is**

The block / mute / restrict journey is wired end-to-end on BOTH web surfaces (profile page and member directory) and for the app/REST client. UI controls bind to Interactivity API store actions that call the registered `buddynext/v1` REST routes, which call `BlockService`, which writes `bn_blocks`. No usability break found.

## Journey chain

Core happy path: a logged-in member opens another member's profile, opens the "More options" menu, chooses Block (confirms in modal) / Mute / Restrict, and the relationship is persisted. Unblock/unmute/unrestrict reverse it.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Profile hero "More options" menu exposes Mute / Restrict / Block / Report | ui | wired | templates/parts/profile-hero.php:498-522 (data-wp-on--click toggleMute/toggleRestrict/toggleBlock; labels from state.muteLabel/restrictLabel/blockLabel) |
| Block opens a confirmation modal (destructive action) | ui | wired | templates/profile/view.php:520-523 renders partials/block-confirm-modal.php; modal bound to context.blockConfirmOpen and actions.confirmBlock (block-confirm-modal.php:27,77) |
| Context seeded server-side (profileUserId, restNonce, isBlocked/isMuted/isRestricted, blockConfirmOpen) | ui | wired | templates/profile/view.php:317-341; initial state read from bn_blocks at view.php:71-73 |
| Store actions call REST with nonce; POST=set, DELETE=unset | store | wired | assets/js/profile/store.js:1216-1298 (toggleMute, toggleRestrict, toggleBlock→confirmBlock POST), doUnblock DELETE at :1494-1509; state labels at :628-630 |
| Second web surface: member directory kebab wires Mute + Block | ui+store | wired | templates/parts/member-card.php:349 (toggleMute), :356 (openBlock); assets/js/members/store.js:672 (mute), :733-745 (confirmBlock→/block) |
| REST routes registered under buddynext/v1 | rest | wired | includes/SocialGraph/BlockController.php:32-113 (block/mute/restrict POST+DELETE, me/blocked, me/muted, me/restricted); registered via includes/REST/Router.php:66 on rest_api_init |
| Auth gate on every route | rest | wired | BlockController.php:255-265 require_auth → is_user_logged_in (matches spec "all controllers require login") |
| Service writes bn_blocks with correct type + fires actions | service | wired | BlockService.php:46-80 (block, type='block', do_action buddynext_block), :88-111 (unblock), :124-148 (mute, INSERT IGNORE preserves block), :185-217 (restrict) |
| Read helpers + viewer-aware checks | service | wired | BlockService.php:361-457 (is_blocked/has_blocked/is_blocking_either bidirectional), :405-429 (is_muted), :465-525 (muted_users/blocked_users); is_user_online applies restrict gate :335-349 |
| Persisted to bn_blocks; (blocker_id,blocked_id) unique, ON DUPLICATE KEY upgrades mute→block | db | wired | BlockService.php:57-65 ON DUPLICATE KEY UPDATE type='block'; cache invalidated both directions :537-553 |
| Asset bundle enqueued on profile route | store | wired | includes/Core/AssetService.php:316,373-378 (@buddynext/profile → profile/store.js); includes/Core/PageRouter.php:637 enqueue('profile') |

## First break

none — journey complete.

## UX gaps

None that break the journey. One documentation-only mismatch worth noting (not a code defect):

- The journey doc (docs/journeys/social-graph.md:119-145, 195) describes block/mute as a single POST "toggle" returning `{"blocked": bool}` / `{"muted": bool}`, implying repeated POST flips state. The actual contract is REST-idiomatic: POST sets, DELETE unsets (BlockController.php:35-65). The frontend store matches the real contract (DELETE to undo — store.js:1500, :1222, :1241), so the user journey works correctly. Only the journey doc's curl examples and the "toggle" phrasing are stale. Severity: low; confidence: confirmed-in-code. This is a doc fix, not a journey break — out of scope for code changes here.

## Minimal refactor plan

(empty — usable-leave-as-is)

## Live-walk URL

http://buddynext-dev.local/members/varundubey/

Walk: log in as a member, open another member's profile, click "More options" (more-horizontal icon) in the hero action cluster. Verify Mute toggles silently with a toast; Restrict toggles with an explanatory toast; Block opens the confirmation modal, and confirming persists the block, toasts, and redirects to the members directory. Re-open the kebab to confirm labels flip to Unmute / Unrestrict / Unblock (state-driven). Repeat the Mute/Block path from the member-directory member-card kebab. Confirm rows in `wp_bn_blocks` with the correct `type`.
