# Conformance: Member Directory (Free)

## Feature
Member Directory — `/members` listing surface with reactive filters, sort, relation
tabs, member-type filter, online filter, and per-card social actions
(Follow / Connect / Accept-Decline / Mute / Block / Report).

## Spec ref
- Locked spec: `docs/specs/features/04-member-directory-search.md`
- Journey: `docs/journeys/member-directory.md`
- UX intent: `docs/v2 Plans/v2/member-directory.html`
- Cross-cutting: `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`

## Verdict
**usable-leave-as-is** — the directory happy-path is wired end-to-end across UI →
store → REST → service → DB. No usability break was provable by reading the code.

The locked spec also describes a separate **Unified Search** surface (grouped
results across members/spaces/posts via a `bn_search_index` FULLTEXT table). That
is a distinct feature owned by `Search/SearchController` and is out of scope for the
member-directory journey (the journey doc itself routes user search through
`type=users`). Its absence here is not a directory break.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Visit `/members`, page routes to directory template | service | wired | `includes/Core/PageRouter.php:840` returns `directory/members.php`; people route case `:651-658` |
| Members SSR-rendered on first paint (no-JS safe) | ui | wired | `templates/directory/members.php:208-211` (WP_User_Query) → grid part `:591-606`; `templates/parts/member-directory-grid.php:96-174` loops `member-card.php` |
| Directory store module enqueued on this route | store | wired | `includes/Core/AssetService.php:318` maps `@buddynext/members` → `members/store`; `includes/Core/PageRouter.php:657` `$assets->enqueue('members')` |
| Reactive root binds Interactivity store + context | store | wired | `templates/directory/members.php:488-493` (`data-wp-interactive="buddynext/members"`, context JSON `:274-303`) |
| Search input → debounced REST fetch | ui | wired | filter-bar `templates/parts/member-directory-filter-bar.php:158` `data-wp-on--input="actions.handleSearchInput"`; `assets/js/members/store.js:440-450` (250ms debounce → `refresh`) → `:344` `GET /members` |
| Sort / relation / member-type / online filters | store | wired | filter-bar `:140,172,190,201`; store `selectSort/selectRelation/selectMemberType/toggleOnlineOnly` `assets/js/members/store.js:452-511`; query built `:71-80` |
| REST `GET /members` registered (public) | rest | wired | `includes/REST/Router.php:75`; `includes/Profile/MemberDirectoryController.php:48-98` |
| Controller → directory service list | rest | wired | `includes/Profile/MemberDirectoryController.php:144-146` `buddynext_service('member_directory')->list_members(...)` |
| Keyset cursor pagination + 60s result cache | service | wired | `includes/Profile/MemberDirectoryService.php:54-80` cache, `:238-273` per-sort cursor, `:297-312` LIMIT+1 |
| Viewer-aware exclusions (suspended / shadow-ban / block / dir opt-out) | db | wired | `MemberDirectoryService.php:149-191` NOT EXISTS clauses (suspensions, `bn_shadow_banned`, `bn_privacy_show_in_directory`, bidirectional `bn_blocks`) |
| Member-type filter via write-through usermeta | db | wired | `MemberDirectoryService.php:227-232` (`bn_member_type` usermeta EXISTS) |
| Computed card fields (follower/mutual/online) | db | wired | `MemberDirectoryService.php:89` follower subquery, `:349-387` batched mutual counts, `:400` online via BlockService |
| Per-card Follow / Connect / Accept-Decline | ui | wired | card `templates/parts/member-card.php:269,281,305,312`; store `toggleFollow/toggleConnection/acceptConnection/declineConnection` `assets/js/members/store.js:544-655` (optimistic + rollback + toast) |
| Per-card Mute / Block / Report (shared modals) | rest | wired | card `:349,356,362`; store `toggleMute` `:665`, `openBlockModal` `:718`, `openReportModal` `:764`; modals rendered `templates/directory/members.php:624-636` |
| Pagination control (SSR) | ui | wired | `templates/directory/members.php:608-615` `parts/pagination.php` |
| Loading skeleton / empty / error+retry states | store | wired | template `:528-589`; store `state.showEmpty/gridHidden` + `refresh` error path `assets/js/members/store.js:339-362`, `retry` `:538` |

## First break
none — journey complete.

## UX gaps
None confirmed-in-code at a severity that blocks the journey. Notes for an
optional live walk (not breaks):

- **Online-only + cursor pagination tail** (low, needs-live-verification):
  `online`/`most_active` cursor reads `bn_last_active` from meta cache
  (`MemberDirectoryService.php:574-583`); rows with no `bn_last_active` meta sort as 0
  and could compress at the tail. Functional; worth a skim on a seeded set.
- **Card/list view toggle render** (low, needs-live-verification): spec lists a
  card/list toggle; store handles it via localStorage + CSS class
  (`assets/js/members/store.js:51-60,435-436`) but the toggle control lives in the
  hero part — confirm it renders. Cosmetic, not a journey break.

## Minimal refactor plan
EMPTY — usable-leave-as-is. Do not rewrite working code.

## Live-walk URL
http://buddynext-dev.local/members
