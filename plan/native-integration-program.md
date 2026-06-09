# BuddyNext — Native Integration Program

**Status:** planning (locked architecture; phased build pending go-ahead)
**Owner direction (2026-06-09):** every integration is API-level only; no 2nd-party
screens; BN pages load only BN assets; partner data is rendered with BN's own
native, consistent components for one uniform 1-to-1 experience — never jumping
between plugins.

---

## 1. Locked architecture

**BuddyNext is the single native UI layer for the community. Every partner plugin
is a headless data/API provider.** BN consumes partner data via the partner's API
and renders it with BN's own components. Concretely:

- **No 2nd-party screens** embedded in BN pages.
- **No link-outs** that jump the member to another plugin's UI.
- **No foreign CSS/JS** on BN routes — uniform, conflict-free.
- **Consistent tabs/cards/elements** across every integrated surface.

## 2. The reference pattern — `includes/Media/` (already proven)

BN already consumes WPMediaVerse **media** this way and renders it natively in the
activity feed. This package is the template every other integration copies:

| Layer | Media example | Role |
|---|---|---|
| **Client** | `Media/MediaClient.php` (guarded `available()`, `repo()`, `messaging()`) | thin, guarded access to the partner's services/REST |
| **Domain** | `Media/Galleries.php` | BN-side queries + BN privacy/visibility gating |
| **Renderer** | `Media/MediaRenderer.php` | **BN-native markup** (grid, card) — never partner UI |
| **Assets** | `Media/MediaAssets.php` (enqueues BN JS configured with `mvsRest = rest_url('mvs/v1')`) | BN's own JS/CSS talking directly to the partner REST API |
| **Surface** | rendered in `templates/parts/post-body.php` with BN components | lives inside BN pages |

Every new integration = a `includes/<Domain>/` package shaped like this.

## 3. Phases (foundation → bounded → large native builds)

### Phase 0 — Asset isolation (foundation) · bounded · ✅ DONE
`includes/Core/AssetIsolation.php` (bound `asset_isolation`, init beside
`AssetService`). On BN routes (`PageRouter::is_bn_route()`), at `wp_enqueue_scripts`
priority 9999, dequeues every style/script/script-module whose `src` isn't
allowlisted (WP core, active theme, BuddyNext, BuddyNext Pro). Script modules
handled via reflective read of `WP_Script_Modules::$queue`/`$registered` (no
public enumeration API), bailing gracefully if internals move. Seams:
`buddynext_asset_isolation_enabled` (master, default true) + `buddynext_allowed_assets`
(URL-prefix allowlist). Verified: foreign style+script+module stripped on BN
routes, all survive on non-BN pages, BN/theme/core assets untouched, zero console
errors on /messages/ + /activity/. Delivers the uniform-UX guarantee the rest relies on.

### Phase 1 — BN-native gap UIs · bounded (already pattern-mapped)
Reactions palette setting · invites single+revoke · approval-queue Members tab ·
announcements composer mode. All BN-native; independent of the bridge work.

### Phase 2 — Career Board → API + native jobs surface · medium
> Supersedes the earlier "search-only" call: per "native for everything", jobs get
> a native BN surface, not just search.
- Fix the **dead bridge guard** (`wcb_get_job`/`WCB_Career_Board` don't exist in
  the real plugin) + correct hook signatures.
- New `includes/Jobs/` package (Client → domain → renderer → assets) consuming the
  Career Board API; native BN jobs hub/tab; keep search indexing. No partner screens.

### Phase 3 — Messaging → native (from WPMediaVerse) · 🔴 large · IN PROGRESS
**Discovery:** MVS exposes a full `mvs/v1` messaging REST API
(`MessagingController`): `GET/POST /conversations`, `GET/PATCH/DELETE
/conversations/{id}`, `GET/POST /conversations/{id}/messages`,
`POST /conversations/{id}/read|typing|accept`, `DELETE|unsend /messages/{id}`,
`/messages/{id}/reactions`, `GET /messages/poll` (realtime). BN already has
**orphaned native partials** `templates/parts/dm-{rail,rail-item,thread-header,
thread-messages,message,composer,delete-modal}.php` and a **16-line stub**
`assets/js/messages/store.js` that currently defers to MVS's own JS store.

**Takeover (not build-from-zero):**
1. Real BN Interactivity store `assets/js/messages/store.js` consuming `mvs/v1`
   (list conversations, open thread, send, mark-read, `/messages/poll` for live
   updates, accept/decline requests). Config (`mvsRest`, nonce) injected the same
   way `Media/MediaAssets.php` does.
2. Wire the native `dm-*` partials into `templates/messages/{list,thread,requests}.php`
   (two-pane: `dm-rail` conversation list + `dm-thread-*` + `dm-composer`).
3. **Remove the embed:** the `do_action('buddynext_render_messages')` in those
   templates + the bridge's `render_messages()`, `mvs_before/after_content`
   shell-wrap, and `mvs_buddynext_active`→true.
4. **Keep** (API-level): `mvs_can_send_message` block enforcement, `mvs_message_sent`
   → BN notification, `mvs_comment_created` data sync.
5. Partner APIs are WBcom-owned + extensible — add any missing `mvs/v1` endpoint
   to WPMediaVerse if the native UI needs it.

#### Phase 3 — grounded build spec (source-verified 2026-06-09)

**mvs/v1 messaging REST (auth: cookie + `X-WP-Nonce` header):**
- `GET /me/conversations?tab=all|unread|requests&per_page&page` → conversation[]
- `POST /conversations {recipient_id}` → `{conversation, created}`
- `GET|PATCH|DELETE /conversations/{id}`
- `GET /conversations/{id}/messages?before&per_page` → message[]
- `POST /conversations/{id}/messages {content, message_type, attachment_id, media_id, parent_id, metadata}` → message
- `POST /conversations/{id}/read` · `/typing` · `/accept` · `/decline`
- `DELETE /messages/{id}` · `DELETE /messages/{id}/unsend`
- `POST|DELETE /messages/{id}/reactions {emoji}`
- `GET /messages/poll?since&conversation_id` → `{messages, typing[], online_users{}, server_time}`
- `GET /me/messages/unread-count` → `{unread}`
- `POST /messages/upload` (multipart) → `{id, source_url, thumbnail, type}`

**Server-render (PHP, in-process via `MediaClient::messaging()`):**
`get_conversations($uid,$tab,$per_page,$page)` → rows w/ `id,type,title,last_message_preview,last_activity_at,last_read_at,is_pinned,participant_status,participants[],unread_count`; participant: `id,role,display_name,avatar_url,status,is_online`. `get_messages($cid,$uid,$before,$per_page)` → `id,sender_id,content,message_type,parent_id,created_at,sender_name,sender_avatar,reactions[],parent_preview,attachment,media_share`.

**Native partials' required store contract (`buddynext/messages`):**
- state: `replyToId`, `replyToText`, `confirmOpen`
- actions: `onPanelSearchInput`, `switchPanelTab`, `openDeleteConfirm`, `openThreadOptions`, `toggleReaction`(data-msg-id,data-emoji), `clearReply`, `openEmojiPicker`, `openAttachment`, `onInputKeydown`, `onMessageInput`, `sendMessage`, `closeDeleteConfirm`, `stopPropagation`, `confirmDeleteConversation`
- dm-rail expects: pinned/recent conversation rows + helper callbacks `initials_fn/tone_fn/relative_fn/online_fn`; dm-rail-item conversation keys: `id, other_user_id, other_user_name, last_message_preview, last_message_at, unread_count, other_user_typing, is_pinned`.

**Embed to remove (the dead code, per owner's no-2nd-party-screens rule):**
`do_action('buddynext_render_messages')` in templates/messages/{list,thread,requests}.php (+ their MVS-driving inline scripts); WPMediaVerseBridge `render_messages()`, `open/close_hub_shell` (`mvs_before/after_content`), `mvs_buddynext_active`→true; the 16-line stub `assets/js/messages/store.js`.

**Config enqueuer:** new `includes/Messages/MessagesAssets.php` mirroring `Media/MediaAssets.php` — `is_bn_front()` gate, enqueue `assets/js/messages/store.js` (dep wp-interactivity), inject `{mvsRest: rest_url('mvs/v1'), nonce: wp_create_nonce('wp_rest'), userId}`.

**Build order (each verified before next):** (1) MessagesAssets + register in Plugin boot → (2) messages/store.js full store → (3) wire dm-* partials in the 3 templates via MediaClient::messaging() server-render → (4) strip embed + dead stub → (5) two-user send/receive/requests browser verify.

### Phase 4 — Discussions → native (from Jetonomy) · 🔴 large
- **Remove** the shell-wrap (`jetonomy_before/after_content`) +
  `jetonomy_show_community_nav`→false.
- New `includes/Discussions/` package consuming Jetonomy data; BN-native discussion
  list/thread views inside BN.
- **Keep** (API-level): post/reply search indexing, reply notifications, rail link,
  space forum tab, hashtag cross-link, profile count.

### Phase 5 — Sweep · bounded
Gamification tiles (already BN-native via `buddynext_profile_extra_data`) +
any remaining bridge surface audited for: zero embedded screens, zero foreign
assets, consistent BN components.

## 4. Sequencing recommendation

0 → 1 → 2 deliver the foundation + close the real gaps + prove the native-jobs
package on a smaller surface. 3 and 4 are large (native messaging + forums) and each
should land as a dedicated effort with code + browser verification before the next.

## 5. Honest scale note

Phases 3–4 are substantial — rebuilding messaging and forums as native BN surfaces
consuming partner APIs. The `includes/Media/` package proves the pattern works and
de-risks it, but this is multi-week work, not a single pass. Each phase ships
verified (code + two-role browser walk) before the next begins.
