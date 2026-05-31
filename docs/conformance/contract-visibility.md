# Contract Conformance — Visibility & Privacy Resolver

**Contract:** One visibility/privacy resolver, most-restrictive-wins, used uniformly by feed, profile, directory, search.
**Spec refs:** `docs/specs/features/17-roles-permissions.md` (capability gate — distinct from visibility), `docs/specs/features/01-social-graph.md` (block/mute feed suppression, visibility levels), member-fields searchable-mirror contract.
**Verdict:** usable-leave-as-is
**Date:** 2026-05-31

---

## Shape of the contract in code

There is no single class literally named `VisibilityResolver`, and that is fine —
"one resolver" is satisfied by each visibility decision routing through a
canonical method/contract rather than being re-derived ad hoc. The contract is
realised by a small set of cooperating pieces:

- **Social-graph view gate** — `SocialGraph/PrivacyService`
  (`can_view_profile`, `can_view_activity`, `can_follow`, `can_connect`).
  Block-graph first (block = hard deny), then relationship (public / followers /
  connections / private). Documented as the single entry point
  (PrivacyService.php:202-235).
- **Profile-field most-restrictive-wins** — `Profile/ProfileService`.
  `visibility_rank()` (:1292), `clamp_visibility()` (member may only tighten,
  :1311), `effective_visibility()` (MAX of group/field/entry rank, :1337), and
  the non-owner enforcement loop in `get_profile()` (:616-644). Ordering
  private > connections > followers > public.
- **Cross-content search visibility** — `bn_search_index.visibility` column.
  Writers (`SearchIndexListener`) stamp public/private at index time; reader
  (`SearchService::search`) hard-filters `WHERE si.visibility='public'` plus a
  bidirectional block subquery (SearchService.php:182-196,374-429).
- **Directory searchable mirror** — `bn_field_{key}` usermeta written by
  `ProfileService::sync_search_mirror()` ONLY when
  `effective_visibility==='public'` (ProfileService.php:1373-1383).
  `MemberDirectoryService` searches the mirror with a bidirectional block
  NOT-EXISTS and no per-row check, because the mirror only holds public values
  (MemberDirectoryService.php:180-219).

This is a sound architecture: privacy is precomputed into the index/mirror at
write time, and live read-path gates (feed, profile) call canonical
`PrivacyService` methods. Most-restrictive-wins is implemented in `ProfileService`.

---

## Per-surface enforcement matrix

| Surface | Relationship gate | Per-post privacy | Viewer block | Viewer mute | Suspended/shadow-ban |
|---|---|---|---|---|---|
| Profile (`get_profile`) | most-restrictive group/field/entry | n/a | via gate / cache key | n/a | suspended → minimal |
| Profile activity (`profile_feed`) | `can_view_activity` (:577) | `privacy IN(...)` by relationship | yes (in `can_view_activity`) | n/a (single owner) | `excluded_users_where` |
| Home feed (`home_feed_uncached`) | follow/connection source blend | `privacy IN(...)` | **yes** (`viewer_block_mute_where` :207,268,455) | **yes** (same clause) | `excluded_users_where` |
| Explore feed (`explore_feed`) | public only | `privacy='public'` | **yes** (:826,835) | **yes** (same clause) | `excluded_users_where` |
| Space feed (`space_feed`) | caller's responsibility (single space) | published only | n/a (space-scoped) | n/a | `excluded_users_where` |
| Directory (`list_members`) | mirror = public only | n/a | yes, bidirectional (:180-190) | n/a | suspended + shadow-ban filtered |
| Search (`SearchService::search`) | `visibility='public'` | n/a | yes, bidirectional (`block_where`) | n/a | `excluded_where` |

---

## First break

None on the spec-mandated surfaces. The earlier-flagged gap — home/explore feeds
not suppressing viewer-blocked/muted authors — is now closed:
`FeedService::viewer_block_mute_where()` (FeedService.php:103-150) builds a
bidirectional-block + one-directional-mute `NOT IN` clause and is applied in
`home_feed_uncached()` (:207, SQL :268, second branch :455) and `explore_feed()`
(:826, SQL :835). Both honour the `bn_blocks` `type IN ('block','mute')` rules
the social-graph spec calls out, and degrade gracefully when the table is absent.

---

## Notes / non-breaks (do not "fix")

- Absence of a single `VisibilityResolver` class is not a break. Most-restrictive
  logic is centralised in `ProfileService`; the relationship gate in
  `PrivacyService`. Do not add a resolver abstraction for its own sake.
- Search/directory not calling a per-row resolver is intentional and correct
  (write-time `visibility` stamping + public-only mirror).
- `space_feed` and the single-owner `profile_feed` SQL apply only
  `excluded_users_where` (suspend/shadow-ban). That is correct: `profile_feed` is
  already block-gated upstream by `can_view_activity`; `space_feed` is a
  single-space surface whose access is the caller's responsibility and not the
  aggregate home/explore surface the spec names for block/mute.
- The 4-level enum collapses to public/private at the search/directory index
  boundary by design (no per-row checks). Noted so it is not mistaken for a bug.

---

## Minor polish opportunities (non-blocking, not required)

1. `get_profile()` (:616-644) hand-inlines the same most-restrictive-wins rank
   ordering that `effective_visibility()` (:1337-1355) already encodes. Two
   copies of one algorithm can drift; have the read path call the existing
   helper. Cosmetic, not a correctness issue.
2. The block predicate is expressed as three separate SQL fragments
   (`FeedService::viewer_block_mute_where`, `SearchService` `block_where`,
   `MemberDirectoryService` inline NOT EXISTS). Same intent, three copies — a
   shared block-predicate SQL helper would prevent drift. Optional.

No refactor required for usability or privacy correctness.
