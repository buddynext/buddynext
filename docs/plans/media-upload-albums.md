# Member Media Upload + Albums — Implementation Plan

**Status:** Approved (plan) — not yet built.
**Scope (this pass):** Profile Media tab ONLY, owner-only writes, full parity.
**Reuse target:** The same architecture is intended to be applied to the **Space** Media tab later (see [§9 Spaces — later phase](#9-spaces--later-phase)). Build profile first; do NOT add upload/album-create to the Space tab now.
**Date:** 2026-06-25.

---

## 1. Goals

On a member's own **profile Media tab**, give them a BuddyNext-native experience to:
- **Upload** media (images/video/audio per engine config), with privacy + optional "add to album".
- **Albums:** create, rename, set privacy, set cover, delete; add/remove media; **drag-to-reorder**; album detail view.

The Space Media tab stays **read-only display** in this pass.

---

## 2. Hard constraints (non-negotiable)

1. **No WPMediaVerse code changes.** Everything is reachable via engine services/seam (verified — see §4). Zero engine fixes required.
2. **No MV CSS/JS on BN screens.** BN owns 100% of the browser experience. MV is invisible server-side plumbing. (Codified already in `MediaClient` docblock: "consumes WPMediaVerse at the API level ONLY — never loading its JS or CSS.")
3. **No new BN DB tables.** Albums + media live entirely in the engine (`mvs_album` CPT, `mvs_album_items`, `mvs_media_index`). BN is UI + a thin owner-gated REST proxy.
4. **Engine access only through `MediaClient`** (the seam rule). No engine class referenced outside `includes/Media/`.
5. **Owner-only writes** this pass. Admins get a moderate-delete path only. Upload always sets `post_author = the member`.
6. Standard gates: 100% REST (no admin-ajax), `--bn-*` tokens only, no emoji (Lucide via `IconService`), `buddynext_can()` + nonce, WPCS + PHPStan L5, big-site checklist, 390px + dark + RTL + a11y verified per item.

---

## 3. Architecture & data flow

**Call engine *services*, not engine REST.** The engine's `POST /mvs/v1/media` and `POST /mvs/v1/albums` require the `upload_mvs_media` capability, which Subscriber-level BN members don't have. So BN calls the engine **services** server-side via the seam; **BN's own ownership gate is the authority.**

```
browser (BN composer — BN CSS/JS only)
  → POST buddynext/v1/me/media   (multipart, BN nonce)
    → BN MediaController (owner gate: buddynext_can + is-own-profile)
      → MediaClient::upload()->handle($_FILES['file'], $member_id, $args)   // MV engine, server-side
        → returns media_id  (auto post_author = member → appears in Galleries::user_media_ids)
  → BN responds with BN-shaped JSON → BN renders the tile (BN CSS)
```

Albums identical: BN endpoint → `MediaClient::albums()` (AlbumService) → engine. Reads (list/grid) → engine services + privacy seam, re-shaped to BN JSON.

---

## 4. Engine capability map (verified — consume via seam)

| Capability | Engine entry (server-side) |
|---|---|
| Upload (privacy, optional album) | `MediaClient::upload()->handle($file, $user_id, $args)` (UploadService) — validation, EXIF strip, WebP/AVIF optimize, thumbs, signed-URL storage |
| Album create/CRUD/items/reorder/cover | `MediaClient::albums()` → `AlbumService::create/add_items/get_items/get_items_with_data/remove_item/reorder/set_cover/delete_all_items` |
| Delete a member's media | `MediaClient::repo()->trash($media_id)` (`MediaRepository::trash()`) |
| Privacy filter for non-owner viewers | new `MediaClient::privacy()` → `PrivacyService::can_view($id, $viewer)` (container key `'privacy'`) |
| List a user's albums | core `WP_Query` on `mvs_album` CPT by `author`, then privacy-filter each via the seam |
| Read URLs / thumbs | `MediaClient::repo()` (already used) |

**Engine gaps → handled BN-side (no engine change):**
- Upload→album is 2 calls → BN does both in one server-side endpoint.
- Album item lists are unpaginated → BN pages the ID list.
- No "list albums by author" service method → BN uses `WP_Query` + privacy seam.

**Optional future engine nicety (not required):** `AlbumService::list_for_author($user_id, $viewer)` would tidy BN's read path. Do NOT add to the engine now.

---

## 5. Backend (free plugin)

| Piece | File | Notes |
|---|---|---|
| Seam accessors | `includes/Media/MediaClient.php` | add `albums()` → `container()->get('albums')`; add `privacy()` → `container()->get('privacy')`. `upload()`/`repo()` already exist. |
| Read helpers | `includes/Media/Galleries.php` | add `user_albums($owner,$viewer,$limit,$offset)` + `count_user_albums()`, `album_media($album_id,$viewer,$limit,$offset)` + count — privacy-filtered via the seam. |
| Member REST | `includes/Media/MediaController.php` *(new, `buddynext/v1`)*, extends `REST/BaseRestController` | owner-gated endpoints (below). |
| Wire routes | `includes/REST/Router.php` | register `MediaController`. |
| Ability | `Plugin::init()` | register `buddynext-upload-media`; every write re-checks owner via `repo()->get_author()` / album `post_author`. |

**Endpoints (all owner-gated; admins → moderate-delete only):**
- `POST /me/media` (multipart) — upload; accepts `privacy`, optional `album_id` (BN does upload→add-to-album server-side), optional client `thumbnail` (video poster).
- `GET /users/{id}/media` · `DELETE /me/media/{id}`
- `GET /users/{id}/albums` · `POST /me/albums` · `GET /me/albums/{id}` (paginated items) · `PUT /me/albums/{id}` · `DELETE /me/albums/{id}`
- `POST /me/albums/{id}/items` · `DELETE /me/albums/{id}/items/{media}` · `PUT /me/albums/{id}/reorder` · `PUT /me/albums/{id}/cover`

---

## 6. Frontend (free plugin)

| Piece | File | Notes |
|---|---|---|
| Tab surface | `templates/parts/profile-tab-panel.php` (media panel ~L230) | sub-nav "Media | Albums"; owner-only Upload + New album (gate on existing `$bn_pf_is_owner`). |
| Composer partial | `templates/partials/media-upload-composer.php` *(new)* | hidden `multiple` file input + drop zone + staged-preview grid; `data-wp-context` passes `restNonce`/`restUrl`/`userId`. |
| Album templates | `templates/parts/…` *(new)* | album cards grid + album detail view. |
| Store | `assets/js/media/upload-store.js` *(new)* | Interactivity store `buddynext/media`; shared `restFetch` (`assets/js/shell/rest-client.js`) + `onNavReady`. |
| Styles | `assets/css/bn-media-upload.css` *(new)* | `--bn-*` tokens only; desktop-first + `@media (max-width:640px)`. |
| Register/enqueue | `includes/Core/AssetService.php` + `includes/Core/PageRouter.php` (profile hub case) | add `@buddynext/media` module + `bn-media-upload` handle; enqueue on profile hub. |

**Reuse:** existing `.bn-media-gallery`/`.bn-media-tile`, the BN lightbox, `.bn-btn`, card/modal/empty-state primitives, Lucide icons (`upload`, `image`, `folder-plus`, `trash`, `link`).
**Precedent to mirror:** profile editor avatar/cover upload (`assets/js/profile/store.js` `handleAvatarFileChange`/`flushStagedMedia`; endpoint `ProfileController::upload_avatar`).

---

## 7. UX spec

### Adopt from MediaVerse (proven)
- Drag-drop **and** click; client-side MIME/size/count validation w/ human-readable allowed-types hint.
- Privacy selector at upload (default Public), applied per batch.
- **Duplicate = warning, not error** (surface existing item; don't fail).
- **Client-side video poster:** canvas first-frame → append as `thumbnail` to FormData; default-poster fallback via `MediaClient::default_video_poster()` (already wired in the Card #2 fix).
- **Embed full media JSON on each grid tile** → lightbox opens instantly, skips REST ~80%.
- Skeleton loaders, optimistic toggles, `prefers-reduced-motion`, per-island state isolation, message-type-aware toast timing, reset+clear file input after success.
- A11y: Esc/←/→/Enter, focus trap + `body` scroll-lock in modals, ARIA labels, 44px targets.

### Improve on MediaVerse (their gaps)
- **Pre-upload staging grid:** thumbnails + per-file remove/reorder/caption (MV shows an opaque list).
- **Per-file progress bars + overall** (MV is text-only).
- **Graceful partial success:** a failed file doesn't abort the batch; mark + per-file retry (MV stops at first failure).
- **Full album management in the detail view:** add-media picker (from own media), remove, **drag-to-reorder**, set-cover, rename/privacy, delete (MV Free frontend has none).
- **Standalone "New album"** + add existing media (MV only creates during an upload).

### States (every async surface)
Empty (no media / no albums) · loading skeletons · error + retry · optimistic insert + rollback · multi-actor ("already deleted / already removed").

### Privacy mapping
Expose **Public / Members / Only me** → engine `public` / `members` / `private`. (Skip engine `friends`/`group` for the profile pass; revisit for spaces.)

---

## 8. Build phasing (each phase browser-verified before next, per the verify-per-item rule)

- **Phase A — Upload:** seam accessors + `POST /me/media` + `DELETE /me/media/{id}` + composer UI → verify a real member upload appears on their tab (desktop + 390 + dark).
- **Phase B — Albums core:** list + create + detail + add/remove → verify.
- **Phase C — Album management:** drag-reorder + cover + rename/privacy + delete → verify.

Gates each phase: WPCS, PHPStan L5, `bin/ux-audit.sh`, REST-boundary, browser per item.

---

## 9. Spaces — later phase

This architecture is designed to extend to the **Space Media tab** with minimal change:
- **Permission model swaps** owner-only → space-manager/role-based (`buddynext_can($uid,'manage-space-media',['space_id'=>…])`); membership/role gates instead of "is-own-profile".
- **Ownership of media/albums** ties to the space (engine supports `group_id` on upload + `privacy='group'` on albums) instead of the member.
- **Same** MediaController patterns, store, composer, album UI, CSS — parameterized by context (profile vs space).
- Endpoints gain a space-scoped sibling set (e.g. `/spaces/{id}/media`, `/spaces/{id}/albums`).
- Do NOT start this until the profile pass ships + is verified.

---

## 10. Open decisions

1. Privacy labels — defaulting to 3-way Public/Members/Only me unless owner wants the 4-way profile vocab.
2. Album-list privacy for non-owner viewers — filter via the privacy seam (owner sees all own).
3. Per-album pagination page size (default 24 to match the media grid).
