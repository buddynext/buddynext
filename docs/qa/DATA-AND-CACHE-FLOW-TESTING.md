# Data-Flow & Cache-Flow Testing Journey (portable, any Mac)

This is the reproducible runbook for the scale-readiness improvements — the
data-layer (counters, presence, follows, segments, scheduling) and the
cache-layer (object-cache groups, TTLs, invalidation) work. It is written so a
developer on **any Mac** can stand the environment up from scratch, **seed
large data through the services** (§5), and verify every item — without the
tribal knowledge that bit us the first time.

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

## 5. Large-data seeding — through the services, NEVER direct `$wpdb`

To exercise the scale behaviour (EXPLAIN plans, cache hit-rates, counter load)
you need volume. **Seed it through the services, never with raw `INSERT`s.** A
direct `$wpdb` insert skips every hook, so counters never increment, caches are
never busted, and the search index + `bn_analytics_events` stay empty — you end
up "testing" an inconsistent state that hides real bugs and invents fake ones.
Seeding through the services reproduces exactly how data accumulates in
production.

> This is a **separate internal QA tool** — NOT the customer demo installer
> (`wp buddynext demo`, which seeds a fixed curated community and is left
> untouched).

### The seeder

`docs/qa/seed-scale.php` drives the real services (`follows->follow`,
`post_service->create`, `reactions->react`, `comments->create`, `shares->share`,
`spaces->create`, `space_members->join`, `PresenceService::write`) via
`wp eval-file`. It lives under `docs/` so it never ships in the dist zip, and it
runs **only on a throwaway / local dev site**.

```bash
# From the WordPress root. Defaults = a quick, meaningful load.
wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php

# Scale each dimension via env knobs:
BN_SEED_USERS=20000 \           # members (0 = reuse already-seeded ones)
BN_SEED_POWER_FOLLOWS=4000 \    # one member follows this many (service caps at 5,000)
BN_SEED_POSTS=500 \             # posts + spread engagement
BN_SEED_VIRAL_REACTIONS=10000 \ # one hot post, reactions at volume
BN_SEED_SPACE_MEMBERS=8000 \    # one populous space
  wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php

# Very large user counts: pre-generate raw users (fast), then reuse them.
wp user generate --count=50000 --role=subscriber
BN_SEED_USERS=0 wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php

# Remove exactly what the seeder created (tagged users + the seed space):
BN_SEED_CLEANUP=1 wp eval-file wp-content/plugins/buddynext/docs/qa/seed-scale.php
```

The follow loop deliberately stops at the **5,000 follow cap** (the cap is doing
its job — that is the bound for the feed subquery). Every seeded user is tagged
`bn_seed_scale` usermeta so cleanup removes only its own data.

### Prove the wiring fired (the seed is only valid if it did)

Because every write went through a service, the denormalized counters must
already be correct — so a recount finds **zero drift**:

```bash
# Should report no change — counters were maintained on each service write.
wp eval 'buddynext_service("post_service")->recount_counters(); echo "recount ran\n";'

# Spot-check a hot post: stored counter == actual rows.
wp eval 'global $wpdb;$p=$wpdb->prefix;$id=(int)$wpdb->get_var("SELECT id FROM {$p}bn_posts ORDER BY reaction_count DESC LIMIT 1");
printf("post %d: stored=%d actual=%d\n",$id,
 (int)$wpdb->get_var("SELECT reaction_count FROM {$p}bn_posts WHERE id=$id"),
 (int)$wpdb->get_var("SELECT COUNT(*) FROM {$p}bn_reactions WHERE object_type=\"post\" AND object_id=$id"));'
```

A mismatch means a write bypassed the service — investigate before trusting any
scale measurement on that dataset.

### What each knob exercises

| Knob | Drives | Verify |
|---|---|---|
| `BN_SEED_USERS` | presence range scans, member directory, segment chunking | `EXPLAIN` the directory online filter → range scan on `bn_presence`, not a usermeta CAST |
| `BN_SEED_POWER_FOLLOWS` | the home-feed "people I follow" subquery + the follow cap | follow loop stops at the cap; time `FeedService::home_feed` for the power user |
| `BN_SEED_VIRAL_REACTIONS` | hot-row counter maintenance + `bn_analytics_events` volume | stored `reaction_count` == actual; recount drift = 0 |
| `BN_SEED_SPACE_MEMBERS` | `member_count` + per-space role/ban permission checks (C1) | `member_count` == active rows; load the space page, watch query count |
| `BN_SEED_POSTS` | feed render, post-card counters, search index | search index populated; counters consistent |

### Cache + EXPLAIN checks at volume

```bash
# Object cache must be persistent for caching to be load-bearing — confirm:
wp eval 'echo wp_using_ext_object_cache() ? "persistent cache: yes\n" : "NO persistent cache — install Redis\n";'

# Presence directory query plan (item F): want a range scan on the last_active key.
wp db query "EXPLAIN SELECT user_id FROM $(wp db prefix --allow-root 2>/dev/null)bn_presence WHERE last_active > UNIX_TIMESTAMP()-300 ORDER BY last_active DESC LIMIT 20"
```

## 6. One-shot "is everything still green" pass

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
