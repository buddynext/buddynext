# Frontend Screen Coverage Checklist (100% — nothing left behind)

Every member-facing screen AND state. Each must be verified at **desktop + 390px +
dark** and brought to the canonical hub standard (see
`hub-consistency-consolidation.md`). Bugs hide in skipped states, so empty/error/
modal states are tracked explicitly, not just the happy path.

Legend: `[ ]` not started · `[~]` in progress · `[x]` verified-conformant.
Columns per row: desktop / 390px / dark / conformant-to-standard.

## Feed
- [ ] feed/home — composer, filter tabs (For you / Following / …), feed list, **empty state**, infinite-scroll spinner
- [ ] feed/explore — explore hero, filter pills, grid, **empty**
- [ ] feed/bookmarks — header, list, **empty**
- [ ] feed/single-post — breadcrumb, post card, comments thread, **deleted-comment tombstone**, reactors popover

## Profile (reference standard)
- [ ] profile/view — hero (+headline tagline), tabs: Posts / Replies / Likes / Media / Network / Discussions, About cards, right sidebar, **each tab empty state**
- [ ] profile/edit — hero edit, field groups, repeater (work/education) add-row, save errors (422)
- [ ] profile/connections — list, **empty**
- [ ] profile/followers — list, **empty**
- [ ] profile/following — list, **empty**

## Members directory
- [ ] directory/members — hero, filter bar (search / type / Online only / Recently active sort / grid|list), card (avatar/name/handle/headline/type/actions), list view, **empty / no-match state**, pagination

## Spaces
- [ ] spaces/directory — hero, category chips, sort, grid, **empty**
- [ ] spaces/home — hero + nav-bar, feed, right sidebar, **avatar tones (C6)**
- [ ] spaces/members — header, member list, filter, pagination, **empty**
- [ ] spaces/moderation — header, **sub-nav (C2)**, queue, reports, 2-col layout
- [ ] spaces/settings — **header (C1)**, settings tabs, forms
- [ ] spaces/admin — **header (C1)**, tile launcher
- [ ] spaces/paywall — interstitial
- [ ] space states — join / request-to-join / pending / banned / private gate

## Messages (deliberate edge-to-edge surface — exempt from centered shell)
- [ ] messages/native — rail, thread, composer, reaction picker, **empty / no-conversation**
- [ ] messages/requests — request banner, accept/decline
- [ ] messages/thread (deep link), list (deep link)

## Notifications
- [ ] notifications/index — hero, filter bar, rows (**desktop horizontal layout done**), Today/Yesterday groups, **empty**, mark-all-read
- [ ] notifications/prefs — settings chrome, channel/type prefs

## Search
- [ ] search/results — hero, type tabs, results per type, **empty / no-query**

## Other hub
- [ ] hashtags/feed — hero, related, posts, **own-grid/double-sidebar (C3)**, **empty**
- [ ] gamification/leaderboard — header, period tabs, table, **own-aside (C3)**
- [ ] onboarding/index — wizard steps (intro → profile → interests → spaces → done), stepper (full-bleed, exempt)

## Auth (logged-out)
- [ ] auth/login — panel + sign-in form (also via [buddynext_auth] shortcode)
- [ ] auth/signup — register form, validation errors, reg-closed state
- [ ] auth/verify — email-verify
- [ ] auth/reset — password reset request + set

## Moderation / status
- [ ] moderation/queue — filters, report rows, actions
- [ ] moderation/account-status — suspended / strike / appeal states

## Admin
- [ ] community-admin — admin hub tiles

## Cross-cutting (verify on representative pages)
- [ ] Host-theme bleed: no theme link-underline / button-bg on BN chrome (BuddyX + Reign)
- [ ] Right sidebar parity across hub pages
- [ ] Empty states use shared `notifications-empty`/equivalent, consistent voice
- [ ] All `<a>`/`<button>` carry BN classes (single-post breadcrumb = C4)
