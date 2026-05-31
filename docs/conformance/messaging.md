# Conformance — Private Messaging (WPMediaVerse-bridged)

**Feature:** Private Messaging / Direct Messaging
**Repo:** buddynext (free)
**Checked:** 2026-05-31
**Live-walk URL:** http://buddynext-dev.local/messages

## Spec ref

- `docs/specs/features/07-direct-messaging.md` (DM engine owned by WPMediaVerse; BuddyNext is UI layer only)
- `docs/specs/WPMediaVerse-DM-Integration-Requirements.md`
- UX intent: `docs/v2 Plans/v2/dm-list.html`, `docs/v2 Plans/v2/dm-thread.html`

## Verdict

**partial-needs-wiring** — the engine, REST surface, bridge, and templates are all built and correctly connected, but the conversation list does not auto-load on the `/messages` landing because the store's init guard keys off a CSS class (`.mvs-messages-page`) that the BuddyNext bridge shell (`.bn-msg-shell`) does not carry. Result: a member visiting `/messages` sees an empty conversation rail (and polling never starts) even when they have conversations.

## Architecture (as built — matches spec)

- BuddyNext is UI shell only; WPMediaVerse owns tables, REST (`mvs/v1`), transport.
- WPMediaVerse is whitelisted in the isolation mu-plugin, so it is NOT stripped on the front-end — `wp-content/mu-plugins/buddynext-isolation.php:117-118`.
- `/messages` routing resolves to BuddyNext's own templates — `includes/Core/PageRouter.php:861-870` (`messages/list.php`, `messages/thread.php`, `messages/requests.php`).
- Those templates fire `do_action('buddynext_render_messages')` — `templates/messages/list.php:66`, `templates/messages/thread.php:108`.
- The bridge handles that action and prints the two-pane `mvs/messaging` Interactivity UI + `mvs/v1` config — `includes/Bridges/WPMediaVerseBridge.php:61,147-176`.

## Journey chain

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Open `/messages`, route to messages hub | rest | wired | `includes/Core/PageRouter.php:861-870` |
| `list.php` checks dependency + fires render hook | ui | wired | `templates/messages/list.php:29-66` |
| Bridge prints two-pane shell + REST/nonce config | ui | wired | `includes/Bridges/WPMediaVerseBridge.php:112-176` |
| `mvs/v1` REST controller registered on `rest_api_init` | rest | wired | `wpmediaverse/includes/Core/Plugin.php:1210-1212`; `wpmediaverse/includes/Messaging/MessagingController.php:50` |
| Conversation list renders (`data-wp-each state.conversations`) | ui | wired | `wpmediaverse/templates/partials/chat-list.php:68-87` |
| Conversation list AUTO-LOADS on landing | store | broken | `wpmediaverse/assets/js/messaging.js` onInit loads list only when `.mvs-messages-page` is present; bridge shell is `.bn-msg-shell` — `includes/Bridges/WPMediaVerseBridge.php:154` |
| Open a thread via deep-link `/messages/{id}` | store | wired | `templates/messages/thread.php:82-98` dispatches `mvs-open-conversation`; store onInit listener + `#mvs-chat/{id}` hash handler in `messaging.js` |
| Composer send → `POST /conversations/{id}/messages` | store | wired | `messaging.js` sendMessage; button `data-wp-on--click="actions.sendMessage"` — `wpmediaverse/templates/partials/chat-composer.php:54` |
| New-message → `bn.new_message` notification | service | wired | `includes/Bridges/WPMediaVerseBridge.php:378-444` |
| `bn_blocks` gate on send | service | wired | `includes/Bridges/WPMediaVerseBridge.php:309-329` |
| Requests/accept/decline, mute/pin/archive, reactions, unsend, typing, unread | rest+store | wired | `wpmediaverse/includes/Messaging/MessagingController.php:55-308` + store actions in `messaging.js` |

## First break

Conversation-list auto-load on the `/messages` landing. The store's `onInit` (`wpmediaverse/assets/js/messaging.js`) calls `loadConversations()` + `startPolling()` only when `.mvs-messages-page` is found in the DOM. WPMediaVerse's standalone page template carries that class (`wpmediaverse/templates/messages.php:17`), but the BuddyNext bridge shell uses `.bn-msg-shell` (`includes/Bridges/WPMediaVerseBridge.php:154`) and BuddyNext never adds `.mvs-messages-page` anywhere. Introduced by commit `c417271` ("Messages: reconcile DM UI onto the WPMediaVerse bridge path", 2026-05-31), which moved `/messages` onto the bridge shell without aligning the store's init selector.

## UX gaps

1. **`/messages` landing shows empty conversation rail; no polling starts** — severity: high; confidence: confirmed-in-code. The list panel renders but stays empty on first load (no hash) because `loadConversations()` is gated on `.mvs-messages-page`, absent from the bridge shell. Evidence: `messaging.js` onInit guard vs `includes/Bridges/WPMediaVerseBridge.php:154`. App/REST clients are unaffected — `GET mvs/v1/me/conversations` works directly.

2. **Deep-linked thread (`/messages/{id}`) opens the thread but leaves the left rail empty** — severity: medium; confidence: confirmed-in-code. The `mvs-open-conversation` / `#mvs-chat/{id}` paths in onInit load that conversation's messages but never call `loadConversations()`, so the sidebar list is blank while a thread is open. Evidence: hash/event branches in `messaging.js` onInit do not invoke `loadConversations`.

3. **Profile / directory "Message" entry point** — severity: low; confidence: needs-live-verification. Spec says the bridge injects a DM link on member profiles and directory cards (`07-direct-messaging.md:76`). The BuddyNext bridge injects only a "Media" nav item (`WPMediaVerseBridge.php:190-207`); the actual Message button lives in WPMediaVerse's `templates/partials/profile-actions.php` and dispatches `mvs-open-conversation`. Whether it surfaces inside BuddyNext member profile templates (vs only WPMediaVerse profile pages) needs a live walk.

## Minimal refactor plan

1. Make the store's list-load fire for the BuddyNext bridge shell. Cheapest, code-reusing fix: add the `mvs-messages-page` class to the bridge shell wrapper alongside `bn-msg-shell` in `includes/Bridges/WPMediaVerseBridge.php:153-157`. The store's existing onInit then auto-loads conversations and starts polling with no JS change. (Alternative, if the WPMediaVerse repo is in scope: broaden the onInit selector to also match `.bn-msg-shell`.)
2. Ensure the rail populates when landing directly on a thread: in the bridge-shell case, also call `loadConversations()` on the `#mvs-chat/{id}` and `mvs-open-conversation` branches — covered automatically by fix #1 if the `mvs-messages-page` class is always present on the shell regardless of deep-link.
3. Live-verify the profile/directory "Message" button renders on BuddyNext member profiles; if it only appears on WPMediaVerse-owned profile pages, surface it on the BuddyNext profile template (gap #3).

## Notes

- Send, accept/decline requests, mute/pin/archive, reactions, unsend, typing, attachments, voice messages, and unread-count are all wired template→store→`mvs/v1`. The break is specifically the initial population of the conversation list in the BuddyNext shell, not the messaging actions themselves.
