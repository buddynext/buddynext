# Journey: Spaces

**Free feature**: `includes/Spaces/` (SpaceService, SpaceMemberService)
**Actions / filters fired**: `buddynext_space_created`, `buddynext_space_updated`, `buddynext_space_deleted`, `buddynext_space_member_joined`, `buddynext_space_member_left`, `buddynext_space_member_invited`, `buddynext_space_member_removed`, `buddynext_space_join_requested`, `buddynext_space_join_approved`, `buddynext_space_user_banned`, `buddynext_space_user_unbanned`, `buddynext_can_join_space` (filter)
**DB tables touched**: `bn_spaces`, `bn_space_members`, `bn_space_bans`
**Estimated time**: 12 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: no pre-existing test spaces required; this journey creates them
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Create an open space

1. Log in as `member1`. Create an open space:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "name": "Journey Open Space",
       "slug": "journey-open-space",
       "description": "Open space for journey testing",
       "type": "open"
     }'
   ```

   - Expected: 201 response. Note the returned `id` (referred to as `OPEN_SPACE_ID`). The creator is automatically the space `owner`.

2. Verify the space row:

   ```sql
   SELECT id, name, slug, type, owner_id, member_count, created_at
   FROM wp_bn_spaces
   WHERE slug = 'journey-open-space';
   ```

   - Expected: 1 row, `type = open`, `member_count = 1` (owner auto-joins).

3. Verify the owner membership row:

   ```sql
   SELECT space_id, user_id, role, status
   FROM wp_bn_space_members
   WHERE space_id = OPEN_SPACE_ID AND role = 'owner';
   ```

   - Expected: 1 row, `role = owner`, `status = active`.

### Part 2: Join an open space

4. As `member2`, join the open space:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/OPEN_SPACE_ID/join \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 response. Row inserted into `wp_bn_space_members` with `status = active`, `role = member`. `member_count` on the space incremented to 2.

5. Verify the membership:

   ```sql
   SELECT space_id, user_id, role, status, joined_at
   FROM wp_bn_space_members
   WHERE space_id = OPEN_SPACE_ID AND user_id = MEMBER2_ID;
   ```

   - Expected: 1 row, `status = active`.

### Part 3: Create a private space and request-to-join flow

6. As `member1`, create a private space:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "name": "Journey Private Space",
       "slug": "journey-private-space",
       "description": "Private space for journey testing",
       "type": "private"
     }'
   ```

   - Expected: 201. Note `PRIVATE_SPACE_ID`.

7. As `member2`, request to join the private space:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/PRIVATE_SPACE_ID/join \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 or 202. Row inserted into `wp_bn_space_members` with `status = pending`.

8. Verify the pending membership:

   ```sql
   SELECT space_id, user_id, role, status
   FROM wp_bn_space_members
   WHERE space_id = PRIVATE_SPACE_ID AND user_id = MEMBER2_ID;
   ```

   - Expected: `status = pending`.

9. As `member1` (space owner), approve the request:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/PRIVATE_SPACE_ID/approve-request \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{"user_id": MEMBER2_ID}'
   ```

   - Expected: 200. `status` updated to `active` in `wp_bn_space_members`.

### Part 4: Create a secret space and invite flow

10. As `member1`, create a secret space:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces \
      -u member1:password \
      -H "Content-Type: application/json" \
      -d '{
        "name": "Journey Secret Space",
        "slug": "journey-secret-space",
        "description": "Secret space for journey testing",
        "type": "secret"
      }'
    ```

    - Expected: 201. Note `SECRET_SPACE_ID`. Secret spaces do not appear in public space listings.

11. Invite `member2` to the secret space:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/SECRET_SPACE_ID/invite \
      -u member1:password \
      -H "Content-Type: application/json" \
      -d '{"user_id": MEMBER2_ID}'
    ```

    - Expected: 200. Row inserted into `wp_bn_space_members` with `status = invited`.

12. Verify the invite row:

    ```sql
    SELECT space_id, user_id, role, status
    FROM wp_bn_space_members
    WHERE space_id = SECRET_SPACE_ID AND user_id = MEMBER2_ID;
    ```

    - Expected: `status = invited`.

### Part 5: Leave a space

13. As `member2`, leave the open space:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/OPEN_SPACE_ID/leave \
      -u member2:password -H "Content-Type: application/json"
    ```

    - Expected: 200. Row removed from `wp_bn_space_members`. `member_count` decremented.

### Part 6: Ban a member from a space

14. First re-add `member2` to the open space (step 4 above), then as `member1` (owner), ban `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/OPEN_SPACE_ID/ban/MEMBER2_ID \
      -u member1:password \
      -H "Content-Type: application/json" \
      -d '{"reason": "Journey test ban"}'
    ```

    - Expected: 200. Row inserted into `wp_bn_space_bans`. Membership row removed or updated to `status = banned`.

15. Verify the ban row:

    ```sql
    SELECT space_id, user_id, banned_by, reason, created_at
    FROM wp_bn_space_bans
    WHERE space_id = OPEN_SPACE_ID AND user_id = MEMBER2_ID;
    ```

    - Expected: 1 row.

16. Attempt to join as `member2` while banned:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/OPEN_SPACE_ID/join \
      -u member2:password -H "Content-Type: application/json"
    ```

    - Expected: 403.

17. As `member1`, unban `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/spaces/OPEN_SPACE_ID/unban/MEMBER2_ID \
      -u member1:password -H "Content-Type: application/json"
    ```

    - Expected: 200. Row removed from `wp_bn_space_bans`.

## Edge cases to also verify

- **Slug uniqueness**: Attempt to create a second space with `slug = journey-open-space`. Expected: 400 or 422 — slug must be unique per UNIQUE KEY on `bn_spaces.slug`.
- **Non-member join secret space**: As `member2` (not invited), attempt `POST /buddynext/v1/spaces/SECRET_SPACE_ID/join`. Expected: 403.
- **Decline join request**: With member2 in `pending` status on the private space, as member1 call `POST /buddynext/v1/spaces/PRIVATE_SPACE_ID/decline-request` with `{"user_id": MEMBER2_ID}`. Expected: 200. Row removed from `wp_bn_space_members`.
- **Space listing excludes secret spaces**: `GET /buddynext/v1/spaces` (public, unauthenticated). Expected: secret space does not appear in the list.

## What this validates

- `SpaceService::create()` inserts into `bn_spaces` and fires `buddynext_space_created(int $space_id, int $creator_id)`.
- `SpaceMemberService::join()` inserts a `status = active` row for open spaces and a `status = pending` row for private spaces.
- `SpaceMemberService::invite()` inserts a `status = invited` row.
- `SpaceMemberService::approve_request()` updates `status` to `active`.
- `SpaceMemberService::leave()` removes the membership row and decrements `member_count`.
- `SpaceMemberService::ban()` inserts into `bn_space_bans` and fires `buddynext_space_user_banned`.
- `buddynext_can_join_space` filter gate is applied before any join is processed.

## Verification queries

```sql
-- All spaces created in this journey:
SELECT id, name, slug, type, owner_id, member_count
FROM wp_bn_spaces
WHERE slug LIKE 'journey-%';

-- Members of the open space:
SELECT space_id, user_id, role, status, joined_at
FROM wp_bn_space_members
WHERE space_id = OPEN_SPACE_ID;

-- Bans for the open space:
SELECT space_id, user_id, banned_by, reason, created_at
FROM wp_bn_space_bans
WHERE space_id = OPEN_SPACE_ID;

-- Pending requests for the private space:
SELECT space_id, user_id, role, status
FROM wp_bn_space_members
WHERE space_id = PRIVATE_SPACE_ID AND status = 'pending';
```

## REST surface walked

```
POST   /buddynext/v1/spaces                               -- 201, created space object
GET    /buddynext/v1/spaces                               -- 200, paginated space list (public)
GET    /buddynext/v1/spaces/{id}                          -- 200, single space
PUT    /buddynext/v1/spaces/{id}                          -- 200, updated space (owner)
DELETE /buddynext/v1/spaces/{id}                          -- 200, { "deleted": true } (owner/admin)
GET    /buddynext/v1/spaces/{id}/members                  -- 200, member list
GET    /buddynext/v1/spaces/{id}/pending-requests         -- 200, pending join requests (moderator)
POST   /buddynext/v1/spaces/{id}/join                     -- 200/202, join or request
POST   /buddynext/v1/spaces/{id}/leave                    -- 200, leave
POST   /buddynext/v1/spaces/{id}/invite                   -- 200, invite sent
POST   /buddynext/v1/spaces/{id}/approve-request          -- 200, request approved
POST   /buddynext/v1/spaces/{id}/decline-request          -- 200, request declined
POST   /buddynext/v1/spaces/{id}/ban/{user_id}            -- 200, user banned
POST   /buddynext/v1/spaces/{id}/unban/{user_id}          -- 200, user unbanned
GET    /buddynext/v1/me/spaces                            -- 200, spaces current user belongs to
GET    /buddynext/v1/spaces/slug/{slug}                   -- 200, space by slug
```

## Cleanup

```sql
-- Remove test ban rows first:
DELETE FROM wp_bn_space_bans
WHERE space_id IN (SELECT id FROM wp_bn_spaces WHERE slug LIKE 'journey-%');

-- Remove test memberships:
DELETE FROM wp_bn_space_members
WHERE space_id IN (SELECT id FROM wp_bn_spaces WHERE slug LIKE 'journey-%');

-- Remove test spaces:
DELETE FROM wp_bn_spaces WHERE slug LIKE 'journey-%';
```

## Known limitations

- `buddynext_space_join_rejected` action listed in HOOKS.md as `buddynext_space_join_declined` — the `SpaceMemberService` does not currently fire an action on decline; this is flagged as pending in HOOKS.md.
- Secret space invite acceptance flow requires the invited user to explicitly `POST /join` after receiving the invite — there is no auto-accept.

## Automation notes

- All REST calls are curl-automatable with basic auth.
- Space IDs must be collected from CREATE responses; do not hardcode IDs.
- The ban → join-attempt → unban sequence tests the full ban lifecycle in a single script pass.
