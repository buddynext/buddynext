# Conformance: Engagement / Leaderboard

**Feature:** Engagement / Leaderboard (gamification bridge surface)
**Repo:** free (buddynext)
**Spec ref:** `docs/specs/features/12-wbgamification-bridge.md` (Locked)
**Cross-cutting:** `docs/specs/SCALE-CONTRACT.md`, `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL (given):** http://buddynext-dev.local/leaderboard
**Canonical route (in code):** `/activity/leaderboard/` (`includes/Core/PageRouter.php:948`, `:1339`)
**Date:** 2026-05-31

## Verdict

**usable-leave-as-is** — the core leaderboard journey (view ranking, see your rank/points/level/badges) is wired end-to-end and renders entirely server-side. The gaps below are spec-conformance items in the *bridge event mapping*, not breaks in the leaderboard view journey, plus runtime-only verification items.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Member navigates to Leaderboard (nav link) | ui | wired | `includes/Core/Plugin.php:540` (nav item -> `PageRouter::leaderboard_url()`) |
| URL `/activity/leaderboard/` resolves to template | rest (route) | wired | `includes/Core/PageRouter.php:948-951` rewrite -> `:793` dispatch -> `gamification/leaderboard.php` |
| Plugin-absent guard (no WBGamification) | service | wired | `templates/gamification/leaderboard.php:32-45` friendly notice |
| Read top-10 ranked users | db | wired | `templates/gamification/leaderboard.php:95-104` (SUM points from `wbg_user_points`) |
| Read current user's rank + points | db | wired | `templates/gamification/leaderboard.php:110-132` |
| Read current user's badges + breakdown | db | wired | `templates/gamification/leaderboard.php:137-163` |
| Render hero (rank/points/level), list rows, sidebar | ui | wired | `templates/gamification/leaderboard.php:321-755`; CSS `assets/css/bn-gamification.css` (present) |
| Period/category filter tabs | ui | wired | `:404-447` plain `<a href add_query_arg>` server round-trip (functional) |
| Points actually accrue (follow/connect/post/space/strike/profile) | service | wired | bridge `includes/Bridges/GamificationBridge.php:39-44` attached at `includes/Core/Plugin.php:288`; source actions all fire (FollowService, ConnectionService, Feed/PostService:167, SpaceMemberService, ModerationService, ProfileService) |
| Badge/level-up -> BN notification | service | wired | `includes/Bridges/GamificationBridgeListener.php:32-34` listens to `wb_gamification_badge_awarded`/`_level_changed` (fired by WBGam plugin, external) |
| Reaction-received / comment points accrue | service | missing | spec rows `bn_reaction_received`, `bn_comment_created`; bridge has NO listener for `buddynext_reaction_added` / `buddynext_comment_created` although both fire (ReactionService.php:81, CommentService.php:92) |

## First break

none — journey complete (for the view-leaderboard happy path). The reaction/comment point-accrual omission is a spec-mapping gap, not a journey break: the leaderboard still ranks correctly on the points that do flow.

## UX gaps

1. **Reaction-received and comment point mappings not wired (spec rows missing).** severity: medium · confidence: confirmed-in-code · `includes/Bridges/GamificationBridge.php:39-44` registers 6 of the 8 spec mappings; `buddynext_reaction_added` (ReactionService.php:81) and `buddynext_comment_created` (CommentService.php:92) fire but have no bridge handler, so spec rows "2 pts per reaction received" and "3 pts per comment" never produce `wb_gamification_event`. Also note the breakdown widget references `bn_comment` / `bn_reaction` event labels (`leaderboard.php:205-212`) that the engine will never receive. Engagement that the spec intends to reward is invisible on the leaderboard.

2. **Live-walk URL `/leaderboard` does not match the registered rewrite `/activity/leaderboard/`.** severity: low · confidence: needs-live-verification · `includes/Core/PageRouter.php:948` only registers `^{activity-slug}/leaderboard/?$`; no standalone `^leaderboard/?$` rule exists. A bare `/leaderboard` would 404 unless a redirect, custom slug, or a WP page exists at runtime. The in-product nav link uses the correct `leaderboard_url()` so members reach it fine; only the hand-given walk URL is suspect.

3. **Follow CTA on rows is inert; Interactivity store is unused by the template.** severity: low · confidence: confirmed-in-code · the per-row Follow button (`leaderboard.php:603-615`) has no `data-wp-on--click`/follow binding (`$is_following` hard-coded false, comment "bridge to follow API can hydrate later"). The store action `setFilter` (`assets/js/gamification/store.js:11`) targets `[data-filter]` elements that the template never emits. Does not block the view journey; filters work via plain links. Cosmetic/secondary affordance only.

## Minimal refactor plan

(Leaderboard *view* journey is usable-leave-as-is. These steps close the spec-mapping gap only — do not rewrite the working template.)

1. In `includes/Bridges/GamificationBridge.php::init()`, add `add_action( 'buddynext_reaction_added', ... )` and `add_action( 'buddynext_comment_created', ... )` handlers that `fire( 'bn_reaction_received', $owner_id, ... )` and `fire( 'bn_comment_created', $author_id, ... )`, matching the param signatures (ReactionService.php:81 passes `$object_type, $object_id, $user_id, $emoji`; reaction-received should award the *content owner*, not the reactor — resolve owner from object). Align the event slug emitted with the breakdown labels in `leaderboard.php:205-212` (`bn_reaction`/`bn_comment` vs spec `bn_reaction_received`).
2. (verify, no code) Confirm with the human whether `/leaderboard` should redirect to `/activity/leaderboard/` or whether the walk should use the canonical URL; if a short alias is desired, add one `add_rewrite_rule` mirroring `:948`.

## Live-walk URL

Use the canonical route during the browser walk: `http://buddynext-dev.local/activity/leaderboard/` (seed `wbg_user_points` rows first — empty tables render the "No leaderboard data yet" empty state at `leaderboard.php:459-466`, which can be mistaken for a break).
