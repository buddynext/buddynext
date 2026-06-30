# Members / Profile — 1.0.4 plan, status & handoff (single source of truth)

One file for the whole Members/Profile component: initial plan, what's done, what's pending. Spaces work
lives in `spaces-master-plan.md`. Both repos on `1.0.4`. Last updated 2026-06-29.

## STATUS — NOT STARTED (Spaces shipped first per owner sequencing)

Every task below is **⏳ PENDING**. Nothing built yet; the plan + exact touch-points are validated at
code `file:line`. Pick up here after the Spaces frontend panels land.

| Task | State | One-liner |
|---|---|---|
| T1 | ✅ DONE | Profile REST visibility leak (security) — `get_profile` now calls `can_view_profile()`, 404 on block/private; browser-verified (`f94c570f`) |
| T2 | ✅ DONE | Explore one-directional block → `block_related_ids()` (bidirectional, same helper the directory uses) |
| T3 | ⏳ PENDING | one canonical `purge_user_relations()` + `buddynext_purge_user_data` action (dup fix) |
| T4 | ⏳ PENDING | member-type directory filter → indexed `bn_member_type_assignments` (P0) |
| T6 | ✅ DONE | bound unbounded reads — `get_members`/controller always-paginate + 200 cap; `transfer_candidates` mods-first + LIMIT 200; `pending_followers`/`list_follow_requests` bounded+paginated; `suggestions` friend-sample capped 200. Browser-verified (members tab + transfer dropdown intact) |
| T9 | ⏳ PENDING | finish follow + build connection denormalization (cache-cold) |
| T13 | ⏳ PENDING | self-clear member type (404 fix) → reuse `remove_user_type()` |
| T14 | ⏳ PENDING | File profile field (decision D3: wire upload vs remove) |
| T15 | ⏳ PENDING | member field search → FULLTEXT via `bn_search_index` (decision D2) |
| T16 | ⏳ PENDING | directory server-render unify + per-card N+1 batch |
| A6 | ⏳ PENDING | scale-audit addendum (2026-06-30): SSR `online_ids` IN-list (A6a), unbounded `exclude` NOT IN (A6b), `post_count` orderby (A6c), REST `newest`→`u.ID` (A6d), total-includes-cursor (A6e), core-table notes (A6f) — see Workstream A6 |
| T17 | ⏳ PENDING | converge suspension filter on `moderation_exclude_sql()` (dedup) |
| T18 | ⏳ PENDING | remove dead digest queue write |
| T19 | ⏳ PENDING | invite email pre-fill + async send |
| T20 | ⏳ PENDING | hygiene (block self-target guard, account-type gate, stale partial) |
| T21 | ⏳ PENDING | digest cron at scale (verify cadence first) |

**Open decisions:** D2 (field search FULLTEXT vs LIKE), D3 (File field upload vs remove), and which of the
7 people-expectation suggestions (bottom of this file) land in 1.0.4.

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

**A1 · P0 — Type filter must use the indexed `bn_member_type_assignments`, not a usermeta value scan.**
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

**A2 · P1 — Unify the server-render path with the cached keyset REST path.**
The no-JS / hard-reload first page (`members.php:121-128`) uses OFFSET + `count_total`
(SQL_CALC_FOUND_ROWS) + a usermeta `meta_query`, **uncached** — opposite of REST `list_members` (keyset +
60s cache). Every hard reload re-runs the heavy query at 50k.
- **Fix:** route the server render through the same keyset + cache path (or cache page-1 with the same
  per-viewer salt); drop SQL_CALC_FOUND_ROWS; reuse the dedicated count (A3).
- **Touch:** `templates/directory/members.php:121-128, 226-229`; the shared path in
  `MemberDirectoryService::list_members` (`:289-324` keyset, `:526` cache).

**A3 · P1 — REST `total` recomputes the full filtered set on every cache miss.**
`MemberDirectoryService.php:386-401` runs `COUNT(*)` over a subquery that materialises the entire
filtered membership through correlated usermeta `EXISTS` clauses; only the 60s cache shields it.
- **Fix:** A1 already removes the usermeta type predicate from this subquery; keep the dedicated
  `COUNT(*)` (never `count(list_all())`), ensure it rides indexed predicates, and keep it cached
  alongside the page.
- **Touch:** `MemberDirectoryService.php:386-401`.

**A4 · P1 — Free-text directory search is leading-wildcard `meta_value LIKE '%term%'` per mirror field.**
`MemberDirectoryService.php:255-264, 691-695` — unindexable, scales with (searchable fields × 50k); does
**not** use the `ft_search` FULLTEXT index content search uses.
- **Fix (gated by decision D2):** fold searchable member fields into `bn_search_index` (FULLTEXT) and
  search there — **reuse** `Search\SearchService::index()` (`:48`, the existing writer) + the FULLTEXT
  reader (`:528-615`) — OR accept the LIKE scan as bounded by the small searchable-field count and
  document the ceiling. *Recommend FULLTEXT for parity.*
- **Touch:** `MemberDirectoryService.php:255-264, 691-695`; `SearchService.php:48` (writer) if FULLTEXT.

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

- **A6a · P0/HIGH — `online_user_ids()` returns an UNBOUNDED id list stuffed into `WP_User_Query 'include'`.**
  `templates/directory/members.php:214` → `MemberDirectoryService::online_user_ids()` (`:584-588`) →
  `PresenceService::online_ids()` (`:228-240`) is `SELECT user_id FROM bn_presence WHERE last_active > %s`
  with **no LIMIT**. At "30-50k active" that's a `WHERE ID IN (…50,000 literals…)` SQL string — megabyte
  query, parser blowup, defeats every other index.
  **Fix:** don't resolve online to an id list for SSR — push it as a JOIN on `bn_presence` (sargable
  `pres.last_active > …`), exactly as the REST path does (`:184-187, 282`). Folds into A2.

- **A6b · P0/HIGH — `exclude` is a globally-unbounded `NOT IN` literal list.**
  `excluded_user_ids()` (`:552-573`) fetches **all** suspended + **all** shadow-banned users globally (two
  unbounded `get_col`s) ∪ the viewer's blocks, fed into `WP_User_Query 'exclude'` (`members.php:130-132`)
  → `WHERE ID NOT IN (…)`. These populations grow with the site.
  **Fix:** mirror the REST correlated `NOT EXISTS` (indexed by `user_id`) for suspended/shadowban +
  `NOT IN (subquery)` for blocks (`:198-238`) via a `pre_user_query` clause injection — never materialise
  global exclusion ids in PHP. **Cross-ref T17** (converge suspension filter on `moderation_exclude_sql()`) —
  same fix, do once.

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

**C2 · Decision D1 — follow/connection counts → finish the denormalization that already half-exists.**
*(Code-proven — supersedes both the "keep cached" and the "add columns via Installer" drafts; both were wrong.)*

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

- **E1 · Self-target guard** on block/mute/restrict (`BlockController.php:122-135`) — reject acting on yourself.
- **E2 · `GET /users/{id}/account-type` is public** (`FollowController.php:167`) — leaks `is_private` to
  logged-out callers; gate to `require_auth` (or document as intentional).
- **E3 · Unused-partial trap.** `templates/parts/profile-field.php:138-224` handles only 5 of 13 field
  types; the live path uses `FieldType::render_input()` (`edit.php:443`, correct). Delete the stale partial
  or bring it to parity — no logic depends on it today.
- **E4 · Member-field registration API (developer-friendliness — P-B).** *Code-proven:*
  `buddynext_register_profile_field` is referenced in a **comment** (`AuthController.php:1137`) but is
  **defined nowhere** (grep of `includes/` finds no function, filter, or registry class) — a dangling API
  reference. `register_meta` is also never called anywhere. So today there is **no** code-level way for a
  developer to register a member field. Standard fix (mirror the planned Spaces field API for symmetry):
  ship a real `buddynext_register_member_field()` over `register_meta('user', …)` that surfaces the field
  in the profile edit UI + REST, and fix/remove the stale `AuthController:1137` comment. (Lower priority
  than A/B/D, but it is the concrete "developer-friendly" deliverable, not optional polish.)
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

**F4 · T17 — Converge suspension filter (dedup).** A shared `ModerationService::moderation_exclude_sql()`
(`:1194`) already exists (used by Feed/Follow/SpaceMember), but **Search** (`:374`), **Explore** (`:514`),
and **MemberDirectory** (`:201,557`) each hand-roll their own suspension/shadow-ban subqueries.
- **Fix:** route them through the shared helper where the bespoke variant isn't intentional.

**F5 · T18 — Dead digest queue (data).** `EmailDispatchListener` (`:119`) writes
`buddynext_digest_queue_{freq}` usermeta with **zero readers** (the cron queries `bn_notification_prefs`
directly), so the queue just accumulates orphaned usermeta.
- **Fix:** delete the dead write path.

**F6 · T19 — Invite email pre-fill + async send (UX/Perf).** `signup.php` has `'email' => ''` (`:100`)
though `$bn_invite` is resolved (`:65`) — invited users re-type their email. `InviteService::create`
loops `wp_mail()` synchronously (`:447`) so a large CSV import can time out.
- **Fix:** pass `$bn_invite['email']`; defer invite emails to Action Scheduler.

**F7 · T21 — Digest cron at scale (verify-first).** The digest cron sends inline `wp_mail()` capped at
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
- **D1 (C2):** keep cached `COUNT(*)` for follow/connection counts; do NOT denormalize unless profiling demands. *(proposed)*
- **D2 (A4):** member field-search via `bn_search_index` FULLTEXT vs accept bounded LIKE. *(recommend FULLTEXT)*
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
