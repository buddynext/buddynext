# Release 1.0.4 — Spaces + Members/Profile hardening (free + pro)

Status: **PLAN — plan all tasks, then execute with a self-check after each fix.** No code until the task
list is signed off. Both repos ship together at **1.0.4** (currently both 1.0.3). Free schema **v10 → v11**
(one additive migration). Pro uses its own `buddynextpro_schema_alters` track (no Pro schema change needed
for this release unless a task says so).

Scale bar: **10,000 installs × up to 50,000 members/site.** Object cache is a bonus layer, never a
dependency (hold up cache-cold). Consistent flow, **zero duplicate logic** — every fix reuses the one
canonical pattern (see contract below).

Source plans merged here (this is the execution index):
- `spaces-master-plan.md` (Spaces Layers 0-3)
- `members-profile-improvements.md` (Members A-E)
- member-adjacent audit (block/visibility/email/onboarding) → **Workstream F** below

### Repo state (pulled + verified 2026-06-29)
- **Free**: branch `1.0.4`, HEAD `15d361c4`, **0 behind remote** — audit ran on current code, nothing stale.
- **Pro**: branch `1.0.4`, HEAD `292754e`.
- **Nav API render-seam refactor is the big in-flight 1.0.4 work** (`includes/Nav/`: `NavRegistry`,
  `PanelRenderer`, `NavContext`, `SpaceNav`/`ProfileNav` providers; Phases 0-5 + profile cutover landed).
  It is now the **canonical surface for space/profile nav + panels** — so several frontend touch-points
  below moved from raw templates to the Nav seam. Re-verified each at code level; corrections inlined and
  flagged `[Nav-API re-verified]`. Note: NavRegistry `children` = nested **sub-nav tabs**, NOT
  **sub-spaces** — different concept, can't be reused directly for T10.

---

## Execution protocol (per the owner's "self-check after each fix, no dups")

**Hard rule — 100% app-ready, app-layer UX (no exceptions):** every feature ships its data + actions
through the REST app layer FIRST (`buddynext/v1` via `restFetch`), and the web UI is an **Interactivity
client of that same REST** — never a server-only PHP form/POST. The mobile app is a first-class consumer:
if the app can't render + drive a feature over REST, the feature is not done. Every surface handles ALL
states (loading / empty / error / success / 390px / dark / RTL / a11y). Judge each from a **fresh-prospect
lens** — would a new member/owner coming from Facebook/LinkedIn find it complete and obvious? No UX gap.
This means T7's REST `meta`/`fields` is the PRIMARY interface (the settings screen consumes it), and T8's
option saves go through REST (the legacy per-tab server POST is retired, not re-wired).

For **every** task, in order:
1. **Read the canonical pattern** for its concern (table below) — never introduce a parallel one.
2. **Implement** the minimal change at the validated file:line.
3. **Self-check immediately (not batched):**
   - `php -l` + `mcp__wpcs__wpcs_check_file` + PHPStan on touched files.
   - **Behavior proof**: the actual effect (REST response / DB row / `EXPLAIN` / rendered surface at
     390px + dark where UI). HTTP 200 / clean grep is NOT proof.
   - **No-dup check**: `grep` the new helper/key name repo-wide — confirm one definition, all callers
     routed to it; confirm no second copy of the logic was introduced.
   - **Contract check** (for any key/option/hook change): every reader + writer moved together
     (run `/wp-contract-audit` mentally or the skill).
4. **Update** the manifest delta + readme changelog line for that task.
5. Only then move to the next task.

### Canonical-pattern contract (reuse these; do not fork)
| Concern | The ONE pattern | Anchor |
|---|---|---|
| Per-space attribute storage | `bn_space_meta` + `*_space_meta()` (NEW in F0) — never a column or autoloaded option | F0 |
| Counting | denormalized store + `CounterService::recount_*` + daily `handle_recount_stats` self-heal | `CounterService.php:44`; cron `CronService.php:279-284` |
| User-data cleanup | ONE `purge_user_relations()` + `buddynext_purge_user_data` action | extends `ProfileService::delete_user_values():1848` |
| Suspension/shadow filter | `ModerationService::moderation_exclude_sql()` (EXISTS — converge the 3 surfaces that bypass it) | `ModerationService.php:1194` |
| Block filter | `PrivacyService::block_exclude_sql()` / `block_related_ids()` (bidirectional) | `PrivacyService.php:323` |
| Profile visibility | `PrivacyService::can_view_profile()` | `PrivacyService.php:231` |
| Directory data access | indexed store, never `usermeta.meta_value` | `bn_member_type_assignments`, `bn_search_index` |
| Async/fan-out | Action Scheduler, batched (defer_email pattern) | existing space-post fan-out |
| REST auth | `BaseRestController` gates | `:33,50` |
| Field rendering | `FieldType::render_input()` only | live caller `edit.php:443` |

---

## Pro coordination (verified — minimal exposure)
- Pro does NOT read per-space options or the `bn_member_type` filter → F0/M-A1 safe.
- Pro reads `bn_follows` only as `COUNT(DISTINCT)` analytics (`Pro FunnelService.php:201`) — NOT the
  per-user count → denormalizing follow counts is safe.
- Pro hooks `ProfileService::get_profile` via filters (`buddynext_profile_labels`, AdvancedField seams) →
  the visibility gate (F-block) runs BEFORE return, so Pro injection only fires for allowed viewers. Safe.
- **Pro task:** after each Free contract change, grep Pro for the same key/hook and confirm still wired;
  bump Pro to 1.0.4 + lockstep readme.

---

## STATUS SNAPSHOT (single progress tracker — update as tasks land)

Last updated: 2026-06-29 (end of Spaces build session). Spaces done first per owner sequencing.
Spaces frontend + full verification continue in a fresh session — see
`docs/plans/release-1.0.4-spaces-handoff.md`.

| Task | Area | State | Notes |
|---|---|---|---|
| T5 | Spaces | ✅ **DONE + verified** | indexes; EXPLAIN-proven |
| T7 | Spaces | ✅ **DONE + verified** | `bn_space_meta` foundation + REST app layer + Pro-collision reshape (zero data loss) |
| T7-Pro | Pro | ✅ **DONE + verified** | Pro BrandService → Free `*_space_meta()`; Pro CREATE removed |
| T8 | Spaces | ✅ **DONE + verified** | 8 built-in options dogfooded as core fields; options→meta migration; autoload P0 closed |
| T10 (app layer) | Spaces | ✅ **DONE + verified** | `/subspaces`, breadcrumb, `subspace_count`, directory root-filter |
| T10 (move/detach) | Spaces | 🟡 **DONE — code-verified** | `PUT parent_id` guards; browser-verify next session |
| T11 | Spaces | 🟡 **DONE — code-verified** | admin pending col + archive + hierarchy; browser-verify next session |
| T10 (Nav UX) | Spaces FE | ⏳ **PENDING** | breadcrumb + Sub-spaces section + Add-CTA (handoff **R1**) |
| T12 | Spaces FE | ⏳ **PENDING** | member panel → REST/Interactivity (handoff **R2**) |
| T7 (web field panel) | Spaces FE | ⏳ **PENDING** | render fields on settings screen (handoff **R3**) |
| T1 | Members | ⏳ **PENDING** | Profile REST visibility leak (security) |
| T2 | Members | ⏳ **PENDING** | Explore one-directional block |
| T3 | Members | ⏳ **PENDING** | canonical `purge_user_relations()` + action (dup fix) |
| T4 | Members | ⏳ **PENDING** | member-type filter → indexed table (P0) |
| T6 | Members/Spaces | ⏳ **PENDING** | bound unbounded reads (`get_members` etc.) |
| T9 | Members | ⏳ **PENDING** | finish follow + build connection denormalization |
| T13 | Members | ⏳ **PENDING** | self-clear member type (404 fix) |
| T14 | Members | ⏳ **PENDING** | File profile field (decision D3) |
| T15 | Members | ⏳ **PENDING** | member field search → FULLTEXT (decision D2) |
| T16 | Members | ⏳ **PENDING** | directory server-render unify + N+1 batch |
| T17 | Members | ⏳ **PENDING** | converge suspension filter (dedup) |
| T18 | Members | ⏳ **PENDING** | dead digest queue removal |
| T19 | Members | ⏳ **PENDING** | invite email pre-fill + async send |
| T20 | Members | ⏳ **PENDING** | hygiene (self-target guard, account-type gate, stale partial) |
| T21 | Members | ⏳ **PENDING** | digest cron at scale (verify-first) |

**Open decisions:** D2 (field search FULLTEXT vs LIKE), D3 (File field upload vs remove), and which of the
7 people-expectation suggestions land in 1.0.4. **Release gate** unchanged (see bottom).

## TASK LIST (priority order; each is independently shippable + self-checked)

### Priority 1 — Privacy/security + data-integrity (ship first)

**T1 · Profile REST visibility leak** *(Security)* — `GET /users/{id}/profile` returns full data to
blocked viewers / non-followers of private accounts.
- Fix: gate `ProfileController::get_profile:645` with `PrivacyService::can_view_profile($viewer,$id)`;
  return 404 (or minimal) when false — mirror `templates/profile/view.php:41`.
- Reuse: existing `can_view_profile()`. No new logic.
- Self-check: REST as blocked user → 404/minimal; as allowed → full + Pro labels still inject.

**T2 · Explore "Members" one-directional block** *(Privacy)* — viewer-blocked-by users still appear.
- Fix: `ExploreService::excluded_member_ids:530` use `block_related_ids()` (bidirectional) not
  `blocked_users()`.
- Reuse: same helper the directory uses. Self-check: A blocks B → B absent from A's Explore deck AND
  A absent from B's.

**T3 · One canonical user-data purge** *(Data integrity)* — two purge lists disagree
(`UserCleanupListener:78-96` vs `PrivacyTools:893-903`); both miss member_type_assignments, presence,
search_index, space_bans, appeals (+ listener misses shares).
- Fix: ONE `purge_user_relations($user_id)` (home: small `MemberCleanupService`) with the full table set;
  both callers use it; fire `buddynext_purge_user_data($user_id,$context)` for addons; decrement affected
  denormalized counters.
- Reuse/dedup: extends `delete_user_values():1848`; **removes** duplication (net-negative code).
- Self-check: delete a user with rows in every table → all gone, counters correct; GDPR-erase path hits
  the same set.

### Priority 2 — Scale P0s (zero/low UX, big wins)

**T4 · Member-type directory filter → indexed table** *(P0 scale)* — `MemberDirectoryService:277` +
`members.php:188` scan `usermeta.meta_value`; indexed `bn_member_type_assignments` ignored.
- Fix: slug→type_id via `MemberTypeService::get_by_slug`, EXISTS on the assignment table.
- Reuse: keep the usermeta mirror for other fast-reads; only change the filter predicate. No 3rd copy of
  the `WHERE type_id` SQL.
- Self-check: `EXPLAIN` on seeded 50k members → index used, zero `meta_value` scan.

**T5 · Spaces roster + parent indexes** *(P0 scale)* — roster filter/sort scans 50k rows; `parent_id`
unindexed.
- Fix (Free schema v11): `ALTER bn_space_members ADD KEY space_status (space_id,status,joined_at)`;
  `ALTER bn_spaces ADD KEY parent (parent_id)`; (`directory` composite only if `EXPLAIN` confirms).
- Self-check: `EXPLAIN` roster page → index-ordered, no filesort; sub-space count → index, no scan.

**T6 · Bound unbounded reads** *(P0/P1)* — `SpaceMemberService::get_members` default `$limit=0`;
`FollowController::list_follow_requests:411` returns all IDs; `FollowService` suggestion `IN(followers)`
no LIMIT (`:583`); `transfer_candidates` unbounded.
- Fix: sane default page sizes + hard caps; paginate the follow-requests list (keyset).
- Self-check: each endpoint at 50k → bounded payload, paginated.

### Priority 3 — The foundation (Spaces Layer 0; widest blast radius → its own careful pass)

**T7 · `bn_space_meta` substrate + space-field system = the ONE extensibility flow** *(Foundation)* —
Spaces have no meta store, and the management page has a tab-registry seam
(`buddynext_part_space_settings_tabs_args`, proven by Pro's Brand tab) but **no save seam and no canonical
storage** — so a dev can add a tab + screen yet cannot persist options through core (Pro brings its own
REST + nonce to work around it). Fix all of this as **one field flow**, not a meta API + a separate
settings helper.
- **Storage:** table (v11) + `$wpdb->bn_spacemeta` alias + `get/add/update/delete_space_meta()` + native
  meta cache. Confirmed greenfield (no `bn_space_meta`/`SpaceMeta`). Skip the uninstall task (wildcard drop
  covers it).
- **Registration (the single developer entry point):** `buddynext_register_space_field()` over
  `register_meta('bn_space')`, reusing the existing `FieldType` engine (add `display_text()`/`rest_value()`).
- **The registration AUTOMATICALLY drives all four surfaces — no extra helper, no per-tab handler:**
  1. **Render** — a registered field renders in space management via the existing `FieldType::render_input()`
     (same engine the profile editor uses), grouped into a section/tab through the existing
     `buddynext_part_space_settings_tabs_args` seam. Owner-editable fields (visibility/`show_on = manage`)
     appear automatically.
  2. **Save** — the field system owns the save: registered fields persist to `bn_space_meta` through the
     meta API on the settings POST, so a dev's options save **without** writing their own REST/nonce
     (replaces the per-tab hardcoded handlers for custom fields; built-in tabs migrate to the same path in T8).
  3. **REST/app** — `meta`/`fields` on `GET/POST /spaces/{id}` + `GET /spaces/fields` schema, viewer-filtered.
  4. **Discovery** — `WP_Meta_Query` opt-in on the directory.
- **CORE dogfoods its own field API (no two-tier system).** The space-field system is **core
  functionality**, not a developer-only extension seam. Core's OWN built-in options are registered through
  the **same** `buddynext_register_space_field()` call a third party uses — so there is one path, not
  "core hardcoded options" + "a lesser dev API beside them." The developer path IS the core path. (This is
  what makes T8 below not a side-migration but simply "register the built-in options as core fields.")
- **Net for developer-friendliness:** one `buddynext_register_space_field()` call ⇒ the option is stored,
  shown in the management screen with the standard label+hint+input, saved, exposed over REST, and
  app-ready — identical for core and third parties, same mental model as profile fields.

**T8 · Register the 8 built-in options AS core space fields (dogfood T7)** *(P0 autoload + contract)* —
This is **not** a bespoke option→meta migration; it is "core registers its own settings as space fields"
through the T7 flow. The result: storage moves to `bn_space_meta` (kills the autoload P0), render+save go
through the field system (retires the per-tab hardcoded POST handlers), and the options become
REST/`meta_query`-visible like any field. **`[Nav-API re-verified]` 10 reader sites** (the Nav refactor
ADDED one; team flagged "legacy-option convergence" as a follow-up, so this is expected/owned).
- The 8 options to register as core fields: `push_to_feed`, `mvs_media_tab`, `jetonomy_forum_id`,
  `require_join_approval`, `who_can_post`, `who_can_invite`, `banned_words`, `default_notification_pref`.
- Move ALL readers off `get_option('bn_space_…')` onto `get_space_meta()`:
  `SpacePostGuard:114`, `SpaceController:392,1025`, `JetonomyBridge:406,768`, `FeedService:487-513`
  (kill the `alloptions` `preg_match` scan), **`Nav/Providers/SpaceNav.php:111` (NEW reader, `mvs_media_tab`)**,
  `SafeguardService:138`, `settings.php:321-325`. One-time data migration of existing option rows → meta;
  drop the per-tab save handlers now that the field system saves; fix `delete()` cleanup (meta cascades).
- Self-check: contract-audit (every key read==written via the field/meta path); fresh-install +
  existing-space both work; `alloptions` has zero `bn_space_*` rows on seeded lab; Jetonomy/MediaVerse/
  banned-words/who-can-post all still apply; the space Media tab still toggles via SpaceNav after cutover.

### Priority 4 — Counting standard (consistent across product)

**T9 · Finish follow + build connection denormalization** *(Scale, cache-cold)* — `[Nav-API re-verified]`
follow counters exist in usermeta (`CounterService:44`) but are DEAD (only an admin button writes; read
path runs `COUNT(*)`); connections have none. **More consumers now:** the Nav badges call
`follower_count()` (`Nav/Providers/ProfileNav.php:90,231`) — another `COUNT(*)` site. 1.0.4 already did
partial nav mitigation (`2c1dc47a` skip per-tab COUNT at scale, `e13f626f` hide badges by default), but
the underlying read is still `COUNT(*)` — so denormalizing fixes the badge path too.
- Fix: maintain `bn_follower_count`/`bn_following_count` in `FollowService::follow:64/unfollow:204/approve:685/reject:730`;
  read path reads usermeta (lazy-recount on miss); add `recount_all_follow_counts()` to daily cron; build
  symmetric `bn_connection_count` + `recount_connection_counts()` maintained in `accept_request:155`/`remove_connection:339`/T3 purge.
- Reuse: `CounterService` + existing daily cron + ToolsTab manual recount. No new table/column (usermeta).
- Self-check: cache-cold profile render does O(1) read; counts correct after follow/unfollow/delete +
  after a forced recount.

### Priority 5 — Sub-spaces navigable (Spaces Layer 2; membership per-space, no inheritance)

**T10 · Sub-space discovery + nav** — `[Nav-API re-verified]` created but unreachable. SpaceNav registers
TABS (feed/members/media/about/moderation), NOT sub-spaces, and NavRegistry `children` = nested sub-nav
tabs ≠ sub-spaces — so sub-spaces are genuinely still unreachable AND must now be built **through the Nav
seam**, not raw templates.
- Fix: `get_subspaces()` (bounded, cached, index by T5); hydrate a `parent` summary; `GET /spaces/{id}/subspaces`;
  directory `parent_id IS NULL` root-filter; surface sub-spaces via the **Nav API** — a SpaceNav panel/section
  (`includes/Nav/Providers/SpaceNav.php` register_items + a render_panel) for the parent's "Sub-spaces" list
  + breadcrumb, rather than hand-rolled template markup; manager-visible "Add sub-space" CTA (owner OR
  moderator — current modal picker is owner-only via `owned_root_spaces`, stricter than the API at
  `SpaceController:628`); move/detach via `PUT`.
- Locked: membership is per-space — no inherited membership, no privacy cascade, no parent-feed rollup.
- Self-check: join parent ≠ join child; secret child hidden from parent-only member; sub-space section +
  breadcrumb render through the seam at 390px/dark; `EXPLAIN` clean.

### Priority 6 — Owner/admin completeness + member-facing dead-ends

**T11 · Spaces admin completeness** *(Layer 3)* — `Admin/Spaces.php` add pending-requests column +
non-destructive Archive/Unarchive (services exist) + hierarchy visibility.
**T12 · Member-management panel → REST/Interactivity** — `[Nav-API re-verified]` convert the legacy
full-page POST (`space-settings-panel-members.php:132-214`) to the toast model. Note: space panels now
render through the Nav seam (`PanelRenderer` → `NavItem::render_panel`); align this conversion with that
seam (a settings/members panel via the provider) rather than re-wiring the old standalone template, so it
doesn't drift from the 1.0.4 nav architecture.
**T13 · Self-clear member type** — empty `type_slug` 404s (`MemberTypeController::set_user_type:297`);
route empty → existing `remove_user_type():484` under the self-select gate. No new route.
**T14 · File profile field** — renders URL input only (`FieldType.php:341`). **Decision D3**: wire a real
upload (reuse `/me/avatar` media path) OR remove `file` from the matrix. *(needs owner call)*

### Priority 7 — Member-adjacent + hygiene

**T15 · Member field search** *(P1, decision D2)* — fold searchable fields into `bn_search_index` FULLTEXT
(reuse `SearchService::index():48`) vs bounded LIKE. *(recommend FULLTEXT)*
**T16 · Directory server-render unify** *(P1)* — `members.php` uncached OFFSET+`count_total` → shared
cached keyset path; per-card N+1 (`member-directory-grid.php:131,135`) → batch `online_ids():228` +
mutual counts before loop.
**T17 · Converge suspension filter** *(dedup)* — Search/Explore/MemberDirectory bypass
`moderation_exclude_sql():1194`; route them through it (where the bespoke variant isn't intentional).
**T18 · Dead digest queue** *(Data)* — `EmailDispatchListener:119` writes `buddynext_digest_queue_*` with
zero readers; remove the write path.
**T19 · Invite email pre-fill + async send** *(UX/Perf)* — `signup.php:100` pass `$bn_invite['email']`;
defer CSV invite `wp_mail` loop (`InviteService:447`) to Action Scheduler.
**T20 · Hygiene** — block/mute self-target guard (`BlockController:122`); gate
`GET /users/{id}/account-type` (`FollowController:167`, leaks `is_private`); retire stale 5-of-13 partial
(`profile-field.php`); fix dangling `buddynext_register_profile_field` comment (`AuthController:1137`) —
and (E4/P-B) ship a real `buddynext_register_member_field()` over `register_meta('user')` for symmetry
with the new space-field API.
**T21 · Digest cron at scale** *(verify-first)* — 200/run inline `wp_mail`; confirm cadence, move sends to
Action Scheduler if a 50k backlog is real.

---

## Suggestions based on member / owner expectations (lead-dev product input)

These are NOT bugs — they're what people coming from Facebook/LinkedIn/Twitter expect. Flagged for your
call; none are scope-locked yet:

1. **Follow request UX — show requester context.** When approving a follow/connection request, members
   expect to see "X mutual connections" + headline inline (LinkedIn pattern). We already compute mutual
   counts — surfacing them on the request list (T6) is cheap and meaningfully reduces "who is this?" friction.
2. **Sub-spaces: let members SEE a space's sub-spaces before joining the parent** (FB Groups shows
   sub-groups on the group card). T10 covers the parent page; consider a "N sub-spaces" affordance on the
   space card in the directory too — discovery, not just post-join.
3. **"Why am I seeing this?" for private accounts.** When a follow goes to `pending`, the follower should
   get clear UI ("Request sent — they approve followers") not a silent state. Cheap copy win on top of T1/T6.
4. **Member directory: "New members" + "Most active" default sorts.** Owners of large communities expect
   to surface fresh + active members, not just A-Z. We have `bn_presence` + join date indexed — a sort
   option is low-cost and high perceived-life.
5. **Profile completion nudge.** We compute a completion score but only show it in onboarding. Members
   expect a gentle "Complete your profile (70%)" prompt on their own profile (LinkedIn). Reuses existing
   score — no new query.
6. **Self-service member type with description.** If `self_select` types exist, members expect to know
   what each type *means* before choosing (T13 fixes the clear-bug; pairing each type with a one-line
   description is the expectation-complete version).
7. **Block should feel total.** After T1/T2/T17, verify the *experience* matches expectation: a blocked
   user vanishes everywhere (search, explore, suggestions, mentions, profile) — people expect block to be
   absolute, and partial block is worse than none for trust.

If you greenlight any, I'll fold them into the matching task with the same validation discipline.

---

## Sequencing & release gate
1. P1 (T1-T3) → 2. P2 (T4-T6) → 3. P3 foundation (T7-T8) → 4. P4 counts (T9) →
5. P5 sub-spaces (T10) → 6. P6 admin/dead-ends (T11-T14) → 7. P7 adjacent/hygiene (T15-T21).
- **Per-task gate:** the self-check protocol above (no batching).
- **Release gate (before tagging 1.0.4):** seeded 5k-space / 50k-member lab `EXPLAIN` + autoload check;
  `/wp-contract-audit` clean; full WPCS/PHPStan/UX-audit green; manifest refreshed (`/wp-plugin-onboard
  --refresh`) both repos; readme changelog (WooCommerce action-prefix style) + lockstep Free/Pro release
  bodies; Docker pristine-install smoke of both zips.

## Open decisions before code
- **D2 (T15):** member field search → FULLTEXT (recommended) vs bounded LIKE.
- **D3 (T14):** File field → real upload vs remove the type.
- **Suggestions 1-7:** which (if any) to fold into 1.0.4 vs defer.
