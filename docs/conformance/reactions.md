# Conformance — Reactions

**Feature:** Reactions (repo: free)
**Spec ref:** docs/specs/features/08-reactions-comments.md (Locked, 2026-03-19)
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## What was verified

The post-reaction happy path — a logged-in member opens the activity feed, opens
the reaction picker on a post, picks an emoji, the reaction persists and the
count + "who reacted" popover reflect it. The chain is fully wired UI → store →
REST → service → DB, plus comment-level reactions and the notification/webhook
integration hooks called for in the spec.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Feed renders post card with React button + emoji picker | ui | wired | templates/parts/post-actions.php:87-134 |
| Card seeds wp-context (postId, reactionType, reactNonce, restUrl) | ui | wired | templates/partials/post-card.php:322-346 |
| Server pre-fills viewer's current reaction | service | wired | templates/partials/post-card.php:259-261 (get_user_reaction) |
| Click React toggles picker; pick emoji fires store action | store | wired | assets/js/feed/store.js:757-784 (toggleReactionPicker, setReaction) |
| setReaction POSTs to /reactions/toggle with nonce, optimistic + revert | store→rest | wired | assets/js/feed/store.js:773-783 |
| Route registered under buddynext/v1 | rest | wired | includes/REST/Router.php:79; includes/Reactions/ReactionController.php:30-57 |
| Toggle replaces/removes/adds (one-per-user), updates cached count | service | wired | includes/Reactions/ReactionService.php:250-286, 43-111 |
| Persisted to bn_reactions (PK user_id+object_type+object_id = one-per-object) | db | wired | includes/Core/Installer.php:582-590 |
| reaction_count cached on bn_posts row | db | wired | includes/Reactions/ReactionService.php:63-71, 146-153 |
| "Who reacted" chip rendered with data-bn-reactors trigger | ui | wired | templates/parts/post-reaction-summary.php:91-99 |
| Popover fetches /reactions/list, hydrates name+avatar+emoji | store→rest | wired | assets/js/feed/store.js:2508-2611; ReactionController.php:167-193 |
| Comment-level reactions (object_type='comment') | ui→store→rest | wired | assets/js/feed/store.js:152-189, 245-259 |
| Reaction → notify post owner | service→listener | wired | ReactionService.php:81; includes/Notifications/NotificationListener.php:40 |
| Pro reaction-type extension point | service | wired | ReactionService.php:188-202 (buddynext_reaction_types filter) |
| Restricted-user gate on reactors list | service | wired | ReactionService.php:384-418 |

## First break

none — journey complete.

## UX gaps

None that stop the journey. Minor observations (not blockers):

- The reactors-popover emoji badge is omitted when no open comment thread exposes
  `[data-emoji-base]` on the page (store.js:2563-2564). The name + avatar list
  still renders; only the per-row emoji icon may be missing on a feed with no
  expanded thread. Graceful fallback, not a break. Confidence: needs-live-verification.

## Minimal refactor plan

Empty — feature is usable as-is. Do not rewrite.

## App / REST-client note

The REST surface (toggle, count, list) is permission-gated correctly (auth
required on toggle, public reads) and object-type agnostic, so in-app/REST
clients get the same capability as the web journey. Per-emoji counts are exposed
via the service `get_counts()`; the toggle endpoint returns total `count` only,
which is sufficient for the current web UI but a REST client wanting the grouped
breakdown would call a count endpoint that returns the per-emoji map — present in
the service (get_counts) but the controller's GET /reactions returns total only.
Not a web-journey break.
