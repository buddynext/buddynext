# Moderation Flow — Completeness & App-Readiness Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Moderation flow 100% REST-clean and app-ready: every moderation action goes through the service layer (no `$wpdb` in controllers), every capability that exists in the service or admin is reachable over REST, and the Pro moderation layer is reconciled in lockstep — without splitting any file for size.

**Architecture:** Flow-first (vertical). We finish the entire Moderation user-flow — entry → REST → service → table → events — before moving to the next flow. Shared infrastructure (`BaseRestController`) is created here because Moderation is the first flow to need it, then reused by later flows. `ModerationService` stays a single cohesive class (1,600 LOC is fine for this domain; it reads clearly and its methods are thematically coupled — `decide_appeal()`→`lift_suspension_by_id()`, etc.). No god-class splits.

**Tech Stack:** PHP 8.1+, Composer PSR-4 (`BuddyNext\\` → `includes/`, `BuddyNextPro\\` → `includes/`), PHPUnit 9.6 + `WP_Test_REST_TestCase`, WPCS, PHPStan level 5.

**Binding contracts this plan must satisfy:**
- `docs/specs/REST-FRONTEND-CONTRACT.md` — frontend is 100% REST; controllers declare `permission_callback`; nonce is `wp_rest`.
- `docs/specs/SCALE-CONTRACT.md` — every list query has `LIMIT` (REST `per_page` max 50), cursor pagination not OFFSET, no `SELECT COUNT(*)` in renders.
- `docs/specs/REST-INVENTORY.md` — regenerate after adding any endpoint.

**Non-goals (explicitly out of scope):**
- Splitting `ModerationService`, `ModerationController`, `SpaceController`, or any file by line count. Large cohesive files are acceptable.
- Touching the filter contracts `buddynext_safeguard_check` / `buddynext_moderation_auto_actions` — they are clean.
- Reworking the wp-admin `ModerationQueue` UX. (Only the Pro `BulkModAdmin` raw-query seam is in scope.)

---

## CRITICAL — Read Before Each Task

- This is a behaviour-preserving refactor plus additive endpoints. Existing routes keep their paths, methods, permissions, and response shapes. New endpoints are additive.
- Run `php -d opcache.enable=0 vendor/bin/phpunit --filter <Test>` to run a test (local PHP emits an opcache load warning; `-d opcache.enable=0` silences it).
- Run `php -l` on every file you create or modify.
- Run `mcp__wpcs__wpcs_check_file({ file_path })` on every file you create or modify — zero violations before commit.
- The REST-boundary gate (`bin/check-rest-boundary.sh`) must stay green — never introduce admin-ajax.
- After any endpoint change, regenerate `docs/specs/REST-INVENTORY.md` (Task 9).
- No version bumps anywhere (pre-release). No `Co-Authored-By` in commits.
- Free repo: `/Users/vapvarun/dev/repos/buddynext`. Pro repo: `/Users/vapvarun/dev/repos/buddynext-pro`. Both are symlinked into the Local site at `wp-content/plugins/`.
- Browser verification uses Playwright MCP tools against the Local site with `?autologin=1`. Never write standalone Playwright scripts.

---

## Flow Map (reference — current state)

**Entry points:** member report modals (`templates/partials/report-modal.php`, `templates/parts/member-report-modal.php`) → `POST /reports`; suspended-user appeal form → `POST /me/appeals`; member queue (`templates/moderation/queue.php`) + space moderation (`templates/spaces/moderation.php`) → REST; wp-admin `Admin/ModerationQueue.php` (admin-post handlers, direct service calls); Pro `Admin/BulkModAdmin.php`.

**Service:** `includes/Moderation/ModerationService.php` (one cohesive class — reports, strikes, suspensions, shadow-bans, warnings, appeals, queue). Stays whole.

**Tables:** `bn_reports`, `bn_mod_log`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_space_bans`, `bn_posts.content_warning*`, `usermeta.bn_shadow_banned`.

**Events:** `buddynext_report_created`, `buddynext_strike_issued`, `buddynext_member_suspended`/`buddynext_user_suspended`, `buddynext_user_warned`, `buddynext_content_removed`, `buddynext_user_unsuspended`, `buddynext_appeal_submitted`, `buddynext_appeal_resolved`, `buddynext_user_shadow_banned`/`_removed`, `buddynext_daily_queue_check`. Consumed by `includes/Moderation/ModerationListener.php`.

**The 5 controller `$wpdb` violations (Free):**
1. `ModerationController::set_content_warning()` — `bn_posts` SELECT exists-check + UPDATE (~lines 1010–1032).
2. `ModerationController::get_content_warning()` — `bn_posts` SELECT (~lines 1462–1471).
3. `ModerationController::list_appeals()` — `bn_appeals` SELECT (~lines 1292–1310) — duplicates existing `ModerationService::get_pending_appeals()`.
4. `ModerationController::list_space_bans()` — `bn_space_bans` SELECT (~lines 1405–1411).
5. (Pro) `BulkModAdmin::render_content()` — `bn_reports` SELECT (~lines 244–262).

**REST gaps for app-readiness:** `GET /me/appeals` (own appeals list), `GET /users/{id}/warnings`, `GET /users/{id}/shadow-ban` (status), `GET /users/{id}/suspensions` (list).

---

## Task 0: Stand up the PHPUnit / WP integration test harness

**Why:** The flow's definition of done includes green REST tests (`WP_Test_REST_TestCase`). The WP test suite is not yet installed locally, so this must happen first.

**Files:**
- Read: `bin/install-wp-tests.sh`

**Steps:**
- [ ] Read `bin/install-wp-tests.sh` to confirm the argument order (`db-name db-user db-pass [db-host] [wp-version] [skip-db-create]`).
- [ ] Find the Local site MySQL socket + credentials:

```bash
ls "$HOME/Library/Application Support/Local/run/"*/mysql/mysqld.sock 2>/dev/null
```
The Local site DB user is `root`, password `root`, host is the socket path above (use `localhost:<socket>`).

- [ ] Install the test suite into a temp dir (creates a throwaway `wordpress_test` DB):

```bash
cd /Users/vapvarun/dev/repos/buddynext
WP_TESTS_DIR=/tmp/wordpress-tests-lib \
  bin/install-wp-tests.sh wordpress_test root root "localhost:<socket>" latest
```

- [ ] Verify the suite is present:

```bash
ls /tmp/wordpress-tests-lib/includes/bootstrap.php
```
Expected: file exists.

- [ ] Run one existing REST test to confirm the harness works end-to-end:

```bash
cd /Users/vapvarun/dev/repos/buddynext
WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 \
  vendor/bin/phpunit --filter SpaceControllerTest
```
Expected: PASS (or, if it fails, the failure is about test logic — not "WordPress test suite not found").

- [ ] If the DB connection fails: confirm the socket path, and that the Local site is running (the MySQL service must be started in the Local app). Do not proceed to Task 1 until `SpaceControllerTest` runs.
- [ ] No commit (environment setup only; nothing tracked changed).

**Fallback if the harness genuinely cannot run** (DB unreachable and cannot be started): note it explicitly, and substitute REST verification via the running site using authenticated `fetch` through Playwright MCP `browser_evaluate` for each endpoint, plus `bin/check.sh` for static gates. Do not silently skip verification.

---

## Task 1: Create `BaseRestController` and adopt it in `ModerationController`

**Why:** `require_auth()` is copy-pasted into 19 controllers with drifting error codes (`rest_forbidden` vs `rest_not_logged_in`). Moderation is the first flow worked, so it owns the extraction. Later flows adopt the same base. This is reuse, not a size split.

**Files:**
- Create: `includes/REST/BaseRestController.php`
- Create: `tests/REST/BaseRestControllerTest.php`
- Modify: `includes/Moderation/ModerationController.php` (extend base; delete its local `require_auth`/`require_admin` duplicates)

- [ ] **Step 1: Write the failing test**

`tests/REST/BaseRestControllerTest.php`:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\REST;

use BuddyNext\REST\BaseRestController;
use WP_Error;

/**
 * @covers \BuddyNext\REST\BaseRestController
 */
class BaseRestControllerTest extends \WP_UnitTestCase {

	private BaseRestController $controller;

	public function set_up(): void {
		parent::set_up();
		// Anonymous concrete subclass — BaseRestController is abstract.
		$this->controller = new class() extends BaseRestController {
			public function register_routes(): void {}
		};
	}

	public function test_require_auth_returns_error_for_logged_out(): void {
		wp_set_current_user( 0 );
		$result = $this->controller->require_auth();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_require_auth_returns_true_for_logged_in(): void {
		wp_set_current_user( self::factory()->user->create() );
		$this->assertTrue( $this->controller->require_auth() );
	}

	public function test_require_admin_returns_error_for_non_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = $this->controller->require_admin();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_require_admin_returns_true_for_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( $this->controller->require_admin() );
	}
}
```

- [ ] **Step 2: Run it; expect failure** — `WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 vendor/bin/phpunit --filter BaseRestControllerTest`. Expected: error "Class BaseRestController not found".

- [ ] **Step 3: Create `includes/REST/BaseRestController.php`** (header comment matches the plugin's PSR-4 file convention):

```php
<?php // phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- PSR-4 naming used throughout this plugin.
/**
 * Base REST controller.
 *
 * Shared permission helpers for buddynext/v1 controllers. Controllers extend
 * this to avoid re-declaring require_auth()/require_admin(). Each controller
 * still implements register_routes() and its own handlers.
 *
 * @package BuddyNext\REST
 */

declare( strict_types=1 );

namespace BuddyNext\REST;

use WP_Error;

/**
 * Permission helpers shared across REST controllers.
 */
abstract class BaseRestController {

	/**
	 * Register the controller's routes. Called from REST\Router.
	 */
	abstract public function register_routes(): void;

	/**
	 * Require an authenticated user.
	 *
	 * @return true|WP_Error
	 */
	public function require_auth(): true|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'rest_not_logged_in',
				__( 'You must be logged in.', 'buddynext' ),
				array( 'status' => 401 )
			);
		}
		return true;
	}

	/**
	 * Require a user who can manage the community.
	 *
	 * @return true|WP_Error
	 */
	public function require_admin(): true|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to do that.', 'buddynext' ),
				array( 'status' => current_user_can( 'read' ) ? 403 : 401 )
			);
		}
		return true;
	}
}
```

- [ ] **Step 4: Run the test; expect PASS** — same phpunit command. Expected: 4 assertions pass.

- [ ] **Step 5: Adopt in `ModerationController`.** Read `includes/Moderation/ModerationController.php`. Then:
  - Add `use BuddyNext\REST\BaseRestController;`.
  - Change the class declaration to `class ModerationController extends BaseRestController {`.
  - Delete its local `require_auth()` method body and its local `require_admin()` method body (the base now provides them). Keep the moderation-specific permission methods (`require_queue_access`, `require_space_owner_or_admin`) as-is.
  - Confirm the base's `require_admin` matches the controller's prior behaviour (both gate on `manage_options`). If the controller's old `require_admin` returned a different error code/status, preserve any test that asserts it — adjust the base only if an existing test breaks; otherwise the base's shape wins.

- [ ] **Step 6:** `php -l includes/Moderation/ModerationController.php`; `php -l includes/REST/BaseRestController.php`.
- [ ] **Step 7:** `mcp__wpcs__wpcs_check_file` on both files — zero violations.
- [ ] **Step 8: Run the moderation REST tests** (if present) plus the new test:
  `WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 vendor/bin/phpunit --filter 'Moderation|BaseRestController'`. Expected: green.
- [ ] **Step 9: Commit** — `refactor(rest): add BaseRestController; ModerationController extends it`.

---

## Task 2: Move content-warning DB access into the service

**Why:** `set_content_warning()` and `get_content_warning()` run raw `$wpdb` against `bn_posts` from the controller. The app and tests need this logic in the service. The same `bn_posts` content-warning write is also reachable from the Profile controller path — centralising removes that duplication.

**Files:**
- Modify: `includes/Moderation/ModerationService.php` (add two methods)
- Modify: `includes/Moderation/ModerationController.php` (delegate)
- Create/Modify test: `tests/Moderation/ContentWarningTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Moderation/ContentWarningTest.php`:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;

/**
 * @covers \BuddyNext\Moderation\ModerationService::set_post_content_warning
 * @covers \BuddyNext\Moderation\ModerationService::get_post_content_warning
 */
class ContentWarningTest extends \WP_UnitTestCase {

	private ModerationService $service;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new ModerationService();
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array(
				'user_id' => self::factory()->user->create(),
				'content' => 'hello',
				'status'  => 'published',
			)
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	public function test_set_then_get_content_warning(): void {
		$ok = $this->service->set_post_content_warning( $this->post_id, 'sensitive', 'Spoilers ahead' );
		$this->assertTrue( $ok );

		$warning = $this->service->get_post_content_warning( $this->post_id );
		$this->assertTrue( $warning['has_warning'] );
		$this->assertSame( 'sensitive', $warning['warning_type'] );
		$this->assertSame( 'Spoilers ahead', $warning['warning_text'] );
	}

	public function test_clear_content_warning(): void {
		$this->service->set_post_content_warning( $this->post_id, 'sensitive', 'x' );
		$this->service->set_post_content_warning( $this->post_id, '', '' );
		$warning = $this->service->get_post_content_warning( $this->post_id );
		$this->assertFalse( $warning['has_warning'] );
	}

	public function test_get_returns_false_for_missing_post(): void {
		$warning = $this->service->get_post_content_warning( 999999 );
		$this->assertFalse( $warning['has_warning'] );
	}
}
```

- [ ] **Step 2: Run it; expect failure** — `--filter ContentWarningTest`. Expected: "Call to undefined method ... set_post_content_warning".

- [ ] **Step 3: Read** the current `set_content_warning()` and `get_content_warning()` bodies in `includes/Moderation/ModerationController.php` (around lines 992–1032 and 1456–1471). Copy the exact column names and behaviour (existence check, the `content_warning` / `content_warning_type` columns).

- [ ] **Step 4: Add to `ModerationService`** (near the report/content methods). Use the real column names confirmed in Step 3:

```php
/**
 * Read a post's content-warning state.
 *
 * @param int $post_id Post id.
 * @return array{has_warning:bool,warning_type:string,warning_text:string}
 */
public function get_post_content_warning( int $post_id ): array {
	global $wpdb;
	$none = array(
		'has_warning'  => false,
		'warning_type' => '',
		'warning_text' => '',
	);
	if ( $post_id <= 0 ) {
		return $none;
	}
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT content_warning, content_warning_type FROM {$wpdb->prefix}bn_posts WHERE id = %d",
			$post_id
		),
		ARRAY_A
	);
	if ( ! $row ) {
		return $none;
	}
	$text = (string) ( $row['content_warning'] ?? '' );
	return array(
		'has_warning'  => '' !== $text,
		'warning_type' => (string) ( $row['content_warning_type'] ?? '' ),
		'warning_text' => $text,
	);
}

/**
 * Set or clear a post's content warning. Empty $text clears it.
 *
 * @param int    $post_id Post id.
 * @param string $type    Warning type slug.
 * @param string $text    Warning text; empty clears the warning.
 * @return bool True on success (post exists and update ran).
 */
public function set_post_content_warning( int $post_id, string $type, string $text ): bool {
	global $wpdb;
	if ( $post_id <= 0 ) {
		return false;
	}
	$exists = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id )
	);
	if ( ! $exists ) {
		return false;
	}
	$text = trim( $text );
	$updated = $wpdb->update(
		$wpdb->prefix . 'bn_posts',
		array(
			'content_warning'      => '' === $text ? null : $text,
			'content_warning_type' => '' === $text ? null : sanitize_key( $type ),
		),
		array( 'id' => $post_id )
	);
	return false !== $updated;
}
```

- [ ] **Step 5: Run the test; expect PASS** — `--filter ContentWarningTest`.

- [ ] **Step 6: Delegate in the controller.** In `ModerationController::set_content_warning()`, replace the raw `$wpdb` block with:
  `$ok = ( new ModerationService() )->set_post_content_warning( $post_id, $type, $text );` (keep the existing request parsing, permission_callback, and response envelope; return the existing error shape if `$ok` is false). In `get_content_warning()`, replace the raw `$wpdb` read with `$warning = ( new ModerationService() )->get_post_content_warning( $post_id );` and serialize `$warning`.
  - Confirm `ModerationService` is imported (`use BuddyNext\Moderation\ModerationService;`) — same namespace, so no import needed if already in `BuddyNext\Moderation`.

- [ ] **Step 7:** Grep to confirm no `bn_posts` raw access remains in the controller for content warnings: `grep -n "bn_posts" includes/Moderation/ModerationController.php` — expected: no content-warning SELECT/UPDATE lines remain.
- [ ] **Step 8:** `php -l` both files; `mcp__wpcs__wpcs_check_file` both; run `--filter 'ContentWarning|Moderation'`.
- [ ] **Step 9: Commit** — `refactor(moderation): move content-warning DB access into ModerationService`.

---

## Task 3: Route `list_appeals` through the existing service method

**Why:** `ModerationController::list_appeals()` runs a raw `bn_appeals` SELECT that duplicates `ModerationService::get_pending_appeals()`, which already exists. This is the smallest, safest layer fix.

**Files:**
- Modify: `includes/Moderation/ModerationController.php`
- Modify/Create test: `tests/Moderation/AppealsListTest.php`

- [ ] **Step 1: Read** `ModerationService::get_pending_appeals()` (around line 907) — confirm its return shape (array of hydrated appeal rows, with `LIMIT`). Confirm it satisfies the SCALE-CONTRACT `LIMIT` rule; if it lacks a cap, add a `LIMIT` parameter defaulting to 50 in this task and a test for it.

- [ ] **Step 2: Write the failing test** — `tests/Moderation/AppealsListTest.php` exercising `GET /buddynext/v1/appeals` as admin returns pending appeals and is capped at 50:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Moderation\ModerationController::list_appeals
 */
class AppealsListTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_list_appeals_requires_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/appeals' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_list_appeals_returns_pending_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		( new ModerationService() )->create_appeal( $user, 'Please reconsider' );

		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/appeals' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data );
	}
}
```

- [ ] **Step 3: Run it; expect** the admin test to pass already (endpoint exists) — this is a characterization test that must stay green through the refactor. If `create_appeal` requires an active suspension, create one first via `ModerationService::suspend_user()`; read the method signature before writing the setup.

- [ ] **Step 4: Replace** the raw `$wpdb` block in `list_appeals()` with `$appeals = ( new ModerationService() )->get_pending_appeals();` and serialize. Keep the `require_admin` permission_callback and response envelope identical.
- [ ] **Step 5:** `grep -n "bn_appeals" includes/Moderation/ModerationController.php` — expected: no raw SELECT remains.
- [ ] **Step 6:** `php -l`; `mcp__wpcs__wpcs_check_file`; run `--filter AppealsListTest` — green.
- [ ] **Step 7: Commit** — `refactor(moderation): list_appeals delegates to ModerationService::get_pending_appeals`.

---

## Task 4: Move space-ban listing into `SpaceMemberService`

**Why:** `ModerationController::list_space_bans()` reads `bn_space_bans` directly. The writes (`ban_from_space`/`unban_from_space`) already live in `SpaceMemberService`; the read should too.

**Files:**
- Modify: `includes/Spaces/SpaceMemberService.php` (add `get_space_bans`)
- Modify: `includes/Moderation/ModerationController.php` (delegate)
- Create test: `tests/Spaces/SpaceBansListTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Spaces/SpaceBansListTest.php`:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Spaces;

use BuddyNext\Core\Installer;
use BuddyNext\Spaces\SpaceMemberService;

/**
 * @covers \BuddyNext\Spaces\SpaceMemberService::get_space_bans
 */
class SpaceBansListTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_get_space_bans_returns_banned_users(): void {
		$svc      = new SpaceMemberService();
		$space_id = 1;
		$user_id  = self::factory()->user->create();
		$svc->ban_from_space( $space_id, $user_id, 0, 'spam' );

		$bans = $svc->get_space_bans( $space_id );
		$this->assertCount( 1, $bans );
		$this->assertSame( $user_id, (int) $bans[0]['user_id'] );
	}

	public function test_get_space_bans_caps_results(): void {
		$svc = new SpaceMemberService();
		$bans = $svc->get_space_bans( 1, 50 );
		$this->assertLessThanOrEqual( 50, count( $bans ) );
	}
}
```

Before writing, read `SpaceMemberService::ban_from_space()` (around line 1133) to confirm the `bn_space_bans` columns and the `ban_from_space` signature; adjust the test's `ban_from_space(...)` args to match exactly.

- [ ] **Step 2: Run it; expect failure** — "undefined method get_space_bans".
- [ ] **Step 3: Read** the raw query in `ModerationController::list_space_bans()` (around lines 1405–1411). Add to `SpaceMemberService`, preserving its columns/order and adding a `LIMIT` per SCALE-CONTRACT:

```php
/**
 * List active bans for a space.
 *
 * @param int $space_id Space id.
 * @param int $limit    Max rows (SCALE-CONTRACT: capped).
 * @return array<int,array<string,mixed>>
 */
public function get_space_bans( int $space_id, int $limit = 50 ): array {
	global $wpdb;
	if ( $space_id <= 0 ) {
		return array();
	}
	$limit = max( 1, min( 50, $limit ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_space_bans WHERE space_id = %d ORDER BY created_at DESC LIMIT %d",
			$space_id,
			$limit
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}
```

- [ ] **Step 4: Run the test; expect PASS.**
- [ ] **Step 5: Delegate** in `ModerationController::list_space_bans()`: replace the raw query with `$bans = ( new \BuddyNext\Spaces\SpaceMemberService() )->get_space_bans( $space_id );` (the controller already calls `SpaceMemberService` for ban/unban — reuse the import). Keep permission_callback + envelope.
- [ ] **Step 6:** `grep -n "bn_space_bans" includes/Moderation/ModerationController.php` — expected: empty.
- [ ] **Step 7:** `php -l` both; `mcp__wpcs__wpcs_check_file` both; run `--filter 'SpaceBansList'`.
- [ ] **Step 8: Commit** — `refactor(spaces): SpaceMemberService::get_space_bans; controller delegates`.

---

## Task 5: Add the four missing app-readiness REST endpoints

**Why:** App-readiness. Each capability exists in the service/admin but a mobile client can't reach it.

Add these under `buddynext/v1`, all on `ModerationController` (extending `BaseRestController` from Task 1):

| Route | Method | Permission | Backed by |
|---|---|---|---|
| `/me/appeals` | GET | `require_auth` | new `ModerationService::get_user_appeals( $user_id )` |
| `/users/(?P<id>[\d]+)/warnings` | GET | `require_admin` | new `ModerationService::get_warnings( $user_id )` |
| `/users/(?P<id>[\d]+)/shadow-ban` | GET | `require_admin` | existing `ModerationService::is_shadow_banned()` |
| `/users/(?P<id>[\d]+)/suspensions` | GET | `require_admin` | existing `ModerationService::get_active_suspensions()` (filter to user) or new `get_user_suspensions( $user_id )` |

**Files:**
- Modify: `includes/Moderation/ModerationService.php` (add `get_user_appeals`, `get_warnings`, `get_user_suspensions`)
- Modify: `includes/Moderation/ModerationController.php` (register 4 routes + 4 handlers)
- Create test: `tests/Moderation/ModerationReadEndpointsTest.php`

- [ ] **Step 1: Write the failing test** covering all four endpoints (auth gating + happy path). Example for `/me/appeals`:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Moderation;

use BuddyNext\Core\Installer;
use BuddyNext\Moderation\ModerationService;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Moderation\ModerationController
 */
class ModerationReadEndpointsTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_me_appeals_lists_own_appeals(): void {
		$user = self::factory()->user->create();
		( new ModerationService() )->create_appeal( $user, 'mine' );
		wp_set_current_user( $user );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/appeals' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}

	public function test_me_appeals_requires_login(): void {
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/me/appeals' ) );
		$this->assertSame( 401, $response->get_status() );
	}

	public function test_user_warnings_requires_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'GET', '/buddynext/v1/users/1/warnings' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}

	public function test_shadow_ban_status_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		( new ModerationService() )->shadow_ban( $user );
		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/shadow-ban" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['shadow_banned'] );
	}

	public function test_user_suspensions_for_admin(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		( new ModerationService() )->suspend_user( $user, $admin, 'spam', 7, false );
		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/buddynext/v1/users/{$user}/suspensions" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data() );
	}
}
```

Before finalizing, read the real signatures of `create_appeal`, `shadow_ban`, and `suspend_user` and align the test setup calls exactly.

- [ ] **Step 2: Run it; expect failure** — routes 404 / methods undefined.

- [ ] **Step 3: Add service methods** to `ModerationService` (reuse existing query patterns; all capped per SCALE-CONTRACT):

```php
/**
 * List a user's own appeals (any status), newest first.
 *
 * @param int $user_id User id.
 * @param int $limit   Cap.
 * @return array<int,array<string,mixed>>
 */
public function get_user_appeals( int $user_id, int $limit = 50 ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return array();
	}
	$limit = max( 1, min( 50, $limit ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_appeals WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
			$user_id,
			$limit
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}

/**
 * List warning log entries for a user, newest first.
 *
 * @param int $user_id User id.
 * @param int $limit   Cap.
 * @return array<int,array<string,mixed>>
 */
public function get_warnings( int $user_id, int $limit = 50 ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return array();
	}
	$limit = max( 1, min( 50, $limit ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_mod_log WHERE object_type = 'user' AND object_id = %d AND action IN ( 'warn', 'warned' ) ORDER BY created_at DESC LIMIT %d",
			$user_id,
			$limit
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}

/**
 * List a single user's active suspensions, newest first.
 *
 * @param int $user_id User id.
 * @return array<int,array<string,mixed>>
 */
public function get_user_suspensions( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return array();
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_user_suspensions WHERE user_id = %d AND lifted_at IS NULL ORDER BY created_at DESC LIMIT 50",
			$user_id
		),
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : array();
}
```

Read the real `bn_mod_log` and `bn_appeals` column names in Step 1 and adjust (`object_id` vs `target_user_id`, etc.) before writing.

- [ ] **Step 4: Register routes + handlers** in `ModerationController::register_routes()`. Pattern for each (uses base permission helpers):

```php
register_rest_route(
	'buddynext/v1',
	'/me/appeals',
	array(
		'methods'             => \WP_REST_Server::READABLE,
		'callback'            => array( $this, 'list_my_appeals' ),
		'permission_callback' => array( $this, 'require_auth' ),
	)
);
```

```php
/**
 * GET /me/appeals — the current user's appeals.
 */
public function list_my_appeals( \WP_REST_Request $request ): \WP_REST_Response {
	$appeals = ( new ModerationService() )->get_user_appeals( get_current_user_id() );
	return new \WP_REST_Response( $appeals, 200 );
}
```

Repeat for `/users/{id}/warnings` (`require_admin` → `get_warnings`), `/users/{id}/shadow-ban` GET (`require_admin` → `array( 'shadow_banned' => $svc->is_shadow_banned( $id ) )`), `/users/{id}/suspensions` (`require_admin` → `get_user_suspensions`). Use `(?P<id>[\d]+)` and read the id via `(int) $request['id']`.
  - Note: `/users/{id}/shadow-ban` already has POST + DELETE; you are ADDING a GET method to the same route array — append a third entry, don't create a duplicate route.

- [ ] **Step 5: Run the test; expect PASS.**
- [ ] **Step 6:** `php -l` both; `mcp__wpcs__wpcs_check_file` both; `bin/check-rest-boundary.sh` (stays green).
- [ ] **Step 7: Commit** — `feat(moderation): add /me/appeals, /users/{id}/warnings, /users/{id}/shadow-ban GET, /users/{id}/suspensions`.

---

## Task 6: Pro lockstep — `BulkModAdmin` uses the service, not raw SQL

**Why:** Pro's `Admin/BulkModAdmin.php` queries Free's `bn_reports` table with raw SQL (~lines 244–262). It must call `ModerationService::get_queue()` so the free/pro seam survives Free-side changes. This is the only Pro moderation change needed — the two filter contracts and `BulkModService`'s service calls are already clean.

**Files:**
- Modify: `/Users/vapvarun/dev/repos/buddynext-pro/includes/Admin/BulkModAdmin.php`

- [ ] **Step 1: Read** `ModerationService::get_queue()` (Free, ~line 509) — confirm its params (`per_page`, `page`, optional filters) and return shape (`array{ items: array, total: int }` or similar). Confirm it returns the reporter info `BulkModAdmin` needs (reporter id/login); if `get_queue()` doesn't include the reporter login that the admin table shows, either (a) hydrate the login in the admin from `get_userdata()` per row, or (b) confirm `get_queue()` already includes it. Prefer (a) — do not widen the service return shape just for one admin screen.
- [ ] **Step 2: Read** `BulkModAdmin::render_content()` raw query block (~lines 244–262) and note the pagination (`self::PER_PAGE`, `$offset`) and the columns the table renders.
- [ ] **Step 3: Replace** the raw `$wpdb->get_results(...)` with:

```php
$mod   = buddynext_service( 'moderation' );
$page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
$queue = $mod->get_queue( array( 'per_page' => self::PER_PAGE, 'page' => $page ) );
$reports = $queue['items'] ?? array();
```

Match `get_queue()`'s real argument signature from Step 1 (it may take positional args, not an array — adapt accordingly). Then, in the row loop, fetch reporter login via `get_userdata( (int) $report['reporter_id'] )` if the service row lacks it.

- [ ] **Step 4:** Confirm no raw `bn_reports` query remains: `grep -n "bn_reports" /Users/vapvarun/dev/repos/buddynext-pro/includes/Admin/BulkModAdmin.php` — expected: empty (or only a comment).
- [ ] **Step 5:** `php -l` the file; `mcp__wpcs__wpcs_check_file` on it.
- [ ] **Step 6: Browser-verify** the Pro bulk-moderation admin screen renders the pending-reports table identically:
  - Create a report via `POST /buddynext/v1/reports` (or seed one), then navigate (Playwright MCP) to the Bulk Moderation admin page with `?autologin=1`, screenshot, confirm the report appears and pagination works.
- [ ] **Step 7: Commit** (in the buddynext-pro repo) — `refactor(moderation): BulkModAdmin reads queue via ModerationService, not raw SQL`.

---

## Task 7: warn() / log_warning() — decide and document (no blind merge)

**Why:** The two methods both write `bn_mod_log` and fire `buddynext_user_warned`, but with different capability models (`warn()` enforces caps; `log_warning()` is a thin trusted-caller API) and different arg order. Per the consolidation-bloat test, only merge if it reduces concepts without breaking callers. Pro's `BulkModService::bulk_warn()` calls `warn()`.

**Files:**
- Modify: `includes/Moderation/ModerationService.php` (doc only, unless merge is clearly safe)

- [ ] **Step 1:** Grep every caller of both across BOTH repos:
  `grep -rn "->warn(\|->log_warning(" /Users/vapvarun/dev/repos/buddynext /Users/vapvarun/dev/repos/buddynext-pro --include='*.php'`.
- [ ] **Step 2: Decide:**
  - If `log_warning()` has exactly one internal caller and no Pro caller, fold it into `warn()` (make `warn()` accept an optional `$enforce_caps = true` flag; have the REST handler pass `true`, the thin caller pass `false`), update callers, keep one hook fire. Add a test asserting both capability paths.
  - If `log_warning()` has multiple distinct callers relying on the no-caps behaviour, KEEP both and add a one-line docblock on each pointing to the other (`@see`) explaining the intentional split. Do not merge.
- [ ] **Step 3:** Whichever path: run `--filter 'Moderation'` and confirm green; if merging, add the capability-path test first (TDD) before changing `warn()`.
- [ ] **Step 4: Commit** — `refactor(moderation): unify warning entry point` OR `docs(moderation): document warn()/log_warning() split`.

---

## Task 8: Full Moderation flow verification (Free + Pro)

**No code changes — verification only.** Walk the flow front-door inward, in the browser and over REST.

- [ ] **REST suite:** `WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 vendor/bin/phpunit --filter 'Moderation|Appeals|ContentWarning|SpaceBans|BaseRestController'` — all green.
- [ ] **Static gates:** `bin/check.sh` in the Free repo — php -l, WPCS, REST-boundary, PHPStan L5, ux-audit all pass (report any pre-existing failures honestly).
- [ ] **Member report flow (browser):** `?autologin=1` → open a post → report it → confirm `POST /buddynext/v1/reports` returns 201 (Playwright network check) and the report lands in the queue.
- [ ] **Admin action flow (browser):** open wp-admin Moderation queue → dismiss/resolve a report → confirm no PHP errors, status updates.
- [ ] **Suspension + appeal flow:** suspend a test user (REST `POST /users/{id}/suspend`) → as that user, submit an appeal (`POST /me/appeals`) → as that user, `GET /me/appeals` returns it (new endpoint) → as admin, `GET /appeals` lists it → approve → confirm suspension lifted (`GET /users/{id}/suspension` null/lifted) and the appellant gets a notification (check Mailpit at http://localhost:10010/).
- [ ] **Shadow-ban + content-warning:** shadow-ban a user, `GET /users/{id}/shadow-ban` → `true`; set a content warning on a post (`PUT /posts/{id}/content-warning`), `GET` it back.
- [ ] **Pro:** open the Bulk Moderation admin screen → confirm the reports table renders (Task 6) and a bulk dismiss works.
- [ ] **No raw DB in controllers:** `grep -rnE "\\\$wpdb" includes/Moderation/ModerationController.php` — expected: zero matches.
- [ ] **Console clean:** `mcp__plugin_playwright_playwright__browser_console_messages` — zero errors across the screens visited.

---

## Task 9: Update REST inventory + CLAUDE.md, close the flow

**Files:**
- Modify: `docs/specs/REST-INVENTORY.md`
- Modify: `CLAUDE.md` (Recent Changes)

- [ ] Regenerate the Free REST surface and update `REST-INVENTORY.md`:
  `grep -rn register_rest_route includes/ --include="*.php"` — add the 4 new GET rows (`/me/appeals`, `/users/{id}/warnings`, `/users/{id}/shadow-ban` GET, `/users/{id}/suspensions`) in the correct alphabetical position with their permission + source links.
- [ ] Add to `CLAUDE.md` Recent Changes:
  - Moderation flow: removed all raw `$wpdb` from `ModerationController`; content-warning, appeals-list, space-ban-list now go through services.
  - Added `REST/BaseRestController` (shared `require_auth`/`require_admin`); `ModerationController` adopts it.
  - Added 4 app-readiness moderation read endpoints.
  - Pro `BulkModAdmin` reads the queue via `ModerationService::get_queue()`.
- [ ] **Commit** — `docs: moderation flow REST inventory + CLAUDE.md`.

---

## Self-Review Checklist (run before handing off)

- [ ] Every raw `$wpdb` in `ModerationController` is gone (Task 8 grep proves it).
- [ ] No file was split for size. `ModerationService` stays one class.
- [ ] All 4 new endpoints have a `permission_callback` and a `LIMIT`-capped backing query (SCALE-CONTRACT).
- [ ] `bin/check-rest-boundary.sh` green — no admin-ajax introduced.
- [ ] Pro filter contracts untouched; only the `BulkModAdmin` raw-query seam changed.
- [ ] Method names are consistent across tasks (`get_post_content_warning`, `set_post_content_warning`, `get_user_appeals`, `get_warnings`, `get_user_suspensions`, `get_space_bans`).
- [ ] No version bumps; no Claude co-author trailer.

---

## Subsequent flows (roadmap — separate plans, written when this one lands)

Same vertical template, in priority order: **Feed + Comments** (PostRepository for the `bn_posts` counter writes; cursor/cache per SCALE-CONTRACT; full compose/read/react/comment REST), **Profile** (visibility + search-mirror + completion as cohesive methods, full profile REST), then Spaces, SocialGraph, Notifications. Each reuses `BaseRestController` from this plan. Each ends with the same Task 8-style end-to-end verification. Free + Pro reconciled in lockstep per flow.
