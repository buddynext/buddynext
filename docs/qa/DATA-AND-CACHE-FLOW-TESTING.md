# Data-Flow & Cache-Flow Testing Journey (portable, any Mac)

This is the reproducible runbook for the scale-readiness improvements — the
data-layer (counters, presence, follows, segments, scheduling) and the
cache-layer (object-cache groups, TTLs, invalidation) work. It is written so a
developer on **any Mac** can stand the environment up from scratch and verify
every item, without the tribal knowledge that bit us the first time.

> For the browser/Playwright E2E suite see [`HOW-TO-RUN.md`](./HOW-TO-RUN.md).
> This doc is the PHPUnit + data/cache half.

---

## 0. Portable test environment (do this once per Mac)

The PHPUnit suites need a MySQL the tests can freely DROP/CREATE (never a real
site DB) and the WordPress test framework on disk. We run MySQL in Docker and the
framework under `/tmp`.

### 0.1 MySQL via Docker

```bash
docker run -d --name buddynext-test-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -p 13306:3306 \
  mysql:8.0
```

Port **13306** (not 3306) so it never collides with Local by Flywheel's MySQL.

### 0.2 Create the two test databases

**Gotcha #1 — the installer's DB-create step silently fails on some setups.**
Create the databases explicitly:

```bash
docker exec buddynext-test-mysql mysql -uroot -proot -e \
  "CREATE DATABASE IF NOT EXISTS buddynext_tests; CREATE DATABASE IF NOT EXISTS buddynextpro_tests;"
```

### 0.3 Install the WordPress test framework (free + pro)

```bash
# FREE — installs WP core (shared) + the framework into /tmp/wordpress-tests-lib
cd "<path>/wp-content/plugins/buddynext"
bash bin/install-wp-tests.sh buddynext_tests root root 127.0.0.1:13306 latest

# PRO — installs into /tmp/wordpress-tests-lib-pro (shares /tmp/wordpress core)
cd "<path>/wp-content/plugins/buddynext-pro"
bash bin/install-wp-tests.sh buddynextpro_tests root root 127.0.0.1:13306 latest
```

**Gotcha #2 — an incomplete SVN checkout.** If a run dies with
`Failed opening '.../wordpress-tests-lib/includes/functions.php'`, the framework
files did not download. Re-export them:

```bash
cd /tmp/wordpress-tests-lib            # or /tmp/wordpress-tests-lib-pro
svn export --force https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/ ./includes
svn export --force https://develop.svn.wordpress.org/trunk/tests/phpunit/data/ ./data
```

### 0.4 The `WP_TESTS_DIR` gotcha (the one that cost us hours)

**Gotcha #3 — macOS `sys_get_temp_dir()` returns `/var/folders/...`, NOT `/tmp`.**
The bootstrap falls back to `sys_get_temp_dir()`, which on macOS is a per-user
`/var/folders/...` path that is usually an empty/partial checkout. So **always
pass `WP_TESTS_DIR` explicitly for the FREE suite:**

```bash
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit
```

Pro already pins it via `phpunit.xml.dist`
(`WP_TESTS_DIR=/tmp/wordpress-tests-lib-pro`), so pro needs no env var.

### 0.5 Sanity check

```bash
# FREE
cd "<path>/buddynext"
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit tests/Core/IconServiceTest.php
# PRO
cd "<path>/buddynext-pro"
vendor/bin/phpunit tests/Core/PluginTest.php
```

Green = environment is good.

### 0.6 PHPStan note

PHPStan needs more memory than its default:

```bash
vendor/bin/phpstan analyse <files> --level=5 --no-progress --memory-limit=1G
```

### 0.7 Pro-only suite caveat (read before trusting a pro run)

The **full** pro suite run pro-only shows ~46 failures/errors — these are
**environment artifacts**: pro tests for Integrations/AI/Moderation/Suite need
**Free tables** (`bn_posts`, `bn_notifications`, `bn_email_log`, …) and addon
stubs the pro-only DB does not have. They fail with or without any change. So
**verify pro by running the touched domain in isolation**, never by the full
pro-only count. The FREE suite is self-contained and must be 100% green.

---

## 1. Quick commands

```bash
# Everything (free, self-contained — must be 0 failures)
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit            # in buddynext/

# A single domain (fast iteration)
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit tests/Realtime/

# Pro, a touched domain in isolation (the trustworthy pro signal)
vendor/bin/phpunit tests/Analytics/                                # in buddynext-pro/

# Static gates on changed files
vendor/bin/phpcs <files>
vendor/bin/phpstan analyse <files> --level=5 --no-progress --memory-limit=1G

# Full local CI gate (also runs on every commit via the pre-commit hook)
bash bin/check.sh --staged
```

---

## 2. Data-flow journeys

Each row: the data path the change introduced + the exact test that proves it.
"Repo" is where the test lives; FREE commands need the `WP_TESTS_DIR` prefix.

| Item | Data flow under test | Test (repo) |
|---|---|---|
| **F-phase4** Presence | stamp() writes ONLY `bn_presence` (no more `bn_last_active` meta); v9 migration deletes legacy meta; readers resolve from the table | `tests/Realtime/PresenceServiceTest.php` (free) — `test_stamp_writes_table_only_not_legacy_meta`, `test_migration_drops_legacy_meta` |
| **J3** Online widget | `recent_online_ids($limit)` = indexed `ORDER BY last_active DESC LIMIT`, newest-first, excludes stale; widget renders online (not newest) + empty state | `tests/Realtime/PresenceServiceTest.php::test_recent_online_ids_is_bounded_and_ordered` + `tests/Widgets/OnlineMembersWidgetTest.php` (free) |
| **Follow cap** | `follow()` blocks at the cap (default 5,000, `buddynext_max_following`), re-follow at cap is a no-op, cap 0 disables | `tests/SocialGraph/FollowServiceTest.php` (free) — 3 cap tests |
| **S2(c)** Counters | event → `col±1`; nightly recount reconciles `share_count` (from `bn_shares`), `member_count` (active-only), hashtag post/follower from source tables | `tests/Core/CounterServiceTest.php` (bulk + active-only) + `tests/Feed/ShareServiceTest.php::test_recount_counters_reconciles_drifted_share_count` (free) |
| **K1** Drip→AS | `arm_if_needed()`/`disarm()` clear the legacy WP-Cron event (migrated to Action Scheduler) | `tests/Email/DripEnrollmentServiceTest.php` (pro) — migration tests |
| **H1–H4** Segments | `all_users`/`by_tag`/`by_activity_level`/`by_join_date` page in batches (no `number=>-1`); empty filter → no recipients; paged loop has no dupes | `tests/Email/SegmentServiceTest.php` (pro) — `test_all_users_segment_returns_every_user_paged`, `test_empty_filter_resolves_to_no_recipients` |
| **S5** Push | the FCM send is deferred (not run inline on the notification hook) | `tests/Push/PushDispatcherTest.php::test_dispatch_is_deferred_when_async` (pro) |

---

## 3. Cache-flow journeys

The portable way to prove a cache: **read (miss→compute), mutate the source
behind the cache, read again (must be the SAME cached value), flush, read again
(must be FRESH).** Our cache tests follow exactly this shape — copy it for any
new cache.

```php
$this->assertSame( 1, $svc->count() );  // miss → computes 1
$seed_one_more_row();                    // mutate behind the cache
$this->assertSame( 1, $svc->count() );  // HIT — still cached value
wp_cache_flush();
$this->assertSame( 2, $svc->count() );  // re-read fresh
```

> Note: `WP_UnitTestCase::set_up()` calls `wp_cache_flush()` before every test,
> so caches never leak across tests. Inside one test the cache persists, which is
> what lets the hit/flush assertion above work.

| Cache | Group / key | TTL | Bust | Test |
|---|---|---|---|---|
| **S4b** Rate limits | `buddynext_rate` / `bn_*_rate_*` | window | n/a (fail-open) | `tests/Core/RateLimiterTest.php` (free) — both backends (transient + object cache) |
| **C11** Analytics | `buddynextpro_analytics` / per-method | 300s | none (read-only, append-only table) | `tests/Analytics/AnalyticsServiceCacheTest.php` (pro) |
| **C12** Profile views | `buddynextpro_analytics` / `pv_*` | 300s | none | `tests/Analytics/ProfileViewServiceCacheTest.php` (pro) |
| **C1** Permissions | `buddynext_space_members` / `role_*`, `status_*` | (SpaceMemberService TTL) | on every membership write (`invalidate_cache`) | `tests/Core/PermissionServiceCacheTest.php` (free) — incl. the role-change-busts-cache invalidation case |
| **J3** Online widget | `buddynext_presence` / `online_widget_{limit}` | 30s | n/a (short TTL) | `tests/Widgets/OnlineMembersWidgetTest.php` (free) |

**Backend matters.** Rate-limit + presence caches have a transient fallback when
there is no persistent object cache; `RateLimiterTest` runs both paths by toggling
`wp_using_ext_object_cache()`. On a real site, **caching is load-bearing at scale —
run a persistent object cache (Redis/Memcached)** and confirm it via
*BuddyNext → Tools* (the object-cache health indicator, item N1).

---

## 4. Browser journeys (the JS / live-site half)

A few items are frontend/JS and are verified in the browser, not PHPUnit. Use the
running Local site (`buddynext.local`) and the Playwright MCP tools (per the
project's MCP-only browser rule). Auto-login with `?autologin=<login>`.

- **L1 — DM poll pause.** Open a conversation
  (`/messages/?conversation=<id>&tab=all`). Hook `window.fetch`, count
  `/messages/poll` calls: ~1 per 5s while visible; **0** while the tab is hidden
  (`document.hidden`); one immediate catch-up on refocus. Switching conversations
  must leave exactly one poll loop (no stacking).
- **J3 — Online widget.** Place the *BuddyNext: Online Members* widget; confirm it
  lists members who are actually online (have a recent `bn_presence` row), and
  shows the empty state when nobody is online.
- **Admin analytics (C11/C12).** Load the Pro Analytics admin; confirm KPIs render
  and a reload is served from cache (no new heavy queries within the TTL).

Capture screenshots to `~/Documents/work-artifacts/screenshots/YYYY-MM/`.

---

## 5. One-shot "is everything still green" pass

```bash
# 1. FREE — must be 0 failures
cd "<path>/buddynext"
WP_TESTS_DIR=/tmp/wordpress-tests-lib vendor/bin/phpunit

# 2. PRO — touched domains in isolation (Analytics/Email/Push/Realtime)
cd "<path>/buddynext-pro"
for d in Analytics Email Push Realtime; do vendor/bin/phpunit "tests/$d/"; done

# 3. Contract audit (static cross-surface drift) — must be 0 errors
php ~/.claude/skills/wp-contract-audit/scripts/contract-audit.php "<path>/buddynext" \
  --pair="<path>/buddynext-pro"
```

Free suite 0 failures + each pro domain at its known pre-existing-only count +
contract audit 0 errors = the data/cache layer is healthy.
