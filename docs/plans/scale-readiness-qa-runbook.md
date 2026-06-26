# Scale Readiness — QA Runbook (mandatory double-checks)

These are **critical edits** (caching, invalidation, schema, scheduling, access control). Every
item in [`scale-readiness-change-index.md`](./scale-readiness-change-index.md) ships only when its
**primary test** AND an **independent double-check** both pass — code-green is not enough, behaviour
must be proven. Tie-in to existing project gates is mandatory.

## Coverage: free + pro both

Verified via parallel-surface grep — no missed mirrors:
- Counter contention (S2): **free-only** (`PostService`); Pro has no synchronous `col=col±1`.
- Frontend poll pause (L1): **free-only**; Pro realtime is WebSocket, no `setInterval`.
- Autoload (G): free (`SpaceController`,`AppearanceTab`) + Pro (`MembershipAdmin`,`BrandService`) — both.
- Cache group convergence (B): **Pro-only** (free already uniform).
- Impressions (E): free fires + **Pro records** — both sides tested.
- Pro transients are token/OAuth/AI caches (correct by standard), NOT rate-limit storms — no item.

## Universal gates — run for EVERY item (no exceptions)

1. **TDD**: write the failing test FIRST (per CLAUDE.md §3), then implement, then green.
2. `bin/check.sh --staged` → **php -l + WPCS + PHPStan level 5 + ux-audit** clean.
3. `vendor/bin/phpunit` (the item's domain test) green.
4. **Contract audit** — `php ~/.claude/skills/wp-contract-audit/scripts/contract-audit.php buddynext --pair=buddynext-pro` → 0 new findings. **Mandatory for every cache key/group change** (catches read-never-written / key drift — exactly the B/C risk class).
5. `wp buddynext cert` (functional certification) green — and promote each confirmed behaviour to a cert oracle so it can never silently regress.
6. `wp-plugin-smoke` (free + combo) before any release tag.
7. UI items (L1, N1): browser-verify at **390px + dark**, hover/focus states (per CLAUDE.md verify-per-item rule).

---

## Per-item QA (primary test + independent double-check)

### A — Delete 15 dead `CacheService` methods
- **Risk:** deleting a method still referenced → fatal.
- **Primary:** delete methods **+ their `CacheServiceTest` assertions in the same commit**; `php -l`; PHPStan (flags any undefined-method use).
- **Double-check:** `grep -rn "<method>" includes/ tests/` across **both** repos = 0 for all 15; plugin boot smoke (activate clean). Also grep `call_user_func`/variable-method dispatch = none.
- **Scope:** free.

### B — Pro cache-group convergence (B1, B2)
- **Risk:** half-rename → stale read (e.g. cancelled subscription still "active" for up to TTL).
- **Primary:** unit test per renamed key — `set` → `get` (hit) → `delete` → `get` (miss), all on the new group; `grep "'buddynextpro'"` (bare) and `'buddynext_pro_embeddings'` = 0 after edit.
- **Double-check (functional):** cancel a subscription / change membership → assert status flips **immediately** (not after 60s); contract-audit clean. Confirm `SubscriptionService::GROUP` (AS group) was **not** touched (`wp action-scheduler` still lists its actions).
- **Scope:** Pro. Atomic per-key, single commit.

### C — Caches added (C7, C13, C11, C12, C2, C16)
- **Risk:** stale data after a write (missed bust); wrong/colliding key.
- **Primary:** per cache, a test: read (miss→DB), read (hit), perform the mutating write, read (must reflect new value). For read-only aggregates (C11/C12) assert TTL expiry only.
- **Double-check (browser/DB):** C7 add a space category → appears in spaces directory + explore aside immediately; C13 add a label → shows in search filters; C2 vote → results update; C11/C12 dashboard numbers match a raw `SELECT COUNT(*)`.
- **Scope:** C7/C2 free; C11/C12/C13/C16 Pro.

### C — Memoizes (C1, C14, C6) — **access-control critical (C1)**
- **Risk:** C1 — a stale permission within/after a change = **security bug**; freezing the `buddynext_user_can` filter.
- **Primary:** memo is a **request-scoped static** (naturally reset each request). Test: (a) same `can()` called twice in one request hits DB once; (b) **new request after a role/ban change returns the new verdict**; (c) a `buddynext_user_can` deny filter still takes effect (memo sits BELOW the filter — cache the raw lookup, not `can()`'s result).
- **Double-check (security):** ban a user from a space → they are denied on the **very next request**; grant via webhook → allowed next request. Confirm the **BUG-1** bust at `SpaceService.php:617` fires on space-delete ban-cascade.
- **Scope:** free.

### E — Impression batching → AS
- **Risk:** lost or double-counted impressions; flush never fires.
- **Primary:** render a 20-post feed → assert impressions **buffered** (not inserted inline); assert **one** AS job enqueued (not 20); run the job → assert N rows inserted once (dedup by post_id).
- **Double-check:** analytics dashboard impression count before/after a known render matches; `cert` confirms no synchronous reader of `bn_analytics_events` breaks (none found, re-assert).
- **Scope:** free (4 producers) + **Pro** (`AnalyticsCollector` + new AS handler).

### F — `bn_presence` table (4-phase, **do not rush**)
- **Risk:** dropping `bn_last_active` before all **8** readers move; online filter wrong; bad migration.
- **Primary (per phase):** P1 `wp db tables` shows `bn_presence` with `INDEX(last_active)`. P2 a heartbeat writes **both** meta and table. P3 each of the **8 readers** (directory online filter `:274`, cursor `:299`, sort `:331`, `online_user_ids:584`, `online_now:622/636`, `Insights:152`, **`Admin/Members:238`**, `MemberDirectoryService:831`) returns the **same online set** as before.
- **Double-check:** `EXPLAIN` the new directory query → uses the index, **no filesort / no full usermeta scan** (the whole point); compare the online-members list pre/post = identical. P4 (drop meta) only in a later release after P3 verified.
- **Scope:** free.

### G — Autoload = false (G1–G7)
- **Risk:** migration SQL corrupts the autoload column; reads change.
- **Primary:** `get_option()` returns the identical value pre/post (autoload-agnostic); new writes set `autoload='no'`; G7 migration is **idempotent + version-gated**, uses WP API (`update_option`/`wp_set_option_autoload`), not raw SQL.
- **Double-check:** `SELECT autoload FROM wp_options WHERE option_name LIKE 'bn_space_%'` = `no` after migration; measure `alloptions` size before/after (must shrink). Re-run migration → no-op.
- **Scope:** free + Pro.

### S3 — Retention (email_log + AS)
- **Risk:** deletes wrong rows; cron not scheduled; unbounded delete.
- **Primary:** seed old + recent `bn_email_log` rows → run cleanup → only rows older than `buddynext_data_retention_days` deleted, **batched** (LIMIT per iteration); `retention_days=0` disables.
- **Double-check:** `wp action-scheduler list` shows the weekly job in the right group; AS retention filter present.
- **Scope:** free (email_log) + AS (both).

### S4 — Presence throttle / limiters → object cache
- **Risk:** throttle breaks (write storm returns) or never throttles (write every request).
- **Primary:** with a persistent object cache, two page views within 60s → **one** `bn_last_active` write; without one, falls back to transient (still throttles).
- **Double-check:** with Redis active, assert **zero `wp_options` writes** per page view from the presence guard (query log / `SAVEQUERIES`).
- **Scope:** free (PresenceService priority; other limiters secondary).

### S5 — Async Push/Soketi dispatch
- **Risk:** notification not delivered or delivered twice; latency unchanged.
- **Primary:** create a notification → assert an AS job is enqueued and the request returns **without** the FCM/Soketi HTTP call; run the job → push sent **once**.
- **Double-check:** end-to-end — device receives exactly one push; reaction/post request latency no longer includes the 10s FCM timeout window.
- **Scope:** Pro.

### S1a — Remove dead `bn_feed_items`
- **Risk:** something references it (verified 0 read/insert).
- **Primary:** grep confirms 0 SELECT/INSERT both repos; remove from installer; **fresh** install has no table.
- **Double-check:** do **not** DROP on existing installs (data-safety; harmless empty table); install smoke passes.
- **Scope:** free.

### K1 — Drip tick → AS
- **Risk:** drip stops firing for installs with active enrollments.
- **Primary:** enroll → assert AS recurring action armed in group `buddynextpro_email`, **old `wp_schedule_event` cleared**, hook name unchanged (`buddynextpro_drip_tick`); tick processes due + disarms when drained.
- **Double-check:** `wp action-scheduler list --group=buddynextpro_email`; create an enrollment, advance time, confirm the drip step actually sends.
- **Scope:** Pro.

### L1 — DM poll hidden-tab pause
- **Risk:** polling stops permanently; timer leak.
- **Primary (browser):** open conversation → poll fires (5s); switch tab (`document.hidden`) → poll pauses; return → resumes; close conversation → `clearInterval` (no orphaned timer in devtools).
- **Double-check:** network panel shows no `/messages` polling while tab hidden / conversation closed.
- **Scope:** free.

### N1 — Object-cache health indicator
- **Risk:** none (read-only display).
- **Primary:** Tools health shows "active" with Redis on, "absent" with it off (`wp_using_ext_object_cache()`); browser 390px + dark.
- **Scope:** free.

### U1 — Pro REST-boundary gate parity
- **Primary:** add `bin/check-rest-boundary.sh` to Pro (mirror free) → runs green (0 `admin-ajax`/`wp_ajax_` in app code); wire into Pro `bin/build-release.sh`.
- **Double-check:** intentionally add a throwaway `wp_ajax_` handler → gate **fails** (proves it works) → remove it.
- **Scope:** Pro.

---

## Per-item sign-off table (fill on execution)

| Item | Primary test ✅ | Double-check ✅ | Gates (check.sh/phpunit/contract/cert) ✅ | Free | Pro | Signed |
|---|---|---|---|---|---|---|
| A | | | | ✓ | — | |
| B1/B2 | | | | — | ✓ | |
| C7/C13/C11/C12/C2/C16 | | | | C7,C2 | rest | |
| C1/C14/C6 (memo) | | | | ✓ | — | |
| E1–E6 | | | | ✓ | ✓ | |
| F1–F8 | | | | ✓ | — | |
| G1–G7 | | | | ✓ | ✓ | |
| S3a/S3b | | | | ✓ | — | |
| S4a/S4b | | | | ✓ | — | |
| S5 | | | | — | ✓ | |
| S1a | | | | ✓ | — | |
| BUG-1 | | | | ✓ | — | |
| K1 | | | | — | ✓ | |
| L1 | | | | ✓ | — | |
| N1 | | | | ✓ | — | |
| U1 | | | | — | ✓ | |

**Release rule:** no item moves to done until both check columns are ticked AND the four gates pass. No batching the verification to the end — verify per item (CLAUDE.md verify-per-item rule).
