# REST: Spaces

Reference for every Spaces REST route under `buddynext/v1` - space lifecycle (CRUD), membership and roles, join/leave/cancel, pending requests, invites, bans, ownership transfer, archive, permissions, avatar/cover images, per-space notification preference, the space feed, and the space-category routes. This page is for developers calling or extending the Spaces surface.

![A Space home driven by the Spaces REST routes - lifecycle, membership, feed, and category - documented on this page](../images/space-home.webp)

## Overview / Contract

All routes live under the `buddynext/v1` namespace. They follow the shared response envelope, authentication, and pagination rules described on the REST Contract page - read that first. In short:

- **Auth.** Routes marked `Public` use `__return_true` and need no authentication. Routes marked `Auth` require a logged-in user (cookie + `X-WP-Nonce`, or an application password). Routes marked `Owner/Mod` additionally check space ownership or `manage_options`. Routes marked `manage_options` require a site administrator.
- **Space types drive behaviour.** `open` spaces join immediately (`{"joined": true}`); `private` spaces create a pending request (`{"requested": true}`); `secret` spaces are invite-only and return `403` unless the caller holds a pending invite.
- **Pagination.** List routes accept `page` and `per_page`. `GET /spaces` caps `per_page` at 50 (default 12). Member and pending-request lists use the shared member-pagination args and return total counts in `X-WP-Total` / `X-WP-TotalPages` headers.
- **Bans are canonical on the plural route.** Space bans are served by the Moderation controller at `/spaces/{id}/bans`. The old singular `/ban` routes were removed.

> The Spaces surface spans two controllers: `SpaceController` (lifecycle, membership, images, preferences) and `ModerationController` (the three ban routes). The space feed is served by `FeedController`.

## Space lifecycle (CRUD)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/spaces` | Public | List spaces with filters/sort (`per_page` capped at 50, default 12). |
| POST | `/spaces` | Auth (space-creation role) | Create a space. Caller must hold a role allowed to create spaces. |
| GET | `/spaces/{id}` | Public | Get a single space by ID. |
| PUT | `/spaces/{id}` | Auth (owner) | Update name, description, type, and other settings. |
| DELETE | `/spaces/{id}` | Auth (owner) | Delete a space. |

The create route's permission callback is `require_space_creation_role`: the caller must be logged in and hold a role permitted to create spaces (configured on the Roles and Capabilities tab). Update and delete enforce owner/manage checks inside the service layer.

## Membership and roles

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/spaces/{id}/members` | Public | List members (paginated). |
| GET | `/spaces/{id}/pending-requests` | Auth (owner/mod) | List pending join requests (paginated). |
| POST | `/spaces/{id}/members/{user_id}/approve` | Auth (owner/mod) | Approve a pending join request. |
| POST | `/spaces/{id}/members/{user_id}/decline` | Auth (owner/mod) | Decline a pending join request. |
| POST | `/spaces/{id}/approve-request` | Auth (owner/mod) | Legacy approve route (kept for backwards compatibility). |
| PUT | `/spaces/{id}/members/{user_id}/role` | Auth (owner/mod) | Change a member's role within the space. |
| DELETE | `/spaces/{id}/members/{user_id}` | Auth (owner/mod) | Remove a member from the space. |
| POST | `/spaces/{id}/invite` | Auth (owner/mod) | Invite a user to the space. |
| POST | `/spaces/{id}/transfer-ownership` | Auth (owner) | Transfer ownership to another member. |
| POST | `/spaces/{id}/transfer` | Auth (owner) | Alias of transfer-ownership (used by the space-home action row). |

> `approve`, `decline`, `role`, `remove`, `invite`, and `transfer` use the `require_auth` permission callback; the owner/moderator check is enforced inside `SpaceMemberService` / `SpaceService` so a non-manager receives a `403` from the service rather than the gate.

## Join, leave, and request management

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/spaces/{id}/join` | Auth | Join (open) or request to join (private); invite-only for secret. |
| DELETE | `/spaces/{id}/join` | Auth | Leave the space (same handler as the leave route). |
| POST | `/spaces/{id}/leave` | Auth | Leave the space. |
| POST | `/spaces/{id}/join/cancel` | Auth | Withdraw a pending join request. |

Join outcomes by space type:

- **Open** - membership becomes active immediately. Response: `{"joined": true}`.
- **Private** - a pending request is created. Response: `{"requested": true}`.
- **Secret** - `403` unless the caller already has a pending `invited` status, in which case the invite is accepted and the response is `{"joined": true}`.

## Bans

Served by `ModerationController`. The permission callback `require_space_owner_or_admin` requires the caller to be logged in and either a site administrator (`manage_options`) or the space owner/manager.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/spaces/{id}/bans` | Auth (owner/admin) | List banned users for the space (ordered by `created_at`). |
| POST | `/spaces/{id}/bans` | Auth (owner/admin) | Ban a user from the space. Body: `user_id` (required), `reason` (optional). |
| DELETE | `/spaces/{id}/bans/{user_id}` | Auth (owner/admin) | Lift a user's ban from the space. |

## Archive and settings

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/spaces/{id}/archive` | Auth (owner/admin) | Archive the space. |
| DELETE | `/spaces/{id}/archive` | Auth (owner/admin) | Restore (unarchive) the space. |
| PUT | `/spaces/{id}/permissions` | Auth (owner) | Update permission-only settings (for example `require_join_approval`). |
| GET | `/spaces/{id}/notification-pref` | Auth | Get the current user's per-space notification preference. |
| POST | `/spaces/{id}/notification-pref` | Auth | Set the current user's per-space notification preference (`pref`). |

The owner/admin check for archive lives inside `SpaceService::archive()`; the route only requires authentication to reach it. `update_permissions` re-checks `buddynext-manage-space` for the caller and stores each flag as a `bn_space_{id}_{key}` option.

## Images

Avatar and cover uploads are multipart and routed through the image storage service. They produce per-owner WebP variations on disk (not WordPress attachments); `DELETE` removes the stored files and clears the column.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/spaces/{id}/avatar` | Auth (owner) | Upload the space avatar (icon). |
| DELETE | `/spaces/{id}/avatar` | Auth (owner) | Remove the space avatar. |
| POST | `/spaces/{id}/cover` | Auth (owner) | Upload the space cover image. |
| DELETE | `/spaces/{id}/cover` | Auth (owner) | Remove the space cover image. |

## Space feed

Served by `FeedController`.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/spaces/{id}/feed` | Public | Return the activity feed for a space. |

> A space forum is not a separate Free REST route. Discussions surface through the activity feed and through the Jetonomy bridge (discussion-type items) when that companion is active; there is no dedicated `/spaces/{id}/forum` endpoint in Free.

## Space categories

Served by `SpaceCategoryController`. Categories are a site-wide taxonomy for organising the spaces directory.

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/space-categories` | Public | List all categories ordered by `sort_order`. |
| POST | `/space-categories` | manage_options | Create a category. |
| PUT | `/space-categories/{id}` | manage_options | Edit a category. |
| DELETE | `/space-categories/{id}` | manage_options | Delete a category (returns `409` if any space uses it). |

## Examples

### Create a space

```bash
curl -X POST https://example.com/wp-json/buddynext/v1/spaces \
  -H "Content-Type: application/json" \
  -H "X-WP-Nonce: <nonce>" \
  --cookie "<auth cookies>" \
  -d '{
    "name": "Photography Club",
    "slug": "photography-club",
    "type": "open",
    "description": "Share shots, lenses, and edits.",
    "category_id": 3
  }'
```

Accepted body fields: `name` (required, 100 chars max), `slug` (optional - derived from `name` when omitted), `type` (`open` / `private` / `secret`, default `open`), `description` (optional, 160 chars max), `category_id` (optional), `parent_id` (optional - creates a sub-space; the caller must manage the parent).

A successful create returns `201` with the full space object:

```json
{
  "id": 42,
  "name": "Photography Club",
  "slug": "photography-club",
  "type": "open",
  "description": "Share shots, lenses, and edits.",
  "member_count": 1,
  "category_id": 3,
  "parent_id": 0
}
```

Validation failures return `422` with a `params` map, for example:

```json
{
  "code": "rest_invalid_param",
  "message": "Validation failed.",
  "data": {
    "status": 422,
    "params": { "slug": "This slug is already in use." }
  }
}
```

### Join a space

```bash
curl -X POST https://example.com/wp-json/buddynext/v1/spaces/42/join \
  -H "X-WP-Nonce: <nonce>" \
  --cookie "<auth cookies>"
```

Response for an open space:

```json
{ "joined": true }
```

Response for a private space (a pending request is created):

```json
{ "requested": true }
```

For a secret space without a pending invite, the response is `403`.

## Notes and gotchas

- **Two `leave` paths exist.** `DELETE /spaces/{id}/join` and `POST /spaces/{id}/leave` both call the same leave handler; pick whichever fits your client.
- **`transfer` and `transfer-ownership` are equivalent**, as are the `members/{user_id}/approve` route and the legacy `approve-request` route. New clients should prefer the spec-conformant `transfer` and `members/{user_id}/approve` forms.
- **Owner/moderator enforcement for membership writes happens in the service layer**, not the route gate. The gate only checks that the caller is authenticated; a non-manager still receives a `403` from the service.
- **Bans are owned by Moderation.** Do not look for ban routes on `SpaceController` - they live in `ModerationController` under the plural `/bans` path.
- **Pro filters on the directory.** Advanced member/space filtering is layered in by buddynext-pro via REST filter seams; Free ignores Pro-only parameters when Pro is inactive.
