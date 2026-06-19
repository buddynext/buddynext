# REST: Members and Profiles

This page documents the largest REST surface in BuddyNext: the self-service `/me/*` routes, the per-user `/users/{id}/*` routes, profile-field and profile-group administration, member types, profile-slug checks, and the member directory. The `/me/*` and `/users/{id}/*` groups together account for roughly 63 routes under `buddynext/v1`. This page covers everything except the social-graph relationship routes (follow, connect, block, mute) that happen to live on the same `/me/*` and `/users/{id}/*` paths - those are documented on the REST: Social Graph page. Read the REST Contract page first; everything here assumes its namespace, nonce auth, envelope, and pagination rules.

## Overview / Contract

All routes register under `buddynext/v1`. Authentication, the success/error envelope, and cursor pagination follow the REST Contract page without exception:

| Rule | Value |
|---|---|
| Namespace | `buddynext/v1` |
| Auth | `X-WP-Nonce` header (cookie session) or Application Password (external) |
| Self routes | `/me/*` - operate on the authenticated caller; require login |
| Target routes | `/users/{id}/*` - act on a specific user; public reads or admin writes |
| Error body | `{ "code": "...", "message": "...", "data": { "status": N } }` |
| `per_page` max | 50 on directory and collection reads |

Permission callbacks fall into a few classes used throughout this surface:

| Callback | Meaning |
|---|---|
| `__return_true` | Public read; visibility is still enforced per row by the service layer |
| `require_auth` | Caller must be logged in (own `/me/*` data) |
| `require_admin` | Site admin (or a role granted the matching capability) |
| `require_edit_any_profile` | Resolves `buddynext-profile/edit-any` through the role map |
| `can_set_user_type` | Self-assignable types, or admin for any user |

> **Note:** Public-read routes (`__return_true`) do not return everything to everyone. The Profile, Directory, and Member-Type services apply per-viewer privacy gates (profile visibility, directory opt-out, blocked/restricted relationships) before a row is serialized.

## /me/* - the self-service surface

Every route below operates on `get_current_user_id()` and requires a logged-in caller. The user ID is never in the path.

### Profile, avatar, and cover

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/me/profile` | require_auth | Read the caller's own profile (all groups, fields, completion score) |
| PUT | `/me/profile` | require_auth | Update the caller's profile fields, display name, privacy, and notification prefs |
| GET | `/me/profile-slug` | require_auth | Read the caller's current profile slug |
| PUT | `/me/profile-slug` | require_auth | Change the caller's profile slug (`slug`, sanitized via `sanitize_title`) |
| POST | `/me/avatar` | require_auth | Upload the caller's avatar |
| DELETE | `/me/avatar` | require_auth | Remove the caller's avatar (revert to default) |
| POST | `/me/cover` | require_auth | Upload the caller's cover image |
| DELETE | `/me/cover` | require_auth | Remove the caller's cover image |

### Drafts, bookmarks, and shares

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/me/drafts` | require_auth | List the caller's saved composer drafts |
| POST | `/me/drafts` | require_auth | Save a new composer draft |
| GET | `/me/bookmarks` | require_auth | List the caller's bookmarked posts (gated by `buddynext_allow_bookmarks`) |
| GET | `/me/shares` | require_auth | List posts the caller has shared |

### Blocked, muted, and restricted lists

These are read-only list views of the caller's own social-graph state. The write actions that populate them (`POST /users/{id}/block`, `/mute`, `/restrict`) are on the Social Graph page.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/me/blocked` | require_auth | List users the caller has blocked |
| GET | `/me/muted` | require_auth | List users the caller has muted |
| GET | `/me/restricted` | require_auth | List users the caller has restricted |

### Onboarding and presence

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/me/onboarding/step` | require_auth | Persist progress for one onboarding step |
| POST | `/me/onboarding/skip` | require_auth | Skip the onboarding wizard |
| POST | `/me/onboarding/complete` | require_auth | Mark onboarding complete (fires `buddynext_onboarding_completed`) |
| POST | `/me/presence/heartbeat` | require_auth | Refresh the caller's `bn_last_active` stamp for online/presence |

### Social login providers

| Method | Path | Auth | Purpose |
|---|---|---|---|
| DELETE | `/me/social/{provider}` | is_user_logged_in | Unlink a connected social-login provider; `{provider}` matches `[a-z0-9_-]+` |

### Notification and space-notification preferences

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/me/notifications` | require_auth | List the caller's in-app notifications |
| GET | `/me/notifications/unread-count` | require_auth | Unread notification count |
| PUT | `/me/notifications/read-all` | require_auth | Mark every notification read |
| PUT | `/me/notifications/{id}/read` | require_auth | Mark one notification read |
| DELETE | `/me/notifications/{id}` | require_auth | Delete one notification |
| GET | `/me/notification-prefs` | require_auth | Read per-type notification preferences |
| GET | `/me/notification-channels` | require_auth | Read per-channel delivery preferences (in-app, email, push) |
| GET | `/me/space-notification-prefs` | require_auth | Read per-space notification overrides |

> **Note:** `/me/notification-prefs`, `/me/notification-channels`, and `/me/space-notification-prefs` are documented here as the read endpoints. Preference writes for these surfaces are submitted through the profile/account save flow (`PUT /me/profile` for the email/digest toggles) and the notification-channel handlers; see the Notifications schema page for the underlying `bn_notification_prefs` storage.

### Account: 2FA, password, email, and sessions

Two-factor lives under `/account/2fa/*`; password, email, and session controls live under `/auth/*`. Both groups require a logged-in caller.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/account/2fa` | login | Read the caller's 2FA status |
| POST | `/account/2fa/setup` | login | Begin TOTP setup (returns secret/QR provisioning data) |
| POST | `/account/2fa/confirm` | login | Confirm setup with a verification code |
| POST | `/account/2fa/disable` | login | Disable 2FA on the account |
| POST | `/account/2fa/backup` | login | Regenerate backup codes |
| POST | `/auth/change-password` | require_auth | Change password (`current_password`, `new_password`) |
| POST | `/auth/change-email` | require_auth | Change account email (`email`) |
| POST | `/auth/sign-out-everywhere` | require_auth | Destroy all of the caller's other sessions |

> **Note:** `/auth/2fa` and `/auth/2fa/email-code` (under the auth namespace, permission `__return_true`, gated by `users_can_register`) are part of the login challenge flow, not account management. They are documented on the REST: Auth page.

### Appeals and self-service privacy

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/me/appeals` | require_auth | Submit an appeal against a moderation action (fires `buddynext_appeal_submitted`) |
| GET | `/me/data-export` | require_auth | Download the caller's own data (gated by `buddynext_allow_data_export`, per-user cooldown) |
| DELETE | `/me/account` | require_auth | Self-delete the caller's account |

## /users/{id}/* - routes that target a specific user

`{id}` matches `[\d]+`. Reads here are public (privacy-gated per row); writes are administrative.

### Profile view and admin media

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/users/{id}/profile` | public | View a user's profile (privacy-gated for the current viewer) |
| PUT | `/users/{id}/profile` | require_edit_any_profile | Admin edit of another user's profile fields |
| POST | `/users/{id}/avatar` | require_edit_any_profile | Admin upload of a user's avatar |
| DELETE | `/users/{id}/avatar` | require_edit_any_profile | Admin removal of a user's avatar |
| POST | `/users/{id}/cover` | require_edit_any_profile | Admin upload of a user's cover image |
| DELETE | `/users/{id}/cover` | require_edit_any_profile | Admin removal of a user's cover image |
| GET | `/users/{id}/feed` | public | A user's own post timeline (gated by `buddynext_public_explore`) |

### Moderation actions targeting a user

These are administrative moderation routes (permission resolves through the moderation capability). The member-facing counterpart is `POST /me/appeals` above.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/users/{id}/strikes` | moderator | List a user's strikes |
| POST | `/users/{id}/strikes/{sid}/reverse` | moderator | Reverse a specific strike |
| POST | `/users/{id}/suspend` | moderator | Suspend a user (reason, duration, content visibility) |
| GET | `/users/{id}/suspension` | moderator | Read a user's active suspension |
| GET | `/users/{id}/suspensions` | moderator | List a user's suspension history |
| POST | `/users/{id}/warn` | moderator | Issue a warning (fires `buddynext_user_warned`) |
| GET | `/users/{id}/warnings` | moderator | List a user's warnings |
| POST | `/users/{id}/shadow-ban` | moderator | Shadow-ban a user |

> **Note:** The relationship and trust actions that also live on `/users/{id}/*` - `block`, `mute`, `restrict`, `connect` (+ accept/decline), `follow`, `followers`, `following`, `connection/status`, `mutual-connections`, `account-type` - are member-driven social-graph routes, not moderation. They are documented in full on the REST: Social Graph page.

## Profile fields and groups (CRUD + reorder)

Profile groups contain fields. Lists are public; all writes require `require_admin`. Reorder uses a `direction` enum (`up`/`down`) rather than absolute positions, so concurrent reorders stay consistent.

### Groups

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/profile-groups` | public | List all group definitions (metadata only) |
| POST | `/profile-groups` | require_admin | Create a group (`group_key`, `label`, `type`, `visibility`, `sort_order`) |
| PUT | `/profile-groups/{id}` | require_admin | Update a group (`label`, `visibility`, `sort_order`) |
| DELETE | `/profile-groups/{id}` | require_admin | Delete a group |
| POST | `/profile-groups/{id}/reorder` | require_admin | Move a group up or down (`direction`) |

### Fields

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/profile-fields` | public | List all field definitions |
| POST | `/profile-fields` | require_admin | Create a field (`group_id`, `field_key`, `label`, `type`, `is_required`, `sort_order`) |
| PUT | `/profile-fields/{id}` | require_admin | Update a field (`label`, `type`, `options`, `is_required`, `visibility`, `sort_order`) |
| DELETE | `/profile-fields/{id}` | require_admin | Delete a field |
| POST | `/profile-fields/{id}/reorder` | require_admin | Move a field up or down (`direction`) |

## Member types (CRUD + assignment)

Type definitions are public to read and admin to write. Assignment to a user is admin for any user, or self-assignable types via `can_set_user_type`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/member-types` | public | List all member-type definitions |
| POST | `/member-types` | require_admin | Create a member type |
| PUT | `/member-types/{slug}` | require_admin | Update a member type; `{slug}` matches `[a-z0-9-]+` |
| DELETE | `/member-types/{slug}` | require_admin | Delete a member type |
| GET | `/users/{id}/member-type` | public | Read a user's assigned type |
| PUT | `/users/{id}/member-type` | can_set_user_type | Assign a type to a user (`type_slug`; fires `buddynext_member_type_assigned`) |
| DELETE | `/users/{id}/member-type` | require_admin | Remove a user's type (fires `buddynext_member_type_removed`) |

## Profile-slug check and member directory

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/profile-slug/check` | require_auth | Check whether a slug is available (`slug`) |
| GET | `/members` | public | Paginated member directory |
| GET | `/search/members` | public | Member search (see REST: Search page) |

`GET /members` accepts these query parameters:

| Param | Type | Default | Notes |
|---|---|---|---|
| `search` | string | `""` | Free-text name/handle search |
| `sort` | string | `newest` | One of `newest`, `alphabetical`, `most_active`, `online` |
| `relation` | string | `all` | One of `all`, `following`, `connections` (viewer-relative) |
| `member_type` | string | `""` | Filter by member-type slug |
| `location` | string | `""` | Filter by location field |
| `online` | boolean | `false` | Only members currently online |
| `cursor` | string | `""` | Opaque cursor from the previous page's `next_cursor` |
| `per_page` | integer | `20` | Clamped to a hard maximum of 50 |

> **Note:** `relation=following` and `relation=connections` are applied inside the directory query (a JOIN on `bn_follows` / connections), so the total count and cursor reflect the filtered set. Do not post-filter directory rows on the client.

## Examples

### Update my profile

`PUT /me/profile` accepts a flat JSON object of `field_key => value`. Core fields (`display_name`), profile fields (`headline`, `bio`, `location`, `website`, `social_*`), privacy gates, and notification toggles can all be sent in one call. Unknown keys are ignored. URL fields are accepted without a protocol (https is prefixed). A clean save returns 200; field-level validation failures return 422 with an `errors` map the client can render inline.

```bash
curl -X PUT 'https://example.com/wp-json/buddynext/v1/me/profile' \
  -H 'X-WP-Nonce: <nonce>' \
  -H 'Content-Type: application/json' \
  --data '{
    "display_name": "Ada Lovelace",
    "headline": "Mathematician and writer",
    "bio": "Working on the Analytical Engine.",
    "location": "London",
    "website": "ada-lovelace.example",
    "social_github": "https://github.com/ada",
    "bn_privacy_profile_visibility": "followers",
    "bn_pref_email_digest": true
  }'
```

Success response (200):

```json
{
  "saved": true,
  "errors": [],
  "profile": {
    "user_id": 12,
    "completion": 80,
    "groups": [],
    "fields": {
      "headline": "Mathematician and writer",
      "bio": "Working on the Analytical Engine.",
      "location": "London",
      "website": "https://ada-lovelace.example"
    }
  }
}
```

Validation-failure response (422):

```json
{
  "saved": false,
  "errors": {
    "website": "Enter a valid URL (https://example.com).",
    "display_name": "Display name is required."
  }
}
```

### Create a profile field

`POST /profile-fields` requires `require_admin`. `group_id`, `field_key`, and `label` are required; `type`, `is_required`, and `sort_order` default as shown. The create returns 201 with the new field ID.

```bash
curl -X POST 'https://example.com/wp-json/buddynext/v1/profile-fields' \
  -H 'X-WP-Nonce: <nonce>' \
  -H 'Content-Type: application/json' \
  --data '{
    "group_id": 1,
    "field_key": "favorite_language",
    "label": "Favorite programming language",
    "type": "text",
    "is_required": false,
    "sort_order": 5
  }'
```

Success response (201):

```json
{ "id": 42 }
```

## Notes / gotchas

- Social-graph routes live on these same paths. `/me/blocked`, `/me/muted`, `/me/restricted`, `/me/connections`, `/me/connection-requests`, `/me/follow-requests*`, and the `/users/{id}/` actions for `block`, `mute`, `restrict`, `connect`, `follow`, `followers`, `following`, `connection/status`, `mutual-connections`, and `account-type` are detailed on the REST: Social Graph page. They are grouped there because they share the social-graph services and hooks, even though their paths sit under `/me/*` and `/users/{id}/*`.
- Public reads are privacy-gated, not open. A `__return_true` permission callback means the route is reachable without login; it does not mean every row is returned. Profile visibility, directory opt-out, and blocked/restricted relationships are enforced row-by-row in the service layer.
- Reorder is relative. Field and group reorder take a `direction` enum (`up`/`down`), not an absolute index, so two admins reordering at once cannot corrupt the order.
- Profile writes are split by storage. `PUT /me/profile` routes core fields to `wp_update_user`, privacy and notification keys to user meta (through PrivacyService for the gate keys, so `buddynext_privacy_preference_changed` fires), and everything else to profile-value rows - all in one request.
- Free vs Pro. Every route on this page is Free (`buddynext/v1`). Pro adds advanced field renderers and membership-driven profile surfaces under `buddynext-pro/v1`; it reads Free profile data through the Profile service rather than re-registering these routes.
