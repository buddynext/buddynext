# BuddyNext ↔ WPMediaVerse — Media Integration Master Plan

_Long-term, modular plan for the complete media + DM integration. Pairs with
`wpmediaverse-integration-plan.md` (engine audit + the 1.6.0 asks) and the
WPMediaVerse `1.6.0` branch (E1/E2/E3 engine seams, delivered). Written 2026-06-02._

## 0. Non-negotiable principles

1. **API-level only.** BuddyNext **never** loads WPMediaVerse JS or CSS. No
   `mvs-*` script/style is enqueued on any BN page. BN talks to the engine over
   REST (`mvs/v1`, `mvs-pro/v1`) and, server-side, the container read API
   (`media_repository`, `object_media`, `messaging`). _(Done: the dead
   `mvs-lightbox` enqueue is removed; the `mvs-messaging` enqueue still violates
   this and is replaced in Phase 4.)_
2. **BN owns 100% of the UX.** Every gallery, grid, lightbox, player, composer,
   DM thread is BN-native — OKLCH `--bn-*` tokens, Lucide via `buddynext_icon()`,
   dark mode, no emoji. Nothing should look like a different plugin.
3. **One adapter.** All engine coupling lives in a single BN module
   (`includes/Media/`). Features compose through it; no template or feature calls
   `mvs/v1` or a `WPMediaVerse\*` class directly.
4. **Mode-agnostic + migration-safe.** Works under BuddyPress/BuddyBoss *or*
   BuddyNext (one provider per site). Object/container links carry
   `object_type`/`container_type` (1.6.0) so BP/BuddyBoss→BN migration is a clean
   id remap. (BP 100k + BuddyBoss 50k are the conversion targets.)
5. **Engine bugs vs BN work.** WPMediaVerse API/asset bugs are the WPMediaVerse
   team's (keep pulling free+pro). BN owns the adapter + all UX. The 1.6.0 engine
   asks (E1/E2/E3) are delivered on the `1.6.0` branch.

## 1. Modular architecture (the `includes/Media/` module)

A single BN module is the only thing that knows WPMediaVerse exists.

| Submodule | Responsibility | Engine touchpoint |
|---|---|---|
| `MediaClient` | Server-side reads/writes to the engine | `media_repository` (url/meta), REST for writes, never raw tables |
| `MediaUrlResolver` | media_id → URL/poster/dimensions (the render fix) | `media_repository->get($id,'file_url'/'thumb_*'/...)` |
| `ObjectMediaLink` | Attach/read media on a BN object | `object_media` seam (1.6.0): `set/get_object_media('bn_post'\|'bn_space', id, ids)` |
| `MediaRenderer` | BN-native markup: grid, single, audio, video, album tiles | consumes `MediaUrlResolver` |
| `Lightbox` (JS+CSS+tpl) | BN-native lightbox/gallery nav (replaces mvs-lightbox) | none (pure BN UX) |
| `Uploader` (JS) | BN-native upload control → REST | `POST mvs/v1/media`, `POST mvs/v1/messages/upload` |
| `Galleries` | Profile gallery, space gallery, albums UX | `mvs/v1/media?author=`, `/albums`, `/collections` |
| `DmMedia` | Media inside BN-native DM threads | `mvs/v1` conversations/messages |
| `Access` | Wire BN privacy/blocks into engine filters | `mvs_privacy_can_view`, `mvs_can_send_message`, `mvs_dm_access_level` |

Rule: if a file outside `includes/Media/` references `mvs/`, `MVS_`, or
`WPMediaVerse\`, that's a leak to fix.

## 2. Complete expected-cases checklist

Legend: ✅ done · 🔧 partial/broken · ⬜ to build · 🧪 needs live test.
"Engine" = the API used; "BN" = the UX BN builds.

### 2.1 Member — own profile (`/members/<me>/`)
- ⬜ Upload to my gallery (BN uploader → `POST /media`, author=me)
- ⬜ View my gallery — grid + album tabs (BN grid ← `GET /media?author=me`, `/albums`)
- ⬜ Create / rename / delete album (`/albums` CRUD)
- ⬜ Add/remove media to album, reorder, set cover (`/albums/{id}/items|reorder|cover`)
- ⬜ Per-media privacy (public/followers/connections/private) honored
- ⬜ Audio item: BN player; Video item: BN player + poster
- ⬜ Open in BN lightbox with gallery nav

### 2.2 Member — activity feed (`/activity/`)
- 🔧 Attach media to a post (composer, ≤N) — uploads via `POST /media` ✅, but **front-end upload failure to diagnose**
- 🔧 Render media grid in the feed — **broken** (stale `_mvs_file_url`/`wp_get_attachment_url`; fix via `MediaUrlResolver`)
- ⬜ Open feed media in BN lightbox (mvs lightbox removed → BN-native)
- ⬜ Video/audio post playback (BN player)
- ⬜ Single-media activity (one media item as its own card)
- ⬜ Post↔media link via `object_media` (`bn_post`) instead of a JSON column (migration-safe)

### 2.3 Viewer — another member's profile / space
- ⬜ Sees only permitted media (privacy + block enforced via `mvs_privacy_can_view` + BN gate)
- ⬜ No private/removed media leaks into grids, lightbox, or feed

### 2.4 Space owner / admin — their space (`/spaces/<slug>/`)
- ⬜ Space "Media" tab — media scoped to the space (`object_type='bn_space'` / album `group_id=space_id`)
- ⬜ Space albums/galleries
- ⬜ Upload to the space (members per space role)
- ⬜ Space-members-only media privacy
- ⬜ Moderate / remove media in their space

### 2.5 Site admin
- ⬜ Media management (list/search/delete) — decide: BN admin screen vs WPMediaVerse wp-admin pages (wp-admin is acceptable — not BN front-end UX)
- ⬜ Moderate reported media → `bn_reports` queue (BN-side)
- ⬜ Settings (allowed types, storage driver, quotas) — WPMediaVerse admin/Pro (config)

### 2.6 DM media (after BN-native DM — Phase 4)
- ⬜ Attach media/voice/file to a DM (`POST /messages/upload`)
- ⬜ Render media in the BN DM thread
- ⬜ Replace the `mvs-messaging` JS enqueue with BN-native DM UI (closes the API-only violation)

### 2.7 Cross-cutting (every surface)
- ⬜ Privacy most-restrictive-wins + block/suspension exclusion
- ⬜ Scale: cursor pagination, no N+1 on grids/galleries
- ✅ No `mvs-*` JS/CSS on BN (lightbox enqueue removed; **messaging enqueue still TODO** — Phase 4)
- ⬜ Dark mode + `--bn-*` tokens + Lucide + no emoji on all media UI
- ⬜ Migration-safe links (`object_type`/`container_type`)

## 3. Modular build phases (incremental, each shippable)

- **Phase 0 — Foundation module.** Build `includes/Media/` core: `MediaClient`,
  `MediaUrlResolver`, `ObjectMediaLink`, `MediaRenderer`. Fix the activity-feed
  render (the immediate broken case) by routing through `MediaUrlResolver`. No new
  surfaces yet — just the spine + the one fix.
- **Phase 1 — Activity media.** Composer uploader (BN), feed media grid, BN
  lightbox, video/audio players, single-media card; switch post↔media to
  `object_media`. Diagnose + fix the upload failure here.
- **Phase 2 — Profile galleries + albums.** Profile "Media" section, album CRUD.
- **Phase 3 — Space media.** Space "Media" tab + space albums, space-scoped privacy.
- **Phase 4 — DM media + BN-native DM UI.** Replace the `mvs-messaging` enqueue
  with BN's own DM threads (consuming `mvs/v1` + `mvs-pro/v1/groups`), media in DMs.
- **Phase 5 — Polish.** Audio waveform, video transcode/poster (Pro), admin/
  moderation, Pro storage, performance pass.

## 4. Open diagnostics (triage before/within phases)
| Symptom | Likely owner | Note |
|---|---|---|
| Activity media upload fails (front-end) | BN + maybe engine | engine `handle()` works server-side; check REST response / stray output |
| Feed media not rendering | **BN** | stale URL resolution → Phase 0 `MediaUrlResolver` |
| `mvs-lightbox.js` 404 | **BN** ✅ fixed | dead enqueue removed |
| "What's new" composer shaking | **BN** | front-end layout/JS — investigate (composer/poll) |
| `/p/58/` + general slowness | **BN** (perf) | N+1 / heavy query — profile |
| `mvs-messaging.js` loaded on BN | **BN** | violates API-only → Phase 4 BN-native DM |
| WPMediaVerse REST/asset bugs | **WPMediaVerse team** | keep pulling free+pro |

## 5. Ownership split
- **BuddyNext (this work):** the `includes/Media/` module + every UX surface + the
  access wiring + DM UI + the checklist above.
- **WPMediaVerse team:** engine API correctness, missing assets, upload-pipeline
  bugs; review/merge the `1.6.0` engine asks (E1/E2/E3) I delivered.
