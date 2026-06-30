# Members / Profile — 1.0.4 plan, status & handoff (single source of truth)

One file for the whole Members/Profile component: initial plan, what's done, what's pending. Spaces work
lives in `spaces-master-plan.md`. Both repos on `1.0.4`. Last updated 2026-06-29.

## STATUS — IN PROGRESS (security + directory-scale cluster shipped 2026-06-30)

**Done + pushed on `1.0.4`:** T1, T2, T4/A1, T6, T16 (A2/A3/A5), A6a–A6d, **T3 (Workstream B — delete
integrity)** — the security/privacy fixes, the **complete member-directory scale cluster** (indexed
sorts/filters, bounded reads, no IN/NOT-IN id lists, no per-card N+1, bounded count), and the canonical
member-delete purge, **T17/F4** (suspension-filter dedup, premise corrected), **T18/F5** (dead digest-queue
removal), **T13** (self-clear member type), **T20/E1–E3** (hygiene), and **T19/F6** (invite pre-fill +
async send), **T21/F7** (digest cron at scale — keyset + AS chaining), and **E4** (member-field
registration API — premise was stale, then 4 real gaps fixed end-to-end), and **T15/A4/D2** (member search
unified on the FULLTEXT `bn_search_index` engine — directory search == unified search, cross-checked), and
**T9/C2/D1** (follow + connection count denormalization — O(1) cache-cold, drift-reconciled, a latent
pending-follow miscount fixed), each browser-verified where applicable (the scale + delete + digest + search
+ counter work re-verified on a 1.5k-member / 300-space seed). The remaining tasks (T14/D3, E5, B3) are
unstarted; their plan + exact touch-points are validated at code `file:line`.

**Post-implementation uniformity audit (all 16 done tasks).** Swept the shipped work for divergent paths /
dups / dead code. All 16 present + aligned. Three cleanups landed in the directory layer (the one area a
later task could have left a stale path): (A) `MemberDirectoryService` computed card `follower_count` via a
live `COUNT(*)` subquery with no `status` filter — it both duplicated T9's denormalised counter and diverged
(counted pending follows); now reads the denormalised `bn_follower_count` from the primed meta cache
(page-bounded lazy populate), so directory == profile == FollowService (verified: 2 == 2 == 2). (B) removed
the now-caller-less `excluded_user_ids()` (the unbounded global suspended+shadowban scan A6 replaced with
NOT EXISTS — not in the manifest, no Pro contract). (C) `online_now()` reused the canonical
`directory_exclusion_subqueries()` instead of re-inlining the same gate. One observation left as-is: the
`buddynext_queue_email_digest` action still fires (EmailSender) but is consumer-less in core post-F5 (the
digest is cron-driven via T21) — a harmless public hook, intentionally kept.
**QA: re-verify the shipped work with the [QA test cases](#qa-test-cases--shipped-work-re-verify) below.**

| Task | State | One-liner |
|---|---|---|
| T1 | ✅ DONE | Profile REST visibility leak (security) — `get_profile` now calls `can_view_profile()`, 404 on block/private; browser-verified (`f94c570f`) |
| T2 | ✅ DONE | Explore one-directional block → `block_related_ids()` (bidirectional, same helper the directory uses) |
| T3 | ✅ DONE | one canonical `MemberCleanupService::purge_user_relations()` + `buddynext_purge_user_data` action; both the delete listener AND the GDPR eraser defer to it; closes the gap tables (member-type/presence/search-index/space-bans/appeals/shares/reactions/poll-votes). Verified live: deleted a member with rows in all 21 user-keyed tables → all purged, zero leaks |
| T4 | ✅ DONE | member-type directory filter → indexed `bn_member_type_assignments` (A1) — both REST + SSR; EXPLAIN uses `idx_type_id`; SSR==REST verified; never loses members (usermeta_without_assignment=0) |
| T6 | ✅ DONE | bound unbounded reads — `get_members`/controller always-paginate + 200 cap; `transfer_candidates` mods-first + LIMIT 200; `pending_followers`/`list_follow_requests` bounded+paginated; `suggestions` friend-sample capped 200. Browser-verified (members tab + transfer dropdown intact) |
| T9 | ✅ DONE | follow denormalization wired + connection denormalization built (cache-cold O(1)). Fixed latent bug: `recount_follow_counts` counted pending follows (read path counts `approved` only). `CounterService` gains `adjust_user_counter` (atomic, clamped, cache-bust), `recount_connection_counts`, `recount_all_follow_counts`, `recount_all_connection_counts` (set-based). Follow/connection write paths maintain the counters; reads lazy-populate; daily cron + B2 purge + ToolsTab reconcile. Verified on seed: inc/dec, read==meta, drift heal, purge-peer decrement |
| T13 | ✅ DONE | self-clear member type — empty slug now calls `remove_user_type()` (under the existing own-profile `can_set_user_type` gate) instead of 404. Verified: PUT `{type_slug:""}` → 200 (was 404) |
| T14 | ⏳ PENDING | File profile field (decision D3: wire upload vs remove) |
| T15 | ✅ DONE | member field search → FULLTEXT `bn_search_index` (D2 resolved: consistency). Step A: `index_user` indexes name + bio + headline + public searchable fields. Step B: `SearchService::match_member_ids()` + both directory search paths (SSR `matching_user_ids`, REST `list_members`) route through it. Verified: directory search == unified `/search/members` (identical 38-member set for "Rivera"); all 3 engines agree; 1530 members reindexed |
| T16 | ✅ DONE | directory SSR scale — A5 per-card N+1 batched (online + mutual set-based); A2/A3 `count_total`/`COUNT` bounded to a 1000 cap (page-number pager kept; look-ahead redesign deferred as cross-cutting SSR+JS). Browser-verified |
| A6 | 🟡 PART | scale-audit addendum (2026-06-30): ✅ A6a SSR online→indexed `bn_presence` EXISTS, ✅ A6b exclude→correlated `NOT EXISTS`, ✅ A6c `post_count` removed, ✅ A6d `newest`→`u.ID`, ✅ A1 member-type→`idx_type_id` — all via a shared `directory_filter_sql()` + one `pre_user_query`; **full 9-point verification matrix passed** (suspended/shadowban/block/dir-optout excluded live, online/type/search/count, EXACT SSR↔REST parity). Remaining: A6e (count-includes-cursor, LOW) + A6f core-table notes |
| T17 | ✅ DONE | suspension-filter dedup — **corrected**: discovery surfaces use a DIFFERENT gate (ANY active suspension) than `moderation_exclude_sql()` (hide_posts content gate), so converging onto it would be a regression. Added `ModerationService::discovery_exclude_sql()`; Search converged; Explore (ID-list) + directory (NOT EXISTS) documented as intentional form-differences. Search verified 200 |
| T18 | ✅ DONE | removed the dead `buddynext_digest_queue_*` write (`on_queue_email_digest` handler + registration); digests are cron-driven from `bn_notifications`+`bn_notification_prefs`. `buddynext_queue_email_digest` kept as an addon extension point |
| T19 | ✅ DONE | invite email pre-fill (`signup.php` value attr + context from `$bn_invite['email']`) + async send (`create()` enqueues `buddynext_async_send_invite_email` via Action Scheduler; `OnboardingListener` handles it). Verified: pre-fill renders the invited email; create() schedules 1 async action (not inline) |
| T20 | ✅ DONE | hygiene — E1 self-target guard was ALREADY handled (BlockService rejects self-block/mute/restrict; false finding); E2 `/account-type` gated to `require_auth` (no public caller, was leaking `is_private`); E3 stale 5-of-13 `parts/profile-field.php` deleted (loaded nowhere). E4 (member-field API) + E5 (manifest refresh) remain |
| T21 | ✅ DONE | digest cron at scale — **verified the defect is real** (un-cursored `LIMIT 200` starved every digest user past the first ~200), then fixed: keyset cursor on `user_id` + AS self-chaining (`chain_next_digest_chunk`) so one cadence reaches ALL users in bounded 200-chunks. Verified live (250 daily users → chunk1 chains at cursor=200th uid, chunk2 of 50 does not chain) |

**Open decisions:** ~~D2~~ **RESOLVED** (owner: be consistent with the existing unified search/Explore →
unify on the FULLTEXT `bn_search_index` engine; the member index now carries name + bio + headline +
public searchable fields so a member is findable by their attributes everywhere — **Step A done**; Step B =
route the directory search box onto the same engine + cross-check). D3 (File field upload vs remove), and
which of the 7 people-expectation suggestions land in 1.0.4 — still open.

---

## QA test cases — shipped work (re-verify)

Test surface: the local demo site `http://buddynext.local`. Append `?autologin=<user_login>` to any URL
to log in (e.g. `?autologin=bn_demo_alex_rivera`). REST checks: open dev-tools console on a logged-in page
and `fetch('/wp-json/buddynext/v1/...', { headers: { 'X-WP-Nonce': <restNonce from the page> } })`.
All of these must also hold on a **large site (≈50k members)** — the whole point of the cluster is that
these surfaces stay correct AND fast at scale. Dev-level checks already confirmed (EXPLAIN: index-backed,
no filesort); QA verifies the **behaviour**.

| # | Area | Setup | Steps | Expected |
|---|---|---|---|---|
| **QA-T1** | Profile REST visibility (security) | User B blocks User A (or set A's profile to "followers only" with B not following) | As B, open A's profile page / hover card, and `GET /users/{A}/profile` | **404 / "User not found"** — no profile data leaks. A public profile, your own, or one you're allowed to see → 200. |
| **QA-T2** | Explore bidirectional block (privacy) | User C blocks the viewer V | As V, open **Explore → Members** | C does **not** appear in V's deck (before the fix, C still showed because V hadn't blocked C) |
| **QA-T6a** | Bounded member roster | A space with many members | `GET /spaces/{id}/members` with **no** `per_page` | Returns a bounded page (≤50), `X-WP-Total` header = the real total (never the whole 50k roster in one response) |
| **QA-T6b** | Bounded transfer picker | Own a space | Space **Settings → Danger zone → Transfer ownership** | Candidate dropdown renders (moderators listed first), bounded — never a 50k-option dropdown |
| **QA-T6c** | Bounded follow-requests | A private account with pending requests | `GET /me/follow-requests` | Returns `{ ids, total, page, total_pages }`; `ids` length ≤200 (bot-flood safe); `total` = the true count |
| **QA-A1** | Member-type filter (indexed) | A member assigned a type (e.g. *developer*) | `/members/?type=developer` (hard reload) **and** the type pill in the directory UI **and** `GET /members?member_type=developer` | Only members of that type appear; the SSR list and the REST list are **identical**; filtering stays fast at scale |
| **QA-A6a** | Online filter | Be active as one member (browse as them) | `/members/?online=1` | Only members active in the last 5 min appear; everyone else is absent. (Reflects live presence — note the live-vs-cached caveat below) |
| **QA-A6b-1** | Suspended excluded | Suspend a member who is visible in the directory | Reload `/members/` | The suspended member disappears from the SSR list **and** the live (REST) list |
| **QA-A6b-2** | Shadow-banned excluded | Shadow-ban a visible member | Reload `/members/` | The shadow-banned member disappears from both |
| **QA-A6b-3** | Blocked excluded (both directions) | Block a member (or have them block you) | Reload `/members/` | That member disappears from both — for *either* block direction |
| **QA-A6b-4** | Directory opt-out | A member turns **off** "Show me in the member directory" (privacy settings) | Reload `/members/` | That member disappears from both |
| **QA-A6b-5** | Self excluded | Any logged-in member | Open `/members/` | You do **not** see yourself in the directory |
| **QA-A6c** | "Most active" sort | — | Directory → sort **Most active** | Orders by recent activity (presence); page loads without slowness; no error (it must NOT count WP posts) |
| **QA-A6d** | "Newest" sort + load more | — | Directory default sort (Newest) → scroll / "Load more" | Newest members first; the next page continues correctly with **no duplicates and no skipped members** |
| **QA-parity** | SSR ↔ live consistency | — | Hard-reload `/members/`, note the members shown, then let the JS filter bar hydrate / re-fetch | The same members in the same order before and after hydration — the first paint and the live list agree |
| **QA-A5** | Grid online dot + mutuals (no N+1) | Be active as one member; have two members share a connection with you | Open `/members/` | The active member shows the online dot; a member you share connections with shows the correct "N mutual" count + avatar pile — same values as before, just rendered without a per-card query |
| **QA-A2a** | Pager still works | A filtered set spanning >1 page (e.g. search a common letter) | Page 1 → click **Next** / page **2** | Page 2 renders the remaining members; "« Prev" returns to page 1; counts add up |
| **QA-A2b** | Bounded total | A directory with **>1000** matching members (large site) | Open `/members/`, read the "Members" total + the pager | The total saturates at **1,000** and the pager at ~50 pages (by design — an exact 50k count is noise; people browse a few pages). Under 1,000 the total is exact. SSR and live (REST) agree |
| **QA-T3** | Delete integrity | A member with follows/connections/blocks/space memberships/a member type/online presence | Delete the member (Users → Delete, or WP-CLI) | None of their rows linger anywhere — they vanish from the directory, online list, space rosters (and member_count drops), search, follower lists. No "ghost" members. Their authored **posts** are a separate concern (out of scope here) |
| **QA-T13** | Self-clear member type | A member who has a self-selected member type | Profile edit → member-type select → **"— None —"** | The type clears with a success toast (no error/danger toast). Previously this 404'd |
| **QA-E2** | Account-type not anonymous | — | Logged **out**, call `GET /wp-json/buddynext/v1/users/{id}/account-type` | 401 (not the account's `is_private`). Logged-in, the same call still works (200) |
| **QA-T19a** | Invite email pre-fill | Invite-only registration; a pending invite for an address | Open the invite link (`/login/signup/?invite=…`) **logged out** | The email field is pre-filled with the invited address (you don't re-type it) |
| **QA-T19b** | Invite send is async | — | Create invites (single or **CSV import** of many) | The request returns promptly even for a large CSV; emails go out via Action Scheduler (Tools → Scheduled Actions shows `buddynext_async_send_invite_email`), not inline — no timeout |
| **QA-T21** | Digest reaches everyone | More than ~200 members set to daily/weekly email digest, each with unread notifications | Let the digest cron run (or trigger it) | EVERY digest member gets their digest, not just the first 200. Tools → Scheduled Actions shows `buddynext_daily_digest`/`buddynext_weekly_digest` chaining through chunks until done |
| **QA-E4** | Developer member field | A small mu-plugin/addon calling `buddynext_register_member_field( 'x_handle', [ 'group_key'=>'details', 'label'=>'X handle', 'type'=>'text' ] )` | Open a member's profile **edit**, type a value, save; reopen | The field renders in edit, persists, and shows the saved value on reload + in `GET /users/{id}/profile` (no code change to the plugin needed by the addon) |
| **QA-T15** | Search is consistent + finds by field | A member with a distinctive searchable field value (a skill/role) who has saved their profile | Search that value in the **directory** search box, in **unified search**, and on a member name | Both the directory and unified search return the **same** members; a member is found by their field value (skill/role), not just their name. (Existing members must be reindexed once for older data to be findable by field) |
| **QA-T9** | Follow/connection counts hold at scale | Two members A and B | A follows B (check B's follower count + A's following count); A unfollows; A connects with B and both accept (check both connection counts); remove the connection | Counts increment/decrement immediately and survive a page reload (read cache-cold). Deleting a member drops their followers'/connections' counts by one. A private account's pending follow request does NOT raise the follower count until approved |

**Caveats QA should know:**
- **A6a live-vs-cached:** the REST member list caches results ~60s per viewer, so a just-changed presence/online state can lag up to 60s on the *live* (JS) list; the SSR hard-reload is always live. Not a bug.
- **A6b instant vs cached:** suspend/block/opt-out is reflected immediately on a hard reload (SSR) and on the next cache cycle for the live list (block/unblock busts the viewer's cache immediately; suspend/shadowban respect the 60s TTL).
- **Empty type filter:** a member type with no *current* members (or only orphaned assignments) correctly shows an empty list — verify with a type that has a real, active member.

---

## Initial plan & validated detail (each task)

Scope = the actionable findings from the 2026-06-29 three-track audit (data+scale, REST+UX,
linkage+extensibility), **each verified at code `file:line`** + manifest cross-check, with exact
touch-points and reuse/dedup notes folded into every item below.

Scale envelope: **10,000 installs × up to 50,000 members per site.**

**The bar (lead-dev mandate): set the standard, don't match current behavior.** The base is sound, but
"works on our box" is not the goal. Two large-site principles govern every decision below:
- **P-A — Object cache is a bonus layer, never a dependency.** Most of 10,000 diverse installs run on
  hosts with **no persistent object cache**. Any design whose scale depends on a warm cache (e.g.
  cached `COUNT(*)`) is wrong by default — it must hold up cache-cold. (Repo principle:
  hosting-agnostic + progressive enhancement.)
- **P-B — Third-party developers are first-class.** At this footprint people *will* build addons on
  members. The deliverable is a **documented, symmetric extension API** (member fields, REST, cleanup
  hooks) — matching the Spaces extension contract — not just internally-correct code.

**Why the surface is still small:** the subsystem is already largely scale-aware — **no autoload bloat**
(per-user state is `usermeta` only), **no CLI-only config** (fields/groups/types all wp-admin buildable),
a **keyset-paginated REST directory**, batch-primed profile reads, throttled+indexed presence, FULLTEXT
search, and a native storage substrate (member = `wp_users` row → usermeta). So there is **no foundation
to lay**. The work is a short list of fixes — but executed to the standard above, not the current floor.

**Dedup posture (code-validated):** new code is limited to (a) `purge_user_relations()` (B2 — *removes*
duplication) and (b) connection-count methods on `CounterService` that **mirror the existing
`recount_follow_counts`/`recount_all_space_members` pattern** in the same class (not parallel logic).
Everything else reuses existing functions (`get_by_slug`, `remove_user_type`, `recount_follow_counts`,
the daily recount cron, `online_ids`, batched `mutual_counts`, `SearchService::index`, avatar/cover media
path). No parallel/duplicate implementations are introduced.

### Explicitly OUT of scope (verified already-good — do not touch)
- No `bn_profile_meta` / meta-foundation table — usermeta + `register_meta` already provide it.
- No autoload remediation — there is none to do (grep of Profile/MemberTypes/SocialGraph/Realtime = zero `*_option`).
- No admin field-builder work — `ProfileFieldsManager` + `MemberTypesManager` are complete CRUD, no CLI.
- No directory-UX rebuild — search/sort/filter/online/pagination/loading/error/empty/social-actions all wired.

---

## Workstream A — Member directory at scale (the one real P0 + its P1 neighbours)

> **Governing principle — design for real directory behavior (see `[[buddynext-directory-ux-behavior]]`).**
> People scan page 1 of the default sort, then **filter/search** to find a specific member — they do NOT
> deep-paginate (2-3 pages max, essentially never deeper). So the scale answer is NOT "make `COUNT(*)` +
> deep OFFSET scale"; it's: make **page 1 + the default sort + the filters** fast, and stop computing the
> things users never use. This re-frames the tasks below:
> - **A2/A3 (the total) → REPLACE the grand-total with a look-ahead "Next"/"Load more" pager**: fetch
>   `per_page + 1`, show a next affordance when the extra row exists; display an approximate count ("1k+")
>   or none. This DELETES the `SQL_CALC_FOUND_ROWS` (SSR) and the full `COUNT(*)` subquery (REST) instead of
>   trying to index them. Deep OFFSET is moot at 2-3 pages; **keyset is reserved for deep-scroll feeds, not
>   the member directory** — indexed shallow OFFSET is fine here.
> - **A1, A4 (type filter, search) move UP in priority** — fast indexed filters/search are the real
>   navigation, so they earn the most ROI.
> - **A5, A6a, A6b (N+1, unbounded `IN`/`NOT IN`) stay mandatory** — they bite page 1 regardless of depth.
> - **A6d (newest → `u.ID`) + the default-sort index** matter because everyone hits the default sort on
>   page 1; the inherent core-table sorts (A6f) are low-value precisely because few users sort by them.

**A1 · ✅ DONE — Type filter must use the indexed `bn_member_type_assignments`, not a usermeta value scan.**
Done on BOTH paths: REST `list_members()` (`:277` swapped to an `idx_type_id` EXISTS) and the SSR via
`directory_filter_sql()`. EXPLAIN drives from `mta` on `idx_type_id` (`ref`, not a scan). Verified
SSR==REST `[647]` for `?type=developer`; `usermeta_without_assignment=0` confirms the switch never loses a
member (the assignments table is the complete canonical source; the usermeta mirror was under-counting).
Both directory paths match `wp_usermeta.meta_value = slug` (`MemberDirectoryService.php:277`;
`templates/directory/members.php:188-196`) — `meta_value` is unindexable, so this scans ~50k usermeta
rows on the hottest filter while the purpose-built `bn_member_type_assignments` (`idx_type_id`,
`Installer.php:1374`) is ignored.
- **Fix:** resolve slug→type_id via existing `MemberTypeService::get_by_slug()`, then filter with
  `EXISTS (SELECT 1 FROM bn_member_type_assignments a WHERE a.user_id = u.ID AND a.type_id = %d)`.
- **Touch:** `MemberDirectoryService.php:277` (REST WHERE) + `templates/directory/members.php:188-196`
  (server `meta_query`).
- **Reuse / dedup:** the assignments table is already queried by `get_type_member_count` (`:526-530`) and
  the reassign path (`:310`) — same predicate. The `bn_member_type` usermeta mirror is **maintained on
  every assign/remove** (`MemberTypeService:406,463,494,721`); **leave it** (other fast-reads use it),
  just stop using it as the *filter*. It's a WHERE fragment, so no new method is strictly required; if a
  helper is preferred add ONE `user_type_assignment_exists_sql()` — do **not** copy the `WHERE type_id`
  SQL a third time.

**A2 · ✅ DONE — SSR `count_total` (SQL_CALC_FOUND_ROWS) bounded.**
The server render dropped `count_total` (which scanned the WHOLE 50k match set every render just to size
the pager). It now sizes the total with a bounded capped count: a second `fields => 'ID'`, `number => CAP`
WP_User_Query reusing the same args + the same `pre_user_query` fragment, so it stops at CAP (1000) and the
displayed total + page-number pager saturate there. **Decision:** kept the page-number pager (the whole
directory — SSR + the JS `syncPager` — is page-number/OFFSET based; a true look-ahead Prev/Next would be a
cross-cutting SSR+JS+sidebar redesign). Capping the count is the behaviour-principle-aligned scale fix (an
exact 50k total is noise; people browse a few pages) at far lower risk. Verified: search "a" → total 28
(exact under cap), page 1 = 20 cards "1 2 Next»", page 2 = 8 cards "«Prev 1 2"; SSR==REST. The keyset REST
path + 60s cache were already in place (A6d) — not re-routed. *(A5 per-card N+1 also done; see below.)*

**A3 · ✅ DONE — REST `total` capped.**
`list_members()`'s `COUNT(*)` subquery LIMIT changed `PHP_INT_MAX` → `DIRECTORY_COUNT_CAP` (1000), so the
count never scans the full 50k set; `total` saturates at 1000 (kept in sync with the SSR cap so the two
surfaces agree). A1 already moved the type predicate to the indexed assignments table; the count stays
cached alongside the page (60s).

**A4 · ✅ DONE (= T15/D2) — directory search now FULLTEXT, consistent with the unified search.**
The directory's per-mirror leading-wildcard `LIKE` is replaced by the shared
`SearchService::match_member_ids()` FULLTEXT lookup over `bn_search_index` (LIKE fallback when the
`ft_search` index is absent — the same seam `search()` uses). Both directory search paths route through it:
the SSR `matching_user_ids()` (the rich inline LIKE+exclusion query is gone — the SSR main query's
`directory_filter_sql` already applies the gate) and the REST `list_members()` (the inline search OR-block
→ `u.ID IN ( match ids )`). The index is enriched so a member is matched on name + bio + headline + public
searchable fields (A4/Step A). **Cross-checked:** the directory search and unified `/search/members` return
the identical member set for a query (38 for "Rivera"); the SSR primitive, the shared primitive, and the
unified search all agree. Existing members reindexed (going-forward: `index_user` on each profile save).

**A5 · P1 — Per-card N+1 in the server grid.**
`member-directory-grid.php:131,135` call `is_online` and `mutual_connections` per card (wp_cache-backed,
but cold cache = one self-join per card). The REST path already batches mutual counts.
- **Fix:** prime presence + mutual counts for the whole page before the card loop, exactly as
  `list_members` does → cold-cache render is O(1) queries, not O(per_page).
- **Touch:** `templates/parts/member-directory-grid.php:131,135`.
- **Reuse — no new helper:** `Realtime\PresenceService::online_ids()` (`:228`, one query for all online
  ids) + the REST batched `$mutual_counts` (`MemberDirectoryService.php:417-458, 476`).

### A6 · Scale-audit addendum (connectivity/scale audit 2026-06-30) — additional gaps the above tasks don't explicitly name. Wrap one-by-one; none skipped.

> Context: the **REST path** (`MemberDirectoryService::list_members`) is already scale-safe (keyset cursor, batched hydration, per-viewer 60s cache+bust, correlated `NOT EXISTS`/subquery exclusions). The gaps cluster in the **SSR `WP_User_Query`** path (`templates/directory/members.php`), which is reachable deep via `?paged=N`, so each detonates at arbitrary page depth — not just page 1. A6a-A6c are the concrete SSR killers behind A2's "unify the SSR path"; treat them as A2's acceptance criteria.

- **A6a · ✅ DONE — `online_user_ids()` returned an UNBOUNDED id list stuffed into `WP_User_Query 'include'`.**
  Fixed: the SSR online filter is now an indexed `bn_presence` EXISTS injected via
  `directory_filter_sql()` + `pre_user_query` — no 50k id list. Verified live: with one
  member made active, `?online=1` returns exactly that member.
  *(original finding below)*
  `templates/directory/members.php:214` → `MemberDirectoryService::online_user_ids()` (`:584-588`) →
  `PresenceService::online_ids()` (`:228-240`) is `SELECT user_id FROM bn_presence WHERE last_active > %s`
  with **no LIMIT**. At "30-50k active" that's a `WHERE ID IN (…50,000 literals…)` SQL string — megabyte
  query, parser blowup, defeats every other index.
  **Fix:** don't resolve online to an id list for SSR — push it as a JOIN on `bn_presence` (sargable
  `pres.last_active > …`), exactly as the REST path does (`:184-187, 282`). Folds into A2.

- **A6b · ✅ DONE — `exclude` was a globally-unbounded `NOT IN` literal list.**
  Fixed: suspended/shadowban/dir-optout are now correlated `NOT EXISTS` + the shared
  `block_exclude_sql()`, injected via `directory_filter_sql()` + `pre_user_query` — no
  materialised global id list. Verified live: suspended, shadow-banned, blocked (both
  directions), and dir-opted-out members each drop from the SSR directory, with EXACT
  SSR↔REST parity. The viewer self-exclusion (`ID != %d`) is in the fragment too.
  *(original finding below)*
  `excluded_user_ids()` (`:552-573`) fetches **all** suspended + **all** shadow-banned users globally (two
  unbounded `get_col`s) ∪ the viewer's blocks, fed into `WP_User_Query 'exclude'` (`members.php:130-132`)
  → `WHERE ID NOT IN (…)`. These populations grow with the site.
  **Fix:** mirror the REST correlated `NOT EXISTS` (indexed by `user_id`) for suspended/shadowban +
  `NOT IN (subquery)` for blocks (`:198-238`) via a `pre_user_query` clause injection — never materialise
  global exclusion ids in PHP. **Cross-ref T17** (converge suspension filter on `moderation_exclude_sql()`) —
  same fix, do once.

> **Ready-to-execute approach for A1 + A6a + A6b (do these three together — one `pre_user_query`):**
> 1. Add `MemberDirectoryService::directory_filter_sql( int $viewer_id, array $args ): array` returning
>    `[ $sql_fragment, $prepare_params ]` built from the **exact** `list_members()` WHERE clauses
>    (`MemberDirectoryService.php:198-283`): suspended `NOT EXISTS` (`:200-205`), shadowban `NOT EXISTS`
>    (`:206-211`), dir-optout `NOT EXISTS` (`:216-221`), `block_exclude_sql()` (`:230-238`), member_type
>    `EXISTS` on the assignments table (A1 — `EXISTS(SELECT 1 FROM bn_member_type_assignments a WHERE
>    a.user_id = {users}.ID AND a.type_id = %d)` after resolving slug→type_id via `MemberTypeService::
>    get_by_slug()`), and online `EXISTS(SELECT 1 FROM bn_presence p WHERE p.user_id = {users}.ID AND
>    p.last_active > UNIX_TIMESTAMP()-300)` (A6a). Reuse, do NOT re-author the SQL — this is why the
>    privacy risk stays low.
> 2. In `templates/directory/members.php`: REMOVE `'exclude' => $bn_dir_excluded_ids` (A6b), the online
>    `include` list (A6a), and the member_type `meta_query` (A1); keep search/relation `include` (bounded)
>    and the dir-optout (now in the fragment). Add+remove a `pre_user_query` closure that appends the
>    prepared fragment to `$query->query_where` (reference `{$wpdb->users}.ID`).
> 3. **Verification matrix (ALL must pass before done — privacy-sensitive):** (a) a suspended user is
>    absent from the SSR directory; (b) a shadow-banned user is absent; (c) a user who blocked the viewer
>    is absent AND a user the viewer blocked is absent; (d) a dir-opted-out user is absent; (e) the online
>    filter shows only <5-min-active members; (f) the member-type filter returns only that type (EXPLAIN
>    uses `idx_type_id`, not a usermeta scan); (g) search still works; (h) the count/look-ahead pager is
>    still correct; (i) SSR result set == REST first-page result set (the two engines agree).
> Also do A1 on the **REST** side: swap `:277` (`bn_member_type` usermeta `EXISTS`) to the same
> `bn_member_type_assignments` `EXISTS` so both surfaces use the indexed table.

- **A6c · ✅ DONE — SSR `orderby => 'post_count'` ran a correlated `wp_posts` count per user.**
  Fixed: the SSR `WP_User_Query` now uses `$bn_query_orderby` (falls back to `registered` for the paint
  when `most_active`/`post_count` is requested), so it never attaches WP's per-user `wp_posts` COUNT. The
  JS still re-sorts via REST `most_active` (`bn_presence`) — `$bn_initial_sort` keeps handing it
  `most_active`. Browser-verified (renders + initial sort = most_active, no `wp_posts` subquery).

- **A6d · ✅ DONE — REST `newest` sort filesorted on `wp_users.user_registered` (no core index).**
  Fixed: `MemberDirectoryService` newest now `ORDER BY u.ID DESC` with an ID-only keyset cursor (ID is
  registration order + the PRIMARY KEY). EXPLAIN = `range` on PRIMARY, `Backward index scan`, **no
  filesort**. Cursor continuity verified live (page1 [647..642] → page2 [641..636]); legacy `registered`
  cursors still honoured via their `id`.

- **A6e · LOW — REST `total` subquery includes the cursor predicate, so the count shrinks as you paginate.**
  `$count_params = array_slice($params, 0, -1)` (`:387`) reuses `$where_sql`, which already carries the
  cursor WHERE (`:289-324`). Harmless if the client reads `total` only on the first (cursorless) load;
  misleading if it drives a persistent "N members" label. **Fix:** build the count WHERE without the cursor
  clause. Not a scale gap (makes deep counts cheaper); flagged so it isn't lost. Lives alongside A3.

- **A6f · NOTE / inherent core-table constraints (NOT actionable via a `bn_*` index — record so we don't
  chase the spaces trick here).** Members live in `wp_users`/`wp_usermeta`, so the spaces fix (add a
  `(parent_id, sort_col)` composite to a table we OWN) does **not** transfer:
  - `alphabetical` sort (`display_name ASC`, `:334`) and `most_active` sort (`COALESCE(pres.last_active,0)
    DESC` over a LEFT JOIN, `:339`) filesort inherently at 100k — core column / nullable-join-expression.
    Escape hatch only if it becomes a real complaint: a `bn_*` member-index shadow table, or drive
    `FROM bn_presence INNER JOIN users` for most_active (excludes presence-less members — a product call).
  - The dir-optout `NOT EXISTS` / member_type `meta_query` (`members.php:171-194`) ride core usermeta
    indexes only (A1 moves member_type to the indexed assignments table; the optout `OR NULL` branch stays
    core-bound). Document the ceiling; don't pretend a `bn_*` index fixes it.

- **Already scale-safe in the REST path — DO NOT re-fix (recorded so the wrap doesn't touch them):** keyset
  cursor for all four sorts (`:289-324, 812-882`); fully batched hydration — `update_meta_cache`/`cache_users`
  + 2-query mutual counts + primed follow/status/block maps, no per-row query in the result loop
  (`:412-473`, Controller `:166-189`); per-viewer version-salt result cache + `bust_viewer()` on
  block/unblock (`:104-114, 890-937`); `bn_presence` PRIMARY(user_id)+KEY(last_active) drives the online
  filter + `online_now` widget sargably; bounded viewer-own block exclusion (`PrivacyService:323-376`).

---

## Workstream B — Referential integrity on user delete (P1 at churn) — the dedup that matters

> **✅ B1 + B2 DONE.** New `Profile\MemberCleanupService::purge_user_relations( $user_id, $context )` holds
> the ONE canonical table set; `SocialGraph\UserCleanupListener::on_deleted_user` (`delete`) and
> `Privacy\PrivacyTools::erase_relational` (`gdpr-erase`) both defer to it (their two divergent lists are
> gone). Closes every gap (member-type assignments, presence, search index, space bans, appeals, shares,
> reactions, poll votes). Fires `do_action( 'buddynext_purge_user_data', $user_id, $context )` as the public
> member-cleanup extension contract; the old `buddynext_user_relations_purged` still fires on delete for
> back-compat. **Verified live** on the 1.5k-member seed: deleted a member seeded a row in all 21 user-keyed
> tables → every relational table purged to 0, zero leaks.
>
> **Follow-up (NOT B — scope boundary):** authored content (`bn_posts`, and its cascade) is intentionally
> NOT purged by the relational cleanup — on hard delete a member's posts are left with an orphaned
> `user_id` (the GDPR eraser handles posts separately via `delete_user_posts`). Worth a dedicated task:
> decide delete-vs-reassign for `bn_posts`/`bn_comments` on `deleted_user`. **B3** (`bn_invites` email
> reconciliation) also still open.

**The duplicate (the real problem):** two parallel member-purge lists that overlap **and disagree**:
- `SocialGraph\UserCleanupListener::on_deleted_user` (`:78-96`) — follows, connections, blocks,
  space_members (+`bn_spaces.member_count--` at `:70`), hashtag_follows, notification_prefs,
  notifications, **user_strikes, user_suspensions**, bookmarks, profile_values (via
  `ProfileService::delete_user_values():1848`).
- `Privacy\PrivacyTools::erase_relational` (`:893-903`) — the same core **plus `bn_reactions` +
  `bn_poll_votes`**, but **not** strikes/suspensions.
- **Both miss:** `bn_member_type_assignments`, `bn_presence`, `bn_search_index`, `bn_space_bans`,
  `bn_appeals`; the listener also misses `bn_shares`.

**B1 · Close the orphan-row gaps** so deleted members stop lingering in search / online / counts and
counters stop drifting.

**B2 · Collapse the two lists into ONE canonical, EXTENSIBLE purge.** Add a single
`purge_user_relations(int $user_id)` holding the full member-id table set; **both** `UserCleanupListener`
and `PrivacyTools::erase_relational` call it (the eraser may *additionally* scrub audit columns per GDPR;
the delete path keeps them). Decrement affected denormalized counters the same way the listener already
decrements `bn_spaces.member_count` (`:70`).
- **Standard (principle P-B): make the cleanup contract a public extension point.** Core cannot know
  every addon's user-keyed tables at 10k sites. After the core purge, fire
  `do_action( 'buddynext_purge_user_data', $user_id, $context )` (context = `delete` | `gdpr-erase`) so
  any addon cleans its own rows on the SAME canonical event. Document it as the member-cleanup contract.
- **Touch:** `SocialGraph/UserCleanupListener.php:50-104`; `Privacy/PrivacyTools.php:859-903`.
- **Reuse / dedup:** extend the existing shared-helper pattern (`ProfileService::delete_user_values()`,
  already called by the listener at `:96`) — **do not** fork a third purge list. Home the helper in a
  small `MemberCleanupService` both callers reference. (Note: the existing
  `buddynext_user_relations_purged` action at `UserCleanupListener:104` fires on the delete path only —
  consolidate it into the new contract so addons get ONE event regardless of how the user was removed.)

**B3 · Minor — `bn_invites` keys on `email`, never reconciled to a created/deleted user.** Reconcile
invite→user on signup/delete if cheap, else document the intentional gap.

---

## Workstream C — Count consistency (decision only — no fix to write)

**C1 · ~~Single-type member count is uncached~~ — WITHDRAWN after code validation.**
`MemberTypeService::get_type_member_count` (`:514-535`) **is already cached** (`$this->cache->set` at
`:534`, with a documented null-miss correction at `:519-522`). The linkage audit's "no cache" claim was
wrong. **Nothing to touch.** Both `get_all_with_counts` (`:87-113`) and `get_type_member_count` are cached.

**C2 · ✅ DONE (= T9) — Decision D1 — follow/connection counts → finished the denormalization that already half-existed.**
*(Code-proven — superseded both the "keep cached" and the "add columns via Installer" drafts; both were wrong.)*
*Built + verified end-to-end on the 1.5k seed: counters maintained on every write path (status-aware for
follows), reads lazy-populate from usermeta (O(1) cache-cold), the daily cron + B2 purge + ToolsTab button
reconcile drift. The latent `recount_follow_counts` "counts pending follows" bug was fixed in the same pass.*

Proven current state:
- **The follow counter store ALREADY EXISTS but is dead.** `CounterService::recount_follow_counts()`
  (`CounterService.php:44-62`) computes both counts and writes them to **usermeta** `bn_follower_count` /
  `bn_following_count`. Its **only caller is a manual admin button** (`Admin/ToolsTab.php:278`). It is
  **not** maintained on follow/unfollow, **not** in the daily self-heal (`CronService::handle_recount_stats`
  `:279-284` recounts posts/spaces/hashtags only), and the read path
  (`FollowService::follower_count:447` / `following_count:478`) **ignores it and runs `COUNT(*)` cached**.
  Net: a written-never-read denormalization (a contract bug `/wp-contract-audit` would flag).
- **Connections have no counter at all** — no `recount_connection_counts`, no `bn_connection_count`;
  `ConnectionService::connection_count:725` is pure `COUNT(*)` cached.
- **Home is usermeta, NOT a new column** — `bn_follows`/`bn_connections` are edge tables with no per-user
  row, and a member is a `wp_users` row, so the per-user count belongs in usermeta (lazy-loaded per user,
  O(1) cache-cold — satisfies P-A without depending on object cache).

Decision (lead, by P-A): **wire the existing follow denormalization and build the symmetric connection one.**
- **Follow (store exists — wire it):** (1) increment/decrement `bn_follower_count`/`bn_following_count`
  in the four write paths — `FollowService::follow:64`, `unfollow:204`, `approve_follow_request:685`,
  `reject_follow_request:730` — right beside the existing cache busts (`:767-771`); (2) make
  `follower_count:447` / `following_count:478` **read the usermeta counter** (lazy-recount via
  `recount_follow_counts` on a missing key, so it self-heals); (3) add a bounded
  `CounterService::recount_all_follow_counts()` (mirror `recount_all_space_members:156`) and call it from
  `handle_recount_stats` (`CronService:280-284`) for periodic reconcile.
- **Connection (build symmetric):** add usermeta `bn_connection_count`, `CounterService::recount_connection_counts()`
  + `recount_all_connection_counts()`, maintained in `ConnectionService::accept_request:155` (+1) and
  `remove_connection:339` (−1) and the B2 purge, read by `connection_count:725`, wired into the daily cron.
- **Reuse / no dup:** extend `CounterService` + the existing daily cron + the existing ToolsTab manual
  recount. No new table, no Installer column. Drift sources are removed by B1/B2; the cron reconciles the
  rest. This is the **product counting standard**, now consistent across follows, connections, and Spaces.

---

## Workstream D — Member-facing dead-ends (small, user-visible)

**D1 · A member cannot clear their own self-selected member type (404 + danger toast).**
Profile edit offers "— None —" (`edit.php:519-530`); the store sends `type_slug:""` (`store.js:1100-1103`);
`set_user_type` does `get_by_slug("")` → null → **404** (`MemberTypeController.php:297`) → danger toast
(`store.js:1109`). Self-assign works; self-remove doesn't (DELETE is `require_admin`).
- **Fix:** in `set_user_type`, when `type_slug === ''`, call the existing
  `MemberTypeService::remove_user_type()` (`:484`) under the **same** `can_set_user_type` self-select gate,
  returning success. **No new route, no new service method.**
- **Touch:** `MemberTypeController::set_user_type` (`:293-300`).

**D2 · "File" profile field type has no upload — members paste a URL.**
Admin can create a File field (`ProfileFieldsManager:134-139`); the front end renders a plain
`<input type="url">` (`FieldType.php:341-358`) validated as a URL — no picker, no value-upload endpoint.
- **Fix (decision D3):** (a) wire a real upload — **reuse** the avatar/cover media path
  (`ProfileController` `/me/avatar:149`, `/me/cover:165`) for a field value-upload — OR (b) remove `file`
  from the matrix + the `case 'file'` so owners can't create a half-working type. *Pick one — don't ship
  a type that looks supported but isn't.*
- **Touch:** `FieldType.php:341-358`; `ProfileFieldsManager.php:134-139`.

---

## Workstream E — Hygiene (cheap correctness/clarity)

- **E1 · ✅ ALREADY HANDLED (false finding).** `BlockService` already rejects self-targeting —
  `cannot_block_self` (`:59`), `cannot_mute_self` (`:181`), `cannot_restrict_self` (`:274`) — and
  `BlockController` propagates those as 400s. The guard lives in the service, not the controller; no change.
- **E2 · ✅ DONE.** `GET /users/{id}/account-type` permission_callback `__return_true` → `require_auth`
  (`FollowController.php`). Confirmed no public caller anywhere (whole-codebase grep). Authed access verified
  200; logged-out now 401 instead of leaking `is_private`.
- **E3 · ✅ DONE.** Deleted the stale `templates/parts/profile-field.php` (239 lines, 5-of-13 field types) —
  confirmed loaded nowhere (the live path is `FieldType::render_input()` at `edit.php:443`).
- **E4 · ✅ DONE — Member-field registration API (premise corrected, then 4 real gaps fixed).** The audit
  said `buddynext_register_profile_field` was "defined nowhere" — **stale**: it's defined at
  `buddynext.php:575` and wires fields onto the `buddynext_profile_fields` filter. BUT verifying end-to-end
  showed the API was **broken in four places**, so a registered field never actually reached a member:
  (1) `normalize_field_row()` only read `field_key`, silently **dropping** every field registered with the
  documented `key` arg (so `get_fields()` never even contained it); (2) `get_profile()` — the path the edit
  UI + member REST read — builds from the DB and **never merged** the filter's virtual fields; (3)
  `save_profile()` built `$field_by_key` from the DB-only `get_flat_fields()`, so a submitted virtual value
  was **skipped**; (4) even when reached, it had **no virtual branch** to store the value. Fixes:
  normalize accepts `key`; `get_profile()` gains `merge_virtual_fields()` (value from `bn_field_{key}`,
  visibility-gated like DB fields); `save_profile()` layers virtual fields into `$field_by_key` and writes
  `bn_field_{key}` on id 0. Added `buddynext_register_member_field( $key, $args )` — the Spaces-symmetric
  name the plan wanted — as a wrapper. Verified live: a code-registered field **renders** in profile edit,
  **surfaces** in `GET /users/{id}/profile`, and a **PUT → GET round-trip** persists + reads back. The
  `AuthController:1137` comment was accurate; refreshed it to name the new alias + the read/save keys.
  (`register_meta('user', …)` was NOT used — BuddyNext serves its own `buddynext/v1` REST, so the filter
  path is the correct surface; register_meta targets core `/wp/v2/users`.)
- **E5 · Manifest refresh.** Its `permission_callback` column is unreliable here (renders array callbacks
  as `__return_true`, e.g. `/member-types` admin routes) and is 2 days stale — drift only, code gates
  correctly. Regenerate (`/wp-plugin-onboard --refresh`) after A–D land.

---

## Workstream F — Member-adjacent + bounded reads (from the block/visibility/email/onboarding audit)

These came from the member-adjacent three-track audit (block/visibility, onboarding/invites, email/digest),
verified at code `file:line`. They map to the status-table T-numbers shown.

**F1 · T1 — Profile REST visibility leak (SECURITY, highest priority).** `GET /users/{id}/profile`
returns full profile data to a blocked viewer / a non-follower of a private account. `ProfileController::
get_profile` (`:649`) → `ProfileService::get_profile` (`:757`) gates only **suspended** users; it has no
block/privacy check. The canonical gate `PrivacyService::can_view_profile()` (`:231` — block +
`profile_visibility` public/followers/connections) is called **only by the template** (`view.php:41`),
never by REST.
- **Fix:** gate the REST endpoint with the existing `can_view_profile($viewer,$id)`; return 404 (or
  minimal) when false. No new logic — reuse the canonical gate.

**F2 · T2 — Explore "Members" one-directional block (privacy).** `ExploreService::excluded_member_ids`
(`:530`) uses `blocked_users($viewer)` (only users the viewer blocked), not the bidirectional
`block_related_ids()` the member directory uses — so a user who blocked the viewer still appears in the
viewer's Explore deck.
- **Fix:** swap to `block_related_ids()` (same canonical helper the directory uses).

**F3 · T6 — Bound unbounded reads (P0/P1).** `SpaceMemberService::get_members` defaults `$limit=0`
(loads a full 50k roster); `FollowController::list_follow_requests` (`:411`) returns ALL pending IDs in
one array (no LIMIT/paging — bot-spam risk); `FollowService` suggestion `IN (followers)` (`:583`) has no
LIMIT; `transfer_candidates` unbounded.
- **Fix:** sane default page sizes + hard caps; keyset-paginate the follow-requests list.

**F4 · ✅ DONE — Suspension-filter dedup (premise corrected).** The audit assumed Search/Explore/Directory
should converge onto `moderation_exclude_sql()`. **Code review showed that would be a regression:** that
helper is the **hide_posts content gate** (`WHERE ... hide_posts = 1`), whereas the three discovery surfaces
deliberately use the **full-suspension discovery gate** (`WHERE lifted_at IS NULL` — ANY active suspension
makes a member undiscoverable). So instead: added `ModerationService::discovery_exclude_sql()` (the
discovery gate, NOT IN form, sibling to `moderation_exclude_sql()`); **Search** now uses it (verified 200);
**Explore** keeps its ID-list (it merges the gate with the viewer's blocks) and **MemberDirectory** keeps
its `NOT EXISTS` form (MySQL-5.7 compat with its mutual self-join) — both now carry comments pointing to the
shared gate and warning NOT to converge onto `moderation_exclude_sql()`.

**F5 · ✅ DONE — Dead digest queue removed.** `EmailDispatchListener::on_queue_email_digest` wrote
`buddynext_digest_queue_{freq}` usermeta that **nothing read** (the digest cron builds from
`bn_notifications` + `bn_notification_prefs`, logging to `bn_email_log`). Removed the handler + its
registration; `EmailSender` still fires `buddynext_queue_email_digest` as an addon extension point (core
keeps no queue), with the docblocks updated to say so.

**F6 · ✅ DONE — Invite email pre-fill + async send.** `signup.php` now seeds the invited address into BOTH
the store context AND the email input's `value` attribute (the field is an uncontrolled
`data-wp-on--input`, so the context alone wouldn't paint it) via `$bn_invite['email']`. `InviteService::
create()` now enqueues `buddynext_async_send_invite_email` through Action Scheduler instead of a blocking
`wp_mail()` (handler: `OnboardingListener::handle_async_invite_email` → `InviteService::deliver_invite_email`;
inline fallback when AS is absent), so a CSV import that loops `create()` schedules N fast async sends.
Verified live: signup renders the invited email pre-filled; `create()` schedules exactly 1 async action.

**F7 · ✅ DONE — Digest cron at scale.** Verify-first confirmed the defect is REAL and worse than a backlog:
`get_digest_user_ids()` did `LIMIT 200` with **no ORDER BY and no cursor**, so every recurring run returned
the same first ~200 users and **permanently starved everyone past them** (the loop's per-user skip can't
help — those users are never queried). Fixed with a keyset cursor on `user_id` + AS self-chaining: each run
processes ≤`DIGEST_USER_CAP` users ordered by id, and `chain_next_digest_chunk()` enqueues a one-off run of
the same recurring hook keyed on the last id whenever a chunk fills the cap. The chain only advances and
terminates on the first short chunk, so a 50k base is fully processed in one cadence cycle, each run bounded.
(Also corrected a stale `get_digest_user_ids` docblock that claimed a `type='global'` filter the query never
had — `DISTINCT user_id` is what dedups.) Verified live on the seed (250 daily users → chunk1 chains at
cursor = the 200th uid, chunk2 of 50 stops).

**F7-OLD ·** The digest cron sends inline `wp_mail()` capped at
200 users/run; at 50k members with a 1×/day cron the backlog can grow.
- **Fix (verify cadence first):** move sends to Action Scheduler if a real backlog exists.

---

## Consistency & no-dup contract (each fix follows ONE existing canonical pattern)

Implementation must reuse the established pattern for its concern — never introduce a parallel one.
All anchors verified in code.

| Concern | The ONE canonical pattern | Reused anchor | Applies to |
|---|---|---|---|
| Counting | per-user/entity **denormalized store + `CounterService::recount_*` + daily `handle_recount_stats` self-heal** | `CounterService.php:44` (follow), `:156` (space); cron `CronService.php:279-284` | D1 follow + connection (connection mirrors follow exactly) |
| User-data cleanup | **ONE `purge_user_relations()` + one `buddynext_purge_user_data` action**, both delete + GDPR paths call it | extends `ProfileService::delete_user_values():1848` pattern | B1/B2 |
| Directory data access | **filter/sort on an indexed store, never `usermeta.meta_value`** | `bn_member_type_assignments`/`idx_type_id`; `bn_search_index` FULLTEXT | A1 (type), A4 (search) — aligns DATA-AT-SCALE Rule 2 |
| Per-page hydration | **batch-prime before the loop** (no per-row query) | `MemberDirectoryService:411-415,417-458`; `PresenceService::online_ids():228` | A5 |
| Caching | **keep each service's existing cache mechanism** — do not add a new layer | `FollowService` `wp_cache`+`CACHE_GROUP`; `MemberTypeService` `CacheService` | A2/A3, D1 |
| Member-type assign/remove | **`MemberTypeService` methods that keep table + usermeta mirror in sync** | `assign_type:425`, `remove_user_type:484` | A1, D1-type-clear |
| REST auth | **`BaseRestController` gates**, no new permission helper | `require_auth:33`, `require_admin:50`, `can_set_user_type` | D1-type-clear, E2 |
| Field rendering | **`FieldType::render_input()` only** (single 13-type engine) | `FieldType.php`; live caller `edit.php:443` | D2, E3 (retire the stale 5-type partial) |

**Rule:** if a fix seems to need a new helper, first prove the canonical one above can't serve it
(`file:line`); only `purge_user_relations()` + `recount_connection_counts()` are net-new, and both are
*instances of* an existing pattern in their existing class — not parallel mechanisms.

## Decisions to lock before coding
- **D1 (C2): ✅ RESOLVED + DONE** — wired the half-existing follow denormalization + built the symmetric
  connection one (the "keep cached" draft was superseded by the code-proven C2 decision; see T9 above). Counts
  are now O(1) cache-cold via usermeta counters, drift-reconciled daily + on the manual ToolsTab button.
- **D2 (A4): ✅ RESOLVED + DONE** — member field-search unified on `bn_search_index` FULLTEXT (see T15).
- **D3 (D2):** File field — wire a real upload vs remove the type from the matrix. *(pick one)*

## Files-to-touch index (quick reference)

| Item | File(s) | Function / line | New code? |
|---|---|---|---|
| A1 | `Profile/MemberDirectoryService.php` · `templates/directory/members.php` | filter clause `:277` · `:188-196` | reuse `get_by_slug`; ≤1 SQL helper |
| A2 | `templates/directory/members.php` · `MemberDirectoryService.php` | `:121-128,226-229` · `list_members:289-324,526` | reuse keyset+cache |
| A3 | `Profile/MemberDirectoryService.php` | `:386-401` | none (A1 fixes predicate) |
| A4 | `Profile/MemberDirectoryService.php` · `Search/SearchService.php` | `:255-264,691-695` · `index():48` | reuse writer (if D2=FULLTEXT) |
| A5 | `templates/parts/member-directory-grid.php` | `:131,135` | reuse `online_ids()` + `mutual_counts` |
| B1+B2 | `SocialGraph/UserCleanupListener.php` · `Privacy/PrivacyTools.php` | `:50-104` · `:859-903` | **1 new** `purge_user_relations()` (kills dup) |
| C1 | — | already cached `:534` | none (withdrawn) |
| D1-follow | `SocialGraph/FollowService.php` · `Core/CounterService.php` · `Core/CronService.php` | write `:64,204,685,730`; read `:447,478`; recount `:44`; cron `:280-284` | reuse usermeta store (exists, orphaned) |
| D1-conn | `SocialGraph/ConnectionService.php` · `Core/CounterService.php` | write `:155,339`; read `:725`; new `recount_connection_counts` | mirror follow pattern |
| D1-type-clear | `MemberTypes/MemberTypeController.php` | `set_user_type:293-300` | reuse `remove_user_type():484` |
| D2 | `Profile/FieldType.php` · `Admin/Members/ProfileFieldsManager.php` | `:341-358` · `:134-139` | reuse avatar/cover path (if D3=upload) |
| E1-E3 | `SocialGraph/BlockController.php` · `Profile/FollowController.php` · `templates/parts/profile-field.php` | `:122-135` · `:167` · `:138-224` | guards / gate / delete |

## Definition of Done
- [ ] Directory type filter uses indexed `bn_member_type_assignments`; `EXPLAIN` on a seeded **50,000-member** lab shows index use, zero usermeta `meta_value` scan.
- [ ] Server-render directory path shares the cached keyset strategy (no uncached OFFSET + SQL_CALC_FOUND_ROWS); total via a dedicated indexed `COUNT(*)`.
- [ ] Field-search path per D2; per-card mutual/presence batched (cold-cache render O(1) queries).
- [ ] `deleted_user` cleanup purges all member-id tables; delete + GDPR-erase share ONE canonical `purge_user_relations()`; affected denormalized counters decremented.
- [ ] D1 (counts) logged; C1 confirmed withdrawn (already cached).
- [ ] Self-clear member type returns success (no 404); File field per D3.
- [ ] Block/mute/restrict reject self; account-type gated; stale partial resolved.
- [ ] Manifest regenerated; WPCS + PHPStan L5 + UX audit green; 390px + dark verified on any changed member surface.

## Build order
1. **A1** (P0 type-filter index) — biggest scale win, smallest change.
2. **B1+B2** (orphan cleanup + the one canonical `purge_user_relations()` helper) — data-integrity + the dedup.
3. **A2-A5** (directory path unification, search, N+1).
4. **D1+D3** (member-facing dead-ends) + **E** hygiene.
5. Seeded-lab `EXPLAIN` + manifest refresh as the gate.

## Out of scope (reaffirmed)
No meta-foundation table, no autoload work, no admin field-builder work, no directory-UX rebuild. (D1
now *does* finish denormalising follow/connection counts — see C2; the earlier "do not denormalize" stance
is withdrawn under principle P-A.)

---

## Appendix — code-proof log (every load-bearing claim, verified 2026-06-29)

| Claim | Verified at | Result |
|---|---|---|
| Type filter scans usermeta `meta_value` | `MemberDirectoryService.php:277`; `members.php:188-196` | confirmed unindexed |
| Indexed assignment table exists + ignored by directory | `Installer.php:1374` (`idx_type_id`); queried at `MemberTypeService.php:310,526-530` | confirmed |
| `get_type_member_count` "uncached" (audit claim) | `MemberTypeService.php:514-535` (`cache->set` `:534`) | **FALSE — already cached → C1 withdrawn** |
| Two purge lists, overlap + disagree | `UserCleanupListener.php:78-96` vs `PrivacyTools.php:893-903` | confirmed (listener=strikes/susp; eraser=reactions/poll_votes) |
| Delete path fires an action; eraser fires none | `UserCleanupListener.php:104` (`buddynext_user_relations_purged`); `PrivacyTools::erase_relational` no `do_action` | confirmed asymmetry |
| Follow counters "should be denormalized" | `CounterService.php:44-62` already writes usermeta `bn_follower_count`/`bn_following_count` | **store EXISTS but DEAD** |
| Follow counter only writer | `Admin/ToolsTab.php:278` (manual button) — no write-path, not in daily cron | confirmed orphaned |
| Follow read path uses the counter | `FollowService.php:447,478` run `COUNT(*)` cached, ignore usermeta | confirmed written-never-read |
| Daily self-heal covers follows | `CronService.php:279-284` (posts/spaces/hashtags only) | confirmed follows NOT covered |
| Connection counter exists | no `recount_connection_counts`; `ConnectionService.php:725` pure `COUNT(*)` | confirmed absent |
| `bn_follows`/`bn_connections` could hold a count column | `Installer.php:832-855` — edge tables, no per-user row | confirmed → usermeta is the home, not a column |
| `buddynext_register_profile_field` API exists | referenced in comment `AuthController.php:1137`; **defined nowhere** in `includes/` | dangling reference; no register API today |
| `remove_user_type` exists to reuse for self-clear | `MemberTypeService.php:484`; controller `:327-329` | confirmed |
| `online_ids()` / batched mutual exist to reuse | `PresenceService.php:228`; `MemberDirectoryService.php:417-458,476` | confirmed |

---

## People-expectation suggestions (lead-dev product input — decide for 1.0.4)

Not bugs — what people coming from Facebook/LinkedIn/X expect. None scope-locked yet.

1. **Follow-request context** — show "X mutual connections" + headline on the request list (T6). Mutual
   counts already computed; cheap, reduces "who is this?".
2. **See a space's sub-spaces before joining** — a "N sub-spaces" affordance on the directory space card
   (T10 covers the parent page; this is discovery pre-join).
3. **"Request sent" state for private accounts** — when a follow goes `pending`, show clear UI not a
   silent state. Cheap copy win on T1/T6.
4. **Directory "New members" + "Most active" sorts** — `bn_presence` + join date indexed; low-cost,
   high perceived-life.
5. **Profile completion nudge** — show the existing completion score on the member's own profile, not
   just onboarding. Reuses the score, no new query.
6. **Self-service member type with description** — pair each `self_select` type with a one-line meaning
   (T13 fixes the clear-bug; this is the expectation-complete version).
7. **Block should feel total** — after T1/T2/T17, verify a blocked user vanishes everywhere
   (search/explore/suggestions/mentions/profile); partial block erodes trust.
