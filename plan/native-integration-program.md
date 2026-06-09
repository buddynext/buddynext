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

### Phase 0 — Asset isolation (foundation) · bounded
Core pass beside `AssetService`: on BN hub routes, at a late `wp_enqueue_scripts`
priority, dequeue every style/script/script-module whose `src` isn't allowlisted
(WP core, active theme, BuddyNext/Pro). Filter `buddynext_allowed_assets`. On by
default. Delivers the uniform-UX guarantee the rest relies on.

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
