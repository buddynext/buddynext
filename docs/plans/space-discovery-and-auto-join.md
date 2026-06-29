# Space Discovery (Suggestions) + Auto-Join — Feature Plan

Status: **PLANNED** · Branch: `1.0.4` · Author: vapvarun
Grounded against live code (2026-06-30) via three exploration passes — every integration point below carries a `file:line` anchor.

---

## 1. Goal & general use cases

Help members **find the right spaces** (discovery) and let owners **seed membership** for spaces that everyone (or a whole member type) is expected to be in (auto-join) — at the mainstream-social bar (Facebook "Groups you may like", LinkedIn suggested groups, Slack/Discord default channels). Two features, deliberately on opposite sides of the consent line:

| | Use case | Who triggers | Consent model |
|---|---|---|---|
| **A. Suggested spaces** | "New member lands on an empty community → here are spaces for you"; "returning member browses the directory → Suggested for you rail"; "onboarding step 2 → join a few spaces" | the member taps **Join** | fully opt-in (additive, nothing happens silently) |
| **B. Auto-join** | "Every new member should be in *Announcements*"; "Staff member type auto-joins *Staff Room*" | admin/owner config, fired on signup / member-type assignment | admin-curated, always visible + leaveable |

## 2. Non-goals (scope guardrails)

- **No broad auto-join rules engine** in Free (match-any-user-by-field → bulk join). That tips from helpful into spam and violates the standing *no-forced-action* rule (`[[one-click-connect-default]]`). Auto-join is owner-curated, per-space only.
- **No new backend "create/assign" admin screen** (`[[frontend-only-creation]]`). Auto-join is configured in the per-space owner Settings → Permissions panel, not a site-wide admin list.
- **No silent mass-join of existing members.** Going-forward triggers are automatic; backfilling existing members is an explicit, batched owner action.
- **No secret-space surfacing.** Suggestions never reveal a space the viewer can't already see.
- **Performance-first** (`[[performance-first-fast-community]]`): suggestion ranking uses only cheap, index-backed signals + a cache; auto-join backfill is Action-Scheduler-batched.

---

## 3. Part A — Suggested Spaces

### 3.1 Ranking service (net-new — mirrors people suggestions)

There is **no suggested-spaces engine today** (the closest is the naive `ORDER BY member_count` in onboarding `templates/onboarding/index.php:71` and the directory "Popular this week" card `directory.php:337`). The proven people-suggestion stack to mirror: `ExploreService::suggested_member_ids()` (`includes/Feed/ExploreService.php:168`) + cached `WidgetService::suggested_follows()` (`includes/Sidebar/WidgetService.php:86`, cache `includes/Sidebar/WidgetCache.php`).

**New: `ExploreService::suggested_space_ids( int $user_id, int $limit = 6 ): int[]`** — ranks candidate spaces by three cheap signals, all index-backed:

1. **Social proof** (highest weight) — spaces that people the viewer follows are active members of:
   `bn_follows (follower_id = viewer, status='approved') → following_id → bn_space_members (user_id IN(...), status='active')`. Index-backed by `bn_space_members KEY user_status (user_id, status)` (`Installer.php:1201-1212`); follow set from `FollowService` pattern (`FollowService.php:417-424`).
2. **Category affinity** (medium) — categories of the spaces the viewer already joined → other spaces in those categories (`bn_spaces.category_id`).
3. **Popularity** (low / cold-start fallback) — `bn_spaces.member_count DESC` (denormalized, `Installer.php:1183`).

**Exclusions** (the hard gate): already-joined spaces (`SpaceMemberService::spaces_for_user()` `SpaceMemberService.php:1224`), secret/unlisted spaces the viewer can't see (reuse the `list_query_scope` viewer predicate), archived spaces, blocked owners. **A member already in everything → empty result (the rail/section simply doesn't render).**

### 3.2 `list_spaces()` gap to close

`SpaceService::list_query_scope()` (`SpaceService.php:873-969`) has **no exclude-already-joined arg** and **no activity ordering** (orderby allowlist = `member_count|name|created_at` only). Add:

- **`exclude_space_ids` (int[])** → `AND s.id NOT IN (...)` (bounded list, sargable). Lets the suggestion query and any future "spaces I'm not in" view exclude the viewer's memberships in SQL rather than over-fetch + PHP-filter.

(Activity ranking is a known deferred gap — out of scope here; popularity via `member_count` is the cold-start proxy.)

### 3.3 Cache

A `WidgetService`-style cached accessor (or fold into `WidgetService`): key `suggested-spaces:{uid}:{limit}`, short TTL, busted on the viewer's **join/leave** (`buddynext_space_member_joined` / `_left`) and **follow/unfollow** (mirror `WidgetListener.php:50-72`). Suggestions are per-viewer and tolerate brief staleness.

### 3.4 REST (app-layer — `[[app-layer-ux-rule]]`)

**`GET /buddynext/v1/spaces/suggestions?limit=6`** (auth-required) → hydrated space rows (same shape as `list_spaces`, with `viewer_role`=null since suggestions are non-member). The web surfaces are SSR for first paint but the endpoint exists for app parity + future "refresh suggestions".

### 3.5 Surfaces (three entry points)

1. **Directory rail** — a "Suggested for you" `<section>` of `parts/space-directory-card.php` cards, injected via the existing **`buddynext_spaces_directory_before`** hook (`directory.php:393`, passes `$current_user_id`) → **zero core template edit**, and placed *outside* `[data-bn-sd-grid]` so a reactive filter swap doesn't wipe it. Gate: logged-in + unfiltered + page 1.
2. **Onboarding step 2** — replace the naive `list_spaces(['orderby'=>'member_count'])` at `templates/onboarding/index.php:71-77` with `suggested_space_ids($user_id)` (viewer-aware, excludes already-joined). Step 2 is *already* the "Spaces" step (`index.php:124-141`) with inline join — just upgrade the candidate query.
3. *(Optional, drop-in)* a **`buddynext_right_sidebar`** "Suggested spaces" card mirroring "Popular this week" (`directory.php:336-384`). Deferred unless the rail proves insufficient (avoid double-surfacing on the same page).

All reuse the **already-fixed 1-click Join** (`[[F5-a]]` `routeMembership`).

---

## 4. Part B — Auto-Join (owner-curated)

### 4.1 Two owner settings (Permissions panel)

Added as **core space fields** in `CoreSpaceFields` (`includes/Spaces/CoreSpaceFields.php:33`, `section => 'permissions'`, `core => true`) — same shape as `who_can_post` (`:56-72`):

| Field | Type | Meaning |
|---|---|---|
| `auto_join_on_signup` | `boolean` (default `0`) | "Automatically add new members to this space." |
| `auto_join_member_types` | `multiselect` (options from `MemberTypeService::get_all()` `MemberTypeService.php:60`) | Optional filter: "...only members of these types (leave empty = all new members)." |

**Owner mental model:** *Auto-join new members* → optionally *limit to member types*. Rendered in `space-settings-panel-permissions.php` after `require_join_approval` (`:120-129`); saved by the permissions POST handler (`settings.php:185-203`) — multiselect stored comma-joined (`FieldType.php:248-264`), read via `buddynext_get_space_field()` (`buddynext.php:343`). The panel form already carries the savebar trigger (`[[F7-a]]` fix), so this is a clean add.

### 4.2 Triggers (going-forward) — `AutoJoinListener implements ListenerInterface`

- **`user_register`** (priority 20, after `VerificationListener`) → join every space where `auto_join_on_signup=1` **AND** `auto_join_member_types` is **empty** (at signup the new user has no member type yet, so only the unfiltered "all new members" spaces apply). `user_register` is the only guaranteed-once-per-member hook.
- **`buddynext_member_type_assigned`** (`MemberTypeService.php:473`, params `$user_id, $slug, $old_slug`) → join every space where `auto_join_on_signup=1` **AND** `auto_join_member_types` **contains `$slug`**.

Both call **`SpaceMemberService::join($space_id, $user_id)`** directly — it is **idempotent** (no-op if already active, `SpaceMemberService.php:123`), **blocks banned users** (`:107`), and **fires `buddynext_space_member_joined`** (`:171`) so notifications/feed/bridges all run normally.

### 4.3 `AutoJoinService`

- `eligible_spaces_for_signup(): int[]` — space ids with `auto_join_on_signup=1` AND empty types. Query `bn_space_meta` by `meta_key` (indexed, WP-meta-shaped), small result, **cached** (bust on any space's permissions save).
- `eligible_spaces_for_type( string $slug ): int[]` — `auto_join_on_signup=1` AND types contains `$slug`.
- `apply_to_existing( int $space_id ): void` — **explicit owner backfill** (the "add current eligible members" action). Action-Scheduler-batched per the canonical fan-out (`as_enqueue_async_action('buddynext_auto_join_batch', [...], 'buddynext')` + keyset paging via `MemberTypeService::get_user_ids_by_type($slug, $limit, $offset)` `:550`, inline-drain fallback when AS absent — pattern from `NotificationListener.php:600-658`).

### 4.4 Guardrails

- Auto-join only ever sets **active member** status on the owner's own space; never reveals/joins anything the owner didn't explicitly flag.
- Member is always shown in "Spaces you've joined" and can **leave** anytime.
- Backfill is **never automatic on toggle-save** — it is the separate `apply_to_existing` button (with a "queued, N members" toast). Saving the setting only changes going-forward behaviour.
- Idempotent + ban-respecting via `join()`; batched at scale.

---

## 5. Free vs Pro split

| | Free (this plan) | Pro (future) |
|---|---|---|
| Suggestions | social-proof + category + popularity, cached | AI/affinity ranking, "because you follow X" explanations |
| Auto-join | `auto_join_on_signup` + member-type filter + backfill | full rule-builder on the Moderation Rules engine, profile-field conditions |

Pro extends via filters: `buddynext_space_suggestion_ids` (re-rank) and `buddynext_auto_join_eligible_spaces` (add rule-driven spaces).

---

## 6. Build sequence

**Phase 1 — Auto-join data + settings (owner-facing, no consumers yet)**
1. `CoreSpaceFields`: add the two fields (boolean + multiselect with member-type options).
2. `space-settings-panel-permissions.php`: render the two controls; `settings.php` read-bundle + POST handler.
3. Verify: render, save→`bn_space_meta`, read-back, savebar fires, 390px/dark.

**Phase 2 — Auto-join consumers**
4. `AutoJoinService` (eligible_* + cache) + `AutoJoinListener` (`user_register`, `buddynext_member_type_assigned`), wired in `Plugin::init()`.
5. `apply_to_existing` + the AS batch worker + the owner "add current members" button.
6. Verify: new user → joins default space; assign member type → joins mapped space; backfill batches; idempotent; banned skipped.

**Phase 3 — Suggestion engine**
7. `SpaceService`: `exclude_space_ids` arg.
8. `ExploreService::suggested_space_ids()` + cached accessor + cache-bust on join/leave/follow.
9. REST `GET /spaces/suggestions`.
10. Verify: ranking (social proof > category > popularity), exclusions (joined/secret/archived), cache.

**Phase 4 — Suggestion surfaces**
11. Directory "Suggested for you" rail (via `buddynext_spaces_directory_before`).
12. Onboarding step-2 query upgrade.
13. Verify per surface in browser (all states + empty + 390px + dark + 1-click join).

**Phase 5 — Docs + manifest**
14. Update `audit/manifest.json` (new endpoint + hooks), CLAUDE.md Recent Changes, this plan → DONE.

## 7. Testing checklist (big-site)

- Suggestions: 0 candidates (all joined) → no rail; secret space never suggested; blocked owner excluded; cache busts on join.
- Auto-join: signup with `auto_join_on_signup` → member added + notification fires; member-type assign → mapped space joined; already-member → no-op; banned user → skipped; backfill on 1000+ members → AS batches, no timeout.
- Settings: multiselect saves/reads as comma-joined; empty types = "all"; savebar appears + saves.
- REST: `GET /spaces/suggestions` 401 unauth, returns excludes-joined, respects limit.

---

## 8. Review-pass refinements (improvements over the first draft)

A critical re-read surfaced seven gaps; resolving them before coding:

1. **Concrete ranking combination (was hand-wavy).** `suggested_space_ids()` runs **three bounded queries** and merges in PHP, not one mega-join:
   - Q1 social proof: `SELECT space_id, COUNT(*) c FROM bn_space_members WHERE user_id IN (followed_ids) AND status='active' GROUP BY space_id` (followed_ids itself bounded, e.g. first 200 follows).
   - Q2 category affinity: spaces in the viewer's joined-categories, `ORDER BY member_count DESC` (bounded).
   - Q3 popularity fallback: top `member_count` spaces (bounded).
   Score = `3*socialProof + 2*categoryAffinity + 1*popularityRank`; dedup; drop excluded (joined/secret/archived/blocked); **interleave so one category can't fill the whole rail** (diversity cap ~2 per category); take `limit`. Each query is index-backed; the whole thing is cached, so cost is paid once per viewer per TTL.

2. **The multiselect is a *sub-option* of the boolean (UI clarity).** `auto_join_member_types` only has meaning when `auto_join_on_signup=1`; render it indented/disabled under the toggle (JS or just a hint), and the consumer ignores types when the boolean is off. Prevents the contradictory "off but types set" state from confusing owners.

3. **Backfill pager for the *all-members* case (was type-only).** `apply_to_existing()` pages members of a type via `get_user_ids_by_type()` when types are set; for an empty-types (all new members) space it keyset-pages the **users table** (`WP_User_Query` ordered by ID, or a direct keyset scan) — the type pager doesn't cover "everyone". Both feed the same idempotent `join()` worker.

4. **Notification-storm guardrail on backfill (new risk).** `join()` fires `buddynext_space_member_joined` per row; a 1000-member backfill could flood the owner/mods with "X joined" notifications. Verify the actual consumer of that hook during Phase 2; if it notifies, the AS backfill worker sets a **bulk-context flag** the notification listener checks and skips (the join row + feed still happen; only the per-row owner ping is suppressed). Single going-forward joins keep their normal notification.

5. **REST home + controller.** `GET /spaces/suggestions` lives on `SpaceController` (alongside `list_spaces`), reusing its hydration + auth base (`REST/BaseRestController`).

6. **Backfill trigger placement.** The "Add current eligible members" button sits in the Permissions panel beside the auto-join controls; it calls a small REST action (`POST /spaces/{id}/auto-join/apply`, manage-gated) → `apply_to_existing()`. Saving the settings never backfills (going-forward only).

7. **Rail-during-reactive-filter + cache TTL backstop.** The directory rail is SSR-gated to page-1-unfiltered; on a reactive filter it harmlessly stays (it's "suggested for you" context, not part of the filtered grid). Suggestion cache busts on join/leave/follow/unfollow, with a short TTL as the backstop for space create/archive/delete (no extra invalidation wiring needed).

These are folded into the Phase sequence in §6 — no phase reordering, just sharper acceptance criteria.
