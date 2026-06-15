# QA — Bridges (free)

**Manifest refs:** tables: none (bridges write to Free tables — `bn_posts`, `bn_blocks`, `bn_notifications`, etc.) · REST routes: `POST /spaces/{id}/forum` (Jetonomy only) · services: JetonomyBridge, WPMediaVerseBridge, GamificationBridge, BuddyXBridge + JetonomyBridgeListener, GamificationBridgeListener · capabilities: `manage_options` (IntegrationHub)
**Cross-ref (no dup):** JOURNEYS — no dedicated bridge journeys (gap); FLOW-TEST-MATRIX — no dedicated bridge rows (gap); component 11 QA-ADMIN-016/017 cover IntegrationHub card states
**Admin location:** BuddyNext → Settings → Integrations tab (`admin.php?page=buddynext&tab=integrations`) · BuddyNext → Settings → Addons tab / IntegrationHub (`admin.php?page=buddynext&tab=integrations_hub`)

---

## 1. Backend settings & options (justify each)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_jetonomy_feed_sync` | Settings → Integrations | `0` | Toggle: when enabled, Jetonomy posts are written as `forum_post` type rows into `bn_posts` so they appear in the BuddyNext activity feed **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to the `render_toggle_row()` hidden-input bug documented in component 11. |
| `buddynext_features` (keys `jetonomy`, `wpmediaverse`, `gamification`, `career_board`) | Settings → Features | all `false` (opt_in tier) | Master on/off per bridge; determines whether `Plugin::init()` calls `JetonomyBridge::init()` etc. | Yes | All four bridge feature flags are opt_in. If the feature flag is off, `class_exists()` guards in the bridge are never reached — the feature-flag check happens first. Site owners enabling a bridge via IntegrationHub but not via the Features tab will see no effect. |
| `bn_space_{id}_jetonomy_forum_id` | Stored via `update_option()` by JetonomyBridge on `POST /spaces/{id}/forum` | `null` | WP option linking a BuddyNext Space to its Jetonomy forum ID | Questionable | Named with a dynamic `{id}` suffix — not enumerable without querying all `bn_space_*` options. No cleanup when a space is deleted; orphan options will accumulate. |

### IntegrationHub detection (per `Admin/IntegrationHub.php`)

| Bridge | Detected via | `plugin_file` used | Detection inconsistency |
|---|---|---|---|
| Jetonomy | `is_plugin_active('jetonomy/jetonomy.php')` | `jetonomy/jetonomy.php` | Bridge `init()` guards on `class_exists('Jetonomy\Jetonomy')`; Settings Integrations tab guards on `class_exists()`; IntegrationHub uses `is_plugin_active()` — three different guards for the same external plugin |
| WPMediaVerse | `is_plugin_active('wpmediaverse/wpmediaverse.php')` | `wpmediaverse/wpmediaverse.php` | Bridge guards on `class_exists('WPMediaVerse\Core\Plugin')` |
| WBGamification | `is_plugin_active('wb-gamification/wb-gamification.php')` | `wb-gamification/wb-gamification.php` | Bridge guards on `function_exists('wb_gam_submit_event')` — different strategy again |
| Career Board | `is_plugin_active('career-board/career-board.php')` | `career-board/career-board.php` | Bridge moved to Pro (2026-06-14); Free no longer registers it; IntegrationHub still shows the card |

---

## 2. Frontend functions

### Jetonomy Bridge (`includes/Bridges/JetonomyBridge.php`)

Guard: `class_exists('Jetonomy\Jetonomy')` in `init()`. If Jetonomy not active, all hooks below are never registered.

| Function | Surface / route | Hooks consumed | BN hooks fired | Notes |
|---|---|---|---|---|
| On Jetonomy post created | Server-side | `jetonomy_after_create_post(2)` | `buddynext_user_mentioned(4)` per matched @username; `buddynext_jetonomy_post_indexed(5)` | @mention parsing via `preg_match_all`; opt-in feed sync writes `forum_post` row to `bn_posts` when `buddynext_jetonomy_feed_sync` is truthy |
| On Jetonomy reply created | Server-side | `jetonomy_after_create_reply(2)` | `buddynext_user_mentioned` | JetonomyBridgeListener also handles: creates `jt.discussion_reply` notification; checks `bn_blocks` bidirectionally before notifying |
| Community nav suppression | Frontend (Jetonomy templates) | `jetonomy_show_community_nav` filter | — | Returns `false` to hide Jetonomy's own nav; BuddyNext renders nav instead |
| Rail / nav injection | Frontend | `buddynext_rail_items` filter | — | Injects Jetonomy discussion link into BuddyNext sidebar rail |
| Hashtag cross-link | Frontend | `buddynext_hashtag_related_discussions(2)` filter | — | Surfaces related Jetonomy threads below a hashtag feed |
| Space tabs | Frontend | `buddynext_space_tabs(2)` filter | — | Adds "Discussions" tab to BuddyNext Space pages |
| Profile extra data | Frontend | `buddynext_profile_extra_data(2)` filter | — | Injects Jetonomy post count into profile data tiles |
| Hub shell wrapping | Frontend (Jetonomy templates) | `jetonomy_before_content`, `jetonomy_after_content` | — | Wraps Jetonomy pages in BuddyNext hub shell |
| Space → Forum provisioning | `POST /buddynext/v1/spaces/{id}/forum` (REST) | `rest_api_init` | — | Provisions or fetches Jetonomy forum linked to a BN space; stores `bn_space_{id}_jetonomy_forum_id` option |

### Jetonomy Bridge Listener (`includes/Bridges/JetonomyBridgeListener.php`)

Guard: `class_exists('Jetonomy\Core\Plugin')`. Note: different class string than JetonomyBridge (`Jetonomy\Jetonomy` vs `Jetonomy\Core\Plugin`) — if Jetonomy's namespace changes, one guard may fail while the other passes.

| Function | Hook consumed | What it does |
|---|---|---|
| Reply notification | `jetonomy_after_create_reply(2)` | Creates `jt.discussion_reply` notification; groups by `jt_reply_{post_id}`; skips if either party has blocked the other (`bn_blocks` bidirectional) |

### WPMediaVerse Bridge (`includes/Bridges/WPMediaVerseBridge.php`)

Guard: `class_exists('WPMediaVerse\Core\Plugin')` in `init()`. Career-Board-style: if WPMediaVerse not active, all hooks below are never registered.

| Function | Hook consumed / filter | BN hooks fired | Notes |
|---|---|---|---|
| Block check before DM | `mvs_can_send_message(3)` filter | — | Queries `bn_blocks WHERE type='block'` bidirectionally; returns `false` to prevent DM send if either party has blocked the other |
| DM sent → BN notification | `mvs_message_sent(4)` | `buddynext_dm_sent(4)`, `buddynext_dm_received(4)` | Creates `bn.new_message` notification; group_key `dm_{conversationId}_{recipientId}` |
| Media favorited → BN notification | `mvs_favorite_toggled(3)` | — | Creates `bn.media_favorited` notification when `$action = 'add'`; group_key `mvs_fav_{media_id}` |
| Comment on media → BN sync | `mvs_comment_created(3)` | `buddynext_comment_created(4)` | Fires BN comment hook so feed/notification listeners process the comment |
| Hub shell | `mvs_before_content`, `mvs_after_content` | — | Wraps WPMediaVerse pages in BuddyNext hub shell |
| Rail injection | `buddynext_rail_items` filter | — | Injects "Media" nav item into BuddyNext sidebar rail |
| Active flag | `mvs_buddynext_active` filter | — | Returns `true` so WPMediaVerse knows BuddyNext is active and defers its own nav |

### WBGamification Bridge (`includes/Bridges/GamificationBridge.php`)

Guard: `function_exists('wb_gam_submit_event')` in `init()`. Note: GamificationBridgeListener uses the same function guard.

| Function | Hook consumed | What it does |
|---|---|---|
| Register BN actions with WBGamification | `buddynext_gamification_noop` (inert hook at bridge init) | Registers 9 action slugs (`bn_followed`, `bn_connected`, `bn_post_created`, `bn_space_joined`, `bn_strike_issued`, `bn_profile_updated`, `bn_profile_completed`, `bn_reaction_received`, `bn_comment_created`) via `wb_gam_register_action()` |
| Submit gamification events | `buddynext_user_followed`, `buddynext_connection_accepted`, `buddynext_post_created`, `buddynext_space_member_joined`, `buddynext_strike_issued`, `buddynext_profile_completion_changed`, `buddynext_post_reaction_received`, `buddynext_comment_created` | Calls `wb_gam_submit_event($user_id, $action_id, $context)` for each event |
| Profile tiles injection | `buddynext_profile_extra_data(2)` filter | Injects Points / Level / Badges tiles into profile extra data |

### WBGamification Bridge Listener (`includes/Bridges/GamificationBridgeListener.php`)

Guard: `function_exists('wb_gam_submit_event')`.

| Function | Hook consumed | Notification created |
|---|---|---|
| Badge awarded | `wb_gam_badge_awarded(3)` | `bn.badge_awarded`; data: `{badge, badge_id, badge_name}` |
| Level up | `wb_gam_level_changed(3)` | `bn.level_up`; data: `{level, level_id, level_name, min_points, old_level_id, old_level_name}` |

### BuddyX Bridge (`includes/Bridges/BuddyXBridge.php`)

Guard: `'buddyx' !== get_template()` bail (bails if BuddyX is NOT the active theme). Only relevant when BuddyX theme is installed.

| Function | Filter consumed | What it does |
|---|---|---|
| Full-width on WPMediaVerse pages | `buddyx_is_full_width_page` | Returns `true` for WPMediaVerse dashboard / explore / upload / media / profile pages (checked via `get_queried_object_id()` vs WPMediaVerse page options) |

---

## 3. QA cases

**Absent-path (external plugin not installed) is the primary testable state on this local install.** All four external plugins (Jetonomy, WPMediaVerse, WBGamification, Career Board) are not present here.

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-BRG-001 | admin | backend | Jetonomy NOT installed | Visit `admin.php?page=buddynext&tab=integrations_hub?autologin=1` | Jetonomy card shows "Not Installed" state; no PHP error; no `class_exists('Jetonomy\Jetonomy')` fatal; IntegrationHub renders with 0 active + 3 not-installed (Career Board moved to Pro) | 1440px, 390px |
| QA-BRG-002 | admin | backend | WPMediaVerse NOT installed | Visit Addons tab (same page as QA-BRG-001) | WPMediaVerse card shows "Not Installed"; no fatal | 1440px |
| QA-BRG-003 | admin | backend | WBGamification NOT installed | Visit Addons tab | WBGamification card shows "Not Installed"; `function_exists('wb_gam_submit_event')` returns false; no hook registered; no fatal | 1440px |
| QA-BRG-004 | admin | backend | Career Board bridge moved to Pro | Visit Addons tab | Career Board card shows "Not Installed" (verify); Free no longer registers `CareerBoardBridge`; no PHP fatal from the removed bridge | 1440px |
| QA-BRG-005 | admin | backend | All externals absent | Navigate to `admin.php?page=buddynext&tab=integrations` | Integrations settings tab renders without PHP error; Jetonomy feed sync toggle present; all `class_exists()` checks return false gracefully | 1440px, 390px |
| QA-BRG-006 | admin | frontend | All externals absent | Visit `http://buddynext.local/activity/?autologin=1` | Feed loads; no `class_exists` fatal from bridge init; bridge hooks simply are not registered; no error in debug log | 1440px, 390px |
| QA-BRG-007 | admin | backend | `jetonomy` feature flag off (default) | `wp option get buddynext_features --path="..."` | Key `jetonomy` absent or `false`; `JetonomyBridge::init()` never called even if Jetonomy were installed; feature flag is the first gate | n/a |
| QA-BRG-008 | admin | backend | All externals absent | `wp eval 'do_action("jetonomy_after_create_post", 1, []);' --path="..."` | Hook fires but no handler is registered (bridge not initialized); no fatal; PHP debug log clean | n/a |
| QA-BRG-009 | admin | backend | All externals absent | `wp eval 'do_action("mvs_message_sent", 1, 1, 2, [3]);' --path="..."` | No WPMediaVerse bridge handler; no fatal | n/a |
| QA-BRG-010 | admin | backend | All externals absent | `wp eval 'do_action("wb_gam_badge_awarded", 2, [], "badge_slug");' --path="..."` | No GamificationBridgeListener handler; no fatal | n/a |
| QA-BRG-011 | admin | backend | All externals absent | `wp eval 'do_action("wb_gam_level_changed", 2, [], null);' --path="..."` | No handler; no fatal | n/a |
| QA-BRG-012 | admin | backend | Jetonomy installed (verify on a separate env) | Enable `jetonomy` feature; create a Jetonomy post with `@admin` mention | `buddynext_user_mentioned` fires; `bn_notifications` gains a row for admin; no duplicate notification (JetonomyBridgeListener also hooks `jetonomy_after_create_reply` — verify no double-fire on post vs reply) | 1440px |
| QA-BRG-013 | admin | backend | Jetonomy installed, `buddynext_jetonomy_feed_sync = 1` | Create a Jetonomy post | `bn_posts` gains a row with `type = 'forum_post'`; activity feed shows the Jetonomy post | 1440px |
| QA-BRG-014 | admin | backend | Jetonomy installed, `buddynext_jetonomy_feed_sync = 0` | Create a Jetonomy post | `bn_posts` does NOT gain a `forum_post` row; post absent from feed | 1440px |
| QA-BRG-015 | admin | api | Jetonomy installed | `POST /buddynext/v1/spaces/{id}/forum` (admin auth) | 200; `bn_space_{id}_jetonomy_forum_id` option written; response includes forum URL | n/a |
| QA-BRG-016 | admin | backend | WPMediaVerse installed (verify on separate env) | User A blocks User B; User B tries to send a DM to User A | `mvs_can_send_message` filter returns `false`; DM is rejected by WPMediaVerse before send; no DM notification created | 1440px |
| QA-BRG-017 | admin | backend | WPMediaVerse installed | User sends a DM | `mvs_message_sent` fires; `bn.new_message` notification created in `bn_notifications`; `buddynext_dm_sent` hook fires | 1440px |
| QA-BRG-018 | admin | backend | WPMediaVerse installed | User marks a media item as favorite | `mvs_favorite_toggled` fires with `$action = 'add'`; `bn.media_favorited` notification created | 1440px |
| QA-BRG-019 | admin | backend | WBGamification installed, feature flag `gamification` enabled | User earns a badge in WBGamification | `wb_gam_badge_awarded` fires; GamificationBridgeListener creates `bn.badge_awarded` notification for that user | 1440px |
| QA-BRG-020 | admin | backend | WBGamification installed | User crosses a level threshold | `wb_gam_level_changed` fires; `bn.level_up` notification created | 1440px |
| QA-BRG-021 | admin | backend | WBGamification installed | User creates a post | `buddynext_post_created` fires; GamificationBridge calls `wb_gam_submit_event($user_id, 'bn_post_created', context)` | n/a |
| QA-BRG-022 | admin | backend | BuddyX theme active | Visit a WPMediaVerse media page | `buddyx_is_full_width_page` filter returns `true`; page renders without BuddyX sidebar; no PHP error | 1440px |
| QA-BRG-023 | admin | backend | BuddyX NOT active theme | Any page | `BuddyXBridge::init()` returns early at `'buddyx' !== get_template()` bail; no hooks registered; no fatal | 1440px |
| QA-BRG-024 | admin | backend | Inconsistent detection test | Enable Jetonomy plugin; call `is_plugin_active('jetonomy/jetonomy.php')` and `class_exists('Jetonomy\Jetonomy')` and `class_exists('Jetonomy\Core\Plugin')` in a `wp eval` | All three return same boolean (verify); if they diverge, a Jetonomy version change broke at least one bridge guard | n/a |
| QA-BRG-025 | admin | backend | Space deleted with linked Jetonomy forum | Delete a space that has `bn_space_{id}_jetonomy_forum_id` set | Verify orphan option cleanup: `wp option list --search="bn_space_{id}_jetonomy*" --path="..."` should return 0 rows post-deletion — **EXPECTED TO FAIL**: no cleanup hook exists; option persists (known gap) | n/a |

---

## 4. Site-owner expectations & suggestions

- **Feature flag and external-plugin guard are redundant but inconsistent.** To activate a bridge, the site owner must both (a) enable the feature flag in Settings → Features AND (b) have the external plugin active. IntegrationHub shows only external-plugin state. There is no single screen that shows both conditions. A combined status (e.g., "Enabled in Features + Plugin Active = Bridge running" vs "Plugin Active but Feature off = Bridge dormant") would eliminate support confusion. Priority: high.

- **Detection inconsistency: three different guards for Jetonomy.** `JetonomyBridge` guards on `class_exists('Jetonomy\Jetonomy')`, `JetonomyBridgeListener` on `class_exists('Jetonomy\Core\Plugin')`, and `IntegrationHub` on `is_plugin_active()`. If Jetonomy ever refactors its namespace or plugin file path, at least one guard silently fails. Consolidate to a single canonical check (suggest `class_exists('Jetonomy\Jetonomy')` as the lowest-level truth, or a `buddynext_is_jetonomy_active()` helper). Priority: high.

- **Orphan `bn_space_{id}_jetonomy_forum_id` options on space deletion.** When a BuddyNext Space is deleted, `bn_space_{id}_jetonomy_forum_id` is never cleaned up. On a busy site this will accumulate indefinitely. Add a `buddynext_space_deleted` hook handler in `JetonomyBridge` that calls `delete_option("bn_space_{$space_id}_jetonomy_forum_id")`. Priority: medium.

- **`buddynext_jetonomy_feed_sync` toggle bug.** This option uses `render_toggle_row()` which has the hidden-input bug documented in component 11. Disabling feed sync by unchecking the toggle does nothing; the value stays enabled. Priority: medium (shared root cause with all toggle options — fixing `render_toggle_row()` fixes all).

- **No bridge-level status panel.** The IntegrationHub shows installed/not-installed per addon, but does not show bridge runtime status (hooks registered, events fired in last 24h, notification count, forum links count). A lightweight status summary per bridge (even just "bridge active — N events synced today") would help site owners confirm the bridge is working. Priority: medium.

- **Career Board bridge card in IntegrationHub points to Free.** The bridge was moved to Pro (2026-06-14) but IntegrationHub still shows the Career Board card. The card should either be removed from Free or updated to say "Available in BuddyNext Pro" with an upsell CTA. Priority: medium.

- **No WBGamification action registration feedback.** `GamificationBridge` registers 9 action slugs via `wb_gam_register_action()` silently. If registration fails (WBGamification returns a `WP_Error`), there is no admin notice. Site owners will see points not accumulating without any diagnostic. Priority: low.

- **BuddyX bridge has no feature flag.** `BuddyXBridge` loads unconditionally (guarded only by theme check) but is not listed in `FeatureRegistry`. It also has no settings surface. This is fine for a thin theme-compat shim, but it should be documented in the bridge file and in IntegrationHub (currently not listed as an integration at all). Priority: low.
