# BuddyNext — Refactor-to-Complete Master Plan

_The charter for finishing BuddyNext as a general-purpose community platform.
Written 2026-05-31. Supersedes ad-hoc gap-patching. Pairs with
`docs/feature-audit.md` (current reality) and `docs/journeys/` (intent)._

## Positioning

BuddyNext is a **general-purpose community platform**, built for two demanding
consumers at once:

1. **In-app communities / app-support backends** — a mobile or web app talks to
   BuddyNext over REST. Every member-facing capability must therefore be
   fully usable through the API, not just the server-rendered templates.
2. **Large communities** — tens of thousands of members and posts. Every read
   path must be indexed, cursor-paginated, and cacheable; every heavy write
   must be async.

It is **not** site-specific. We design for what *any* niche community owner
needs, and let owners specialize through configuration and native extension
points — never through bespoke code we have to maintain.

## Doctrine (non-negotiable)

1. **Refactor, never patch.** When a model is wrong, rewrite that section from
   scratch. No stacking override rules on a broken foundation.
2. **Complete-per-feature.** Build the full skeleton — every type, state,
   empty/error case, admin side *and* member side, plus REST — so the feature
   is finished and we do not revisit it.
3. **Verify before advancing.** Exactly one change enters the live site at a
   time → seed real data → walk the flow light + dark → REST-check → confirm
   console + PHP error log are clean → commit. The serialization is the safety.
4. **Organized around shared contracts, not one-offs.** Features plug into the
   cross-cutting engines below. But do **not** invent a new abstraction for a
   marginal win (the consolidation bloat test) — codify the good patterns that
   already exist and apply them consistently.
5. **App-first.** Every member-facing capability exposes a complete, versioned
   REST surface (`buddynext/v1`) with a consistent envelope, auth, and cursor
   pagination.
6. **Scale-first.** Indexed queries, keyset/cursor pagination, async heavy work
   via Action Scheduler, `wp_cache` on hot reads.
7. **UX is part of the feature.** OKLCH `--bn-*` tokens, Lucide via
   `buddynext_icon()`, working dark mode, V2 mockups are authoritative. No emoji
   in markup. No "polish later."
8. **No version bumps** (pre-release). **No Claude co-author** on commits.

## The shared contracts (the "organized" backbone)

Every feature composes from these. Where one already exists and is good, it is
the model; we extend the pattern rather than reinventing per feature.

| Contract | Single source of truth | Status / model |
|---|---|---|
| **Type/registry engines** | one registry per domain, extensible via native filters | `Profile/FieldType` ✓ is the template — reaction types, post types, notification types, member types must follow it |
| **Visibility & privacy** | one resolver, most-restrictive-wins (`public/followers/connections/space/private`) | applied uniformly across feed, profile, directory, search — no per-feature copies |
| **Moderation & safeguard** | one report → queue → action → audit pipeline | every content type registers with it (posts, comments, spaces, profiles) |
| **Notifications, email & push** | one channel model + template registry + preference catalogue | every event emits through it; templates carry their own subject/body |
| **REST / app contract** | `buddynext/v1`, consistent error envelope + cursor pagination + auth | every feature exposes its full CRUD + list here; the app is a first-class client |
| **Extensibility seams** | native `apply_filters`/`do_action` at the site | Pro composes through these; no Free-side wrapper helpers |
| **Design system** | `bn-*` OKLCH tokens, `IconService` (Lucide), dark mode | V2 mockups in `docs/v2 Plans/v2/` win where templates diverge |
| **Async + cache** | Action Scheduler for indexing/fan-out; keyset pagination; `wp_cache` hot reads | search index, hashtag index, feed fan-out |
| **Gamification** | **integration-only** — `wb-gamification` owns all points/badges/streaks/leaderboards | BN does **not** build or store any gamification data. BN's only job is to **emit clean, hookable events** through the seam `wb-gamification` actually ingests, and to render leaderboards via that plugin's API — never its own tables. |

A feature is only "organized" when it reuses these, not when it reimplements
them. Audit-confirmed breaks in a *contract* (e.g. the gamification event
contract, an inconsistent REST envelope, a visibility path that bypasses the
resolver) are fixed **before** the features that ride on them.

## The pipeline — master + phased subagents

**Master = the main orchestration loop.** Owns the queue, the verification gate,
and commits. Subagents fan out for the parallelizable parts — spec design,
investigation, repro, and *disjoint-file* implementation. **Fixing and live
verification are serial** (one Local site, one DB, one browser); that is what
keeps incremental work from breaking things.

Per feature:

1. **SPEC** _(parallel design agent)_ → a target spec under the backbone:
   complete + generic, admin + member + REST, scale + extensibility, reconciling
   the journey doc (intent) with the audit (reality). Like the member-fields
   YAML spec.
2. **REFACTOR** _(master + implementer)_ → build to spec, in place, refactor not
   patch.
3. **VERIFY** _(master, serial)_ → seed → walk light+dark → REST check → error
   log clean.
4. **COMMIT** → one verified unit.

Loop until the feature is **usable for a site owner *and* consumable by an app**.

## Phase order (spine → foundational → outward)

0. **Backbone hardening.** Make the shared contracts solid first — fixing
   features on a wrong spine is rework. Includes audit-confirmed contract breaks:
   - **Gamification seam** — fix BN's *emit* side so events land in the seam
     `wb-gamification` actually listens on (it ingests via
     `wb_gamification_register_action()` / its registered hooks, **not** the
     `wb_gamification_event` action BN currently fires), and render any
     leaderboard through the plugin's API. Delete BN's direct `wbg_*` table
     queries. BN ships **zero** gamification logic of its own.
   - REST envelope consistency, visibility-resolver coverage, and the
     notification/email template model (templates must carry their own
     subject/body).
1. **Foundational flows.** Profiles + fields (finish), Member Directory, Social
   Graph (follows/connections/blocks), Feed + posts/composer, Spaces.
2. **Engagement.** Reactions (incl. custom), Comments, Polls, Shares, Bookmarks,
   Hashtags.
3. **Trust & ops.** Moderation (Free + Pro rules/bulk/auto), Privacy,
   Notifications + Email + Push.
4. **Growth & monetization.** Onboarding, Membership + Gated Spaces, Stripe
   checkout, Broadcasts + Drip. _(Sits after notifications/email are solid — we
   don't build checkout on a broken email path.)_
5. **Platform.** Search (incl. advanced filters + saved search), Realtime, PWA,
   Outbound/Unlimited Webhooks, White Label, AI, Analytics.
6. **Long tail.** Remaining highs/mediums, grouped by flow.

## Done = usable (the close criterion)

A feature is closed — and not revisited — only when:

- a site owner can configure it in wp-admin,
- a member can complete its core flow in the browser (seeded data, light + dark),
- an app can drive the same flow over REST,
- no console errors and no PHP error-log entries,
- it matches the V2 mockup and the backbone contracts.

Until all five hold, the feature stays open.
