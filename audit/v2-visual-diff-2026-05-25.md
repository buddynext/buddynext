# v2 Prototype vs Live — Visual Diff Audit

**Generated:** 2026-05-25
**Method:** Screenshot capture at 1280×800 of each `docs/v2 Plans/v2/*.html` prototype against its live counterpart, followed by structural landmark + visual comparison. Class-count comparison is **not** used — production code prepends the `bn-` namespace per the documented v2 translation rule, so name-by-name comparison is noisy by design.
**Scope:** 5 highest-traffic surfaces (home feed, user profile, member directory, space home, notifications). The remaining 8 prototypes (`explore-feed`, `post-detail`, `dm-list`, `dm-thread`, `spaces-directory`, `search-results`, `onboarding`, `admin`) can be audited with the same recipe.

---

## Verdict at a glance

| Dimension | Status |
|---|---|
| **Markup-level v2 compliance** | ✅ achieved — every part uses the `.bn-*` primitive vocabulary with `data-variant` / `data-tone` / `data-size` attributes; 0 raw hex, 0 inline `<style>`, 0 inline `<script>` in any composer or part. |
| **Layer-3 part contract** | ✅ achieved — 75 parts each fire `_args` / `_classes` / `_before` / `_after`. |
| **Feature parity with v2 prototypes** | 🟡 ~60-65% built — major chrome (hero, tabs, sidebar shell, post-card, modals) matches; **a significant set of v2 widgets / pills / count badges / personalization surfaces is not yet implemented**. |
| **Information density** | 🟡 live is consistently sparser than prototypes by ~30-50% per viewport. |

The refactor did **not** introduce drift — it preserved current state byte-identically. The drift catalogued below is **pre-existing**: features the v2 prototypes call for that haven't yet been built into BN templates/blocks/widgets.

---

## Per-surface findings

### 1. Home feed (`/activity/`)

| What v2 shows | What live shows | Gap |
|---|---|---|
| Filter tabs with count badges: `For you 24` `Following` `Spaces` `Network 3` | Same tabs WITHOUT counts | Counts not surfaced per-tab |
| (no sub-tab row) | Home / Explore sub-tab row appears ABOVE the filter strip | Duplicate-tabbing — Explore is also reachable as a top-level item |
| Post-card author: avatar + name + `Mentor` role pill + Follow button + kebab | Author: avatar + name + (no role pill, no inline Follow) | Role pill widget + inline-Follow not built |
| Inline hashtag chips under body: `#design` `#community` | Not visible | Hashtag-chip primitive not wired into the rendered post card |
| Engagement summary chips: `❤️24` `🎉12` `🚀8` `+` button | Plain `React / Comment / Share / Save` buttons | Reaction-summary primitive (sum + emoji + +) absent |
| Right sidebar — "Good afternoon Varun" personalized greeting card with 7-day streak (M T W T F S S highlighted) | (sidebar shows Trending Topics / People to Follow / Your Spaces only) | Greeting+streak widget not built |
| Right sidebar — "This week" calendar with upcoming events (`MAY 02 Friday office hours · 4pm · Voice room`, `MAY 05 Design critique #7 · 14 going`) | Not present | Calendar/events widget not built |
| Trending tags as colored badges with hover variants | Plain text list of trending topics | Tag-chip primitive variant not wired |
| Left rail: icon-only condensed | Left rail: icon + text labels (Activity / Explore / Members / Spaces / Notifications / Messages + YOU section) | Different density — proto is icon-only; live is icon+label. The shell collapses to icon-only at the rail's compact breakpoint per design; viewport-1280 may not trigger that. |

### 2. User profile (`/members/varundubey/`)

| What v2 shows | What live shows | Gap |
|---|---|---|
| Cover with annotation overlay (`COVER · 1500 × 400`) | Cover with no annotation | Annotation is editor-mode only; not a gap |
| Identity head: `Sarah Rodriguez` + `2nd` degree badge + `she/her` pronouns + `@sarah · joined Feb 2024 · Member #1,124` | `varundubey @varundubey Joined May 2026` | Pronouns, member#, N-th-degree-connection badge not built |
| Bio paragraph: "Designs community platforms…" + location chip + URL chip + "Active in 4 spaces" | (none — user hasn't filled bio) | Bio + location/URL chip primitives present in `parts/profile-hero.php` but the user record has no bio. Real CONTENT gap, not template gap. |
| Action cluster on identity row: `Follow` primary + `Message` + kebab | Owner sees `Edit profile / Edit avatar / Edit cover` row ABOVE the hero | Owner-action buttons in live are positioned as a separate top row; proto keeps the kebab as the single owner-action surface |
| 5 stat tiles: `Posts 284 +12` `Followers 1,228 +18` `Following 438` `Connections 67 +2` `Streak 14d` 🔥 | 4 stat tiles: `Posts 34` `Followers 0` `Following 1` `Connections 0` — NO Streak, no deltas | **5th stat (Streak)** + **stat-delta indicator** not built |
| Right sidebar — Notice card: `🛠️ Heads down on tokens v3 ship · Replies might be slow · until Fri` | (none) | User-status card pattern not built |
| Right sidebar — Profile Completion: circular gauge `82%` + `3 to go` + checklist (Cover ✓ / Bio + tagline ✓ / Skills 6 ✓ / Connect 3+ accounts / Verify email / Add pinned post) | Circular gauge (`%` ring) + "N to go" + curated checklist with ✓ / Add links | ✅ SHIPPED 2026-05-30 — `templates/partials/profile-right-sidebar.php` now renders the v2 ring (`.bn-pf-ring`) from `ProfileService::get_completion_score()` plus a 6-item checklist (bio / tagline / location / skills / work / account). Replaced the linear bar; the old `.bn-completion-*` bar CSS stays for the Gutenberg completion-bar block. Verified light + dark at 50%. |
| Right sidebar — ABOUT card with Work / EDU / Based / TZ / SITE / GITHUB / VERIFIED rows + edit link | Profile Strength is the only widget; no detailed About card | About-card-with-typed-rows primitive not built |
| Pro P5.3 "Who viewed your profile" | Present ✅ — but rendered BELOW stats inside the main column, not in the right sidebar | Placement difference — minor |

### 3. Member directory (`/members/`)

| What v2 shows | What live shows | Gap |
|---|---|---|
| Header: `Members` H1 + meta `2,847 members across 146 spaces · 312 online now` + `+ Invite` button | `Members` H1 + `4 members in the community` + `Edit profile` button | Meta-line richer in proto (online-now count); Invite button missing |
| 4 filter dropdowns: `All members` / `Any role` / `Anywhere` / `Joined: anytime` | 3 nav tabs (All members / Following / Connections) + 1 sort dropdown | Filter pattern fundamentally different — proto = dropdown grid; live = relation tabs |
| Grid/List view toggle | Not present | View-mode toggle not built |
| A–Z alphabet jumper bar | Not present | Alphabet-jumper primitive not built |
| `SUGGESTED FOR YOU` section header + tagline `based on mutual spaces & followed people` | (none) | "Suggested for you" recommendation surface not built |
| Member cards: avatar + name + handle + role title + 1st/2nd degree pill + skill chips + mutual avatars + meta line | Member cards: avatar + name + handle + Follow/Connect buttons | Cards are visually much sparser — no role title, no skill chips, no mutual viz, no degree-pill |
| Right sidebar — `ONLINE NOW 312` widget with live actor status: `Sarah Rodriguez · active in Design` / `Julia Park · typing in #product` / `Emil Holm · in voice room` | (none) | Real-time presence widget not built |
| Right sidebar — `BY ROLE` widget: `Members 2,712 · Moderators 98 · Admins 12 · Pro members 25` | (none) | Role-summary widget not built |

### 4. Space home (`/spaces/open-discussion/`)

| What v2 shows | What live shows | Gap |
|---|---|---|
| Hero: large `Design` H1 + `Public` badge + `Paid tier` badge + handle `@buddynext/design` + meta `open · english · 2,418 members · 84 posts/week · 12 online now · Started Jan 2024` | Hero: `Open Discussion` + `Open` badge + minimal metadata | Tier badge + dense metadata line not built |
| Action cluster: bell icon + ellipsis + `Invite` + `Member` button (joined-state) | bell + `Invite` + `Settings` | Member-state button missing; Settings is owner-only and shown here |
| 6 tabs with count badges: `Feed 24 / Forum 12 / Members 2.4k / Library 38 / Events 3 / About` | 5 tabs: `Feed / Members / Media / About / Moderation` (no counts) | Forum/Library/Events tabs not built; Moderation is owner-only addition |
| Pinned post inside Feed tab: `READ FIRST · WELCOME THREAD` pill + title + body + meta | Composer at top instead — no pinned post in screenshot scope | Pinned-post slot rendering present in code, just no pinned content here |
| Right sidebar — `🔴 LIVE · VOICE ROOM` widget: `Friday office hours · 5 talking · 12 listening · Sarah is hosting` + Join button + avatar pile | (none) | Live voice-room widget not built |
| Right sidebar — `About this space` description card with stats: `2,418 members · 84 posts/wk · 3 mods` | `ABOUT THIS SPACE` card with `3 MEMBERS · 0 POSTS` + creation date | Lighter version of same widget |
| Right sidebar — `Moderators` with profile + role + Message button per row | `MEMBERS` widget with Admin badge per row | Moderators widget specifically is missing — the live widget shows all members |
| Right sidebar — `Library All 38` link | (none) | Library widget not built |

### 5. Notifications (`/notifications/`)

| What v2 shows | What live shows | Gap |
|---|---|---|
| Header: `Activity` + `4 new` blue badge + `Mark all read` + `Settings` | `Notifications` + `Settings` | "X new" badge in header missing — but Mark-all-read button shows only when unread > 0 (current acct has 0) |
| 8 tabs with count badges: `All 4 / Mentions 1 / Replies / Reactions / People / Spaces / AI digests 2 / Paid` | 6 tabs without counts: `All / Unread / Mentions / Comments / Reactions / Spaces` | Tab count badges missing; **AI digests** + **Paid** tabs not built |
| `AI digest` row variant: blue-bordered card with body summary, `Open thread / Skip / Daily digest →` actions | (none) | AI-digest notification primitive not built |
| Reaction-cluster row: `Sarah Rodriguez, Lena Park and 5 others liked your post` with quoted post body and `posted in @design · 84 likes total` meta | Simple per-actor rows (no clustering) | Notification-clustering primitive not built |
| Mention row with `Reply / Quote` action buttons inline | Plain mention rows | Inline quick-reply on notifications not built |
| Right sidebar — `Quick filters` ✅ present in both | ✅ present | Match |
| Right sidebar — `THIS WEEK` stats widget: `187 notifications +18%` / `142 read rate 76%` / `2.4× reach delta` / `12 new follows` | (none) | Engagement-summary widget not built |
| Right sidebar — `MUTED` list: `@marketing announcements 14d left` / `"weekly digest" thread forever` / `replies from @anon-bot forever` + `Manage muted` | (none) | Mute-management UI not built |

---

## Patterns across all 5 surfaces

These are themes that recur, not isolated finds — each pattern represents a primitive or system that exists in v2 but has not been built into the BN templates yet.

### A. Count badges on tabs (Gate-2 follow-up) ✅ SHIPPED
Filter / nav tabs in protos consistently surface a per-tab count (`For you 24`, `Mentions 1`, `Forum 12`, `Library 38`, `AI digests 2`). Live tabs render the label only. **Affects:** home feed, notifications, space home. **Effort:** ~1 day — extend `parts/space-tab-bar`, `parts/notifications-filter-bar`, etc. to accept `count` per row, render `<span class="bn-tab__count">` when set; populate via existing service queries.

**Status (2026-05-26):** Done. Home feed (`bn-feed-filter-tabs`) and notifications (`parts/notifications-filter-bar`) already shipped with chips. Space tabs finalised in commit `3b2611a` — adds Media (posts with media) and Moderation (open reports, admin-gated) counts to `templates/spaces/home.php`.

### B. Stat-delta indicators ✅ SHIPPED
Stat tiles in protos carry deltas (`Posts 284 +12`, `Followers 1,228 +18`, `Notifications 187 +18%`). Live stat tiles show absolute value only. **Affects:** user profile, notifications, future analytics widgets. **Effort:** ~1 day — add `delta_value` + `delta_tone` args to `parts/profile-stats-strip`, render the `<span class="bn-stat__delta">` when set.

**Status (2026-05-26):** Done across both surfaces.
  - **Profile stat strip:** `parts/profile-stats-strip.php` accepts `delta` + `trend` per tile and renders `<span class="bn-pf-stat__delta" data-trend>`. `templates/profile/view.php` computes 7-day deltas for posts, followers, following, connections from `bn_posts.created_at` + `bn_follows.created_at` + `bn_connections.created_at`. Verified live on `/members/varundubey/` — "34 Posts · +34" and "1 Following · +1" chips visible.
  - **Notifications sidebar:** `parts/sidebar-this-week-stats.php` renders a four-tile week-over-week card. The "read" tile carries a `data-trend` WoW % delta computed from prior-week reference. Verified live on `/notifications/`.
  - Fix in commit (incoming): `follower_delta_7d` + `following_delta_7d` SQL now filters `status='approved'` to match `FollowService::follower_count()` after the S2 private-follow gate landed — otherwise pending follow requests would inflate the delta beyond the absolute count.

### C. Engagement-summary chips under posts ✅ SHIPPED
v2 post cards show `❤️24 🎉12 🚀8 +` chips above the action row — this is the **reaction summary primitive**. Live post cards only render the action buttons (React / Comment / Share / Save) and a count under the post for total reactions. **Effort:** ~1-2 days — wire `parts/post-reaction-summary` to render top-3 emoji + counts; data already exists in `bn_reactions`.

**Status (2026-05-26):** Done. `templates/parts/post-reaction-summary.php` renders per-emoji chips using `buddynext_get_emoji()` (Microsoft Fluent SVGs) keyed by the top-3 emoji types on each post; `templates/partials/post-card.php` computes the top-3 from `bn_reactions` (skips the query when `reaction_count = 0`) and passes the list in. Comment + share chips render alongside in the same strip when their counts are non-zero. Verified live at `/activity/explore/` — 17 chips across 8 cards, mix of `--reaction` emoji variants and comment/share variants.

### D. Right-sidebar widget catalogue 🟡 IN PROGRESS
Protos consistently use the right sidebar for **dense, personalized, time-relevant info cards** (greeting + streak, this-week calendar, live voice room, online-now actor list, by-role summary, this-week stats, muted list, status update). Live sidebars use it for **navigational cards** (Trending Topics, People to Follow, Your Spaces, About this space, Members). **Effort:** ~2-4 weeks — each widget is a separate ~2-3 day build with its own data source and Layer-3 part. List of widgets to build catalogued in the per-surface tables above.

**Status (2026-05-30):** Five widgets now ship end-to-end — **greeting + streak (D-1)**, **upcoming events / "This week" (D-2)**, **by-role (D-4)**, **this-week stats (D-6)**, and **muted list (D-15)**. The PHP parts (`templates/parts/sidebar-*.php`) had landed earlier but were rendering **unstyled** (the streak strip showed as a numbered `<ol>`, oversized headings, etc.) because no CSS had been written for their internal markup. CSS for D-1/D-2/D-4/D-6 added to `assets/css/bn-base.css` (cross-surface — these widgets appear on feed / notifications / member-directory, so they must live in the always-loaded base sheet, not a per-surface one). Verified live in light + dark on all three surfaces.

While verifying, found and fixed a **global dark-mode token bug** (`includes/Theme/TokenService.php`): the legacy aliases bridge through `var(--wp--preset--color--base, var(--bn-*))` so the host block theme's palette wins in light mode, but `get_dark_overrides()` returned `array()` — so in dark mode the static **light** WP preset resolved first and the dark `--bn-*` fallback never fired. Every `.bn-card` / `.bn-sidebar-card` rendered as a white box with near-white text. Fixed by re-pinning the preset-bridged aliases to their dark `--bn-*` sources under both `[data-bn-theme="dark"]` and `[data-theme="dark"]`. This corrected dark mode across the **entire** UI, not just these widgets.

Still unbuilt under D: online-now actor list, live voice-room widget (Pro P3 realtime), moderators widget, library widget, status-update card, profile-completion circular gauge, about-card-with-typed-rows.

### E. Identity-row enrichment 🟡 MOSTLY BUILT (this entry was stale)
v2 author/identity rows include **role pills** (`Mentor`, `Mod · founder`, `Mod · since 2024`), **pronouns**, **N-th-degree connection badge**, **member number**, **mutual-avatar pile**, **skill chips**, **inline Follow/Message buttons**. Live identity rows show only avatar + name + handle. **Effort:** ~2 weeks — extend `parts/post-byline`, `parts/profile-hero`, `parts/member-card`; some of this hooks into the P10 Member Labels work already shipped in Pro.

**Status (2026-05-30):** The "live shows only avatar + name + handle" claim above was **wrong** — it was written against empty test accounts, so the built enrichment never rendered. Ground-truth audit of the three parts shows most of E is already shipped:

- **`parts/profile-hero.php`** supports `pronouns`, `headline`, `bio`, `location`, `website`, `joined`, `mutual_count`, `degree_badge`, `member_type`, social links, and follow/connect/pending states — plus a profile-completion gauge. Verified live (light + dark) after seeding `wp_bn_profile_values` for one account: `@handle (he/him)`, headline, bio, location + website chips, "Joined …", and a 50% completion ring all render correctly.
- **`parts/member-card.php`** supports `degree` (1st/2nd pill), `mutual_count`, `bio`, `member_type_label`, presence, the full Follow / Connect / Message / mute cluster, and now a **mutual-avatar pile** (added 2026-05-30) — up to 3 overlapping mutual-connection avatars before the count. The directory grid derives the count + pile from a single `mutual_connections()` lookup per card (the `mutual_ids_fn` contract replaced the old count-only `mutual_count_fn`). Verified live (light + dark): member2's card shows member1's avatar + "1 mutual connection".
- **`parts/post-byline.php`** renders the `member_type` pill + space attribution and now the **connection-degree pill (1st/2nd)** — added 2026-05-30, matching `member-card` / `profile-hero`. Degree is computed in `post-card.php` only for other people's posts, memoized per `(viewer:author)` for the request so a feed of N cards costs at most one lookup per unique author. Verified live (light + dark) on `/activity/explore/` with seeded 1st- and 2nd-degree connections. Inline Follow on the byline is still unbuilt.
- **Field model exists** — `pronouns` (`basic_info`) and `skills`/`interests` are registered in `Installer.php` and stored in the `bn_profile_*` custom tables (not usermeta). Skill chips render in the profile "Community Interests" section + sidebar.

**Real remaining gaps (not "all of E"):** inline Follow on the **post byline** (a UX call — risks feed clutter / CLS); **member number** (no field — low value). The directory/feed look sparse mostly because **accounts have empty profiles**, not because the templates lack the fields — a content gap, as this doc's own profile table already noted.

### F. Real-time / presence
v2 shows real-time signals throughout: `active in Design`, `typing in #product`, `in voice room`, `LIVE`. Live has no presence indicators. **Effort:** large — this is the **Pro P3 Realtime + Soketi** layer; the infrastructure ships in Pro, but the front-end widgets need building. Coordinate with the Pro realtime team.

### G. AI / personalization surfaces
v2 has AI features visible: `AI digests` notification tab + dedicated AI-digest row variant, `Suggested for you` directory section, personalized greeting card. **Effort:** AI digest is **Pro P2 AI engine** (smart-reply / classifier / semantic search shipped; digest format not yet). Suggested-for-you is unbuilt.

### H. Information density
At equal viewports, protos show ~30-50% more functional content per fold than live. This is a cumulative consequence of A-G — protos surface counts, deltas, presence, suggestions, mute-lists, reaction summaries that live omits. The chrome itself is the same; what's missing is **content + meta** on every surface.

---

## What this audit does NOT say

- **The refactor sweep is not the cause** of any drift listed here. The refactor was byte-identical output from a pre-existing state; whatever was missing before is still missing. Whatever was present is still present.
- **No regressions were introduced.** Every surface that previously worked still works (verified live in earlier verification passes).
- **The v2 prototypes are not contracts that must match pixel-for-pixel.** They are the design source. Some divergence (e.g., owner-action button placement on profile, theme-owned topbar instead of v2 condensed topbar) is intentional and documented in `docs/v2 Plans/TEMPLATE-REFACTOR-PLAN.md` § "Why no opt-out".

## Suggested next steps in priority order

1. **Wire count badges into tab bars** (Pattern A) — 1 day, unblocks 3 surfaces, lifts perceived information density across the board.
2. **Wire engagement-summary chips on post cards** (Pattern C) — 1-2 days, highest-traffic visual win.
3. **Wire stat-delta indicators** (Pattern B) — 1 day.
4. **Build the right-sidebar widget catalogue incrementally** (Pattern D) — each widget is independently shippable; suggested first three: `Greeting + streak`, `This week / upcoming events`, `Online now`. Each ~2-3 days.
5. **Enrich identity rows** with role pills, pronouns, member #, mutual viz (Pattern E) — 1-2 weeks; coordinate with Pro Member Labels (P10) which already ships the label-chip primitive.
6. **Real-time / presence (F) and AI surfaces (G)** are larger, multi-week initiatives that depend on Pro's P3 + P2 infrastructure being wired into front-end consumers.

---

## Reproducing this audit

Screenshots live under the WordPress root at `wp-content/uploads/bn-v2-proto/v2/*.html` (symlinked from `docs/v2 Plans/v2/`). For each pair:

```bash
# Prototype URL
http://buddynext-dev.local/wp-content/uploads/bn-v2-proto/v2/{surface}.html

# Live URL
http://buddynext-dev.local/{surface-route}/?autologin=1
```

Saved screenshots: `wp-content/plugins/buddynext-dev/app/public/v2-{proto|live}-{surface}-1280.png`. Repeat at 390×844 for the mobile compare.
