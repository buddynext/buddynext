# Contract Conformance — Visibility & Privacy Resolver

**Contract:** One visibility/privacy resolver, most-restrictive-wins, used uniformly by feed, profile, directory, search.
**Spec refs:** `docs/specs/features/01-social-graph.md` (block/mute feed suppression, visibility levels), `docs/specs/features/member-fields-search-privacy.yaml` (searchable mirror), `docs/specs/features/17-roles-permissions.md` (permission model — distinct from visibility).
**Verdict:** partial-needs-wiring
**Date:** 2026-05-31

---

## Shape of the contract in code

There is no single class literally named `VisibilityResolver`. Instead the contract is realised by a small, coherent set of cooperating pieces. That is acceptable — "one resolver" is satisfied in spirit by each visibility decision routing through a canonical method rather than being re-derived ad hoc:

- **Social-graph view gate** — `SocialGraph/PrivacyService` (`can_view_profile`, `can_view_activity`, `can_follow`, `can_connect`). Always consults `BlockService` first (block = hard deny), then `profile_visibility` (public / followers / connections / private). This is the documented single entry point ("Callers ... should consult this method instead of duplicating the rules", `PrivacyService.php:215-219`).
- **Profile-field most-restrictive-wins** — `Profile/ProfileService`. `visibility_rank()` (`ProfileService.php:1292`), `clamp_visibility()` (member may only tighten, `:1311`), `effective_visibility()` (MAX of group/field/entry rank, `:1337`), and the inline non-owner loop in `get_profile()` (`:616-644`). Correct: private > connections > followers > public.
- **Cross-content search visibility** — `bn_search_index.visibility` column. Writers (`SearchIndexListener`) stamp `public`/`private` at index time; readers (`SearchService::search`) hard-filter `WHERE si.visibility = 'public'`. Privacy is enforced at write time, so reads need no per-row resolver call.
- **Directory searchable mirror** — `bn_field_{key}` usermeta is written by `ProfileService::sync_search_mirror()` ONLY when `effective_visibility === 'public'` (`ProfileService.php:1373-1390`). `MemberDirectoryService` searches the mirror with no per-row check because, by construction, the mirror only holds public values.

This is a sound architecture: rather than calling a resolver on every read row, the privacy decision is precomputed into the index/mirror at write time, and the live read-path gates (feed posts, profile view) call the canonical `PrivacyService` methods. Most-restrictive-wins is implemented once, correctly, in `ProfileService`.

---

## Per-surface enforcement matrix

| Surface | View/relationship gate | Per-post privacy | Block | Mute | Suspended/shadow-ban |
|---|---|---|---|---|---|
| Profile (`get_profile`) | most-restrictive group/field/entry | n/a | via `can_view_profile` path / cache key | n/a | suspended → minimal profile |
| Profile activity (`profile_feed`) | `can_view_activity` | `privacy IN(...)` by relationship | yes (inside `can_view_activity`) | n/a (own profile feed) | `excluded_users_where` |
| **Home feed (`home_feed_uncached`)** | follow/connection source blend | `privacy IN(...)` | **NO** | **NO** | `excluded_users_where` |
| **Explore feed (`explore_feed`)** | public only | `privacy = 'public'` | **NO** | **NO** (n/a) | `excluded_users_where` |
| Directory (`list_members`) | mirror = public only | n/a | yes, bidirectional (`:168-181`) | n/a | suspended + shadow-ban filtered |
| Search (`SearchService::search`) | `visibility='public'` | n/a | yes, bidirectional (`block_where`, `:182-196`) | n/a | `excluded_where` |

---

## First break

`includes/Feed/FeedService.php:91-101` — `excluded_users_where()` is the only author-exclusion clause applied by `home_feed_uncached()` (`:156,216`) and `explore_feed()` (`:767`). It excludes **suspended** and **shadow-banned** users only. It does NOT exclude users the viewer has **blocked** or **muted**.

The locked social-graph spec is explicit (`docs/specs/features/01-social-graph.md:29-30`):
- Block: "hard stop — can't see your content ... **gone from your feed**"
- Mute: "still connected/following but **invisible in feed**, they never know"

So a blocked or muted author's public/followers posts can surface in the blocker's/muter's for-you, following, network, and explore feeds. Every other surface that should honour blocks (directory, search, profile activity) does so via `BlockService`; the home/explore feed is the lone path that skips it. This is a genuine, code-proven inconsistency in uniform application of the visibility contract — the home feed is the single most-visited surface and the one the spec calls out by name for block/mute suppression.

`PrivacyService` already injects `BlockService`, and `BlockService` exposes `blocked_users()` and `muted_users()` — the data needed to extend `excluded_users_where()` (or add a viewer-scoped block/mute clause) already exists.

---

## Notes / non-breaks (do not "fix")

- Absence of a class called `VisibilityResolver` is not a break. The most-restrictive-wins logic is centralised in `ProfileService`; the social-graph view gate is centralised in `PrivacyService`. Don't introduce a new resolver abstraction for its own sake.
- Search/directory not calling a per-row resolver is intentional and correct (write-time `visibility` stamping + public-only mirror). Leave as-is.
- `member_banned`/space-ban paths in `PermissionService` are the permission contract (17-roles), not the visibility contract; out of scope here.
- Mute is feed-only by spec, so its absence from search/directory is correct; its absence from the home feed is the break.

---

## Minimal refactor plan

1. Add a viewer-scoped block+mute exclusion to the home feed and explore feed. Either extend `excluded_users_where()` to take an optional `$viewer_id` and append `AND user_id NOT IN (blocked_or_muted_of_viewer)`, or add a dedicated `viewer_block_mute_where( $viewer_id )` clause mirroring `SearchService`'s `block_where` (bidirectional block + one-directional mute). Apply it in `home_feed_uncached()` and `explore_feed()`.
2. Reuse `BlockService::blocked_users()` / `muted_users()` (or a subquery against `bn_blocks` filtered by `type IN ('block','mute')` for the viewer) — no new data model needed.
3. Re-walk: as viewer A who blocked B and muted C, confirm B's and C's public posts no longer appear in for-you/following/network/explore; confirm A still sees them is impossible (gone), and that B/C are unaffected for everyone else.
</content>
</invoke>
