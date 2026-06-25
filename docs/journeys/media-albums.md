# Journey: Profile Media Uploads + Albums (1.0.3)

**Feature** (new in 1.0.3): `includes/Media/MediaController.php` — member-owned media uploads and albums on the profile **Media tab**. BuddyNext owns the UX and the `buddynext/v1` REST surface; **all storage is delegated to the WPMediaVerse engine** through `includes/Media/MediaClient.php` (upload service, `media_repository`, `albums` service, `privacy` service). Albums are the engine's `mvs_album` CPT. There is **no new `bn_` table**.
**Requires the `wpmediaverse` plugin active** — every endpoint degrades to `503 bn_media_unavailable` when the engine (or its DI container) is absent, so BuddyNext never fatals without it.
**Privacy mapping** (BuddyNext value → engine value): `public → public`, `followers → members`, `connections → members`, `private → private`. Privacy is enforced by the engine (`MediaRepository::query_by_author()` hides `private` media from non-owner / non-moderator viewers), so BuddyNext never filters rows itself and private media cannot leak into a profile grid.
**Hooks fired**: `buddynext_image_stored` / `buddynext_image_deleted` (avatar/cover image storage path only — not the media-upload path). The media/album endpoints raise no BN-domain `do_action`; they call the engine services directly.
**Wiring**: routes registered at `includes/REST/Router.php:100` (`( new \BuddyNext\Media\MediaController() )->register_routes()`); frontend stores `assets/js/media/upload-store.js`, `assets/js/media/albums-store.js`, `assets/js/media/upload-core.js`; templates `templates/partials/media-tab.php`, `templates/partials/media-upload-composer.php`.
**Estimated time**: 14 min manual

## Site-owner expectation

When a community owner runs BuddyNext + WPMediaVerse, members expect their profile **Media tab** to behave like a modern social profile gallery:

- Upload photos and videos straight from the Media tab, pick **who can see each upload** (public / followers / connections / private), and the upload appears in the activity feed right away.
- Organise media into **albums**: create an album, add and remove media, set a cover, drag to reorder, rename, change privacy, and delete.
- A viewer only ever sees media/albums they are allowed to — private media never shows on someone else's grid; the Media **count badge** matches what the grid actually shows.
- A video without a poster shows a generated thumbnail, not a black tile.

All of this is delivered by BuddyNext's UX over the engine's storage — the owner installs both plugins and it "just works".

## Preconditions

- BuddyNext Free + **`wpmediaverse`** active on `http://buddynext.local/`. Confirm:

  ```bash
  wp plugin list --status=active --field=name | grep -E '^(buddynext|wpmediaverse)$'
  ```

  Both must appear. (The `mvs_album` CPT must be registered — `wp post-type list --field=name | grep mvs_album`.)
- Test members (subscribers): `alice`, `bob`. Use autologin for browser steps (`?autologin=alice`); for REST basic-auth set a known password on the dev site only: `wp user update alice --user_pass=password`.
- Capture IDs up front:

  ```bash
  wp user get alice --field=ID   # ALICE_ID
  wp user get bob   --field=ID   # BOB_ID
  ```

Base URL: `http://buddynext.local/` · Admin: `http://buddynext.local/wp-admin/`

## Happy-path steps

### Part 1: Upload media to your own profile

1. As `alice`, upload an image (multipart; `file` field + optional `privacy`):

   ```bash
   curl -s -X POST http://buddynext.local/wp-json/buddynext/v1/me/media \
     -u alice:password \
     -F "file=@/path/to/photo.jpg" \
     -F "privacy=public"
   ```

   - Expected: `200` with the created media object (note its `media_id`). The engine `upload` service ingests the file exactly like its own upload path.
   - Error shapes to confirm: no file → `422 bn_media_missing`; engine absent → `503 bn_media_unavailable`.

2. List `alice`'s media (paginated, newest first, `per_page` default 24):

   ```bash
   curl -s "http://buddynext.local/wp-json/buddynext/v1/users/ALICE_ID/media?page=1&per_page=24" -u alice:password
   ```

   - Expected: `200`, the just-uploaded item present.

3. **Privacy gate:** upload a second item as `private`, then list `alice`'s media **as `bob`**:

   ```bash
   curl -s -X POST http://buddynext.local/wp-json/buddynext/v1/me/media -u alice:password -F "file=@/path/to/secret.jpg" -F "privacy=private"
   curl -s "http://buddynext.local/wp-json/buddynext/v1/users/ALICE_ID/media" -u bob:password
   ```

   - Expected: `bob` sees `alice`'s public item but **not** the private one (engine `query_by_author` privacy rule). The Media **count** for `bob`'s view excludes the private item.

4. Delete an item you own:

   ```bash
   curl -s -X DELETE http://buddynext.local/wp-json/buddynext/v1/me/media/MEDIA_ID -u alice:password
   ```

   - Expected: `200`. Deleting media you do not own → `403 bn_media_forbidden`; a failed engine delete → `bn_media_delete_failed`.

### Part 2: Create and populate an album

5. As `alice`, create an album (`title` required; `description`, `privacy` optional):

   ```bash
   curl -s -X POST http://buddynext.local/wp-json/buddynext/v1/me/albums \
     -u alice:password -H "Content-Type: application/json" \
     -d '{"title":"Holiday 2026","description":"Trip pics","privacy":"followers"}'
   ```

   - Expected: `200` with the album summary (`id`, `title`, `description`, `privacy`, `media_count:0`, `cover_url`, `owner`). Missing title → `400 bn_album_title_required`. Engine albums service absent → `bn_albums_unavailable`.

6. Add media to the album (`media_ids` array required):

   ```bash
   curl -s -X POST http://buddynext.local/wp-json/buddynext/v1/me/albums/ALBUM_ID/items \
     -u alice:password -H "Content-Type: application/json" \
     -d '{"media_ids":[MEDIA_ID_1, MEDIA_ID_2]}'
   ```

   - Expected: `200`, `media_count` increases. Empty/missing array → `bn_album_no_media`. Unknown album → `bn_album_not_found`.

7. Get the album (paginated items):

   ```bash
   curl -s "http://buddynext.local/wp-json/buddynext/v1/albums/ALBUM_ID?page=1&per_page=24" -u alice:password
   ```

   - Expected: `200`, ordered media ids returned.

8. List `alice`'s albums:

   ```bash
   curl -s "http://buddynext.local/wp-json/buddynext/v1/users/ALICE_ID/albums" -u alice:password
   ```

   - Expected: `200`, the new album present (newest first).

### Part 3: Manage the album (rename, cover, reorder, remove, delete)

9. Update title / description / privacy / cover:

   ```bash
   curl -s -X PUT http://buddynext.local/wp-json/buddynext/v1/me/albums/ALBUM_ID \
     -u alice:password -H "Content-Type: application/json" \
     -d '{"title":"Holiday (edited)","privacy":"public","cover_media_id":MEDIA_ID_1}'
   ```

   - Expected: `200`, summary reflects the changes; `cover_url` resolves to the chosen cover.

10. Reorder items (`order` = array of media ids in the desired sequence):

    ```bash
    curl -s -X PUT http://buddynext.local/wp-json/buddynext/v1/me/albums/ALBUM_ID/reorder \
      -u alice:password -H "Content-Type: application/json" \
      -d '{"order":[MEDIA_ID_2, MEDIA_ID_1]}'
    ```

    - Expected: `200`; GET the album (step 7) confirms the new order.

11. Remove one item, then delete the album:

    ```bash
    curl -s -X DELETE http://buddynext.local/wp-json/buddynext/v1/me/albums/ALBUM_ID/items/MEDIA_ID_2 -u alice:password
    curl -s -X DELETE http://buddynext.local/wp-json/buddynext/v1/me/albums/ALBUM_ID -u alice:password
    ```

    - Expected: `200` each. Acting on an album you do not own → `bn_album_forbidden`.

### Part 4: Browser verification (the Media tab)

12. As `alice` (`?autologin=alice`), open the profile **Media tab**. Verify:
    - The upload composer accepts an image/video; the tile appears immediately with a real (downscaled) thumbnail, not a spinner-stuck or black tile.
    - The privacy selector offers public / followers / connections / private.
    - The Albums section lets you create an album, add media, set a cover, drag to reorder, rename, change privacy, delete.
    - As `bob` (`?autologin=bob`) viewing `alice`'s profile, private items are absent and the Media count matches the visible grid.
    - Check the browser console: **zero errors** on the Media tab and album views.

## Edge cases to also verify

- **Engine inactive (graceful absence, no fatals):** `wp plugin deactivate wpmediaverse`, then call any media endpoint → `503 bn_media_unavailable`; load the profile Media tab → clean render, no PHP fatal in `wp-content/debug.log`. `wp plugin activate wpmediaverse` to restore.
- **Video without a poster** shows a generated thumbnail (engine default video poster via `MediaClient::default_video_poster()`), not a black tile.
- **Cross-owner writes are forbidden:** `bob` cannot delete `alice`'s media (`403 bn_media_forbidden`) or modify her album (`bn_album_forbidden`).
- **Private album visibility:** a `private` album is not returned to `bob` in `users/{alice}/albums` (engine `privacy->can_view`; fails closed for non-owners when the privacy service is absent).

## What this validates

- **Upload path:** `POST /me/media` → engine `upload` service ingests the file; privacy mapped via `PRIVACY_MAP`.
- **Owner-scoped listing + privacy:** `GET /users/{id}/media` → `Galleries::user_media_ids()` → engine `query_by_author` (private hidden from non-owners); count via `Galleries::user_media_count()` mirrors the same rule.
- **Album lifecycle:** create / get / add-items / remove-item / update / reorder / delete all route through the engine `albums` service against the `mvs_album` CPT.
- **Graceful degradation:** every endpoint returns `503 bn_media_unavailable` (or `bn_albums_unavailable`) when the engine is absent — no fatals.
- **Ownership enforcement:** delete/modify on non-owned media/albums returns `403 bn_media_forbidden` / `bn_album_forbidden`.

## REST surface walked

```
# BuddyNext (buddynext/v1) — new in 1.0.3 (includes/Media/MediaController.php), all require auth:
POST   /wp-json/buddynext/v1/me/media                                   -- upload own media
GET    /wp-json/buddynext/v1/users/{id}/media                           -- list a user's media (privacy-filtered)
DELETE /wp-json/buddynext/v1/me/media/{media_id}                        -- delete own media
GET    /wp-json/buddynext/v1/users/{id}/albums                          -- list a user's albums
POST   /wp-json/buddynext/v1/me/albums                                  -- create an album
GET    /wp-json/buddynext/v1/albums/{id}                                -- get an album (paginated items)
POST   /wp-json/buddynext/v1/me/albums/{id}/items                       -- add media to an album
DELETE /wp-json/buddynext/v1/me/albums/{id}/items/{media_id}            -- remove a media item
PUT    /wp-json/buddynext/v1/me/albums/{id}                             -- update album (title/desc/privacy/cover)
DELETE /wp-json/buddynext/v1/me/albums/{id}                             -- delete an album
PUT    /wp-json/buddynext/v1/me/albums/{id}/reorder                     -- reorder album items
```

Storage (`mvs_album` CPT, engine media items) is **partner-owned** (WPMediaVerse); BuddyNext depends on the engine's container services (`upload`, `media_repository`, `albums`, `privacy`) resolved through `MediaClient`, not on its wire format.

## Admin-config → member-effect

- **WPMediaVerse feature toggle / plugin active:** OFF → the Media tab upload + album surfaces return `503` and the UI shows the unavailable state; ON → restored. This is the single gate — there is no separate BuddyNext "media uploads" admin switch in 1.0.3 (the capability is the engine's `moderate_mvs_media` for moderator visibility of private media).

## Known limitations

- **All media/album storage is owned upstream (WPMediaVerse).** BuddyNext owns 100% of the Media-tab UX but consumes the engine via `MediaClient`; any storage-layer gap is an engine matter — trace into the `wpmediaverse` repo first.
- **No BN-domain action hook** is fired on media upload/album change (the endpoints call engine services directly). Integrations that need to react should hook the engine's media hooks, or the existing `buddynext_image_stored` for the avatar/cover image path.
- **Album privacy filtering is post-query** in `Galleries::user_albums()` (acceptable because per-user album counts are small); media privacy is enforced in the engine query itself.
