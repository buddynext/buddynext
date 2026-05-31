# Conformance — Pro Advanced Search Filters

**Feature:** Advanced Search Filters (repo: buddynext-pro)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Member Directory → Filters; Search Architecture seam)
**Cross-cutting:** `REST-FRONTEND-CONTRACT.md`, `SCALE-CONTRACT.md`, `features/17-roles-permissions.md` (visibility), `FREE-PRO-CONTRACT.md` seam #12
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/search

---

## What the feature is

Pro extends Free's unified `/search` member results with five extra member filters injected
through the `buddynext_search_query_args` seam:

- `tier_slug` — active subscription to a membership tier
- `space_id` — active member of a space
- `member_label` — assigned a Pro member label
- `joined_after` — registration date window (query-only, no Pro table)
- `active_within_days` — recent analytics activity

Pro also supplies the tier / space / label option lists for the web filter UI via
`buddynext_search_filter_options`, and saved searches via its own REST collection.

---

## Journey chain (web + REST)

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/search` page routes to results template | (router) | wired | `buddynext/includes/Core/PageRouter.php:808-809` returns `search/results.php` |
| Advanced-filter form rendered in aside (tier/space/label/joined/active) | ui | wired | `buddynext/templates/search/results.php:626-731` (plain GET form, no JS dependency) |
| Tier/space/label option lists populate the dropdowns | ui→service | wired | template applies `buddynext_search_filter_options` `results.php:108-119`; Pro provides it `AdvancedSearchFilters.php:83-150` |
| Pro registers both seams at boot (unconditional) | service | wired | `buddynext-pro/includes/Core/Plugin.php:203` → `AdvancedSearchFilters::register()` (`AdvancedSearchFilters.php:61-64`) |
| Member results routed through canonical SearchService so seam fires on web page | service | wired | `results.php:161-186` calls `buddynext_service('search')->search($q,'user',…)` |
| Pro merges + validates the five args on the seam | service | wired | `AdvancedSearchFilters::apply_pro_args()` `AdvancedSearchFilters.php:184-244` (sources from `$_GET` for the GET page, clamps/validates each) |
| SearchService emits EXISTS sub-queries for each arg (user scope only) | service→db | wired | `buddynext/includes/Search/SearchService.php:274-334` |
| Privacy/visibility enforced (public-only, blocks, suspended/shadow-ban) | db | wired | `SearchService.php:185-208`, `:374`, `:390` visibility='public' |
| REST `GET /search` forwards the five params into the seam (app/REST client) | rest | wired | `buddynext/includes/Search/SearchController.php:66-100` schema + `:203-238` request-scoped closure injector |
| Pro tables referenced by the sub-queries exist | db | wired | Pro `Installer.php` creates all five (`bn_subscriptions`, `bn_membership_tiers`, `bn_member_labels`, `bn_member_label_assignments`, `bn_analytics_events`) |
| Saved searches (save/run/delete) | ui→rest | wired | aside `results.php:740-790` bound to Interactivity store → `buddynext-pro/v1/me/saved-searches` (`SavedSearchController.php`) |

---

## First break

none — journey complete.

Both surfaces are wired: the web `/search` page renders a no-JS GET form whose keys are read
off the seam by Pro, and the REST `GET /search` endpoint documents and forwards the same five
params via a request-scoped closure. SearchService consumes every arg, scoped to `user`/`member`
type only, and only emits Pro-table sub-queries when an arg is present (so Free-only installs
never touch the missing tables). Pro registration is unconditional in `wire_extensions()`, and
Pro's installer creates every referenced table.

---

## UX gaps

None that break the journey. One observation, not a break:

- The advanced-filter card is scoped to the members/`all` tab (`results.php:625-626`), which
  matches spec intent (these are member filters). `joined_after` / `active_within_days` render
  even with Pro inactive (query-only), with an inline hint that tier/space/label require Pro
  (`results.php:715-719`). This is graceful degradation, not a gap.

---

## Minimal refactor plan

Empty — usable, leave as is.

---

## Live-walk note (for the human)

On `http://buddynext-dev.local/search?q=<term>&type=members`, confirm the "Advanced member
filters" card shows tier/space/label dropdowns (needs Pro active + seeded tiers/labels and a
viewer who belongs to ≥1 space — empty test accounts will show only joined/active controls).
Apply a filter, confirm the result set narrows and chosen values reflect back into the controls.
Memory note applies: seed subscription/label/space-membership data before judging the dropdowns
"empty".
