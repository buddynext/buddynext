# Scale Readiness — DEFER Tier Scoping (2026-06-25)

The 33 DO-NOW items are complete. This doc scopes the **DEFER tier (8 sub-items)** —
the work deliberately held back as "bigger design or lower urgency." Each was
re-investigated at code level (two architect passes on the heavy items, inline
review on the rest). Purpose: decide what is safe to ship now vs what stays
deferred behind a real signal, so nothing is done speculatively on a high-blast-radius
path.

Governing rule (from the change index): **sign off before ANY code change here.**

## Verdict table

| Item | What | Verdict | Effort | Why |
|---|---|---|---|---|
| **F-phase4** | Drop `bn_last_active` usermeta | **READY NOW** | S | All 8 readers migrated to `bn_presence` (verified: zero meta reads remain in either repo). Pure cleanup. |
| **S2(c)** | Close counter drift-correction gap | **RECOMMEND NOW** | S | 3 of 4 hot counters have NO automated recount — a latent data-integrity gap, not a contention one. |
| **S2(a/b)** | Buffered/sharded counters | **KEEP DEFERRED** | M / L | Row-lock contention is sub-ms; build only behind a measured hot-post signal. |
| **S1b** | Power-follower feed JOIN rewrite | **KEEP DEFERRED** | S–L | `IN` is fine for realistic follow counts; real cost is a missing recency index, not IN-vs-JOIN. |
| **S1b-cap** | Follow cap (~5,000) | **PRODUCT DECISION** | S | Cheapest high-leverage bound on the subquery — but it's user-facing behaviour. |
| **H1–H4** | `SegmentService` `number=>-1` → chunk | **KEEP DEFERRED** | M | Admin/cron broadcast only; send already AS-batched; bites only the biggest sites. |
| **J3** | `OnlineMembersWidget` cache | **LOW PRIORITY** | S | Already bounded (≤20). Also misnamed — shows newest, not online. |
| **C1-crossrequest** | Cross-request permission cache | **DECIDED AGAINST** | — | Not a task; kept as an explicit "do not pursue." |

---

## S2 — Hot-row counter contention (architect pass)

**Inventory:** 4 hot counter columns across 3 tables, 15 synchronous `col±1` write
sites. `bn_posts` (reaction/comment/share) routed through
`PostService::increment_counter`/`decrement_counter` (`:1347`/`:1373`, underflow-safe
`GREATEST(1,col)-1`); `bn_spaces.member_count` via `SpaceMemberService::adjust_member_count`;
`bn_hashtags.follower_count` inline. Hottest: `bn_posts.reaction_count`/`comment_count`
on viral content.

**Safety net — the real finding:** the nightly `recount_counters()` (daily AS job
`buddynext_recount_stats`) reconciles **only** `bn_posts.reaction_count` + `comment_count`.
`share_count`, `member_count`, and hashtag counters have **no cron self-heal** — only a
manual admin Tools button (`CounterService`, wired to `ToolsTab` only). So drift on 3 of
4 counters is never auto-corrected.

**Options:** (a) buffered deltas in object cache → AS fold-in (M; needs persistent cache +
read-path folding + a sub-hour job the team just removed); (b) append-only shard table (L;
over-engineered for a mainstream-social product); (c) accept synchronous writes (sub-ms row
locks) + **extend recount coverage** + document the ceiling (S).

**Recommendation: keep S2 deferred; do (c) now.** Concrete (c) work:
1. Extend the daily recount to also reconcile `bn_posts.share_count` from `bn_shares`.
2. Wire `CounterService::recount_space_members` + `recount_hashtag_*` into the daily AS job
   (currently manual-only), so member/hashtag drift self-heals.
3. Document the row-lock ceiling + the buffered-delta + per-post hot-mode activation plan in
   `docs/standards/DATA-AT-SCALE.md`.
Defer (a) until telemetry shows a hot post; do not build (b).

---

## S1b — Power-follower feed (architect pass)

**Current SQL** (`FeedService::home_source_clause`, `:392`/`:429`):
`user_id IN (SELECT following_id FROM bn_follows WHERE follower_id = %d)` — used by the
`following` tab and as one of five OR'd branches in `for-you`.

**Index facts:** `bn_follows` PRIMARY KEY `(follower_id, following_id)` fully covers the
edge — `WHERE follower_id=%d` is a pure prefix scan. `bn_posts` has
`(user_id,status,created_at)` but **no** `(status,created_at)` / `created_at` index, so the
global recency sort filesorts regardless of IN vs JOIN.

**Key conclusions:**
- MySQL 8 already semi-joins `IN (subquery)`; for realistic users (hundreds–low-thousands
  of follows) there is no measurable problem. "Tens of thousands" is a thin tail.
- The IN→JOIN swap does **not** fix the real cost (the missing recency index), so S1b alone
  is low-leverage.
- The `following`-tab-only JOIN is **S** (no DISTINCT needed — PK makes it 1:1). The full
  `for-you` UNION/derived rewrite is **L** on the highest-blast-radius read path (privacy
  guards, self-post exclusion, pending-follow inclusion, block/mute, excluded-spaces).

**Recommendation: keep S1b deferred.** Cheapest interim, in order: (a) **add a follow cap**
(~5,000, FB/X/LinkedIn norm) — bounds the subquery permanently, zero feed-query change, but
a **product decision**; (b) threshold-gated `following`-tab-only JOIN when profiling
justifies; (c) defer the `for-you` rewrite until slow-log evidence. Also worth a separate
ticket: evaluate a `(status, created_at)` index on `bn_posts` for the global sort (the
actual scale cost).

---

## F-phase4 — Drop `bn_last_active` usermeta (ready)

`BlockService::is_user_online` now reads `PresenceService::last_active_at()` (the indexed
`bn_presence` table). Grep across both repos confirms **zero remaining reads** of the
`bn_last_active` usermeta — only `PresenceService::stamp()` still dual-writes it. Scope:
remove the `update_user_meta` dual-write line; add a one-time bulk `delete_metadata` of
`bn_last_active` in a guarded `Installer` migration; bump `SCHEMA_VERSION`. Risk: low
(readers verified migrated). Test: a migration test + presence still resolves from the table.

---

## H1–H4 — SegmentService `number => -1` (deferred)

4 `WP_User_Query` `'number' => -1` sites (`SegmentService.php:80/158/186/212`) load the full
recipient set into memory for broadcast resolution. Admin/cron only; the broadcast **send**
is already AS-batched, so this is a one-shot memory/SQL spike at resolution time (~a few MB
of ints at 100k). Fix = paged `WP_User_Query` loop accumulating into the enqueue. Medium
effort (4 sites + the consumer expects a full list). Low-medium value — only the largest
sites' broadcasts. Keep deferred.

---

## J3 — OnlineMembersWidget (low priority)

`widget()` does `get_users(['number'=>$limit, 'orderby'=>'registered'])` — already bounded
(`$limit` default 5, max 20), so caching is marginal. Finding: it sorts by `registered`, so
it shows **newest members, not online ones** (never touches `bn_presence`). If touched at
all: make it use `PresenceService::online_ids()` (so it does what its name says) + a short
TTL cache. Low priority either way.
