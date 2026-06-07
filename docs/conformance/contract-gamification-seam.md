# Contract Conformance — Gamification Seam (integration-only)

**Spec:** `docs/specs/features/12-wbgamification-bridge.md` (Locked, 2026-03-19)
**Checked:** 2026-05-31
**Verdict:** usable-minor-polish — core seam contract holds; some spec-listed UI surfaces unwired
**Repo:** buddynext (contract)

---

## Contract under test

BN must EMIT clean events into the seam wb-gamification actually ingests, render
leaderboards/points via that plugin's public read API — NOT its own `wbg_*` tables —
and ship zero gamification logic.

The wb-gamification plugin is present at `wp-content/plugins/wb-gamification`, so
signatures were verified against installed code.

---

## Verified guarantees

### 1. Zero gamification logic / zero own tables (CORE) — SOLID
`grep` across `includes/` finds **no** `wbg_*` / `wb_gam_*` table reads, no `$wpdb`
gamification queries, no points/badge/level computation. BN holds only an action
*catalogue* (labels + default points), which is config handed to the engine, not logic.

### 2. Emit clean events through the public submit API (CORE) — SOLID
`GamificationBridge::fire()` routes every award through
`wb_gam_submit_event( $user_id, $action_id, $context )` (GamificationBridge.php:330-336).
Signature match verified: functions.php:270. Each BN producer hook the bridge listens
on is really fired with the expected arity:

| Emit hook | Producer | Bridge handler |
|-----------|----------|----------------|
| `buddynext_user_followed` (2) | FollowService.php:143,562 | on_user_followed |
| `buddynext_connection_accepted` (3) | ConnectionService.php:178 | on_connection_accepted (awards BOTH peers) |
| `buddynext_post_created` (3) | PostService.php:186, ShareService.php:96, CronService.php:316 | on_post_created |
| `buddynext_space_member_joined` (3) | SpaceMemberService.php:145,363 | on_space_joined |
| `buddynext_strike_issued` (3) | ModerationService.php:324 | on_strike_issued |
| `buddynext_profile_completion_changed` (2) | ProfileService.php:805,862 | on_profile_completion_changed |
| `buddynext_post_reaction_received` (4) | ReactionService.php:105 | on_reaction_received (awards OWNER; self-reaction excluded upstream) |
| `buddynext_comment_created` (4) | CommentService.php:103 / WPMediaVerseBridge.php:516 (legacy 3-arg) | on_comment_created (variadic; commenter is last arg) |

No wrong-hook emits found. Recipient resolution matches spec semantics.

### 3. Action catalogue registration is valid and non-double-awarding — SOLID
`register_actions()` calls `wb_gam_register_action()` against an inert `NOOP_HOOK`
(`buddynext_gamification_noop`, never fired) with `user_callback => '__return_zero'`.
Correct, not a hack: Registry::register_action validates `id + hook +
is_callable(user_callback)` and `_doing_it_wrong`-bails otherwise (Registry.php:128),
then auto-hooks the named source action (Registry.php:152). Binding to a never-fired
hook lets the engine recognize the slug (admins get configurable point rows) while BN
owns the single manual emit via `fire()` — no auto-award double-counting. Dedup guard
via `wb_gam_get_actions()` keyed by id (GamificationBridge.php:145-150) is valid.

### 4. Render leaderboard via plugin read API (CORE) — SOLID
`templates/gamification/leaderboard.php` consumes ONLY the public read API:
`wb_gam_get_leaderboard`, `wb_gam_get_user_points`, `wb_gam_get_user_badges`,
`wb_gam_get_user_streak` (all defined functions.php:117-297). Guards on
`function_exists('wb_gam_get_leaderboard')` with a friendly fallback when the plugin is
absent. No BN-side SQL. This is the only consumer of the read API.

### 5. Outbound engine events → BN notifications — SOLID
`GamificationBridgeListener` listens INBOUND-only on `wb_gam_badge_awarded` (3-arg) and
`wb_gam_level_changed` (3-arg). Signatures verified: BadgeEngine.php:296,
LevelEngine.php:146. Listener never submits an award, so it cannot double-award
alongside the bridge. Notification types `bn.badge_awarded` / `bn.level_up` are
registered (Installer.php:152), rendered (NotificationMessageService.php:302,313), have
email templates (EmailEditor.php:188) and prefs (NotificationPrefCatalogue.php:291).
Round-trip closed.

---

## Gaps (spec UI-integration surfaces not wired — NOT seam breaks)

The spec "UI Integration" section lists four injection surfaces. Only the leaderboard
page is built. These spec-named surfaces have no gamification read-API calls:

- **Profile** points/level/badges grid via `buddynext_profile_extra_data` — the filter
  exists and JetonomyBridge uses it (JetonomyBridge.php:74), but GamificationBridge does
  NOT inject points/level/badges. (medium)
- **Member directory** points badge on member cards (admin toggle) — absent. (low; spec marks optional)
- **Space pages** leaderboard sidebar widget — absent. (low)

These are missing *consumers* of an already-correct seam, not violations of the seam
contract. The read API is available; wiring them is additive, not a refactor.

---

## First break
none — core seam complete. Earliest *missing* (not broken) link is profile-card
gamification injection (spec UI Integration).

## Refactor plan (additive, optional — spec-completeness only)
1. Hook `buddynext_profile_extra_data` in GamificationBridge to inject
   `wb_gam_get_user_points/_level/_badges` into the profile card (plugin-presence guarded).
2. (Optional, admin-toggle) Member-directory points badge + space-sidebar leaderboard widget.

No changes to the working emit/submit/render seam.
