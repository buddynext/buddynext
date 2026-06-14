# Notifications Flow — Base Adoption, Layer Cleanup & Pro Seam

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]`.

**Goal:** Finish the program — `NotificationController` extends `BaseRestController` and is free of raw `$wpdb`/`usermeta` (its 3 data sites move into `NotificationPrefService`), and Pro's `PushDispatcher` reads the notification row through a new Free `NotificationService::get()` instead of raw SQL. No file splits.

**Architecture:** Flow-first. Notifications is already clean — services cohesive, hooks fire only from `NotificationService` (no controller/service duplication, unlike Spaces), unread-count caching is scale-compliant, and REST coverage is complete (no app gaps). So this is a small layer-cleanup flow plus one Pro seam.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6 + WP_Test_REST_TestCase, WPCS, PHPStan 5. Harness: [[reference_bn_phpunit_harness]].

**What the investigation found:**
- **BaseRestController:** `NotificationController` (class:32) declares a local `require_auth` (516).
- **3 controller data sites** (the layer violation):
  - `get_notification_channels()` (352) reads `get_user_meta(bn_channel_prefs)` (354).
  - `update_notification_channels()` (383) reads + `update_user_meta(bn_channel_prefs)` (395, 406).
  - `list_space_notification_prefs()` (420) runs raw `$wpdb` joining `bn_spaces`+`bn_space_members` (421–430).
  `NotificationPrefService` already owns prefs; add `get_channel_prefs()`, `set_channel_prefs()`, `list_space_notification_prefs()`.
- **Pro:** `Push/PushDispatcher.php:128` does `$wpdb->get_row("SELECT ... FROM bn_notifications WHERE id = %d")` — there is no Free `NotificationService::get()`. Add it and route Pro. (Pro's own controllers re-rolling auth is a separate Pro-wide cleanup, out of scope here.)

**Non-goals:** No service split. No new app endpoints (coverage is complete). No change to hooks. No Pro controller base-adoption (separate effort).

---

## CRITICAL — Read Before Each Task
- `php -l` + `php -d xdebug.mode=off vendor/bin/phpcs --standard=phpcs.xml.dist <files>`; `includes/` stays phpcs-clean (no NEW errors vs baseline; `phpcbf` for auto-fixable array/format nits). Match existing test style.
- Behaviour-preserving. No version bumps, no co-author trailer. Commit per task on green.

---

## Task 1: NotificationController extends BaseRestController

**Files:** `includes/Notifications/NotificationController.php`

- [ ] `grep -rn "get_error_code" tests/Notifications` — confirm no test pins controller auth error codes.
- [ ] Add `use BuddyNext\REST\BaseRestController;`, `class NotificationController extends BaseRestController`, delete the local `require_auth` (516). Fix the class-close blank-line nit if it appears (`}\n}`).
- [ ] `php -l`; phpcs no NEW errors; `grep -n "function require_auth" includes/Notifications/NotificationController.php` → none.
- [ ] Run `--filter 'Notification|BaseRestController'` → green.
- [ ] Commit — `refactor(rest): NotificationController extends BaseRestController`.

---

## Task 2: Move channel + space-prefs data access into NotificationPrefService

**Files:** `includes/Notifications/NotificationPrefService.php` (add 3 methods), `includes/Notifications/NotificationController.php` (delegate), test.

- [ ] **Step 1: Read** the 3 controller methods (`get_notification_channels` 352, `update_notification_channels` 383, `list_space_notification_prefs` 420) to capture the exact meta key (`bn_channel_prefs`), the channel keys (`in_app, email, push, sound`), and the space-prefs query/response shape.
- [ ] **Step 2: Write failing tests** (`tests/Notifications/NotificationPrefServiceChannelsTest.php`): `set_channel_prefs` then `get_channel_prefs` round-trips a partial update (only provided keys change); `list_space_notification_prefs($user)` returns `[{space_id,name,slug,pref}]` for the user's active spaces.
- [ ] **Step 3: Add to `NotificationPrefService`:**

```php
/**
 * Read a user's notification channel toggles (in_app/email/push/sound).
 *
 * @param int $user_id User id.
 * @return array<string,bool>
 */
public function get_channel_prefs( int $user_id ): array {
	$stored   = get_user_meta( $user_id, 'bn_channel_prefs', true );
	$stored   = is_array( $stored ) ? $stored : array();
	$defaults = array( 'in_app' => true, 'email' => true, 'push' => true, 'sound' => true );
	$out      = array();
	foreach ( $defaults as $key => $default ) {
		$out[ $key ] = array_key_exists( $key, $stored ) ? (bool) $stored[ $key ] : $default;
	}
	return $out;
}

/**
 * Update a user's notification channel toggles (partial — only provided keys change).
 *
 * @param int                $user_id  User id.
 * @param array<string,mixed> $channels Subset of in_app/email/push/sound.
 * @return void
 */
public function set_channel_prefs( int $user_id, array $channels ): void {
	$current = get_user_meta( $user_id, 'bn_channel_prefs', true );
	$current = is_array( $current ) ? $current : array();
	foreach ( array( 'in_app', 'email', 'push', 'sound' ) as $key ) {
		if ( array_key_exists( $key, $channels ) ) {
			$current[ $key ] = (bool) $channels[ $key ];
		}
	}
	update_user_meta( $user_id, 'bn_channel_prefs', $current );
}

/**
 * List a user's per-space notification prefs (active memberships only).
 *
 * @param int $user_id User id.
 * @return array<int,array{space_id:int,name:string,slug:string,pref:string}>
 */
public function list_space_notification_prefs( int $user_id ): array {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT s.id AS space_id, s.name, s.slug, COALESCE( NULLIF( sm.notification_pref, '' ), 'all' ) AS pref
			 FROM {$wpdb->prefix}bn_spaces s
			 INNER JOIN {$wpdb->prefix}bn_space_members sm ON sm.space_id = s.id AND sm.user_id = %d AND sm.status = 'active'
			 ORDER BY s.name ASC",
			$user_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return array_map(
		static function ( array $row ): array {
			return array(
				'space_id' => (int) $row['space_id'],
				'name'     => (string) $row['name'],
				'slug'     => (string) $row['slug'],
				'pref'     => (string) $row['pref'],
			);
		},
		(array) $rows
	);
}
```

Verify the exact channel keys + response shape against Step 1 before finalizing.

- [ ] **Step 4: Delegate** in the controller: `get_notification_channels` → `( new NotificationPrefService() )->get_channel_prefs( $user_id )`; `update_notification_channels` → validate body is array, then `set_channel_prefs( $user_id, $body )` and return `get_notification_channels()`; `list_space_notification_prefs` → `( new NotificationPrefService() )->list_space_notification_prefs( $user_id )` wrapped in `{ items: ... }`. Preserve the exact response shapes and error envelopes.
- [ ] **Step 5:** `grep -nE "\\\$wpdb|update_user_meta|get_user_meta" includes/Notifications/NotificationController.php` → none. php -l + phpcs (no new errors); run `--filter 'NotificationController|NotificationPrefService'`.
- [ ] **Step 6: Commit** — `refactor(notifications): move channel + space-pref data access into NotificationPrefService`.

---

## Task 3: Pro lockstep — NotificationService::get() seam for PushDispatcher

**Files:** `includes/Notifications/NotificationService.php` (Free), `buddynext-pro/includes/Push/PushDispatcher.php` (Pro), test.

- [ ] **Step 1 (Free, TDD):** add `NotificationService::get( int $id ): ?array` returning the hydrated notification row (or null). Read the table columns the row exposes (and what `PushDispatcher` selects: `type, sender_id, object_type, object_id`) — return at least those, hydrated/typed, plus `id` and `recipient_id`. Test: create a notification, `get()` returns it; missing id → null.
- [ ] **Step 2 (Pro):** read `PushDispatcher` around 110–140. Replace the raw `$wpdb->get_row` with `$row = buddynext_service( 'notifications' )->get( $notification_id );` (it builds the push snippet from `type/sender_id/object_type/object_id`). Confirm the keys returned by `get()` match what `build_snippet()` reads; adapt the access if the hydrated shape differs. Remove the now-unused `global $wpdb;`.
- [ ] **Step 3:** `php -l` + phpcs both; `grep -n "bn_notifications" buddynext-pro/includes/Push/PushDispatcher.php` → none (or only a comment). Run the Free test.
- [ ] **Step 4: Commit** — Free: `feat(notifications): NotificationService::get`; Pro: `refactor(push): read notification row via NotificationService, not raw SQL`.

---

## Task 4: Verification + docs

- [ ] Full suite: `--filter 'Notification|Email|BaseRestController'` — green (note pre-existing unrelated failures honestly).
- [ ] `bin/check-rest-boundary.sh` clean; PHPStan project config `[OK]`.
- [ ] `grep -nE "\\\$wpdb|user_meta" includes/Notifications/NotificationController.php` → none; `grep -n "function require_auth" includes/Notifications/NotificationController.php` → none.
- [ ] Browser-smoke: a page that loads the notification bell/dropdown (`?autologin=1`) — confirm the unread-count + list render with no console errors; optionally hit `GET /me/notifications/unread-count` live.
- [ ] Update `CLAUDE.md` Recent Changes (REST-INVENTORY unchanged — no new endpoints).
- [ ] Commit — `docs: notifications flow CLAUDE.md`.

---

## Self-Review
- [ ] `NotificationController` is `$wpdb`/`usermeta`-free and on `BaseRestController`.
- [ ] 3 new pref-service methods; no service split.
- [ ] Pro `PushDispatcher` reads via `NotificationService::get()`.
- [ ] No new endpoints (coverage already complete); hooks untouched.
- [ ] No version bumps, no co-author trailer.
