# Conformance — Member Directory

**Feature:** Member Directory (free)
**Spec ref:** `docs/specs/features/04-member-directory-search.md` (Locked) + journey `docs/journeys/member-directory.md` + V2 mockup `docs/v2 Plans/v2/member-directory.html`
**Verdict:** usable-leave-as-is
**Live-walk URL:** http://buddynext-dev.local/members

---

## Verdict rationale

The member-directory happy path is wired end-to-end at every layer for the web journey. A site owner can open `/members`, see members rendered server-side on first paint, and then search / sort / filter by member type / filter by relation / toggle online-only without a page reload — all reactive through the `buddynext/members` Interactivity store hitting `GET /buddynext/v1/members`. Inline Follow / Connect (5-state machine) / Accept-Decline / Mute / Block / Report are wired with optimistic UI, REST round-trip, success toast, and rollback-on-failure. Viewer-aware exclusions (suspensions, shadow-ban, bidirectional blocks) are enforced in BOTH the SSR `WP_User_Query` path and the service SQL path. No usability break was provable from the code; default verdict stands.

---

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Open `/members` → resolves to directory template | service (router) | wired | `includes/Core/PageRouter.php:823` (people view, no user_id → `directory/members.php`) |
| Members render on first paint (SSR) | ui | wired | `templates/directory/members.php:208-211` (`WP_User_Query`), grid part `:592-606` |
| Directory store bundle enqueued on this view | ui | wired | `includes/Core/PageRouter.php:640` (`enqueue('members')`); module map `includes/Core/AssetService.php:318` |
| Reactive filter bar bound to store actions | ui→store | wired | `templates/parts/member-directory-filter-bar.php:158,172,190,201,140` (`data-wp-on--input/change/click`) |
| Store issues debounced/instant fetch | store→rest | wired | `assets/js/members/store.js:339-362` (`refresh`), `:71-80` (`buildQuery`) |
| REST route `GET /members` registered | rest | wired | `includes/REST/Router.php:74`; `includes/Profile/MemberDirectoryController.php:48-98` |
| Controller calls directory service | rest→service | wired | `includes/Profile/MemberDirectoryController.php:144-146` (`buddynext_service('member_directory')->list_members()`) |
| Service binding | service | wired | `includes/Core/Plugin.php:640` |
| Paginated/filtered query + viewer exclusions | service→db | wired | `includes/Profile/MemberDirectoryService.php:54-413` (cursor keyset, suspensions `:151`, shadow-ban `:157`, blocks `:168-181`, member_type `:217-222`) |
| Search (name/login/searchable-field mirrors), privacy-safe | service→db | wired | `MemberDirectoryService.php:183-210` (REST/live) + `matching_user_ids() :428-456` (shared by SSR `members.php:153-154`) |
| Sort: newest / alphabetical / most_active / online | service→db | wired | `MemberDirectoryService.php:271-285` |
| Cursor-based pagination | service | wired | `MemberDirectoryService.php:232-263, 398-402, 549-619` |
| Inline Follow / Connect / Accept / Decline / Mute | store→rest | wired | `assets/js/members/store.js:544-682` (optimistic + toast + rollback) |
| Block / Report (shared modals) | store→rest | wired | `store.js:691-817`; modals `templates/directory/members.php:624-636` |
| Member-type pill filter + sidebar counts | ui→store | wired | `templates/directory/members.php:91-110, 404-460`; tabs part wired to `actions.selectMemberType` |

---

## First break

none — journey complete.

---

## UX gaps

No journey-blocking gaps proven in code. Minor / spec-coverage notes (none rise to a usability break):

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| Card view + list view toggle is JS-only (`writeView`/`applyViewClass` operate on `.bn-md-grid`); no toggle control was located in the filter-bar part read here, so the list-view affordance may not be surfaced to the user. | low | needs-live-verification | `assets/js/members/store.js:435-436,51-60`; toggle control not present in `templates/parts/member-directory-filter-bar.php` |
| Unified cross-content grouped search (Members/Spaces/Posts/Discussions/Jobs) and the `bn_search_index` FULLTEXT architecture are part of the same locked spec but are a SEPARATE surface (SearchController); not exercised by the `/members` directory journey. Directory search here is `WP_User_Query`/usermeta-mirror based, which is correct and privacy-safe for the directory surface. | low | needs-live-verification | spec `docs/specs/features/04-member-directory-search.md:33-81`; directory search `MemberDirectoryService.php:183-210` |

Note: the journey doc's "Known limitation" stating there is no dedicated `/members` endpoint and that the directory routes through `SearchController::search()` is OUTDATED — a dedicated `GET /buddynext/v1/members` endpoint exists (`MemberDirectoryController.php:48-98`) and is what the live UI uses.

---

## Minimal refactor plan

(empty — usable-leave-as-is)

---

## App / REST client note

The directory is equally usable for app/REST clients: `GET /buddynext/v1/members` is public (`permission_callback => __return_true`), returns fully shaped card payloads (display_name, handle, avatar, bio_excerpt, profile_url, member_type, follow/connection state, mutual_count, is_online), supports search/sort/relation/member_type/location/online filters and cursor pagination, and applies the same viewer-aware exclusions server-side.
