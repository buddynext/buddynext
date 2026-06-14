# Centralised Notification Aggregation — BuddyNext as the one notification center

**Status:** 🟡 PLAN (build after lock). **Scope:** all integrations (Career Board,
Jetonomy, WPMediaVerse, + future). Realises the locked-model promise: *"One
notification center — BuddyNext aggregates events from every bridge."*

## Goal

Every Wbcom plugin has its own notifications menu. A member lives in BuddyNext (the
hub), so **BN's notification center must show ALL of them** — jobs, discussions,
messages, courses, events — in one place, using BN's existing notification system
(`bn_notifications` + the bell + `/notifications/`). Each plugin keeps its own menu
(we never touch partner core); BN is the unified view on top.

## Survey (manifest-first, 2026-06-14)

| Plugin | Store / menu | Mirror seam | Events |
|---|---|---|---|
| Jetonomy | `jt_notifications` + REST + menu | ✅ `jetonomy_notification_created` action | replies, mentions, votes, … |
| WPMediaVerse | messaging | `mvs_message_sent` (+ media events) | new message, … |
| Career Board | `wcb_notifications` + `wcb/v1/notifications` + bell | ❌ no creation hook | application submitted/status, job approved/rejected/expired |

Existing BN aggregation is **partial**: `jt.discussion_reply` only (Jetonomy),
`mvs_message_sent` (messages). Career Board: none. This plan makes it complete + consistent.

## One uniform strategy: CLEAN MIRROR (these are our plugins — add the hook)

**Locked 2026-06-14.** Every Wbcom plugin fires a `<plugin>_notification_created`
action when it creates an in-app notification, carrying `(user_id, message, link,
event_type, id)`. BN has ONE generic listener that mirrors any of them into
`bn_notifications` (via `SuiteNotifications`). One source of truth per notification —
BN never re-derives or guesses partner wording, so it can never drift.

**Where the hook is missing, we ADD it to our own plugin** (not a BN-side workaround):
- **Jetonomy** — already fires `jetonomy_notification_created` ✅. Wire BN's listener.
- **Career Board** — has NO creation hook. **Basecamp card filed** (WP Career Board →
  Triage, card 9994152495; CB dev 1.4.3) to add `wcb_notification_created` in free + pro
  at the point CB creates a notification (Pro bell `NotificationsBellModule::insert()`,
  and the free notification-worthy events). BN clean-mirrors once shipped. Until then,
  Career Board notifications simply don't appear in BN — no event-derived fallback (avoids drift).
- **WPMediaVerse** — messages already flow via `mvs_message_sent`; align it to the same
  contract (add `mvs_notification_created` if a broader notification surface exists).

**Principle:** if any of our plugins is missing a hook BN needs, add the hook to that
plugin (Basecamp card) rather than work around it in BN.

## Shared infrastructure (build once, scales to every integration)

**The point:** a new integration = one small listener that pushes a notification — NO
per-type rendering code, NO growing switch.

- **`Suite\SuiteNotifications` (Pro)** — `push( int $recipient, array $args )` where
  `$args = { message, url, icon, group_key, sender_id, type }`. Creates a
  `bn_notifications` row via Free's `NotificationService` with a `suite.*` type and the
  message/url/icon stored in `data`.
- **Free generic seams (data-driven rendering)** — `NotificationMessageService` already
  exposes `buddynext_notification_message`; add the symmetric `buddynext_notification_url`
  + `buddynext_notification_meta` filters. `SuiteNotifications` hooks all three: for any
  `suite.*` type, return `data.message` / `data.url` / `data.icon`. So integration
  notifications render with zero per-type code.
- **Prefs catalogue** — `SuiteNotifications` registers its types into
  `buddynext_notification_prefs_catalogue` (grouped, e.g. "Jobs", "Discussions") so
  members can toggle them like native types.
- **Idempotency / no self-duplication** — dedupe by `(type, group_key)` so re-fired
  events don't double-insert in BN.

## Per-plugin listeners (thin — one generic mirror per `*_notification_created`)

- **Jetonomy**: hook `jetonomy_notification_created` → `SuiteNotifications::push()` with
  the partner's message+link. Replaces/extends the lone `jt.discussion_reply`.
- **Career Board**: hook `wcb_notification_created` (← the hook we're adding via the
  Basecamp card) → `SuiteNotifications::push()`. No re-derivation; we mirror CB's exact
  message + link.
- **WPMediaVerse**: messages already flow via `mvs_message_sent`; align to the contract.

## Coexistence with partner menus (the trade-off, stated)

Each plugin's own notifications menu still works (CB bell, Jetonomy menu) — we do NOT
touch partner core. So a member may see an item in BN's center AND in the partner's
dashboard menu. That's acceptable: BN is the hub they live in; the partner menu is the
in-dashboard view. **Future option (Basecamp, partner-side):** a partner toggle to defer
its own menu to BN once BN is the canonical center — out of scope here, never a unilateral BN change.

## Build order (after lock)

1. Free: add `buddynext_notification_url` + `buddynext_notification_meta` seams (symmetric to the existing message filter). TDD.
2. Pro: `Suite\SuiteNotifications` (push + 3 render filters for `suite.*` + catalogue). TDD.
3. Jetonomy listener (clean mirror via `jetonomy_notification_created`). TDD + verify in BN center.
4. Career Board listener (event-derived, 5 events). TDD + verify.
5. WPMediaVerse: fold messages into SuiteNotifications (optional).
6. Browser-verify BN's `/notifications/` shows jobs + discussions + messages together, toggleable in prefs.

## Locked decisions (2026-06-14)
- **Coexistence:** ACCEPT — BN aggregates; partner menus keep working in their own
  dashboards (no partner-menu core touched). BN is the hub view.
- **Career Board:** add the `wcb_notification_created` hook (free + pro) so BN clean-
  mirrors it — Basecamp card filed against CB dev 1.4.3. Scope = whatever CB's own bell
  notifies (BN mirrors 1:1, so it stays in lockstep automatically).
- **Adding hooks to our plugins is in scope** (additive, not a menu/core change). The
  "never touch partner core" rule is about not changing/breaking partner behaviour — a
  new `do_action` is safe and is the right fix when a hook is missing.
