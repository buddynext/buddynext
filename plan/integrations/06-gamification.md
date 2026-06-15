# Integration #5 — WB Gamification (Badges / Points / Streaks)

**Status:** 🟢 BUILT (2026-06-14) — Achievements tab + credential-badge activity (notifications
already existed via `GamificationBridgeListener`). **Tier:** Free **CORE** integration (one of
the 3: media/messages, discussions, gamification). **Plugin:** wb-gamification 1.5.5. Guard
`function_exists('wb_gam_get_user_points')` / `WBGam\` classes.

> **Gamification is a MAIN profile surface, NOT a Portfolio panel.** Per the core-vs-app
> rule (`03-jetonomy.md` #1): the 3 core integrations earn their own prominent surfaces;
> only business apps (jobs/listings/courses) consolidate into the shared Portfolio tab. So
> gamification gets its **own dedicated "Achievements" profile tab** — exactly like Jetonomy
> keeps its own "Discussions" tab. Never hidden under Portfolio.

## What already exists (BN Free `GamificationBridge`)

- Registers `bn_*` action slugs with the engine (BN ships zero gamification logic — the
  engine awards points/badges; admins configure values).
- Emits BN events (followed, connection, post, space join, reaction, comment, …) the engine
  scores.
- Surfaces standing as **profile stat tiles** via `buddynext_profile_extra_data` (Points,
  Level, Badge **count**). ✅ keep as the lightweight strip signal.

**The gap:** the badges themselves are never shown — only a count. No badge grid, no
badge-earned activity, no badge notifications in BN's center.

## Data model (manifest-first)

- Tables: `wb_gam_user_badges` (earned, `earned_at`, `expires_at`), `wb_gam_badge_defs`
  (`name`, `description`, `image_url`, `category`, **`is_credential`**, criteria).
- Read APIs (use these — never raw SQL): `wb_gam_get_user_badges($uid)` →
  `{id,name,description,image_url,is_credential,category,earned_at,expires_at}`;
  `wb_gam_get_user_points/level/streak($uid)`; `LeaderboardEngine::get_user_rank($uid,$period)`.
- **Per-badge public credential URL:** `…\get_share_url($badge_id,$uid)` =
  `/gamification/badge/{badge_id}/{user_id}/share/`.
- Hub page (option `hub_page_id`, renders `[wb_gam_hub]`) → the "view all" / leaderboard CTA.
- Award hooks: `wb_gam_badge_awarded($user_id,$def,$badge_id)` (def carries name/image),
  `wb_gam_level_changed`, `wb_gam_streak_milestone`. Gamification's own "notifications" are
  ephemeral front-end toasts (`NotificationBridge`, transients) — NOT a persistent center.
- App: REST `/wb-gamification/v1/badges`, `/leaderboard`, `/leaderboard/me`,
  `MembersController::get_badges`. ✅ app coverage already exists.

## Touchpoints

| Touchpoint | What | How |
|---|---|---|
| **Profile — Achievements tab** | A dedicated main tab: a badge grid + standing strip | Free tab via `buddynext_part_profile_tab_bar_args` + `buddynext_part_profile_tab_panel_after` (the same mechanism Discussions uses) — **NOT** `buddynext_member_suite_panels`. |
| → Badge grid | earned badges (credential-first), each = `image_url` + `name` + earned date, linking to its public share URL | `wb_gam_get_user_badges()`, ordered credentials-first (`is_credential`), share URL per badge. |
| → Standing strip | Points · Level · Streak · Leaderboard rank | `wb_gam_get_user_points/level/streak`, `get_user_rank`. |
| → CTA | "View on leaderboard" → the gamification hub | `get_permalink( hub_page_id )`. |
| **Activity — badge earned** | "earned the {badge} badge" → links to the badge share page | `wb_gam_badge_awarded` → `Feed\IntegrationActivity::publish` (gate to `is_credential`, lock #2). |
| **Notifications** | badge / level milestones into BN's central center | listen on `wb_gam_badge_awarded` (+ `wb_gam_level_changed`) → `SuiteNotifications::push`, BN composing message + badge share link (gamification has no persistent center, so no card; BN owns this). |
| **Stat tiles** | Points / Level / Badge count in the stat strip | ✅ keep the existing `inject_profile_gamification`. |

## Organisation

- `includes/Bridges/GamificationBridge.php` (Free) — **extend**: add the Achievements tab
  (button + panel render) + the badge-earned activity + the notification mirror. Keep the
  existing action registration, event emission, and stat tiles.
- Reuse the badge-grid markup as a small Free template part; style with the OKLCH tokens
  (no AI-gradient palette — `feedback_no_ai_marker_colors`).
- All reads go through `wb_gam_*` public functions (guard each `function_exists`) — never
  the engine's tables directly.

## LinkedIn-minimum + clean

- The grid leads with **credential badges** (`is_credential`) — the achievements worth
  showing; non-credential/participation badges fall below or behind a "show all". Lock #1.
- Everyone can have achievements (no role split) — but the tab only appears when the member
  has ≥1 badge OR non-zero points, so brand-new members don't get an empty tab.

## Build status (2026-06-14) — DONE
1. ✅ `includes/Profile/GamificationAchievements.php` (Free, NEW) — dedicated **Achievements
   tab** via `buddynext_part_profile_tab_bar_args` + `buddynext_part_profile_tab_panel_after`
   (NOT the Portfolio filter). Panel = standing strip (points/level/streak) + badge grid
   (credential-first, each linking to its public share URL) + leaderboard CTA. Data-gated
   (≥1 badge OR points>0) → non-gamified members see no tab. `assets/css/achievements.css`
   (OKLCH tokens; credential badges get an accent ring). Wired in `Core\Plugin`.
2. ✅ `GamificationBridge` — `wb_gam_badge_awarded` → `Feed\IntegrationActivity::publish`
   ("earned the {name} badge", share URL), gated to `is_credential` (lock #2). Kept the
   existing event wiring + stat tiles.
3. ✅ Notifications: **already existed** — `GamificationBridgeListener` mirrors
   `wb_gam_badge_awarded` + `wb_gam_level_changed` into the BN center (`bn.badge_awarded`).
   No new work; the audit's notification task was already covered.
4. ✅ Verified: 13 tests green (`GamificationAchievementsTest` 6 + `WBGamificationBridgeTest`
   7 — also **fixed 5 pre-existing failures** by restoring the missing `wb_gam_*` bootstrap
   stubs). phpcs + phpstan clean (added `wb_gam_*` stubs to `phpstan-bootstrap.php`). Live on
   `buddynext-dev.local`: awarded a member 8 badges (2 credential) + 1,377 points → the
   Achievements tab shows the standing strip (Points · **Rank #N** · Level · Day streak) +
   badge grid (credentials ringed) + leaderboard CTA. Rank reads
   `\WBGam\Engine\LeaderboardEngine::get_user_rank()` (guarded; phpstan-baselined like the
   WPMediaVerse engine call). Verified live with a real seeded leaderboard (Alex = #4 of the
   field, 1,377 pts, 8 badges). Desktop + 390px, 0 console errors.

> Pre-existing, unrelated Free-suite failures (Feed / Auth / Admin / Onboarding) confirmed to
> fail with this work stashed out — not introduced here.

## Open decisions to lock
1. **Grid scope:** credential badges only, or all earned with credentials first?
   *Recommend: credentials first, all earned shown (badges are visual + fun — unlike LMS
   in-progress noise; the whole grid has value here).*
2. **Activity trigger:** every badge, or only `is_credential` badges? *Recommend: only
   credential badges post activity (avoid feed spam from tiny participation badges).*
3. **Tab label:** "Achievements" vs "Badges". *Recommend: "Achievements" (covers badges +
   standing).*
4. **Tab placement order** relative to Discussions / Portfolio in the tab bar.
