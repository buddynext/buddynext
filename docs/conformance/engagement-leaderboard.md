# Conformance — Engagement / Leaderboard

**Feature:** Engagement / Leaderboard (gamification surface)
**Repo:** buddynext (free)
**Spec ref:** docs/specs/features/12-wbgamification-bridge.md (locked, 2026-03-19)
**Cross-cutting:** REST-FRONTEND-CONTRACT.md, SCALE-CONTRACT.md, 17-roles-permissions.md
**Live-walk URL:** http://buddynext-dev.local/leaderboard (canonical route: `{activity_base}/leaderboard/`, default `/activity/leaderboard/`)

## Verdict

**usable-minor-polish**

The leaderboard page renders end-to-end against the wb-gamification public read API. Every core data tile (rank, points, level, level meter, badges grid, streak, next-milestone) is wired to a real read function that exists in wb-gamification and whose return shape matches what the template consumes. The single non-working element is the per-row "Follow" CTA, which is a decorative button with no handler — a secondary affordance, not the core journey.

## Journey chain

Core happy-path: a member opens the leaderboard, sees community ranks + their own standing, badges, streak, and next milestone.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Route `/activity/leaderboard/` → `bn_hub=feed&bn_activity_action=leaderboard` | rest (routing) | wired | includes/Core/PageRouter.php:964-968 |
| Resolve to template `gamification/leaderboard.php` | service | wired | includes/Core/PageRouter.php:810-811 |
| Guard: wb-gamification active, else friendly notice | ui | wired | templates/gamification/leaderboard.php:37-50 |
| Fetch ranked rows `wb_gam_get_leaderboard($period,10)` | service | wired | templates/gamification/leaderboard.php:66 → wb-gamification/src/Extensions/functions.php:226-228 |
| Leaderboard row shape (rank/user_id/display_name/avatar_url/points) matches consumer | db | wired | wb-gamification/src/Engine/LeaderboardEngine.php:578-582 vs template:331-340 |
| Current-user points / rank tile | ui | wired | templates/gamification/leaderboard.php:72-84, 201-254 |
| Badges grid `wb_gam_get_user_badges` | service+ui | wired | functions.php:201; template:88-92, 506-533 |
| Streak widget `wb_gam_get_user_streak` | service+ui | wired | functions.php:213; template:96-98, 537-570 |
| Next-milestone meter (computed from points) | ui | wired | template:121-127, 572-608 |
| Period filter (All time / Month / Week) via `<a href>` reload | ui | wired | template:283-305 (full-page nav with `add_query_arg`) |
| Points awarded from social actions (bridge) | service | wired | includes/Bridges/GamificationBridge.php:96-252 → `wb_gam_submit_event` |
| Per-row "Follow" CTA | ui | broken | template:475-487 — bare `<button>`, no `data-wp-on--click`/store binding; `$is_following = false` hard-coded at :470 |

## First break

None on the core journey — the leaderboard reads, ranks, and member self-stats all render. First (and only) broken link is the secondary per-row Follow button (template:470-487): no Interactivity binding and a hard-coded `false` follow state, so it is inert. The journey "view the leaderboard and your standing" completes fully without it.

## UX gaps

1. **Follow CTA in leaderboard rows is inert** — severity: medium, confidence: confirmed-in-code. `templates/gamification/leaderboard.php:475-487` renders a `bn-btn` Follow button with no `data-wp-on--click`, no follow context, and `aria-pressed` from a hard-coded `$is_following = false` (:470). A working follow store already exists (`buddynext/follow-button` block, `assets/js/blocks.js:199-222`, REST-backed `toggleFollow`) but the leaderboard does not wire to it. Clicking does nothing.

2. **"Show: Top 10" select is disabled** — severity: low, confidence: confirmed-in-code. `templates/gamification/leaderboard.php:311-313` is a disabled `<select>` with one option; the limit is hard-coded to 10 at :66. Cosmetic affordance promising configurability that does not exist. Not a journey break.

3. **Rank-change deltas always show "No change"** — severity: low, confidence: confirmed-in-code. `templates/gamification/leaderboard.php:115-119` sets all deltas to 0 because wb-gamification exposes no rank-snapshot read API yet. The trending indicators are inert by design until that data source lands. Documented in-code; not a break.

4. **Dead store action `setFilter`** — severity: low, confidence: confirmed-in-code. `assets/js/gamification/store.js:11-18` targets `[data-filter]` elements, but the template never emits `data-filter` (it uses plain `<a href>` links). The store loads but its only action is unreachable. Harmless dead code; period switching works via full-page navigation.

## Minimal refactor plan

1. Wire the leaderboard row Follow button to the existing follow store: wrap each non-self row CTA in the `buddynext/follow-button` Interactivity context (reuse `assets/js/blocks.js:199-222` `toggleFollow`) and seed real `isFollowing` per `user_id`. Reuse the existing REST follow action — do not add a new endpoint. (Addresses gap 1.)
2. Either remove the disabled "Top 10" `<select>` (template:307-314) or bind it to the `wb_gam_get_leaderboard` `$limit` arg via a query param like the period tabs. Removal is lower risk. (Addresses gap 2.)
3. Optional cleanup: drop the unreachable `setFilter` action in `assets/js/gamification/store.js` (or convert the period `<a>` links to use it). Cosmetic. (Addresses gap 4.)

Gap 3 (rank deltas) is blocked on a wb-gamification snapshot API and is correctly stubbed; leave as-is.

## Notes for the live walk

- Seed gamification data first (points events via social actions, or `wb_gam_submit_event`); on an empty install the page correctly renders the "No leaderboard data yet" empty state (template:317-324), which can read as "broken" but is built-and-correct.
- Confirm the `/leaderboard` top-level URL resolves — code registers `{activity_base}/leaderboard/` (default `/activity/leaderboard/`). If the site uses a root-level slug or redirect, that is deployment config, not a code gap.
- Walk light + dark mode; all styling is in `assets/css/bn-gamification.css`.
