# BuddyNext Template Audit — Bug Report + Execution Plan

**Audited:** 2026-03-25
**Updated:** 2026-03-25
**Total templates:** 27

---

## Completed (2026-03-25)

### Phase A — Missing JS Stores (P0) — DONE
- [x] 8 stores created: auth, moderation, onboarding, search, gamification, connections, space-members
- [x] Registered in AssetService (script modules + CSS handles)
- [x] ~40 dead buttons now have working actions calling correct REST endpoints

### Phase B — Token Cleanup (P1) — DONE
- [x] 20 templates: replaced wrong `:root` blocks with canonical alias-only version
- [x] Removed all duplicate `[data-theme="dark"]` blocks (TokenService handles dark mode)
- [x] 3 templates: removed Google Fonts `@import` (fonts via AssetService)

### Phase C — Cross-Plugin Fix (P2) — DONE
- [x] Removed raw `jt_posts` SQL from `hashtags/feed.php`
- [x] Added `apply_filters('buddynext_hashtag_related_discussions')` bridge pattern
- [x] Hashtag Like/Comment/Share/Save actions wired to correct REST endpoints

### Phase E — Typography System — DONE
- [x] TokenService: all `--text-*` tokens converted from px to rem
- [x] Nav bar: Dark toggle replaced with A | A+ | A++ font size control
- [x] `bn-base.css`: `html[data-bn-font-scale="110|120"]` scales everything uniformly
- [x] localStorage persistence + page-load application
- [x] Post card explicit font reset to prevent theme CSS bleeding in

---

## Remaining — Next Session

### Phase D — Hub Shell Rollout (8 templates need sidebar)

Templates that should render inside `bn-hub-shell` with community sidebar:

| # | Template | Current state | Fix |
|---|---|---|---|
| D-1 | `profile/view.php` | No hub shell | Wrap in hub shell + sidebar |
| D-2 | `spaces/home.php` | No hub shell | Wrap in hub shell + sidebar |
| D-3 | `spaces/directory.php` | No hub shell | Wrap in hub shell + sidebar |
| D-4 | `directory/members.php` | No hub shell | Wrap in hub shell + sidebar |
| D-5 | `search/results.php` | No hub shell | Wrap in hub shell + sidebar |
| D-6 | `hashtags/feed.php` | Has own sidebar | Convert to hub shell pattern |
| D-7 | `notifications/index.php` | Has own sidebar | Convert to hub shell pattern |
| D-8 | `gamification/leaderboard.php` | No hub shell | Wrap in hub shell + sidebar |

### Phase F — CSS/UX Polish

| # | Issue | Template(s) | Fix |
|---|---|---|---|
| F-1 | Sidebar "Your Spaces" layout broken | `partials/sidebar.php` | CSS classes changed by user; sidebar CSS needs to match modified HTML structure |
| F-2 | Post card font too large on some themes | All post cards | Verify `--text-md` (0.9375rem) renders correctly across BuddyX, Flavor, Reign |
| F-3 | Action bar inconsistency | Some cards show Share, some don't | Ensure all post cards show same 4 actions: React, Comment, Share, Save |
| F-4 | `&amp;` entity in post content | `partials/post-card.php` | Use `wp_specialchars_decode()` or `html_entity_decode()` on content |
| F-5 | Avatar initials display | Post cards + sidebar | Verify avatar fallback shows 2-letter initials centered in colored circle |
| F-6 | A/A+/A++ control visual | `partials/nav.php` | Verify active state styling, verify scaling at 110% and 120% on all pages |
| F-7 | bn-feed.css hardcoded color tokens | `bn-feed.css` `:root` block | Replace `--bn-bg: #ffffff` etc. with `var(--bg)` references (lines 23-55) |

### Phase G — Jetonomy + WPMediaVerse Standalone Font Control

When BuddyNext is NOT active, each plugin should have its own A/A+/A++ control:

| # | Plugin | File | Fix |
|---|---|---|---|
| G-1 | Jetonomy | `templates/partials/header.php` | Add A/A+/A++ buttons to Jetonomy's own nav bar |
| G-2 | Jetonomy | `assets/css/jetonomy.css` | Add `html[data-bn-font-scale="110|120"] { font-size: 110%|120%; }` |
| G-3 | Jetonomy | `templates/partials/header.php` | Add localStorage JS (same pattern as BuddyNext) |
| G-4 | WPMediaVerse | If standalone pages have a nav | Same A/A+/A++ pattern |

### Phase H — BLOCK HT: Hashtag ↔ Tag Bridge (dedicated integration)

| # | Plugin | File | Fix |
|---|---|---|---|
| H-1 | Jetonomy | `includes/models/class-tag.php` | Add `list_by_tag($slug, $limit)` public static method |
| H-2 | Jetonomy | `includes/models/class-tag.php` | Add `exists($slug)` method |
| H-3 | BuddyNext | `includes/Bridges/JetonomyBridge.php` | Hook `buddynext_hashtag_related_discussions` filter |
| H-4 | BuddyNext | `templates/hashtags/feed.php` | Render "Related Discussions" section from bridge data |

### Phase I — BLOCK PC: Post Card Unification

| # | Template | Issue | Fix |
|---|---|---|---|
| I-1 | `blocks/activity-feed.php` | Inline HTML, no interactive actions | Convert to `buddynext_get_template('partials/post-card.php')` |
| I-2 | All templates | Verify shared partial used everywhere | Grep audit + browser verify |

### Phase J — BLOCK MC: Unified Composer Partial

| # | Fix |
|---|---|
| J-1 | Extract composer from `feed/home.php` into `partials/composer.php` |
| J-2 | `feed/home.php` includes shared partial with `['space_id' => null]` |
| J-3 | `spaces/home.php` includes shared partial with `['space_id' => $space_id]` |
| J-4 | Composer CSS moves to `bn-feed.css` (shared, not inline) |
| J-5 | Verify: activity post + space post + photo post all work end-to-end |

### Phase K — BLOCK MN: WP Menu System

| # | Fix |
|---|---|
| K-1 | `register_nav_menus()` for BuddyNext menu location |
| K-2 | Custom meta box in Appearance > Menus with all BuddyNext/MVS/JT URLs |
| K-3 | Site owners can add Feed, Members, Spaces, Media, Discussion to any menu |

### Phase L — BLOCK L2: Level 2 Context Nav

| # | Fix |
|---|---|
| L-1 | Add `buddynext_context_nav` filter in nav partial |
| L-2 | Discussion context: Home / Search / Leaderboard |
| L-3 | Space context: Feed / Forum / Media / Members / Settings |
| L-4 | Media context: Explore / My Media / Albums |
| L-5 | Community Admin context: Settings / Members / Reports |

---

## Execution Priority (next session)

```
Phase D (hub shell rollout)      — 8 templates
Phase F (CSS/UX polish)          — 7 fixes
Phase G (standalone font control) — Jetonomy + WPMediaVerse
Phase H (hashtag/tag bridge)     — dedicated integration
Phase I (post card unification)  — 1 block template
Phase J (unified composer)       — extract to shared partial
Phase K (WP Menu System)         — site owner control
Phase L (Level 2 context nav)    — per-section sub-navigation
```
