# Gamification Integration — Plan (deferred)

Status: PLANNED. Do NOT start until the backend-settings audit + fixes are
complete (owner decision 2026-06-17). Owner intent: "integrations should post
inside the activity feed — that's how integration should work," and members'
rank + badges should be visible.

Bridges WBGamification (`wb-gamification`). All point/badge/level logic lives in
WBGamification; BuddyNext is the community surface (feed + profile + notifications).

## What already exists (don't rebuild)

`includes/Bridges/GamificationBridge.php`:
- Registers ~11 earnable BN actions with the rules engine (follow, connect, post,
  space-join, reaction received, comment, profile complete, etc.) — admins set
  points per action; BuddyNext hard-codes none.
- `inject_profile_gamification` (filter `buddynext_profile_extra_data`) — surfaces
  standing (points / level) as profile stat tiles.
- `on_badge_awarded_activity` (`wb_gam_badge_awarded`) — posts a feed activity ONLY
  for **credential** badges (gated so tiny participation badges don't spam the feed).

`includes/Bridges/GamificationBridgeListener.php`:
- Notifications to the earner: `bn.badge_awarded`, `bn.level_up`.

So: points engine ✓, profile standing tiles ✓, credential-badge feed activity ✓,
notifications ✓. Gaps below.

## Gaps to close (the actual work)

### 1. Feed activity (heartbeat) — owner: default ON
- **Level-ups have no feed activity** (notification only). Add `on_level_changed`
  → feed activity ("X reached <Level>") via the single-source-of-truth path
  (bn_posts achievement activity, like JetonomyBridge writes discussion activities).
- **Badges:** keep the credential-only gate by default (avoid spam), but make the
  threshold a setting (all badges / credential only / off).
- Privacy-aware (respect blocks/visibility); dedupe; never double-post.
- Use a single activity type (e.g. `achievement`) so it flows into Explore.

### 2. Rank + badge DISPLAY for members
- **Profile header:** show current level/rank chip near the name + a badge row
  (earned badges as chips), beyond the existing stat tiles. Existing
  `buddynext_profile_extra_data` already carries the data — surface it in the
  profile header template, not just a stat tile / tab.
- **Bylines / member cards:** small rank chip next to the author name in feed
  cards + the member directory (opt-in, performance-aware — batch fetch, no N+1).
- Reuse the member-label/badge visual vocabulary for consistency.

### 3. Settings (one Gamification area)
- Feed-activity control: level-ups on/off (default on), badge threshold
  (all / credential / off), default on.
- Display control: show rank on bylines (on/off), show badges on profile (on/off).
- Lives under the (merged) Integrations tab as the Gamification integration's
  settings, gated on `wb-gamification` active + the `gamification` feature toggle.

### 4. Wiring + safety
- Everything gated on the `gamification` feature flag (see the bridge-toggle fix
  in the settings audit) AND `class_exists` for WBGamification.
- Big-site: byline rank fetch must batch (WP_User_Query include / one query),
  never per-row.
- Filters: `buddynext_gamification_activity_badge_threshold`,
  `buddynext_gamification_show_rank_byline`, etc.

## Verify (when built)
- Earn a credential badge + level up → both appear in feed + Explore; notification
  still fires; respects the settings + feature toggle.
- Profile shows rank chip + badges; member cards show rank (when enabled).
- WPCS + PHPStan + browser (desktop + 390px). No N+1 on directory/feed.

## Depends on
- The backend-settings audit + fixes (esp. the Features bridge-toggle gating and
  the Add-ons→Integrations merge) landing first. See
  [[admin-settings-ia-reorg]], docs/plans/backend-settings-completeness-audit.md.
