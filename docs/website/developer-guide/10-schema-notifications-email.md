# Schema: Notifications and Email

Reference for the four tables that back BuddyNext's notification and email subsystem: `bn_notifications`, `bn_notification_prefs`, `bn_email_templates`, and `bn_email_log`. All four are created by `BuddyNext\Core\Installer` via `dbDelta()` and live in the site table prefix (shown below as `wp_`). This page is for developers reading, extending, or writing against these tables directly.

![The notifications inbox backed by the bn_notifications and preference tables this schema page covers](../images/notifications.webp)

## Overview / Contract

The notification and email subsystem follows the one-channel-plus-preference model documented in the notification contract:

- Every event fans in through `NotificationService::create()`, which inserts one `bn_notifications` row and fires `do_action('buddynext_notification_created', ...)`.
- In-app delivery is the `bn_notifications` row itself, read back through the bell endpoint.
- Email delivery is a separate listener that is gated by the recipient's `bn_notification_prefs` row for that notification `type` (the `email_freq` column).
- Email copy comes from `bn_email_templates`, keyed by template `type`. A composed email (campaign / drip) can pass its own subject and body and bypass the template row entirely.
- `bn_email_log` records what was actually sent, so digest batching and per-period dedup can be enforced.

Two design notes that shaped the schema:

- **Group aggregation on `bn_notifications`.** Rather than show fifteen separate "X reacted to your post" rows, the table carries `group_key` + `group_count` so repeat events on the same object collapse into a single bell row with an incrementing counter. See the column notes below.
- **Per-type, per-channel preferences.** `bn_notification_prefs` is keyed by `(user_id, type)` and stores one on-site flag plus one email-frequency enum per type, so a member can mute one notification category on one channel without affecting the rest.

## `bn_notifications`

One row per in-app notification. The primary read path is the bell: notifications for a recipient, newest first, filtered by read state.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `recipient_id` | `BIGINT UNSIGNED NOT NULL` | The user who receives the notification. |
| `sender_id` | `BIGINT UNSIGNED NULL` | The user who triggered it. Null for system notifications. |
| `type` | `VARCHAR(64) NOT NULL` | Notification type slug, e.g. `bn.new_follower`, `bn.post_reacted`, `bn.mention`. Matches the email template `type` and the preference `type`. |
| `object_type` | `VARCHAR(32) NULL` | The kind of object the notification points at, e.g. `post`, `comment`, `space`. |
| `object_id` | `BIGINT UNSIGNED NULL` | The id of that object, used to build the deep link. |
| `group_key` | `VARCHAR(128) NULL` | Aggregation key. Events that should collapse into one bell row share a `group_key` (typically `type` + `object`). Null means the row never aggregates. |
| `group_count` | `INT UNSIGNED NOT NULL DEFAULT 1` | How many underlying events this row represents. Incremented in place when a new event matches an existing unread row with the same `group_key`, so "5 people reacted to your post" is one row, not five. |
| `data` | `JSON NULL` | Free-form payload for rendering (actor names, snippet text, extra ids). |
| `is_read` | `TINYINT(1) NOT NULL DEFAULT 0` | Read state. Drives the unread bell count. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Creation time; also the bell sort key. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `bell` | `recipient_id, is_read, created_at` | The bell query: a recipient's notifications, filtered by read state, ordered by recency. |
| `recipient_group` | `recipient_id, group_key` | Find an existing aggregatable row for a recipient when deciding whether to increment `group_count` instead of inserting. |

### Relationships

- `recipient_id` and `sender_id` reference WordPress users (`wp_users.ID`); not enforced by a foreign key.
- `object_type` + `object_id` is a polymorphic pointer to a BuddyNext object (a `bn_posts` row, a `bn_comments` row, a `bn_spaces` row, and so on), resolved at render time.
- `type` ties a row to its `bn_email_templates` row and the recipient's `bn_notification_prefs` row of the same `type`.

## `bn_notification_prefs`

Per-user, per-type delivery preferences. One row stores the on-site and email choices for a single notification type for a single user. The absence of a row means defaults apply.

| Column | Type | Notes |
|---|---|---|
| `user_id` | `BIGINT UNSIGNED NOT NULL` | The member whose preference this is. Part of the primary key. |
| `type` | `VARCHAR(64) NOT NULL` | The notification type this preference governs, e.g. `bn.new_follower`. Part of the primary key. Matches `bn_notifications.type`. |
| `on_site` | `TINYINT(1) NOT NULL DEFAULT 1` | Whether the in-app (bell) channel is enabled for this type. |
| `email_freq` | `ENUM('immediate','daily','weekly','off') NOT NULL DEFAULT 'immediate'` | The email channel cadence for this type. `off` suppresses email; `daily` / `weekly` route the event into the matching digest instead of an immediate send. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `user_id, type` | One preference row per (user, type). Doubles as the lookup key when the dispatcher checks whether to deliver. |

### Relationships

- `user_id` references `wp_users.ID`.
- `type` matches `bn_notifications.type` and `bn_email_templates.type`. The structure is deliberately per-type and per-channel: the on-site channel is a single flag, the email channel is a four-value frequency enum, and each type carries its own pair. A missing row means the member has never changed that type, so the dispatcher applies the seeded defaults.

## `bn_email_templates`

The catalog of email templates, one row per template `type`. Seeded on install with the built-in transactional, moderation, and digest templates (idempotent `INSERT IGNORE`, so customised rows are never overwritten on upgrade).

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `type` | `VARCHAR(64) NOT NULL` | Unique template slug, e.g. `welcome`, `email_verify`, `bn.new_follower`, `bn.daily_digest`. The lookup key when an event renders its email. |
| `subject` | `VARCHAR(255) NOT NULL` | Email subject line. Supports `{{token}}` placeholders, e.g. `{{site_name}}`. |
| `preview_text` | `VARCHAR(255) NULL` | Inbox preview / preheader text. |
| `body_html` | `LONGTEXT NOT NULL` | The HTML body. Supports `{{token}}` placeholders such as `{{user_name}}`, `{{action_url}}`, `{{unsubscribe_url}}`, and (for digests) `{{notification_list}}`. |
| `enabled` | `TINYINT(1) NOT NULL DEFAULT 1` | Whether this template's event email is sent. A disabled row suppresses its own event email but never a composed campaign or drip email. |
| `created_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | Creation time. |
| `updated_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` | Auto-updated on every edit. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `type` (UNIQUE) | `type` | One template per type; the lookup key for an event's email. The unique constraint is what makes the seeder's `INSERT IGNORE` idempotent. |

### Relationships

- `type` matches `bn_notifications.type` and `bn_notification_prefs.type` for event-driven emails (e.g. `bn.new_follower`). Some template types are not event notifications (e.g. `welcome`, `email_verify`, `bn.daily_digest`, `bn.bulk_invite`) and are rendered directly by their sender.

## `bn_email_log`

A record of emails actually sent, primarily so digest batching can be deduped per period and per type.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` AUTO_INCREMENT | Primary key. |
| `user_id` | `BIGINT UNSIGNED NOT NULL` | Recipient of the email. |
| `type` | `VARCHAR(64) NOT NULL` | The template / email type that was sent. |
| `digest_date` | `DATE NULL` | The period a digest covers. Null for non-digest (immediate) emails. Combined with `type` and `user_id`, this prevents sending the same daily/weekly digest twice. |
| `sent_at` | `DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` | When the email was sent. |

### Indexes

| Key | Columns | Purpose |
|---|---|---|
| `PRIMARY` | `id` | Row identity. |
| `user_type` | `user_id, type, digest_date` | Dedup / lookup: "has this user already been sent this email type for this period?" |

### Relationships

- `user_id` references `wp_users.ID`.
- `type` matches `bn_email_templates.type`.

## Notes / gotchas

- **Aggregation is unread-only in practice.** `group_count` increments while a matching unread row exists; once read, a fresh event generally starts a new row. Treat `group_count` as "events in this collapsed bell item", not a lifetime total.
- **A missing preference row is not "off".** `bn_notification_prefs` is sparse: no row means seeded defaults (`on_site = 1`, `email_freq = immediate`). Do not assume a member has opted out just because they have no row for a type.
- **Disabled template vs disabled channel are different switches.** `bn_email_templates.enabled = 0` turns off one event email globally; `bn_notification_prefs.email_freq = 'off'` turns off email for one type for one user. A composed campaign / drip email passes its own subject and body and is gated by neither.
- **`digest_date` is the dedup spine for digests.** Immediate emails leave it null; daily and weekly digests stamp the covered period so the same digest is never sent twice for the same window.
