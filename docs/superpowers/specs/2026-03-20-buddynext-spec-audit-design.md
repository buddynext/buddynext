# BuddyNext Spec Audit — Design Document

**Date:** 2026-03-20
**Type:** Spec correction audit
**Status:** Complete (v2 — reviewer issues resolved)

---

## Goal

Verify all BuddyNext feature specs against the actual codebases of the four first-party addons (Jetonomy, Jetonomy Pro, WPMediaVerse, WPMediaVerse Pro, WBGamification, Career Board) and correct any claims that don't match reality. Specs stay the source of truth.

---

## Approach

Spec-first verification: start with specs that make concrete claims about plugin internals, spot-check each claim against actual plugin code, correct inline. 7 specs checked; the other 13 make no plugin-internal claims.

**Specs verified:**
- `00-architecture.md` — bootstrap order + data flow hook names
- `01-social-graph.md` — MVS table names
- `06-notifications-email.md` — email catalog
- `13-jetonomy-bridge.md` — hook names, feed sync behavior
- `14-wpmediaverse-bridge.md` — hook names
- `15-career-board-bridge.md` — hook names (plugin installed, added to scope)
- `12-wbgamification-bridge.md` — hook names (plugin installed, added to scope)

---

## Findings and Corrections

### `00-architecture.md`

**Wrong hook in data flow diagram:**
- Line 147: `jetonomy_discussion_created` → `jetonomy_after_create_post`
  - Source: `jetonomy/includes/class-abilities.php:791`, `jetonomy/includes/api/class-posts-controller.php:255`
  - No hook named `jetonomy_discussion_created` exists anywhere in Jetonomy core or Pro
- Data flow comment updated: feed entry is conditional on feed sync toggle; search index always created

**Bootstrap priorities — verified correct:**
- Jetonomy: `add_action('plugins_loaded', [$this, 'init'])` default priority 10 — `jetonomy/includes/class-jetonomy.php:23` ✓
- WPMediaVerse: `add_action('plugins_loaded', ['WPMediaVerse\\Core\\Plugin', 'init'])` default priority 10 — `wpmediaverse/wpmediaverse.php:62` ✓
- Jetonomy Pro: `plugins_loaded` priority 20 — `jetonomy-pro/jetonomy-pro.php:35` ✓
- WPMediaVerse Pro: hooks into `mvs_loaded` — `wpmediaverse-pro/wpmediaverse-pro.php:68` ✓

---

### `13-jetonomy-bridge.md`

**Wrong hook names (3):**

| Spec claimed | Actual hook | Source |
|---|---|---|
| `jetonomy_reply_created` | `jetonomy_after_create_reply` | `jetonomy/includes/api/class-replies-controller.php:230`, `class-abilities.php:866` |
| `jetonomy_mention_created` | No native hook — does not exist | Jetonomy core has no mention system; `class-mentions.php` is a utility only, fires no actions |
| `jetonomy_reaction_added` | `jetonomy_pro_reaction_toggled` | `jetonomy-pro/includes/extensions/reactions/class-extension.php:367` — Pro extension only |

**Mention handling (precise bridge behavior):**
No `jetonomy_mention_created` hook exists. The bridge handles mentions by parsing `@username` patterns from post content on `jetonomy_after_create_post`. When matches are found, bridge calls `NotificationService::create()` for each mentioned user (type: `jt.discussion_mention`).

**Design decisions added:**

*Feed sync — default OFF:*
Pushing discussions to feed is opt-in. Default off prevents overcrowding — forums are intent-driven, social feeds are passive scroll. Toggle at admin level (site-wide) and per-space override. Only the opening post is pushed, never replies. Notifications and search always-on regardless of toggle.

*Hot Topics block:*
Native Jetonomy block. Bridge makes it available in BuddyNext space layouts and auto-scopes it to the linked forum. Space admins place it anywhere.

*Manual space ↔ forum linking:*
Space admin picks which Jetonomy forum to link in Space Settings. Options: pick existing forum, create new, or leave unlinked. Unlinked spaces get no Forum tab, no Hot Topics block, no feed sync. Removed conflicting "auto-create on `buddynext_space_created`" sentence (was contradicted by manual linking section).

---

### `14-wpmediaverse-bridge.md`

**Wrong hook name (1):**

| Spec claimed | Actual hook | Source |
|---|---|---|
| `mvs_favorite_added` | `mvs_favorite_toggled($media_id, $user_id, $action)` | `wpmediaverse/includes/REST/Controller/FavoriteController.php:148` |

Note: WPMediaVerse `NotificationService.php:45` listens on `mvs_favorite_added` which is never fired anywhere in the codebase — existing bug in WPMediaVerse. Fix needed in WPMediaVerse before the bridge can rely on a favorite hook. Bridge should listen on `mvs_favorite_toggled` and check `$action === 'added'`.

**`mvs_reaction_added` — verified with caveat:**
`wpmediaverse/includes/REST/Controller/ReactionController.php:175-178` fires `mvs_reaction_added` when `$result['action'] === 'added' || $result['action'] === 'updated'` — it fires on both a new reaction AND a reaction-type change (e.g. switching from like to heart). The bridge must check whether the user already had a reaction on this media item to avoid sending a duplicate notification when someone just changes their reaction emoji. The spec note has been updated accordingly.

**Feed content triggers named explicitly:**
- `mvs_media_uploaded` — `wpmediaverse/includes/Services/UploadService.php:256` ✓
- `mvs_media_deleted` — `wpmediaverse/includes/REST/Controller/MediaController.php:495` and `BulkController.php:149` ✓

---

### `15-career-board-bridge.md`

**Wrong hook names (2):**

| Spec claimed | Actual hook | Source |
|---|---|---|
| `wp_cb_application_received` | `wcb_application_submitted($app_id, $job_id, $candidate_id)` | `wp-career-board/api/endpoints/class-applications-endpoint.php:263` |
| `wp_cb_application_status_changed` | `wcb_application_status_changed($app_id, $old_status, $new_status)` | `wp-career-board/api/endpoints/class-applications-endpoint.php:334`, `admin/class-admin-applications.php:591` |

**Feed content triggers named explicitly:**
- `wcb_job_created($job_id, $request)` — `wp-career-board/api/endpoints/class-jobs-endpoint.php:429` ✓
- `wcb_job_expired($job_id)` — `wp-career-board/modules/jobs/class-jobs-expiry.php:97` ✓

**Gap found and filled:**
`wcb_application_withdrawn($app_id, $job_id, $candidate_id)` — `wp-career-board/api/endpoints/class-applications-endpoint.php:421`. Not in spec. Added: bridge notifies employer when candidate withdraws (type: `cb.application_withdrawn`).

---

### `06-notifications-email.md`

**Stale email types removed:**
- "Renewal Reminder" and "Payment Failed" removed from Pro/Membership catalog
- No plugin in the stack processes payments (locked decision in `00-architecture.md` and `P1-stripe-membership.md`)
- Replaced with: Membership Welcome, Membership Activated, Membership Cancelled

---

### `01-social-graph.md` — Clean

`mvs_follows` table confirmed: `wpmediaverse/includes/Core/Migrator.php:124` — `CREATE TABLE {prefix}mvs_follows`. ✓

---

### `12-wbgamification-bridge.md` — Clean

Spec uses `buddynext_*` action names (BuddyNext fires, WBGam listens) — correct pattern direction. WBGam output hooks for notifications (`wb_gamification_badge_awarded` at `src/Engine/BadgeEngine.php:220`, `wb_gamification_level_changed` at `src/Engine/LevelEngine.php:61`) are not named in the spec — no incorrect claims made.

---

## Side Findings (Not Spec Errors — Noted for Implementation)

1. **WPMediaVerse has 15 custom tables** — `CLAUDE.md` only lists 2. Full list from `Migrator.php`: `mvs_reports`, `mvs_blocks`, `mvs_activity`, `mvs_follows`, `mvs_notifications`, `mvs_reactions`, `mvs_favorites`, `mvs_media_views`, `mvs_media_stats`, `mvs_access_rules`, `mvs_access_grants`, `mvs_mentions`, `mvs_album_items`, `mvs_media_index`, `mvs_error_log`. WPMediaVerse CLAUDE.md needs updating (separate task).

2. **WPMediaVerse `mvs_favorite_added` bug** — `NotificationService.php:45` listens on a hook that `FavoriteController.php:148` never fires. One-line fix needed in WPMediaVerse before bridge can use favorite notifications. Bridge uses `mvs_favorite_toggled` as workaround.

3. **WPMediaVerse already ships WordPress Abilities API** — `wpmediaverse/includes/Core/Abilities.php`. Aligned with BuddyNext's Abilities API requirement from day 1.

4. **DM/Chat ownership — locked:** WPMediaVerse Pro messaging engine (`mvs_conversations`, `mvs_conversation_participants`, `mvs_messages`, `mvs_message_reactions`) is **moved into BuddyNext free** (tables renamed to `bn_*` prefix). Nothing is released yet, so this is a clean move with no migration debt. Split: **DM (1:1 private messaging) = BuddyNext free**; **Live Chat (WebSocket) + Group Chat = BuddyNext Pro**. WPMediaVerse Pro's standalone messaging remains for standalone mode; in BuddyNext mode it sets `mvs_owns_messaging → false` and defers to BuddyNext via bridge. Jetonomy Pro's private messaging (`jt_pro_conversations`, `jt_pro_conversation_participants`, `jt_pro_messages`) is a separate feature unaffected by this decision — it defers to BuddyNext DM via bridge in BuddyNext mode.

---

## All Spec Files Touched

| File | Changes |
|------|---------|
| `00-architecture.md` | Data flow: `jetonomy_discussion_created` → `jetonomy_after_create_post`; feed sync note added |
| `13-jetonomy-bridge.md` | Hook names corrected; feed sync default-off; manual space linking; Hot Topics block; auto-create line removed |
| `14-wpmediaverse-bridge.md` | `mvs_favorite_added` → `mvs_favorite_toggled`; explicit trigger hooks |
| `15-career-board-bridge.md` | Hook names + arg signatures; `wcb_application_withdrawn` added |
| `06-notifications-email.md` | Payment email types removed |
| `03-spaces.md` | Integrations section: linked forum field, per-space feed toggle, media tab toggle |
| `11-gutenberg-blocks.md` | Hot Topics block added to Jetonomy extensions |
| `16-admin-settings.md` | Jetonomy feed toggle added to Integrations tab |

---

## Specs Verified Clean

`01-social-graph`, `02-activity-feed`, `04-member-directory-search`, `05-user-profiles`, `07-direct-messaging`, `08-reactions-comments`, `09-moderation`, `10-onboarding-setup-wizard`, `12-wbgamification-bridge`, `17-roles-permissions`, `18-hashtags`, `19-database-scale`, `P1` through `P6`

---

## Next Step

Implementation planning — break BuddyNext v1 build into phases and sequence work across the 20 locked feature specs.
