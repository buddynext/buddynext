# Conformance — Reactions (free)

**Spec ref:** `docs/specs/features/08-reactions-comments.md` (Reactions section; Locked, 2026-03-19)
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/activity

This dossier covers the **Reactions** half of the combined Reactions + Comments spec. Comments are out of scope for this trace.

---

## Journey chain (member reacts to a feed post, then views who reacted)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member sees React button + emoji picker on each post card | ui | wired | `templates/parts/post-actions.php:84-196` (React button `data-wp-on--click="actions.toggleReactionPicker"`; picker buttons `data-wp-on--click="actions.setReaction"` with `data-reaction-type`) |
| Picker populated from filterable reaction set (six defaults + Pro custom) | ui | wired | `templates/parts/post-actions.php:131-159` consumes `ReactionService::reaction_types()` / `buddynext_reaction_meta` |
| Card seeds Interactivity context (postId, current reaction, REST nonce, restUrl) | ui | wired | `templates/partials/post-card.php:333-348` seeds `reactionType`, `reactNonce` (= `$rest_nonce`, l.228), `restUrl=rest_url('buddynext/v1')`; `$my_reaction_type` from `buddynext_service('reactions')->get_user_reaction()` l.259-261 |
| Picker click → store action → optimistic UI + POST toggle | store | wired | `assets/js/feed/store.js:761-783` `setReaction` posts `{object_type:'post', object_id, emoji}` to `restUrl + '/reactions/toggle'` with `X-WP-Nonce`, reverts on failure |
| REST route exists and authenticates | rest | wired | `includes/Reactions/ReactionController.php:30-57` registers `POST buddynext/v1/reactions/toggle` with `require_auth`; mounted at `includes/REST/Router.php:80` |
| Service applies add / replace / remove + cache invalidation | service | wired | `includes/Reactions/ReactionService.php:250-286` (`toggle()`: null→react, same→unreact, different→update); one-per-user enforced by UNIQUE key + `INSERT IGNORE` l.43-56 |
| Counts cached on parent post row; notifications + gamification fired | service/db | wired | `ReactionService.php:63-71` increments `bn_posts.reaction_count`; `do_action('buddynext_reaction_added')` l.81 and `buddynext_post_reaction_received` l.105 (recipient-side hook for notifications + WBGamification) |
| Member opens "who reacted" popover from summary chip | ui→store→rest | wired | `templates/parts/post-reaction-summary.php:91-128` renders `button[data-bn-reactors]` (count > 0); `store.js:95-125` click handler fetches `/reactions/list`; endpoint at `ReactionController.php:81-108` + hydration `:167-193` |
| Reactor list respects block/restrict visibility | service | wired | `ReactionService.php:359-421` drops restricted reactors for non-owner/non-admin viewers (honors 17-roles-permissions visibility) |

---

## First break

none — journey complete. Every UI control is bound to an Interactivity API store action that reaches a registered, authenticated REST route backed by a working service and DB layer. Both the web journey and the REST/app journey are served (the controller is the single source for both).

---

## UX gaps

None that stop the journey. Two non-blocking observations:

- **Toggle-off payload sends `emoji: null` rather than empty string** — `store.js:776` sends `emoji: newType` where `newType` is `null` when removing. Controller arg `emoji` has `default => 'like'` + `sanitize_key` (`ReactionController.php:49-54`); a JSON `null` value coerces via `sanitize_key(null)` → `''`, which the service treats as "remove" (`ReactionService.php:251-255`). Behavior is correct but relies on null→'' coercion rather than the service's explicit empty-string contract. Severity low; confirmed correct in code.
- **`reaction_count` denormalized on `bn_posts` only for `object_type='post'`** — comments/messages/media reactions get no cached parent counter (`ReactionService.php:63-71, 146-153`). This matches the spec ("Counts cached on bn_posts row") and SCALE-CONTRACT intent; not a gap for the post journey.

---

## Minimal refactor plan

Empty — feature is usable as built. No rewrites proposed.
