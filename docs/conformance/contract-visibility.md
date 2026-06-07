# Contract conformance — Visibility & privacy resolver

**Contract:** ONE visibility resolver (most-restrictive-wins) used uniformly by feed, profile, directory, search.
**Spec checked against:** docs/specs/features/17-roles-permissions.md. The spec locks buddynext_can() as the single capability gate and a per-field visibility model (buddynext-profile/view is "public, privacy model applies"); it does not define a single named content-visibility resolver function, so "ONE resolver used uniformly" is read against the de-facto authority SocialGraph\PrivacyService.
**Verdict:** partial-needs-wiring (not broken — every path is privacy-correct; the gap is duplication, not a leak).

---

## What exists

Visibility is enforced through three correct-but-separate mechanisms, each at the right layer:

| Concern | Authority | Most-restrictive-wins? |
|---|---|---|
| Account-level access (who may see a profile / a user's activity) | SocialGraph\PrivacyService::can_view_profile() / ::can_view_activity() | Yes — block first, then private-account follower gate |
| Profile field/group/entry visibility | Profile\ProfileService::effective_visibility() + clamp_visibility() + visibility_rank() | Yes — explicit private > connections > followers > public rank, max() wins; member may only tighten |
| Directory / search indexability | public-only search mirror (bn_field_{key} usermeta, written only when effective_visibility === 'public') + SearchService index visibility column | By construction — non-public never enters the searchable surface |

PrivacyService is the closest thing to the contract's "one resolver," and its docblock explicitly claims that role: "Callers (FeedService audience build, profile activity tab, search index visibility) should consult this method instead of duplicating the rules."

## Where it is consumed correctly

- Feed\FeedService::profile_feed() gates on buddynext_service('privacy')->can_view_activity() (FeedService.php:638) before returning rows — the canonical resolver IS used here.
- Profile\ProfileService builds the search mirror only for public-resolved values (ProfileService.php:1388-1399), so MemberDirectoryService and SearchService inherit privacy safety without per-row checks.

## Where the contract diverges (the gap)

The block/visibility rule is re-expressed in hand-written SQL three times, none routed through PrivacyService:

1. FeedService::viewer_block_mute_where() (FeedService.php:123-150) — bn_blocks type IN ('block','mute') forward + type='block' reverse.
2. MemberDirectoryService block clause (MemberDirectoryService.php:178-191) — type='block' bidirectional only.
3. SearchService block clause (SearchService.php:185-197) — type='block' bidirectional + type='restrict' forward.

Feed list building, the home/explore/space privacy IN (...) source clauses (FeedService.php:348-406), and the inline follower rule in profile_feed() (FeedService.php:676-684) also re-state the public/followers/connections logic PrivacyService encodes, rather than sharing it.

### Severity is medium, not critical, because:
- The three block subqueries differ for a real reason: mute is feed-only soft-hide, restrict is a search-surface limit, block is the bidirectional hard stop (confirmed against BlockService — three distinct types exist). The divergence is mostly semantic, not accidental drift.
- A per-row PHP resolver cannot run over a paginated feed/search/directory query; SQL-set filtering is the correct performance pattern. The contract's "one resolver" ideal is in tension with the SCALE contract here.
- No path was found that LEAKS non-public content. Each surface is privacy-correct on its own.

### The residual risk
Drift. There is no shared canonical block-SQL builder, so a future change to block semantics must be hand-applied to three call sites and will silently diverge if one is missed. The three clauses are already non-identical today.

## Recommendation (minimal, no rewrite)

Extract ONE block-exclusion SQL fragment builder (e.g. PrivacyService::block_where_sql( $viewer_id, $surface ) returning [sql, params] keyed by surface so mute/restrict inclusion stays explicit) and have FeedService, SearchService, MemberDirectoryService call it. Collapses three copies to one source of truth while preserving the intentional per-surface type differences. Do NOT replace the SQL-set approach with a per-row resolver — that would break the scale contract. Profile-field most-restrictive logic and PrivacyService account gates are solid; leave as-is.
