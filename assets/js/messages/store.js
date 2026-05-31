/**
 * BuddyNext messages store — intentionally empty (no store is registered).
 *
 * Per docs/specs/features/07-direct-messaging.md, BuddyNext is the UI shell
 * only over WPMediaVerse. All Direct Messaging surfaces (/messages/,
 * /messages/{id}/, /messages/requests/) now route through the bridge UI
 * rendered by WPMediaVerseBridge::render_messages(), which is driven entirely
 * by WPMediaVerse's own `mvs/messaging` Interactivity store (sendMessage,
 * acceptRequest, declineRequest, setTab, etc. — wpmediaverse/assets/js/
 * messaging.js).
 *
 * The former native DM templates that bound a `buddynext/messages` store have
 * been retired, so this file deliberately registers NO state and NO actions.
 * It is retained only so AssetService's registration does not error on a
 * missing path. Do not reimplement messaging here — route through the bridge.
 */
