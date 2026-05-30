# Conformance — Private Messaging (WPMediaVerse-bridged)

**Repo:** buddynext (free)
**Feature:** Direct Messaging — BuddyNext is the UI layer over the WPMediaVerse DM engine.
**Spec refs:**
- `docs/specs/features/07-direct-messaging.md` (Locked)
- `docs/specs/WPMediaVerse-DM-Integration-Requirements.md` (Locked)
**UX intent:** `docs/v2 Plans/v2/dm-list.html`, `docs/v2 Plans/v2/dm-thread.html`
**Cross-cutting:** `docs/specs/REST-FRONTEND-CONTRACT.md`, `docs/specs/SCALE-CONTRACT.md`, `docs/specs/features/17-roles-permissions.md`
**Live-walk URL:** http://buddynext-dev.local/messages

---

## Verdict: partial-needs-wiring

The DM journey is **not completable today on the live site**, for two independent reasons:

1. **Environment / install gap (the dominant, current blocker).** The required soft-dependency plugin **WPMediaVerse is not installed or active** on the live site. Live `wp option get active_plugins` = `buddynext-pro, buddynext, jetonomy-pro, jetonomy`. WPMediaVerse exists only at `/Users/vapvarun/dev/repos/wpmediaverse` and is **not symlinked** into `wp-content/plugins/` (only `buddynext` and `buddynext-pro` are symlinked there; `jetonomy*` are real dirs). The isolation mu-plugin whitelist (`wp-content/mu-plugins/buddynext-isolation.php`) lists `wpmediaverse/wpmediaverse.php`, confirming it is *expected* to be present — but it isn't. With WPMediaVerse absent, `/messages` renders the spec-sanctioned "Direct messaging requires WPMediaVerse" dependency notice (`templates/messages/list.php:42-53`). Graceful, but the journey cannot start.

2. **Code break, latent until WPMediaVerse is installed (confirmed in code).** BuddyNext ships **two parallel DM front-ends**. The list pane works through the bridge; the **thread + requests templates are inert**. They bind Interactivity directives to `data-wp-interactive="buddynext/messages"`, but that store (`assets/js/messages/store.js`) is **intentionally empty** — it registers no `state` and no `actions`. So the composer Send button, emoji/attach buttons, and the request Accept/Decline/Block buttons are wired to actions that do not exist. No JS file anywhere registers a `buddynext/messages` store with those actions (grep across `assets/` returns only the empty stub).

The working DM UI is the *other* path: `/messages/` (`bn_conv_id == 0`) → `messages/list.php` → `do_action('buddynext_render_messages')` → `WPMediaVerseBridge::render_messages()` → MVS chat partials driven by WPMediaVerse's own `mvs/messaging` store (which DOES define `sendMessage`, `acceptRequest`, etc. — `wpmediaverse/assets/js/messaging.js:107,433,559`). That path is spec-correct. The native `thread.php` / `requests.php` / `dm-*` parts are a contradictory second implementation that the spec does not call for ("BuddyNext has no own DM tables… pure UI" — `07-direct-messaging.md:20-23`).

---

## Journey chain

Entry: member opens `/messages`, sees inbox, opens a thread, sends a message; unknown-sender requests can be accepted.

| Step | Layer | Status | Evidence |
|------|-------|--------|----------|
| Route `/messages/` → list template | rest/service | wired | `includes/Core/PageRouter.php:844-853` (dispatch), `:1098-1116` (rewrite) |
| Auth gate (guests → login) | service | wired | `includes/Core/PageRouter.php:186-197` |
| List template renders inbox via bridge | ui | wired (code) / unknown (live) | `templates/messages/list.php:29-67` — delegates to `buddynext_render_messages` |
| Bridge renders MVS chat UI (list + thread + composer) | service | wired (code) | `includes/Bridges/WPMediaVerseBridge.php:61,147-176` |
| MVS engine: REST `mvs/v1/me/conversations`, store actions | rest/db | wired-in-WPMediaVerse | `wpmediaverse/includes/Messaging/MessagingController.php`, `assets/js/messaging.js:107,559` |
| **WPMediaVerse present at runtime** | db/service | **missing (live)** | live `active_plugins` lacks `wpmediaverse`; not symlinked in `wp-content/plugins/` |
| Open thread `/messages/{id}/` (or `?conversation=`) → `thread.php` | ui | broken | `PageRouter.php:847-848`; `templates/messages/thread.php:225-227` binds `buddynext/messages` |
| Composer Send / emoji / attach | store | broken | `templates/parts/dm-composer.php:81,87,94,116` → `actions.sendMessage` etc.; store empty `assets/js/messages/store.js` (no actions) |
| Requests inbox: Accept / Decline / Block | store | broken | `templates/messages/requests.php:126,298,308,319` → `actions.acceptRequest` etc.; store empty |
| New-message notification fan-out | service | wired | `WPMediaVerseBridge.php:378-444` (`mvs_message_sent` → `bn_notifications`) |
| Block gate on send | service | wired | `WPMediaVerseBridge.php:309-329` (`mvs_can_send_message` → `bn_blocks`) |

---

## First break

**WPMediaVerse is not installed/active at the live site** — `/messages` shows the dependency notice; the journey cannot start (`templates/messages/list.php:42-53`; live `active_plugins`). Behind that, even once WPMediaVerse is installed, the **second break** surfaces immediately on opening a thread: `templates/messages/thread.php` + `parts/dm-composer.php` bind to the empty `buddynext/messages` store (`assets/js/messages/store.js`), so sending a message does nothing.

---

## UX gaps

| Gap | Severity | Confidence | Evidence |
|-----|----------|------------|----------|
| WPMediaVerse (soft dependency) not installed/active on the live site → entire DM surface is the dependency-notice fallback | critical | needs-live-verification | live `active_plugins`=`buddynext-pro,buddynext,jetonomy-pro,jetonomy`; `wp-content/plugins/` has no `wpmediaverse` symlink; whitelist expects it (`mu-plugins/buddynext-isolation.php`) |
| Native DM thread template's composer (Send/emoji/attach) bound to an empty Interactivity store → no message can be sent via this template even with WPMediaVerse active | critical | confirmed-in-code | `templates/parts/dm-composer.php:81,87,94,116`; `templates/messages/thread.php:227`; `assets/js/messages/store.js` (empty) |
| Requests inbox Accept/Decline/Block bound to the same empty store → request triage non-functional via this template | high | confirmed-in-code | `templates/messages/requests.php:298,308,319`; `assets/js/messages/store.js` |
| Two contradictory DM front-ends ship in free: bridge path (`list.php`→MVS partials, works) vs native path (`thread.php`/`requests.php`/`dm-*`, inert). Spec mandates UI-only-over-bridge | high | confirmed-in-code | spec `07-direct-messaging.md:20-23`; `WPMediaVerseBridge.php:147-176` vs `templates/messages/thread.php`, `templates/parts/dm-*.php` |

---

## Minimal refactor plan

1. **Install/activate WPMediaVerse free** on the live site (symlink `/Users/vapvarun/dev/repos/wpmediaverse` into `wp-content/plugins/wpmediaverse` and activate), matching the isolation whitelist. Re-walk `/messages` to confirm the bridge path renders the MVS inbox instead of the dependency notice.
2. **Resolve the duplicate front-end.** Make `/messages/{id}/` and `/messages/requests/` route through the same bridge UI that `/messages/` uses, OR finish the native store. Prefer routing through the bridge (the spec's "UI-only over WPMediaVerse" model):
   - In `PageRouter::resolve_hub_template()` (`:844-853`) point the thread + requests sub-routes at `messages/list.php` (the bridge wrapper) so the MVS two-pane UI handles thread + requests itself, and retire the inert `thread.php` / `requests.php` / `parts/dm-*.php` + the empty `assets/js/messages/store.js`.
   - If the native templates must stay for SSR/SEO, implement the `buddynext/messages` Interactivity store (`sendMessage`, `acceptRequest`, `deleteRequest`, `blockSender`, `openEmojiPicker`, `openAttachment`, `onPanelSearchInput`, `switchPanelTab`, `onInputKeydown`, `onMessageInput`, `clearReply`) calling the same `mvs/v1` endpoints, and register it in `AssetService` (`:319`).

(Note: the bridge wiring itself — block gate, notification fan-out, render hook — is correct and should NOT be touched.)
