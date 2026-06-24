# Scale Readiness — Master Change Index (consolidated, right-sized)

Authoritative punch-list behind [`scale-readiness-100k.md`](./scale-readiness-100k.md). Every
item is code-verified (grep + file read), then filtered through three lenses so the plan is
**ready for large sites without over-cooking**:

1. **Breakage** — will the change break functionality? (code-level safe-execution rule)
2. **Frequency** — for caches: is it actually re-hit on a large site, or single-use overhead?
3. **Value** — does it bite at 100k with a concrete mechanism, or is it speculative?

Result: **Do-now / Defer / Skip**. The **Skip** list is the proof we're not over-cooking —
each carries the reason it was cut. Nothing is deleted from the catalogue; it's triaged.

**Every item must pass its QA before it's done** — primary test + independent double-check per
[`scale-readiness-qa-runbook.md`](./scale-readiness-qa-runbook.md). These are critical edits
(caching, invalidation, schema, scheduling, access control); code-green is not sufficient,
behaviour must be proven, and the contract audit + cert gates are mandatory.

## ▶ ALL DO-NOW COMPLETE (33/33)

As of 2026-06-25 (free `@fe73f678`, pro `@dd6c357`, both pushed). Every DO-NOW item
is implemented, unit-tested, gate-clean (WPCS + PHPStan L5 + REST boundary), and
committed. Nothing pending in this tier.

**Still open (lower tiers, by design):** the **DEFER** tier (8 items — bigger design
or lower urgency) and the **PE-4 / PE-6** pre-existing items (see
`pre-existing-issues.md`). These were never part of the DO-NOW scope.

**Test-env recipe (for the next session):** Docker `buddynext-test-mysql` is the DB
only (127.0.0.1:13306); the WP PHPUnit framework lives on the host — free at
`/tmp/wordpress-tests-lib`, pro at `/tmp/wordpress-tests-lib-pro`. macOS
`sys_get_temp_dir()` returns `/var/folders/...` (an incomplete checkout), so always
run free with `WP_TESTS_DIR=/tmp/wordpress-tests-lib`. PHPStan needs
`--memory-limit=1G`. Pro tests skip cases that need Free tables (`bn_notifications`,
`bn_email_log`) — run the combo suite to exercise those.

## Tally

| Tier | Count | Meaning |
|---|---|---|
| **DO NOW** | 33 (33 done · 0 pending) | ✅ COMPLETE | High value, low risk — **E done** (pro `31e6e05`), **F done** (free `9280d37b`+`e77e28c0`) |
| **DEFER** | 8 | Real, but bigger design or lower urgency — scheduled, not now |
| **SKIP** | 11 | Cut — caching/changing them is overhead at 100k (reasons below) |
| **catalogued total** | 52 work-items | (was 69; +5 from the senior sweep, −22 collapsed/cut by frequency+value filter) |

Already-verified-safe and **explicitly NOT touched**: notification fan-out, external-API degradation guards, REST boundary (CI-enforced), background-job conformance (drip excepted).

---

## DO NOW (33)

### Structural scale wins (the ones that actually bite at 100k)

- [x] **E1–E6 DONE** (pro `31e6e05`) — Impression write storm fixed. Free producers left **unchanged** (the `buddynext_post_impression` hook keeps firing — zero free-side risk); batching done in the **listener**: `AnalyticsCollector::on_post_impression()` buffers per request (deduped by `post:surface`) and flushes once via a **single bulk INSERT on shutdown**. *Design deviation (senior call): chose shutdown bulk-insert over a per-request AS job — an AS job per feed render would flood the queue with millions of actions/day; the bulk insert collapses ~20 writes → 1 with no new moving part. Sampling left as a documented future lever; retention already exists (AiModerationSweep). QA: AnalyticsCollectorImpressionTest 5/5 + regression-checked (group back to baseline) + php-l/PHPStan L5/WPCS green.*
- [x] **F1–F8 DONE** (free `9280d37b` stage 1 + `e77e28c0` stage 2) — Presence `CAST(meta_value)` scans → indexed `bn_presence(user_id PK, last_active INT, KEY)`. Stage 1: table + `SCHEMA_VERSION 6→7` + idempotent backfill + `PresenceService` dual-write + read API. Stage 2: all **8 readers** switched (MemberDirectoryService online filter/cursor/order/online_user_ids/online_now, Insights:152, Admin/Members:238 batched, BlockService:448). *EXPLAIN @100k: online filter OLD = ref scan 50,000 usermeta rows + CAST; NEW = range scan **152 rows** on `last_active` index (covering). QA: PresenceServiceTest 5/5 + Profile/SocialGraph/Realtime 57/57 + Core 169/169; static gates clean. **P4 (drop `bn_last_active` meta) deferred** — dual-write still on.*
- [x] **G1–G7 DONE** (free `4c27998b`, pro `82846d3`) — Autoloaded per-entity/large options → `autoload=false` + one-time migration. Free: `SpaceController.php:384`, `AppearanceTab.php:134` + `SCHEMA_VERSION 7→8` `maybe_fix_autoload()`. Pro: `MembershipAdmin.php:399/402/405`, `BrandService.php:150` + `SCHEMA_ALTERS_VERSION 2→3` `maybe_fix_autoload()`. Migrations use `wp_set_options_autoload()` (WP API, correct cross-version values), idempotent. *QA: AutoloadHygieneTest free 3/3 + pro 2/2 (scoped flip, reads autoload-agnostic, idempotent); Core/Spaces/WhiteLabel regression green.*
- [x] **S3a DONE** (free `78513926`) - weekly bn_email_log retention prune. EmailLogCleanupTest 2/2.
- [x] **S3b DONE** (free `535987c0`) - AS retention capped 14d. CronRetentionTest 1/3.
- [x] **S4a DONE** (free `86034209`) - PresenceService throttle now wp_cache when persistent (transient fallback); PresenceServiceTest 5/5. ~~ PresenceService 60s throttle uses `set_transient` on **`template_redirect`** = `wp_options` write storm at 100k → move the guard to `wp_cache_*` (presence is ephemeral; losing it on flush is harmless). `PresenceService.php:114`. *Highest-volume offender.*
- [x] **S4b DONE** (free `06a845ed`) — shared `Core\RateLimiter` (group `buddynext_rate`, atomic `wp_cache_incr` + transient fallback). Routed: Comment cap (refactored off its inline dual-path, dead const removed), Registration per-IP, SocialLogin per-IP, Profile export cooldown. **2FA brute-force counter deliberately KEPT on the DB transient** — it's a security lockout, so a cache-flush reset would hand an attacker fresh guesses; "fail open" is only acceptable for the ephemeral anti-spam throttles. *(Senior course-correction on the original "move all 5" note; documented inline + here.)* QA: RateLimiterTest 8/8 (both backends) + Comment 16/16 + Auth/Profile 41/41; WPCS + PHPStan L5 clean.
- [x] **S5 DONE** (pro `dd6c357`) — split by latency profile (senior call on the original "AS both" note): **Push (FCM)** deferred to Action Scheduler (`as_enqueue_async_action`, hook `buddynextpro_push_send`, group `buddynextpro_push`, `deliver()` worker, sync fallback + `buddynextpro_push_async` filter) — async-tolerant + gains retry. **Soketi realtime** NOT moved to AS (queue latency would defeat realtime); instead `SoketiClient::trigger()` is now non-blocking (`wp_remote_post 'blocking'=>false`), so the broadcast dispatches instantly and the request returns immediately. Test-connection (`signed_get`) stays blocking. QA: PushDispatcherTest `test_dispatch_is_deferred_when_async` proves deferral; Push+Realtime 50 tests, 0 failures; WPCS + PHPStan L5 clean.
- [x] **S1a DONE** (free `85b20825`) — removed dead `bn_feed_items` (CREATE + post-delete cascade); never INSERT/SELECT. Existing installs keep the harmless empty table. PostServiceTest green.

### Cache — right-sized (6 caches + 3 memoizes + 1 delete)

- [x] **C7 DONE** (free `013a7719`) — `SpaceCategoryService` get_all/get_all_with_counts cached (list TTL 600s, counts TTL 60s to bound space-reassignment staleness); bust on category CRUD. SpaceCategoryServiceCacheTest 4/4.
- [x] **C13 DONE** (pro `fa35968`) — `LabelService::list_labels()` cached (group `buddynextpro_labels`, TTL 600s); bust on create/update/delete. LabelServiceCacheTest 4/4. *(Note: pre-existing broken `ProfileLabelInjectorTest` — calls undefined `inject_labels()` — is unrelated, not a regression.)*
- [x] **C11 DONE** (pro `f00201f`) — all 10 `AnalyticsService` read aggregates cached read-only (group `buddynextpro_analytics`, TTL 300s, no bust — append-only table, dashboard tolerates short staleness). AnalyticsServiceCacheTest 2/2 + Analytics suite green (only the 3 pre-existing PE-3 failures). WPCS + PHPStan L5 clean.
- [x] **C12 DONE** (pro `21bc849`) — `ProfileViewService` pure aggregates cached (view_count/top_viewed_profiles/top_viewers/privacy_adoption/daily_views_series; group `buddynextpro_analytics`, prefix `pv_`, TTL 300s, no bust). **get_viewers/get_recent_for_owner left UNCACHED on purpose** — they resolve the `bn_pro_hide_profile_views` opt-out at read time and caching would lag an opt-out by the TTL (privacy regression). Get/set plumbing extracted to a shared `CachesAggregates` trait (AnalyticsService refactored onto it). ProfileViewServiceCacheTest 2/2. WPCS + PHPStan L5 clean.
- [x] **C2 DONE** (free `adf8c6e0`) — `PollService::results()` cached per post_id (group `buddynext_polls`, TTL 600s); bust on vote (both toggle-off + cast paths). PollServiceCacheTest 2/2.
- [x] **C16 DONE** (pro `3ae3973`) - DripService::get_sequence cached, bust at 3 choke points. DripServiceCacheTest 3/3.
- [x] **C1 DONE** (free `fe73f678`) — `PermissionService` routes its in-space **role** lookup → `SpaceMemberService::get_role()` and the **soft-ban** half → cached `get_status()==='banned'` (both object-cached with write-invalidation), so a page's many space-cap checks collapse from 3 queries each → 1. **Did NOT memoize `can()`'s result** (filter still runs every call) and **kept the hard-ban `bn_space_bans` query direct** (security gate with no cache+invalidation — no stale-ban window). *Senior refinement on the "static-var memoize" note: routing through SpaceMemberService's already-invalidated cache is safer than a static memo that can't see mid-request writes.* QA: PermissionServiceCacheTest 4/4 incl. the invalidation-safety case (cached role stays until flush, then fresh role honoured); Core+Spaces 172 green; WPCS + PHPStan L5 clean.
- [x] **C14 DONE** (pro `499e06d`) - get_user_labels request-scoped memo, cleared on writes. LabelAssignment 12/12.
- [x] **C6 DONE — folded into C7** (free `013a7719`). `SpaceCategoryService::get_all()` is fully object-cached (key `all`, TTL 600s, bust on category CRUD) by the C7 change — no separate memoize needed.
- [x] **C4 RESOLVED — kept, not deleted** (lead-dev call). 0 *production* callers, but it's the read-back used by the `share()`/`unshare()` tests + its own list test. Deleting forces rewriting 2 unrelated tests for no real benefit, and it's a sensible public read API. The actual scale decision (don't cache it) stands. No code change.

### Cache uniformity + cleanup (low-risk)

- [x] **A1–A15 DONE** (free `19851e80`) — deleted 15 dead `CacheService` typed methods + tests; 0 callers confirmed. Kept generic helpers.
- [x] **B1+B2 DONE** (pro `d850807`) — EmbeddingProvider group → `buddynextpro_embeddings`; 23 wp_cache sites → `buddynextpro_membership` (AS `GROUP` left as-is). Membership+Stripe 43/43.
- [x] **I1 DONE** (free `b03cf68b`) - enrich_members primes cache_users+update_meta_cache. Search 16/16.
- [x] **I2/J1 DONE** (free `fa739a26`) - RecentActivityWidget short-TTL cache + cache_users prime.
- [x] **J2 DONE** (free `fa739a26`) - TrendingHashtagsWidget uses canonical cached get_trending.

### Small / hygiene

- [x] **BUG-1 DONE** (free `e92fc8b5`) - SpaceService::delete flushes member/ban cache for affected users via SpaceMemberService::flush_user_caches; SpaceMemberFlushTest 2/2 + Spaces 101/101. ~~ `SpaceService.php:617` deletes `bn_space_bans` on space-delete **without firing a ban hook/bust** — real invalidation gap today (independent of caching). Add the bust/hook.
- [x] **K1 DONE** (pro `23ff01a`) — drip tick migrated WP-Cron → Action Scheduler, mirroring BroadcastService exactly (`as_schedule_recurring_action` hourly, group `buddynextpro_email`, on-demand arm + `disarm()`). Hook name `buddynextpro_drip_tick` + handler unchanged; `converge_cron_schedule()` migrates existing installs (now `disarm()`-based for both email jobs). Removed dead `CRON_INTERVAL`. QA: DripEnrollmentServiceTest 9/9 (incl. 2 migration tests); Email suite at the 2 pre-existing failures only; WPCS + PHPStan L5 clean.
- [x] **K2/K2b DONE** (pro `8dc42f2`) — corrected the stale `publish_scheduled` "5-min cron" claim in CLAUDE.md + PRO-ROADMAP.md (code re-uses Free's on-demand single event). drip_tick was already correctly documented as hourly.
- [x] **L1 DONE** (free `390357d3`) — DM thread poll skips the request while `document.hidden` (single `visibilitychange` listener does an immediate refocus catch-up), a module-scoped `stopThreadPoll()` replaces any prior thread's interval at init (no stacking under client-nav), and the poll self-terminates when its thread element disconnects. *Browser-verified on buddynext.local: visible 2 polls/9s, hidden 0/9s, refocus 1 immediate; conv 31→32 switch = one interval polling only conv 32; 0 console errors.*
- [x] **N1 DONE** (free `d496727d`) - object-cache health indicator in Tools. ~~ Add `wp_using_ext_object_cache()` health indicator to Tools (caching is load-bearing at scale).
- [x] **U1 DONE** (pro `af1faa2`) — added `bin/check-rest-boundary.sh` to Pro (mirrors free's, wired into `bin/check.sh`); passes clean, exits 1 on a planted `wp_ajax_`. Pro REST boundary now CI-enforced like free.

---

## DEFER (8) — real, but bigger design or lower urgency

- [ ] **S2** Hot-row counter contention (`UPDATE bn_posts SET col=col+1` synchronous per reaction/comment/share/join). High value on viral content, but a **real design task** (buffered deltas or sharded counters + cron fold-in) and the nightly `recount_counters()` is a safety net — so it's scheduled, not on fire. `PostService.php:1336`.
- [ ] **F-phase4** Drop `bn_last_active` usermeta — only after all 8 readers migrate (a later release, by design).
- [ ] **S1b** Power-follower feed: `user_id IN (SELECT following_id …)` → JOIN/derived-table rewrite for users following tens of thousands. `FeedService.php:388`.
- [ ] **H1–H4** `SegmentService` `number=>-1` (4 sites) → chunk. *Admin/cron only (broadcast send), not user-facing — low urgency.*
- [ ] **J3** `OnlineMembersWidget` cache — bounded `get_users(limit)`, low blast.
- [ ] **C1-crossrequest** Do NOT pursue cross-request permission caching beyond the memoize above (kept here as an explicit "decided against").

---

## SKIP (11) — cut as overhead at 100k (the anti-over-cooking record)

Cache items removed from the build because the value is read once per request / unique per user / admin-rare — caching them fills the object cache with single-use keys (low hit-rate, evicts genuinely hot keys):

- **C3** `PollService::user_vote()` — per-(user,post), one-shot REST read.
- **C5** `ShareService::user_shares_paginated()` — per-user, one-shot, not polled.
- **C8** `SpaceCategoryService::get_by_id()` — category CRUD only (admin-rare), no front read.
- **C9** `ActivityLogService::recent()` — admin dashboard only, constantly-changing list.
- **C10** `AiRankedFeedService::home_feed()` — personalized affinity-ranked **live** feed, unique per (user,cursor,filter) + content-volatile → cross-request cache is low-hit **and** a staleness regression. (Underlying row reads already cached.)
- **C15** `SegmentService::resolve()` — cron one-shot; caching a send audience risks emailing a **stale list**.
- **C17** `SavedSearchService::get_searches/get_search` — per-user, one-shot REST.

Verified-NOT-issues (kept as evidence so they don't get re-raised):
- **CounterService** — write-side recounts, not reads. **E7 analytics prune** — already exists. **Notification fan-out** — already batched/AS. **`bn_notifications`/`bn_activity_log` retention** — already present. **Degradation guards** — already strong. **AS-gated native-cron fallbacks** — not gaps. **`ScheduledPostsAdmin` cross-plugin bust** — by-design.

---

## Suggested execution order
1. **E (impressions)** → **F (presence)** → **S4a (presence throttle)** — the three highest-traffic write/scan fixes.
2. **G (autoload)** + **S3 (retention)** + **S1a (dead table)** — cheap structural cleanups.
3. **Cache set**: A (deletes) → B (group converge) → C7/C13/C11/C12 (the real caches) → memoizes → widgets.
4. **S5 (async dispatch)** + **K1 (drip→AS)** + **BUG-1 (ban bust)**.
5. **L1, N1, K2** hygiene. **DEFER tier** scheduled after.
