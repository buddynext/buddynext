# BuddyNext — Master Architecture

**Status:** Locked
**Last updated:** 2026-03-19

---

## Core Principle

> **Minimal by default, extensible by design.**
> Ship the essential feature. Cover it with hooks. Let the ecosystem add layers.

---

## Stack Ownership

All plugins in this stack are first-party — nothing is publicly released yet. Bridges and deferral filters are designed for perfect compatibility. No legacy shims needed. DB schemas across all plugins can be updated freely to align with BuddyNext.

**No plugin in this stack processes payments.** Access gating is handled via the BuddyNext webhook API — any external system (WooCommerce, MemberPress, Zapier, Stripe, etc.) grants access by calling `buddynext/v1/webhook/access`. BuddyNext stores and checks the result via the Abilities API.

---

## Product Hierarchy

```
┌─────────────────────────────────────────────────────┐
│                    BuddyNext                        │
│                                                     │
│  Social Graph · Profiles · Activity Feed · Spaces   │
│  Member Directory · Search · Notifications · DM     │
│  Email System · Onboarding · Moderation             │
│                                                     │
│              THE SOCIAL LAYER                       │
└──────┬──────────────┬────────────────┬──────────────┘
       │              │                │
       ▼              ▼                ▼
┌──────────┐   ┌──────────┐   ┌──────────────┐   ┌─────────────┐
│WPMedia   │   │ Jetonomy │   │WBGamification│   │Career Board │
│Verse     │   │          │   │              │   │             │
│ MEDIA    │   │  FORUMS  │   │ GAMIFICATION │   │    JOBS     │
└──────────┘   └──────────┘   └──────────────┘   └─────────────┘
```

**BuddyNext** owns the social layer: who people are, who they follow, what they post, where they gather, how they're notified, how they communicate.

**Addons** own their domain: WPMediaVerse owns media, Jetonomy owns discussions, WBGamification owns points/badges, Career Board owns jobs.

---

## Two Modes Per Addon

### Standalone Mode (BuddyNext not active)
The addon runs its own social layer — own follow graph, own notifications, own activity feed. Full feature set for its domain. No BuddyNext required.

### BuddyNext Mode (BuddyNext active)
The addon defers its social layer to BuddyNext. Social features are handled once, consistently. The addon contributes its content into BuddyNext surfaces (feed, notifications, search).

---

## Mode Detection

Each addon checks at boot whether BuddyNext is active (`defined('BUDDYNEXT_VERSION')`). BuddyNext's bridge classes set deferral flags on each addon during initialization.

---

## Addon Deferral Map

### WPMediaVerse

| Capability | Standalone | BuddyNext Mode |
|-----------|-----------|----------------|
| Follow graph | Own `mvs_follows` | Defers → `bn_follows` |
| Notifications | Own bell + `mvs_notifications` | Defers → `bn_notifications` |
| Activity feed | Own `mvs_activity` | Pushes to `bn_posts` (type: media) |
| Profile avatar/cover | Own UI | Defers → BuddyNext profile system |
| REST follow endpoints | `mvs/v1/follows` active | Disabled / redirects to `buddynext/v1` |
| **Always owns** | Media upload, storage, playback, AI moderation, watermarking, chapters, captions, transcoding, quota | |

### Jetonomy

| Capability | Standalone | BuddyNext Mode |
|-----------|-----------|----------------|
| Notifications | Own bell + notification store | Defers → `bn_notifications` |
| Activity feed items | Own feed | Pushes to `bn_posts` (type: discussion) |
| Member profiles | Own profile data | Defers → BuddyNext profiles |
| **Always owns** | Discussion engine, forum structure, spaces-as-forums, voting, topic management, moderation | |

### WBGamification

| Capability | Standalone | BuddyNext Mode |
|-----------|-----------|----------------|
| Event sources | WordPress + BuddyPress actions | Extends → also listens to `buddynext_*` actions |
| Leaderboard display | Own pages/widgets | Surfaces inside BuddyNext directory + profiles |
| Notifications | Own notification | Defers → `bn_notifications` for gamification events |
| **Always owns** | Points, badges, levels, leaderboards, challenges, streaks, rules engine | |

### Career Board

| Capability | Standalone | BuddyNext Mode |
|-----------|-----------|----------------|
| Job post feed | Own listing pages | Job cards appear in `bn_posts` feed |
| Employer profiles | Own pages | Links to BuddyNext profiles |
| Notifications | Own | Defers → `bn_notifications` |
| **Always owns** | Job management, applications, employer/candidate management | |

---

## Bridge Classes

BuddyNext ships one bridge per addon. Each bridge:
1. Sets deferral flags on the addon
2. Listens to addon actions and normalizes into BuddyNext
3. Injects BuddyNext content/UI into addon surfaces

Location: `buddynext/includes/Bridges/`

Each bridge loads only when its addon is active (checked via version constant).

---

## Data Flow in BuddyNext Mode

### Follow action
```
User clicks Follow
    → BuddyNext FollowService
        → writes bn_follows
        → fires buddynext_user_followed
            → WBGamificationBridge → awards points
            → WPMediaVerseBridge → (loop-safe, no sync needed — MVS follow off)
```

### Media uploaded
```
User uploads media
    → WPMediaVerse UploadService
        → fires mvs_media_uploaded
            → WPMediaVerseBridge
                → creates bn_posts entry (type: media)
                → creates bn_search_index entry
```

### Discussion created
```
User creates discussion
    → Jetonomy discussion engine
        → fires jetonomy_after_create_post
            → JetonomyBridge
                → creates bn_posts entry (type: discussion) [if feed sync enabled]
                → creates bn_search_index entry [always]
```

### Notification flow
```
Any plugin fires action (follow, reaction, reply, badge earned)
    → BuddyNext NotificationService
        → writes bn_notifications
            → On-site bell updated
            → Email queued via Action Scheduler
```

---

## Bootstrap Order

```
plugins_loaded:10  → WPMediaVerse::init()        (standalone mode by default)
plugins_loaded:10  → Jetonomy::instance()         (standalone mode by default)
plugins_loaded:10  → WBGamification::boot()       (standalone mode by default)
plugins_loaded:10  → WPCareerBoard::init()         (standalone mode by default)
plugins_loaded:15  → BuddyNext\Core\Plugin::init()
                       → fires buddynext_loaded
plugins_loaded:20  → BuddyNext Pro hooks in
plugins_loaded:25  → BuddyNext bridges initialize
                       (WPMediaVerseBridge, JetonomyBridge, WBGamificationBridge, CareerBoardBridge)
                       → set deferral flags on each addon
```

Addons must initialize first so BuddyNext can inspect their state and set deferral flags before any `init` hooks fire.

---

## REST API Namespaces

| Namespace | Plugin | Active when |
|-----------|--------|------------|
| `buddynext/v1` | BuddyNext free | BuddyNext active |
| `buddynext-pro/v1` | BuddyNext Pro | Pro active |
| `mvs/v1` | WPMediaVerse | Always (media endpoints never defer) |
| `mvs-pro/v1` | WPMediaVerse Pro | Always |
| `jetonomy/v1` | Jetonomy | Always (discussion endpoints never defer) |

Social endpoints on `mvs/v1` and `jetonomy/v1` return redirects to `buddynext/v1` equivalents when in BuddyNext mode, or are disabled.

**Full REST API from day 1** — every feature is API-first. All surfaces (feed, spaces, profiles, notifications, search, directory) are accessible via `buddynext/v1`. No feature ships without a REST endpoint.

**Webhook system (bidirectional):**
- Inbound: `POST buddynext/v1/webhook/access` — external systems grant/revoke user access
- Outbound: admin registers external URLs, BuddyNext pushes signed events on any action (member registered, post created, space joined, etc.). Works natively with Zapier and Make — no custom app needed.

---

## WordPress Abilities API (WP 6.9+)

BuddyNext registers all social abilities. Addons register their domain abilities. No conflicts — different namespaces.

```
buddynext-profile.*     BuddyNext
buddynext-connections.* BuddyNext
buddynext-feed.*        BuddyNext
buddynext-spaces.*      BuddyNext
buddynext-messaging.*   BuddyNext
mvs-media.*             WPMediaVerse
jetonomy-discussions.*  Jetonomy
wb-gam-points.*         WBGamification
```

---

## Upgrade Path (Standalone → BuddyNext)

When a site running WPMediaVerse or Jetonomy standalone installs BuddyNext:

1. BuddyNext activator detects existing addon data
2. Migration wizard offers to import:
   - `mvs_follows` → `bn_follows`
   - `mvs_notifications` → `bn_notifications`
   - `mvs_activity` → `bn_posts`
   - Jetonomy member data → BuddyNext profiles
3. After migration, addon switches to BuddyNext mode
4. Old standalone tables kept (not deleted) for safety, flagged as archived

---

## Admin Layer File Organization

**Last updated:** 2026-03-22

Every admin page follows the thin-controller + subdirectory pattern. No single admin file grows beyond ~400 lines.

```
includes/Admin/
├── AdminPageBase.php                    ← abstract base: shared chrome, tab bar, section cards
├── {PageName}.php                       ← thin controller: menu registration, routing, list queries
├── {PageName}/
│   ├── {Feature}Manager.php             ← form submission handlers + tab renderer for one domain
│   ├── {PageName}EditForm.php           ← form rendering + inline rendering helpers
│   └── {PageName}Export.php             ← export handler (if the page has one)
└── Helpers/
    └── {Domain}Display.php              ← static display utilities — reusable across admin pages
```

**Namespace convention**

| Path | Namespace |
|------|-----------|
| `includes/Admin/{PageName}.php` | `BuddyNext\Admin` |
| `includes/Admin/{PageName}/*.php` | `BuddyNext\Admin\{PageName}` |
| `includes/Admin/Helpers/*.php` | `BuddyNext\Admin\Helpers` |

**Boot wiring rule:** The thin controller's `register()` instantiates its sub-handlers and calls their `register()`. Sub-handlers never self-register — the parent class owns the hook registration sequence.

**Current state (2026-03-22)**

```
includes/Admin/
├── Members.php                          ← thin controller (query + suspend/unsuspend + save profile)
├── Members/
│   ├── ProfileFieldsManager.php         ← 14 profile field CRUD handlers + profile fields tab
│   ├── MemberEditForm.php               ← edit-member form renderer + repeater UI
│   └── MemberExport.php                 ← CSV export
└── Helpers/
    └── MemberDisplay.php                ← get_initials, get_avatar_color, render_role_badge, human_time_diff_short
```

Apply the same pattern to Spaces.php and NavManager.php when those admin pages are implemented.

---

## Summary

| Question | Answer |
|----------|--------|
| Who owns the social graph? | BuddyNext always |
| Who owns media? | WPMediaVerse always |
| Who owns discussions/forums? | Jetonomy always |
| Who owns points/badges? | WBGamification always |
| Who owns the notification bell? | BuddyNext when active, each addon standalone otherwise |
| Can I use WPMediaVerse without BuddyNext? | Yes — fully standalone |
| Can I use Jetonomy without BuddyNext? | Yes — fully standalone |
| Can I use BuddyNext without WPMediaVerse? | Yes — no media in feed, everything else works |
| Can I use BuddyNext without Jetonomy? | Yes — no discussions, everything else works |
