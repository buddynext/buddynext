# BuddyNext ↔ WPMediaVerse — Integration Plan

_Planning doc (no code yet). Based on a 7-facet audit of WPMediaVerse **free 1.5.0**
+ **pro 1.5.0** (196 inventoried items). BuddyNext owns **all** UX for a consistent
BN experience; WPMediaVerse is the **media + messaging engine**. Written 2026-06-01._

## 0. The core principle (how BN owns the UX)

WPMediaVerse already anticipates BuddyNext. Two switches make it the headless engine:

- **`apply_filters('mvs_buddynext_active', false)` → return `true`.** This single flag
  makes WPMediaVerse **stand down** its own DM UI: it skips `messaging.js`, the
  `wp_head` messaging config, the floating chat FAB/panel, and its messaging
  notification UI (`Plugin.php:1431/1467/1713/1846`, `Messaging/NotificationListener.php:71`).
  BN then owns 100% of the DM surface while still calling the engine's REST/services.
- **`mvs_should_render_chat_panel`** / asset dequeues for the rest.

**There are NO `mvs_*` global helper functions.** The integration contract is exactly three seams:
1. **REST** — the versioned, permission-checked `mvs/v1` (free) + `mvs-pro/v1` (pro) API.
2. **Container services** — obtained on `do_action('mvs_loaded', $container)`:
   `upload` (`UploadService::handle`), `media_repository` (`MediaRepositoryInterface`,
   explicitly stable since 1.2.0). For server-side ingest with no HTTP round-trip.
3. **Event hooks** — the stability-pledged catalog in
   `wpmediaverse/docs/development/INTEGRATION-EVENT-HOOKS.md` (args appended, never
   reordered, aliased ≥2 majors): `mvs_media_uploaded`, `mvs_message_sent`,
   `mvs_conversation_created`, `mvs_media_deleted`, `mvs_media_privacy_changed`, etc.

**Never** fork templates or read `mvs_*` tables directly for writes. Read via REST /
`media_repository`; write via REST / `UploadService`; react via hooks.

## 1. Engine surface BN integrates against

### Data model (the engine's source of truth — `mvs_*`, NOT `wp_posts`)
| Table | Holds | BN uses for |
|---|---|---|
| `mvs_media_index` | every media item (image/video/audio/file): `post_author`, `media_type`, `privacy`, `album_id`, `file_url`, dims/duration, counts | all media touchpoints (member/space/activity galleries) |
| `mvs_album` (CPT) + `mvs_album_items` | ordered albums w/ cover, `group_id` meta | member + space galleries |
| `mvs_conversations` (`type` direct\|group) + `mvs_conversation_participants` + `mvs_messages` (`message_type`, `media_id`, `attachment_id`, `parent_id`) + `mvs_message_reactions` | 1:1 **and** group DM (free) | DM |
| `mvs_bp_activity_media` | links media ↔ a feed item | activity media |

### REST (free `mvs/v1`) — the write+read surface
- **Media:** `GET/POST /media` (upload accepts image/video/audio; `?author=` = member gallery, `?media_type=` filter, privacy enforced in SQL); `GET /me/media`; `GET/PUT/DELETE /media/{id}`; `/media/{id}/view|download|share|access`; `PUT /media/{id}/group`.
- **Albums:** `GET/POST /albums`, item add/remove/reorder, cover.
- **Collections:** rule-based smart galleries.
- **DM:** `GET /me/conversations`, `GET/POST /conversations`, `/conversations/{id}/messages`, `/read|typing|accept|decline`, `/messages/{id}` (+ `/unsend`, `/reactions`), `/messages/poll`, `/messages/upload`, `/me/messages/unread-count`.
- **Pro `mvs-pro/v1`:** `POST /media/{id}/transcode` + `GET /transcodes`, captions, etc.

### Key filters/actions BN will use
- `mvs_buddynext_active` → `true` (stand engine UI down).
- `mvs_activity_media_ids` (filter) — **correct write path** to link media to a feed item (don't regex content).
- `mvs_privacy_can_view` (filter) — inject BN's visibility resolver into media access.
- `mvs_can_send_message` / `mvs_dm_access_level` — gate DM with BN's block/relationship rules.
- `mvs_media_uploaded`, `mvs_message_sent`, `mvs_conversation_created` (actions) — BN notifications/feed reactions.
- `mvs_storage_driver` (pro) — S3/Spaces/R2/BunnyCDN offload, **zero BN code**.

### Frontend conventions (so BN UX can mount/drive the engine)
- `mvs/messaging` Interactivity store: `actions.openWithRecipient(userId)`, `openWithMediaShare(mediaId)`, the `mvs-open-conversation` CustomEvent, and `#mvs-chat/{id}` / `#mvs-chat/user/{userId}` hash deep-links. *(With `mvs_buddynext_active=true` BN replaces this store — but mirrors its REST calls.)*
- `mvs/shared-ui` store: `openUploadModal`/`setUploadMode`, `openLightboxById(mediaId)` — reusable upload + lightbox primitives.
- Uploader configs are `wp_localize_script` globals (`mvsActivityMedia`, `mvsBpUpload`, `mvsAlbumUpload`) — BN emits its own.

## 2. Per-touchpoint plan (engine → BN UX → where it lives)

### A. Activity media (images/video/audio in the feed composer)
- **Engine:** `POST /media` (or `UploadService::handle`) stores the upload in `mvs_media_index`. For the post↔media link, **reuse the engine's existing `mvs_bp_activity_media` table** — it is already a provider-agnostic `(activity_id, media_id, position, variant)` store (the column is a bare `bigint`, no FK to BP; only the *trigger* `bp_activity_after_save` and the meta source are BP-specific). BN feeds `bn_post_id` as the `activity_id`.
- **Why this is safe (the key insight):** an id is an id. BuddyPress and BuddyNext are **never both the activity provider at once** (a site activates one), so the `activity_id` namespace is unambiguous — no collision, no dual-write, no parallel BN link table to maintain. Whichever plugin is active owns the id space; the engine stores + serves the media the same way for both.
- **BN UX to build:** attach control in `templates/partials/composer.php` (reuse `mvs/shared-ui` upload modal *or* a BN dropzone) → on post save, write the links keyed by `bn_post_id`; render a **BN-styled** grid + lightbox in `post-card.php` by reading the media ids back (BN owns markup — do not use the engine's `render()` which emits engine HTML).
- **Engine seam needed (small, G1):** the read/write are internal (`insert_link`/`get_links`). Ask WPMediaVerse to expose two provider-neutral methods — `set_object_media(int $object_id, int[] $media_ids)` and `get_object_media(int $object_id): int[]` (thin public wrappers over the existing private ones). Until then BN can write via the existing `mvs_activity_media_ids` filter path by stamping its own meta + firing the engine's save path.
- **Where:** Free `Feed/PostService` (call the link seam on save), `templates/partials/composer.php` + `post-card.php`, a thin `Bridges/MediaVerseBridge`.

### B. Video & audio upload
- **Engine:** same `POST /media` (MIME allowlist already includes `video/mp4|webm`, `audio/mpeg|ogg`; auto poster/waveform). **Pro** adds transcode/HLS/captions via `mvs-pro/v1` + `mvs_pro_transcode_complete`.
- **BN UX:** BN media grid renders the engine's `file_url`/poster; show a "processing" state by listening to `mvs_pro_transcode_complete`. Player is BN-styled.
- **Where:** same as A; transcode status via the bridge listening to the Pro action.

### C. Member-specific media (profile gallery)
- **Engine:** `GET /media?author={user_id}` / `GET /me/media`; albums via `/albums`.
- **BN UX:** a "Media" section on the BN profile (`templates/profile/view.php`) — BN-styled grid/album tabs driving the REST list; upload via the shared-ui modal scoped to the viewer.
- **Where:** Free `templates/profile/` + a profile media part; no BP dependency (author scoping is a column).

### D. Space-specific media
- **Engine:** albums + activity linkage scope by a BP `group_id` (a plain int, like `activity_id`).
- **The same insight as activity media:** a container id is a container id. BP Groups and BN Spaces are never both active as the container system, so BN feeds `bn_space_id` as the `group_id` — one namespace, no collision, no parallel space-media table.
- **BN UX:** a BN-styled "Media" tab on the space view listing media scoped to that space (drives the engine list filtered by the space's group id).
- **Where:** Free Spaces templates + `Bridges/MediaVerseBridge`. **Engine seam (G2):** add a `container_type` discriminator so the scope is explicit and migration-clean; short-term reuse `group_id = bn_space_id`.

### E. DM (1:1 + group)
- **Engine:** full free DM REST (`/conversations`, `/messages`, `/messages/upload`, `/messages/poll`); group-capable; reactions, typing, read receipts, requests.
- **BN UX:** set `mvs_buddynext_active=true`, then build BN's inbox + thread (`templates/messages/*`, already partly present) driving `mvs/v1` — replacing the inert native store. Gate sends via `mvs_can_send_message` using BN blocks. Deep-link from the `?recipient=` param (already wired on profile/connections buttons).
- **Where:** Free `templates/messages/` + `assets/js/messages/store.js` + `Bridges/WPMediaVerseBridge` (already hooks `mvs_message_sent`/`mvs_can_send_message`). This **closes the DM journey** the conformance pass flagged.

## 3. Free vs Pro
- **Free covers all 5 touchpoints** end-to-end (media types, galleries, albums, 1:1+group DM).
- **Pro is engine-only, opt-in, zero BN UX code:** cloud storage (`mvs_storage_driver`), video transcode/HLS/captions (`mvs-pro/v1` + `mvs_pro_*`), quotas, watermarking. BN builds nothing Pro-specific; it just benefits when Pro is active.

## 4. Phasing (proposed — build after this plan is approved)
1. **Bridge + DM** — `mvs_buddynext_active=true`; BN inbox/thread over `mvs/v1`; wire `mvs_can_send_message` to BN blocks. (Closes the open DM journey.)
2. **Member media** — profile gallery via `?author=` + albums.
3. **Activity media** — composer attach + feed render + BN↔media link (resolve G1).
4. **Space media** — space gallery + space↔group mapping (resolve G2).
5. **Video/audio polish** — transcode status, Pro storage offload (config only).

## 5. Gaps / WPMediaVerse feature requests
- **G1 — Activity↔media linkage (generalize for migration):** the link store is already provider-agnostic, but its column is *named* `activity_id` and its trigger is BP. **Request:** WPMediaVerse generalize it to `(object_type, object_id, media_id, position, variant)` — `object_type ∈ {bp_activity, bn_post, …}` — plus public `set_object_media(type,id,ids[])` / `get_object_media(type,id)` seams. This serves both the "one id namespace, one active provider" reality **and** the migration path below. Short-term BN can reuse the existing table with `bn_post_id` as `activity_id`; the `object_type` column is what makes the eventual BP→BN conversion a clean, targeted remap rather than a guess.
- **G2 — Space/group scope (same shape as G1):** the engine scopes space media via a BP `group_id` (on albums + activity linkage). BN Spaces are likewise just container IDs, and **BP Groups and BN Spaces are never both the container provider at once** — so BN feeds `bn_space_id` as the `group_id` with no collision. **Request:** add a `container_type` discriminator (`bp_group | bn_space`) alongside `group_id` (mirroring G1's `object_type`), so the namespace is explicit and the eventual BP-Group → BN-Space conversion is a targeted remap. Short-term BN reuses `group_id = bn_space_id`.
- **G3 — BP assumption:** the turnkey surfaces (ActivityFormIntegration, Profile/Group tabs) guard on `function_exists('buddypress')`; BN is not BuddyPress, so BN drives the engine via REST/services/hooks directly (which is the intended headless path — `mvs_buddynext_active` confirms WPMediaVerse expects this).

## 6. Doctrine for the build
- Consume REST / `media_repository` / hooks — **never** fork engine templates or write `mvs_*` tables directly.
- All UX is BN: OKLCH `--bn-*` tokens, Lucide via `buddynext_icon()`, BN Interactivity stores, no emoji, dark mode.
- One thin `Bridges/MediaVerseBridge` (+ existing `WPMediaVerseBridge`) owns every engine call; features compose through it.
- Verify each touchpoint live (seed media, walk light+dark, web + REST) before commit.

## 7. Migration readiness (BuddyPress → BuddyNext)

Eventually all BP sites convert to BuddyNext, so the media layer is designed so a
**BP→BN migration is a targeted id-remap, never a media re-import.** Two facts make
this cheap:

- **Media records are already provider-neutral.** `mvs_media_index` keys on
  `post_author` + `privacy` + `media_type` — nothing BP-specific. Migration touches
  **zero** media rows; the files and records stay exactly as they are.
- **Only the *links* carry a provider id**, and BP/BN are never both the provider at
  once. So migration = rewrite the link references from the old id space to the new:

| Link | BP value | After migration | Remap |
|---|---|---|---|
| activity ↔ media | `object_id = bp_activity_id` | `object_id = bn_post_id` | `UPDATE … SET object_id = map[old]` keyed by the activity→post id map |
| space ↔ media (albums) | `group_id = bp_group_id` | `group_id = bn_space_id` | same, keyed by the group→space id map |
| activity media-ids meta | `bp_activity_meta '_mvs_media_ids'` | `bn_post` meta | copy via the same map |

**Why the `object_type` / `container_type` discriminators (G1/G2) matter here:** with an
explicit type column the migration is an unambiguous, idempotent `UPDATE … WHERE
object_type='bp_activity'` → `'bn_post'` + id remap, and a half-migrated site is never
ambiguous about which id space a row belongs to. Without it the remap relies on the
"one provider active" assumption holding perfectly throughout the cutover.

**Action:** when BN builds its BP→BN migrator (separate effort), it consumes the
activity→post and group→space id maps it already produces and runs these remaps
through the engine's (requested) public link seams — it must **not** rewrite
`mvs_*` tables directly. Until the discriminators land, the migrator can still remap
on the bare id columns under the one-provider assumption.
