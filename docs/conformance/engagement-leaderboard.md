# Conformance — Engagement / Leaderboard

**Feature:** Engagement / Leaderboard (gamification seam)
**Repo:** free (buddynext)
**Spec ref:** `docs/specs/features/12-wbgamification-bridge.md` (Locked, 2026-03-19)
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL (canonical):** `http://buddynext-dev.local/activity/leaderboard/`
**Verdict:** usable-minor-polish

---

## What the spec asks for

Spec 12 is an **integration seam**, not a standalone feature. BuddyNext ships zero gamification
logic. It (a) registers a `bn_*` action catalogue with wb-gamification and emits events when social
actions occur, (b) routes wb-gamification badge/level signals into the BN notification bell, and
(c) renders wb-gamification's **public read API** on BN surfaces — the leaderboard page being the
primary one. wb-gamification is installed and active in this environment
(`wp-content/plugins/wb-gamification`).

The core member happy path: open the leaderboard → see top-10 ranked members with points and
badges → see my own rank / points / level / streak / next milestone.

---

## Journey chain

| # | Step | Layer | Status | Evidence |
|---|------|-------|--------|----------|
| 1 | `/activity/leaderboard/` rewrite → `bn_hub=feed&bn_activity_action=leaderboard` | rest (routing) | wired | `includes/Core/PageRouter.php:970-974` |
| 2 | Router maps action to template `gamification/leaderboard.php` | service | wired | `includes/Core/PageRouter.php:816-817` |
| 3 | Gamification CSS + Interactivity store bundle enqueued on the route | ui | wired | `includes/Core/PageRouter.php:645-646`; `includes/Core/AssetService.php:275,329` |
| 4 | Template guards on `wb_gam_get_leaderboard` (graceful notice if plugin absent) | ui | wired | `templates/gamification/leaderboard.php:37-50` |
| 5 | Ranked rows from public read API `wb_gam_get_leaderboard($period,10)` | service | wired | `templates/gamification/leaderboard.php:66-69` |
| 6 | Current-user points / rank / badges / streak from read API | service | wired | `templates/gamification/leaderboard.php:72-98` |
| 7 | Render hero stats, level meter, ranked list, badges/streak/milestone widgets | ui | wired | `templates/gamification/leaderboard.php:201-608` |
| 8 | Period filter (week/month/alltime) via plain `<a>?period=` links, re-read server-side | ui | wired | `templates/gamification/leaderboard.php:56-62,287-303` |
| 9 | Event emission: social actions → `wb_gam_submit_event()` | service | wired | `includes/Bridges/GamificationBridge.php:113-128,330-336` |
| 10 | Badge/level signals → BN notification bell | service | wired | `includes/Bridges/GamificationBridgeListener.php:42-43,57-131` |
| 11 | Leaderboard offered as an addable nav-menu item (Appearance → Menus) | ui | wired | `includes/Core/Plugin.php:543-546` |

No BN-side SQL; reads go through wb-gamification's public functions only, honoring the
"route through the bridge / don't reimplement" rule.

---

## First break

**none — journey complete.** A member who reaches `/activity/leaderboard/` sees the ranked board,
their own rank/points/level, badges, streak and next milestone, all populated from the live
wb-gamification read API. Period switching works via server-rendered links. Points are emitted on
the full set of social actions in the spec's mapping table, and badge/level events surface in the bell.

---

## UX gaps (real, non-blocking)

1. **Per-row "Follow" button is a dead control** — severity: medium — confidence: confirmed-in-code.
   The button at `templates/gamification/leaderboard.php:475-487` has no `data-wp-on--click`, no
   bound store action, and `$is_following` is hardcoded `false` (line 470). The gamification store
   (`assets/js/gamification/store.js`) only exposes `setFilter`. A `@buddynext/social-buttons`
   follow store exists (`AssetService.php:333`) but is not enqueued here and the button is not wired
   to it. Clicking does nothing. This is a secondary CTA, not the leaderboard view journey, so it
   does not break the happy path — but it is a visibly inert control.

2. **Live-walk URL given to the human is non-canonical** — severity: low — confidence: confirmed-in-code.
   The task's entry URL `http://buddynext-dev.local/leaderboard` has no matching rewrite. The only
   leaderboard rewrite is `^activity/leaderboard/?$` (`PageRouter.php:970-974`, slug base
   `buddynext_slug_activity` default `activity`, line 952). Walk `/activity/leaderboard/` instead,
   unless the activity slug was customized to `leaderboard`.

3. **Period tabs do not use the Interactivity store; store `setFilter` is orphaned** — severity: low —
   confidence: confirmed-in-code. Tabs are plain anchors (`leaderboard.php:297-303`) that reload with
   `?period=`. `store.js:setFilter` reads `data-filter` / context key `filter`, but the template emits
   neither (context key is `period`, lines 171-180). The journey still works (server-side links); the
   JS path is simply unused. Not a break.

4. **Rank deltas and "Show Top N" are static** — severity: low — confidence: confirmed-in-code.
   Rank-change trend is hardcoded to 0 (`leaderboard.php:115-119`) because wb-gamification exposes no
   rank-snapshot read API, and the window `<select>` is `disabled` with a single "Top 10" option
   (lines 311-313). Both are intentional placeholders, documented in-template; neither blocks viewing.

No SCALE-contract violation: the per-row badge fetch loop (`leaderboard.php:103-112`) is bounded to
the 10 returned rows.

---

## Minimal refactor plan

The core leaderboard view journey is complete and usable; do not rewrite it. Optional polish only:

1. Wire the per-row Follow button to the existing `@buddynext/social-buttons` follow store: enqueue
   that module on the leaderboard route and bind the button with `data-wp-on--click` + the member id,
   hydrating `aria-pressed`/label from follow state — reusing existing code, no new store. (Addresses
   gap 1.) If a quicker path is preferred, drop the button until follow-state hydration is available.
2. Confirm the human walks `/activity/leaderboard/` (or document the custom activity slug). (Gap 2 —
   doc only, no code.)

Gaps 3 and 4 are intentional and need no action.
