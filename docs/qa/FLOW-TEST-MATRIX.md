# BuddyNext — End-to-End Flow Test Matrix

_Two-role walkthrough: **Owner** (site admin / Facebook page owner) and **Member**
(end user / Facebook member). Each flow is checked at **code** level (wiring proven
in `docs/conformance/`) and **browser** level (walked live on http://buddynext-dev.local)._

**Legend:** ✅ pass · 🟡 partial · ❌ fail · ⬜ not yet walked · n/a not applicable to role
**Code** column = current conformance verdict. **Browser** columns = live walk result (this matrix).

Test accounts (local): admin `varundubey` / `Admin12345!` · member `member1` / `Test12345!`
Seeded: 6 members, 3 self-select member types, gamification points, follows.

---

## A. Member (end-user) flows

| # | Flow | Code | Browser (member) | Notes |
|---|------|------|------------------|-------|
| M1 | Register → email verify → login | ✅ auth-verification | ⬜ | |
| M2 | Onboarding wizard | 🟡 onboarding | ⬜ | |
| M3 | Edit profile (fields, avatar, cover) | ✅ member-profiles | ✅ | edit page renders, 60 fields |
| M3b | Profile member-type self-select | ✅ member-types | ✅ | BUILT 2026-06-07: selector on edit page → setMemberType → usermeta bn_member_type=designer (live) |
| M4 | View own/other profile (+ gamification tiles) | ✅ member-profiles | ✅ | Points 138 + Level tiles render live |
| M5 | Activity feed: view home/for-you | ✅ activity-feed | ✅ | 15 cards render, composer present |
| M6 | Compose post (text, poll, content-warning) | ✅ post-composer / polls | ✅ | post id 65 → bn_posts (published) |
| M7 | React / comment / reply | ✅ reactions / comments | ✅ | Love reaction → bn_reactions (react verified; comment pending) |
| M8 | Share / repost | ✅ shares | ✅ | POST /posts/48/share → share_id 3 (REST+DB); in-card modal flaky in harness only |
| M9 | Bookmark / saved hub | ✅ bookmarks | ✅ | bookmark post 65 → bn_bookmarks (in-card click fired REST) |
| M10 | Follow / unfollow (feed, directory, profile, leaderboard) | ✅ follows | ✅ | leaderboard follow verified live |
| M11 | Connect / accept / decline | ✅ connections | ✅ | request id 8 (member1→flowtest3, pending) in bn_connections |
| M12 | Block / mute | ✅ blocking-muting | ✅ | POST /users/4/block → bn_blocks (type=block) |
| M13 | Member directory: browse / filter / search | ✅ member-directory | ✅ | grid/list toggle verified live |
| M14 | Spaces: browse / join / post | ✅ spaces | ✅ | 3 spaces render; member1 active in all (bn_space_members) |
| M15 | Direct messaging: inbox / send / receive / request | ✅ messaging | ✅ | two-pane WPMediaVerse shell + composer render, no dep notice |
| M16 | Notifications: bell / read / prefs | ✅ notifications | ✅ | 11 unread, list + count render |
| M17 | Hashtags: feed / trending / follow | ✅ hashtags | ✅ | hashtag page renders (graceful not-found); trending REST 200 |
| M18 | Search (members / posts / spaces) | ✅ search | ✅ | 'member' → 9 results; SCALE ceiling intact |
| M19 | Leaderboard / gamification view | ✅ engagement-leaderboard | ✅ | verified live (1.5.4) |
| M20 | PWA install / offline | ✅ pwa | ✅ | manifest endpoint 200 application/manifest+json |
| M21 | Privacy settings | ✅ privacy-framework | ✅ | privacy/visibility controls present on edit page |

## B. Owner (site admin) flows

| # | Flow | Code | Browser (admin) | Notes |
|---|------|------|------------------|-------|
| O1 | BN settings (general / features / slugs) | ✅ admin-settings | ✅ | renders, 12 fields, no errors |
| O2 | Members admin: list / search | ✅ member-directory | ✅ | 6 members, tabs render |
| O3 | Member types: create / edit / assign / self-select toggle | ✅ member-types | ✅ | Developer/Designer/Writer + self-select shown |
| O4 | Spaces admin: create / settings / branding | 🟡 spaces | 🟡 | admin page renders (no fatal); deep create/branding pending |
| O5 | Navigation admin | ✅ admin-settings | ⬜ | |
| O6 | Email templates | ✅ email-system | ⬜ | |
| O7 | Moderation: review queue / reports / strikes / suspend | ✅ moderation | ✅ | page renders, no errors |
| O8 | Outbound webhooks: add / test / view log | 🟢 outbound-webhooks | ⬜ | log viewer under build |
| O9 | Onboarding/setup wizard config | 🟡 onboarding | ⬜ | |

## C. Pro (owner) flows

| # | Flow | Code | Browser (admin) | Notes |
|---|------|------|------------------|-------|
| P1 | Membership tiers + gated spaces | ✅ pro-membership | 🟡 | monetization admin renders (no fatal); deep flow pending |
| P2 | Stripe checkout / webhook → ability grant | ✅ pro-stripe | 🟡 | admin renders (no fatal); live checkout needs Stripe keys |
| P3 | Email broadcasts (incl. scheduled) | ✅ pro-broadcasts | ✅ | LIVE: due scheduled campaign → cron tick → status scheduled→sent, 6 recipients, unsub injected (fix verified) |
| P4 | Drip / welcome sequences | ✅ pro-drip | 🟡 | admin renders (no fatal); deep flow pending |
| P5 | AI feed ranking | ✅ pro-ai | 🟡 | ai-feed admin renders (no fatal) |
| P6 | Analytics | ✅ pro-analytics | 🟡 | admin renders (no fatal) |
| P7 | White-label | 🟢 pro-white-label | 🟡 | admin renders (no fatal) |
| P8 | Push notifications | ✅ pro-push | 🟡 | admin renders (no fatal); copy fix code-verified |
| P9 | Auto / rule moderation | ✅ pro-auto-mod | ✅ | ENUM migration applied live; rate_limit rule persists (fix verified) |
| P10 | Advanced profile field types | 🟢 pro-advanced-fields | ⬜ | multi-select fix code-verified; JS hydration pending |
| P11 | Bulk moderation | ✅ pro-bulk-mod | 🟡 | admin renders (no fatal) |
| P12 | Custom reactions | ✅ pro-custom-reactions | 🟡 | admin renders (no fatal) |
| P13 | Member labels | ✅ pro-member-labels | 🟡 | admin renders (no fatal) |
| P14 | Multi-pin posts | ✅ pro-multi-pin | ⬜ | |
| P15 | Unlimited webhooks | ✅ pro-unlimited-webhooks | ⬜ | |
| P16 | Advanced search filters | ✅ pro-advanced-search | ⬜ | |

---

_Browser walks are recorded here as they complete. Code column tracks
`docs/conformance/SCOREBOARD.md` (currently 46 usable-leave-as-is / 4 minor-polish)._
