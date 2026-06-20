# REST: Notifications

Reference for the notification routes under the `buddynext/v1` namespace: the in-app inbox, unread badge, read-state changes, deletion, and the three preference surfaces (per-type, master channels, and per-space). These are the routes an app or custom bell widget uses.

![The notifications inbox and bell driven by the notification REST routes documented here](../images/notifications.png)

## Contract

All routes in this page live under the `buddynext/v1` namespace and follow the conventions described in the BuddyNext REST Contract page (envelope, authentication, nonce, and cursor pagination). Read that page first; this page documents only the routes and their request/response shapes.

In short:

- Every route here requires an authenticated user (`require_auth`). A guest receives `401 rest_not_logged_in`.
- All routes are scoped to the current user (`/me/...`). There is no admin route to read another user's notifications.
- The list endpoint uses cursor pagination via `?cursor=` and `?per_page=` (max 50).
- The read-state routes accept `PUT` (canonical) and also `POST` (kept for backwards compatibility).

> **Note:** The `Auth` column below reflects the route's `permission_callback`. Every route resolves to `auth` (an authenticated user). Finer scoping (you can only act on your own notifications, you must be a member of a space to set its preference) is enforced inside the handler and the service layer.

## Routes

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/me/notifications` | auth | List the current user's notifications, each enriched with a rendered message, link, icon, tone, and label. Accepts `?cursor=` and `?per_page=` (max 50). |
| GET | `/me/notifications/unread-count` | auth | Unread count for the bell badge. |
| PUT | `/me/notifications/read-all` | auth | Mark all of the current user's notifications read. Also accepts `POST`. |
| PUT | `/me/notifications/(?P<id>[\d]+)/read` | auth | Mark one notification read. Also accepts `POST`. |
| DELETE | `/me/notifications/(?P<id>[\d]+)` | auth | Delete one of the current user's notifications. |
| GET | `/me/notification-prefs` | auth | Per-type preferences, with catalogue defaults merged so every type appears once. |
| PUT | `/me/notification-prefs` | auth | Update per-type preferences. Body keyed by type, values `{ on_site, email_freq }`. Returns `422` on an invalid `email_freq`. |
| GET | `/me/notification-channels` | auth | Master channel switches (`in_app`, `email`, `push`, `sound`) plus `push_available`. |
| PUT | `/me/notification-channels` | auth | Update master channel switches. |
| GET | `/me/space-notification-prefs` | auth | One row per active space membership with the resolved preference (defaults to `all`). |
| POST | `/me/space-notification-prefs` | auth | Set the current user's preference for one space. Body: `space_id` and `pref` (one of `all`, `mentions_only`, `none`). Non-members get `403`. |

> **Note:** There is no `/spaces/{id}/notification-pref` route. Per-space notification preferences are written through `POST /me/space-notification-prefs` with `space_id` in the body, so they sit under the `/me/...` user scope alongside the other preference routes.

### Preference value reference

| Surface | Field | Allowed values |
|---|---|---|
| Per-type (`/me/notification-prefs`) | `on_site` | boolean |
| Per-type (`/me/notification-prefs`) | `email_freq` | `immediate`, `daily`, `weekly`, `off` |
| Channels (`/me/notification-channels`) | `in_app`, `email`, `push`, `sound` | boolean |
| Per-space (`/me/space-notification-prefs`) | `pref` | `all`, `mentions_only`, `none` |

When the Pro push module is not loaded, `push_available` is `false` and the `push` channel defaults off so the client can hide the row without a separate feature-flag request.

## Examples

### List notifications

```bash
curl 'https://example.com/wp-json/buddynext/v1/me/notifications?per_page=20' \
  -H 'X-WP-Nonce: <nonce>' \
  --cookie 'wordpress_logged_in_...=<cookie>'
```

The response wraps the raw rows with a presentation layer (`message`, `url`, `icon`, `tone`, `label`, `actor_name`) so the in-app dropdown, mobile app, and email tokens all render the same way, plus a cursor for the next page:

```json
{
  "items": [
    {
      "id": 9051,
      "user_id": 12,
      "type": "bn.new_follower",
      "actor_id": 47,
      "is_read": 0,
      "created_at": "2026-06-20 08:55:11",
      "message": "Priya started following you.",
      "url": "https://example.com/members/priya/",
      "icon": "user-plus",
      "tone": "info",
      "label": "New follower",
      "actor_name": "Priya"
    }
  ],
  "next_cursor": "eyJpZCI6OTA1MX0",
  "has_more": false
}
```

### Update a per-type preference

```bash
curl -X PUT 'https://example.com/wp-json/buddynext/v1/me/notification-prefs' \
  -H 'X-WP-Nonce: <nonce>' \
  -H 'Content-Type: application/json' \
  --cookie 'wordpress_logged_in_...=<cookie>' \
  -d '{ "bn.new_follower": { "on_site": true, "email_freq": "daily" } }'
```

A valid update returns the resolved preference set (catalogue defaults merged with the stored rows) and a server timestamp. An invalid `email_freq` returns `422 invalid_email_freq` with a `params` map naming the offending type(s).

## Notes

- The list route hydrates each row through the notification message composer; clients should render `message`/`url`/`icon`/`tone`/`label` directly rather than re-deriving copy from `type`.
- Read-state and delete routes only ever touch the current user's own rows; passing another user's notification id returns a `WP_Error` from the service rather than mutating it.
- Free vs Pro: all routes on this page are in the free plugin. Pro's push module adds a delivery channel (surfaced via the `push` channel and `push_available` flag) but registers no additional routes here.
