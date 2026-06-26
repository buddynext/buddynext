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
   - Follow is **not** a POST toggle: `POST /users/{id}/follow` always means *follow*. A second POST does NOT unfollow — use `DELETE` to unfollow (step 4).

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

4. Unfollow member2 (note the `DELETE` method — a 2nd POST does not unfollow):

   ```bash
   curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/follow \
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
   # Withdraw (member1) — DELETE, not a 2nd POST:
   curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/connect \
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
POST   /buddynext/v1/users/{id}/follow                -- follow; 200 { "following": true }
DELETE /buddynext/v1/users/{id}/follow                -- unfollow; 200 { "following": false }
GET  /buddynext/v1/users/{id}/followers              -- 200, { ids:[...], total, page, per_page } (ID array, NOT hydrated user objects)
GET  /buddynext/v1/users/{id}/following              -- 200, { ids:[...], total, page, per_page }
GET  /buddynext/v1/follow-suggestions                -- 200, array of user objects (logged-in)
POST   /buddynext/v1/users/{id}/connect              -- send request; 200-201
DELETE /buddynext/v1/users/{id}/connect              -- withdraw request; 200
POST /buddynext/v1/users/{id}/connect/accept         -- 200 { "status": "accepted" }
POST /buddynext/v1/users/{id}/connect/decline        -- 200 { "status": "declined" }
GET  /buddynext/v1/me/connections                    -- 200, { ids:[...], total, ... } (ID array, not hydrated objects)
GET  /buddynext/v1/me/connection-requests            -- 200, array of pending requests
POST   /buddynext/v1/users/{id}/block                -- block; 200 { "blocked": bool }
DELETE /buddynext/v1/users/{id}/block                -- unblock; 200 { "blocked": false }
POST   /buddynext/v1/users/{id}/mute                  -- mute; 200 { "muted": bool }
DELETE /buddynext/v1/users/{id}/mute                  -- unmute; 200 { "muted": false }
POST   /buddynext/v1/users/{id}/restrict              -- restrict; 200 { "restricted": bool }
DELETE /buddynext/v1/users/{id}/restrict              -- un-restrict; 200 { "restricted": false }
GET    /buddynext/v1/me/blocked                       -- 200, array of blocked users
GET    /buddynext/v1/me/muted                         -- 200, array of muted users
GET    /buddynext/v1/me/restricted                    -- 200, array of restricted users
```

> **Data-model caveat (runtime-confirmed 2026-06-20):** `bn_blocks` has `PRIMARY KEY (blocker_id, blocked_id)` — exactly ONE row per pair, so `block`/`mute`/`restrict` are **mutually exclusive**, not independent rows. `block` upgrades an existing row (`ON DUPLICATE KEY UPDATE type='block'`); `mute`/`restrict` use `INSERT IGNORE`, so if any row already exists they **no-op but still return a success body** (`{"restricted":true}`) — a success response does NOT prove a state change. Verify the actual `type` column, not the HTTP body. This is intentional; don't "fix" the code.

> Confirm this list against the **live** index every run — do not trust the source grep:
> `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if any(k in r for k in ('block','mute','restrict','follow','connect'))]"`
> (The `restrict` routes were once mis-reported as missing by a grep-only audit; the live index proves they exist with both POST and DELETE.)

## Frontend action wiring

*(Runbook contract item 11 — the layer REST-only journeys miss. Map each member-facing control to its JS action, the LIVE route+method, and the nonce source. Verify the JS path/method matches the live route index, and that the template emits the nonce the store reads.)*

| Control | Template (file) | JS store / handler | Live route + method | Nonce source |
|---|---|---|---|---|
| Follow / Unfollow | `templates/blocks/follow-button.php`, `blocks/member-card.php`, `blocks/profile-header.php` | `buddynext/follow-button` (`assets/js/social/follow-store.js:78`) | `POST`/`DELETE /users/{id}/follow` | `ctx.nonce` |
| Connection request accept / decline | `templates/profile/followers.php` | `buddynext/connection-requests` (`follow-store.js:196`) | `POST /users/{id}/connect/accept` · `/decline` | `ctx.nonce` |
| Follow-request approve / reject | `templates/profile/followers.php` | `buddynext/follow-requests` (`follow-store.js:141`) | `POST /me/follow-requests/{id}/approve` · `/reject` | `ctx.nonce` |
| Block (confirm) | `templates/parts/member-block-modal.php` | modal handler | `POST /users/{id}/block` | modal-emitted nonce |
| Report (submit) | `templates/parts/member-report-modal.php` | modal handler | `POST /reports` | modal-emitted nonce |
| Un-block / un-mute / un-restrict row | `templates/parts/settings-relations.php` | `assets/js/social/relation-remove.js:65` | `DELETE /users/{id}/{block\|mute\|restrict}` | `data-bn-nonce` → `wpApiSettings.nonce` → `ctx.restNonce` |

**How to verify each row this run:**
1. Live route exists with the right method — `curl -s http://buddynext.local/wp-json/buddynext/v1` and grep the path (see snippet in REST surface above).
2. Template emits the control + nonce — `curl -s -b /tmp/bn.txt -L http://buddynext.local/members/ | grep -n 'data-relation="restrict"\|data-bn-nonce'` (logged in).
3. The JS method matches the route's allowed methods (e.g. relation-remove uses `DELETE`; the route allows `POST,DELETE` — match).

A control passes the REST-layer steps above but fails here when the template points JS at the wrong path/method or omits the nonce key the store reads — that is the "button does nothing" class customers report. **Confirm against the running site, never grep alone** (see `docs/qa/FLOW-VERIFICATION-2026-06-20.md`).

## Admin-config → member-effect

*(Runbook contract item 12 — flip the setting in the REAL admin form, then re-check the member-facing effect. Restore after.)*

- **`buddynext_connection_require_note`** (Settings → BuddyNext → Members): turn ON, then as `member1` open `member2`'s profile and start a connection request.
  - Expected member effect: the connect dialog requires a note before submit (`assets/js/shell/dialog.js`, value passed as `connectRequireNote` in `PageRouter.php:983`).
  - **Documented caveat (verify, don't "fix" blindly):** enforcement is client-side only — `POST /users/{id}/connect` declares `note` as `required => false` (`ConnectionController.php`), so a direct API caller can omit it. This is current intended behaviour for Free; note it, don't weaken the REST contract to chase a UI hint.
- **Block visibility gate** (no setting — relationship-driven): after `member1` blocks `member2`, confirm the member effect end-to-end — `member2` is excluded from `member1`'s directory/search results and feeds. Restore by un-blocking (`DELETE /users/{id}/block`).

Restore any option you changed (`wp option delete <key>` returns it to default).

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
- Follow/connect are method-driven (POST = add, DELETE = remove), not POST toggles; block returns a bool in the response. Automation scripts should send the correct method and assert the returned `following`/`blocked` value rather than checking HTTP status alone.
- DB verification queries can be run via `wp db query "..."` inside LocalWP's site shell.
