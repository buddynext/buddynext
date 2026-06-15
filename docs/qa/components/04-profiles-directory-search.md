# QA — Profiles, Member Directory & Search (free)

**Manifest refs:** tables: `bn_profile_fields` · `bn_profile_groups` · `bn_profile_values` · `bn_search_index` · `bn_member_types` · `bn_member_type_assignments` · REST routes: `/users/{id}/profile` (GET/PUT) · `/me/profile` (GET/PUT) · `/me/avatar` (POST/DELETE) · `/me/cover` (POST/DELETE) · `/users/{id}/avatar` (POST/DELETE) · `/users/{id}/cover` (POST/DELETE) · `/me/profile-slug` (GET/PUT) · `/profile-slug/check` (GET) · `/profile-fields` (GET/POST) · `/profile-fields/{id}` (PUT/DELETE) · `/profile-fields/{id}/reorder` (POST) · `/profile-groups` (GET/POST) · `/profile-groups/{id}` (PUT/DELETE/reorder) · `/members` (GET) · `/member-types` (GET/POST) · `/member-types/{slug}` (PUT/DELETE) · `/users/{id}/member-type` (GET/PUT/DELETE) · `/search` (GET) · `/search/members` (GET) · services: ProfileService · MemberDirectoryService · MemberTypeService · SearchService · AvatarService · capabilities: `manage_options` (admin field/type CRUD) · `buddynext-view-profile` · authenticated user for self-edits
**Cross-ref (no dup):** JOURNEYS J-24 (directory browse) · J-25 (filter by member type) · J-26 (directory search) · J-29 (profile view own) · J-33 (edit avatar) · J-34 (edit bio) · J-35 (edit custom fields) · FLOW-TEST-MATRIX M3 (profile edit) · M3b (member-type self-select) · M4 (profile view + gamification) · M13 (member directory browse/filter/search) · M18 (unified search) · O2 (members admin list/search) · O3 (member types admin)
**Admin location:** BuddyNext → Members → Member Types (`admin.php?page=buddynext-members&subtab=types`) · Members → Directory (`admin.php?page=buddynext-members`) · Profile fields managed via REST only (no dedicated admin UI tab)

---

## 1. Backend settings & options (justify each)

No dedicated tab for profiles or search. Relevant options live in the **General** and **Registration** tabs, and one option is outside SETTINGS_MAP.

### General tab (profile-relevant options)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_site_name` | General | `''` | Community name used in profile SEO meta and email headers | Yes | No wiring to `wp_head` meta verified; `buddynext_description` similarly has no confirmed frontend output |
| `buddynext_public_explore` | General | `true` (bool) | Allow guests to view the member directory without login **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably — unchecked state not POSTed (no preceding hidden input); guests always see directory |
| `buddynext_enable_dm` | General | `true` (bool) | Controls whether DM button appears on profile view **[TOGGLE-BUG]** | Yes | Same toggle bug; profile DM button cannot be hidden |

### Registration tab (profile-relevant options)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_reg_mode` | Registration | `'open'` | `open` / `invite-only` / `approval` — determines whether profiles can be created via public registration | Yes | No option to require profile fields to be filled before member can post |
| `buddynext_email_verify` | Registration | `false` (bool) | Toggle: require email verification before profile access **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; also cannot be turned ON reliably from a false default (same bug, opposite direction) |

### Outside SETTINGS_MAP

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_features` | Features tab | `[]` (array) | Array of enabled feature slugs — no profile-specific slug confirmed in this array; member directory and search appear always-on | Questionable | No per-feature toggle for member directory or search — they cannot be disabled without code; expected site-owner control absent |

### Missing must-haves (no option exists)

| Expected option | Gap description |
|---|---|
| Profile field visibility global default | No option to set the network-wide default for `visibility` on new fields (currently defaults to `public` at table level, not configurable in admin) |
| Directory opt-out | No option to let members hide themselves from the directory; `bn_profile_fields.visibility` is per-field but no per-user "hide from directory" flag exists |
| Search index rebuild trigger | No admin UI button to trigger `buddynext_reindex_all` — rebuilding the `bn_search_index` requires direct CLI or code |
| Member type self-select toggle | Whether members can self-assign their type is controlled per-type via usermeta `bn_member_type` write permission (`can_set_user_type` capability), but there is no admin UI toggle; site owners cannot flip this without code |

---

## 2. Frontend functions (function-by-function)

### Profile view

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `ProfileController::get_profile()` | `/members/{slug}/` profile hub | `GET /buddynext/v1/users/{id}/profile` | Returns full profile data including custom field values, avatar URL, cover URL, member type, stats (posts/connections/followers). Permission: `__return_true` (public for public profiles) |
| `ProfileController::get_own_profile()` | `/members/{slug}/` when viewer = subject | `GET /buddynext/v1/me/profile` | Same as above but always returns private fields; requires auth |
| `AvatarService::get_avatar_url()` | Profile hero, post cards, member cards | none (PHP helper) | Returns CDN-resolved URL for uploaded avatar or SVG initials fallback; `data:` protocol whitelisted for SVG |
| `ProfileService::get_field_key()` | Pro AdvancedFieldRenderer, internal | none | Resolves a field key from field ID; added 2026-06-14 to remove direct `bn_profile_fields` queries from Pro |

### Profile edit

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `ProfileController::update_profile()` | `/members/{slug}/?tab=edit` | `PUT /buddynext/v1/me/profile` | Updates `bn_profile_values`; fires `buddynext_index_user` after save to re-index search; requires auth (self only) |
| `ProfileController::upload_avatar()` | Profile edit → avatar section | `POST /buddynext/v1/me/avatar` | Uploads new avatar, stores URL in usermeta `bn_avatar_url`; requires auth |
| `ProfileController::delete_avatar()` | Profile edit → avatar section | `DELETE /buddynext/v1/me/avatar` | Removes avatar, reverts to SVG initials; requires auth |
| `ProfileController::upload_cover()` | Profile edit → cover section | `POST /buddynext/v1/me/cover` | Uploads cover photo; no focal-point adjustment endpoint (documented gap, low priority) |
| `ProfileController::delete_cover()` | Profile edit → cover section | `DELETE /buddynext/v1/me/cover` | Removes cover image |
| `ProfileController::get_profile_slug()` / `update_profile_slug()` | Profile edit → username section | `GET/PUT /buddynext/v1/me/profile-slug` | Reads and updates the profile slug; uniqueness enforced |
| `ProfileController::check_slug_availability()` | Profile edit → username live-check | `GET /buddynext/v1/profile-slug/check` | Returns `{available: bool}`; requires auth |

### Admin avatar / cover management

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `ProfileController::admin_upload_avatar()` | Members admin → member edit | `POST /buddynext/v1/users/{id}/avatar` | Admin uploads avatar for any user; requires `manage_options` |
| `ProfileController::admin_update_profile()` | Members admin → member edit | `PUT /buddynext/v1/users/{id}/profile` | Admin edits any member's profile fields; requires `manage_options` |
| `ProfileController::admin_upload_cover()` / `admin_delete_cover()` | Members admin | `POST/DELETE /buddynext/v1/users/{id}/cover` | Admin uploads/removes cover for any user |

### Profile fields CRUD (admin)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `ProfileController::list_fields()` | Public API / onboarding | `GET /buddynext/v1/profile-fields` | Returns all fields with group, type, options; permission `__return_true` |
| `ProfileController::create_field()` | No admin UI (REST only) | `POST /buddynext/v1/profile-fields` | Creates field in `bn_profile_fields`; requires `manage_options` |
| `ProfileController::update_field()` / `delete_field()` | No admin UI (REST only) | `PUT/DELETE /buddynext/v1/profile-fields/{id}` | Update or remove a field; requires `manage_options` |
| `ProfileController::reorder_field()` | No admin UI (REST only) | `POST /buddynext/v1/profile-fields/{id}/reorder` | Updates `sort_order`; requires `manage_options` |
| `ProfileController::list_groups()` / `create_group()` etc. | No admin UI (REST only) | `GET/POST /buddynext/v1/profile-groups` | Manages field groups; requires `manage_options` for writes |

### Member directory

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `MemberDirectoryService::list()` | `/members/` directory hub | `GET /buddynext/v1/members` | Cursor-paginated (max 50/page, default 20); filters: `search`, `location`, `member_type`, `space_id`, `connection_status` (`everyone`/`connections`), `online_only` (bool); sort: `newest`/`alphabetical`/`most_active`/`online`; 60-second object cache; excludes suspended + shadow-banned users; hook: `buddynext_member_directory_query_args` |
| `MemberDirectoryController::list_members()` | `/members/` hub | same as above | Permission `__return_true`; guests can see public directory if `buddynext_public_explore` is on |

### Member types

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `MemberTypeService::get_all()` | Directory filter chips, type badges | `GET /buddynext/v1/member-types` | Returns all types; cached at `bn_member_types_all` (TTL 3600s); permission `__return_true` |
| `MemberTypeService::get_all_with_counts()` | Admin member-types list | none (admin render) | Includes member count per type via `bn_member_type_assignments`; uses `bn_member_type_count_{type_id}` cache |
| `MemberTypeController::create_type()` / `update_type()` / `delete_type()` | Admin member-types manager | `POST /buddynext/v1/member-types` · `PUT/DELETE /buddynext/v1/member-types/{slug}` | Fires `buddynext_member_type_created` / `buddynext_member_type_deleted`; requires `manage_options` |
| `MemberTypeController::set_user_type()` | Profile edit → member type selector | `PUT /buddynext/v1/users/{id}/member-type` | Fires `buddynext_member_type_assigned` (3 args: user_id, new_slug, old_slug); permission `can_set_user_type`; Free enforces one type per user |
| `MemberTypeController::remove_user_type()` | Admin only | `DELETE /buddynext/v1/users/{id}/member-type` | Fires `buddynext_member_type_removed`; requires `manage_options` |

### Search

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| `SearchController::search()` | `/search/` results hub | `GET /buddynext/v1/search` | Args: `q` (required), `type` (optional filter), `per_page` (default 20, max implied by MAX_RESULTS=1000), `page`; FULLTEXT MATCH…AGAINST on `bn_search_index(title, content)`; falls back to LIKE in test environments; excludes blocked users (bidirectional); excludes suspended/shadow-banned; result capped at 1000 (SCALE-CONTRACT §1) |
| `SearchController::list_members()` | Search results → members tab | `GET /buddynext/v1/search/members` | Members-only search scoped to `bn_search_index` WHERE `object_type='user'`; same block/suspension exclusion |
| `SearchIndexListener` | Background hook | none (WP hook) | Listens on `buddynext_index_user` to re-index a user's profile into `bn_search_index`; batch reindex on `buddynext_reindex_all` (100 items/batch, covers posts + users + spaces) |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-PROF-001 | member (self) | frontend | Logged in as member1; profile fields seeded (bio, location, headline) | Visit `http://buddynext.local/members/member1/?autologin=member1`; observe hero card, stats row, post tab | Hero shows avatar (or initials), display name, headline, bio; stats row shows follower/connection/post counts; Posts tab loads feed | 1440px, 768px, 390px |
| QA-PROF-002 | member (viewer) | frontend | member2 logged in; member1 profile has public bio | Visit `/members/member1/?autologin=member2` | Profile renders; Follow + Connect buttons visible; DM button visible (if DM enabled); no Edit button for member2 on member1's profile | 1440px, 390px |
| QA-PROF-003 | member (self) | api | Logged in as member1 | `GET /wp-json/buddynext/v1/me/profile` with auth cookie | Returns JSON with `custom_fields` array; field keys match `bn_profile_fields.field_key`; field values from `bn_profile_values`; visibility respected per field setting | — |
| QA-PROF-004 | member (self) | frontend | member1 on profile edit page | Visit `/members/member1/?tab=edit&autologin=member1`; change bio field; click Save | `PUT /buddynext/v1/me/profile` fires; success response; reload profile — bio updated; `buddynext_index_user` action fires (verify via `wp action-scheduler list` or check `bn_search_index`) | 1440px, 390px |
| QA-PROF-005 | member (self) | frontend | member1 on profile edit page | Upload a 1MB JPEG as avatar; verify preview updates | Avatar stored; `bn_avatar_url` usermeta set; profile view shows new avatar; member card in directory shows new avatar; initials fallback gone | 1440px, 390px |
| QA-PROF-006 | member (self) | api | member1 has uploaded avatar | `DELETE /wp-json/buddynext/v1/me/avatar` with auth | 200 response; profile avatar reverts to SVG initials; `bn_avatar_url` usermeta removed or empty | — |
| QA-PROF-007 | member (self) | frontend | member1 on edit page, username section | Type slug `member1-new`; live-check fires; change another field; Save | `GET /profile-slug/check?slug=member1-new` → `{available: true}`; after Save, profile URL changes to `/members/member1-new/`; old URL 404s | 1440px |
| QA-PROF-008 | member (self) | frontend | Profile edit page, member types configured | Open member type selector; select `designer` | `PUT /buddynext/v1/users/{id}/member-type` fires; `bn_member_type` usermeta updated to `designer`; `bn_member_type_assignments` row updated; badge on profile and member card shows `Designer` | 1440px, 390px |
| QA-PROF-009 | guest | frontend | `buddynext_public_explore` = true (default) | Visit `/members/` without logging in | Directory grid renders; member cards visible; no login prompt; filter chips visible | 1440px, 390px |
| QA-PROF-010 | guest | frontend | `buddynext_public_explore` = false (if toggle bug is fixed) | Visit `/members/` without logging in | Redirect to auth page or login prompt; no member data exposed | 1440px |
| QA-PROF-011 | member | frontend | Directory at `/members/`; 6 members seeded with types | Click `Designer` member-type filter chip | Directory re-fetches `GET /members?member_type=designer`; only designer-type members shown; chip shows active state | 1440px, 390px |
| QA-PROF-012 | member | frontend | Directory loaded | Type `varun` in directory search input | Directory re-fetches with `search=varun`; results update; empty state shown if no match | 1440px, 390px |
| QA-PROF-013 | member | frontend | Directory loaded | Click sort dropdown; select `Alphabetical` | Directory re-fetches with `sort=alphabetical`; cards reorder A-Z by display name | 1440px |
| QA-PROF-014 | member | frontend | Directory with 50+ members (or paginated set) | Scroll to bottom or click Next page | Cursor-based pagination fires next `GET /members` with cursor arg; next batch loads; no duplicate members | 1440px, 390px |
| QA-PROF-015 | member | api | member1 suspended via admin | `GET /wp-json/buddynext/v1/members` | member1 not in results; suspension exclusion applied at query level in `MemberDirectoryService` | — |
| QA-PROF-016 | member | api | member2 has blocked member1; member1 is logged in | `GET /wp-json/buddynext/v1/members` (member1 auth) | member2 not in results for member1 (bidirectional block exclusion); member2 sees member1 excluded too | — |
| QA-PROF-017 | member | frontend | Search hub at `/search/`; `bn_search_index` has entries | Type `design` in search input | `GET /buddynext/v1/search?q=design` fires; results across members + posts + spaces returned; tabs show counts; empty state if no match | 1440px, 390px |
| QA-PROF-018 | member | api | `bn_search_index` populated | `GET /wp-json/buddynext/v1/search?q=a` (matches many) with `per_page=1100` | Response capped at 1000 results (MAX_RESULTS = 1000 per SCALE-CONTRACT §1); no unbounded result set | — |
| QA-PROF-019 | admin | backend | Admin Members directory | Visit `admin.php?page=buddynext-members?autologin=1`; search for `member1`; filter by type | Members list filters correctly; 20/page; pagination works; member row shows username/email/registered/role | 1440px, 390px |
| QA-PROF-020 | admin | backend | Member Types manager | Visit `admin.php?page=buddynext-members&subtab=types?autologin=1`; create type `Founder` with slug `founder` | `POST /buddynext/v1/member-types` fires; type appears in list with count=0; `buddynext_member_type_created` hook fires; cache `bn_member_types_all` busted | 1440px, 390px |
| QA-PROF-021 | admin | backend | `Founder` type exists | Delete `Founder` type from member-types manager | `DELETE /buddynext/v1/member-types/founder` fires; type removed from list; `bn_member_type_assignments` rows for that type cleaned up; `buddynext_member_type_deleted` hook fires; affected users' `bn_member_type` usermeta cleared | 1440px |
| QA-PROF-022 | admin | api | Fresh install | `POST /wp-json/buddynext/v1/profile-fields` with auth (admin) body `{field_key: "linkedin", label: "LinkedIn", type: "text", group_id: 2}` | 201 response; row inserted in `bn_profile_fields`; field appears in `GET /profile-fields`; visible in profile edit form | — |
| QA-PROF-023 | admin | api | Field `linkedin` exists | `PUT /wp-json/buddynext/v1/profile-fields/{id}` with `{label: "LinkedIn URL", is_searchable: true}` | Field label updated in `bn_profile_fields`; `is_searchable=1`; next user save re-indexes via `buddynext_index_user` | — |
| QA-PROF-024 | member (self) | frontend | Profile field `visibility` for bio = `connections` | Visit member1's profile as member2 (not a connection) | Bio field hidden; connection-restricted fields not rendered for non-connections | 1440px |
| QA-PROF-025 | member | frontend | Profile with repeater group `work_experience` | On profile edit, click Add entry in Work Experience; fill company + title; Save | New row in `bn_profile_values` with `entry_index = next`; profile view shows new work entry | 1440px, 390px |
| QA-PROF-026 | admin | backend | member1 has avatar | Visit member edit at `admin.php?page=buddynext-members&action=edit&user_id=X`; upload avatar via admin | `POST /buddynext/v1/users/{id}/avatar` (admin route); member1's avatar updated site-wide | 1440px |
| QA-PROF-027 | subscriber | api | Logged in as subscriber (no manage_options) | `POST /wp-json/buddynext/v1/profile-fields` | 403 Forbidden; no field created; capability gate holds | — |
| QA-PROF-028 | member | frontend | Directory on mobile | Visit `/members/?autologin=1` at 390px viewport | Grid collapses to single-column or 2-column; filter chips scroll horizontally or stack; no horizontal overflow; search input full-width; member cards readable | 390px |
| QA-PROF-029 | member | api | member1 profile updated (bio changed) | After `PUT /me/profile` completes | `bn_search_index` row for member1 updated (title = display name, content includes new bio); verify `SELECT * FROM bn_search_index WHERE object_type='user' AND object_id={member1_id}` | — |
| QA-PROF-030 | member | frontend | Dark mode active (`[data-theme="dark"]`) | Visit `/members/` and `/members/{slug}/` in dark mode | Member cards, profile hero, directory grid, filter chips all use `--bg`, `--text-1`, `--border` tokens; no hardcoded white or dark hex values visible | 1440px, 390px |

---

## 4. Site-owner expectations & suggestions

- **No admin UI for profile field management.** Profile fields and groups are REST-only (no admin panel). A site owner cannot add a custom field (e.g. "Company", "Job Title") without using a REST client or code. A drag-and-drop field builder in the Members admin is a standard expectation. Priority: high.

- **Member directory opt-out missing.** There is no per-user flag to hide a profile from the `/members/` directory. Members who want privacy cannot opt out without admin intervention. Expected: a "Hide my profile from directory" toggle in member settings. Priority: high.

- **Search index rebuild has no admin trigger.** If `bn_search_index` gets out of sync (after plugin updates, bulk user changes, or data migration), the only way to rebuild it is via `do_action('buddynext_reindex_all')` in code or WP-CLI. A one-click "Rebuild Search Index" button in Settings or Members admin is expected. Priority: medium.

- **Member type self-select has no admin toggle.** Whether members can self-assign their own type (via `can_set_user_type` capability) is not configurable in the UI. Site owners who want types to be admin-assigned only (e.g. a credentialing community) have no control. Priority: medium.

- **Profile fields have no admin visibility configuration.** The `visibility` column on `bn_profile_fields` (`public/followers/connections/private`) has no admin UI surface. Site owners cannot see or change which fields are visible to whom without REST calls or direct DB edits. Priority: medium.

- **`buddynext_public_explore` toggle is broken (TOGGLE-BUG).** Unchecking it does not disable guest directory access because unchecked checkboxes are not POSTed. The entire member directory is always guest-visible, regardless of setting. Priority: critical (same root cause as all toggle bugs documented in component 11).

- **No group-based profile field sections on public profile.** Five system groups (`basic_info`, `social_links`, `work_experience`, `education`, `skills`) are defined but there is no site-owner control over which groups are shown on the public profile tab vs. a separate "Details" tab. Priority: low.

- **Search does not expose `is_searchable` field filtering.** Fields with `is_searchable=1` write to usermeta mirrors (`bn_field_location`, `bn_field_interests`) for `WP_User_Query` matching, but the `/members` directory `search` parameter only searches these mirrored keys. Full-text search of the `bn_search_index` is separate. The two search paths are not unified for the directory. Priority: low.
