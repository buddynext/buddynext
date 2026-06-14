# Feed + Comments + Reactions Flow — Consolidation & Layer Cleanup Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make the Feed/Comments/Reactions flow consistent with the Moderation flow's rules: one home for `bn_posts` counter writes (no scattered raw SQL), REST controllers free of `$wpdb`, and shared auth via `BaseRestController` — Free + Pro in lockstep. No new abstraction classes; no file splits.

**Architecture:** Flow-first (vertical). The `bn_posts` counter writes are currently copy-pasted across 6 sites in 4 files. We consolidate them onto **`PostService`** — the class that already owns `bn_posts` and already writes `share_count` — NOT a new `PostRepository`. (Per the consolidation-bloat test: a new Repository class would add a concept for a marginal win; folding the methods onto the existing owner reduces duplication with zero new concepts.)

**Tech Stack:** PHP 8.1+, PSR-4, PHPUnit 9.6 + `WP_Test_REST_TestCase`, WPCS, PHPStan level 5.

**Binding contracts:** `docs/specs/SCALE-CONTRACT.md` (cached counters, cursor pagination, no COUNT in render), `docs/specs/REST-FRONTEND-CONTRACT.md` (100% REST), `docs/specs/REST-INVENTORY.md`.

**What the investigation found (current state):**
- **Counter duplication (headline):** `bn_posts` counter UPDATEs in 6 places — `CommentService.php:120/:321` (comment_count ±1), `ReactionService.php:67/:150` (reaction_count ±1), `PostService.php:540/:549` (share_count ±1), `Bridges/WPMediaVerseBridge.php:412` (comment_count +1). Plus the `SELECT user_id FROM bn_posts` author lookup duplicated in `CommentService:138` and `ReactionService:85`. Increments lack the `GREATEST(0,…)` guard that decrements have (harmless but inconsistent).
- **Controller `$wpdb` (layer violation):** `FeedController::dismiss_announcement()` (raw read ~line 318) and `FeedController::end_announcement()` (raw write ~line 361). All other Feed/Comments/Reactions controllers are clean.
- **`BaseRestController` not yet adopted:** 8 controllers still declare a local `require_auth()` (PostController, FeedController, CommentController, ReactionController, BookmarkController, PollController, ShareController, ComposerDraftController). `CommentController` also has a local `require_moderator()`.
- **REST completeness:** NO gaps — compose/read/edit/delete/pin/react/comment/bookmark/poll/share are all exposed. (No new endpoints needed this flow.)
- **Scale:** Feed home page-1 is already cached and cursor-paginated (compliant). `CommentService` list uses OFFSET + a COUNT (the COUNT is cache-mitigated via a generation counter); the OFFSET is low-impact (threads rarely exceed the cap). Tracked as an optional hardening task, not required.
- **Pro:** `ProPinService`, `CustomReactionsService`, `AiRankedFeedService` extend via stable filters/subclass — untouched. `ScheduledPostsService` / `ScheduledPostsController` / `ScheduledPostsIntegration` write `bn_posts.status`/`scheduled_at` directly (different columns from the counters — the counter work does NOT affect them). Lockstep target: give them a service seam for the status write.

**Non-goals:** No `PostRepository` class. No file splits. No change to the stable Pro filter/subclass seams. No reworking the announcement feature itself.

---

## CRITICAL — Read Before Each Task

- Test harness is already installed (see [[reference_bn_phpunit_harness]]). Run: `WP_TESTS_DIR=/tmp/wordpress-tests-lib php -d opcache.enable=0 vendor/bin/phpunit --filter <Test>` with the mysql client on PATH.
- `php -l` + `php -d xdebug.mode=off vendor/bin/phpcs --standard=phpcs.xml.dist <files>` on every changed file (the wpcs MCP tool chokes on PHP extension warnings — run phpcs directly). Production `includes/` must be phpcs-clean; `tests/` has accepted pre-existing doc-comment noise — match existing test style.
- Behaviour-preserving refactor. No new endpoints. No version bumps. No Claude co-author trailer.
- Free repo: `/Users/vapvarun/dev/repos/buddynext`. Pro: `/Users/vapvarun/dev/repos/buddynext-pro`.
- Commit per task after verification (the user authorized task-by-task auto-commit when the flow is verified).
- Browser-verify any UI/template path with Playwright MCP against `http://buddynext-dev.local` (HTTP, not HTTPS — self-signed cert) with `?autologin=1`.

---

## Task 1: Counter + author methods on PostService

**Why:** Consolidate the 6 scattered counter UPDATEs and 2 author lookups onto the existing `bn_posts` owner. One implementation, consistent `GREATEST(0,…)` guard, no new class.

**Files:**
- Modify: `includes/Feed/PostService.php` (add methods; route its own share_count writes through them)
- Modify: `includes/Comments/CommentService.php` (route comment_count + author lookup)
- Modify: `includes/Reactions/ReactionService.php` (route reaction_count + author lookup)
- Modify: `includes/Bridges/WPMediaVerseBridge.php` (route comment_count +1)
- Test: `tests/Feed/PostCountersTest.php`

- [ ] **Step 1: Write the failing test** — `tests/Feed/PostCountersTest.php`:

```php
<?php
declare( strict_types=1 );

namespace BuddyNext\Tests\Feed;

use BuddyNext\Core\Installer;
use BuddyNext\Feed\PostService;

/**
 * @covers \BuddyNext\Feed\PostService::increment_counter
 * @covers \BuddyNext\Feed\PostService::decrement_counter
 * @covers \BuddyNext\Feed\PostService::get_author_id
 */
class PostCountersTest extends \WP_UnitTestCase {

	private PostService $service;
	private int $post_id;
	private int $author;

	public function set_up(): void {
		parent::set_up();
		Installer::run();
		$this->service = new PostService();
		$this->author  = self::factory()->user->create();
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'bn_posts',
			array( 'user_id' => $this->author, 'content' => 'x', 'status' => 'published' )
		);
		$this->post_id = (int) $wpdb->insert_id;
	}

	private function col( string $c ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT {$c} FROM {$wpdb->prefix}bn_posts WHERE id = %d", $this->post_id ) // phpcs:ignore
		);
	}

	public function test_increment_and_decrement_comment_count(): void {
		$this->service->increment_counter( $this->post_id, 'comment_count' );
		$this->service->increment_counter( $this->post_id, 'comment_count' );
		$this->assertSame( 2, $this->col( 'comment_count' ) );
		$this->service->decrement_counter( $this->post_id, 'comment_count' );
		$this->assertSame( 1, $this->col( 'comment_count' ) );
	}

	public function test_decrement_never_goes_negative(): void {
		$this->service->decrement_counter( $this->post_id, 'reaction_count' );
		$this->assertSame( 0, $this->col( 'reaction_count' ) );
	}

	public function test_rejects_unknown_column(): void {
		$this->service->increment_counter( $this->post_id, 'evil; DROP TABLE' );
		$this->assertSame( 0, $this->col( 'comment_count' ) );
	}

	public function test_get_author_id(): void {
		$this->assertSame( $this->author, $this->service->get_author_id( $this->post_id ) );
		$this->assertSame( 0, $this->service->get_author_id( 999999 ) );
	}
}
```

- [ ] **Step 2: Run; expect failure** — `--filter PostCountersTest` → "undefined method increment_counter".

- [ ] **Step 3: Add to `PostService`** (place near the existing share_count code around line 540). Whitelist guards against arbitrary column names:

```php
/** Counter columns that may be incremented/decremented on bn_posts. */
private const COUNTER_COLUMNS = array( 'comment_count', 'reaction_count', 'share_count' );

/**
 * Increment a bn_posts counter column by 1.
 *
 * @param int    $post_id Post id.
 * @param string $column  One of self::COUNTER_COLUMNS.
 */
public function increment_counter( int $post_id, string $column ): void {
	if ( $post_id <= 0 || ! in_array( $column, self::COUNTER_COLUMNS, true ) ) {
		return;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET {$column} = {$column} + 1 WHERE id = %d", $post_id )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Decrement a bn_posts counter column by 1, never below zero.
 *
 * @param int    $post_id Post id.
 * @param string $column  One of self::COUNTER_COLUMNS.
 */
public function decrement_counter( int $post_id, string $column ): void {
	if ( $post_id <= 0 || ! in_array( $column, self::COUNTER_COLUMNS, true ) ) {
		return;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare( "UPDATE {$wpdb->prefix}bn_posts SET {$column} = GREATEST(0, {$column} - 1) WHERE id = %d", $post_id )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Return a post's author id, or 0 if the post does not exist.
 *
 * @param int $post_id Post id.
 * @return int
 */
public function get_author_id( int $post_id ): int {
	if ( $post_id <= 0 ) {
		return 0;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}bn_posts WHERE id = %d", $post_id )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
```

- [ ] **Step 4: Run; expect PASS** — `--filter PostCountersTest`.

- [ ] **Step 5: Route the 6 call sites + 2 author lookups through the new methods.** For each, read the surrounding code first, then replace ONLY the raw counter UPDATE / author SELECT — keep the `if ( 'post' === $object_type )` guards and everything else:
  - `PostService.php:540` share increment → `$this->increment_counter( $post_id, 'share_count' );` (it's already in PostService — call `$this->`).
  - `PostService.php:549` share decrement → `$this->decrement_counter( $post_id, 'share_count' );`
  - `CommentService.php:120` → resolve PostService (`buddynext_service( 'post_service' )`) and call `increment_counter( $object_id, 'comment_count' )`.
  - `CommentService.php:321` → `decrement_counter( (int) $comment['object_id'], 'comment_count' )`.
  - `CommentService.php:138` author lookup → `$author_id = buddynext_service( 'post_service' )->get_author_id( $object_id );`
  - `ReactionService.php:67` → `increment_counter( $object_id, 'reaction_count' )`.
  - `ReactionService.php:150` → `decrement_counter( $object_id, 'reaction_count' )`.
  - `ReactionService.php:85` author lookup → `get_author_id( $object_id )`.
  - `Bridges/WPMediaVerseBridge.php:412` → `buddynext_service( 'post_service' )->increment_counter( $bn_post_id, 'comment_count' )`.
  - For CommentService/ReactionService, prefer constructor injection of `PostService` if they already take container deps; otherwise resolve via `buddynext_service( 'post_service' )` at the call site (match each class's existing style — read the constructor first).

- [ ] **Step 6: Confirm no `bn_posts SET .*count` raw UPDATEs remain outside PostService:** `grep -rn "bn_posts SET" includes/ | grep -i count` → only the two PostService method bodies.
- [ ] **Step 7:** `php -l` each changed file; phpcs clean on all; run `--filter 'PostCounters|Comment|Reaction|Feed|Post'` → green.
- [ ] **Step 8: Commit** — `refactor(feed): consolidate bn_posts counter writes onto PostService`.

---

## Task 2: Move FeedController announcement DB access into PostService

**Why:** `FeedController::dismiss_announcement()` (raw read) and `end_announcement()` (raw write) are the only Feed/Comments controller `$wpdb` sites. The Moderation flow established that controllers must not touch `$wpdb`.

**Files:**
- Modify: `includes/Feed/PostService.php` (add `get_announcement`, `end_announcement`)
- Modify: `includes/Feed/FeedController.php` (delegate)
- Test: `tests/Feed/AnnouncementServiceTest.php`

- [ ] **Step 1: Read** `FeedController::dismiss_announcement()` (~311–339) and `end_announcement()` (~348–382) — capture the exact `bn_posts` columns (`is_announcement`, `type='announcement'`, `site_pin_expires_at`) and the 204/200 responses.
- [ ] **Step 2: Write the failing test** asserting `PostService::get_announcement( $id )` returns the row (or null) and `end_announcement( $id )` sets `site_pin_expires_at` and returns bool. Seed a `bn_posts` row with `is_announcement=1, type='announcement'`.
- [ ] **Step 3: Add to `PostService`:**

```php
/**
 * Fetch an active announcement post, or null if not an announcement.
 *
 * @param int $post_id Post id.
 * @return array<string,mixed>|null
 */
public function get_announcement( int $post_id ): ?array {
	if ( $post_id <= 0 ) {
		return null;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}bn_posts WHERE id = %d AND is_announcement = 1 AND type = 'announcement' LIMIT 1",
			$post_id
		),
		ARRAY_A
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $row ?: null;
}

/**
 * End an announcement by expiring its site pin now. Returns true if a row changed.
 *
 * @param int $post_id Post id.
 * @return bool
 */
public function end_announcement( int $post_id ): bool {
	if ( $post_id <= 0 ) {
		return false;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->update(
		$wpdb->prefix . 'bn_posts',
		array( 'site_pin_expires_at' => gmdate( 'Y-m-d H:i:s' ) ),
		array( 'id' => $post_id, 'is_announcement' => 1 ),
		array( '%s' ),
		array( '%d', '%d' )
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (bool) $updated;
}
```

- [ ] **Step 4: Delegate** in `FeedController`: `dismiss_announcement()` uses `get_announcement()` for the existence check; `end_announcement()` uses the service method. Preserve the 404/204/200 envelopes and the `current_user_can('manage_options')` gate in `end_announcement()`.
- [ ] **Step 5:** `grep -n "\\\$wpdb" includes/Feed/FeedController.php` → none. php -l + phpcs clean. Run `--filter 'Announcement|Feed'`.
- [ ] **Step 6: Commit** — `refactor(feed): move announcement DB access into PostService`.

---

## Task 3: Adopt BaseRestController across Feed/Comments/Reactions controllers

**Why:** 8 controllers each re-declare `require_auth()`. Reuse the base from the Moderation flow.

**Files:**
- Modify: `includes/REST/BaseRestController.php` (add `require_moderator()` — used by CommentController, candidate for reuse)
- Modify: PostController, FeedController, CommentController, ReactionController, BookmarkController, PollController, ShareController, ComposerDraftController
- Test: extend `tests/REST/BaseRestControllerTest.php` with a `require_moderator` case

- [ ] **Step 1:** Add `require_moderator()` to `BaseRestController` (mirror the existing `CommentController::require_moderator()` body — logged-in + `manage_options`, 401/403). Add a unit test for it (logged-out→401, subscriber→403, admin→true). Run, green.
- [ ] **Step 2:** For each of the 8 controllers: add `use BuddyNext\REST\BaseRestController;`, change the class to `extends BaseRestController`, delete its local `require_auth()` (and `CommentController`'s local `require_moderator()`). Before deleting each, confirm the local body matches the base (status codes); some use code `rest_forbidden` for 401 — the base standardises to `rest_not_logged_in` (status unchanged), consistent with the rest of the codebase. Check each controller's existing tests assert only on status, not error code (grep the test files).
- [ ] **Step 3:** Per controller: `php -l`, phpcs clean, and run that domain's tests (`--filter PostController`, `FeedController`, etc.). Do them one controller at a time so a regression is isolated.
- [ ] **Step 4: Browser-smoke** the feed: `?autologin=1`, load the activity feed, post a comment, add a reaction — confirm no console errors and the actions succeed (these exercise the migrated controllers' `require_auth`).
- [ ] **Step 5: Commit** — `refactor(rest): Feed/Comments/Reactions controllers extend BaseRestController`.

---

## Task 4 (scale hardening, optional): CommentService cursor pagination

**Why:** `CommentService` list uses OFFSET (SCALE-CONTRACT prefers cursor). Low impact (threads rarely exceed the cap; the COUNT is cache-mitigated). Include only if pursuing full scale-contract compliance this flow; otherwise defer with a logged note.

**Files:** `includes/Comments/CommentService.php`, `tests/Comments/CommentPaginationTest.php`

- [ ] Read `CommentService` list method (~603–639). Add a cursor path (`WHERE created_at > ? AND id > ?`) mirroring `FeedService::cursor_where()`, keeping the existing response shape and the cached total. Write a test asserting page-2-by-cursor returns the next slice without OFFSET. Verify, commit — `perf(comments): cursor pagination for comment lists`. **If deferred:** add a `// SCALE-CONTRACT: OFFSET pagination, acceptable for bounded threads` note at the query and `log` the decision in the task summary — do not silently skip.

---

## Task 5: Pro lockstep — scheduled-posts status writes through a service seam

**Why:** Pro's `ScheduledPostsService`/`ScheduledPostsController` write `bn_posts.status`/`scheduled_at` with raw `$wpdb`. (The counter consolidation does NOT touch these — different columns.) Give Free a service seam for the status write so Pro stops reaching into `bn_posts` directly. `ProPinService`, `CustomReactionsService`, `AiRankedFeedService` are stable filter/subclass seams — leave them.

**Files:**
- Modify: `includes/Feed/PostService.php` (Free) — add `set_schedule()`, `clear_schedule()`/publish, `get_posts_by_status()`
- Modify (Pro): `buddynext-pro/includes/Feed/ScheduledPostsService.php`, `Controllers/ScheduledPostsController.php`
- Test (Free): `tests/Feed/PostScheduleTest.php`

- [ ] **Step 1: Read** Pro `ScheduledPostsService` writes (schedule ~133–142, cancel ~185–194, tick publish ~288–294) and `ScheduledPostsController::get_all_scheduled_posts()` (~272–315). Capture the exact column writes (status, scheduled_at) and the admin list query.
- [ ] **Step 2 (Free):** Add to `PostService` (TDD first): `set_schedule( int $post_id, string $scheduled_at ): bool` (status='scheduled', set scheduled_at), `publish_scheduled( int $post_id ): bool` (status='published'), `clear_schedule( int $post_id ): bool` (status='draft', scheduled_at null), and `get_posts_by_status( string $status, int $limit = 50 ): array`. Capped per scale-contract. Test each.
- [ ] **Step 3 (Pro):** Route `ScheduledPostsService` and `ScheduledPostsController::get_all_scheduled_posts()` through `buddynext_service( 'post_service' )->…`. Keep the `buddynext_post_created` re-fire in `tick()` exactly as is. Confirm `grep -n "bn_posts" buddynext-pro/includes/Feed/` shows no raw status/scheduled_at UPDATEs left.
- [ ] **Step 4: Verify** — Free PostSchedule tests green; browser-smoke the Pro scheduled-posts admin/REST if reachable (schedule a post via `POST buddynext-pro/v1/posts/{id}/schedule`, confirm it lands and the admin list shows it).
- [ ] **Step 5: Commit** — Free: `feat(feed): PostService schedule seam`; Pro: `refactor(feed): scheduled posts write status via PostService`.

---

## Task 6: Full flow verification (Free + Pro)

- [ ] Full suite: `--filter 'Feed|Comment|Reaction|Post|Bookmark|Poll|Share|Announcement|BaseRestController'` — green (note any pre-existing unrelated failures honestly, e.g. the known `Admin\SpacesTest::test_register_adds_admin_menu_hook`).
- [ ] `bin/check-rest-boundary.sh` green; PHPStan via project config `[OK]`.
- [ ] `grep -rn "bn_posts SET" includes/ | grep -i count` → only PostService.
- [ ] `grep -n "\\\$wpdb" includes/Feed/FeedController.php` → none.
- [ ] Browser: post → comment → react → bookmark → poll vote, confirming counters update and no console errors (Playwright MCP, `?autologin=1`). Check Mailpit (http://localhost:10010/) if an engagement notification is expected.

---

## Task 7: Docs

- [ ] No new endpoints → REST-INVENTORY unchanged. Update `CLAUDE.md` Recent Changes: counter consolidation onto PostService, FeedController $wpdb removed, 8 controllers on BaseRestController, Pro scheduled-posts via service seam.
- [ ] Commit — `docs: feed/comments flow recent changes`.

---

## Self-Review Checklist

- [ ] No `PostRepository` class created; counter methods live on `PostService`.
- [ ] No raw `bn_posts` counter UPDATE outside `PostService`; `FeedController` is `$wpdb`-free.
- [ ] All 8 controllers extend `BaseRestController`; `require_moderator` lives on the base.
- [ ] Pro stable seams (pin/reactions/AI feed) untouched; only scheduled-posts writes rerouted.
- [ ] No new endpoints, no file splits, no version bumps, no co-author trailer.
