# BuddyNext Code Organization — Architecture Design

**Date:** 2026-03-24
**Status:** Approved
**Type:** Structural refactor (no behaviour change)

---

## Problem

The plugin has 90+ PHP files across 20+ domains but its internal layout is partly horizontal:

| Symptom | Impact |
|---|---|
| `Core/EventListener.php` — 1287 lines, 43 hooks | One file owns all cross-domain side effects; any feature change requires reading 1287 lines to find the right hook |
| `REST/Controllers/` — 21 controllers in one flat folder | To understand "how does a follow work end-to-end?" requires jumping Feed → SocialGraph → REST/Controllers — three folders |
| `Plugin.php` — 467 lines mixing DI binding with hook wiring | Bootstrapper doubles as event bus; hard to know which part of Plugin.php to touch for a given feature |
| No `Contracts/` layer | No interfaces; services cannot be swapped or tested in isolation |
| `Moderation/EventListener.php` naming conflict | Two files named EventListener in different folders; confusing |

---

## Design: Option C — Hybrid (vertical domains + horizontal Core)

### Principle

Every feature domain owns its full stack: service, controller, listener. Infrastructure (`Core/`) stays horizontal. Admin stays horizontal.

### Rule

> "Where do I find the notifications code?" → `includes/Notifications/`
> Everything relevant: service, REST controller, listener, email sender.

---

## Target `includes/` Structure

```
Core/               ← infrastructure (unchanged)
  Plugin.php        ← slimmed to ~100 lines: DI bindings + listener bootstrap only
  Container.php
  Installer.php
  CacheService.php
  PageRouter.php
  PageSetup.php
  IconService.php
  TemplateLoader.php

Contracts/          ← NEW: interfaces only
  ListenerInterface.php

Feed/               ← PostService, FeedService, ShareService, BookmarkService, PollService
  + PostController.php       (moved from REST/Controllers/)
  + FeedController.php       (moved from REST/Controllers/)
  + PollController.php       (moved from REST/Controllers/)
  + BookmarkController.php   (moved from REST/Controllers/)
  + ShareController.php      (moved from REST/Controllers/)

SocialGraph/        ← FollowService, ConnectionService, BlockService, MuteService
  + FollowController.php     (moved from REST/Controllers/)
  + ConnectionController.php (moved from REST/Controllers/)
  + BlockController.php      (moved from REST/Controllers/)

Spaces/             ← SpaceService, SpaceMemberService
  + SpaceController.php        (moved from REST/Controllers/)
  + SpaceCategoryController.php (moved from REST/Controllers/)

Notifications/      ← NotificationService, NotificationPrefService, EmailService, EmailSender, VerificationService
  + NotificationController.php  (moved from REST/Controllers/)
  + NotificationListener.php    (NEW — replaces EmailDispatchListener for cross-domain hooks)
      Hooks owned: buddynext_user_followed, buddynext_connection_requested,
                   buddynext_connection_accepted, buddynext_reaction_added,
                   buddynext_comment_created, buddynext_post_shared,
                   buddynext_user_mentioned, buddynext_space_join_requested,
                   buddynext_space_join_approved, buddynext_space_member_invited,
                   buddynext_space_member_joined

Profile/            ← ProfileService
  + ProfileController.php    (moved from REST/Controllers/)

Reactions/          ← ReactionService
  + ReactionController.php   (moved from REST/Controllers/)

Comments/           ← CommentService
  + CommentController.php    (moved from REST/Controllers/)

Hashtags/           ← HashtagService, HashtagListener (existing)
  + HashtagController.php    (moved from REST/Controllers/)

Search/             ← SearchService, SearchIndexListener
  + SearchController.php     (moved from REST/Controllers/)

Moderation/         ← ModerationService, SafeguardService
  + ModerationController.php (moved from REST/Controllers/)
  + ModerationListener.php   (RENAMED from Moderation/EventListener.php + hooks from Core/EventListener.php)
      Hooks owned: buddynext_strike_issued, buddynext_member_suspended,
                   buddynext_user_suspended, buddynext_appeal_resolved,
                   buddynext_user_warned, buddynext_user_unsuspended,
                   buddynext_appeal_submitted, buddynext_user_shadow_banned,
                   buddynext_user_shadow_ban_removed, buddynext_daily_queue_check

Auth/               ← NEW domain folder
  + AuthController.php       (moved from REST/Controllers/)

Outbound/           ← NEW domain folder (outbound webhooks)
  + OutboundWebhookController.php (moved from REST/Controllers/)
  + AccessWebhookController.php   (moved from REST/Controllers/)
  + OutboundWebhookListener.php   (NEW — extracted from Core/EventListener.php)
      Hooks owned: user_register (on_webhook_member_registered),
                   buddynext_post_created, buddynext_post_deleted,
                   buddynext_space_member_joined (webhook), buddynext_space_member_left,
                   buddynext_connection_accepted (webhook), buddynext_user_followed (webhook),
                   buddynext_reaction_added (webhook), buddynext_comment_created (webhook),
                   buddynext_user_suspended (webhook), buddynext_user_unsuspended (webhook),
                   buddynext_ability_granted, buddynext_ability_revoked, buddynext_user_verified

Onboarding/         ← SetupWizard, OnboardingService, InviteService
  + OnboardingListener.php   (NEW — extracted from Core/EventListener.php)
      Hooks owned: user_register (on_user_register_schedule_nudges),
                   buddynext_onboarding_completed, bn_onboarding_nudge_24h,
                   bn_onboarding_nudge_72h

Bridges/            ← JetonomyBridge, GamificationBridge, CareerBoardBridge, WPMediaVerseBridge
  + JetonomyBridgeListener.php     (NEW — extracted from Core/EventListener.php)
      Hooks owned: jetonomy_after_create_reply
  + GamificationBridgeListener.php (NEW — extracted from Core/EventListener.php)
      Hooks owned: wb_gamification_badge_awarded, wb_gamification_level_changed

Admin/              ← unchanged (Settings, Members, Spaces, NavManager, etc.)
  + MemberTypeController.php (moved from REST/Controllers/)

Blocks/             ← unchanged
Theme/              ← unchanged
Messages/           ← unchanged
Gamification/       ← unchanged
```

---

## Contracts Layer

```php
// includes/Contracts/ListenerInterface.php
namespace BuddyNext\Contracts;

interface ListenerInterface {
    /**
     * Register all hooks for this domain listener.
     */
    public function register(): void;
}
```

Every `*Listener.php` class implements `ListenerInterface`.

---

## Plugin.php After

```php
public function init(): void {
    $this->register_services();
    $this->register_listeners();
    do_action( 'buddynext_loaded' );
}

private function register_listeners(): void {
    ( new Notifications\NotificationListener( $this->container ) )->register();
    ( new Moderation\ModerationListener( $this->container ) )->register();
    ( new Outbound\OutboundWebhookListener( $this->container ) )->register();
    ( new Onboarding\OnboardingListener( $this->container ) )->register();
    ( new Bridges\JetonomyBridgeListener() )->register();
    ( new Bridges\GamificationBridgeListener() )->register();
    ( new Hashtags\HashtagListener( $this->container ) )->register();
}
```

---

## REST/Router.php After

`REST/Router.php` stays as the central REST route registrar. It imports controllers from their new domain namespaces:

```php
use BuddyNext\Feed\PostController;
use BuddyNext\Feed\FeedController;
use BuddyNext\SocialGraph\FollowController;
// etc.
```

The `REST/Controllers/` subfolder is deleted. `REST/` contains only `Router.php`.

---

## Namespace Changes (complete mapping)

| Old namespace | New namespace |
|---|---|
| `BuddyNext\REST\Controllers\PostController` | `BuddyNext\Feed\PostController` |
| `BuddyNext\REST\Controllers\FeedController` | `BuddyNext\Feed\FeedController` |
| `BuddyNext\REST\Controllers\PollController` | `BuddyNext\Feed\PollController` |
| `BuddyNext\REST\Controllers\BookmarkController` | `BuddyNext\Feed\BookmarkController` |
| `BuddyNext\REST\Controllers\ShareController` | `BuddyNext\Feed\ShareController` |
| `BuddyNext\REST\Controllers\FollowController` | `BuddyNext\SocialGraph\FollowController` |
| `BuddyNext\REST\Controllers\ConnectionController` | `BuddyNext\SocialGraph\ConnectionController` |
| `BuddyNext\REST\Controllers\BlockController` | `BuddyNext\SocialGraph\BlockController` |
| `BuddyNext\REST\Controllers\SpaceController` | `BuddyNext\Spaces\SpaceController` |
| `BuddyNext\REST\Controllers\SpaceCategoryController` | `BuddyNext\Spaces\SpaceCategoryController` |
| `BuddyNext\REST\Controllers\NotificationController` | `BuddyNext\Notifications\NotificationController` |
| `BuddyNext\REST\Controllers\ProfileController` | `BuddyNext\Profile\ProfileController` |
| `BuddyNext\REST\Controllers\ReactionController` | `BuddyNext\Reactions\ReactionController` |
| `BuddyNext\REST\Controllers\CommentController` | `BuddyNext\Comments\CommentController` |
| `BuddyNext\REST\Controllers\HashtagController` | `BuddyNext\Hashtags\HashtagController` |
| `BuddyNext\REST\Controllers\SearchController` | `BuddyNext\Search\SearchController` |
| `BuddyNext\REST\Controllers\ModerationController` | `BuddyNext\Moderation\ModerationController` |
| `BuddyNext\REST\Controllers\AuthController` | `BuddyNext\Auth\AuthController` |
| `BuddyNext\REST\Controllers\OutboundWebhookController` | `BuddyNext\Outbound\OutboundWebhookController` |
| `BuddyNext\REST\Controllers\AccessWebhookController` | `BuddyNext\Outbound\AccessWebhookController` |
| `BuddyNext\REST\Controllers\MemberTypeController` | `BuddyNext\Admin\MemberTypeController` |
| `BuddyNext\Moderation\EventListener` | `BuddyNext\Moderation\ModerationListener` |

---

## Non-Breaking Guarantees

1. **No behaviour change** — every hook, REST route, and service stays identical. Only file locations and namespace declarations change.
2. **PSR-4 autoloader** — the `composer.json` maps `BuddyNext\\` to `includes/`. Moving files into domain subfolders is automatically handled. No composer.json changes needed.
3. **Sequential execution** — each domain is migrated and browser-verified before the next starts. No parallel file edits on `REST/Router.php` or `Plugin.php`.
4. **Core/EventListener.php is only deleted after every hook is extracted and verified.**
5. **REST/Router.php is only updated after all controller moves in a group are complete.**

---

## Migration Execution Sequence

```
Step 1  — Add Contracts/ListenerInterface.php (additive, zero risk)
Step 2  — Migrate Feed controllers (5 files + Router update)
Step 3  — Migrate SocialGraph controllers (3 files + Router update)
Step 4  — Migrate Spaces controllers (2 files + Router update)
Step 5  — Migrate Notifications controller + create NotificationListener (extract hooks from EventListener)
Step 6  — Migrate Profile + Reactions + Comments + Hashtags + Search controllers (5 files + Router update)
Step 7  — Migrate Moderation controller + rename Moderation/EventListener → ModerationListener
           + extract moderation hooks from Core/EventListener.php
Step 8  — Create Outbound/ domain (2 controllers + OutboundWebhookListener)
Step 9  — Create Auth/ domain (1 controller)
Step 10 — Create OnboardingListener + GamificationBridgeListener + JetonomyBridgeListener
Step 11 — Update Plugin.php register_listeners() + final Router.php cleanup
Step 12 — Delete Core/EventListener.php + delete REST/Controllers/ folder
Step 13 — Browser verify all features at http://forums.local
```

---

## Quality Gates (every step)

- `mcp__wpcs__wpcs_check_file` on every modified file → zero violations
- `php -l` on every new/moved file → no parse errors
- `mcp__wpcs__wpcs_phpstan_check` on modified domain folder → level 5 clean
- Browser verify the affected feature at `http://forums.local?autologin=1`

---

## Files Unchanged

- All service classes (`*Service.php`) — no movement, no namespace change
- All templates (`templates/`)
- All assets (`assets/`)
- All blocks (`blocks/`)
- All bridge classes (only listeners are added)
- `composer.json` — no changes (PSR-4 handles subnamespace resolution automatically)
- `buddynext.php` — no changes
