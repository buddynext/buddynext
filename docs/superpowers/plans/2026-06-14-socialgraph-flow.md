# SocialGraph Flow — Base Adoption & App-Readiness Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development or superpowers:executing-plans. Steps use `- [ ]`.

**Goal:** Bring SocialGraph (follows, connections, blocks, mutes) in line with the prior flows: the 3 controllers share `BaseRestController`, the app-readiness relationship-inspection gaps close, and Pro's one raw `bn_follows` read routes through a Free service. No file splits.

**Architecture:** Flow-first. SocialGraph is already in excellent shape — `FollowController`/`ConnectionController`/`BlockController` are all `$wpdb`-free, services are cohesive (BlockService intentionally owns block+mute+restrict in one table), and every hook fires from the service only (no controller/service duplication, unlike Spaces). So this flow is mostly additive REST endpoints.

**Tech Stack:** PHP 8.1+, PHPUnit 9.6 + WP_Test_REST_TestCase, WPCS, PHPStan 5. Harness: [[reference_bn_phpunit_harness]].

**What the investigation found:**
- **BaseRestController:** `FollowController` (class:26, require_auth:312), `ConnectionController` (class:27, require_auth:219), `BlockController` (class:27, require_auth:255) each declare a local `require_auth` identical to the base.
- **REST gaps (app-readiness — the main value):** the client cannot query relationship state. All backed by existing service methods:
  - `is_following` (227) + `has_pending_request` (260) → no endpoint
  - `ConnectionService::status` (350) → no endpoint
  - `is_private_account` (47) → no endpoint
  - `mutual_connections` (569) → no endpoint
  - `pending_followers_count` (514) → no endpoint (request-inbox badge)
- **Pro:** adds no social-graph controllers/REST. Depends on the stable container key `follows` (no change). One raw read: `Analytics/FunnelService.php:194` does `SELECT COUNT(DISTINCT follower_id) FROM bn_follows WHERE created_at BETWEEN ...`. Route through a new `FollowService::count_followers_in_range()`.
- **Deferred:** bulk `POST /relationship-statuses` / `/block-statuses` (the member directory already returns relationship data server-side via `statuses_for`/`blocking_either_map`, so the app gets bulk status in the directory payload). Note, don't build this flow.

**Non-goals:** No service split. No change to hooks (they're clean). No bulk-status endpoints (deferred, noted). `SegmentService`'s `bn_space_members` read is a Spaces concern, out of scope.

---

## CRITICAL — Read Before Each Task
- `php -l` + `php -d xdebug.mode=off vendor/bin/phpcs --standard=phpcs.xml.dist <files>`; `includes/` stays phpcs-clean (no NEW errors vs baseline). Match existing test style.
- Additive endpoints + base adoption only. No version bumps, no co-author trailer. Commit per task on green.

---

## Task 1: SocialGraph controllers extend BaseRestController

**Files:** `includes/SocialGraph/FollowController.php`, `ConnectionController.php`, `BlockController.php`

- [ ] `grep -rn "get_error_code" tests/SocialGraph` — confirm no test pins controller auth error codes.
- [ ] For each: add `use BuddyNext\REST\BaseRestController;`, `class X extends BaseRestController`, delete the local `require_auth`. Watch for the class-close blank-line phpcs nit after removing the last method (the prior flows hit it — ensure `}\n}` not `}\n\n}`).
- [ ] `php -l` all three; phpcs (no NEW errors); `grep -rn "function require_auth" includes/SocialGraph` → none.
- [ ] Run `--filter 'FollowController|ConnectionController|BlockController|Followers|Following|BaseRestController'` → green.
- [ ] Commit — `refactor(rest): SocialGraph controllers extend BaseRestController`.

---

## Task 2: Relationship-inspection REST endpoints

Add under `buddynext/v1`, each backed by an existing service method:

| Route | Method | Permission | Backing |
|---|---|---|---|
| `/users/(?P<id>[\d]+)/follow/status` | GET | require_auth | `is_following(me,id)` + `has_pending_request(me,id)` |
| `/users/(?P<id>[\d]+)/connection/status` | GET | require_auth | `ConnectionService::status(me,id)` |
| `/users/(?P<id>[\d]+)/mutual-connections` | GET | require_auth | `mutual_connections(me,id)` |
| `/users/(?P<id>[\d]+)/account-type` | GET | public | `is_private_account(id)` |
| `/me/follow-requests/count` | GET | require_auth | `pending_followers_count(me)` |

**Files:** `FollowController.php` (follow/status, account-type, follow-requests/count), `ConnectionController.php` (connection/status, mutual-connections), tests.

- [ ] **Step 1: Write the failing tests** (`tests/SocialGraph/RelationshipStatusTest.php`, WP_Test_REST_TestCase): e.g. after A follows B, `GET /users/{B}/follow/status` as A → `{is_following:true,is_pending:false}`; connection status reflects an accepted/pending request; mutual-connections returns the shared ids; account-type returns `is_private` for a user with `bn_account_private` usermeta; follow-requests/count returns the pending inbound count. Confirm exact service signatures/return types first (e.g. `status()` returns `?string`).
- [ ] **Step 2:** Register the routes + handlers. Pattern:

```php
register_rest_route(
	'buddynext/v1',
	'/users/(?P<id>[\d]+)/follow/status',
	array(
		'methods'             => \WP_REST_Server::READABLE,
		'callback'            => array( $this, 'follow_status' ),
		'permission_callback' => array( $this, 'require_auth' ),
		'args'                => array( 'id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ) ),
	)
);
```

```php
public function follow_status( \WP_REST_Request $request ): \WP_REST_Response {
	$me     = get_current_user_id();
	$target = (int) $request['id'];
	$follows = buddynext_service( 'follows' );
	return new \WP_REST_Response(
		array(
			'is_following' => $follows->is_following( $me, $target ),
			'is_pending'   => $follows->has_pending_request( $me, $target ),
		),
		200
	);
}
```

Mirror for the others: `connection/status` → `array( 'status' => buddynext_service( 'connections' )->status( $me, $target ) )`; `mutual-connections` → `array( 'ids' => buddynext_service( 'connections' )->mutual_connections( $me, $target ) )`; `account-type` → `array( 'is_private' => buddynext_service( 'follows' )->is_private_account( $target ) )` (public — uses `__return_true`); `me/follow-requests/count` → `array( 'count' => buddynext_service( 'follows' )->pending_followers_count( $me ) )`.

- [ ] **Step 3:** Run the tests → green. `bin/check-rest-boundary.sh` clean. phpcs no new errors.
- [ ] **Step 4: Commit** — `feat(social-graph): relationship-inspection read endpoints`.

---

## Task 3: Pro lockstep — FunnelService reads follows via FollowService

**Files:** `includes/SocialGraph/FollowService.php` (Free), `buddynext-pro/includes/Analytics/FunnelService.php` (Pro), test.

- [ ] **Step 1 (Free, TDD):** add `FollowService::count_followers_in_range( string $start_utc, string $end_utc ): int` — `SELECT COUNT(DISTINCT follower_id) FROM bn_follows WHERE created_at BETWEEN %s AND %s`. Test with seeded rows.
- [ ] **Step 2 (Pro):** at `FunnelService.php:192–197`, the `follow.first` case currently returns a raw SQL string (the funnel builds SQL fragments). Two options — pick based on how FunnelService consumes the return:
  - If it executes per-event queries, replace the raw `$wpdb->prepare` with `buddynext_service( 'follows' )->count_followers_in_range( $start_sql, $end_sql )` and adapt the case to return the count directly.
  - If the case MUST return a SQL string (the funnel UNIONs them), leave it but add a doc note that it reads a Free table directly for analytics, and (preferred) still expose the Free method for future use. Read the surrounding `FunnelService` code before deciding; do not break the funnel's query-assembly contract.
- [ ] **Step 3:** `php -l` + phpcs both. If routed, verify against the Free test; else document.
- [ ] **Step 4: Commit** — Free: `feat(social-graph): FollowService::count_followers_in_range`; Pro: `refactor(analytics): count follows via FollowService` (or a doc-note commit if the SQL-fragment contract blocks routing).

---

## Task 4: Verification + docs

- [ ] Full suite: `--filter 'Follow|Connection|Block|Relationship|SocialGraph|BaseRestController'` — green (note pre-existing unrelated failures honestly).
- [ ] `bin/check-rest-boundary.sh` clean; PHPStan project config `[OK]`.
- [ ] `grep -rn "function require_auth" includes/SocialGraph` → none.
- [ ] Browser-smoke a profile/member directory page; hit one new endpoint live via `browser_evaluate` (e.g. `GET /users/{id}/account-type`, public) — confirm 200 + shape.
- [ ] Update `docs/specs/REST-INVENTORY.md` (5 new endpoints). Update `CLAUDE.md` Recent Changes. Note the deferred bulk-status endpoints.
- [ ] Commit — `docs: social-graph flow REST inventory + CLAUDE.md`.

---

## Self-Review
- [ ] 3 controllers on `BaseRestController`; no local `require_auth`.
- [ ] 5 read endpoints, each `permission_callback` + backed by an existing service method.
- [ ] No service split; hooks untouched.
- [ ] Pro's raw `bn_follows` read addressed (routed or documented per the SQL-fragment constraint).
- [ ] No version bumps, no co-author trailer.
