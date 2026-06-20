# Doc Action Coverage — customer-journey audit

Goal: a customer performs many actions. Every action should have (a) a doc that explains it and (b) an image that SHOWS it. This matrix lists each action, the doc that covers it, and the image status:

- ✅ shown — an image depicts this exact action/screen
- 🟡 hero-only — the doc has a feature image but not a step/action close-up of THIS action
- 🖥️ backend — owner action; specific admin-tab image present
- ✗ gap — no image shows this action yet

Library: `docs/website/images/`. Source of actions: the journey wiring tables (`docs/journeys/*.md`) + the live REST/admin surface.

---

## A. Member actions — frontend (the "lots of actions" a customer does)

### Accounts & access
| Action | Doc | Image |
|---|---|---|
| Register / sign up | accounts-access/01-registration | ✅ onboarding + 🖥️ admin-registration |
| Log in | accounts-access/02-login | ✅ login |
| Social login (Google/FB/GitHub/Discord) | accounts-access/03-social-login | ✅ login + 🖥️ admin-social |
| Verify email / resend | accounts-access/04-email-verification | 🟡 onboarding + 🖥️ admin-registration |
| Set up / disable 2FA, backup codes | accounts-access/05-two-factor-authentication | ✗ needs **2FA setup** front shot (has member-profile) |
| Complete onboarding wizard (steps) | accounts-access/06-member-onboarding | ✅ onboarding |
| Edit profile / upload avatar & cover | accounts-access/07-account-settings, members/01 | ✗ needs **profile edit form** shot (has member-profile view) |
| Set profile field values | members/02-profile-fields | 🟡 member-profile |
| Set vanity slug | accounts-access/07-account-settings | ✗ |
| Change email / password, sign out everywhere | accounts-access/07-account-settings | ✗ needs **account-security** shot |
| Export data / delete account | accounts-access/08-privacy-and-data | 🟡 member-profile + 🖥️ admin-privacy |

### Social graph
| Action | Doc | Image |
|---|---|---|
| Follow / unfollow | members/06-following | ✅ member-directory (Follow buttons) |
| Connect / accept / decline | members/07-connections | 🟡 member-directory |
| Approve / reject follow requests | members/06-following | ✗ |
| Block / mute / restrict, and undo | members/08-blocking-and-muting | ✗ needs **block modal / relations** shot |

### Feed & content
| Action | Doc | Image |
|---|---|---|
| Post text / link | community/02-post-composer | ✅ community-activity-feed (composer) |
| Create a poll / vote | community/06-polls | 🟡 feed (needs **poll card** shot) |
| Schedule a post (Pro) | pro/05-scheduled-posts | 🟡 feed + 🖥️ admin-scheduled |
| Post an announcement | community/11-announcements | 🟡 feed + 🖥️ admin-features |
| React / swap / un-react | community/04-reactions | 🟡 post-detail (needs **reaction picker open**) |
| See who reacted | community/04-reactions | ✗ needs **reactor popover** |
| Comment / reply / edit / delete / pin | community/05-comments | ✅ post-detail (comments) |
| Share / repost | community/07-shares-reposts | 🟡 feed (needs **share dialog**) |
| Bookmark / view bookmarks | community/08-bookmarks | 🟡 feed (needs **bookmarks list**) |
| Mention a member | community/10-mentions | ✗ needs **@-mention typeahead** |
| Use a #hashtag / follow hashtag / trending | community/09-hashtags | 🟡 search (needs **hashtag feed**) |
| Browse Explore | community/13-explore | ✅ explore |

### Members & discovery
| Action | Doc | Image |
|---|---|---|
| Browse directory, search / filter / sort | members/04-member-directory | ✅ member-directory |
| View a member profile | members/01-member-profiles | ✅ member-profile |
| Member types / labels | members/05-member-types | ✅ member-directory + 🖥️ admin-labels |

### Spaces
| Action | Doc | Image |
|---|---|---|
| Browse spaces directory | spaces/01-spaces-overview | ✅ spaces-directory |
| Create a space | spaces/02-creating-a-space | ✗ needs **create-space form** (has spaces-directory + 🖥️) |
| Join / request / cancel / leave | spaces/03-space-types-and-privacy | 🟡 space-home |
| View a space home / post in space | spaces/09-space-forum | ✅ space-home |
| Manage members / change roles | spaces/04, 05 | 🟡 space-home + 🖥️ admin-spaces-settings |
| Ban / unban from space | spaces/06-space-bans | 🟡 space-home + 🖥️ moderation-queue |
| Space categories / notifications | spaces/07, 08 | 🟡 + 🖥️ |

### Messaging & notifications
| Action | Doc | Image |
|---|---|---|
| Send a DM / accept request | messaging-notifications/01-direct-messaging | ✅ direct-messaging + dm-thread |
| View notifications, mark read, dismiss | messaging-notifications/02-notifications | ✅ notifications |
| Set notification preferences / channels | messaging-notifications/03-notification-preferences | ✅ notifications + 🖥️ admin-notifications |

### Search & moderation (member side)
| Action | Doc | Image |
|---|---|---|
| Search (grouped, by type) | community/12-search | ✅ search |
| Report content | moderation/01-reporting-content | 🟡 post-detail (needs **report modal**) |
| Submit an appeal | moderation/04-appeals | 🟡 moderation-queue |

---

## B. Owner actions — backend (admin tabs)

Each admin tab = an owner action surface; every settings/feature doc should show its specific tab.

**Captured & mapped (✅):** Registration, Privacy, Roles, Labels, Features, Integrations, Webhooks, Reactions, Social, Insights, Email, Notifications, Spaces settings, Moderation controls, Moderation queue, Tiers, Stripe, Auto-mod (Rules), Broadcasts, Drip, Scheduled, Push, admin Overview.

**Not yet captured (✗ — use closest for now):** General, Appearance, Navigation, Pages & URLs, Tools, Members Directory (admin), Spaces Directory (admin), Email Templates, Moderation Pending / Reports / Suspensions / Log, Bulk Moderation, Auto-mod AI, Subscriptions, License, Paywall, AI Feed, Realtime, Push Prefs.

---

## C. Prioritized gaps to fill

**Frontend action close-ups (member "full guide"):**
1. Reaction picker open + reactor popover (reactions)
2. Comment edit/pin controls (comments) — partly in post-detail
3. Poll card with vote (polls)
4. Share dialog (shares)
5. Bookmarks list (bookmarks)
6. @-mention + #hashtag typeahead (mentions, hashtags)
7. Create-space form (spaces/creating)
8. Join/leave + role/ban controls (spaces)
9. Profile edit form + avatar/cover upload (account-settings, profiles)
10. 2FA setup + account-security panel (2FA, account-settings)
11. Block/report modals (blocking, reporting)
12. Hashtag feed page (hashtags)

**Backend tabs to capture (owner actions):** the ~18 ✗ tabs in section B, then remap the docs currently on a closest-match image (white-label→Appearance, gated-spaces/content-protection→Paywall, wp-cli→Tools, moderation docs→their specific tab, bulk-moderation→Bulk, ai-feed-and-moderation→AI, etc.).

---

## Update 2026-06-20 — frontend action close-ups captured

Section C's frontend gaps are now filled with live, presentable close-ups (dark mode to
match the library; admin bar hidden; test data scrubbed; secrets blurred). Each is embedded
in its doc:

| Action close-up | Image | Doc |
|---|---|---|
| Reaction picker open | `reaction-picker.png` | community/04-reactions |
| Poll card with results | `poll-card.png` | community/06-polls |
| Share dialog | `share-dialog.png` | community/07-shares-reposts |
| Bookmarks list (Save) | `bookmarks-list.png` | community/08-bookmarks |
| Hashtag feed page | `hashtag-feed.png` | community/09-hashtags |
| @-mention typeahead | `mention-typeahead.png` | community/10-mentions |
| Create-space form | `space-create-form.png` | spaces/02-creating-a-space |
| Two-factor setup | `twofa-setup.png` | accounts-access/05-two-factor-authentication |
| Account security panel | `account-security.png` | accounts-access/07-account-settings |
| Report dialog | `report-modal.png` | moderation/01-reporting-content |
| Block confirmation | `block-modal.png` | members/08-blocking-and-muting |

Plus `profile-edit.png` (edit profile / avatar / cover) shipped earlier the same day.

**Still open (one item):** the **reactor "see who reacted" popover** could not be captured —
under headless it returns the reactor list (200) but renders an empty list and auto-closes
(a hydration timing quirk, same class as the earlier hashtag false-positive). Logged in
`FLOW-VERIFICATION-2026-06-20.md` for real-browser confirmation; no empty-popover image was
shipped (it would read as broken). The reactions doc uses `reaction-picker.png` meanwhile.

Library now 66 images; 130/130 docs covered; every image referenced; no broken refs.
