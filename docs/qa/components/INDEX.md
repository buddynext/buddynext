# BuddyNext QA — Component-by-Component Index

Master QA suite, organized **one file per feature component** (the plugin is large; component
isolation keeps QA findings and regressions traceable). This complements — never duplicates —
the existing role-based catalogues:

- `docs/qa/JOURNEYS.md` — 67 frontend journeys, organized by role (J-01…J-67).
- `docs/qa/FLOW-TEST-MATRIX.md` — Owner/Member/Pro pass-fail tracker (M/O/P rows).
- `audit/manifest.json` — canonical inventory (135 REST endpoints, 39 tables, 21 caps, 55 services).

Each component file below **cross-references** those by ID instead of copying them.

## How this set is used

- **Authoring:** one `NN-<component>.md` per component, free first then Pro.
- **Backend audit:** every admin option justified + gaps/missing-must-haves flagged (site-owner lens).
- **Frontend audit:** every user-facing function walked, function-by-function.
- **QA cases:** numbered `QA-<COMP>-NNN`, runnable on `http://buddynext.local` (autologin `?autologin=1`).
- **Triage:** a real bug found here, or filed by the team in Basecamp **Possible Bug**, is verified on
  local and moved to **Bugs** with proof (see Basecamp project 47683682).

## Per-component file template (fixed — keep every file identical in shape)

```
# QA — <Component>  (<free|pro>)

**Manifest refs:** <tables · REST routes · services · capabilities>
**Cross-ref (no dup):** JOURNEYS J-xx · FLOW-TEST-MATRIX <rows> · scope card <id>
**Admin location:** <BuddyNext → Settings → Tab, or admin page slug, or "frontend-only">

## 1. Backend settings & options (justify each)
| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |

## 2. Frontend functions (function-by-function)
| Function | Surface / route | REST endpoint | Expected behaviour |

## 3. QA cases
| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
(Layer = backend | frontend | api ; IDs are stable, append-only.)

## 4. Site-owner expectations & suggestions
- <option/feature a typical community owner expects but is missing> → Suggestion / must-have
```

## Component map (21 — mirrors Basecamp Scope domains)

### Free
| # | File | Component | Primary admin tab(s) |
|---|------|-----------|----------------------|
| 01 | `01-social-graph.md` | Social Graph — follows, connections, blocks, mutes | Social |
| 02 | `02-activity-feed.md` | Activity Feed — posts, polls, bookmarks, shares | Features |
| 03 | `03-reactions-comments-hashtags.md` | Reactions, Comments & Hashtags | Features |
| 04 | `04-profiles-directory-search.md` | Profiles, Member Directory & Search | General, Registration |
| 05 | `05-spaces.md` | Spaces — sub-communities | Spaces |
| 06 | `06-notifications-email.md` | Notifications & Email | Notifications, Email |
| 07 | `07-moderation.md` | Moderation — reports, strikes, suspensions, appeals | Moderation |
| 08 | `08-direct-messaging.md` | Direct Messaging (WPMediaVerse engine, BN UI) | General (DM baseline) |
| 09 | `09-onboarding-invites.md` | Onboarding, Invites & Setup Wizard | Registration |
| 10 | `10-blocks-routing.md` | Gutenberg Blocks (17) & Frontend routing | — (frontend) |
| 11 | `11-admin-hub-settings.md` | Admin Hub & Settings (all 11 tabs), Nav, Email editor, Integrations | All |
| 12 | `12-rest-api-webhooks.md` | REST API (135) & Outbound Webhooks | Webhooks, Integrations |
| 13 | `13-licensing-updates.md` | Licensing & Updates (EDD SL SDK) | Settings → Advanced/License |
| 14 | `14-bridges.md` | Bridges — Jetonomy, WPMediaVerse, WBGamification, Career Board | Integrations |
| 15 | `15-privacy-data.md` | Privacy & Data (retention, export, member privacy) | Privacy & Data |

### Pro
| # | File | Component |
|---|------|-----------|
| P1 | `p1-membership-stripe.md` | Membership Tiers, Gated Spaces & Stripe |
| P2 | `p2-email-campaigns.md` | Email Campaigns — Broadcasts & Drip Sequences |
| P3 | `p3-moderation-rules-labels.md` | Moderation Rules, Bulk Actions & Member Labels |
| P4 | `p4-ai-feed-moderation.md` | AI — Feed Ranking & Content Moderation |
| P5 | `p5-realtime-push.md` | Realtime (Soketi) & Mobile Push (FCM) |
| P6 | `p6-analytics-profile-views.md` | Analytics & Profile Views |
| P7 | `p7-whitelabel-domains.md` | White-label, Custom Domains & Feed Extras |

_Status: INDEX + template locked 2026-06-15. Component files authored in order; each pushed to
master (free) / main (pro) as QA docs — no PR, no code changes._
