# REST: Media and Albums

This page documents the member-facing media and album REST surface that powers the profile Media tab: per-member photo and video uploads, owner gallery reads, and the full album lifecycle (create, list, detail, add and remove items, update, delete, reorder). All routes live under the `buddynext/v1` namespace and are registered by `MediaController` in `includes/Media/`. Read the REST Contract page first; everything here assumes its namespace, nonce auth, envelope, and pagination rules.

The feature is a thin BuddyNext layer over the **WPMediaVerse** companion engine. BuddyNext never calls the engine's own REST and never loads its CSS or JS - it consumes WPMediaVerse server-side through the `BuddyNext\Media\MediaClient` service seam, and applies its own ownership gate (logged in, acting on own media) as the authority. When the engine is absent, write routes return `503` with `bn_media_unavailable` rather than fataling.

![The profile Media tab driven by the media and album REST routes documented here](../images/profile-media.webp)

## Overview / Contract

| Rule | Value |
|---|---|
| Namespace | `buddynext/v1` |
| Registered by | `MediaController` (`includes/Media/MediaController.php`) |
| Auth | `X-WP-Nonce` header (cookie session) or Application Password (external) |
| Permission callback | `require_auth` on every route - all media routes require a logged-in caller |
| Self routes | `/me/media`, `/me/albums/*` - operate on the authenticated caller; ownership enforced in the callback |
| Target reads | `/users/{id}/media`, `/users/{id}/albums` - per-viewer privacy filtered |
| Album detail | `/albums/{id}` - readable by the owner or any viewer the album privacy allows |
| Error body | `{ "code": "...", "message": "...", "data": { "status": N } }` |
| `per_page` max | Clamped to 60 on every paginated read (default 24) |

> **Note:** Although every route uses the `require_auth` permission callback, that only guarantees a logged-in caller. Per-row visibility and per-album ownership are enforced inside the callbacks through the engine privacy seam (`Galleries`) and the album owner gate (`require_album_owner`). A caller who is logged in still only sees and mutates what they are allowed to.

## Routes

### Media

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/me/media` | require_auth | Upload one file to the caller's own profile media |
| GET | `/users/{id}/media` | require_auth | List a user's media, privacy-filtered for the viewer |
| DELETE | `/me/media/{media_id}` | require_auth | Trash a media item the caller owns |

### Albums

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/users/{id}/albums` | require_auth | List a user's albums, privacy-filtered for the viewer |
| POST | `/me/albums` | require_auth | Create an album owned by the caller |
| GET | `/albums/{id}` | require_auth | Album detail plus a page of its media |
| POST | `/me/albums/{id}/items` | require_auth | Add media to an album (owner only) |
| DELETE | `/me/albums/{id}/items/{media_id}` | require_auth | Remove one media item from an album (owner only) |
| PUT | `/me/albums/{id}` | require_auth | Update title, description, privacy, or cover (owner only) |
| DELETE | `/me/albums/{id}` | require_auth | Delete an album and its item links (owner only) |
| PUT | `/me/albums/{id}/reorder` | require_auth | Set the item order within an album (owner only) |

`{id}` and `{media_id}` match `[\d]+` and are sanitized with `absint`.

## Privacy mapping

Media uploads use the same audience vocabulary the post composer offers, because a batch upload becomes a feed post and the member picks the post audience. That vocabulary is mapped to the engine's media-file privacy on the way in. Albums use the engine's album vocabulary directly.

| BuddyNext choice (media upload) | Engine media privacy |
|---|---|
| `public` | `public` |
| `followers` | `members` |
| `connections` | `members` |
| `private` | `private` |

The engine has no followers/connections concept, so both collapse to logged-in `members`; the surfacing post's own privacy is what gates the feed. Unknown values fall back to `public`.

Album privacy (`POST /me/albums`, `PUT /me/albums/{id}`) accepts `public`, `members`, or `private` directly; any other value falls back to `public`.

## Media routes in detail

### POST /me/media

Uploads a single file to the caller's own profile media. The request is `multipart/form-data`.

| Field | In | Required | Notes |
|---|---|---|---|
| `file` | multipart | yes | The binary file. Missing or failed uploads return `bn_media_missing` (422). |
| `title` | body | no | `sanitize_text_field` |
| `description` | body | no | `sanitize_textarea_field` |
| `privacy` | body | no | One of `public`, `followers`, `connections`, `private`; mapped per the table above (default `public`). |

The engine validates MIME, size, and duplicates, strips EXIF, optimizes, and generates thumbnails. The caller is set as author so the new media surfaces in their own gallery. Returns `201`:

```json
{
  "media": { },
  "duplicate_warning": false,
  "existing_media_id": 0
}
```

`media` is the media descriptor (`MediaUrlResolver::descriptor()`). When the engine detected a near-duplicate, `duplicate_warning` is `true` and `existing_media_id` points at the existing item.

### GET /users/{id}/media

Returns a page of the user's gallery as both rendered tile HTML and the ordered ID list. Privacy is enforced per viewer inside `Galleries` - private media is included only when the viewer is the owner or a moderator.

| Param | Type | Default | Notes |
|---|---|---|---|
| `id` | integer (path) | - | Media owner user ID |
| `page` | integer | `1` | 1-based |
| `per_page` | integer | `24` | Clamped to 60 |

Response (`200`) also sets `X-WP-Total` and `X-WP-TotalPages` headers:

```json
{
  "html": "<...tile markup...>",
  "ids": [ 901, 900, 899 ],
  "total": 42,
  "page": 1,
  "per_page": 24,
  "total_pages": 2
}
```

The `html` is the same `MediaRenderer::gallery()` markup the profile template renders, so the client can swap the grid in place after an upload or delete without a second renderer drifting out of sync.

### DELETE /me/media/{media_id}

Trashes a media item. Owner-only: a member may delete their own media, and a site manager (`manage_options`) may moderate any media; any other member gets `bn_media_forbidden`. Deleting an item that is already gone returns success so the UI converges.

```json
{ "deleted": true, "id": 901 }
```

## Album routes in detail

### GET /users/{id}/albums

Lists the user's albums (newest first), each privacy-filtered for the viewer through the engine privacy seam. Accepts `page` and `per_page` (default 24, max 60).

```json
{
  "albums": [
    {
      "id": 51,
      "title": "Summer trip",
      "description": "",
      "privacy": "public",
      "owner": 12,
      "media_count": 8,
      "cover_url": "https://example.com/.../cover.webp"
    }
  ],
  "page": 1,
  "per_page": 24
}
```

### POST /me/albums

Creates an album owned by the caller. `title` is required (`bn_album_title_required` / 422 when empty); `description` and `privacy` are optional. Returns the album summary at `201`.

```bash
curl -X POST 'https://example.com/wp-json/buddynext/v1/me/albums' \
  -H 'X-WP-Nonce: <nonce>' \
  -H 'Content-Type: application/json' \
  --data '{ "title": "Summer trip", "description": "Italy 2026", "privacy": "members" }'
```

### GET /albums/{id}

Returns the album summary merged with `is_owner`, a page of its media as rendered HTML, the ordered ID list, and pagination. Accepts `page` and `per_page` (default 24, max 60).

Visibility: the owner always sees their own album; everyone else must pass `Galleries::can_view_album()`. A private album does not disclose its existence - a non-permitted viewer gets `bn_album_not_found` (404), the same response as a genuinely missing album. A non-`mvs_album` ID also returns `bn_album_not_found`.

```json
{
  "id": 51,
  "title": "Summer trip",
  "description": "Italy 2026",
  "privacy": "members",
  "owner": 12,
  "media_count": 8,
  "cover_url": "https://example.com/.../cover.webp",
  "is_owner": true,
  "html": "<...tile markup...>",
  "ids": [ 901, 902 ],
  "page": 1,
  "per_page": 24,
  "total_pages": 1
}
```

### POST /me/albums/{id}/items

Adds media to an album. Owner-only (`require_album_owner`). Body carries `media_ids` (an array; duplicates and non-numeric values are filtered). An empty list returns `bn_album_no_media` (422).

```json
{ "added": 3, "media_count": 11 }
```

### DELETE /me/albums/{id}/items/{media_id}

Removes one media item from the album. Owner-only. The underlying media itself is not deleted - only the album link.

```json
{ "removed": true, "media_count": 10 }
```

### PUT /me/albums/{id}

Updates an album. Owner-only. Every field is optional; only the fields present in the body are changed.

| Field | Notes |
|---|---|
| `title` | `sanitize_text_field`; present-but-empty returns `bn_album_title_required` (422) |
| `description` | `sanitize_textarea_field`; stored as the post excerpt |
| `privacy` | `public`, `members`, or `private` (default `public`) |
| `cover_media_id` | Sets the album cover to this media item |

Returns the updated album summary at `200`.

### DELETE /me/albums/{id}

Deletes the album and its item links. Owner-only. The media inside the album is not deleted - it stays in the owner's gallery.

```json
{ "deleted": true, "id": 51 }
```

### PUT /me/albums/{id}/reorder

Sets the item order. Owner-only. Body carries `order`, an array of media IDs in the new order; the numeric positions become the engine's 0-indexed order.

```json
{ "reordered": true }
```

## Error codes

| Code | Status | When |
|---|---|---|
| `bn_media_unavailable` | 503 | The WPMediaVerse engine (or the needed service) is absent |
| `bn_media_missing` | 422 | No file uploaded, or the upload failed |
| `bn_media_forbidden` | 403 / 401 | Caller tried to delete media they do not own (401 if not readable) |
| `bn_album_title_required` | 422 | Album create/update with a missing or empty title |
| `bn_album_no_media` | 422 | Add-items called with no usable media IDs |
| `bn_album_not_found` | 404 | Album ID is not an `mvs_album`, or a private album hidden from the viewer |
| `bn_album_forbidden` | 403 / 401 | Caller tried to manage an album they do not own (401 if not readable) |

> **Note:** `bn_album_not_found` is deliberately reused for "private album the viewer may not see" so the API never discloses that a hidden album exists. The 403-vs-401 split on the forbidden codes follows `current_user_can( 'read' )` - a readable session gets 403 (authenticated but not allowed), otherwise 401.

## Notes / gotchas

- **Engine seam only.** All engine access funnels through `MediaClient` (`repo()`, `upload()`, `albums()`, `privacy()`). BuddyNext does not call WPMediaVerse REST and does not enqueue its assets on BuddyNext screens. Every accessor degrades to null/empty when the engine is absent, which is why write routes can return `bn_media_unavailable` instead of fataling.
- **HTML is server-rendered.** `GET /users/{id}/media` and `GET /albums/{id}` return ready-to-insert `MediaRenderer::gallery()` markup so the client never re-implements tile rendering. Privacy is already applied to the IDs behind that HTML.
- **Privacy is enforced on read.** Owner gallery reads, album lists, and album detail all filter by the engine privacy seam before serializing, so private media and private albums cannot leak into another viewer's response.
- **Free vs Pro.** Every route on this page is Free (`buddynext/v1`), gated only by the WPMediaVerse companion being active. There is no Pro counterpart that re-registers these routes.
