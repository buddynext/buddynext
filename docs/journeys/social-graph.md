# Journey: Social Graph

**Free feature**: `includes/SocialGraph/` (FollowService, ConnectionService, BlockService, PrivacyService)
**Actions / filters fired**: `buddynext_user_followed`, `buddynext_user_unfollowed`, `buddynext_connection_requested`, `buddynext_connection_accepted`, `buddynext_connection_declined`, `buddynext_connection_withdrawn`, `buddynext_block`, `buddynext_unblock`
**DB tables touched**: `bn_follows`, `bn_connections`, `bn_blocks`
**Estimated time**: 10 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: 2 member users (`member1`, `member2`) created and logged in
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Follow / Unfollow

1. Log in as `member1`. Follow `member2` via REST:

   ```bash
   # Get member2's user ID first:
   wp user get member2 --field=ID

   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/follow \
     -u member1:password -H "Content-Type: application/json"
   ```

   Replace `MEMBER2_ID` with the actual ID.
   - Expected: 200 response with `{"following": true}`. Row inserted into `wp_bn_follows`.

2. Verify the follow row in the DB:

   ```sql
   SELECT follower_id, following_id, created_at
   FROM wp_bn_follows
   WHERE following_id = MEMBER2_ID;
   ```

   - Expected: 1 row with `follower_id` = member1's ID.

3. Confirm member2's follower list includes member1:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/followers
   ```

   - Expected: 200, JSON array containing member1's user object.

4. Unfollow member2:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/follow \
     -u member1:password -H "Content-Type: application/json"
   ```

   - Expected: 200 with `{"following": false}`. Row removed from `wp_bn_follows`.

### Part 2: Connection request / accept / decline

5. As `member1`, send a connection request to `member2`:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/connect \
     -u member1:password -H "Content-Type: application/json"
   ```

   - Expected: 201 or 200. Row inserted into `wp_bn_connections` with `status = pending`.

6. Verify the connection row:

   ```sql
   SELECT id, requester_id, recipient_id, status, created_at
   FROM wp_bn_connections
   WHERE requester_id = MEMBER1_ID AND recipient_id = MEMBER2_ID;
   ```

   - Expected: 1 row, `status = pending`.

7. Log in as `member2`. Accept the connection:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/connect/accept \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 response. `status` updated to `accepted` in `wp_bn_connections`.

8. Confirm the connection is accepted:

   ```sql
   SELECT id, status
   FROM wp_bn_connections
   WHERE requester_id = MEMBER1_ID AND recipient_id = MEMBER2_ID;
   ```

   - Expected: `status = accepted`.

9. To test decline: first withdraw the accepted connection, then re-request, then decline as member2:

   ```bash
   # Withdraw (member1):
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/connect \
     -u member1:password -H "Content-Type: application/json"

   # Re-request:
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/connect \
     -u member1:password -H "Content-Type: application/json"

   # Decline (member2):
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/connect/decline \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected on decline: 200, `status = declined`.

### Part 3: Block / Unblock

10. As `member1`, block `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/block \
      -u member1:password -H "Content-Type: application/json"
    ```

    - Expected: 200 with `{"blocked": true}`. Row inserted into `wp_bn_blocks` with `type = block`.

11. Verify the block row:

    ```sql
    SELECT blocker_id, blocked_id, type, created_at
    FROM wp_bn_blocks
    WHERE blocker_id = MEMBER1_ID AND blocked_id = MEMBER2_ID;
    ```

    - Expected: 1 row, `type = block`.

12. Unblock `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/block \
      -u member1:password -H "Content-Type: application/json"
    ```

    - Expected: 200 with `{"blocked": false}`. Row removed from `wp_bn_blocks`.

## Edge cases to also verify

- **Self-follow**: Attempt to follow own user ID. Expected: 4xx error — self-follow is disallowed by `FollowController`.
- **Block prevents follow**: Block member2 as member1, then attempt to follow member2. Expected: 4xx — blocked user cannot be followed.
- **Duplicate connection request**: Send two connection requests to the same user. Expected: second request returns the existing pending row, no duplicate inserted (UNIQUE KEY `pair` on `bn_connections`).
- **Mute vs block**: Send a `POST /buddynext/v1/users/MEMBER2_ID/mute` request. Expected: row inserted into `wp_bn_blocks` with `type = mute`, not `type = block`. Muted user still appears in feeds but their notifications are suppressed.
- **Follow suggestions**: As member1 (with no follows), call `GET /buddynext/v1/follow-suggestions`. Expected: 200 with a non-empty array. After following all suggestions, verify the suggestions list excludes already-followed users.

## What this validates

- `FollowService` inserts/deletes `wp_bn_follows` rows and fires `buddynext_user_followed` / `buddynext_user_unfollowed`.
- `ConnectionService` transitions `wp_bn_connections.status` through `pending -> accepted` / `pending -> declined` / `accepted -> withdrawn` and fires corresponding actions.
- `BlockService` inserts/deletes `wp_bn_blocks` rows with the correct `type` column and fires `buddynext_block` / `buddynext_unblock`.
- `FollowController`, `ConnectionController`, `BlockController` all require `is_user_logged_in`.
- `PrivacyService` uses `bn_blocks` to filter viewer-aware content.

## Verification queries

```sql
-- Follow graph for member1 (replace IDs):
SELECT follower_id, following_id, created_at
FROM wp_bn_follows
WHERE follower_id = MEMBER1_ID;

-- All connections for member1:
SELECT id, requester_id, recipient_id, status, created_at
FROM wp_bn_connections
WHERE requester_id = MEMBER1_ID OR recipient_id = MEMBER1_ID;

-- Block list for member1:
SELECT blocker_id, blocked_id, type, created_at
FROM wp_bn_blocks
WHERE blocker_id = MEMBER1_ID;
```

## REST surface walked

```
POST /buddynext/v1/users/{id}/follow                 -- toggle follow; 200 { "following": bool }
GET  /buddynext/v1/users/{id}/followers              -- 200, array of user objects
GET  /buddynext/v1/users/{id}/following              -- 200, array of user objects
GET  /buddynext/v1/follow-suggestions                -- 200, array of user objects (logged-in)
POST /buddynext/v1/users/{id}/connect                -- send / withdraw request; 200-201
POST /buddynext/v1/users/{id}/connect/accept         -- 200 { "status": "accepted" }
POST /buddynext/v1/users/{id}/connect/decline        -- 200 { "status": "declined" }
GET  /buddynext/v1/me/connections                    -- 200, array of accepted connections
GET  /buddynext/v1/me/connection-requests            -- 200, array of pending requests
POST /buddynext/v1/users/{id}/block                  -- toggle block; 200 { "blocked": bool }
POST /buddynext/v1/users/{id}/mute                   -- toggle mute; 200 { "muted": bool }
GET  /buddynext/v1/me/blocked                        -- 200, array of blocked users
GET  /buddynext/v1/me/muted                          -- 200, array of muted users
```

## Cleanup

```sql
-- Remove test follow rows:
DELETE FROM wp_bn_follows
WHERE follower_id = MEMBER1_ID OR follower_id = MEMBER2_ID;

-- Remove test connection rows:
DELETE FROM wp_bn_connections
WHERE requester_id IN (MEMBER1_ID, MEMBER2_ID)
   OR recipient_id IN (MEMBER1_ID, MEMBER2_ID);

-- Remove test block rows:
DELETE FROM wp_bn_blocks
WHERE blocker_id IN (MEMBER1_ID, MEMBER2_ID);
```

## Known limitations

- Follow suggestions algorithm is seeded from users not already followed — no ML ranking at this stage.
- `buddynext_connection_rejected` action is listed as `buddynext_connection_declined` in HOOKS.md; use the HOOKS.md spelling in addon code.

## Automation notes

- All REST calls in this journey are curl-automatable with basic auth.
- The toggle pattern (follow/block return a bool in the response) means automation scripts should assert the returned `following`/`blocked` value rather than checking HTTP status alone.
- DB verification queries can be run via `wp db query "..."` inside LocalWP's site shell.
