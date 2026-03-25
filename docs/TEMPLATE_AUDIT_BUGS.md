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

### Phase D — Hub Shell Rollout (8 templates) — DONE
- [x] D-1: `profile/view.php` — wrapped in hub shell + community sidebar
- [x] D-2: `spaces/home.php` — wrapped in hub shell + community sidebar
- [x] D-3: `spaces/directory.php` — wrapped in hub shell + community sidebar
- [x] D-4: `directory/members.php` — wrapped in hub shell + community sidebar (with `bn-hub-content` wrapper)
- [x] D-5: `search/results.php` — wrapped in hub shell + community sidebar
- [x] D-6: `hashtags/feed.php` — wrapped in hub shell + community sidebar
- [x] D-7: `notifications/index.php` — wrapped in hub shell + community sidebar; removed duplicate trending/spaces widgets from internal sidebar
- [x] D-8: `gamification/leaderboard.php` — wrapped in hub shell + community sidebar
- [x] `bn-base.css`: added `min-width: 0` for all content wrappers; CSS overrides to flatten internal two-column grids inside hub shell
- [x] Browser verified: all 8 pages render with consistent layout at 1280px

---

## Remaining — Next Session

### Phase F — CSS/UX Polish — DONE
- [x] F-1: Sidebar layout — verified correct with current sidebar.php structure
- [x] F-2: Post card font — `--text-md` (0.9375rem) renders correctly, explicit reset prevents theme bleed
- [x] F-3: Action bar — all non-share posts show 4 actions (React, Comment, Share, Save); share posts correctly omit Share (can't reshare)
- [x] F-4: `&amp;` entity — added `wp_specialchars_decode()` on content in post-card.php
- [x] F-5: Avatar initials — 2-letter initials centered in colored circles, verified on feed + sidebar + members
- [x] F-6: A/A+/A++ — active state styling works, 110% and 120% scale uniformly across all pages
- [x] F-7: bn-feed.css — replaced all hardcoded hex/px values with `var(--global-token)` references; dark mode block reduced to shadow-only overrides
- [x] Member directory grid changed from 4 to 3 columns (better fit with hub shell sidebar)

### Phase G — Jetonomy + WPMediaVerse Standalone Font Control — DONE
- [x] G-1: Jetonomy header — A/A+/A++ buttons added to `jt-community-nav-actions`, guarded by `! did_action('buddynext_loaded')`
- [x] G-2: Jetonomy CSS — `html[data-bn-font-scale="110|120"]` rules added to `jetonomy.css`
- [x] G-3: Jetonomy header — localStorage JS added (shares `bn_font_scale` key with BuddyNext)
- [x] G-4: WPMediaVerse — N/A (no standalone community nav; uses theme header when BuddyNext absent)

### Phase H — BLOCK HT: Hashtag ↔ Tag Bridge — DONE
- [x] H-1: Jetonomy `Tag::list_by_tag($slug, $limit)` — returns posts joined through jt_post_tags with author display_name
- [x] H-2: Jetonomy `Tag::exists($slug)` — simple slug check
- [x] H-3: BuddyNext `JetonomyBridge::get_related_discussions()` — hooked to `buddynext_hashtag_related_discussions` filter
- [x] H-4: `hashtags/feed.php` — fixed undefined `$jt_author_id`, replaced `view_count`/`vote_count`/`is_answered` with correct `vote_score`, proper Jetonomy URL via space slug lookup

### Phase I — BLOCK PC: Post Card Unification — DONE
- [x] I-1: `blocks/activity-feed.php` — replaced inline HTML with `buddynext_get_template('partials/post-card.php')`
- [x] I-2: `profile/view.php` — replaced inline post card with shared partial; expanded query to `ARRAY_A` with all needed columns
- [x] I-2: `spaces/home.php` — replaced inline post card with shared partial; query changed to `ARRAY_A`
- [x] Grep audit: zero remaining inline `bn-post-card` HTML — all 5 consumers use shared partial

### Phase J — BLOCK MC: Unified Composer Partial — DONE
- [x] J-1: Extracted composer from `feed/home.php` into `partials/composer.php`
- [x] J-2: `feed/home.php` includes shared partial with `space_id => null`
- [x] J-3: `spaces/home.php` includes shared partial with `space_id => $space_id`
- [x] J-4: Composer CSS moved to `bn-feed.css` (~200 lines); removed from inline `<style>` in feed/home.php
- [x] J-5: Composer accepts `space_id` param — space posts default to `space_members` privacy, hides privacy selector

### Phase K — BLOCK MN: WP Menu System — DONE
- [x] K-1: `register_nav_menus('buddynext-community')` — already existed in Plugin.php
- [x] K-2: Custom meta box "BuddyNext Pages" in Appearance > Menus — 8 core pages + Discussions (Jetonomy) + Media (WPMediaVerse) when active
- [x] K-3: Uses Walker_Nav_Menu_Checklist — site owners can check pages and "Add to Menu"
- [x] Container width fix: all templates now use hub shell as sole layout controller (no internal max-width conflicts)

### Phase L — BLOCK L2: Level 2 Context Nav — DONE
- [x] L-1: `buddynext_context_nav` filter added to nav partial — renders sub-nav bar below main nav when items present
- [x] L-2: Discussion context (Home / Search / Leaderboard) — registered in JetonomyBridge
- [x] L-3: Space context handled by space template's own tab bar (`bn-sh-tabs`)
- [x] L-4: Media/Admin context — filterable via `buddynext_context_nav`; WPMediaVerse bridge can add items when needed
- [x] CSS: `.bn-context-nav` with bottom border highlight for active item, scrollable on mobile

---

## Execution Priority (next session)

```
Phase D (hub shell rollout)      — 8 templates ✓ DONE
Phase F (CSS/UX polish)          — 7 fixes ✓ DONE
Phase G (standalone font control) — Jetonomy + WPMediaVerse ✓ DONE
Phase H (hashtag/tag bridge)     — dedicated integration ✓ DONE
Phase I (post card unification)  — 1 block template ✓ DONE
Phase J (unified composer)       — extract to shared partial ✓ DONE
Phase K (WP Menu System)         — site owner control ✓ DONE
Phase L (Level 2 context nav)    — per-section sub-navigation ✓ DONE
```
