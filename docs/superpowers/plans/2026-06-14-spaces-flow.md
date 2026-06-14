# Spaces Flow — Layer, Hook-Reconciliation & App-Readiness Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]`.

**Goal:** Bring Spaces in line with Moderation/Feed/Profile: `SpaceController` becomes `$wpdb`-free by delegating its ban/unban/remove/transfer writes to services, the duplicate/divergent hook fires are reconciled to one canonical hook per event, controllers share `BaseRestController`, two REST gaps close, and Pro's direct `bn_spaces` reads route through `SpaceService`. Free + Pro in lockstep. No file splits.

**Architecture:** Flow-first. `SpaceService`/`SpaceMemberService` are confirmed cohesive (dual ban interfaces are intentional for Pro gating — no split). The crux is `SpaceController`'s 5 raw `$wpdb` sites, several of which **duplicate** logic the service already owns AND **double-fire** hooks the service also fires.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6 + WP_Test_REST_TestCase, WPCS, PHPStan 5. Harness: [[reference_bn_phpunit_harness]].

**Hook reconciliation (CRITICAL — read before Task 2):**
- `buddynext_space_member_removed` — **the canonical removal hook; one live consumer** (`Sidebar/WidgetListener.php:59`, 2 args). Fired by the controller (`remove_member`, 1261) AND `SpaceMemberService::ban()` (519).
- `buddynext_member_removed_from_space` — fired only by `SpaceMemberService::remove()` (836); **zero consumers (orphan)**. Consolidate onto the canonical hook.
- `buddynext_space_user_banned` / `_unbanned` — **zero consumers** in either repo. Controller and service both fire them with differing arg counts; the service's version wins on delegation (harmless, no consumer).

**What the investigation found (SpaceController $wpdb, line refs approximate — re-read before editing):**
1. `ban_user()` (~1046–1108): INSERT `bn_space_bans` + DELETE `bn_space_members` + UPDATE `member_count`, then fires `buddynext_space_user_banned`. → `SpaceMemberService::ban_from_space()` already does all this and fires the hook.
2. `unban_user()` (~1153–1175): DELETE `bn_space_bans` + fires hook. → `SpaceMemberService::unban_from_space()`.
3. `remove_member()` (~1231–1261): DELETE `bn_space_members` + member_count + fires `buddynext_space_member_removed`. → `SpaceMemberService::remove()` (but see hook reconciliation).
4. `transfer_ownership()` (~1297–1313): raw UPDATE `bn_spaces.owner_id` (+ `change_role`). → new `SpaceService::transfer_ownership()`.
5. `join_space()` (~761–769): raw SELECT `bn_space_bans` ban check. → `SpaceMemberService::is_banned_from_space()` (public alias of `is_hard_banned`).
- BaseRestController: `SpaceController` (class:40) has local `require_auth` (1497). `SpaceCategoryController` (class:25) has a custom `require_manage_options` (269) — keep, but extend the base.
- REST gaps: **no `PUT /space-categories/{id}`** (can create/delete, not edit); **no cancel-pending-join endpoint** (`SpaceMemberService::cancel_request()` exists). Note: `GET /spaces/{id}/bans` already exists (added to `ModerationController` in the moderation flow) — do NOT duplicate.
- Pro reads `bn_spaces` directly with `$wpdb` in `WhiteLabel/BrandService.php` (owner_id), `Membership/PaywallIntegration.php` (required_ability), `Realtime/RealtimeAssets.php` (slug→id). Route through `SpaceService::get()`/`get_by_slug()`.

**Non-goals:** No service split. No change to the stable Pro filter seams (`buddynext_can_join_space`, paywall, settings-tab). `SpaceCategoryController`'s own `bn_space_categories` queries stay (it's the data layer for that small table; no service exists or is needed).

---

## CRITICAL — Read Before Each Task

- `php -l` + `php -d xdebug.mode=off vendor/bin/phpcs --standard=phpcs.xml.dist <files>` on every changed file; `includes/` stays phpcs-clean (note: `SpaceController` may carry pre-existing debt — compare to baseline, only ensure no NEW errors). Match existing test style in `tests/`.
- Behaviour-preserving except the deliberate hook consolidation and 2 additive endpoints. No version bumps, no Claude co-author trailer. Commit per task on green.

---

## Task 1: Space controllers extend BaseRestController

**Files:** `includes/Spaces/SpaceController.php`, `includes/Spaces/SpaceCategoryController.php`

- [ ] Confirm no test pins controller auth error codes (only statuses): `grep -rn "get_error_code" tests/Spaces`.
- [ ] `SpaceController`: add `use BuddyNext\REST\BaseRestController;`, `class SpaceController extends BaseRestController`, delete the local `require_auth` (1497). Keep any space-specific permission methods.
- [ ] `SpaceCategoryController`: add the use + `extends BaseRestController`. Keep its `require_manage_options` (custom name used by its routes) OR switch its routes to the base `require_admin` — prefer keeping `require_manage_options` to avoid touching every route arg; just make it delegate: `return $this->require_admin();`.
- [ ] `php -l` both; phpcs (no NEW errors vs baseline — stash-compare if needed); `grep -n "function require_auth" includes/Spaces/SpaceController.php` → none.
- [ ] Run `--filter 'SpaceController|SpaceCategory|BaseRestController'` → green.
- [ ] Commit — `refactor(rest): Space controllers extend BaseRestController`.

---

## Task 2: Make SpaceController $wpdb-free (delegate writes + reconcile hooks)

**Files:** `includes/Spaces/SpaceController.php`, `includes/Spaces/SpaceMemberService.php`, `includes/Spaces/SpaceService.php`, tests.

**Step A — reconcile the removal hook (do this FIRST so delegation is safe):**
- [ ] In `SpaceMemberService::remove()` (~836), change the fired hook from `buddynext_member_removed_from_space` to **`buddynext_space_member_removed`** with args `( $space_id, $user_id, $acting_user_id )` (matches the controller's current fire and keeps `WidgetListener` working — it uses the first 2 args). The orphan hook had zero consumers (verified), so this is a safe consolidation.
- [ ] Grep both repos to be sure nothing references the orphan: `grep -rn "buddynext_member_removed_from_space" includes/ ../buddynext-pro/includes/` → only the (now-changed) fire site.

**Step B — add `SpaceService::transfer_ownership()`:**
- [ ] Read the controller's `transfer_ownership()` (~1272–1313) to capture exactly what it does (validate new owner is a member, `change_role` old→member / new→owner, UPDATE `bn_spaces.owner_id`, fire any hook, bust cache).
- [ ] TDD: add `SpaceService::transfer_ownership( int $space_id, int $new_owner_id, int $actor_id ): true|WP_Error` that encapsulates the owner_id UPDATE + cache bust (and delegates the role swaps to `SpaceMemberService::change_role` or does them inline consistently with the controller's current behaviour). Fire `buddynext_space_ownership_transferred` if the controller already fires one; otherwise keep parity. Test: a member becomes owner; non-member → WP_Error; cache busted.

**Step C — delegate each controller method and DROP its duplicate $wpdb + do_action:**
- [ ] `ban_user()` → `$result = buddynext_service( 'space_members' )->ban_from_space( $space_id, $ban_user_id, $actor_id, $reason );` Remove the raw INSERT/DELETE/UPDATE block AND the controller's `do_action( 'buddynext_space_user_banned', ... )` (the service fires it). Preserve the response envelope + permission checks.
- [ ] `unban_user()` → `buddynext_service( 'space_members' )->unban_from_space( $space_id, $ban_user_id );` remove raw DELETE + the controller `do_action`.
- [ ] `remove_member()` → `buddynext_service( 'space_members' )->remove( $space_id, $target_id, $actor_id );` remove raw DELETE/UPDATE + the controller `do_action( 'buddynext_space_member_removed', ... )` (now fired by `remove()` per Step A). Confirm `remove()`'s return type and map it to the response.
- [ ] `transfer_ownership()` → `buddynext_service( 'spaces' )->transfer_ownership( $space_id, $new_owner_id, $actor_id );` remove raw UPDATE.
- [ ] `join_space()` ban check → replace the raw SELECT with `buddynext_service( 'space_members' )->is_banned_from_space( $space_id, $user_id )` (confirm this public method exists; if only `is_hard_banned` private exists, add a thin public `is_banned_from_space`).

**Step D — verify:**
- [ ] `grep -nE "\\\$wpdb" includes/Spaces/SpaceController.php` → 0.
- [ ] Existing `SpaceControllerTest` + `SpaceMemberServiceTest` green (these exercise ban/remove/transfer/join). Add a regression test asserting the removal hook still fires for `WidgetListener` (or that a removed member's cache is busted) and that ban/unban/remove via REST return the same statuses as before.
- [ ] phpcs: no NEW errors vs baseline.
- [ ] Commit — `refactor(spaces): SpaceController delegates ban/unban/remove/transfer to services; reconcile removal hook`.

---

## Task 3: REST gaps — category update + cancel join request

**Files:** `includes/Spaces/SpaceCategoryController.php`, `includes/Spaces/SpaceController.php`, tests.

- [ ] **PUT /space-categories/{id}:** read the existing `create_category`/`delete_category` to mirror permission (`require_manage_options`) and the `bn_space_categories` columns. Add an `update_category()` handler (name/description/sort_order). Since `SpaceCategoryController` owns this small table directly (no service), the UPDATE stays in the controller — consistent with its create/delete. TDD: admin can rename a category; non-admin gets 401/403.
- [ ] **Cancel join request:** add `POST /spaces/{id}/join/cancel` (or `DELETE /spaces/{id}/request`) → handler calls `buddynext_service( 'space_members' )->cancel_request( $space_id, get_current_user_id() )` (confirm signature). `require_auth`. TDD: a pending requester can cancel; result clears the pending row.
- [ ] `bin/check-rest-boundary.sh` clean. Commit — `feat(spaces): PUT /space-categories/{id} + cancel join request endpoint`.

---

## Task 4: Pro lockstep — route Pro's direct bn_spaces reads through SpaceService

**Files (Pro):** `includes/WhiteLabel/BrandService.php`, `includes/Membership/PaywallIntegration.php`, `includes/Realtime/RealtimeAssets.php`.

- [ ] Confirm how Pro resolves Free's space service (`buddynext_service( 'spaces' )` — the container key is `spaces`, verified in Free `Plugin.php:717`). Confirm `SpaceService::get( $id )` returns `owner_id` and `SpaceService::get_by_slug( $slug )` exists and returns `id`.
- [ ] `BrandService::can_manage_space_brand()` (~423): replace `SELECT owner_id FROM bn_spaces` with `$space = buddynext_service( 'spaces' )->get( $space_id ); $owner = (int) ( $space['owner_id'] ?? 0 );`.
- [ ] `PaywallIntegration::required_ability_for_space()` (~248): replace `SELECT required_ability` with `buddynext_service( 'spaces' )->get( $space_id )['required_ability'] ?? ''` (confirm `get()` returns this column; if `get()` doesn't hydrate `required_ability`, leave this one with a documented note rather than break it).
- [ ] `RealtimeAssets` (~199): replace slug→id `SELECT` with `buddynext_service( 'spaces' )->get_by_slug( $slug )['id'] ?? 0`.
- [ ] Verify the paywall filter contract exists in Free: `grep -rn "buddynext_space_join_denied_data" includes/` — if Free never fires it, note the gap (do not fix Free's join-denial path in this flow; just record it).
- [ ] `php -l` + phpcs each Pro file. `grep -rn "bn_spaces" buddynext-pro/includes/ | grep wpdb` → only `MembershipAdmin` (admin-only, lower priority — leave with a note) remains, if any.
- [ ] Browser-smoke a space settings page with the Brand tab if reachable (else rely on the trivial delegation + Free `SpaceService` tests). Commit (Pro) — `refactor(spaces): read bn_spaces via Free SpaceService, not raw SQL`.

---

## Task 5: Verification + docs

- [ ] Full suite: `--filter 'Space|SpaceMember|SpaceCategory|BaseRestController'` — green (note pre-existing unrelated failures honestly).
- [ ] `bin/check-rest-boundary.sh` clean; PHPStan project config `[OK]`.
- [ ] `grep -nE "\\\$wpdb" includes/Spaces/SpaceController.php` → 0; `grep -n "function require_auth" includes/Spaces/SpaceController.php` → none.
- [ ] Browser-smoke: a space page, join/leave, and the space members list — no console errors.
- [ ] Update `docs/specs/REST-INVENTORY.md` (PUT /space-categories/{id}, cancel-join). Update `CLAUDE.md` Recent Changes (controller $wpdb-free, hook reconciliation, 2 endpoints, Pro reads via service).
- [ ] Commit — `docs: spaces flow REST inventory + CLAUDE.md`.

---

## Self-Review

- [ ] `SpaceController` is `$wpdb`-free; no duplicate hook fires; `buddynext_space_member_removed` still reaches `WidgetListener`.
- [ ] No service split. Dual ban interfaces preserved.
- [ ] 3 controllers (incl. category) on `BaseRestController`.
- [ ] Pro reads `bn_spaces` via `SpaceService`, not raw SQL (admin-only `MembershipAdmin` read may remain, noted).
- [ ] No version bumps, no co-author trailer.
