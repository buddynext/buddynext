# Profile Flow — Layer & App-Readiness Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]`.

**Goal:** Bring the Profile flow in line with the Moderation/Feed flows: controllers share `BaseRestController`, the one app-readiness REST gap is closed, and Pro's single direct table read goes through a Free service seam. Free + Pro in lockstep. No file splits.

**Architecture:** Flow-first. The Profile flow is already in good shape — `ProfileController`/`MemberDirectoryController`/`MemberTypeController` are `$wpdb`-free, and `ProfileService` (1,615 LOC) is confirmed cohesive (search-mirror + visibility-clamp are interwoven with save/get; extracting them would add coupling, not remove it — leave whole). So this is a small flow.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6 + WP_Test_REST_TestCase, WPCS, PHPStan 5. Harness: see [[reference_bn_phpunit_harness]].

**What the investigation found:**
- **BaseRestController not adopted:** `ProfileController` (class:44) has local `require_admin` (1350) + `require_auth` (1367); `MemberTypeController` (class:29) has local `require_admin` (340) + a custom `can_set_user_type` (357, keep). `MemberDirectoryController` (class:43) has no auth methods.
- **One REST gap (app-readiness):** `DELETE /me/avatar` exists, and `POST /users/{id}/avatar` (admin) exists, but there is **no `DELETE /users/{id}/avatar`** — admins can upload but not remove a user's avatar. `ProfileService::delete_avatar( int $user_id )` (1541) already exists.
- **Pro:** entirely hook-based (no Pro controllers/REST/table-writes). One exception — `AdvancedFieldRenderer::resolve_field_key()` (line 450) runs a raw `SELECT field_key FROM bn_profile_fields WHERE id = %d`. Route it through a new Free `ProfileService::get_field_key()`.
- **Deferred minor gap:** no post-upload cover focal-point adjustment endpoint (focal is set on upload only). Low priority; documented, not implemented this flow.

**Non-goals:** No `ProfileService` split. No change to the stable Pro field-type filter seams. No focal-point endpoint.

---

## CRITICAL — Read Before Each Task

- `php -l` + `php -d xdebug.mode=off vendor/bin/phpcs --standard=phpcs.xml.dist <files>` on every changed file; `includes/` stays phpcs-clean; match existing test style in `tests/`.
- Behaviour-preserving + one additive endpoint. No version bumps, no Claude co-author trailer. Commit per task on green.
- Run tests: `WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 vendor/bin/phpunit --filter <Test>` (mysql client on PATH).

---

## Task 1: Profile controllers extend BaseRestController

**Files:**
- Modify: `includes/Profile/ProfileController.php` (extend base; delete local `require_admin`/`require_auth`)
- Modify: `includes/MemberTypes/MemberTypeController.php` (extend base; delete local `require_admin`; KEEP `can_set_user_type`)
- Modify: `includes/Profile/MemberDirectoryController.php` (extend base for consistency; no methods to remove)

- [ ] **Step 1:** Confirm no test pins error codes for these controllers (only statuses): `grep -rn "get_error_code" tests/Profile tests/MemberTypes`. If any assert a specific 401/403 code, preserve it.
- [ ] **Step 2:** For each controller: add `use BuddyNext\REST\BaseRestController;`, change `class X {` → `class X extends BaseRestController {`. Read the local `require_admin`/`require_auth` bodies first; delete them (they duplicate the base — base's `require_admin` returns 403/401 on `manage_options`, matching). For `MemberTypeController`, delete only `require_admin`; keep `can_set_user_type`.
- [ ] **Step 3:** `php -l` all three; phpcs clean; confirm none still declare `require_auth`/`require_admin`: `grep -rn "function require_auth\|function require_admin" includes/Profile includes/MemberTypes`.
- [ ] **Step 4:** Run `--filter 'ProfileController|MemberType|MemberDirectory|FollowersController|FollowingController|ProfileControllerPrivacy|BaseRestController'` → green (the existing Profile test suite exercises auth-gating).
- [ ] **Step 5: Commit** — `refactor(rest): Profile controllers extend BaseRestController`.

---

## Task 2: Add DELETE /users/{id}/avatar (admin)

**Files:**
- Modify: `includes/Profile/ProfileController.php` (register route + handler)
- Test: `tests/Profile/AdminAvatarDeleteTest.php`

- [ ] **Step 1: Read** the existing `POST /users/(?P<id>[\d]+)/avatar` registration (~line 79) and the `/me/avatar` DELETE handler `delete_avatar()` (1059) to mirror the response envelope and the admin permission pattern used by the POST.
- [ ] **Step 2: Write the failing test** — `tests/Profile/AdminAvatarDeleteTest.php` (WP_Test_REST_TestCase): admin DELETE `/buddynext/v1/users/{id}/avatar` returns 200; non-admin gets 401/403. Seed an avatar via `update_user_meta( $user, 'bn_avatar', 'http://x/a.png' )` (confirm the real meta key in `ProfileService::update_avatar` first), assert it's cleared after.

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Profile;

use BuddyNext\Core\Installer;
use WP_REST_Request;

/**
 * @covers \BuddyNext\Profile\ProfileController
 */
class AdminAvatarDeleteTest extends \WP_Test_REST_TestCase {

	public function set_up(): void {
		parent::set_up();
		Installer::run();
	}

	public function test_admin_can_delete_user_avatar(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user  = self::factory()->user->create();
		update_user_meta( $user, 'bn_avatar', 'http://example.test/a.png' );

		wp_set_current_user( $admin );
		$response = rest_do_request( new WP_REST_Request( 'DELETE', "/buddynext/v1/users/{$user}/avatar" ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', (string) get_user_meta( $user, 'bn_avatar', true ) );
	}

	public function test_non_admin_cannot_delete_user_avatar(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$response = rest_do_request( new WP_REST_Request( 'DELETE', '/buddynext/v1/users/1/avatar' ) );
		$this->assertContains( $response->get_status(), array( 401, 403 ) );
	}
}
```

- [ ] **Step 3:** Add a DELETE method to the existing `/users/(?P<id>[\d]+)/avatar` route array (WP merges; mirror the POST's `require_admin` permission), with handler `admin_delete_avatar( WP_REST_Request $request )` that calls `( buddynext_service( 'profiles' ) )->delete_avatar( (int) $request['id'] )` and returns `{ deleted: true, user_id }`. Confirm the avatar meta key by reading `ProfileService::update_avatar`/`delete_avatar` and the `/me/avatar` handler.
- [ ] **Step 4:** Run `--filter AdminAvatarDeleteTest` → green. `bin/check-rest-boundary.sh` clean.
- [ ] **Step 5: Commit** — `feat(profile): add DELETE /users/{id}/avatar (admin)`.

---

## Task 3: Pro lockstep — field_key via ProfileService seam

**Files:**
- Modify: `includes/Profile/ProfileService.php` (Free) — add `get_field_key()`
- Modify: `buddynext-pro/includes/Profile/AdvancedFieldRenderer.php` — route line 450
- Test: `tests/Profile/FieldKeyTest.php`

- [ ] **Step 1: Write the failing test** (Free) — `ProfileService::get_field_key( int $id ): string` returns the field_key for a seeded `bn_profile_fields` row, '' for a missing id.
- [ ] **Step 2: Add to `ProfileService`:**

```php
/**
 * Resolve a field's key by its numeric id. Empty string when not found.
 *
 * @param int $field_id Field id.
 * @return string
 */
public function get_field_key( int $field_id ): string {
	if ( $field_id <= 0 ) {
		return '';
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT field_key FROM {$wpdb->prefix}bn_profile_fields WHERE id = %d", $field_id )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
```

- [ ] **Step 3:** Run the Free test → green.
- [ ] **Step 4 (Pro):** In `AdvancedFieldRenderer::resolve_field_key()` (line 441–455), replace the raw `$wpdb` read with `return (string) buddynext_service( 'profiles' )->get_field_key( $field_id );`. Remove the now-unused `global $wpdb;` in that method. Confirm `grep -n "bn_profile_fields" buddynext-pro/includes/Profile/` shows no raw query left.
- [ ] **Step 5:** `php -l` + phpcs both files.
- [ ] **Step 6: Verify** — the `conditional` field type uses this path; if reachable on the running site, render a profile with a conditional field and confirm no error (else rely on the Free unit test + the trivial delegation). 
- [ ] **Step 7: Commit** — Free: `feat(profile): ProfileService::get_field_key`; Pro: `refactor(profile): resolve field_key via ProfileService`.

---

## Task 4: Verification + docs

- [ ] Full suite: `--filter 'Profile|MemberType|MemberDirectory|Followers|Following|FieldKey|AdminAvatar|BaseRestController'` — green (note pre-existing unrelated failures honestly).
- [ ] `bin/check-rest-boundary.sh` clean; PHPStan project config `[OK]`.
- [ ] `grep -rn "function require_auth\|function require_admin" includes/Profile includes/MemberTypes` → none (all on base).
- [ ] Browser-smoke a member profile page (`?autologin=1`) and the member directory — no console errors.
- [ ] Update `docs/specs/REST-INVENTORY.md` with the new `DELETE /users/{id}/avatar`. Update `CLAUDE.md` Recent Changes.
- [ ] Note the deferred minor gap (cover focal-point post-upload adjustment) in CLAUDE.md or a code comment near the cover handler.
- [ ] Commit — `docs: profile flow REST inventory + CLAUDE.md`.

---

## Self-Review

- [ ] No `ProfileService` split.
- [ ] 3 controllers on `BaseRestController`; `can_set_user_type` preserved.
- [ ] `DELETE /users/{id}/avatar` has `require_admin` + clears the avatar meta.
- [ ] Pro's only direct `bn_profile_fields` read now goes through `ProfileService::get_field_key`.
- [ ] No version bumps, no co-author trailer.
