# QA — Direct Messaging (free)

**Manifest refs:** tables: none own — DM engine tables are WPMediaVerse `mvs_conversations` / `mvs_messages` / etc. (NOT `bn_*`); BuddyNext gates on `bn_blocks` and routes events into `bn_notifications` · REST routes: consumes `mvs/v1/*` (engine) — BuddyNext owns no DM REST · services: WPMediaVerseBridge, MessagesData (`MessagesData::available()` presence gate) · capabilities: gated via `buddynext_can( $id, 'send-dm', [ 'recipient_id' => ... ] )`
**Cross-ref (no dup):** JOURNEYS J-32 (profile message button — blocked) · J-45 (DM list — blocked) · J-46 (DM thread — blocked) · J-47 (DM request accept — blocked) · FLOW-TEST-MATRIX M15 (member DM)
**Admin location:** BuddyNext → Settings → General (`buddynext_enable_dm` DM baseline toggle). DM UI is frontend-only at `/messages/`, `/messages/{id}/`, `/messages/requests/`.

> **DEPENDENCY STATE ON THIS SITE — READ FIRST.** WPMediaVerse is **NOT installed/active** here (active plugins: `buddynext`, `buddynext-pro` only). `MessagesData::available()` returns `false`, so every DM route renders the **dependency notice** and `return`s early before any rail or thread. The dependency-notice path is therefore the testable one. All thread / send / accept / request journeys (J-45/J-46/J-47, M15) are **BLOCKED on the WPMediaVerse dependency** and cannot be exercised until the engine is installed — their QA cases below are written but marked **BLOCKED**.

---

## 1. Backend settings & options (justify each)

DM owns exactly one baseline option in Free. Everything else lives in the WPMediaVerse engine, not BuddyNext.

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_enable_dm` | Settings → General | `true` (NOT SET on this site → default `true` applies) | Intended master switch: enable Direct Messaging for members **[TOGGLE-BUG]** | Questionable | **Read-never-consumed gap:** registered + rendered in `Admin/Settings.php` (line 38 registration, lines 573-576 render) but **no template or front-end gate reads it** — `native.php` gates only on `MessagesData::available()` (WPMediaVerse presence). Turning this toggle OFF has zero effect on whether the DM UI / nav entry shows. Also subject to the global toggle-OFF bug (`render_toggle_row()` emits no preceding hidden input). |
| `buddynext_default_dm_access` | Settings → General | `'followers'` (verify) | Intended: who may DM a member (everyone / followers / connections / nobody) | Questionable | Cross-ref component 11. Confirm whether the WPMediaVerseBridge `mvs_can_send_message` gate actually reads this option or only `bn_blocks` — bridge `check_block()` reads blocks; per-access-level enforcement is **(verify)** and may be unwired in Free. |

**Note on the DM engine surface:** conversations, messages, requests, read receipts, typing, reactions, and media all live in WPMediaVerse (`mvs_*` tables, `mvs/v1/*` REST). BuddyNext's only owned DM responsibilities are: (1) presence gate via `MessagesData::available()`; (2) block enforcement via `WPMediaVerseBridge::check_block()` on the `mvs_can_send_message` filter; (3) routing `mvs_message_sent` into `bn_notifications` (type `bn.new_message`) so BuddyNext notifications/email prefs own delivery; (4) declaring `mvs_buddynext_active` true so the engine suppresses its own chat panel / messages page / notifications.

---

## 2. Frontend functions (function-by-function)

All three routes are thin shims that delegate to `templates/messages/native.php` (the real two-pane renderer) with different `active_conv_id` / `active_tab`. native.php consumes WPMediaVerse at the `mvs/v1` REST level only — no MVS screens are embedded.

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| DM list (all / unread tabs) | `/messages/` → `templates/messages/list.php` → `native.php` (tab from `?tab=all\|unread\|requests`) | `mvs/v1/conversations` (engine) | **Engine present:** two-pane shell — conversation rail (left) + empty/active thread (right). **Engine absent (this site):** dependency notice only. Guest gate enforced upstream in `PageRouter::dispatch_hub_template()` (messages is a login-required hub → guests redirect to `auth_url()`). |
| DM thread | `/messages/{id}/` → `thread.php` → `native.php` (`active_conv_id` = `bn_conv_id` query var) | `mvs/v1/conversations/{id}/messages` (engine) | **Engine present:** opens the requested conversation in the right pane; message bubbles (own = brand, other = surface), composer bar, read receipts, typing. **Engine absent:** dependency notice. Defensive fallback parses legacy `?conversation=` query string when `bn_conv_id` is 0. |
| DM requests | `/messages/requests/` → `requests.php` → `native.php` (`active_tab` = `requests`) | `mvs/v1/conversations/{id}/accept` · `mvs/v1/conversations/{id}/decline` (engine) | **Engine present:** Requests tab pre-filtered; per-request Accept / Decline / Block actions (`dm-request-banner.php`). **Engine absent:** dependency notice. |
| Profile "Message" button | Profile view (`templates/profile/view.php`) | opens `/messages/?to={id}` / engine compose | **Engine present:** routes to compose against that member, subject to `send-dm` ability + `bn_blocks`. **Engine absent:** button should be hidden or land on the dependency notice (verify which — J-32 is BLOCKED). |
| Dependency notice | any `/messages/*` route when `MessagesData::available()` is false | none | Renders a single `.bn-card.bn-dm-dep-notice` with: a `message-circle` icon, a `data-tone="warn"` badge **"Dependency required"**, an `<h2>` **"Direct messaging requires WPMediaVerse"**, and body **"Install and activate the WPMediaVerse plugin to enable direct messaging in BuddyNext."** Then `return`s — no rail, no thread, no console errors, no `mvs/v1` fetches. |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-DM-001 | member | frontend | WPMediaVerse NOT installed (this site); logged in via `?autologin=member1` | Navigate to `/messages/` | Dependency notice renders: warn badge "Dependency required", title "Direct messaging requires WPMediaVerse", body "Install and activate the WPMediaVerse plugin…". No conversation rail. No JS console errors. No `mvs/v1` network requests fire | 1440px, 768px, 390px |
| QA-DM-002 | member | frontend | Engine absent | Open `/messages/{id}/` for any id (e.g. `/messages/5/`) | Same dependency notice as QA-DM-001 (thread shim delegates to native.php which returns early); no fatal, no empty thread pane | 1440px, 390px |
| QA-DM-003 | member | frontend | Engine absent | Open `/messages/requests/` | Same dependency notice; Requests tab is never reached because `available()` short-circuits before tab rendering | 1440px, 390px |
| QA-DM-004 | member | frontend | Engine absent; dependency notice visible | Inspect notice markup + at 390px | `.bn-dm-dep-notice` card is full-width, no horizontal scroll; icon + badge on one row, title + body stack; tokens drive colors (warn tone), dark mode honored via `[data-bn-theme="dark"]` | 390px, 1440px (dark) |
| QA-DM-005 | guest | frontend | Logged out | Navigate directly to `/messages/` | Redirected to `auth_url()` (login) — guest gate enforced in `PageRouter::dispatch_hub_template()` before any DM template loads; never sees the dependency notice | 1440px, 390px |
| QA-DM-006 | admin | backend | Settings → General | Toggle `buddynext_enable_dm` OFF; Save; reload `/messages/` as a member | **EXPECTED TO FAIL / NO-OP:** DM surface behaviour is unchanged because no front-end gate reads `buddynext_enable_dm`; only WPMediaVerse presence (`MessagesData::available()`) governs the UI. Also DB value likely reverts ON due to global toggle bug. Verify with `wp option get buddynext_enable_dm --path="/Users/varundubey/Local Sites/buddynext/app/public"` | 1440px |
| QA-DM-007 | member | api | Engine absent | `GET {rest_url}mvs/v1/conversations` with a valid nonce | 404 `rest_no_route` — the `mvs/v1` namespace does not exist on this site (engine not installed). Confirms BuddyNext owns no DM REST of its own | n/a |
| QA-DM-008 | member | frontend | **BLOCKED** — requires WPMediaVerse installed + active | Install/activate WPMediaVerse; seed 2 members with a conversation; open `/messages/{id}/`; send a message | Message persists in `mvs_messages`; thread updates; `mvs_message_sent` fires → `bn_notifications` row (type `bn.new_message`) created for recipient. **Cannot run on this site** (J-46) | 1440px, 390px |
| QA-DM-009 | member | frontend | **BLOCKED** — requires WPMediaVerse | Member A blocks Member B (creates `bn_blocks` row); B attempts to DM A | `WPMediaVerseBridge::check_block()` returns false on `mvs_can_send_message`; send rejected; no `mvs_messages` row, no notification. **Cannot run on this site** | 1440px |
| QA-DM-010 | member | frontend | **BLOCKED** — requires WPMediaVerse | Open `/messages/requests/`; Accept an inbound request | `mvs/v1/conversations/{id}/accept` succeeds; conversation moves from Requests to All; request banner clears. **Cannot run on this site** (J-47) | 1440px, 390px |
| QA-DM-011 | member | frontend | **BLOCKED** — requires WPMediaVerse | From a member profile, click the "Message" button (J-32) | Routes to compose against that member; subject to `send-dm` ability + block check. **Cannot run on this site** — verify J-32 also confirms the button is hidden/disabled when engine absent | 1440px, 390px |
| QA-DM-012 | member | frontend | **BLOCKED** — requires WPMediaVerse | With engine active, open `/messages/` and confirm BuddyNext owns the UX | Two-pane native shell renders (no WPMediaVerse chat panel, no MVS standalone page) — confirms `mvs_buddynext_active` filter suppression works; WPMediaVerse JS/CSS never enqueued on BN pages | 1440px, 390px |

---

## 4. Site-owner expectations & suggestions

- **`buddynext_enable_dm` is a dead toggle (read-never-consumed).** The owner sees an "enable Direct Messaging" switch in Settings → General and reasonably expects toggling it OFF to hide DMs site-wide. It does nothing — the DM UI is gated solely by WPMediaVerse presence. Either wire the option into `MessagesData::available()` / `PageRouter` messages-hub gating + the nav entry, or remove the toggle so the UI doesn't lie. Priority: high (contract integrity — a setting that reads but is never applied).

- **No in-product signal that DM needs WPMediaVerse until you open `/messages/`.** A site owner enabling community features has no admin-side hint that DMs require a separate engine plugin. Surface a notice in Settings → General next to `buddynext_enable_dm` ("Direct messaging requires the free WPMediaVerse plugin") and/or an Addons/IntegrationHub card. Priority: medium.

- **Per-member / per-access-level DM permission enforcement is unverified in Free.** `buddynext_default_dm_access` (everyone/followers/connections/nobody) is exposed, but the only confirmed runtime gate is `bn_blocks` via `check_block()`. Confirm whether `mvs_can_send_message` actually honors the access level; if not, the setting is another read-never-applied gap. Add a per-member override in profile privacy settings either way. Priority: medium.

- **Dependency notice is a dead end — no install CTA.** The notice tells the owner WPMediaVerse is required but offers no link to install/activate it. Add a "Install WPMediaVerse" button (plugin-install link for `manage_options`, plain text for members) so the owner can act from where the problem surfaces. Priority: low.

- **`bn.new_message` notification + email prefs rely on the engine firing `mvs_message_sent`.** Because DM delivery notifications are owned by BuddyNext but triggered by WPMediaVerse, a version mismatch in the engine's hook signature would silently drop all new-message notifications. Add a smoke check (or a `mvs_message_sent` arg-count guard) so this fails loudly, not silently. Priority: low.
