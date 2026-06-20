# Journey: Moderation Report

**Free feature**: `includes/Moderation/` (ModerationService, ModerationController, ModerationLogService, SafeguardService)
**Actions / filters fired**: `buddynext_report_created`, `buddynext_strike_issued`, `buddynext_user_suspended`, `buddynext_member_suspended`, `buddynext_appeal_submitted`, `buddynext_appeal_resolved`, `buddynext_content_removed`, `buddynext_user_warned`, `buddynext_user_shadow_banned`, `buddynext_safeguard_check` (filter), `buddynext_moderation_auto_actions` (filter)
**DB tables touched**: `bn_reports`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_mod_log`, `bn_space_bans`
**Estimated time**: 12 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- Test data: `member1`, `member2`, and 1 open space with a post by `member2`
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Member reports a post

1. As `member2`, create a post to be reported:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"content": "This is a test post that will be reported.", "space_id": SPACE_ID, "privacy": "public"}'
   ```

   - Expected: 201. Note the returned post `id` (referred to as `REPORTED_POST_ID`).

2. As `member1`, submit a report on that post:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reports \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d '{
       "object_type": "post",
       "object_id": REPORTED_POST_ID,
       "reason": "spam",
       "notes": "This looks like spam content"
     }'
   ```

   - Expected: 201. Note the returned report `id` (referred to as `REPORT_ID`). Action `buddynext_report_created` fires.

3. Verify the report row:

   ```sql
   SELECT id, reporter_id, object_type, object_id, reason, notes, status, created_at
   FROM wp_bn_reports
   WHERE id = REPORT_ID;
   ```

   - Expected: 1 row, `reason = spam`, `status = pending`.

### Part 2: Admin reviews via moderation queue

4. As admin, retrieve the moderation queue:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/reports/queue \
     -u admin:password
   ```

   - Expected: 200. Array includes the report created in Step 2. `status = pending`.

5. As admin, dismiss the report:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reports/REPORT_ID/dismiss \
     -u admin:password -H "Content-Type: application/json"
   ```

   - Expected: 200. `status` updated to `dismissed` in `bn_reports`.

6. Verify the dismiss:

   ```sql
   SELECT id, status, resolved_by, resolved_at
   FROM wp_bn_reports
   WHERE id = REPORT_ID;
   ```

   - Expected: `status = dismissed`, `resolved_by` = admin's user ID, `resolved_at` is a valid datetime.

### Part 3: Escalate and resolve a report

7. Create a second post and report for the resolve flow:

   ```bash
   # Create post:
   SECOND_POST=$(curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
     -u member2:password \
     -H "Content-Type: application/json" \
     -d '{"content": "Second test post for resolve flow.", "space_id": SPACE_ID, "privacy": "public"}')
   SECOND_POST_ID=$(echo $SECOND_POST | grep -o '"id":[0-9]*' | head -1 | cut -d: -f2)

   # Report it:
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/reports \
     -u member1:password \
     -H "Content-Type: application/json" \
     -d "{\"object_type\": \"post\", \"object_id\": $SECOND_POST_ID, \"reason\": \"harassment\"}"
   ```

8. As admin, escalate the new report:

   ```bash
   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/reports/SECOND_REPORT_ID/escalate \
     -u admin:password -H "Content-Type: application/json"
   ```

   - Expected: 200. `status = escalated`.

9. As admin, resolve the escalated report:

   ```bash
   curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/reports/SECOND_REPORT_ID/resolve \
     -u admin:password -H "Content-Type: application/json"
   ```

   - Expected: 200. `status = resolved`.

### Part 4: Issue a strike

10. As admin, issue a moderation strike to `member2`:

    ```bash
    wp user get member2 --field=ID

    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/strikes \
      -u admin:password \
      -H "Content-Type: application/json" \
      -d '{"reason": "Repeated spam content violating community guidelines"}'
    ```

    - Expected: 201. Row inserted into `wp_bn_user_strikes`. `buddynext_strike_issued` fires.

11. Verify the strike:

    ```sql
    SELECT id, user_id, issued_by, reason, is_reversed, created_at
    FROM wp_bn_user_strikes
    WHERE user_id = MEMBER2_ID;
    ```

    - Expected: 1 row, `is_reversed = 0`.

### Part 5: Suspend a user

12. As admin, suspend `member2`:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER2_ID/suspend \
      -u admin:password \
      -H "Content-Type: application/json" \
      -d '{"reason": "Spam policy violation", "duration_days": 7}'
    ```

    - Expected: 200. Row inserted into `wp_bn_user_suspensions`. `buddynext_user_suspended` fires.

13. Verify the suspension:

    ```sql
    SELECT id, user_id, suspended_by, reason, duration_days, expires_at, lifted_at, created_at
    FROM wp_bn_user_suspensions
    WHERE user_id = MEMBER2_ID;
    ```

    - Expected: 1 row, `duration_days = 7`, `lifted_at = NULL`.

14. Verify `member2` cannot post while suspended:

    ```bash
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/posts \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"content": "Post while suspended?", "space_id": SPACE_ID}'
    ```

    - Expected: 403 — suspended users cannot post.

### Part 6: User submits an appeal

15. As `member2`, submit an appeal:

    ```bash
    # Get suspension ID:
    wp db query "SELECT id FROM wp_bn_user_suspensions WHERE user_id = MEMBER2_ID ORDER BY id DESC LIMIT 1;"

    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/appeals \
      -u member2:password \
      -H "Content-Type: application/json" \
      -d '{"suspension_id": SUSPENSION_ID, "message": "I believe this suspension was applied in error."}'
    ```

    - Expected: 201. Row inserted into `wp_bn_appeals`. `buddynext_appeal_submitted` fires.

16. Verify the appeal:

    ```sql
    SELECT id, suspension_id, user_id, message, status, created_at
    FROM wp_bn_appeals
    WHERE user_id = MEMBER2_ID;
    ```

    - Expected: 1 row, `status = pending`.

17. As admin, approve the appeal:

    ```bash
    curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/appeals/APPEAL_ID/approve \
      -u admin:password \
      -H "Content-Type: application/json" \
      -d '{"reviewer_note": "Appeal reviewed. Suspension lifted."}'
    ```

    - Expected: 200. `status = approved`. `buddynext_appeal_resolved` fires. Suspension should be lifted.

18. Verify:

    ```sql
    SELECT id, status, reviewed_by, reviewer_note, reviewed_at
    FROM wp_bn_appeals
    WHERE id = APPEAL_ID;

    SELECT id, lifted_at, lifted_by
    FROM wp_bn_user_suspensions
    WHERE id = SUSPENSION_ID;
    ```

    - Expected: appeal `status = approved`; suspension `lifted_at` is set.

## Edge cases to also verify

- **Duplicate report**: As `member1`, attempt to report the same post a second time. Expected: 409 or 422 — UNIQUE KEY `one_per_reporter` on `bn_reports` prevents duplicates.
- **Non-moderator queue access**: As `member1`, attempt `GET /buddynext/v1/reports/queue`. Expected: 403.
- **Strike reversal**: As admin, reverse member2's strike. Call `POST /buddynext/v1/users/MEMBER2_ID/strikes/STRIKE_ID/reverse`. Expected: `is_reversed = 1` set in `bn_user_strikes`.
- **Warn without suspend**: Call `POST /buddynext/v1/users/MEMBER2_ID/warn` with a reason. Expected: `buddynext_user_warned` fires; no suspension row created.
- **Shadow ban**: Call `POST /buddynext/v1/users/MEMBER2_ID/shadow-ban`. Expected: `buddynext_user_shadow_banned` fires; member2's content hidden from non-admin search/feed results.

## What this validates

- `ModerationService::create_report()` inserts into `bn_reports` and fires `buddynext_report_created(int $report_id, string $object_type, int $object_id, int $reporter_id)`.
- `ModerationService::dismiss_report()` and `resolve_report()` update `bn_reports.status` and `resolved_by`/`resolved_at`.
- `ModerationService::issue_strike()` inserts into `bn_user_strikes` and fires `buddynext_strike_issued`.
- `ModerationService::suspend_user()` inserts into `bn_user_suspensions` and fires `buddynext_user_suspended`.
- `ModerationService::submit_appeal()` inserts into `bn_appeals` and fires `buddynext_appeal_submitted`.
- `ModerationService::approve_appeal()` updates `bn_appeals.status` to `approved` and lifts the suspension.
- `ModerationLogService` writes an immutable row to `bn_mod_log` for every moderation action.
- All moderation REST endpoints require `is_user_logged_in`; queue/strike/suspend require `manage_options` or moderator capability.

## Verification queries

```sql
-- All reports from this journey:
SELECT id, reporter_id, object_type, object_id, reason, status, created_at
FROM wp_bn_reports
WHERE reporter_id = MEMBER1_ID
ORDER BY created_at DESC;

-- Strikes for member2:
SELECT id, user_id, issued_by, reason, is_reversed, reversed_at, created_at
FROM wp_bn_user_strikes
WHERE user_id = MEMBER2_ID;

-- Suspension for member2:
SELECT id, user_id, reason, duration_days, expires_at, lifted_at
FROM wp_bn_user_suspensions
WHERE user_id = MEMBER2_ID;

-- Appeal for member2:
SELECT id, suspension_id, status, reviewed_by, reviewed_at
FROM wp_bn_appeals
WHERE user_id = MEMBER2_ID;

-- Moderation log (last 10 entries):
SELECT id, actor_id, action, object_type, object_id, target_user_id, created_at
FROM wp_bn_mod_log
ORDER BY created_at DESC
LIMIT 10;
```

## REST surface walked

```
POST /buddynext/v1/reports                           -- 201, report created (logged-in)
GET  /buddynext/v1/reports/queue                     -- 200, pending reports (moderator)
POST /buddynext/v1/reports/{id}/dismiss              -- 200, dismissed (moderator)
PUT  /buddynext/v1/reports/{id}/escalate             -- 200, escalated (moderator)
PUT  /buddynext/v1/reports/{id}/resolve              -- 200, resolved (moderator)
GET  /buddynext/v1/users/{id}/strikes                -- 200, strike list (moderator)
POST /buddynext/v1/users/{id}/strikes/{sid}/reverse  -- 200, strike reversed (admin)
POST /buddynext/v1/users/{id}/suspend                -- 200, user suspended (admin)
DELETE /buddynext/v1/users/{id}/suspend              -- 200, suspension lifted (admin)
GET  /buddynext/v1/users/{id}/suspension             -- 200, suspension details
POST /buddynext/v1/appeals                           -- 201, appeal submitted (logged-in)
POST /buddynext/v1/me/appeals                        -- 201, own appeal submitted
GET  /buddynext/v1/appeals                           -- 200, appeal list (moderator)
PUT  /buddynext/v1/appeals/{id}/approve              -- 200, appeal approved (moderator)
PUT  /buddynext/v1/appeals/{id}/deny                 -- 200, appeal denied (moderator)
POST /buddynext/v1/users/{id}/warn                   -- 200, warning issued (moderator)
DELETE/GET/POST /buddynext/v1/users/{id}/shadow-ban  -- apply / status / remove shadow ban
GET  /buddynext/v1/posts/{id}/content-warning        -- 200, content warning details
POST /buddynext/v1/reports/{id}/remove               -- soft-remove reported content (moderator)
POST /buddynext/v1/appeals/{id}/resolve              -- resolve appeal: decision=approved|denied
GET  /buddynext/v1/me/appeals                        -- member's own appeals
GET  /buddynext/v1/moderation/pending, /moderation/log -- admin queue + immutable log
```

> **Verified live 2026-06-20.** Note: `/users/{id}/warn` and `GET /users/{id}/suspension` did NOT appear in the live index (warnings flow through `/strikes`; suspension via `POST/DELETE /users/{id}/suspend`). Re-confirm before walking those two: `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if any(k in r for k in ('report','appeal','strike','suspend','shadow','moderation'))]"`

## Frontend action wiring

*(Item 11. Report = member entry; queue actions = admin. The admin appeal action uses `/resolve` — a grep-only audit once mis-flagged this as a 404 because it matched only `/approve`,`/deny`; the live route IS `/resolve`.)*

| Control | Template (file) | JS store / action | Live route + method | Nonce |
|---|---|---|---|---|
| Report content (modal) | `templates/parts/member-report-modal.php` | modal handler | `POST /reports` | modal nonce |
| Report post (from card) | `templates/parts/post-options-menu.php` | `feed/store.js:612` | `POST /reports` | `ctx.reportNonce` |
| Queue: dismiss | `templates/moderation/queue.php` | `moderation/store.js:38` | `POST /reports/{id}/dismiss` | `ctx.restNonce` |
| Queue: remove content | `templates/moderation/queue.php` | `moderation/store.js:64` | `POST /reports/{id}/remove` | `ctx.restNonce` |
| Queue: escalate / resolve | `templates/moderation/queue.php` | `moderation/store.js` | `POST /reports/{id}/escalate` · `/resolve` | `ctx.restNonce` |
| Appeal approve / deny | `templates/moderation/account-status.php` | `moderation/store.js:172` | `POST /appeals/{id}/resolve` `{decision}` | `ctx.restNonce` |

**Verify this run (incl. multi-actor — the gap in this journey):**
1. `alice` reports `bob`'s post (`POST /reports` 201); as admin the report appears in `GET /reports/queue`.
2. Resolve it; assert the report `status` field changed (not just HTTP 200).
3. **Multi-actor:** two moderators resolve the same report — the second must degrade gracefully ("already resolved"), not 500.

## Admin-config → member-effect

*(Item 12. The threshold ladder is owner-configurable and must actually change behaviour.)*

- **Auto-hide threshold** (`buddynext_auto_hide_threshold`, default 5; `ModerationService.php:159`): set to **2**, file 2 reports on one post → confirm it auto-hides. Set high → confirm it doesn't.
- **Strike → warn/suspend/ban thresholds** (`buddynext_strike_warn_threshold` default 2, `ModerationListener.php:110`): set warn=1 → confirm one strike triggers a warning notification to the member.
- **Suspended member effect:** suspend `bob` → confirm `bob` can't post (`POST /posts` 403) and sees the account-status screen; lift → restored.

Restore options after.


## Cleanup

```sql
-- Lift suspension:
UPDATE wp_bn_user_suspensions
SET lifted_at = NOW(), lifted_by = 1
WHERE user_id = MEMBER2_ID AND lifted_at IS NULL;

-- Remove appeals:
DELETE FROM wp_bn_appeals WHERE user_id = MEMBER2_ID;

-- Remove strikes:
DELETE FROM wp_bn_user_strikes WHERE user_id = MEMBER2_ID;

-- Remove reports:
DELETE FROM wp_bn_reports
WHERE reporter_id = MEMBER1_ID
   OR object_id IN (REPORTED_POST_ID, SECOND_POST_ID);

-- Remove test posts:
DELETE FROM wp_bn_posts WHERE user_id = MEMBER2_ID AND space_id = SPACE_ID;
```

## Known limitations

- `buddynext_user_warned`, `buddynext_user_shadow_banned`, `buddynext_appeal_submitted`, `buddynext_appeal_resolved` are marked as pending in HOOKS.md (BLOCK 2 tasks). Verify their implementation status before asserting fires.
- Strike-list REST endpoint is `GET /buddynext/v1/users/{id}/strikes` — confirm this route in `ModerationController` matches the manifest before testing.

## Automation notes

- Report creation and queue retrieval are fully curl-automatable.
- Suspension + appeal flow requires sequential execution (suspension must exist before appeal is submitted).
- The strike-reversal endpoint path (`/strikes/{sid}/reverse`) requires collecting the strike ID from the create response or a DB query.
