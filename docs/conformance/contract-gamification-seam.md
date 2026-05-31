# Contract Conformance — Gamification Seam (integration-only)

**Contract:** BN emits clean events into the seam wb-gamification ingests, and renders
leaderboards/points via the plugin's public read API — never its own tables. BN ships
zero gamification logic.

**Spec:** `docs/specs/features/12-wbgamification-bridge.md` (Locked, 2026-03-19)
**Verified against plugin:** `wp-content/plugins/wb-gamification` (live local install)
**Date:** 2026-05-31
**Verdict:** partial-needs-wiring

---

## What is solid (do NOT touch)

### Emit side — registration + submit
- `GamificationBridge::register_actions()` calls `wb_gam_register_action()` with a
  deliberate inert `NOOP_HOOK` ('buddynext_gamification_noop') + `__return_zero`
  callback, then submits manually via `wb_gam_submit_event()`. This is the correct
  pattern: the engine recognises each `bn_*` slug (admins get configurable point rows)
  but never auto-awards on a WP hook, so each award fires exactly once. Guards on
  `wb_gam_get_actions()` keep re-load idempotent.
- `fire()` → `wb_gam_submit_event( int $user_id, string $action_id, array $context )`
  matches the plugin signature exactly (`functions.php:270`).
- All 6 wired BN source actions are really fired by BN with matching arity:
  - `buddynext_user_followed` — FollowService.php:132,551
  - `buddynext_connection_accepted` — ConnectionService.php:167
  - `buddynext_post_created` — PostService.php:178 (+ Cron, Share)
  - `buddynext_space_member_joined` — SpaceMemberService.php:145,363
  - `buddynext_strike_issued` — ModerationService.php:324
  - `buddynext_profile_completion_changed` — ProfileService.php:790,847

### Render side — leaderboard
- `templates/gamification/leaderboard.php` consumes ONLY the public read API
  (`wb_gam_get_leaderboard`, `wb_gam_get_user_points`, `wb_gam_get_user_badges`,
  `wb_gam_get_user_streak` — all present in `functions.php`). Guards on plugin
  presence with a friendly notice. No BuddyNext-side SQL.
- No `wbg_*` table queries anywhere in `includes/`, `src/`, `templates/`. No
  gamification logic in BN. The contract's core "integration-only" guarantee holds.

---

## Breaks / gaps

### BREAK #1 — Inbound notification hooks are wrong (dead path) — CRITICAL
`GamificationBridgeListener::register()` subscribes to:
- `wb_gamification_badge_awarded`
- `wb_gamification_level_changed`

The plugin NEVER fires those. It fires the short-prefix actions:
- `wb_gam_badge_awarded`  (BadgeEngine.php:296) — `($user_id, array $def, string $badge_id)`
- `wb_gam_level_changed`  (LevelEngine.php:146) — `($user_id, array $new_level, array $old_level)`

No `do_action( 'wb_gamification_badge_*' )` / `wb_gamification_level_*` exists anywhere
in the plugin. Result: badge-earned and level-up notifications never reach the BuddyNext
bell. This breaks the spec's "Notifications: Badge earned + level up appear in BuddyNext
bell" guarantee. (The listener docblock claims it "matches the BadgeEngine fire
signature" — the comment is right, the actual hook string is wrong.)

### BREAK #2 — level_changed handler signature mismatch — CRITICAL (compounds #1)
Even after fixing the hook name, `on_level_changed( int $user_id, int $old_level,
int $new_level )` does not match the real fire `($user_id, array $new_level,
array $old_level)`:
- arg order reversed (new before old)
- types are arrays (level-data rows), not ints
`on_badge_awarded( int, array, string )` is correct once the hook name is fixed.

### GAP #3 — Two spec-mapped events never emitted — HIGH
Spec Event Mapping table lists 8 mappings. The bridge catalogue/handlers cover 6.
Missing:
- `buddynext_reaction_added` → `bn_reaction_received` (2 pts per reaction received)
- `buddynext_comment_created` → `bn_comment_created` (3 pts per comment)
Both source actions ARE fired by BN (ReactionService.php:81 `($object_type,
$object_id, $user_id, $emoji)`; CommentService.php:103 `($comment_id, $object_type,
$object_id, $user_id)`), but no catalogue entry, no `add_action`, no handler. These
two award paths silently never reach the engine.

### GAP #4 — Profile / directory UI injection not wired — MEDIUM
Spec UI Integration mandates profile points/level/badges via
`buddynext_profile_extra_data`, plus an optional member-directory points badge.
`GamificationBridge` registers NO filters (no `buddynext_profile_extra_data`,
no member-card hook). Only the standalone leaderboard surface consumes the read
API. JetonomyBridge demonstrates the `buddynext_profile_extra_data` pattern
(JetonomyBridge.php:74) — the gamification bridge has no equivalent.

---

## Refactor plan (minimal, integration-only — no rewrites of working code)

1. Fix listener hook names: `wb_gamification_badge_awarded` → `wb_gam_badge_awarded`,
   `wb_gamification_level_changed` → `wb_gam_level_changed` in
   `GamificationBridgeListener::register()`.
2. Fix `on_level_changed()` signature to `(int $user_id, array $new_level,
   array $old_level)` and read level numbers/labels from the arrays before building
   the notification payload.
3. Add `bn_reaction_received` + `bn_comment_created` to ACTION_CATALOGUE, add
   `add_action( 'buddynext_reaction_added', ... )` and
   `add_action( 'buddynext_comment_created', ... )`, with handlers that resolve the
   content owner (recipient of the reaction/comment) and `fire()` the event. Mind the
   real arg orders above.
4. Register a `buddynext_profile_extra_data` filter in GamificationBridge that injects
   points/level/badges via the read API (guarded on `wb_gam_get_user_points`), mirroring
   JetonomyBridge. Wire the optional member-card points badge behind the admin toggle.
