# BuddyNext Structure Audit Fixes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix 9 structural violations identified in the 2026-03-24 audit so the codebase has zero ambiguity, consistent naming, and every file is in its correct domain.

**Architecture:** Pure file-organisation changes — no behaviour change, no schema change, no new features. Every task moves or renames exactly one unit (file/class/method), updates every importer atomically, runs WPCS + browser verify, then commits. If any verify step fails the task is NOT done.

**Tech Stack:** PHP 8.1, PSR-4 autoloader (`BuddyNext\` → `includes/`), WPCS WordPress standard, PHPStan level 5, Playwright MCP for browser verification.

**Plugin root:** `/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext`

---

## Safety Rules (non-negotiable for every task)

1. **Grep before moving.** Before touching any file run the grep commands listed in the task to confirm the full importer list. If you find an importer not listed in the plan, update that file too before committing.
2. **Atomic commits.** The moved/renamed file and every file that imports it go in the same commit. Never commit a broken intermediate state.
3. **WPCS before commit.** Run `mcp__wpcs__wpcs_check_file` on every PHP file you modify. Zero violations required.
4. **Browser verify after commit.** Navigate to `http://forums.local/activity/?autologin=1` and check for PHP fatal errors. Zero errors = pass.
5. **No behaviour change.** Public method signatures, hook names, REST routes, and DB queries must be identical before and after. Only namespace declarations and `use` import lines change.

---

## File Change Map

| # | Old path | New path | What changes |
|---|---|---|---|
| 1 | `includes/Core/OutboundWebhookService.php` | `includes/Outbound/OutboundWebhookService.php` | namespace `Core` → `Outbound` |
| 2 | `includes/Feed/SafeguardService.php` | `includes/Moderation/SafeguardService.php` | namespace `Feed` → `Moderation` |
| 3 | `includes/Admin/MemberTypeController.php` | `includes/MemberTypes/MemberTypeController.php` | namespace `Admin` → `MemberTypes` |
| 4a | `includes/Bridges/Jetonomy.php` | `includes/Bridges/JetonomyBridge.php` | class `Jetonomy` → `JetonomyBridge` |
| 4b | `includes/Bridges/WBGamification.php` | `includes/Bridges/GamificationBridge.php` | class `WBGamification` → `GamificationBridge` |
| 4c | `includes/Bridges/CareerBoard.php` | `includes/Bridges/CareerBoardBridge.php` | class `CareerBoard` → `CareerBoardBridge` |
| 4d | `includes/Bridges/WPMediaVerse.php` | `includes/Bridges/WPMediaVerseBridge.php` | class `WPMediaVerse` → `WPMediaVerseBridge` |
| 5a | `includes/Auth/VerificationListener.php` | same | `init()` → `register()` + `implements ListenerInterface` |
| 5b | `includes/Hashtags/HashtagListener.php` | same | `init()` → `register()` + `implements ListenerInterface` |
| 5c | `includes/Notifications/EmailDispatchListener.php` | same | `init()` → `register()` + `implements ListenerInterface` |
| 5d | `includes/Search/SearchIndexListener.php` | same | `init()` → `register()` + `implements ListenerInterface` |
| 6 | `tests/REST/*.php` (11 files) | `tests/{Domain}/*.php` | namespace update only |
| 7 | `includes/Admin/Helpers/MemberDisplay.php` | `includes/Admin/Members/MemberDisplay.php` | namespace `Admin\Helpers` → `Admin\Members` |
| 8 | `includes/Search/MemberDirectoryService.php` | `includes/Profile/MemberDirectoryService.php` | namespace `Search` → `Profile` |
| 9 | `includes/Core/CronHandlers.php` | `includes/Core/CronService.php` | class `CronHandlers` → `CronService` |

---

## Task 1 — Move OutboundWebhookService: Core → Outbound

**Why:** Service, controller, and listener for outbound webhooks must co-locate in `Outbound/`. Current split puts the business logic in `Core/` while the REST surface is in `Outbound/`.

**Files:**
- Move: `includes/Core/OutboundWebhookService.php` → `includes/Outbound/OutboundWebhookService.php`
- Modify: `includes/Core/Plugin.php` (line 67 — use import, line 474 — binding)
- Modify: `includes/Outbound/OutboundWebhookController.php` (line 19 — use import)

**Importers (confirmed via grep):**
```
includes/Core/Plugin.php:67         use BuddyNext\Core\OutboundWebhookService;
includes/Outbound/OutboundWebhookController.php:19  use BuddyNext\Core\OutboundWebhookService;
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
grep -rn "OutboundWebhookService" --include="*.php" | grep -v "class OutboundWebhookService"
```
Expected: ~8 lines total. The lines in `OutboundWebhookListener.php` are docblock/comment references only — no `use` import to update there. Only `Plugin.php` and `OutboundWebhookController.php` have actual `use` imports that need changing.

- [ ] **Step 2: Copy file to new location, update namespace declaration**

In `includes/Outbound/OutboundWebhookService.php` change line 16:
```php
// Before:
namespace BuddyNext\Core;

// After:
namespace BuddyNext\Outbound;
```

- [ ] **Step 3: Update Plugin.php use import (line 67)**
```php
// Before:
use BuddyNext\Core\OutboundWebhookService;

// After:
use BuddyNext\Outbound\OutboundWebhookService;
```

- [ ] **Step 4: Update OutboundWebhookController.php use import (line 19)**
```php
// Before:
use BuddyNext\Core\OutboundWebhookService;

// After:
use BuddyNext\Outbound\OutboundWebhookService;
```

- [ ] **Step 5: Delete the old file**
```bash
rm "includes/Core/OutboundWebhookService.php"
```

- [ ] **Step 6: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Outbound/OutboundWebhookService.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Outbound/OutboundWebhookController.php" })
```
Expected: zero violations on all three.

- [ ] **Step 7: Browser verify**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/activity/?autologin=1" })
```
Expected: feed renders, no white screen, no PHP fatal in page source.

- [ ] **Step 8: Commit**
```bash
git add includes/Outbound/OutboundWebhookService.php \
        includes/Core/Plugin.php \
        includes/Outbound/OutboundWebhookController.php
git rm includes/Core/OutboundWebhookService.php
git commit -m "refactor(outbound): move OutboundWebhookService Core → Outbound domain"
```

---

## Task 2 — Move SafeguardService: Feed → Moderation

**Why:** SafeguardService enforces banned words, rate limits, and domain filters — moderation concerns, not feed composition. It has zero relationship to post or feed hydration logic.

**Files:**
- Move: `includes/Feed/SafeguardService.php` → `includes/Moderation/SafeguardService.php`
- Modify: `includes/Core/Plugin.php` (line 36 — use import)
- Modify: `includes/Feed/PostService.php` (uses `SafeguardService` without a `use` import — add one after the move)

**Importers (confirmed via grep):**
```
includes/Core/Plugin.php:36          use BuddyNext\Feed\SafeguardService;
includes/Feed/PostService.php:425    private function get_safeguard(): SafeguardService  (no use statement — relies on same namespace)
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "SafeguardService" includes/ --include="*.php" | grep -v "class SafeguardService"
```

- [ ] **Step 2: Copy file, update namespace declaration**

In `includes/Moderation/SafeguardService.php`:
```php
// Before:
namespace BuddyNext\Feed;

// After:
namespace BuddyNext\Moderation;
```

- [ ] **Step 3: Update Plugin.php use import**
```php
// Before:
use BuddyNext\Feed\SafeguardService;

// After:
use BuddyNext\Moderation\SafeguardService;
```

- [ ] **Step 4: Add use import to PostService.php**

`PostService.php` is in `BuddyNext\Feed` and currently calls `new SafeguardService()` without a `use` statement because it was in the same namespace. Add after the existing use block:
```php
use BuddyNext\Moderation\SafeguardService;
```

- [ ] **Step 5: Delete old file**
```bash
rm "includes/Feed/SafeguardService.php"
```

- [ ] **Step 6: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Moderation/SafeguardService.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Feed/PostService.php" })
```

- [ ] **Step 7: Browser verify** — navigate to `/activity/` and create a test post with a banned word to confirm SafeguardService still triggers.

- [ ] **Step 8: Commit**
```bash
git add includes/Moderation/SafeguardService.php includes/Core/Plugin.php includes/Feed/PostService.php
git rm includes/Feed/SafeguardService.php
git commit -m "refactor(moderation): move SafeguardService Feed → Moderation domain"
```

---

## Task 3 — Move MemberTypeController: Admin → MemberTypes

**Why:** The service and its REST controller for member types must be co-located. `MemberTypeService` lives in `includes/MemberTypes/`. `MemberTypeController` (a REST controller, not an admin page) belongs there too.

**Files:**
- Move: `includes/Admin/MemberTypeController.php` → `includes/MemberTypes/MemberTypeController.php`
- Modify: `includes/REST/Router.php` (line 29 — use import)

**Importers (confirmed via grep):**
```
includes/REST/Router.php:29    use BuddyNext\Admin\MemberTypeController;
includes/REST/Router.php:74    ( new MemberTypeController(...) )->register_routes();
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "MemberTypeController" includes/ tests/ --include="*.php"
```

- [ ] **Step 2: Copy file, update namespace declaration**
```php
// Before:
namespace BuddyNext\Admin;

// After:
namespace BuddyNext\MemberTypes;
```

- [ ] **Step 3: Update Router.php use import**
```php
// Before:
use BuddyNext\Admin\MemberTypeController;

// After:
use BuddyNext\MemberTypes\MemberTypeController;
```

- [ ] **Step 4: Delete old file**
```bash
rm "includes/Admin/MemberTypeController.php"
```

- [ ] **Step 5: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/MemberTypes/MemberTypeController.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/REST/Router.php" })
```

- [ ] **Step 6: Browser verify** — navigate to `/wp-admin/admin.php?page=buddynext-members` → Member Types tab. Confirm member types load. Also verify `GET /wp-json/buddynext/v1/member-types` returns 200.

- [ ] **Step 7: Commit**
```bash
git add includes/MemberTypes/MemberTypeController.php includes/REST/Router.php
git rm includes/Admin/MemberTypeController.php
git commit -m "refactor(member-types): move MemberTypeController Admin → MemberTypes domain"
```

---

## Task 4 — Rename Bridge Adapter Classes (add Bridge suffix)

**Why:** `class Jetonomy` in `Bridges/Jetonomy.php` is ambiguous — reads like the external plugin class, not our adapter. PSR-4 requires file name = class name. Plugin.php currently aliases all four via `as XxxBridge` exactly because the class names are wrong.

**Files:**
- Rename: 4 bridge PHP files (class name + file name)
- Modify: `includes/Core/Plugin.php` (4 use imports — remove `as` aliases)
- Modify: `tests/Bridges/JetonomyBridgeTest.php` (use import + @covers annotation)
- Modify: `tests/Bridges/WBGamificationBridgeTest.php` (use import + @covers annotation)
- Modify: `tests/Bridges/CareerBoardBridgeTest.php` (use import + @covers annotation)
- Modify: `tests/Bridges/WPMediaVerseBridgeTest.php` (use import + @covers annotation)

**Current Plugin.php imports (lines 39–44):**
```php
use BuddyNext\Bridges\CareerBoard as CareerBoardBridge;
use BuddyNext\Bridges\Jetonomy as JetonomyBridge;
use BuddyNext\Bridges\WBGamification as WBGamificationBridge;
use BuddyNext\Bridges\WPMediaVerse as WPMediaVerseBridge;
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "Bridges\\\\Jetonomy\b\|Bridges\\\\WBGamification\|Bridges\\\\CareerBoard\|Bridges\\\\WPMediaVerse" includes/ tests/ --include="*.php"
```
Expected: Plugin.php (4 lines) + 4 test files (one each). Bridge listener files reference `class_exists('Jetonomy\...')` — that's the external plugin check, not our class — no update needed there.

- [ ] **Step 2: Rename Jetonomy.php**

In new file `includes/Bridges/JetonomyBridge.php`, change:
```php
// namespace stays BuddyNext\Bridges

// Before:
class Jetonomy {

// After:
class JetonomyBridge {
```
Delete `includes/Bridges/Jetonomy.php`.

- [ ] **Step 3: Rename WBGamification.php**

In new file `includes/Bridges/GamificationBridge.php`:
```php
// Before:
class WBGamification {

// After:
class GamificationBridge {
```
Delete `includes/Bridges/WBGamification.php`.

- [ ] **Step 4: Rename CareerBoard.php**

In new file `includes/Bridges/CareerBoardBridge.php`:
```php
// Before:
class CareerBoard {

// After:
class CareerBoardBridge {
```
Delete `includes/Bridges/CareerBoard.php`.

- [ ] **Step 5: Rename WPMediaVerse.php**

In new file `includes/Bridges/WPMediaVerseBridge.php`:
```php
// Before:
class WPMediaVerse {

// After:
class WPMediaVerseBridge {
```
Delete `includes/Bridges/WPMediaVerse.php`.

- [ ] **Step 6: Update Plugin.php use imports — remove all `as` aliases**
```php
// Before:
use BuddyNext\Bridges\CareerBoard as CareerBoardBridge;
use BuddyNext\Bridges\Jetonomy as JetonomyBridge;
use BuddyNext\Bridges\WBGamification as WBGamificationBridge;
use BuddyNext\Bridges\WPMediaVerse as WPMediaVerseBridge;

// After:
use BuddyNext\Bridges\CareerBoardBridge;
use BuddyNext\Bridges\JetonomyBridge;
use BuddyNext\Bridges\GamificationBridge;
use BuddyNext\Bridges\WPMediaVerseBridge;
```

The internal usages in Plugin.php (`new JetonomyBridge()`, `new CareerBoardBridge()` etc.) stay identical — only the `use` lines change.

- [ ] **Step 7: WPCS check**
```
mcp__wpcs__wpcs_check_directory({ directory: "includes/Bridges" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })
```

- [ ] **Step 8: Browser verify** — deactivate Jetonomy if active, confirm no fatal. Re-activate, confirm bridge hook fires.

- [ ] **Step 6b: Update the 4 bridge test files**

In each test file update both the `use` import and the `@covers` annotation:
```php
// tests/Bridges/JetonomyBridgeTest.php
// Before:
use BuddyNext\Bridges\Jetonomy;
// @covers \BuddyNext\Bridges\Jetonomy
// After:
use BuddyNext\Bridges\JetonomyBridge;
// @covers \BuddyNext\Bridges\JetonomyBridge
```
Apply the same pattern to `WBGamificationBridgeTest` → `GamificationBridge`, `CareerBoardBridgeTest` → `CareerBoardBridge`, `WPMediaVerseBridgeTest` → `WPMediaVerseBridge`.

Also update any test method body that references the old class name directly (e.g., `new Jetonomy()` → `new JetonomyBridge()`).

- [ ] **Step 9: Commit**
```bash
git add includes/Bridges/JetonomyBridge.php includes/Bridges/GamificationBridge.php \
        includes/Bridges/CareerBoardBridge.php includes/Bridges/WPMediaVerseBridge.php \
        includes/Core/Plugin.php \
        tests/Bridges/JetonomyBridgeTest.php tests/Bridges/WBGamificationBridgeTest.php \
        tests/Bridges/CareerBoardBridgeTest.php tests/Bridges/WPMediaVerseBridgeTest.php
git rm includes/Bridges/Jetonomy.php includes/Bridges/WBGamification.php \
       includes/Bridges/CareerBoard.php includes/Bridges/WPMediaVerse.php
git commit -m "refactor(bridges): rename adapter classes to *Bridge suffix, remove Plugin.php as-aliases"
```

---

## Task 5 — Standardise Listeners: init() → register() + ListenerInterface

**Why:** Plugin.php calls 6 new listeners via `->register()` (implements `ListenerInterface`) and 4 old ones via `->init()`. Two contracts for the same concept. Any new developer adding a listener won't know which to follow.

**Files:**
- Modify: `includes/Auth/VerificationListener.php`
- Modify: `includes/Hashtags/HashtagListener.php`
- Modify: `includes/Notifications/EmailDispatchListener.php`
- Modify: `includes/Search/SearchIndexListener.php`
- Modify: `includes/Core/Plugin.php` (4 call sites)

**Plugin.php call sites to update:**
```php
// Before:
( new VerificationListener( $container->get( 'verification' ) ) )->init();
$container->get( 'search_index_listener' )->init();
( new HashtagListener( $container->get( 'hashtags' ) ) )->init();
( new EmailDispatchListener( ... ) )->init();

// After:
( new VerificationListener( $container->get( 'verification' ) ) )->register();
$container->get( 'search_index_listener' )->register();
( new HashtagListener( $container->get( 'hashtags' ) ) )->register();
( new EmailDispatchListener( ... ) )->register();
```

- [ ] **Step 1: Update VerificationListener**

Add after existing `use` lines:
```php
use BuddyNext\Contracts\ListenerInterface;
```

Change class declaration:
```php
// Before:
class VerificationListener {

// After:
class VerificationListener implements ListenerInterface {
```

Rename method:
```php
// Before:
public function init(): void {

// After:
public function register(): void {
```

- [ ] **Step 2: Update HashtagListener** — same three changes (use import, implements, rename method)

- [ ] **Step 3: Update EmailDispatchListener** — same three changes

- [ ] **Step 4: Update SearchIndexListener** — same three changes

- [ ] **Step 5: Update 4 Plugin.php call sites** — replace every `->init()` on these 4 listeners with `->register()`

- [ ] **Step 6: WPCS check on all 5 modified files**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Auth/VerificationListener.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Hashtags/HashtagListener.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Notifications/EmailDispatchListener.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Search/SearchIndexListener.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })
```

- [ ] **Step 7: Browser verify** — navigate to `/activity/`, create a post with a `#hashtag`, confirm hashtag link appears (HashtagListener fired). Check `/notifications/` for notification (NotificationListener / EmailDispatchListener path). Verify email verification flow still registers user (VerificationListener path).

- [ ] **Step 8: Commit**
```bash
git add includes/Auth/VerificationListener.php \
        includes/Hashtags/HashtagListener.php \
        includes/Notifications/EmailDispatchListener.php \
        includes/Search/SearchIndexListener.php \
        includes/Core/Plugin.php
git commit -m "refactor(listeners): standardise all listeners to ListenerInterface::register()"
```

---

## Task 6 — Restructure tests/REST/ → domain test folders

**Why:** Test structure must mirror source structure. `tests/REST/` is a ghost from the old `REST/Controllers/` layout. PHPUnit discovers all files under `tests/` automatically (no phpunit.xml change needed).

**Files to move (11 files):**

| Old path | New path | Namespace change |
|---|---|---|
| `tests/REST/FollowControllerTest.php` | `tests/SocialGraph/FollowControllerTest.php` | `BuddyNext\Tests\REST` → `BuddyNext\Tests\SocialGraph` |
| `tests/REST/ConnectionControllerTest.php` | `tests/SocialGraph/ConnectionControllerTest.php` | same |
| `tests/REST/BlockControllerTest.php` | `tests/SocialGraph/BlockControllerTest.php` | same |
| `tests/REST/FeedControllerTest.php` | `tests/Feed/FeedControllerTest.php` | → `BuddyNext\Tests\Feed` |
| `tests/REST/PostControllerTest.php` | `tests/Feed/PostControllerTest.php` | same |
| `tests/REST/SpaceControllerTest.php` | `tests/Spaces/SpaceControllerTest.php` | → `BuddyNext\Tests\Spaces` |
| `tests/REST/NotificationControllerTest.php` | `tests/Notifications/NotificationControllerTest.php` | → `BuddyNext\Tests\Notifications` |
| `tests/REST/ProfileControllerTest.php` | `tests/Profile/ProfileControllerTest.php` | → `BuddyNext\Tests\Profile` |
| `tests/REST/SearchControllerTest.php` | `tests/Search/SearchControllerTest.php` | → `BuddyNext\Tests\Search` |
| `tests/REST/ModerationControllerTest.php` | `tests/Moderation/ModerationControllerTest.php` | → `BuddyNext\Tests\Moderation` |
| `tests/REST/AccessWebhookTest.php` | `tests/Outbound/AccessWebhookTest.php` | → `BuddyNext\Tests\Outbound` |

- [ ] **Step 1: Create destination directories**
```bash
mkdir -p tests/{SocialGraph,Outbound}
# tests/Feed, tests/Spaces, tests/Notifications, tests/Profile, tests/Search, tests/Moderation
# already exist — check first
ls tests/
```

- [ ] **Step 2: Move each file and update its namespace declaration**

For each file, the only change inside the file is the `namespace` line at the top. Example for `FollowControllerTest.php`:
```php
// Before:
namespace BuddyNext\Tests\REST;

// After:
namespace BuddyNext\Tests\SocialGraph;
```

Repeat for all 11 files (matching the table above).

- [ ] **Step 3: Verify PHPUnit still finds all tests**
```bash
cd "/Users/varundubey/Local Sites/forums/app/public/wp-content/plugins/buddynext"
vendor/bin/phpunit --list-tests 2>&1 | grep "ControllerTest\|WebhookTest" | wc -l
```
Expected: 11 or more test classes listed.

- [ ] **Step 4: Delete the now-empty `tests/REST/` directory**
```bash
rmdir tests/REST/
```

- [ ] **Step 5: Commit**
```bash
git add tests/
git rm -r tests/REST/
git commit -m "refactor(tests): move controller tests from tests/REST/ to domain folders"
```

---

## Task 7 — Move Admin/Helpers/MemberDisplay → Admin/Members/MemberDisplay

**Why:** `Admin/Helpers/` is a catch-all with a single file. `MemberDisplay` is purely a view-helper for the Members admin page and belongs alongside the other Members admin sub-components.

**Files:**
- Move: `includes/Admin/Helpers/MemberDisplay.php` → `includes/Admin/Members/MemberDisplay.php`
- Modify: `includes/Admin/Members.php` (line 17 — use import)
- Modify: `includes/Admin/Members/MemberEditForm.php` (line 15 — use import)

**Importers (confirmed via grep):**
```
includes/Admin/Members.php:17              use BuddyNext\Admin\Helpers\MemberDisplay;
includes/Admin/Members/MemberEditForm.php:15  use BuddyNext\Admin\Helpers\MemberDisplay;
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "MemberDisplay\|Admin\\\\Helpers" includes/ tests/ --include="*.php"
```

- [ ] **Step 2: Copy file, update namespace**
```php
// Before:
namespace BuddyNext\Admin\Helpers;

// After:
namespace BuddyNext\Admin\Members;
```

- [ ] **Step 3: Update Members.php use import**
```php
// Before:
use BuddyNext\Admin\Helpers\MemberDisplay;

// After:
use BuddyNext\Admin\Members\MemberDisplay;
```

- [ ] **Step 3b: Update MemberEditForm.php use import**
```php
// Before:
use BuddyNext\Admin\Helpers\MemberDisplay;

// After:
use BuddyNext\Admin\Members\MemberDisplay;
```

- [ ] **Step 4: Delete old file and directory**
```bash
rm "includes/Admin/Helpers/MemberDisplay.php"
rmdir "includes/Admin/Helpers/"
```

- [ ] **Step 5: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Admin/Members/MemberDisplay.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Admin/Members.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Admin/Members/MemberEditForm.php" })
```

- [ ] **Step 6: Browser verify** — navigate to `/wp-admin/admin.php?page=buddynext-members`. Confirm member table renders with avatar initials and role badges (both call `MemberDisplay` static methods). Also open a member's edit form to confirm `MemberEditForm` renders without fatal.

- [ ] **Step 7: Commit**
```bash
git add includes/Admin/Members/MemberDisplay.php \
        includes/Admin/Members.php \
        includes/Admin/Members/MemberEditForm.php
git rm includes/Admin/Helpers/MemberDisplay.php
git commit -m "refactor(admin): move MemberDisplay Admin/Helpers → Admin/Members"
```

---

## Task 8 — Move MemberDirectoryService: Search → Profile

**Why:** `MemberDirectoryService` handles member listing and filtering via `WP_User_Query` — it is a people/profile concern, not a search concern. It does not touch `bn_search_index`.

**Files:**
- Move: `includes/Search/MemberDirectoryService.php` → `includes/Profile/MemberDirectoryService.php`
- Modify: `includes/Core/Plugin.php` (line 62 — use import)
- Modify: `includes/Search/SearchController.php` (line 16 — use import; line 207 — inline instantiation)
- Move test: `tests/Search/MemberDirectoryServiceTest.php` → `tests/Profile/MemberDirectoryServiceTest.php`

**Importers (confirmed via grep):**
```
includes/Core/Plugin.php:62              use BuddyNext\Search\MemberDirectoryService;
includes/Search/SearchController.php:16  use BuddyNext\Search\MemberDirectoryService;
tests/Search/MemberDirectoryServiceTest.php:14  use BuddyNext\Search\MemberDirectoryService;
```

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "MemberDirectoryService" includes/ tests/ --include="*.php"
```

- [ ] **Step 2: Copy file, update namespace**
```php
// Before:
namespace BuddyNext\Search;

// After:
namespace BuddyNext\Profile;
```

- [ ] **Step 3: Update Plugin.php**
```php
use BuddyNext\Profile\MemberDirectoryService;
```

- [ ] **Step 4: Update SearchController.php**
```php
use BuddyNext\Profile\MemberDirectoryService;
```

The inline instantiation on line 207 (`new MemberDirectoryService(...)`) uses the class name — no change needed there after the `use` update.

- [ ] **Step 5: Move test file, update namespace**
```php
// tests/Profile/MemberDirectoryServiceTest.php
// Before:
namespace BuddyNext\Tests\Search;
use BuddyNext\Search\MemberDirectoryService;

// After:
namespace BuddyNext\Tests\Profile;
use BuddyNext\Profile\MemberDirectoryService;
```

- [ ] **Step 6: Delete old files**
```bash
rm "includes/Search/MemberDirectoryService.php"
rm "tests/Search/MemberDirectoryServiceTest.php"
```

- [ ] **Step 7: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Profile/MemberDirectoryService.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Search/SearchController.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/Plugin.php" })
```

- [ ] **Step 8: Browser verify** — navigate to `/members/`. Confirm member directory loads with real data. Also verify `GET /wp-json/buddynext/v1/members` returns user list.

- [ ] **Step 9: Commit**
```bash
git add includes/Profile/MemberDirectoryService.php \
        includes/Core/Plugin.php \
        includes/Search/SearchController.php \
        tests/Profile/MemberDirectoryServiceTest.php
git rm includes/Search/MemberDirectoryService.php tests/Search/MemberDirectoryServiceTest.php
git commit -m "refactor(profile): move MemberDirectoryService Search → Profile domain"
```

---

## Task 9 — Rename CronHandlers → CronService

**Why:** Every class in `Core/` follows a `*Service`, `*Router`, `*Loader`, or proper-noun pattern. `CronHandlers` breaks this — "Handlers" implies callback wiring, but this class runs the actual job logic (recount stats, etc.).

**Files:**
- Rename: `includes/Core/CronHandlers.php` → `includes/Core/CronService.php`
- Modify: `includes/Core/CronScheduler.php` (line 79 — instantiation)

**Importers (confirmed via grep):**
```
includes/Core/CronScheduler.php:79    $handlers = new CronHandlers();
```
(No `use` import needed — both are in `BuddyNext\Core`.)

- [ ] **Step 1: Confirm no hidden importers**
```bash
grep -rn "CronHandlers" includes/ tests/ --include="*.php"
```

- [ ] **Step 2: Copy file, rename class**
```php
// includes/Core/CronService.php
// Before:
class CronHandlers {

// After:
class CronService {
```

- [ ] **Step 3: Update CronScheduler.php**
```php
// Before:
$handlers = new CronHandlers();

// After:
$handlers = new CronService();
```

Also update any variable names or docblocks referencing `CronHandlers`.

- [ ] **Step 4: Delete old file**
```bash
rm "includes/Core/CronHandlers.php"
```

- [ ] **Step 5: WPCS check**
```
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/CronService.php" })
mcp__wpcs__wpcs_check_file({ file_path: "includes/Core/CronScheduler.php" })
```

- [ ] **Step 6: Browser verify** — navigate to `/activity/`. Also trigger the cron manually:
```bash
wp --path="/Users/varundubey/Local Sites/forums/app/public" cron event run buddynext_daily_recount
```
Expected: exits 0, no fatal.

- [ ] **Step 7: Commit**
```bash
git add includes/Core/CronService.php includes/Core/CronScheduler.php
git rm includes/Core/CronHandlers.php
git commit -m "refactor(core): rename CronHandlers → CronService (naming convention)"
```

---

## Task 10 — Add Architectural Rules to CLAUDE.md

**Why:** The 9 issues fixed in this plan all stem from the absence of written placement rules. Without them, the same drift will recur on the next feature.

**Files:**
- Modify: `includes/Core/Plugin.php` (no change — this is docs only)
- Modify: `CLAUDE.md` — add a new "File Placement Rules" section

- [ ] **Step 1: Add the following section to CLAUDE.md**, immediately before the "Tech Stack" table:

```markdown
## File Placement Rules — Where Every New File Goes

These rules are enforced on every PR. When in doubt, follow the pattern already in the nearest domain folder.

### Domain Principle
Every feature domain owns its full stack in one folder:
```
includes/{Domain}/
  {Domain}Service.php        ← business logic
  {Domain}Controller.php     ← REST endpoints
  {Domain}Listener.php       ← WordPress hooks (implements ListenerInterface)
```
If a new file's name starts with the domain prefix, it goes in that domain folder. If it doesn't, pick the domain whose README description best matches the file's responsibility.

### Mandatory Placement Rules

| File type | Belongs in | Example |
|---|---|---|
| Outbound webhook service, controller, listener | `Outbound/` | `OutboundWebhookService` |
| Content moderation logic (banned words, rate limits, safeguards) | `Moderation/` | `SafeguardService` |
| REST controller for a domain | Same folder as its Service | `MemberTypeController` → `MemberTypes/` |
| Bridge adapter classes | `Bridges/` with `Bridge` suffix | `JetonomyBridge.php` |
| Bridge listener classes | `Bridges/` with `BridgeListener` suffix | `JetonomyBridgeListener.php` |
| Admin-only UI helpers | `Admin/{SubPage}/` not `Admin/Helpers/` | `MemberDisplay` → `Admin/Members/` |
| Directory/listing service | `Profile/` if it queries WP_User_Query; `Search/` only if it queries `bn_search_index` | `MemberDirectoryService` → `Profile/` |
| Cron job runner | `Core/CronService.php` — no `Handlers` suffix | — |

### Listener Convention
Every class that ends in `Listener` **must**:
1. `implement BuddyNext\Contracts\ListenerInterface`
2. Expose `public function register(): void` (not `init()`)
3. Be wired in `Plugin::init()` as `( new XxxListener() )->register()`

Never use `init()` on a listener. The only classes that use `init()` are Services and Admin registrars.

### Bridge Naming Convention
```
Bridges/JetonomyBridge.php         class JetonomyBridge         ← adapter (no alias needed in Plugin.php)
Bridges/JetonomyBridgeListener.php class JetonomyBridgeListener ← hook registrar
```
Never name a bridge adapter `class Jetonomy` — it reads like the external plugin class.

### Tests Mirror Source
```
includes/Feed/PostController.php  →  tests/Feed/PostControllerTest.php
includes/SocialGraph/FollowController.php  →  tests/SocialGraph/FollowControllerTest.php
```
`tests/REST/` must stay empty. All controller tests live in the controller's domain folder.
```

- [ ] **Step 2: Commit**
```bash
git add CLAUDE.md
git commit -m "docs(claude): add File Placement Rules section — prevent future structural drift"
```

---

## Execution Order

Run tasks sequentially (each touches Plugin.php or shares the same domain).

```
Task 1  OutboundWebhookService move      → commit, verify
Task 2  SafeguardService move            → commit, verify
Task 3  MemberTypeController move        → commit, verify
Task 4  Bridge renames                   → commit, verify
Task 5  Listener standardisation         → commit, verify
Task 6  Test restructuring               → commit, verify
Task 7  MemberDisplay move               → commit, verify
Task 8  MemberDirectoryService move      → commit, verify
Task 9  CronHandlers rename              → commit, verify
Task 10 CLAUDE.md architectural rules   → commit
```

Tasks 6, 7, 9 do not touch Plugin.php and could technically run in parallel with any of the others, but sequential is safer.

---

## Quality Gates (every task)

```
Gate 1 — GREP     Confirm full importer list before moving anything
Gate 2 — WPCS     mcp__wpcs__wpcs_check_file on every modified file → zero violations
Gate 3 — BROWSER  http://forums.local/activity/?autologin=1 → no PHP fatal, no white screen
Gate 4 — COMMIT   Moved file + all importers in same commit, git rm the old file
```

No task is done until all 4 gates pass.
