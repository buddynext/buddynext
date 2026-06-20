# Journey: Member Directory

**Free feature**: `includes/Profile/MemberDirectoryService`, `includes/MemberTypes/MemberTypeService`, `includes/MemberTypes/MemberTypeController`
**Actions / filters fired**: `buddynext_member_type_created`, `buddynext_member_type_assigned`, `buddynext_member_type_removed`
**DB tables touched**: `bn_member_types`, `bn_member_type_assignments`, `bn_blocks`, `bn_user_suspensions`
**Estimated time**: 8 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: at least 5 users including `member1` and `member2`; admin user exists
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Admin creates member types

1. Log in as admin. Create a `developer` member type:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/member-types \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{
       "slug": "developer",
       "name": "Developer",
       "description": "Community members who are software developers",
       "color": "#0073aa",
       "text_color": "#ffffff",
       "sort_order": 1,
       "show_in_dir": true,
       "self_select": false
     }'
   ```

   - Expected: 201. Note the returned `id` (referred to as `DEV_TYPE_ID`).

2. Verify the member type row:

   ```sql
   SELECT id, slug, name, color, show_in_dir, self_select, sort_order
   FROM wp_bn_member_types
   WHERE slug = 'developer';
   ```

   - Expected: 1 row with correct values.

3. Create a second member type (`designer`):

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/member-types \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{
       "slug": "designer",
       "name": "Designer",
       "color": "#8b5cf6",
       "text_color": "#ffffff",
       "sort_order": 2,
       "show_in_dir": true,
       "self_select": true
     }'
   ```

   - Expected: 201. Note `DESIGNER_TYPE_ID`.

### Part 2: Admin assigns member types

4. Assign `developer` type to `member1`:

   ```bash
   wp user get member1 --field=ID
   # Use MEMBER1_ID below.

   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/member-type \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{"type_id": DEV_TYPE_ID}'
   ```

   - Expected: 200. Row inserted into `wp_bn_member_type_assignments`.

5. Verify the assignment:

   ```sql
   SELECT id, user_id, type_id, assigned_by, assigned_at
   FROM wp_bn_member_type_assignments
   WHERE user_id = MEMBER1_ID;
   ```

   - Expected: 1 row, `type_id = DEV_TYPE_ID`.

6. Assign `designer` type to `member2`:

   ```bash
   wp user get member2 --field=ID
   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/member-type \
     -u admin:password \
     -H "Content-Type: application/json" \
     -d '{"type_id": DESIGNER_TYPE_ID}'
   ```

### Part 3: Directory listing and filter by member_type

7. List all members (public directory):

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/member-types
   ```

   - Expected: 200, array including `developer` and `designer` types.

8. Filter directory by member type (admin members list):

   ```bash
   curl -s "http://buddynext-dev.local/wp-admin/admin.php?page=buddynext-members&member_type=developer" \
     --cookie "$(wp user session get admin | tail -1)"
   ```

   Alternatively, use WP-CLI to confirm MemberDirectoryService filters correctly:

   ```bash
   wp eval "
   \$svc = buddynext_service('member_directory');
   \$results = \$svc->list(['member_type' => 'developer', 'per_page' => 20, 'page' => 1]);
   echo count(\$results['users']) . ' developer(s) found.\n';
   "
   ```

   - Expected: only member1 (or any user assigned `developer` type) in results.

### Part 4: Search by name

9. Search for member1 by display name:

   ```bash
   curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=member1&type=users" \
     -u member1:password
   ```

   - Expected: 200. member1 appears in search results.

### Part 5: Viewer-aware exclusions for blocked and shadow-banned users

10. As `member1`, block `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/block \
      -u member1:password -H "Content-Type: application/json"
    ```

11. As `member1`, list members via the search/directory. Confirm `member2` does not appear in the results:

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=member&type=users" \
      -u member1:password
    ```

    - Expected: `member2` excluded from the list (viewer-aware block exclusion).

12. Shadow-ban `member2` as admin:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/shadow-ban \
      -u admin:password -H "Content-Type: application/json"
    ```

13. As an anonymous viewer (no credentials), search for `member2`:

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=member2&type=users"
    ```

    - Expected: shadow-banned `member2` does not appear in results for unauthenticated viewers.

### Part 6: Pagination

14. Confirm pagination works on the member list:

    ```bash
    curl -s "http://buddynext-dev.local/wp-json/buddynext/v1/search?q=&type=users&per_page=2&page=1"
    ```

    - Expected: 200 with at most 2 users. Response headers or body include total count.

## Edge cases to also verify

- **Self-select member type**: `designer` has `self_select = true`. As `member2`, attempt to assign themselves a type — confirm the permission gate allows it. `developer` has `self_select = false` — confirm a non-admin member cannot assign it to themselves.
- **Duplicate assignment**: Assign `developer` type to `member1` a second time. Expected: no duplicate row (UNIQUE KEY `uq_user_type` on `bn_member_type_assignments`).
- **Delete member type**: Delete the `developer` type as admin. Expected: row removed from `bn_member_types`. Assignment rows in `bn_member_type_assignments` become orphaned (no CASCADE — confirm the orphan behavior is acceptable or flag for cleanup).
- **Blocked user cannot see blocker**: As `member2` (blocked by member1), search for `member1`. Expected: `member1` excluded from member2's search results.

## What this validates

- `MemberTypeService::create()` inserts into `bn_member_types`.
- `MemberTypeService::assign()` inserts into `bn_member_type_assignments` and fires `buddynext_member_type_assigned`.
- `MemberDirectoryService::list()` applies member_type filter and enforces viewer-aware block/shadow-ban exclusions.
- Shadow-ban exclusions in search and directory depend on `bn_user_suspensions` / usermeta state set by `ModerationService`.
- `MemberTypeController` endpoints require `manage_options` for create/update/delete and `is_user_logged_in` for assign.

## Verification queries

```sql
-- All member types:
SELECT id, slug, name, show_in_dir, self_select, sort_order
FROM wp_bn_member_types
ORDER BY sort_order;

-- All assignments for the journey:
SELECT mta.user_id, mt.slug, mta.assigned_by, mta.assigned_at
FROM wp_bn_member_type_assignments mta
INNER JOIN wp_bn_member_types mt ON mt.id = mta.type_id
WHERE mta.type_id IN (DEV_TYPE_ID, DESIGNER_TYPE_ID);

-- Block rows from this journey:
SELECT blocker_id, blocked_id, type
FROM wp_bn_blocks
WHERE blocker_id = MEMBER1_ID AND blocked_id = MEMBER2_ID;
```

## REST surface walked

```
GET    /buddynext/v1/member-types                    -- 200, all member types (public)
POST   /buddynext/v1/member-types                    -- 201, new type (admin)
PUT    /buddynext/v1/member-types/{slug}             -- 200, updated type (admin)
DELETE /buddynext/v1/member-types/{slug}             -- 200, { "deleted": true } (admin)
PUT    /buddynext/v1/users/{id}/member-type          -- 200, type assigned (admin)
DELETE /buddynext/v1/users/{id}/member-type          -- 200, type removed (admin)
GET    /buddynext/v1/members                          -- 200, THE directory list/search/filter (the route the UI actually calls)
GET    /buddynext/v1/search/members                   -- 200, viewer-aware member search (secondary)
```

> **Correction (verified live 2026-06-20):** the directory UI calls **`GET /members?...`** (`assets/js/members/store.js:608`), NOT `/search?type=users`. The old journey walked the wrong (shadow) endpoint, so the dedicated `MemberDirectoryController` was never actually tested. Walk `/members` this run.
> `curl -s "http://buddynext.local/wp-json/buddynext/v1/members?per_page=2" -b /tmp/bn.txt | python3 -m json.tool | head`

## Frontend action wiring

*(Item 11. The directory is a high-traffic browse surface — live search, filter, follow-from-card.)*

| Control | Template (file) | JS store / action | Live route + method | Nonce |
|---|---|---|---|---|
| Live search (debounced) | `templates/directory/members.php` | `buddynext/members` (`members/store.js:608`) | `GET /members?search=` | `ctx.restNonce` |
| Filter by member-type / sort | `templates/directory/members.php` filter bar | `members/store.js` setFilter | `GET /members?type=&orderby=` | `ctx.restNonce` |
| Load more / pagination | `templates/directory/members.php` | `members/store.js` | `GET /members?page=` | `ctx.restNonce` |
| Follow / unfollow from card | `templates/blocks/member-card.php` | `members/store.js:444` | `POST/DELETE /users/{id}/follow` | `cfg.restNonce` |
| Connect / accept / decline from card | `templates/blocks/member-card.php` | `members/store.js:471,507` | `POST /users/{id}/connect[/accept|/decline]` | `cfg.restNonce` |

**Verify this run (incl. SCALE — the 2000-row baseline gap):**
1. `GET /members?per_page=20&page=1` returns 20 + `X-WP-Total` header; page 2 differs. (Seed 500+ users first if the site is small — `wp user generate --count=500`.)
2. Type in the search box → confirm `GET /members?search=` fires and narrows results.
3. Filter by a member-type → confirm only that type returns.

## Admin-config → member-effect

*(Item 12.)*

- **Member types:** admin creates a type and assigns it to `bob` (`PUT /users/{id}/member-type`); confirm the directory filter-by-type surfaces `bob` and the type badge renders on his card.
- **Block exclusion (relationship-driven):** `alice` blocks `bob`; confirm `bob` is excluded from `alice`'s `GET /members` results (viewer-aware). Unblock to restore.
- **Directory columns** (`buddynext_directory_columns`, owner setting): change 2↔3↔4 in admin, confirm the grid column count changes for members.


## Cleanup

```sql
-- Remove type assignments from this journey:
DELETE FROM wp_bn_member_type_assignments
WHERE type_id IN (SELECT id FROM wp_bn_member_types WHERE slug IN ('developer', 'designer'));

-- Remove member types:
DELETE FROM wp_bn_member_types WHERE slug IN ('developer', 'designer');

-- Remove block placed in this journey:
DELETE FROM wp_bn_blocks
WHERE blocker_id = MEMBER1_ID AND blocked_id = MEMBER2_ID;

-- Lift shadow ban on member2:
UPDATE wp_usermeta
SET meta_value = '0'
WHERE user_id = MEMBER2_ID AND meta_key = 'bn_shadow_banned';
```

## Known limitations

- Shadow-ban storage location (usermeta vs `bn_user_suspensions`) should be confirmed against `ModerationService::shadow_ban()` implementation before asserting exact DB state.
- The directory listing endpoint is routed through `SearchController::search()` with `type=users`, not a dedicated `/members` endpoint; pagination follows the search contract.

## Automation notes

- Member type CRUD is fully automatable via admin REST endpoints with basic auth as admin.
- The shadow-ban and block exclusion tests require setting up the state first; run them in order.
- Use `wp eval` to call `MemberDirectoryService::list()` directly for unit-level verification without needing to parse REST paginated responses.
