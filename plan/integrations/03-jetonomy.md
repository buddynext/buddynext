# Integration #2 — Jetonomy (Discussions / Forums)

**Status:** 🟡 PLAN. **Tier:** community-layer (forums = a section, NOT a takeover — the
WPMediaVerse-messaging rebuild is the only takeover exception). **Plugin:** Jetonomy
free + pro (1.5.0-dev). Standalone — keeps its own forum pages; BN wires profile + space
+ activity + notifications + search around them.

## Data model (manifest)

Jetonomy owns: `jt_spaces` (forums), `jt_posts` (discussions), `jt_replies`,
`jt_space_members`, `jt_votes`. Rich hooks: `jetonomy_after_create_post/reply`,
`after_create_space`, `before_join_space`, `join_request_*`, `membership_*`, etc.

## What already exists (BN `JetonomyBridge`) — predates the new models

| Touchpoint | What | Reconcile |
|---|---|---|
| Search | `jetonomy_after_create_post` → `bn_search_index` (type discussion); delete on post-delete | ✅ keep |
| Reply notification | `jetonomy_after_create_reply` → BN `jt.discussion_reply` | → migrate to the aggregation plan (`02`) once the hook carries message+link (card 9994156006) |
| Rail nav | `buddynext_rail_items` → "Discussions" entry | ✅ keep |
| Hashtag cross-link | `buddynext_hashtag_related_discussions` | ✅ keep |
| **Space Discussions tab** | `buddynext_space_tabs` → tab when `bn_space_{id}_jetonomy_forum_id` option is set; forum renders wrapped in BN shell (`jetonomy_before/after_content`, native nav hidden) | ⚠️ keep, but **manual link** is a gap (see below) |
| **Profile Discussions count** | `buddynext_profile_extra_data` → stats-strip tile (member's `jt_posts` count) | ✅ keep (lightweight signal — the right pattern) |
| **Profile Discussions tab** | dedicated tab listing the member's discussions | ✅ **keep** — discussions is a CORE community surface (earns its own tab); business apps (jobs/courses) use the shared Portfolio tab |

## Locked decisions (2026-06-14)

1. **Profile: Discussions KEEPS its own tab.** Forums are a core community surface and
   earn prominence — unlike business apps (Career Board jobs, Learnomy courses) which
   consolidate into the shared Portfolio tab. So: keep the dedicated Discussions tab +
   the stats-strip count tile. (Rule of thumb: the 3 core integrations — media/messages,
   discussions, gamification — may have prominent surfaces; business apps use Portfolio.)
2. **Space forum: ON-DEMAND.** No forum until a member first opens a space's Discussions
   tab; then lazily create + link a paired `jt_space` and store
   `bn_space_{id}_jetonomy_forum_id`. Zero empty forums, zero admin friction. (Keep the
   Space-Settings manual linker as an override.)
3. **Activity: YES.** A new discussion posts a BN feed activity for engagement.

## Build tasks (TDD; each verified)

### Task A — Activity on new discussion · ✅ DONE
`jetonomy_after_create_post( $post_id, $space_id )` → `SuiteActivity::publish( author,
'started a discussion', $discussion_url, $title )`. Read author/title from `jt_posts`
(the hook passes only ids, like the search handler already does). Delete on post-delete.
Verify: starting a discussion creates a feed activity; deleting removes it.

### Task B — On-demand space forum · ✅ DONE (+ REST: POST buddynext/v1/spaces/{id}/forum)
When the space Discussions tab is opened and no `bn_space_{id}_jetonomy_forum_id` exists,
lazily create a paired `jt_space` (Jetonomy space-create API / `jetonomy_after_create_space`)
named after the BN space, store the option, then render. Keep the manual Space-Settings
override. Verify: opening Discussions on a forumless space provisions one once; reopening reuses it.

### Task C — Notifications → aggregation · ✅ DONE (2026-06-14, jetonomy 1.5.0-dev)
Card 9994156006 shipped: Jetonomy 1.5.0 fires the central
`jetonomy_notification_created( $notif_id, $user_id, $type, $object_type, $object_id,
$message, $url )` for EVERY notification (replies, mentions, accepted answers, votes,
join-requests). `JetonomyBridgeListener` (Free — Jetonomy is a core integration, so it stays
Free, not Pro `SuiteNotifications`) now mirrors them all into BN's center as a single
`jt.notification` type, data-driven (message + url), icon `messages-square`, label
"Discussions". Replaced the bespoke `jt.discussion_reply` reply-only handler. Honours BN
blocks when the actor resolves from the object.

**Collect-only (no double email):** registered in the prefs catalogue with `can_email=false`.
Required making `can_email` authoritative — `EmailSender` now skips any type the catalogue
marks `can_email=false` (it was previously ignored, so BN was emailing mirrored partner
notifications). This now also gates `suite.*` (Learnomy) correctly.

**Two render bugs fixed along the way:** (1) the listener guarded on the non-existent
`Jetonomy\Core\Plugin` so it never registered live — fixed to `Jetonomy\Jetonomy`;
(2) the server notifications page SELECT omitted `n.data`, so data-driven notifications
(jt.* AND suite.*) rendered the "Notification (type)" fallback — added `n.data` + a native
`data['message']`/`data['url']` fallback in `NotificationMessageService`. Verified live:
firing the hook collects a notification rendering the real message + thread link.

### Task D — Verify · keep existing
Search index, rail nav, hashtag cross-link, profile count tile + Discussions tab, space
Discussions tab all stay. Browser-walk: profile Discussions tab, space Discussions tab
(on-demand provisioned), a new discussion → feed activity. 390px + light/dark.

## Organisation (consistent with Career Board)

- `includes/Bridges/JetonomyBridge.php` stays as the **event wiring** (search, activity,
  space tab, rail, hashtag). Notifications move to the aggregation listener.
- `includes/Integrations/Jetonomy/JetonomySocial.php` (new) = the **profile panel provider**
  (mirrors `CareerBoardSocial`).
- Space provisioning lives in the bridge (hooks `buddynext_space_created` per decision).

## Build order
A (activity) and B (on-demand space forum) are buildable now. C (notifications) is blocked
on the Jetonomy hook card (9994156006). D verifies the existing wiring stays green.
