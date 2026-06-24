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

## Tally

| Tier | Count | Meaning |
|---|---|---|
| **DO NOW** | 33 | High value, low risk, ready-for-scale |
| **DEFER** | 8 | Real, but bigger design or lower urgency — scheduled, not now |
| **SKIP** | 11 | Cut — caching/changing them is overhead at 100k (reasons below) |
| **catalogued total** | 52 work-items | (was 69; +5 from the senior sweep, −22 collapsed/cut by frequency+value filter) |

Already-verified-safe and **explicitly NOT touched**: notification fan-out, external-API degradation guards, REST boundary (CI-enforced), background-job conformance (drip excepted).

---

## DO NOW (33)

### Structural scale wins (the ones that actually bite at 100k)

- [ ] **E1–E6** Impression write storm → buffer per request + flush via one AS bulk-insert. `FeedService.php:345/861/982/1088` (producers), `buddynext-pro/Analytics/AnalyticsCollector.php:35/90/473` (listener), +1 new AS handler. *Safe: lossy-async is correct for analytics; no synchronous reader depends on impression rows. Retention already exists.*
- [ ] **F1–F8** Presence `CAST(meta_value)` scans → indexed `bn_presence(user_id PK, last_active INT, INDEX)`. `MemberDirectoryService.php:274/299/331/584/622/636`, `Insights.php:152`, new `Installer` table, `PresenceService.php:121` dual-write. *Safe: 4-phase rollout (add → dual-write → switch **all 8 readers** → drop meta later). **8 readers, not 6** — also `Admin/Members.php:238`. Do NOT drop `bn_last_active` until all 8 move.*
- [ ] **G1–G7** Autoloaded per-entity/large options → `autoload=false` + one-time migration. `SpaceController.php:384`, `MembershipAdmin.php:399/402/405`, `AppearanceTab.php:134`, `BrandService.php:150`, migration via `maybe_upgrade()` (WP API, not raw SQL). *Safe: `get_option` is autoload-agnostic — reads can't break.*
- [ ] **S3a** `bn_email_log` grows forever → add weekly `handle_cleanup_email_log` prune (mirror `handle_cleanup_activity_log`, gated on `buddynext_data_retention_days`). *Fastest-growing table at 100k.*
- [ ] **S3b** Action Scheduler tables untuned → set `action_scheduler_retention_period` filter (7–14d) so fan-out's completed actions don't accumulate.
- [ ] **S4a** PresenceService 60s throttle uses `set_transient` on **`template_redirect`** = `wp_options` write storm at 100k → move the guard to `wp_cache_*` (presence is ephemeral; losing it on flush is harmless). `PresenceService.php:114`. *Highest-volume offender.*
- [ ] **S4b** Other limiters (Comment/Registration/SocialLogin/Profile/2FA) → use `wp_cache_*` when `wp_using_ext_object_cache()`, transient fallback otherwise.
- [ ] **S5** Pro Push + Soketi dispatch fire **synchronously per notification** (blocking FCM/Soketi HTTP in-request; serializes fan-out workers) → enqueue via AS. `PushDispatcher.php:56`, `RealtimeDispatcher.php:61`. *Guards are fine; this is latency, not safety.*
- [ ] **S1a** `bn_feed_items` is a **dead table** (CREATE'd + delete-on-post-delete, never INSERT/SELECT) → remove from installer (stop shipping a confusing empty table). `Installer.php:797`.

### Cache — right-sized (6 caches + 3 memoizes + 1 delete)

- [ ] **C7** `SpaceCategoryService::get_all_with_counts()` — **top cache**: global list on 2 hot render paths (spaces directory + explore aside). Bust on space/category change.
- [ ] **C13** `LabelService::list_labels()` — global catalogue on every search render. Bust on label CRUD.
- [ ] **C11** `AnalyticsService` 7 aggregates — heavy COUNT/GROUP-BY, all fired per dashboard load. Medium TTL, no bust (read-only).
- [ ] **C12** `ProfileViewService` aggregates — per-(profile,window), re-hit across viewers of a popular profile. Medium TTL.
- [ ] **C2** `PollService::results()` — one poll fetched by many viewers. Bust on `vote()`.
- [ ] **C16** `DripService` sequence defs — global, low-traffic (marginal; cheap-correct).
- [ ] **C1(memo)** `PermissionService::can()` → **within-request memoize** (static var), NOT cross-request object cache. Collapses the many same-(user,ability,space) checks per page (nav build + REST gate + template gates). *Avoids the security risk of stale role/ban + the frozen `buddynext_user_can` filter.*
- [ ] **C14(memo)** `LabelAssignmentService::get_user_labels()` → within-request memoize. Dedups repeated authors in the feed byline loop.
- [ ] **C6(memo)** `SpaceCategoryService::get_all()` → memoize or fold into C7's key (marginal).
- [ ] **C4(delete)** `ShareService::user_shares()` — **dead method, 0 callers** → delete, don't cache.

### Cache uniformity + cleanup (low-risk)

- [ ] **A1–A15** Delete 15 dead `CacheService` methods (0 callers) **+ their tests in the same commit**. Keep generic `get/set/delete/remember/forget_group`.
- [ ] **B1** `EmbeddingProvider` group `buddynext_pro_embeddings` → `buddynextpro_embeddings` (self-contained const, 2 sites).
- [ ] **B2** Bare `buddynextpro` cache literal → `buddynextpro_membership` const, **all 23 `wp_cache_*` sites atomically** across the 5 files. *Leave `SubscriptionService::GROUP` (it's the AS group).*
- [ ] **I1** `SearchService::enrich_members()` N+1 → `cache_users()` + `update_meta_cache('user', …)` before loop.
- [ ] **I2 / J1** `RecentActivityWidget` — prime `cache_users()` + route through `WidgetCache`.
- [ ] **J2** `TrendingHashtagsWidget` direct query → use cached `WidgetService::trending_hashtags()`.

### Small / hygiene

- [ ] **BUG-1** `SpaceService.php:617` deletes `bn_space_bans` on space-delete **without firing a ban hook/bust** — real invalidation gap today (independent of caching). Add the bust/hook.
- [ ] **K1** Drip tick native hourly cron → AS `buddynextpro_email`. *Safe: in `maybe_upgrade()` clear old `wp_schedule_event` + arm AS, **keep hook name `buddynextpro_drip_tick`** (handler unchanged).*
- [ ] **K2 + K2b** Correct stale Pro docs (phantom 5-min `publish_scheduled` cron + drip "5-min" mislabel; code says hourly). 4 files.
- [ ] **L1** DM poll `setInterval(poll,5000)` never paused/cleared → gate on `!document.hidden` + `clearInterval` on conversation close. `messages/store.js:1586`.
- [ ] **N1** Add `wp_using_ext_object_cache()` health indicator to Tools (caching is load-bearing at scale).
- [ ] **U1** Pro has **no `check-rest-boundary.sh`** gate (app code is already 100% REST, but ungated) → add one mirroring free's so the boundary is CI-enforced uniformly across both repos.

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
