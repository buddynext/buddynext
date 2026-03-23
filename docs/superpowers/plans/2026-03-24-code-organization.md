# BuddyNext Code Organization — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate from a horizontal REST/Controllers + god-EventListener layout to a vertical domain-first layout where each feature folder owns its service, controller, and listener.

**Architecture:** Every domain in `includes/` gets its own `*Controller.php` (moved from `REST/Controllers/`) and `*Listener.php` (extracted from `Notifications/EventListener.php`). Infrastructure stays in `Core/`. Plugin.php's single `EventListener` boot is replaced by per-domain listener calls. REST/Router.php keeps the central route registration but imports controllers from their new domain namespaces.

**Tech Stack:** PHP 8.1+, Composer PSR-4, WPCS, PHPStan level 5

**Spec:** `docs/superpowers/specs/2026-03-24-code-organization-design.md`

---

## CRITICAL — Read Before Each Task

- **The god listener is `includes/Notifications/EventListener.php`** — class `BuddyNext\Notifications\EventListener`, 42 hooks, method `init()`. There is NO `Core/EventListener.php`.
- **There is NO `Moderation/EventListener.php`** — Task 6 creates `ModerationListener.php` from scratch by extracting moderation methods from `Notifications/EventListener.php`.
- **Existing listeners use `->init()`** — `HashtagListener`, `VerificationListener`, `EmailDispatchListener` all use `init()`. Do NOT call `->register()` on them. New listeners created in this plan use `->register()` (they implement `ListenerInterface`).
- This is a pure refactor. Zero behaviour change. Every hook and REST route stays identical.
- Run `php -l` on every file you create or modify.
- Run `mcp__wpcs__wpcs_check_file` on every file you create or modify. Zero violations before commit.
- `Notifications/EventListener.php` is only deleted in Task 10 after ALL 42 hooks are extracted.
- `REST/Controllers/` folder is only deleted in Task 10 after all 21 controllers are moved.
- Composer PSR-4 map (`BuddyNext\\ => includes/`) handles subnamespace resolution automatically. No composer.json changes needed.
- `SafeguardService` lives in `includes/Feed/SafeguardService.php` (namespace `BuddyNext\Feed`), not in Moderation — this is correct, do not move it.
- `OutboundWebhookService` lives in `includes/Core/` — it is NOT moved. Retrieve via `$container->get('webhooks')`.

---

## Current Plugin.php Listener Boot (lines 169–189)

The following lines in `Plugin::init()` are the existing listener wiring that Task 10 will replace/supplement:

```php
// Wire cross-plugin event hooks to notification routing.
( new EventListener() )->init();                              // ← REPLACED by register_listeners()

// Wire email verification hooks.
( new VerificationListener( $container->get( 'verification' ) ) )->init();  // ← unchanged

// Wire search index lifecycle hooks.
$container->get( 'search_index_listener' )->init();           // ← unchanged

// Wire hashtag extraction.
( new HashtagListener( $container->get( 'hashtags' ) ) )->init();  // ← unchanged, uses init()

// Wire outbound webhook dispatcher.
$container->get( 'webhooks' )->init();                        // ← unchanged (boots OutboundWebhookService retry logic)

// Wire email dispatch.
( new EmailDispatchListener( ... ) )->init();                 // ← unchanged
```

Task 10 replaces ONLY the `( new EventListener() )->init();` line with `$this->register_listeners()`.
All other listener boots remain exactly as they are.

---

## File Structure After Migration

```
includes/
  Auth/
    VerificationService.php      (currently in Notifications/ — NOT moved by this plan)
    VerificationListener.php     (currently in Auth/ — unchanged)
    AuthController.php           ← was REST/Controllers/AuthController.php
  Bridges/
    JetonomyBridge.php           (unchanged)
    GamificationBridge.php       (unchanged)
    CareerBoardBridge.php        (unchanged)
    WPMediaVerseBridge.php       (unchanged)
    JetonomyBridgeListener.php   ← NEW (Task 9)
    GamificationBridgeListener.php ← NEW (Task 9)
  Comments/
    CommentService.php           (unchanged)
    CommentController.php        ← was REST/Controllers/CommentController.php
  Contracts/
    ListenerInterface.php        ← NEW (Task 1)
  Core/
    Plugin.php                   ← modified Task 10 (register_listeners added, EventListener import removed)
    OutboundWebhookService.php   (unchanged — stays in Core/)
    + all other Core files unchanged
  Feed/
    PostService.php              (unchanged)
    FeedService.php              (unchanged)
    ShareService.php             (unchanged)
    BookmarkService.php          (unchanged)
    PollService.php              (unchanged)
    SafeguardService.php         (unchanged — already in Feed/)
    PostController.php           ← was REST/Controllers/PostController.php
    FeedController.php           ← was REST/Controllers/FeedController.php
    PollController.php           ← was REST/Controllers/PollController.php
    BookmarkController.php       ← was REST/Controllers/BookmarkController.php
    ShareController.php          ← was REST/Controllers/ShareController.php
  Hashtags/
    HashtagService.php           (unchanged)
    HashtagListener.php          (unchanged — uses init(), not register())
    HashtagController.php        ← was REST/Controllers/HashtagController.php
  Moderation/
    ModerationService.php        (unchanged)
    SafeguardService.php         NOTE: actually in Feed/ — do not move
    ModerationController.php     ← was REST/Controllers/ModerationController.php
    ModerationListener.php       ← NEW (Task 6 — created from scratch, methods extracted from Notifications/EventListener)
  Notifications/
    NotificationService.php      (unchanged)
    NotificationPrefService.php  (unchanged)
    EmailService.php             (unchanged)
    EmailSender.php              (unchanged)
    EmailDispatchListener.php    (unchanged — handles email dispatch, keeps init() pattern)
    VerificationService.php      (unchanged — may be here or in Auth/ per current code; don't move)
    NotificationController.php   ← was REST/Controllers/NotificationController.php
    NotificationListener.php     ← NEW (Task 5)
    EventListener.php            ← DELETED (Task 10 — after all 42 hooks extracted)
  Onboarding/
    SetupWizard.php              (unchanged)
    OnboardingService.php        (unchanged)
    InviteService.php            (unchanged)
    OnboardingListener.php       ← NEW (Task 8)
  Outbound/
    OutboundWebhookController.php ← was REST/Controllers/OutboundWebhookController.php
    AccessWebhookController.php   ← was REST/Controllers/AccessWebhookController.php
    OutboundWebhookListener.php   ← NEW (Task 7)
  Profile/
    ProfileService.php           (unchanged)
    ProfileController.php        ← was REST/Controllers/ProfileController.php
  Reactions/
    ReactionService.php          (unchanged)
    ReactionController.php       ← was REST/Controllers/ReactionController.php
  REST/
    Router.php                   ← modified (Tasks 2–4 — 21 use statements updated)
    Controllers/                 ← DELETED (Task 10 — empty after all controllers moved)
  Search/
    SearchService.php            (unchanged)
    SearchIndexListener.php      (unchanged)
    SearchController.php         ← was REST/Controllers/SearchController.php
  SocialGraph/
    FollowService.php            (unchanged)
    ConnectionService.php        (unchanged)
    BlockService.php             (unchanged)
    MuteService.php              (unchanged)
    FollowController.php         ← was REST/Controllers/FollowController.php
    ConnectionController.php     ← was REST/Controllers/ConnectionController.php
    BlockController.php          ← was REST/Controllers/BlockController.php
  Spaces/
    SpaceService.php             (unchanged)
    SpaceMemberService.php       (unchanged)
    SpaceController.php          ← was REST/Controllers/SpaceController.php
    SpaceCategoryController.php  ← was REST/Controllers/SpaceCategoryController.php
  Admin/
    Settings.php                 (unchanged)
    Members.php                  (unchanged)
    Spaces.php                   (unchanged)
    NavManager.php               (unchanged)
    IntegrationHub.php           (unchanged)
    EmailEditor.php              (unchanged)
    MemberTypeController.php     ← was REST/Controllers/MemberTypeController.php
```

---

## Namespace Change Reference (all 21 controllers)

| Controller | Old namespace | New namespace |
|---|---|---|
| PostController | `BuddyNext\REST\Controllers` | `BuddyNext\Feed` |
| FeedController | `BuddyNext\REST\Controllers` | `BuddyNext\Feed` |
| PollController | `BuddyNext\REST\Controllers` | `BuddyNext\Feed` |
| BookmarkController | `BuddyNext\REST\Controllers` | `BuddyNext\Feed` |
| ShareController | `BuddyNext\REST\Controllers` | `BuddyNext\Feed` |
| FollowController | `BuddyNext\REST\Controllers` | `BuddyNext\SocialGraph` |
| ConnectionController | `BuddyNext\REST\Controllers` | `BuddyNext\SocialGraph` |
| BlockController | `BuddyNext\REST\Controllers` | `BuddyNext\SocialGraph` |
| SpaceController | `BuddyNext\REST\Controllers` | `BuddyNext\Spaces` |
| SpaceCategoryController | `BuddyNext\REST\Controllers` | `BuddyNext\Spaces` |
| NotificationController | `BuddyNext\REST\Controllers` | `BuddyNext\Notifications` |
| ProfileController | `BuddyNext\REST\Controllers` | `BuddyNext\Profile` |
| ReactionController | `BuddyNext\REST\Controllers` | `BuddyNext\Reactions` |
| CommentController | `BuddyNext\REST\Controllers` | `BuddyNext\Comments` |
| HashtagController | `BuddyNext\REST\Controllers` | `BuddyNext\Hashtags` |
| SearchController | `BuddyNext\REST\Controllers` | `BuddyNext\Search` |
| ModerationController | `BuddyNext\REST\Controllers` | `BuddyNext\Moderation` |
| AuthController | `BuddyNext\REST\Controllers` | `BuddyNext\Auth` |
| OutboundWebhookController | `BuddyNext\REST\Controllers` | `BuddyNext\Outbound` |
| AccessWebhookController | `BuddyNext\REST\Controllers` | `BuddyNext\Outbound` |
| MemberTypeController | `BuddyNext\REST\Controllers` | `BuddyNext\Admin` |

---

## Listener Extraction Reference (42 hooks from `Notifications/EventListener.php`)

**NotificationListener** — `includes/Notifications/NotificationListener.php`
Extract these hook registrations + method bodies from `Notifications/EventListener.php`:
- `buddynext_user_followed` → `on_user_followed`
- `buddynext_space_member_joined` → `on_space_member_joined`
- `buddynext_connection_requested` → `on_connection_requested`
- `buddynext_connection_accepted` → `on_connection_accepted`
- `buddynext_reaction_added` → `on_reaction_added`
- `buddynext_comment_created` → `on_comment_created`
- `buddynext_post_shared` → `on_post_shared`
- `buddynext_user_mentioned` → `on_user_mentioned`
- `buddynext_space_join_requested` → `on_space_join_requested`
- `buddynext_space_join_approved` → `on_space_join_approved`
- `buddynext_space_member_invited` → `on_space_member_invited`

**ModerationListener** — `includes/Moderation/ModerationListener.php`
Extract these hook registrations + method bodies from `Notifications/EventListener.php`:
- `buddynext_strike_issued` → `on_strike_issued`
- `buddynext_member_suspended` → `on_member_suspended`
- `buddynext_user_suspended` → `on_user_suspended`
- `buddynext_appeal_resolved` → `on_appeal_resolved`
- `buddynext_user_warned` → `on_user_warned`
- `buddynext_user_unsuspended` → `on_user_unsuspended`
- `buddynext_appeal_submitted` → `on_appeal_submitted`
- `buddynext_user_shadow_banned` → `on_user_shadow_banned`
- `buddynext_user_shadow_ban_removed` → `on_user_shadow_ban_removed`
- `buddynext_daily_queue_check` → `on_daily_queue_check`

**OutboundWebhookListener** — `includes/Outbound/OutboundWebhookListener.php`
Extract these hook registrations + method bodies from `Notifications/EventListener.php`:
- `user_register` → `on_webhook_member_registered`
- `buddynext_post_created` → `on_webhook_post_created`
- `buddynext_post_deleted` → `on_webhook_post_deleted`
- `buddynext_space_member_joined` → `on_webhook_space_joined` (2nd registration of this action)
- `buddynext_space_member_left` → `on_webhook_space_left`
- `buddynext_connection_accepted` → `on_webhook_connection_accepted` (2nd registration)
- `buddynext_user_followed` → `on_webhook_user_followed` (2nd registration)
- `buddynext_reaction_added` → `on_webhook_reaction_added` (2nd registration)
- `buddynext_comment_created` → `on_webhook_comment_created` (2nd registration)
- `buddynext_user_suspended` → `on_webhook_user_suspended` (2nd registration)
- `buddynext_user_unsuspended` → `on_webhook_user_unsuspended` (2nd registration)
- `buddynext_ability_granted` → `on_webhook_ability_granted`
- `buddynext_ability_revoked` → `on_webhook_ability_revoked`
- `buddynext_user_verified` → `on_webhook_member_verified`
Note: Several actions (user_followed, comment_created, etc.) fire TWO callbacks — one for notification, one for webhook. Both must be registered in their respective listeners.

**OnboardingListener** — `includes/Onboarding/OnboardingListener.php`
Extract from `Notifications/EventListener.php`:
- `user_register` → `on_user_register_schedule_nudges` (this is a 3rd registration of user_register alongside VerificationListener's own registration — all three must survive)
- `buddynext_onboarding_completed` → `on_onboarding_completed_cancel_nudges`
- `bn_onboarding_nudge_24h` → `handle_onboarding_nudge`
- `bn_onboarding_nudge_72h` → `handle_onboarding_nudge`

**JetonomyBridgeListener** — `includes/Bridges/JetonomyBridgeListener.php`
Extract from `Notifications/EventListener.php`:
- `jetonomy_after_create_reply` → `on_jetonomy_reply`

**GamificationBridgeListener** — `includes/Bridges/GamificationBridgeListener.php`
Extract from `Notifications/EventListener.php`:
- `wb_gamification_badge_awarded` → `on_badge_awarded`
- `wb_gamification_level_changed` → `on_level_changed`

**Verification:** After Tasks 5–9 complete, run `grep -c "add_action\|add_filter" includes/Notifications/EventListener.php` — must return 0.

---

## Task 1: Contracts Layer

**Files:**
- Create: `includes/Contracts/ListenerInterface.php`

**Steps:**
- [ ] Create `includes/Contracts/ListenerInterface.php`:

```php
<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Listener interface.
 *
 * Every new domain listener must implement this contract.
 * Called during Plugin::register_listeners() bootstrap.
 *
 * Note: existing listeners (HashtagListener, VerificationListener,
 * EmailDispatchListener) use an init() method and are NOT required to
 * implement this interface — they pre-date it and are not being changed.
 *
 * @package BuddyNext\Contracts
 */

declare( strict_types=1 );

namespace BuddyNext\Contracts;

/**
 * Contract for new domain event listeners.
 */
interface ListenerInterface {

	/**
	 * Register all WordPress hooks for this domain listener.
	 *
	 * Called once during plugin bootstrap (plugins_loaded:15).
	 */
	public function register(): void;
}
```

- [ ] Run: `php -l includes/Contracts/ListenerInterface.php` — expect "No syntax errors"
- [ ] Run: `mcp__wpcs__wpcs_check_file({ file_path: "includes/Contracts/ListenerInterface.php" })` — expect zero violations
- [ ] Commit: `feat(contracts): add ListenerInterface — domain listener contract`

---

## Task 2: Controller Migration — Feed Domain (5 controllers)

**Files:**
- Create: `includes/Feed/PostController.php`
- Create: `includes/Feed/FeedController.php`
- Create: `includes/Feed/PollController.php`
- Create: `includes/Feed/BookmarkController.php`
- Create: `includes/Feed/ShareController.php`
- Modify: `includes/REST/Router.php` (5 use statements)
- Delete: `includes/REST/Controllers/PostController.php` (and other 4)

**Steps:**
- [ ] Read `includes/REST/Controllers/PostController.php` — copy entire file content
- [ ] Create `includes/Feed/PostController.php` — paste content, change `namespace BuddyNext\REST\Controllers;` to `namespace BuddyNext\Feed;`
- [ ] Repeat for FeedController, PollController, BookmarkController, ShareController (same: copy → new path → change namespace only)
- [ ] Run `php -l` on all 5 new files — expect "No syntax errors" on each
- [ ] Edit `includes/REST/Router.php` — replace these 5 `use` lines:
  ```php
  use BuddyNext\REST\Controllers\FeedController;
  use BuddyNext\REST\Controllers\PollController;
  use BuddyNext\REST\Controllers\PostController;
  use BuddyNext\REST\Controllers\BookmarkController;
  use BuddyNext\REST\Controllers\ShareController;
  ```
  with:
  ```php
  use BuddyNext\Feed\FeedController;
  use BuddyNext\Feed\PollController;
  use BuddyNext\Feed\PostController;
  use BuddyNext\Feed\BookmarkController;
  use BuddyNext\Feed\ShareController;
  ```
- [ ] Delete the 5 old files from `includes/REST/Controllers/`
- [ ] Run `mcp__wpcs__wpcs_check_file` on all 5 new files + Router.php — zero violations required
- [ ] Navigate browser to `http://forums.local?autologin=1` — confirm activity feed loads, no PHP errors
- [ ] Commit: `refactor(feed): move 5 Feed controllers to Feed\ domain namespace`

---

## Task 3: Controller Migration — SocialGraph + Spaces (5 controllers)

**Files:**
- Create: `includes/SocialGraph/FollowController.php`
- Create: `includes/SocialGraph/ConnectionController.php`
- Create: `includes/SocialGraph/BlockController.php`
- Create: `includes/Spaces/SpaceController.php`
- Create: `includes/Spaces/SpaceCategoryController.php`
- Modify: `includes/REST/Router.php` (5 use statements)
- Delete: the 5 old files from `includes/REST/Controllers/`

**Steps:**
- [ ] Read each source controller, create in domain folder with namespace updated:
  - `FollowController` → `includes/SocialGraph/` → `namespace BuddyNext\SocialGraph;`
  - `ConnectionController` → `includes/SocialGraph/` → `namespace BuddyNext\SocialGraph;`
  - `BlockController` → `includes/SocialGraph/` → `namespace BuddyNext\SocialGraph;`
  - `SpaceController` → `includes/Spaces/` → `namespace BuddyNext\Spaces;`
  - `SpaceCategoryController` → `includes/Spaces/` → `namespace BuddyNext\Spaces;`
- [ ] Run `php -l` on all 5 new files
- [ ] Edit `includes/REST/Router.php` — replace 5 use lines:
  ```php
  use BuddyNext\REST\Controllers\BlockController;
  use BuddyNext\REST\Controllers\ConnectionController;
  use BuddyNext\REST\Controllers\FollowController;
  use BuddyNext\REST\Controllers\SpaceCategoryController;
  use BuddyNext\REST\Controllers\SpaceController;
  ```
  with:
  ```php
  use BuddyNext\SocialGraph\BlockController;
  use BuddyNext\SocialGraph\ConnectionController;
  use BuddyNext\SocialGraph\FollowController;
  use BuddyNext\Spaces\SpaceCategoryController;
  use BuddyNext\Spaces\SpaceController;
  ```
- [ ] Delete the 5 old files from `includes/REST/Controllers/`
- [ ] Run `mcp__wpcs__wpcs_check_file` on all 5 new files + Router.php
- [ ] Browser verify: navigate to a space URL — confirm space loads, no errors
- [ ] Commit: `refactor(social-graph,spaces): move 5 controllers to domain namespaces`

---

## Task 4: Controller Migration — Remaining 11 Controllers

**Files:**
- Create: `includes/Notifications/NotificationController.php`
- Create: `includes/Profile/ProfileController.php`
- Create: `includes/Reactions/ReactionController.php`
- Create: `includes/Comments/CommentController.php`
- Create: `includes/Hashtags/HashtagController.php`
- Create: `includes/Search/SearchController.php`
- Create: `includes/Moderation/ModerationController.php`
- Create: `includes/Auth/AuthController.php`
- Create: `includes/Outbound/OutboundWebhookController.php` (create Outbound/ folder)
- Create: `includes/Outbound/AccessWebhookController.php`
- Create: `includes/Admin/MemberTypeController.php`
- Modify: `includes/REST/Router.php` (11 use statements)
- Delete: the 11 old files from `includes/REST/Controllers/`

**Steps:**
- [ ] Read each source controller, create at new path with namespace updated:
  - `NotificationController` → `includes/Notifications/` → `namespace BuddyNext\Notifications;`
  - `ProfileController` → `includes/Profile/` → `namespace BuddyNext\Profile;`
  - `ReactionController` → `includes/Reactions/` → `namespace BuddyNext\Reactions;`
  - `CommentController` → `includes/Comments/` → `namespace BuddyNext\Comments;`
  - `HashtagController` → `includes/Hashtags/` → `namespace BuddyNext\Hashtags;`
  - `SearchController` → `includes/Search/` → `namespace BuddyNext\Search;`
  - `ModerationController` → `includes/Moderation/` → `namespace BuddyNext\Moderation;`
  - `AuthController` → `includes/Auth/` → `namespace BuddyNext\Auth;`
  - `OutboundWebhookController` → `includes/Outbound/` → `namespace BuddyNext\Outbound;`
  - `AccessWebhookController` → `includes/Outbound/` → `namespace BuddyNext\Outbound;`
  - `MemberTypeController` → `includes/Admin/` → `namespace BuddyNext\Admin;`
- [ ] Run `php -l` on all 11 new files
- [ ] Edit `includes/REST/Router.php` — replace remaining 11 use lines:
  ```php
  use BuddyNext\REST\Controllers\AccessWebhookController;
  use BuddyNext\REST\Controllers\AuthController;
  use BuddyNext\REST\Controllers\CommentController;
  use BuddyNext\REST\Controllers\HashtagController;
  use BuddyNext\REST\Controllers\MemberTypeController;
  use BuddyNext\REST\Controllers\ModerationController;
  use BuddyNext\REST\Controllers\NotificationController;
  use BuddyNext\REST\Controllers\OutboundWebhookController;
  use BuddyNext\REST\Controllers\ProfileController;
  use BuddyNext\REST\Controllers\ReactionController;
  use BuddyNext\REST\Controllers\SearchController;
  ```
  with:
  ```php
  use BuddyNext\Outbound\AccessWebhookController;
  use BuddyNext\Auth\AuthController;
  use BuddyNext\Comments\CommentController;
  use BuddyNext\Hashtags\HashtagController;
  use BuddyNext\Admin\MemberTypeController;
  use BuddyNext\Moderation\ModerationController;
  use BuddyNext\Notifications\NotificationController;
  use BuddyNext\Outbound\OutboundWebhookController;
  use BuddyNext\Profile\ProfileController;
  use BuddyNext\Reactions\ReactionController;
  use BuddyNext\Search\SearchController;
  ```
- [ ] Verify Router.php has zero `BuddyNext\REST\Controllers` imports: `grep -c "REST\\\\Controllers" includes/REST/Router.php` — must return 0
- [ ] Delete all remaining files from `includes/REST/Controllers/`
- [ ] Run `mcp__wpcs__wpcs_check_file` on all 11 new files + Router.php
- [ ] Browser verify: notifications page, profile page, search, moderation queue — no errors
- [ ] Commit: `refactor(rest): move remaining 11 controllers to domain namespaces`

---

## Task 5: NotificationListener

**Read first:** `includes/Notifications/EventListener.php` — identify the 11 notification hook/method pairs listed in the Listener Extraction Reference.

**Files:**
- Read: `includes/Notifications/EventListener.php`
- Create: `includes/Notifications/NotificationListener.php`
- Modify: `includes/Notifications/EventListener.php` (remove 11 hooks + their methods)

**Steps:**
- [ ] Read `includes/Notifications/EventListener.php` in full — note its constructor signature and what services it resolves from the container
- [ ] Create `includes/Notifications/NotificationListener.php`:
  - `declare(strict_types=1);`
  - `namespace BuddyNext\Notifications;`
  - `use BuddyNext\Contracts\ListenerInterface;`
  - Class: `NotificationListener implements ListenerInterface`
  - Constructor: same service dependencies as EventListener's notification methods need (container or specific services)
  - `register()` method: all 11 `add_action()` calls for notification hooks
  - Copy all 11 method bodies verbatim from EventListener
- [ ] Run `php -l includes/Notifications/NotificationListener.php`
- [ ] Remove from `includes/Notifications/EventListener.php`:
  - The 11 `add_action()` calls (in `init()`)
  - The 11 corresponding method bodies
  - Any `use` statements that are only needed by those 11 methods (careful — don't remove imports used by remaining methods)
- [ ] Run `php -l includes/Notifications/EventListener.php`
- [ ] Run `mcp__wpcs__wpcs_check_file` on both files
- [ ] Browser verify: follow a user → in-app notification appears
- [ ] Commit: `refactor(notifications): extract NotificationListener from EventListener`

---

## Task 6: ModerationListener

**Context:** There is NO `Moderation/EventListener.php`. The 10 moderation hooks live in `Notifications/EventListener.php`. Create `ModerationListener.php` from scratch.

**Files:**
- Read: `includes/Notifications/EventListener.php`
- Create: `includes/Moderation/ModerationListener.php`
- Modify: `includes/Notifications/EventListener.php` (remove 10 moderation hooks)

**Steps:**
- [ ] Read `includes/Notifications/EventListener.php` — find the 10 moderation hook/method pairs (see Listener Extraction Reference: strike_issued, member_suspended, user_suspended, appeal_resolved, user_warned, user_unsuspended, appeal_submitted, shadow_banned, shadow_ban_removed, daily_queue_check)
- [ ] Create `includes/Moderation/ModerationListener.php`:
  - `declare(strict_types=1);`
  - `namespace BuddyNext\Moderation;`
  - `use BuddyNext\Contracts\ListenerInterface;`
  - Class: `ModerationListener implements ListenerInterface`
  - Constructor: inject service dependencies needed by these 10 methods
  - `register()` method: 10 `add_action()` calls
  - Copy all 10 method bodies verbatim from EventListener
- [ ] Run `php -l includes/Moderation/ModerationListener.php`
- [ ] Remove from `includes/Notifications/EventListener.php`:
  - The 10 `add_action()` calls for moderation hooks
  - The 10 corresponding method bodies
- [ ] Run `php -l includes/Notifications/EventListener.php`
- [ ] Run `mcp__wpcs__wpcs_check_file` on both files
- [ ] Browser verify: suspend a user in wp-admin/tools → no PHP errors
- [ ] Commit: `refactor(moderation): extract ModerationListener from Notifications/EventListener`

---

## Task 7: OutboundWebhookListener

**Files:**
- Read: `includes/Notifications/EventListener.php`
- Create: `includes/Outbound/OutboundWebhookListener.php`
- Modify: `includes/Notifications/EventListener.php` (remove 14 webhook hooks)

**Steps:**
- [ ] Read `includes/Notifications/EventListener.php` — find the 14 `on_webhook_*` methods
- [ ] Create `includes/Outbound/OutboundWebhookListener.php`:
  - `declare(strict_types=1);`
  - `namespace BuddyNext\Outbound;`
  - `use BuddyNext\Contracts\ListenerInterface;`
  - Class: `OutboundWebhookListener implements ListenerInterface`
  - Constructor: inject the webhook service — `$this->webhooks = $container->get('webhooks')` or pass the `OutboundWebhookService` directly
  - `register()` method: 14 `add_action()` calls
  - Copy all 14 method bodies verbatim from EventListener
  - Note: `OutboundWebhookService` lives in `Core/` — use `use BuddyNext\Core\OutboundWebhookService;` if needed
- [ ] Run `php -l includes/Outbound/OutboundWebhookListener.php`
- [ ] Remove from `includes/Notifications/EventListener.php`:
  - The 14 webhook hook `add_action()` calls
  - The 14 `on_webhook_*` method bodies
- [ ] Run `php -l includes/Notifications/EventListener.php`
- [ ] Run `mcp__wpcs__wpcs_check_file` on both files
- [ ] Browser verify: no PHP errors on any page
- [ ] Commit: `refactor(outbound): extract OutboundWebhookListener from Notifications/EventListener`

---

## Task 8: OnboardingListener

**Files:**
- Read: `includes/Notifications/EventListener.php`
- Create: `includes/Onboarding/OnboardingListener.php`
- Modify: `includes/Notifications/EventListener.php` (remove 4 onboarding hooks)

**Steps:**
- [ ] Read `includes/Notifications/EventListener.php` — find the 4 onboarding hooks (user_register nudge, onboarding_completed, nudge_24h, nudge_72h) and their methods
- [ ] Create `includes/Onboarding/OnboardingListener.php`:
  - `declare(strict_types=1);`
  - `namespace BuddyNext\Onboarding;`
  - `use BuddyNext\Contracts\ListenerInterface;`
  - Class: `OnboardingListener implements ListenerInterface`
  - Constructor: inject services needed by onboarding nudge methods
  - `register()` method: 4 `add_action()` calls
  - Copy all method bodies verbatim
  - Important: `user_register` will have 3 total listeners after this (VerificationListener, OnboardingListener, potentially others) — this is fine, WP supports multiple callbacks per hook
- [ ] Run `php -l includes/Onboarding/OnboardingListener.php`
- [ ] Remove from `includes/Notifications/EventListener.php`:
  - The 4 onboarding `add_action()` calls
  - The corresponding method bodies (`on_user_register_schedule_nudges`, `on_onboarding_completed_cancel_nudges`, `handle_onboarding_nudge`)
- [ ] Run `php -l includes/Notifications/EventListener.php`
- [ ] Run `mcp__wpcs__wpcs_check_file` on both files
- [ ] Browser verify: onboarding page loads — no errors
- [ ] Commit: `refactor(onboarding): extract OnboardingListener from Notifications/EventListener`

---

## Task 9: Bridge Listeners

**Files:**
- Read: `includes/Notifications/EventListener.php` (should now have only 3 hooks remaining)
- Create: `includes/Bridges/JetonomyBridgeListener.php`
- Create: `includes/Bridges/GamificationBridgeListener.php`
- Modify: `includes/Notifications/EventListener.php` (remove last 3 hooks)

**Steps:**
- [ ] Read `includes/Notifications/EventListener.php` — confirm remaining hooks are exactly: `jetonomy_after_create_reply`, `wb_gamification_badge_awarded`, `wb_gamification_level_changed`
  - If any other hooks remain, STOP — a previous task missed something. Do not proceed until accounted for.
- [ ] Create `includes/Bridges/JetonomyBridgeListener.php`:
  - `declare(strict_types=1);`
  - `namespace BuddyNext\Bridges;`
  - `use BuddyNext\Contracts\ListenerInterface;`
  - Class: `JetonomyBridgeListener implements ListenerInterface`
  - `register()`: guard with `if ( ! class_exists( 'Jetonomy' ) ) { return; }` before registering hook
  - Hook: `add_action( 'jetonomy_after_create_reply', array( $this, 'on_jetonomy_reply' ) );`
  - Method body: `on_jetonomy_reply` copied verbatim
- [ ] Create `includes/Bridges/GamificationBridgeListener.php`:
  - Same pattern, namespace `BuddyNext\Bridges`, class `GamificationBridgeListener`
  - `register()`: guard with appropriate `class_exists` check (check the existing `GamificationBridge.php` to see what class it guards against)
  - Hooks: `wb_gamification_badge_awarded` + `wb_gamification_level_changed`
  - Method bodies copied verbatim
- [ ] Run `php -l` on both new files
- [ ] Remove the 3 final hook registrations + method bodies from `includes/Notifications/EventListener.php`
- [ ] Verify `init()` method body of `includes/Notifications/EventListener.php` is now empty (no add_action/add_filter calls):
  - Run: `grep -c "add_action\|add_filter" includes/Notifications/EventListener.php` — must return 0
- [ ] Run `mcp__wpcs__wpcs_check_file` on all 3 files
- [ ] Commit: `refactor(bridges): extract JetonomyBridgeListener + GamificationBridgeListener`

---

## Task 10: Final Wiring — Plugin.php + Cleanup

**Files:**
- Read: `includes/Core/Plugin.php`
- Modify: `includes/Core/Plugin.php`
- Delete: `includes/Notifications/EventListener.php`
- Delete: `includes/REST/Controllers/` (entire folder — must be empty by now)

**Steps:**
- [ ] Read `includes/Core/Plugin.php` in full
- [ ] Verify `includes/REST/Controllers/` is empty: `ls includes/REST/Controllers/` — must list nothing
- [ ] Verify `includes/Notifications/EventListener.php` init() is empty of hooks: `grep -c "add_action\|add_filter" includes/Notifications/EventListener.php` — must return 0
- [ ] Add these `use` statements to Plugin.php (after existing uses):
  ```php
  use BuddyNext\Notifications\NotificationListener;
  use BuddyNext\Moderation\ModerationListener;
  use BuddyNext\Outbound\OutboundWebhookListener;
  use BuddyNext\Onboarding\OnboardingListener;
  use BuddyNext\Bridges\JetonomyBridgeListener;
  use BuddyNext\Bridges\GamificationBridgeListener;
  ```
- [ ] Add a private STATIC method `register_listeners()` to Plugin class (Plugin::init() is static — no $this):
  ```php
  private static function register_listeners( Container $container ): void {
      ( new NotificationListener( $container ) )->register();
      ( new ModerationListener( $container ) )->register();
      ( new OutboundWebhookListener( $container ) )->register();
      ( new OnboardingListener( $container ) )->register();
      ( new JetonomyBridgeListener() )->register();
      ( new GamificationBridgeListener() )->register();
  }
  ```
  Note: adjust constructor args to match what each listener actually needs — check what services the methods call and pass only those. If a listener needs the full container, pass `$container`. If it only needs a specific service, pass `$container->get('key')`. The `$container` variable is already defined earlier in `init()`.
- [ ] In `Plugin::init()`, find the line:
  ```php
  ( new EventListener() )->init();
  ```
  Replace it with:
  ```php
  static::register_listeners( $container );
  ```
- [ ] Remove `use BuddyNext\Notifications\EventListener;` from Plugin.php (line 50 — the import that now points to a file about to be deleted)
- [ ] Run `php -l includes/Core/Plugin.php`
- [ ] Delete `includes/Notifications/EventListener.php`
- [ ] Delete `includes/REST/Controllers/` folder: `rmdir includes/REST/Controllers/`
- [ ] Run `mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })`
- [ ] Run `mcp__wpcs__wpcs_phpstan_check({ path: "includes/Core/", level: 5 })`
- [ ] Commit: `refactor(core): Plugin.php dispatches domain listeners, remove god EventListener`

---

## Task 11: Full Browser Verification

**No file changes — verification only.**

- [ ] Navigate: `http://forums.local?autologin=1`
- [ ] Confirm activity feed loads — no PHP errors, no white screen
- [ ] Follow a user → confirm in-app notification appears (NotificationListener)
- [ ] Navigate to a space URL → confirm space loads (SpaceController)
- [ ] Navigate to notifications page → confirm renders (NotificationController)
- [ ] Navigate to profile → confirm renders (ProfileController)
- [ ] Navigate to `/wp-admin/` → confirm admin loads cleanly
- [ ] Check browser console: `mcp__plugin_playwright_playwright__browser_console_messages` — zero errors
- [ ] Check PHP error log: `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo file_get_contents(ABSPATH . "wp-content/debug.log") ?: "log empty";'`
- [ ] Confirm no `REST\Controllers` references remain anywhere in codebase: `grep -rn "REST\\\\Controllers" includes/` — must return empty
- [ ] Confirm `includes/Notifications/EventListener.php` no longer exists
- [ ] Confirm `includes/REST/Controllers/` no longer exists
- [ ] Run: `mcp__wpcs__wpcs_check_directory({ directory: "includes/", standard: "WordPress" })` — zero violations
- [ ] Commit: `docs: update CLAUDE.md Recent Changes — code organization refactor`

---

## CLAUDE.md Update (in Task 11 commit)

Add to Recent Changes table:

```
| 2026-03-24 | — | refactor | All 21 REST controllers moved from REST\Controllers\ to domain namespaces (Feed, SocialGraph, Spaces, Notifications, Profile, Reactions, Comments, Hashtags, Search, Moderation, Auth, Outbound, Admin) |
| 2026-03-24 | — | refactor | Notifications/EventListener.php (42 hooks) split into 6 domain listeners: NotificationListener, ModerationListener, OutboundWebhookListener, OnboardingListener, JetonomyBridgeListener, GamificationBridgeListener |
| 2026-03-24 | — | refactor | Plugin.php: register_listeners() replaces single EventListener boot |
| 2026-03-24 | — | feature | Added Contracts/ListenerInterface.php |
| 2026-03-24 | — | refactor | Created Outbound/ and Auth/ domain folders |
| 2026-03-24 | — | deleted | Core/EventListener.php (empty after extraction) and REST/Controllers/ folder |
```
