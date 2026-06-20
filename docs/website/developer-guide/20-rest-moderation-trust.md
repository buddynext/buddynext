# REST: Moderation and Trust

This page documents the moderation REST surface in BuddyNext free: member reports, the moderation queue, appeals, and the per-user trust actions (warnings, strikes, shadow-bans, suspensions, account type). All routes live under the `buddynext/v1` namespace and are registered by `ModerationController` in `includes/Moderation/`.

![The moderation queue driven by the report, queue, appeal, and trust-action REST routes on this page](../images/moderation-queue.webp)

## Overview / Contract

- Base namespace: `buddynext/v1`. Full base URL: `/wp-json/buddynext/v1`.
- Three permission tiers gate these routes:
  - **Auth** (`require_auth`) - any logged-in user. Used for filing reports and appeals.
  - **Queue** (`require_queue_access`) - site admins (`manage_options`) plus space owners/moderators (scoped to their spaces). Used for reading and actioning the queue.
  - **Admin** (`require_admin`) - site admins only. Used for trust actions and report dispositions.
- Unauthenticated calls to gated routes return `401 rest_forbidden`; authenticated-but-unprivileged calls return `403`.
- Path ids (`{id}` for a report, user, or appeal; `{sid}` for a strike) are positive integers validated server-side.
- Several surfaces share a path with both a GET (read) and a CREATE/EDIT (write) method; WordPress merges these registrations on the same route.

See the REST contract page (`14-rest-contract`) for the shared envelope, pagination, error shape, and nonce handling that apply to every route below.

## Report routes

A report is filed by a member against an object (post, reply, user, etc.). Admins and queue-access roles triage it from the queue, then dismiss, escalate, resolve, or remove the content.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/reports` | Auth | File a report. Body: `object_type`, `object_id`, `reason`, optional `notes`, `space_id`. |
| GET | `/reports` | Admin | List reports for a given `object_type` + `object_id`. |
| GET | `/reports/queue` | Queue | Paginated moderation queue (pending + escalated). Space mods see only their spaces. |
| POST | `/reports/{id}/dismiss` | Admin | Dismiss the report (no action warranted). |
| PUT | `/reports/{id}/escalate` | Admin | Escalate the report to site-admin review. |
| PUT | `/reports/{id}/resolve` | Admin | Resolve the report (handled). |
| POST | `/reports/{id}/remove` | Admin | Remove the reported content. |

> **Note:** `dismiss`, `escalate`, `resolve`, and `queue` are the report dispositions referenced across the moderation UI. `escalate` and `resolve` use `EDITABLE` (PUT/PATCH); `dismiss` and `remove` use `CREATABLE` (POST).

### Related queue surfaces

These sit alongside the report queue and share the same queue-access tier.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/moderation/pending` | Queue | Posts awaiting pre-moderation approval (paginated). |
| POST | `/posts/{id}/approve` | Queue | Approve a pending post. |
| POST | `/posts/{id}/reject` | Queue | Reject a pending post (optional reason). |
| GET | `/moderation/log` | Admin | Moderation action log (paginated, filterable). |

## Appeal routes

A member appeals a moderation action against them. Admins approve or deny.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| POST | `/appeals` | Auth | File an appeal. |
| GET | `/appeals` | Admin | List appeals. |
| PUT | `/appeals/{id}/approve` | Admin | Approve the appeal (reverse the action). |
| PUT | `/appeals/{id}/deny` | Admin | Deny the appeal. |
| POST | `/appeals/{id}/resolve` | Admin | Mark the appeal resolved. |
| POST | `/me/appeals` | Auth | File an appeal as the current user. |
| GET | `/me/appeals` | Auth | The current user's own appeals. |

## User trust routes

Per-user trust actions. All are site-admin only. Reads (warnings, suspension state, shadow-ban state, strikes) share paths with their write counterparts.

| Method | Path | Auth | Purpose |
|--------|------|------|---------|
| GET | `/users/{id}/warnings` | Admin | List warnings issued to the user. |
| POST | `/users/{id}/warn` | Admin | Issue a warning to the user. |
| GET | `/users/{id}/strikes` | Admin | List the user's strikes. |
| POST | `/users/{id}/strikes` | Admin | Add a strike to the user. |
| POST | `/users/{id}/strikes/{sid}/reverse` | Admin | Reverse a specific strike. |
| GET | `/users/{id}/shadow-ban` | Admin | Read the user's shadow-ban state. |
| POST | `/users/{id}/shadow-ban` | Admin | Shadow-ban the user. |
| DELETE | `/users/{id}/shadow-ban` | Admin | Lift the shadow-ban. |
| GET | `/users/{id}/suspension` | Admin | Read the user's current suspension state. |
| GET | `/users/{id}/suspensions` | Admin | List the user's suspension history. |
| POST | `/users/{id}/suspend` | Admin | Suspend the user. Body: optional `reason`, `duration_days`, `hide_posts`. |
| DELETE | `/users/{id}/suspend` | Admin | Lift the suspension. |
| GET | `/users/{id}/account-type` | Auth | The user's account type (public/private). |

> **Note:** Strikes and shadow-ban use a single path for read and write (GET/POST, plus DELETE for shadow-ban). Suspend likewise pairs POST (suspend) and DELETE (lift) on `/users/{id}/suspend`, with separate GET reads on `/suspension` (current) and `/suspensions` (history).

## Examples

### File a report

```bash
curl -X POST "https://example.com/wp-json/buddynext/v1/reports" \
  -H "X-WP-Nonce: <wp_rest_nonce>" \
  -H "Content-Type: application/json" \
  --cookie "<auth cookies>" \
  -d '{
        "object_type": "post",
        "object_id": 128,
        "reason": "spam",
        "notes": "Repeated promotional links.",
        "space_id": 0
      }'
```

`object_type`, `object_id`, and `reason` are required; `notes` and `space_id` are optional. `reason` is sanitized as a key (lowercase, underscores).

### Suspend a user

```bash
curl -X POST "https://example.com/wp-json/buddynext/v1/users/42/suspend" \
  -H "X-WP-Nonce: <wp_rest_nonce>" \
  -H "Content-Type: application/json" \
  --cookie "<admin auth cookies>" \
  -d '{
        "reason": "Repeated harassment after warnings.",
        "duration_days": 7,
        "hide_posts": true
      }'
```

All body fields are optional: omit `duration_days` for an indefinite suspension, set `hide_posts` to `true` to hide the user's content for the duration. Lift the suspension with `DELETE /users/42/suspend`.

## Notes / gotchas

- **Queue scoping.** `require_queue_access` grants site admins the full queue, but space owners/moderators see only reports tied to their spaces. Build clients against the scoped result, not the assumption of a global view.
- **Read/write share a path.** Where a GET and a POST/DELETE register on the same path (strikes, shadow-ban, appeals, `/me/appeals`), WordPress merges them. Pick the method deliberately.
- **Disposition verbs are non-destructive vs destructive.** `dismiss`, `escalate`, and `resolve` change a report's state; `remove` acts on the underlying content. Treat `remove` as the destructive path in any confirmation UI.
- **Appeals have two entry points.** `/appeals` (admin-facing list + per-appeal actions) and `/me/appeals` (the member's own create + read). They are not interchangeable.
- **Account type is shared with the social graph surface.** The same `/users/{id}/account-type` read documented in REST: Social Graph informs both follow-flow decisions and trust-context displays.
