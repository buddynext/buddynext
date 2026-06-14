# BuddyNext Pro — Packaging & Integration Lock (for the June 20 release)

**Status:** Lock document. Decides what is Free / Pro / Business and what ships vs. is roadmap. Locked 2026-06-14 for the 2026-06-20 release.

**Strategic thesis:** BuddyNext is the *mother of all* — the last plugin of the Wbcom family, released last because it is the **nervous system** that turns a set of already-live, best-of-breed plugins into one product. BuddyNext **Pro is the integration hub**: the layer where the whole suite becomes one AI-powered, monetizable social community. That is the moat — and it is the answer to "why Pro."

**Architecture (locked): Free = community OS + core integrations · Pro = application layer.**
- **Free** = the platform/OS + the integrations that make a community *usable and engaging*: social graph, feed, spaces, profiles, moderation, notifications, 100% REST, AI-ready ability surface — **plus 3 core integrations: WPMediaVerse (media upload + DM/messages), Jetonomy (forums/Q&A), WB Gamification (points/badges/engagement)**, and the BuddyX theme. These are mandatory floor — a community without media, messaging, discussion, and engagement is a teaser, not a community.
- **Pro** = the *application layer* on top — the integrations that turn the community into a **business**: Career Board (jobs), Learnomy (courses), WB Listora (directory), Eventonomy (events), WPConnectPress (video), MediaShield, WP Sell Services, ProjectFlow — plus monetization rails + shared credits wallet + AI/agent infra + operator tools.
- **Tier-aware core integrations:** the 3 core integrations bridge to the sibling's *Free* tier for baseline, and **light up the sibling's Pro features automatically when its Pro version is present** (WPMediaVerse Pro = richer media/DM, Jetonomy Pro = richer forums/Q&A, WB Gamification Pro = richer gamification). The bridge detects tier; it does not gate the sibling's own upsell.
- **Bridge consequence:** the 3 core bridges (`WPMediaVerseBridge`, `JetonomyBridge`, `GamificationBridge` + listeners) and `BuddyXBridge` **stay in Free**. Only **`CareerBoardBridge` moves to Pro** (jobs = a business app); all *new* integration bridges (Learnomy, Listora, Eventonomy, WPConnectPress, …) are built **in Pro**. So "the application-layer move" is a one-bridge change for launch — Free exposes events/REST/abilities; Pro consumes them (`plugins_loaded:20`).

## Clarity — the anti-confusion rules (most important for launch)

The suite is powerful but rich; a confused buyer doesn't buy and a confused member churns. Resolve it with two mental models and a hard rule set.

**Integration shape (corrected 2026-06-14 — community layer on top, NOT takeover):** BuddyNext adds a **community layer on top of** each standalone plugin. It does **not** replace, rebuild, or take over a plugin's screens; the plugin keeps its own pages and core features, untouched.

- **Application-layer business apps (Tier 2 — the default): Career Board, Learnomy, Eventonomy, Listora, WPConnectPress.** Integration = **profile + activity (+ possible space)** touchpoints that drive engagement, plus notifications. BN surfaces the member's partner content on their profile and posts a BN activity when they create it — **linking out to the partner's own pages.** Never take over their screens; never touch their core. Wiring lives in a Pro bridge (`includes/Bridges/`).
- **Core community primitives (Tier 1 — rare exception): WPMediaVerse messaging.** A deliberate BN-native rebuild, justified ONLY because a member seeing **two message inboxes** is confusing. Proven/done (`includes/Messages/` + native `dm-*`). A business app never qualifies. The HOW lives in `plan/native-integration-program.md` (Tier-1 only).

Canonical law: `plan/WORKFLOW.yaml` → integration_law. Per-integration plans: `plan/integrations/<n>-<name>.md`. Member mental model stays "one community, many sections," but the sections are powered by best-of-breed apps the member still visits — BN is the connective community tissue, not a re-skin of every plugin.

**Member mental model — "one community, many sections":** members never see "13 plugins." They see one community whose **profiles, feed, and notifications connect to** the best-of-breed apps — their posted jobs show on their profile, posting a course shows in the feed, an application pings their notifications. Core community primitives (messaging) are BN-native; business-app sections are the apps' own pages, stitched into the community by BuddyNext. Enforced by:
- One unified navigation (sections, not plugins).
- One identity / SSO (shared WP users — already true).
- **One notification center** — BuddyNext aggregates events from every bridge (the refactored notification system already does this via per-domain listeners).
- One design language (BuddyNext tokens / BuddyX).
- Contextual, honest upgrade prompts ("Courses — available in Pro"), never dead ends.

**Buyer mental model — "lead with outcomes, hide the plumbing":**
- **Free** = *Your community* (feed, profiles, spaces, messages, forums, gamification).
- **Pro** = *Your community as a business* (courses, events, jobs, directory, video + monetization + AI).
- **Bundle / all-access** = *Everything* — the **default recommendation** and the single biggest confusion-killer.
- Sell the BuddyNext tier + the bundle. Per-app Pro upgrades are power-user options, **never the headline**.

## Monetization axes (no cannibalization)

Three independent upgrade paths, plus a bundle:
1. **Per-app Pro** — WPMediaVerse Pro, Jetonomy Pro, Gamification Pro, Career Board Pro, Learnomy Pro, Listora Pro, … each app deepens on its own. BuddyNext surfaces the Pro features through the (tier-aware) bridge.
2. **BuddyNext Pro (application layer)** — the business-app integrations (Career Board, Learnomy, Listora, Eventonomy, WPConnectPress) + monetization rails + shared credits wallet + AI/agent infra + operator tools.
3. **Suite bundle / all-access** — everything together (the highest-ARPU SKU; ties to `wbcom-credits-sdk` for one wallet).

A customer can grow along any axis: deepen one app, unlock the platform layer, or go all-in. Free stays a complete, engaging community throughout.

**Governing principle — raise the ceiling, don't lower the floor.** Free is a *complete* community (the refactor delivered this: 100% REST, thin controllers, services own the logic, every flow fully functional). Pro earns its price by being *ahead* — integrations, money rails, AI — never by withholding basics. A complete Free grows the funnel; Pro converts the serious.

**Why this beats the SaaS field (Circle, Mighty, Skool, Bettermode, Discourse):** they are *monolithic* — one app does courses + events + community, mediocre at each. We are *federated best-of-breed* — a real LMS, event platform, video, forums, jobs, gamification, DM/media — each deep on its own, **unified by BuddyNext Pro.** No SaaS can be best-of-breed at six things.

---

## The suite ↔ the proven paid gates

*(Versions/status are authoritative from the wbcom-crm product catalog, 2026-06-14.)*

Every capability SaaS community tools gate behind paid, we deliver as a *dedicated live product*, fused by BuddyNext Pro:

| SaaS gates this | Our product | Catalog status |
|---|---|---|
| Courses / monetized learning | **Learnomy** (Simple LMS) | free v1.0.0 live · pro built, unreleased |
| Directory / business listing | **WB Listora** ("Litora") | free+pro v1.0.0 live |
| Forums / Q&A | **Jetonomy** | free+pro v1.4.2 live |
| Jobs / opportunity | **WP Career Board** | free+pro v1.0.2 live |
| Gamification (Skool's whole hook) | **WB Gamification** | free v1.5.0 · pro v1.0.0 live |
| DM / media | **WPMediaVerse** | free+pro v1.5.0 live |
| Media protection / DRM | **MediaShield** | free+pro v1.0.0 live (pairs w/ WPMediaVerse) |
| Sell services / freelance | **WP Sell Services** | free+pro v1.0.0 live |
| Project management | **ProjectFlow** | v1.0.0 live |
| Events / tickets | **Eventonomy** | coming (not yet in catalog) |
| Live / video rooms | **WPConnectPress** (Zoom + Google Meet) | coming (not yet in catalog) |

**Shared monetization primitive:** `wbcom-credits-sdk` — a family-wide credits / virtual-currency SDK. This is the unifier for in-app economy across the suite (earn/spend across community, courses, events, listings) and the cleanest path to **one wallet, one paywall** in BuddyNext Pro.

**Theming layer (presentation):** **BuddyX** (free v5.0.3 + **BuddyX Pro** v5.0.6) is the dedicated community theme; **Reign** is the premium community theme; **KnowX** is the knowledge-base theme. BuddyNext ships theme-agnostic (its own design tokens) but is first-class on BuddyX/Reign.

---

## Integration lock — what's Pro, and what ships June 20

Every suite integration lives in **Pro**. Bridge status determines ship-now vs. roadmap:

| Integration | Plugin | BN bridge | Lock as |
|---|---|---|---|
| Forums / Q&A | Jetonomy | ✅ `JetonomyBridge` | **Pro — ships June 20** |
| Jobs | WP Career Board | ✅ `CareerBoardBridge` | **Pro — ships June 20** |
| Gamification | WB Gamification | ✅ `GamificationBridge` | **Pro — ships June 20** |
| DM / media | WPMediaVerse | ✅ `WPMediaVerseBridge` | **Pro — ships June 20** |
| Theme | BuddyX | ✅ `BuddyXBridge` | Free / theme |
| **Courses** | **Learnomy** | ❌ missing | **Pro — declare; build #1** |
| **Directory / listings** | **WB Listora** | ❌ missing | **Pro — declare; build #2** |
| **Events** | **Eventonomy** | ❌ missing (plugin coming) | **Pro — declare; build #3** |
| **Video** | **WPConnectPress** | ❌ missing (plugin coming) | **Pro — declare; build #4** |
| Media protection | MediaShield | n/a (extends WPMediaVerse) | Pro — via WPMediaVerse |
| Sell services | WP Sell Services | ❌ missing | Pro — later |
| Project management | ProjectFlow | ❌ missing | Pro — later (if community-relevant) |

Existing bridges are wired (`includes/Bridges/`): Jetonomy, CareerBoard, Gamification, WPMediaVerse, BuddyX. Playbook for new bridges: `plan/native-integration-program.md` (API-level only, BN-native UI, no 2nd-party screens, uniform asset-isolated pages). Post-launch build order is revenue-first: **Learnomy (sell courses) → WB Listora (paid listings) → Eventonomy (tickets) → WPConnectPress (live).**

---

## The three-tier structure (locked)

The market's two highest-ASP gates are the **branded app** and **AI** — both belong at a top tier. So: 3 tiers, none cutting basics.

### Free — *a complete community*
Feed, spaces, profiles, social graph (follow/connect/block/mute), comments, reactions, basic moderation, notifications, member directory, search (basic). **100% REST + the AI-ready ability surface.** The floor stays whole.

### Pro — *make money + run it with intelligence*
- **Suite integrations** (the moat): Jetonomy, Career Board, Gamification, WPMediaVerse now; Learnomy, Eventonomy, WPConnectPress next.
- **Money rails:** membership tiers, subscriptions, Stripe, paywalls, gated spaces (+ unified billing across the suite).
- **AI:** ranked feed, AI moderation, AI replies, semantic search (→ the agent/abilities frontier).
- **Automation:** email broadcasts, drip sequences, segmentation.
- **Analytics:** funnels, cohorts, profile-view analytics.
- **Reach:** push (FCM), realtime (Soketi).
- **Power versions** of Free features (see "ceiling not floor").

### Business / Agency — *your own branded AI-powered network*
- **Branded native app** (the #1 SaaS upsell — belongs here).
- Full **white-label**, custom domain.
- **SSO**, advanced roles/permissions, audit log.
- API keys / member CRM, multi-community.
- **Live/video rooms** (WPConnectPress) at scale.

---

## Ceiling, not floor — the basic-vs-power splits

Borderline features are split, not withheld:

| Capability | Free (basic) | Pro (power) |
|---|---|---|
| Profile fields | text / select | location / file / conditional / advanced types |
| Reactions | standard set | custom emoji reactions |
| Search | basic | saved searches + advanced filters + semantic |
| Feed | chronological / for-you | AI-ranked |
| Moderation | report + manual queue | rules engine + bulk + AI moderation |
| Notifications | in-app + email | push + digests + quiet-hours/fatigue (AI) |

Free keeps the capability; Pro is the powered-up version. Nobody feels robbed; upgraders get a real step up.

---

## The futuristic ceiling — AI-native, agent-operable (the frontier)

The forward bet that no SaaS competitor can match at suite scale: **expose the whole family as a WordPress Abilities API surface** (`wp-abilities/v1`, thin wrappers over the now-clean services), so AI agents — Claude, the WP AI features, MCP clients, and the native app's assistant — can *read and act across the entire suite*: post, reply, react, enroll in a course, register for an event, join a meeting, answer a question, apply to a job, award points.

- **Free = AI-ready** (the ability surface; read + basic act). Drives adoption + positioning.
- **Pro = AI-driven** (the agent runtime: AI personas that keep communities alive, auto-engagement, content seeding, grounding, governance + attribution + approval).

This is the answer to "why Pro" in one line: *Free lets you build a community; Pro is the AI that keeps it alive and the integrations that make it your whole platform — at any scale, even while you sleep.* (Needs WP 6.9+ for core Abilities API, else the feature-plugin/package — pin the min-WP target.)

---

## June 20 lock checklist (commercial architecture, not new features)

1. **Tier matrix locked** (above) — the source of truth for Free / Pro / Business.
2. **Gating wired** — every already-built Pro feature correctly fenced; every *future* Pro item shows as "coming in Pro," never "missing."
3. **Integration story locked** — 4 bridges ship (Jetonomy, Career Board, Gamification, WPMediaVerse); 3 declared as Pro roadmap (Learnomy, Eventonomy, WPConnectPress).
4. **Positioning locked** — "Free = complete community · Pro = make money + AI + your suite · Business = your branded AI network."
5. **No basics cut** — verify Free still ships every basic capability (the refactor guarantees this).

## Post-launch build order (revenue-first)

1. **LearnomyBridge** — sell courses inside the community.
2. **EventonomyBridge** — sell event tickets.
3. **WPConnectPressBridge** — live Zoom/Meet rooms.
4. **AI/abilities frontier** — ability registry across the suite → Pro agent runtime.
5. **Gamification depth** (cross-suite points), **payouts/tipping**, **Business-tier** app + SSO.
