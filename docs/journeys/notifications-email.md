# Journey: Notifications and Email

**Free feature**: `includes/Notifications/` (NotificationService, NotificationPrefService, EmailDispatchListener, EmailSender)
**Actions / filters fired**: `buddynext_user_followed` (trigger), `buddynext_notification_created`, `buddynext_queue_email_digest`, `buddynext_notification_should_send` (filter), `buddynext_notification_send_at` (filter), `buddynext_email_payload` (filter)
**DB tables touched**: `bn_notifications`, `bn_email_log`, `bn_notification_prefs`, `bn_email_templates`
**Estimated time**: 10 min manual

## Preconditions

- BuddyNext Free active on http://buddynext-dev.local/ (LocalWP dev site)
- WordPress email delivery configured (use WP Mail SMTP + a local mailhog/mailpit, or inspect `wp_bn_email_log` directly)
- Test data: `member1` and `member2` exist
- Admin user (autologin: append `?autologin=1` to any admin URL — the mu-plugin at `mu-plugins/00-autologin.php` handles it)
- Member users: `member1` / `password`, `member2` / `password`

## Happy-path steps

### Part 1: Trigger a notification via follow

1. As `member2`, follow `member1`. This fires `buddynext_user_followed(int $follower_id, int $following_id)`, which `NotificationService` (or a connected listener) converts into a notification row:

   ```bash
   wp user get member1 --field=ID
   wp user get member2 --field=ID

   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
     -u member2:password -H "Content-Type: application/json"
   ```

   - Expected: 200 with `{"following": true}`.

2. Verify a notification row was inserted for `member1`:

   ```sql
   SELECT id, recipient_id, sender_id, type, object_type, object_id, is_read, created_at
   FROM wp_bn_notifications
   WHERE recipient_id = MEMBER1_ID AND type = 'bn.new_follower'
   ORDER BY created_at DESC
   LIMIT 1;
   ```

   - Expected: 1 row, `is_read = 0`, `type = bn.new_follower`, `sender_id = MEMBER2_ID`.

3. Verify an email log entry was inserted (if `email_freq` is `immediate` for the `follow` type):

   ```sql
   SELECT id, user_id, type, sent_at
   FROM wp_bn_email_log
   WHERE user_id = MEMBER1_ID AND type = 'bn.new_follower'
   ORDER BY sent_at DESC
   LIMIT 1;
   ```

   - Expected: 1 row. If no row, confirm member1's notification pref for `follow` is `immediate` (see Step 6 below).

### Part 2: Retrieve notifications via REST

4. As `member1`, list notifications:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications \
     -u member1:password
   ```

   - Expected: 200, array with at least 1 notification of `type = bn.new_follower`.

5. Check the unread count:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications/unread-count \
     -u member1:password
   ```

   - Expected: 200, `{"count": N}` where N >= 1.

### Part 3: Mark notification as read via REST

6. As `member1`, mark all notifications read:

   ```bash
   curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications/read-all \
     -u member1:password \
     -H "Content-Type: application/json"
   ```

   - Expected: 200. All notification rows for member1 updated to `is_read = 1`. (To mark a single notification read instead, `POST /me/notifications/{id}/read`.)

7. Verify:

   ```sql
   SELECT COUNT(*) AS unread
   FROM wp_bn_notifications
   WHERE recipient_id = MEMBER1_ID AND is_read = 0;
   ```

   - Expected: `unread = 0`.

8. Confirm via REST:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications/unread-count \
     -u member1:password
   ```

   - Expected: `{"count": 0}`.

### Part 4: Notification preferences

9. As `member1`, get current notification preferences:

   ```bash
   curl -s http://buddynext-dev.local/wp-json/buddynext/v1/me/notification-prefs \
     -u member1:password
   ```

   - Expected: 200, map of `type -> {on_site, email_freq}` preferences.

10. Update the `follow` notification preference to `email_freq = off`:

    ```bash
    curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/me/notification-prefs \
      -u member1:password \
      -H "Content-Type: application/json" \
      -d '{"bn.new_follower": {"on_site": true, "email_freq": "off"}}'
    ```

    - Expected: 200. Pref row upserted in `wp_bn_notification_prefs`.

11. Verify the preference row:

    ```sql
    SELECT user_id, type, on_site, email_freq
    FROM wp_bn_notification_prefs
    WHERE user_id = MEMBER1_ID AND type = 'bn.new_follower';
    ```

    - Expected: `email_freq = off`.

12. Trigger another follow (have member2 unfollow then re-follow member1):

    ```bash
    # Unfollow:
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
      -u member2:password -H "Content-Type: application/json"

    # Re-follow:
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
      -u member2:password -H "Content-Type: application/json"
    ```

    - Expected: in-app notification is still created (on_site = true), but no new row in `wp_bn_email_log` (email_freq = off).

### Part 5: EmailDispatchListener sends email for immediate pref

13. Reset member1's follow pref to `immediate`:

    ```bash
    curl -s -X PUT http://buddynext-dev.local/wp-json/buddynext/v1/me/notification-prefs \
      -u member1:password \
      -H "Content-Type: application/json" \
      -d '{"bn.new_follower": {"on_site": true, "email_freq": "immediate"}}'
    ```

14. Trigger another follow notification. Confirm the email template `bn.new_follower` is queued:

    ```bash
    # Unfollow + re-follow again:
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
      -u member2:password -H "Content-Type: application/json"
    curl -s -X POST http://buddynext-dev.local/wp-json/buddynext/v1/users/MEMBER1_ID/follow \
      -u member2:password -H "Content-Type: application/json"
    ```

    Check the email log:

    ```sql
    SELECT id, user_id, type, sent_at
    FROM wp_bn_email_log
    WHERE user_id = MEMBER1_ID AND type = 'bn.new_follower'
    ORDER BY sent_at DESC
    LIMIT 3;
    ```

    - Expected: new row(s) for the immediate dispatch.

### Part 6: Delete a single notification

15. Delete the follow notification via REST:

    ```bash
    # Get the notification ID:
    curl -s http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications \
      -u member1:password

    curl -s -X DELETE http://buddynext-dev.local/wp-json/buddynext/v1/me/notifications/NOTIFICATION_ID \
      -u member1:password
    ```

    - Expected: 200. Row removed from `wp_bn_notifications`.

## Edge cases to also verify

- **Suppressed notification via filter**: Hook `buddynext_notification_should_send` to return `false` for the `follow` type. Trigger a follow. Expected: no row inserted in `bn_notifications` and no email queued.
- **Daily digest preference**: Set `email_freq = daily` for `follow`. Trigger a follow. Expected: `bn.daily_digest` email type queued (or `buddynext_queue_email_digest` action fired) rather than `bn.new_follower` immediate.
- **Group notifications**: Trigger 3 follow actions in rapid succession from different users. Confirm `group_key` and `group_count` columns on `bn_notifications` aggregate correctly rather than creating 3 separate rows.
- **Unauthorized access**: Attempt `GET /buddynext/v1/me/notifications` without credentials. Expected: 401.

## What this validates

- `NotificationService::create()` inserts into `bn_notifications` and fires `buddynext_notification_created(int $notification_id, int $recipient_id, string $type, array $data)`.
- `EmailDispatchListener` hooks `buddynext_notification_created`, checks `bn_notification_prefs`, and dispatches via `EmailSender` when `email_freq = immediate`.
- `EmailSender` writes to `bn_email_log` after dispatch.
- `NotificationController::mark_read()` sets `is_read = 1` for all or a single notification.
- `NotificationPrefService` upserts `bn_notification_prefs` rows.
- `buddynext_notification_should_send` filter is evaluated before inserting the notification row.

## Verification queries

```sql
-- All unread notifications for member1:
SELECT id, sender_id, type, object_type, object_id, group_count, is_read, created_at
FROM wp_bn_notifications
WHERE recipient_id = MEMBER1_ID AND is_read = 0
ORDER BY created_at DESC;

-- Email log for member1 (last 10 entries):
SELECT id, type, sent_at
FROM wp_bn_email_log
WHERE user_id = MEMBER1_ID
ORDER BY sent_at DESC
LIMIT 10;

-- Notification preferences for member1:
SELECT type, on_site, email_freq
FROM wp_bn_notification_prefs
WHERE user_id = MEMBER1_ID;

-- Email templates available:
SELECT id, type, subject, enabled
FROM wp_bn_email_templates
ORDER BY type;
```

## REST surface walked

```
GET        /buddynext/v1/me/notifications              -- 200, paginated list (logged-in)
GET        /buddynext/v1/me/notifications/unread-count  -- 200, { "count": int }
PUT|POST   /buddynext/v1/me/notifications/{id}/read     -- 200, marks one read
PUT|POST   /buddynext/v1/me/notifications/read-all      -- 200, marks all read
DELETE     /buddynext/v1/me/notifications/{id}          -- 200, { "deleted": true }
GET        /buddynext/v1/me/notification-prefs          -- 200, pref map
PUT        /buddynext/v1/me/notification-prefs          -- 200, updated prefs
GET|PUT    /buddynext/v1/me/notification-channels        -- per-channel (email/in-app) prefs
GET|POST   /buddynext/v1/me/space-notification-prefs     -- per-space prefs
GET|POST   /buddynext/v1/spaces/{id}/notification-pref   -- a single space's pref
```

> Re-confirm live: `curl -s http://buddynext.local/wp-json/buddynext/v1 | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes']) if 'notif' in r]"`

## Frontend action wiring

*(Item 11. Note the **injected-base-URL fragility**: the prefs store does NOT build paths from a fixed namespace — it uses `base: ctx.restPrefsUrl` / `restChannelsUrl` / `restSpacesUrl` passed from the template. The REST-layer journey hits the canonical path directly, so a wrong/missing injected URL is invisible there but breaks Save. Verify the template emits all three.)*

| Control | Template (file) | JS store / action | Live route + method | Source |
|---|---|---|---|---|
| Notification list / bell | `templates/notifications/index.php` | `buddynext/notifications` (`store.js`) | `GET /me/notifications`, `/unread-count` | `ctx.restNonce` |
| Mark one read | `templates/notifications/index.php` | `store.js:216` | `POST /me/notifications/{id}/read` | `ctx.restNonce` |
| Mark all read | `templates/notifications/index.php` | `store.js:163` | `POST /me/notifications/read-all` | `ctx.restNonce` |
| Delete notification | `templates/notifications/index.php` | `store.js:315` | `DELETE /me/notifications/{id}` | `ctx.restNonce` |
| Save prefs (channels) | `templates/notifications/prefs.php` | `buddynext/notification-prefs` (`prefs-store.js`) | `PUT /me/notification-prefs` · `/me/notification-channels` | `base: ctx.restPrefsUrl` / `restChannelsUrl` |
| Save per-space prefs | `templates/notifications/prefs.php` | `prefs-store.js:217` | `POST /me/space-notification-prefs` | `base: ctx.restSpacesUrl` |

**Verify this run:**
1. Trigger an action (alice follows bob) → bob's `/me/notifications/unread-count` increments; mark read → decrements (assert the count delta, not just 200).
2. **Injected URLs present:** `curl -s -b /tmp/bn.txt -L http://buddynext.local/settings/notifications/ | grep -oE 'restPrefsUrl|restChannelsUrl|restSpacesUrl'` → all three must appear, or Save silently no-ops.

## Admin-config → member-effect

*(Item 12. The CLAUDE.md "preview matches the real send" contract lives here.)*

- **Email sender identity** (Settings → BuddyNext → Email: from-name, from-address, reply-to, footer): set custom values, trigger a real notification email, then open **Mailpit (http://localhost:10030/)** and confirm the From/Reply-To/footer on the *actual sent message* match the admin settings (not just the in-admin preview).
- **Digest frequency** (`buddynext_digest_frequency`): set to **never** → the digest cron produces no email; daily → exactly one digest.
- **Per-event channel:** disable email for "new follower" in prefs → a follow creates the in-app row but NO email (channel honored end-to-end).

Restore options after.

## Cleanup

```sql
-- Remove test notifications for member1:
DELETE FROM wp_bn_notifications
WHERE recipient_id = MEMBER1_ID AND type = 'bn.new_follower';

-- Remove email log entries from this journey:
DELETE FROM wp_bn_email_log
WHERE user_id = MEMBER1_ID AND type = 'bn.new_follower';

-- Remove notification pref overrides:
DELETE FROM wp_bn_notification_prefs
WHERE user_id = MEMBER1_ID AND type = 'bn.new_follower';
```

## Known limitations

- Email delivery in LocalWP requires SMTP or a catch-all mail server (e.g. Mailpit); `wp_bn_email_log` is the reliable verification surface in dev.
- The `EmailDispatchListener` is registered at `plugins_loaded:15`. Hook ordering matters if other plugins interact with WordPress `wp_mail`.

## Automation notes

- All REST calls in this journey are curl-automatable.
- For email delivery testing, configure WP Mail Logging or Mailpit and assert the email was received.
- The digest email path (`email_freq = daily` / `weekly`) requires the WP cron tick or `wp cron event run buddynext_process_email_digests` to dispatch.
