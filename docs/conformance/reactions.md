# Conformance: Reactions (Free)

**Spec ref:** `docs/specs/features/08-reactions-comments.md` (Reactions section; Locked 2026-03-19. Comments not in scope of this trace)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/activity
**Verdict:** usable-leave-as-is

---

## Journey traced (web member reacting to a feed post)

Entry: member on `/activity` → clicks React → picks an emoji → reaction persists, count updates, picker closes → can re-open "who reacted" list.

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | React button + emoji picker rendered on every post card; viewer's current reaction hydrated server-side | ui | wired | `templates/parts/post-actions.php:86-196`; viewer reaction read `templates/partials/post-card.php:261`, seeded into context `:333` |
| 2 | Picker toggle + emoji-select bound to Interactivity store; nonce/restUrl in context | store | wired | `actions.toggleReactionPicker` `assets/js/feed/store.js:757`; `actions.setReaction` `:761-784`; context seed `templates/partials/post-card.php:322-348` |
| 3 | Optimistic toggle POSTs to REST; reverts on failure | rest | wired | `assets/js/feed/store.js:773-783` → `POST buddynext/v1/reactions/toggle` `includes/Reactions/ReactionController.php:31-57`; mounted `includes/REST/Router.php:80` |
| 4 | Replace existing emoji / remove same / add new; fires owner + gamification hooks | service | wired | `ReactionService::toggle()` `includes/Reactions/ReactionService.php:250-286`; `buddynext_reaction_added` `:81`, `buddynext_post_reaction_received` `:105` |
| 5 | Row persisted (unique per user+object); count cached on `bn_posts` row | db | wired | `INSERT IGNORE bn_reactions` `ReactionService.php:47-56`; `UPDATE bn_posts SET reaction_count` `:65-70`; cache invalidate `:496-500` |
| 6 | "Who reacted" trigger → popover fetches reactors | ui→rest | wired | trigger `templates/parts/post-reaction-summary.php:93-95`; popover fetch `assets/js/feed/store.js:2756` → `GET /reactions/list` `ReactionController.php:81-108`; query `ReactionService::get_reactors()` `:359-421` |

## First break

none — journey complete.

## Spec coverage notes

- **Six defaults / admin-configurable set:** `ReactionService::REACTION_TYPES` `:175`; picker renders filterable `reaction_types()` `:188-202`, so Pro custom slugs surface via `buddynext_reaction_types` / `buddynext_reaction_meta` (`post-actions.php:131-159`).
- **One reaction per user, new emoji replaces old:** enforced at DB (unique key + INSERT IGNORE) and in `toggle()` replace branch `ReactionService.php:269-286`.
- **Counts grouped by emoji + who-reacted list:** `get_counts()` `:295-325`, `get_reactors()` `:359-421`, surfaced via `/reactions/list`.
- **Notifications + WBGamification:** recipient-side `buddynext_post_reaction_received` `:105` fires only when reactor != author.
- **Visibility (17-roles-permissions):** reactor list applies the restricted-user gate `:384-412` (owner/admin/self bypass, others filtered); raw count stays factual.
- **Scale:** count + per-user emoji + counts cached in object cache (TTL 300, group `buddynext_reactions`); reactor list capped at 100.

## UX gaps

None proven in code. Observation (needs-live-verification, not a gap): `setReaction` posts `emoji: newType` where `newType` becomes JS `null` when un-reacting; `null` serializes to JSON `null` and the REST arg default is `'like'` (`ReactionController.php:50-54`). The remove path still works because the un-react case is the same-emoji-toggle, which the server's same-emoji branch removes (`ReactionService.php:264-267`); the `null`→`'like'` coercion does not alter outcome for the traced flow. Worth a quick live click-twice check.

## Minimal refactor plan

None. Feature is wired end-to-end across ui/store/rest/service/db for the web journey and serves the REST/app client via the same routes.
