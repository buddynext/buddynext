# Contract Conformance — Gamification Seam (integration-only)

**Spec:** `docs/specs/features/12-wbgamification-bridge.md` (Locked)
**Checked:** 2026-05-31
**Verdict:** broken-journey
**Repo:** contract (cross-cutting)

---

## Contract under test

BuddyNext must EMIT clean events into the seam wb-gamification actually ingests, render
leaderboards via the plugin's read API (not BN-owned tables), and ship zero gamification
logic. Verified against the real plugin source at `/Users/vapvarun/dev/repos/wb-gamification`.

## What wb-gamification actually exposes (ground truth)

- **Bootstrap class:** `final class WB_Gamification` in global namespace (`wb-gamification.php:90`). Code namespaces are `WBGam\*`. There is **no** `WBGamification\Plugin` class.
- **Registration intake:** integrations hook `wb_gamification_register` (`src/Engine/Registry.php:66`) and call `wb_gamification_register_action([ 'id', 'hook', 'user_callback', ... ])` (`src/Extensions/functions.php:45`). WBGam then auto-hooks the named source action and routes to `Engine::process()`.
- **Direct submit intake:** `wb_gam_submit_event( $user_id, $action_id, $meta )` (`functions.php:248`) and `wb_gam_award_points()` (`functions.php:138`) → `Engine::process()`.
- **Leaderboard read API:** `wb_gam_get_leaderboard( $period, $limit )` (`functions.php:204` → `WBGam\Engine\LeaderboardEngine`). Plus REST `LeaderboardController`.
- **Outbound events:** `wb_gamification_badge_awarded` fired as `($user_id, array $def, string $badge_id)` (`src/Engine/BadgeEngine.php:244`); `wb_gamification_level_changed` fired as `($user_id, $old_level_id, $new_level_id)` (`src/Engine/LevelEngine.php:110`).
- **Tables:** `wb_gam_points`, `wb_gam_user_badges`, `wb_gam_badge_defs`, `wb_gam_events`, ... (all `wb_gam_*`). No `wbg_*` tables exist.

## Confirmed breaks

### 1. Emit hook is dead — no points will ever be awarded (CRITICAL)
`GamificationBridge::fire()` does `do_action( 'wb_gamification_event', $event_type, $user_id, $context )` (`includes/Bridges/GamificationBridge.php:149`). Nothing in wb-gamification listens to `wb_gamification_event` (grep across the whole plugin: zero `add_action` for it). The correct seam is `wb_gamification_register_action()` (register `bn_*` actions against `buddynext_*` hooks) or `wb_gam_submit_event()`. As written, every BN social action translates into a no-op.

### 2. Wrong class guard — bridge self-disables even when plugin is active (CRITICAL)
`GamificationBridge::init()` (`:35`), `GamificationBridgeListener::register()` (`:28`), `templates/gamification/leaderboard.php:32`, and `includes/Admin/Settings.php:1113` all gate on `class_exists( 'WBGamification\Plugin' )`. That class does not exist; the plugin ships `WB_Gamification` (global ns). Result: the bridge bails on line 1 on every real install. The seam is unconditionally off.

### 3. Wrong badge-callback signature (HIGH)
`GamificationBridgeListener::on_badge_awarded( int $user_id, int $badge_id )` (`:44`) takes 2 ints. WBGam fires 3 args with the badge id as a **string** in slot 3 and a def **array** in slot 2. Under `declare(strict_types=1)` BN would receive the def array where it expects `int $badge_id` → TypeError. (Unreachable today because of break #2, but latent.)

### 4. Leaderboard renders via direct table queries against non-existent tables (CRITICAL)
`templates/gamification/leaderboard.php` resolves `$wpdb->prefix . 'wbg_user_points'`, `'wbg_user_badges'`, `'wbg_badges'` (`:64-66`) and runs raw `$wpdb->get_results()` SELECTs (`:95-178`). Two violations: (a) contract requires rendering via the plugin API (`wb_gam_get_leaderboard()`), not BN-side SQL; (b) the table names are wrong — the plugin uses `wb_gam_points` / `wb_gam_user_badges` / `wb_gam_badge_defs`. The leaderboard returns empty (or errors) even with WBGam active and seeded.

### 5. Notification type slugs diverge from spec (LOW)
Spec UI section names `wb.badge_earned` / `wb.level_up`. Listener emits `bn.badge_awarded` / `bn.level_up` (`GamificationBridgeListener.php:53,79`). Internally consistent but off-spec; harmless to function.

## Things the contract gets right
- BN ships no points/badge/level *logic* — the bridge only translates and the listener only routes to notifications. The "zero gamification logic" rule holds.
- All mapping is intended to be rule-config-driven on the WBGam side (no hard-coded point values in BN). Correct in spirit.
- `buddynext_profile_extra_data` filter exists and is the right injection seam for profile points/badges (used today by JetonomyBridge), so the profile-UI path is wirable once emits land.

## Minimal refactor plan
1. Replace the `WBGamification\Plugin` guard with `class_exists( 'WB_Gamification' )` (or `function_exists( 'wb_gam_submit_event' )`) in `GamificationBridge`, `GamificationBridgeListener`, `templates/gamification/leaderboard.php`, and `Admin/Settings.php:1113`.
2. Rework `GamificationBridge`: on `wb_gamification_register`, call `wb_gamification_register_action()` for each `bn_*` action (mapping `buddynext_*` hook → `user_callback`), OR have each `on_*` handler call `wb_gam_submit_event( $user_id, $action_id, $meta )`. Delete the dead `do_action( 'wb_gamification_event' )`.
3. Fix `on_badge_awarded` to the 3-arg signature `( int $user_id, array $def, string $badge_id )`.
4. Rewrite `templates/gamification/leaderboard.php` to call `wb_gam_get_leaderboard()` / `wb_gam_get_user_points()` / `wb_gam_get_user_badges()` — remove all `$wpdb` SQL and the `wbg_*` table constants.
5. (Optional) Align notification slugs to spec (`wb.badge_earned` / `wb.level_up`) or update the spec to the `bn.*` convention used elsewhere in BN notifications.

## Notes / grounding
- Static-only inspection. Breaks #1, #2, #4 are provable from code (dead hook, missing class, wrong table names) — not runtime/isolation artifacts. Break #3 is a latent type mismatch confirmed against both signatures.
- The wb-gamification source inspected is the dev repo, not the installed plugin (not present under the Local site's `wp-content/plugins`). If a different/older build is what BN targets in production, re-verify the class name and hook set there — but the `wbg_*` vs `wb_gam_*` table divergence alone breaks the leaderboard regardless.
