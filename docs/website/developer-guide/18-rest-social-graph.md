# REST: Social Graph

This page documents the social-graph REST surface in BuddyNext free: following, connections (mutual/two-way relationships), and the block/mute/restrict privacy controls. All routes live under the `buddynext/v1` namespace and are registered by `FollowController`, `ConnectionController`, and `BlockController` in `includes/SocialGraph/`.

## Overview / Contract

- Base namespace: `buddynext/v1`. Full base URL: `/wp-json/buddynext/v1`.
- Authentication is cookie + nonce (`X-WP-Nonce`) for first-party requests, or any standard WordPress REST auth (Application Passwords, etc.). Routes marked **Public** use `__return_true` and need no auth. Routes marked **Auth** require a logged-in user (`require_auth`).
- All write routes apply a capability gate in addition to the auth check: follow/unfollow gate on `buddynext-connections/follow`, connect/withdraw gate on `buddynext-connections/connect`. A user whose role is denied the capability gets a `403` even when logged in.
- Block state is enforced on relationship writes: following or connecting with a user who blocks (or is blocked by) you returns `403 buddynext_blocked`.
- Targeting a non-existent user id returns `404 buddynext_user_not_found`.
- List routes (`/me/connections`, `/me/connection-requests`, followers, following) accept `page` and `per_page` query args and paginate server-side.

See the REST contract page (`14-rest-contract`) for the shared envelope, pagination headers, error shape, and nonce handling that apply to every route below.

## Follow routes

Follow is a one-directional relationship. When the target account is private (account type gated), a follow becomes a pending follow-request the target approves or rejects.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/users/{id}/follow` | Auth (cap `follow`) | Follow the user, or create a pending follow-request for a private account. |
| DELETE | `/users/{id}/follow` | Auth (cap `follow`) | Unfollow the user (or cancel a pending request). |
| GET | `/users/{id}/followers` | Public | List the user's followers (paginated). |
| GET | `/users/{id}/following` | Public | List who the user follows (paginated). |
| GET | `/users/{id}/follow/status` | Auth | The current user's follow state with this user (following / pending / none). |
| GET | `/users/{id}/account-type` | Auth | The target's account type (public/private), so the UI can decide follow vs request. |
| GET | `/follow-suggestions` | Auth | Suggested accounts to follow for the current user. |
| GET | `/me/follow-requests` | Auth | Incoming follow-requests awaiting the current user's approval. |
| GET | `/me/follow-requests/count` | Auth | Count of pending incoming follow-requests (for badges). |
| POST | `/me/follow-requests/{follower_id}/approve` | Auth | Approve an incoming follow-request. |
| POST | `/me/follow-requests/{follower_id}/reject` | Auth | Reject an incoming follow-request. |

## Connection routes

A connection is a mutual, two-sided relationship (request, then accept). An optional note (capped at 280 characters, tags stripped) can accompany the request.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/users/{id}/connect` | Auth (cap `connect`) | Send a connection request. Optional `note` body field. |
| DELETE | `/users/{id}/connect` | Auth (cap `connect`) | Withdraw a connection request you sent. |
| POST | `/users/{id}/connect/accept` | Auth | Accept an incoming connection request from this user. |
| POST | `/users/{id}/connect/decline` | Auth | Decline an incoming connection request from this user. |
| GET | `/users/{id}/connection/status` | Auth | The current user's connection status with this user. |
| GET | `/users/{id}/mutual-connections` | Auth | User ids connected to both the viewer and this user. |
| GET | `/me/connections` | Auth | The current user's connections (paginated; `page`, `per_page`). |
| GET | `/me/connection-requests` | Auth | Incoming connection requests awaiting the current user (paginated). |

> **Note:** Connection requests are approved or declined through the per-peer `/users/{id}/connect/accept` and `/users/{id}/connect/decline` routes. There are no separate `/me/connection-requests/{id}/approve|reject` endpoints; the peer id in the connect path is the actor on the other side of the request.

## Block, mute, and restrict routes

These are the per-user privacy controls. Block is mutual-cut (neither party sees the other), mute hides someone's content from you only, and restrict limits their interaction with you. Each control has a POST (apply) and DELETE (remove) on the same path, plus a list route.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/users/{id}/block` | Auth | Block the user. |
| DELETE | `/users/{id}/block` | Auth | Unblock the user. |
| POST | `/users/{id}/mute` | Auth | Mute the user (hide their content from you). |
| DELETE | `/users/{id}/mute` | Auth | Unmute the user. |
| POST | `/users/{id}/restrict` | Auth | Restrict the user. |
| DELETE | `/users/{id}/restrict` | Auth | Remove the restriction. |
| GET | `/me/blocked` | Auth | List users the current user has blocked. |
| GET | `/me/muted` | Auth | List users the current user has muted. |
| GET | `/me/restricted` | Auth | List users the current user has restricted. |

## Examples

### Follow a user

```bash
curl -X POST "https://example.com/wp-json/buddynext/v1/users/42/follow" \
  -H "X-WP-Nonce: <wp_rest_nonce>" \
  --cookie "<auth cookies>"
```

Response when the target is a public account (followed immediately):

```json
{
  "following": true,
  "pending": false
}
```

Response when the target is private (a pending follow-request was created):

```json
{
  "following": false,
  "pending": true
}
```

A blocked relationship returns:

```json
{
  "code": "buddynext_blocked",
  "message": "You cannot follow this user.",
  "data": { "status": 403 }
}
```

### Send a connection request

```bash
curl -X POST "https://example.com/wp-json/buddynext/v1/users/42/connect" \
  -H "X-WP-Nonce: <wp_rest_nonce>" \
  -H "Content-Type: application/json" \
  --cookie "<auth cookies>" \
  -d '{ "note": "We met at the community meetup - would like to connect." }'
```

Response:

```json
{
  "status": "pending"
}
```

The recipient then accepts with `POST /users/{your_id}/connect/accept` or declines with `POST /users/{your_id}/connect/decline`.

## Notes / gotchas

- **Capability gates are symmetric.** Unfollow is gated on the same `follow` capability as follow, and withdraw on the same `connect` capability as connect, so a role denied the action cannot mutate state in either direction.
- **Block precedence.** `is_blocking_either()` is checked before any follow or connect write; a block by either party short-circuits the relationship with `403 buddynext_blocked`.
- **Public vs Auth listing.** Follower/following lists and follow-suggestions reflect public-graph reads; `/users/{id}/followers` and `/users/{id}/following` are public, while `/follow-suggestions` is per-user and requires auth.
- **Account type drives the UI flow.** Read `/users/{id}/account-type` (or the `pending` flag in the follow response) to decide whether to show "Follow" or "Request to follow".
- **Pagination.** List routes accept `page` (default 1) and `per_page` (default 20 for connections/requests) and return paginated results; do not assume the full set in one call.
