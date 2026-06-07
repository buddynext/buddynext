# Conformance — Private Messaging (WPMediaVerse-bridged)

**Feature:** Private Messaging / Direct Messaging (1:1, free tier)
**Repo:** buddynext (free)
**Checked:** 2026-05-31 (supersedes the earlier `usable-leave-as-is` pass on this same date — see "Why this supersedes")
**Live-walk URL:** http://buddynext-dev.local/messages

## Spec ref

- `docs/specs/features/07-direct-messaging.md` (DM engine owned by WPMediaVerse; BuddyNext is UI layer only)
- `docs/specs/WPMediaVerse-DM-Integration-Requirements.md`
- UX intent: `docs/v2 Plans/v2/dm-list.html`, `docs/v2 Plans/v2/dm-thread.html`

## Verdict

**partial-needs-wiring** — The DM inbox, thread, composer, send, receive, request inbox, reactions, block-gate, and `bn_notifications` routing are all wired end-to-end (UI → `mvs/messaging` Interactivity store → `mvs/v1` REST → service → `mvs_*` tables) and function for any conversation reached from the inbox or via `/messages/{id}`. WPMediaVerse free is active, the messaging module ships in the free build, and the engine is whitelisted in the isolation mu-plugin so it is not front-end-stripped.

One real break remains: the two **member-facing entry points** — the "Message" button on profile connections and on member-directory cards — do not deep-link to the recipient in a form the store reads. Both land the user at the inbox root with no conversation opened or created, defeating the spec's "inject DM link on member profiles and directory cards" intent. The fix is 1-2 lines per entry point reusing a deep-link pattern BuddyNext already emits in `thread.php`.

## Why this supersedes the prior pass

The earlier dossier marked this same entry-point item as gap #2 with `low` severity / `needs-live-verification`, on the assumption the Message button lived in WPMediaVerse profile partials and dispatched the `mvs-open-conversation` event (which the store consumes). Code tracing shows that is not the path: the buttons that render on BuddyNext member profiles and directory cards are BuddyNext templates, and they emit a bare `/messages/` (profile) or a `?recipient={id}` query string (directory) — neither of which the store's `onInit` reads. So the gap is now **confirmed-in-code** and is an actual journey break, not a live-verification unknown.

## Architecture (as built — matches spec)

- BuddyNext is UI shell only; WPMediaVerse owns tables, REST (`mvs/v1`), transport, and the Interactivity store.
- WPMediaVerse free is active and the `includes/Messaging/` module ships in the free build (not excluded by `.distignore`).
- WPMediaVerse whitelisted in the isolation mu-plugin, NOT stripped on front-end — `mu-plugins/buddynext-early-router.php:90`; site `wp-content/mu-plugins/buddynext-isolation.php:117`.
- `/messages*` routing resolves to BuddyNext templates — `includes/Core/PageRouter.php:1121,867`.
- All three templates (`list.php`, `thread.php`, `requests.php`, revised 31 May) uniformly delegate to `do_action('buddynext_render_messages')`. The "two divergent UIs" note in `docs/feature-audit.md` is stale — the parallel hand-rolled server-side thread/requests implementation has been retired (see template headers).
- Bridge handles the render action, prints the two-pane `mvs/messaging` UI + `mvs/v1` config — `includes/Bridges/WPMediaVerseBridge.php:61,112-176`.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| `/messages` route resolves to list template | service | wired | `includes/Core/PageRouter.php:1121,867` |
| `list.php` dependency check + fires render hook | ui | wired | `templates/messages/list.php:29-66` |
| Bridge prints two-pane shell + REST/nonce config | store | wired | `includes/Bridges/WPMediaVerseBridge.php:112-176` |
| MVS not front-end-stripped (isolation whitelist) | service | wired | `mu-plugins/buddynext-early-router.php:90`; site `buddynext-isolation.php:117` |
| `mvs/v1` REST controller registered (~18 routes) | rest | wired | `wpmediaverse/includes/Messaging/MessagingController.php:24,53-308` |
| Conversation list + tabs/search/new bound to store | ui | wired | `wpmediaverse/templates/partials/chat-list.php:14,26,68-91` |
| List auto-loads + polling on landing | store | wired | `wpmediaverse/assets/js/messaging.js:1258-1261`; shell class `WPMediaVerseBridge.php:154` |
| Load conversations from REST | store/rest | wired | `messaging.js:347` → `GET mvs/v1/me/conversations` (`MessagingController.php:53`) |
| Open conversation → load messages | store/rest | wired | `messaging.js:363,375` → `GET mvs/v1/conversations/{id}/messages` (`MessagingController.php:134`) |
| Composer (text/attach/voice/send) bound to store | ui | wired | `wpmediaverse/templates/partials/chat-composer.php:42-60` |
| Send → POST to REST | store/rest | wired | `messaging.js:601` → `POST mvs/v1/conversations/{id}/messages` (`MessagingController.php:157`) |
| Service writes message + fires `mvs_message_sent` | service/db | wired | `MessagingService.php:832`; `wp_mvs_messages` |
| `bn_blocks` gate on send | service | wired | `MessagingService.php:58` `apply_filters('mvs_can_send_message')` → `WPMediaVerseBridge.php:309-329` |
| New message → `bn.new_message` notification + bell badge | service/db | wired | `WPMediaVerseBridge.php:378-444` |
| Deep-link thread `/messages/{id}` opens that conversation | store | wired | `templates/messages/thread.php:87-108` sets `#mvs-chat/{id}` + dispatches `mvs-open-conversation`; `messaging.js:1231,1243` |
| Request inbox accept/decline, mute/pin/archive, reactions, delete/unsend, typing | rest+store | wired | `messaging.js:438,452,630,644`; `MessagingController.php:182-308` |
| **Click "Message" on a member → open/create DM with them** | ui | **broken** | `templates/profile/connections.php:205` (bare `/messages/`); `templates/parts/member-directory-grid.php:129` builds `/messages/?recipient={id}` but store `onInit` reads only `location.hash` + `mvs-open-conversation` event, never `?recipient=` (`wpmediaverse/assets/js/messaging.js:1223-1260`) |
| Dependency fallback when MVS inactive | ui | wired | `templates/messages/list.php:42-53` |

## First break

**Member entry points do not deep-link to the recipient.**
`templates/profile/connections.php:205` links the per-connection "Message" button to a bare `PageRouter::messages_url()` (`/messages/`) with no recipient. `templates/parts/member-directory-grid.php:129` (rendered into `templates/parts/member-card.php:321`) passes one, but as a query string `?recipient={id}` — and the WPMediaVerse store's `onInit` (`wpmediaverse/assets/js/messaging.js:1223-1260`) only inspects `window.location.hash` (`#mvs-chat/{id}`, `#mvs-chat/user/{id}`) and the `mvs-open-conversation` DOM event. The query param is never read, so both entry points land the user at the inbox root with no conversation opened or created. The store already exposes `openWithRecipient` (`messaging.js:289`) for exactly this flow; the entry points just don't invoke it.

Everything downstream — inbox render, send, receive, requests, reactions, notifications, block-gate — is wired and works for any conversation the user opens from the list or reaches via `/messages/{id}`.

## UX gaps

| # | Gap | Severity | Confidence | Evidence |
|---|-----|----------|-----------|----------|
| 1 | Profile-connections "Message" button targets bare `/messages/` — opens empty inbox, not a DM with that person | high | confirmed-in-code | `templates/profile/connections.php:205` |
| 2 | Directory member-card "Message" button passes `?recipient={id}` but the store reads only `location.hash`/event, so the recipient is dropped | high | confirmed-in-code | `templates/parts/member-directory-grid.php:129`; `wpmediaverse/assets/js/messaging.js:1223-1260` |
| 3 | Group DM, read receipts, WebSocket transport | low | confirmed-in-code | Spec'd as WPMediaVerse Pro and marked Pending (`WPMediaVerse-DM-Integration-Requirements.md:235-240`); out of free-journey scope, not a break |

## Minimal refactor plan

BuddyNext already emits the correct pattern in `templates/messages/thread.php:87-97` (set the `#mvs-chat/{id}` hash + dispatch `mvs-open-conversation`). Reuse the recipient variant the store already supports (`#mvs-chat/user/{id}` → `openWithRecipient`, `messaging.js:289,1225`).

1. In `templates/parts/member-directory-grid.php:129`, change the message URL from `add_query_arg(['recipient'=>$id], $base)` to the recipient deep-link form `$base . '#mvs-chat/user/' . $bn_member_id` (matches `onInit`'s `#mvs-chat/user/{id}` branch). No store change needed.
2. In `templates/profile/connections.php:168/205`, build the same per-connection deep-link (`messages_url() . '#mvs-chat/user/' . $conn_id`) instead of the bare `messages_url()`.
3. Optional hardening: also read `?recipient=` in the store `onInit` and call `openWithRecipient`, so any future query-string caller works too. Not required if step 1-2 use the hash form.

No engine, REST, store, or inbox changes. The DM core is correct as-is — only the two entry-point hrefs need wiring.

## Notes for the human browser walk

1. Seed a conversation between two members (custom `wp_mvs_*` tables, not usermeta) before walking, or the list pane correctly shows the empty state.
2. Visit http://buddynext-dev.local/messages as a member; confirm the two-pane shell renders (not the dependency notice) and the rail auto-populates.
3. Open a thread, send a message, confirm it persists and the recipient's bell unread count increments.
4. **Confirm the break:** from a member profile's Connections list and from the Members directory, click "Message" on another member — observe you land on the inbox root with no conversation opened (gaps #1, #2). After the fix, the targeted conversation should open/create automatically.
5. Walk both light and dark mode.
