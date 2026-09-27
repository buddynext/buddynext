# Page-cache compatibility bench

Proves BuddyNext works under real page-cache and optimisation plugins with no
owner setup: every page a member is logged in on is served fresh, and guests
still get cached public pages.

```bash
cd docker/cache-test
docker compose --profile litespeed up -d   # Apache site :8091 + OpenLiteSpeed site :8092
./setup.sh                                 # install the Apache site
./setup.sh litespeed                       # install the OpenLiteSpeed site
./check.sh none
./check.sh wp-super-cache
./check.sh w3-total-cache
./check.sh litespeed-cache
docker compose --profile litespeed down -v # remove everything
```

BuddyNext is mounted read-only from this checkout, so the bench always runs the
code in your working tree. Both sites run a Redis persistent object cache.
Logins: `admin` / `admin`, `member` / `member`.

## Why this exists

Support ticket #41871 (LiteSpeed, `x-litespeed-cache: hit`): in onboarding,
Continue after Interests went back to step 1. The wizard reloads after saving a
step, and a page cache that caches logged-in visitors served the reload from
its copy of step 1. Only the login page told caches to stay away. A local site
without a page-cache plugin can never show this, so it is tested here against
the real plugins.

## What check.sh proves

Each cache is switched on in its riskiest setting for a community: caching
logged-in visitors too.

1. **The customer path.** The member loads `/onboarding/` at step 1 (twice, so a
   cache stores it), the step is advanced on the server, and the reload must
   show step 3.
2. **No community page is cached for a member.** Activity, Explore, the member
   directory, a profile, Spaces, Notifications, Settings and Onboarding are each
   loaded twice; the second load must be a fresh render.
3. **Guests still get the cache**, and never a cached login page.

"Fresh or cached" is read from a render stamp the bench adds to every page (a
`<meta name="bn-bench-render">` with a per-request id), not from any one
plugin's markers: the same id twice means the second copy came from a cache.

The checker exits non-zero on any failure.

## Results (1.2.2)

| Cache | Without the fix | With the fix |
|---|---|---|
| none | 0 failures | 0 failures |
| WP Super Cache | 9 failures ("Step 1 of 5") | 0 failures |
| W3 Total Cache | 9 failures ("Step 1 of 5") | 0 failures |
| LiteSpeed Cache (OpenLiteSpeed) | 9 failures ("Step 1 of 5") | 0 failures |

To see the "without" column yourself, revert the fix in
`includes/Core/PageRouter.php` (the `'auth' === $hub || is_user_logged_in()`
condition back to `'auth' === $hub`) and re-run a check.

## QA checklist

Run on the bench, then tick on the card.

**Page cache (scripted)**

- [ ] `./check.sh none` - 0 failures (the bench itself is sound)
- [ ] `./check.sh wp-super-cache` - 0 failures
- [ ] `./check.sh w3-total-cache` - 0 failures
- [ ] `./check.sh litespeed-cache` - 0 failures, and `curl -sI http://127.0.0.1:8092/spaces/` shows `x-litespeed-cache: hit` for a guest
- [ ] With the fix reverted, each of the three shows the onboarding FAIL (proves the check can fail)

**Script optimisation (in a browser, as `member`)**

Reset the member first: `docker compose exec -T cli wp user meta update 2 bn_onboarding_step 1`
(use `cli-ols` for the OpenLiteSpeed site).

- [ ] Autoptimize on the Apache site: activate it, set JS, aggregate JS, include inline JS, CSS and "Also optimize for logged in editors/ administrators" on. Log in as member at http://127.0.0.1:8091/wp-login.php, open `/onboarding/`, Continue, pick an interest, Continue: the page shows **Step 3 of 5**, the console has no errors.
- [ ] LiteSpeed on the OpenLiteSpeed site: Cache Logged-in Users, JS minify, JS Combine, Load JS Deferred = Delayed, CSS minify and combine, and "Optimize for Guests Only" off. Same onboarding path at http://127.0.0.1:8092: **Step 3 of 5**, no console errors.
- [ ] Same LiteSpeed settings: post "hello" from the activity composer; it appears in the feed and the composer clears.

**Docs**

- [ ] `docs/website/getting-started/09a-page-cache-and-optimisation.md` states only what this bench proved, and lists what was not tested.

## Notes and traps

- **Downloads happen on the host.** `setup.sh` fetches WordPress and the cache
  plugins into `zips/` (gitignored), because downloads from inside the CLI
  container have stalled.
- **OpenLiteSpeed reads `.htaccess` only at start-up.** Both scripts restart it
  after anything writes rewrite rules.
- **`wp litespeed-purge` cannot reach the server** from the CLI container, so
  `check.sh` clears LiteSpeed's cache store (`/usr/local/lsws/cachedata`)
  directly. A stale store makes guest checks fail with "not cached".
- **Optimisers strip HTML comments**, which is why the render stamp is a `<meta>`
  tag. `check.sh` switches optimisers off before each run.
- **Not covered:** WP Rocket (commercial), Cloudflare APO and hand-written proxy
  rules. Any of them works if it honours `DONOTCACHEPAGE` or bypasses the
  `wordpress_logged_in_` cookie.
