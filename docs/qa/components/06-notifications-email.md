# QA — Notifications & Email (free)

**Manifest refs:** tables: `bn_notifications`, `bn_notification_prefs`, `bn_email_templates`, `bn_email_log` · REST routes: `GET/PUT /me/notifications`, `GET /me/notifications/unread-count`, `PUT/POST /me/notifications/read-all`, `PUT/POST /me/notifications/{id}/read`, `DELETE /me/notifications/{id}`, `GET/PUT /me/notification-prefs`, `GET/PUT /me/notification-channels`, `GET/POST /me/space-notification-prefs` · services: NotificationService, NotificationPrefService, NotificationPrefCatalogue, NotificationMessageService, NotificationListener, EmailDispatchListener, EmailSender, EmailEditor · capabilities: `manage_options` (Email Templates editor only)
**Cross-ref (no dup):** JOURNEYS J-60 (email editor list + preview) · FLOW-TEST-MATRIX M16 (notifications bell / read / prefs), O6 (email templates) · scope card component 11 (Admin Hub & Settings) for option-level bug notes
**Admin location:** BuddyNext → Settings → Notifications tab + Settings → Email tab + Settings → Email Templates tab (Advanced group)

---

## 1. Backend settings & options (justify each)

Toggle options (type `boolean`) that use `render_toggle_row()` are flagged **[TOGGLE-BUG]**: unchecked state is never saved because no preceding hidden input is emitted (see `AdminPageBase.php` `render_toggle_row()` — the checkbox-only pattern without `<input type="hidden" value="0">`).

### Notifications tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_notif_default_follow` | Notifications | `1` (true) | New-follower in-app notification ON by default for all new members **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably; toggle bug means uncheck is never posted |
| `buddynext_notif_default_connection` | Notifications | `1` (true) | Connection-request notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_reaction` | Notifications | `1` (true) | Post-reaction notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_comment` | Notifications | `1` (true) | Post-comment notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_mention` | Notifications | `1` (true) | @mention notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_space_join` | Notifications | `1` (true) | Space-join notification for space owners ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_digest_frequency` | Notifications | `'weekly'` | Email digest cadence: never / daily / weekly (select); rendered `render_select_row()` — no toggle bug | Yes | Default shown in Settings.php code is `'weekly'`; note 11-admin-hub-settings.md says `'daily'` — actual DB default in SETTINGS_MAP line 83 is `'weekly'`; discrepancy should be verified |
| `buddynext_admin_alert_email` | Notifications | `''` | Receives daily moderation queue and registration alert emails; falls back to `admin_email` when blank | Yes | Fallback not documented in UI label; admin may not realise WP admin email is used when blank |

### Email tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_email_from_name` | Email | `''` (falls back to site name in render) | `From:` display name for all BuddyNext system emails | Yes | When blank `get_bloginfo('name')` is shown in the field but not stored — if user saves blank, the option is empty and wp_mail will use WordPress defaults |
| `buddynext_email_from_address` | Email | `''` (falls back to `admin_email` in render) | `From:` sending address | Yes | No SPF/DKIM hint; owners frequently configure an address their mail server can't send from and see no warning |
| `buddynext_email_reply_to` | Email | `''` | `Reply-To:` header on all outgoing emails | Questionable | No UI explanation of what this does vs. From; blank means Reply-To = From (standard), but that is not stated |
| `buddynext_email_footer_text` | Email | `''` | Plain-text footer appended to every transactional email | Yes | `{{unsubscribe_url}}` token is not auto-inserted and not documented in the field hint; community owners expected to add it manually but there is no prompt |

### Email Templates (Admin Hub Advanced → Email Templates tab)

The Email Templates editor (`EmailEditor.php`) is a separate admin interface, not part of the Settings API option set. Templates are stored as rows in `bn_email_templates` (columns: `type`, `subject`, `preview_text`, `body_html`, `enabled`). The full catalogue (`EmailEditor::get_catalogue()`) has **20 templates** in 7 categories. The table row is written on first Save; before save, catalogue defaults apply.

| Template slug | Category | Trigger | Notable tokens | can_email in catalogue |
|---|---|---|---|---|
| `bn.new_follower` | Social | Someone follows you | `{{follower_name}}`, `{{follower_bio}}`, `{{follow_back_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.connection_requested` | Social | Connection request received | `{{requester_name}}`, `{{profile_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.connection_accepted` | Social | Connection accepted | `{{connector_name}}`, `{{profile_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.mention` | Social | @mentioned in a post | `{{mentioner_name}}`, `{{context_excerpt}}`, `{{post_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.post_reacted` | Social | Reaction on your post | `{{reactor_name}}`, `{{post_excerpt}}`, `{{post_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.post_commented` | Social | Comment on your post | `{{commenter_name}}`, `{{comment_excerpt}}`, `{{post_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.post_shared` | Social | Your post was shared | `{{sharer_name}}`, `{{post_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.space_invite` | Spaces | Invited to a space | `{{inviter_name}}`, `{{space_name}}`, `{{space_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.space_join_requested` | Spaces | Member requests to join your space | `{{requester_name}}`, `{{space_name}}`, `{{space_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.space_request_approved` | Spaces | Space join request approved | `{{space_name}}`, `{{space_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.strike_issued` | Moderation | Strike issued to a member | `{{recipient_name}}`, `{{site_name}}` | Yes |
| `bn.badge_awarded` | Gamification | Badge earned | `{{badge_name}}`, `{{profile_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.level_up` | Gamification | New level reached | `{{new_level}}`, `{{profile_url}}`, `{{unsubscribe_url}}` | Yes |
| `bn.jetonomy_reply` | Jetonomy | Reply to your forum discussion | `{{replier_name}}`, `{{discussion_title}}`, `{{reply_url}}`, `{{unsubscribe_url}}` | Yes |
| `welcome` | Auth | Sent on registration | `{{recipient_name}}`, `{{login_url}}`, `{{site_name}}` | Yes |
| `email_verify` | Auth | Email address verification OTP | `{{recipient_name}}`, `{{otp_code}}`, `{{site_name}}` | Yes |
| `bn.bulk_invite` | Onboarding | Admin CSV bulk-invite | `{{first_name}}`, `{{site_name}}`, `{{invite_url}}` | Yes |
| `bn.onboarding_nudge` | Onboarding | 24h/72h nudge to incomplete profiles | `{{recipient_name}}`, `{{site_name}}`, `{{onboarding_url}}` | Yes |

Note: `bn.bookmark_milestone`, `bn.user_shadow_banned`, `bn.appeal_submitted`, and `bn.report_resolved` have `can_email: false` in `NotificationPrefCatalogue.php` and have no entry in the email template catalogue.

---

## 2. Frontend functions (function-by-function)

### Notification bell & list

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Load unread count | Bell icon in topbar (all hubs) | `GET buddynext/v1/me/notifications/unread-count` | Returns `{count: int}`; cached 30s server-side in group `buddynext_notifications` key `unread_{user_id}`; 0 hides the badge |
| Open notification panel | Click bell | `GET buddynext/v1/me/notifications?per_page=20` | Cursor-paginated list; each item enriched with `message`, `url`, `icon`, `tone`, `label`, `actor_name`, `group_count` by `NotificationMessageService::compose_batch()` which primes `cache_users()` for N actor IDs |
| Mark single read | Click notification item | `PUT buddynext/v1/me/notifications/{id}/read` | 403 if logged-in user is not the owner; updates `is_read=1` in `bn_notifications`; unread count cache cleared |
| Mark all read | "Mark all read" button | `PUT buddynext/v1/me/notifications/read-all` | Sets `is_read=1` on all user's rows; 200 on success |
| Delete single notification | Delete icon on item | `DELETE buddynext/v1/me/notifications/{id}` | 403 if not owner; hard-deletes the row |
| Pagination | "Load more" in panel | `GET .../me/notifications?cursor=<base64>` | Cursor encodes `created_at|id`; `next_cursor` is `null` when no more items; `per_page` max 50 |
| Grouped collapse | Multi-actor events (follows, reactions) | Returned in list payload | Events with same `group_key` within 24h increment `group_count` instead of creating a new row; `NotificationMessageService::compose_grouped()` renders "{name} and {N} others…" for types: `bn.new_follower`, `bn.post_reacted`, `bn.post_commented`, `bn.post_shared`, `bn.space_join`, `bn.space_new_post`, `bn.new_message`, `bn.media_favorited` |

### Per-type notification messages (NotificationMessageService)

All 34 notification types have a case in `compose_single()`. Key actor-resolution: `get_userdata($actor_id)` with `cache_users()` prefetch in batch. Space names resolved via `SELECT name FROM bn_spaces WHERE id=%d` with 300s object cache in group `buddynext_space_names`. Types with no actor (`sender_id=null`) show "Someone" as actor name.

### Notification preferences (member-facing)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Load per-type prefs | Notification settings page | `GET buddynext/v1/me/notification-prefs` | Returns 34-type catalogue merged with stored rows; defaults: `{on_site: true, email_freq: 'immediate'}` |
| Save per-type pref | Toggle/select in prefs UI | `PUT buddynext/v1/me/notification-prefs` | Body: `{"bn.new_follower": {"on_site": true, "email_freq": "daily"}}`; validates `email_freq` ∈ {immediate, daily, weekly, off}; 422 on invalid freq; stored in `bn_notification_prefs` via INSERT ON DUPLICATE KEY UPDATE; cached 600s group `buddynext_notif_prefs` |
| Load channel prefs | Notification settings, Channel section | `GET buddynext/v1/me/notification-channels` | Returns `{in_app, email, push, sound}` booleans from usermeta `bn_channel_prefs`; `push` defaults false when Pro PushDispatcher not present |
| Save channel prefs | Channel toggles | `PUT buddynext/v1/me/notification-channels` | Partial update of in_app/email/push/sound in usermeta `bn_channel_prefs` |
| Load space prefs | Per-space notification row | `GET buddynext/v1/me/space-notification-prefs` | Lists active memberships (JOINs `bn_spaces` + `bn_space_members`) with `pref` per space |
| Save space pref | Per-space select | `POST buddynext/v1/me/space-notification-prefs` | Validates user is an active member before UPDATE of `bn_space_members.notification_pref`; valid values: all / mentions_only / none |

### Email dispatch

| Function | Surface / route | Expected behaviour |
|---|---|---|
| Immediate email send | `EmailDispatchListener::on_notification_created` → `EmailSender::send()` | Reads `email_freq` pref; `off` → no-op; `daily`/`weekly` → fires `buddynext_queue_email_digest` action (appended to usermeta `buddynext_digest_queue_{freq}`); `immediate` → enqueues Action Scheduler `buddynext_send_notification_email` (or inline `send_now()` when AS not available) |
| Template render | `EmailSender::send_now()` → `render()` | Fetches template from `bn_email_templates` by `type`; disabled template suppresses event emails (not campaign/drip emails); token replacement: `{{site_name}}`, `{{site_url}}`, `{{user_name}}`, `{{actor_name}}`, `{{notification_message}}`, `{{unsubscribe_url}}`, plus any scalar key from `$data` as `{{key}}` |
| Unsubscribe URL | `EmailSender::unsubscribe_url()` | HMAC-SHA256 signed with `wp_salt('auth')`: `?bn_unsub=1&uid={id}&type={slug}&sig={hmac}`; verified with `hash_equals()` (timing-safe) |
| Unsubscribe handler | `EmailDispatchListener::handle_unsubscribe_request()` on `init` hook | Verifies HMAC; sets `email_freq='off'` for user+type in `bn_notification_prefs`; redirects with `?bn_unsub_status=success` or `invalid` |
| Email log | `EmailSender::log_sent()` | INSERT into `bn_email_log` (user_id, type, digest_date=null, sent_at) on every wp_mail() call |
| Payload filter | Before wp_mail() | `buddynext_email_payload` filter: Pro can modify recipients/body or set `{send: false}` to suppress wp_mail() for campaign batching |

### Fan-out (space new-post notifications)

`NotificationListener::on_post_created_in_space()` fans out `bn.space_new_post` to all space members. Uses Action Scheduler when available (`buddynext_async_space_new_post_notification` -> `buddynext_async_space_post_fanout`). Batches of 200 members (`SPACE_FANOUT_BATCH`) via keyset pagination on `user_id`. Falls back to inline loop when AS absent.

Each batch (`fan_out_space_post_batch`) is processed in two stages, NOT one-recipient-at-a-time:

- **RECORD (in-app, bulk):** one keyset query reads members + their per-space pref; one query each batches the block check (`blocked_member_ids`, type-aware), the per-type `on_site` pref (`NotificationPrefService::get_on_site_map`), and the existing-group dedup; `in_app` is primed via `update_meta_cache`. Eligible recipients are bulk-INSERTed (new) / bulk-UPDATEd (merge), then `buddynext_notification_created` fires per row so the realtime / push / analytics consumers behave exactly as for a single `create()`. Free-only fan-out is constant per batch (~11 queries / 200 members) instead of ~6 per member. Block-suppression: bidirectional for `block` type; unidirectional (recipient only) for `mute` and `restrict`.
- **DELIVER (email, async):** email is not real-time. The record stage stamps `defer_email` on the payload (so `EmailDispatchListener` skips the per-row email) and hands delivery to `buddynext_async_space_post_emails`, a self-paginating AS action that sends inline via `EmailSender::send($defer = false)` in chunks of 50.

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-NOTIF-001 | member | frontend | Logged in; another member follows | Visit any hub; observe bell icon | Unread count badge increments to 1; `GET /me/notifications/unread-count` returns `{count:1}` | 1440px, 390px |
| QA-NOTIF-002 | member | frontend | 1 unread notification | Open bell panel | Notification row appears with actor name, correct message copy for type, correct icon/tone; cursor-paginated list loads 20 items max | 1440px, 390px |
| QA-NOTIF-003 | member | frontend | 1 unread notification open in panel | Click notification row | Row marked read; badge count decrements; `PUT /me/notifications/{id}/read` returns 200; `bn_notifications.is_read=1` in DB | 1440px |
| QA-NOTIF-004 | member | frontend | Multiple unread notifications | Click "Mark all read" | All rows `is_read=1`; badge disappears; `PUT /me/notifications/read-all` returns 200 | 1440px, 390px |
| QA-NOTIF-005 | member | frontend | 1 notification | Delete it | Row removed from panel; `DELETE /me/notifications/{id}` returns 200; row absent from `bn_notifications` | 1440px |
| QA-NOTIF-006 | member | api | User A not owner of notification ID | `DELETE buddynext/v1/me/notifications/{id_of_user_B}` | 403 Forbidden | API |
| QA-NOTIF-007 | member | frontend | 3 followers rapidly | Observe notification panel | Single notification row with group_count=3; message reads "{name} and 2 others started following you." | 1440px |
| QA-NOTIF-008 | member | frontend | Notification prefs page loaded | Visit `GET /me/notification-prefs` | 34 notification types returned with `on_site` and `email_freq` per type; `bn.bookmark_milestone` has `can_email: false` | 1440px, 390px |
| QA-NOTIF-009 | member | api | Notification prefs page | `PUT /me/notification-prefs` with `{"bn.new_follower": {"on_site": true, "email_freq": "invalid"}}` | 422 Unprocessable Entity | API |
| QA-NOTIF-010 | member | api | Notification prefs page | `PUT /me/notification-prefs` with `{"bn.new_follower": {"on_site": false, "email_freq": "off"}}` | 200; `bn_notification_prefs` row for user+type has `on_site=0`, `email_freq='off'` | API |
| QA-NOTIF-011 | member | frontend | Notification prefs, email_freq=off for bn.new_follower | Another member follows | No email sent; `bn_email_log` has no new row for this user + type | 1440px |
| QA-NOTIF-012 | member | frontend | Notification prefs, email_freq=daily for bn.post_commented | Another member comments | No immediate email; usermeta `buddynext_digest_queue_daily` for user includes this item | 1440px |
| QA-NOTIF-013 | member | frontend | Channel prefs | `PUT /me/notification-channels` with `{"email": false}` | `bn_channel_prefs.email=false` saved to usermeta; reload shows email channel toggled off | 1440px, 390px |
| QA-NOTIF-014 | member | frontend | Space member (all pref) | `POST /me/space-notification-prefs` with `{space_id, pref: "mentions_only"}` | `bn_space_members.notification_pref='mentions_only'`; new posts in that space don't create `bn.space_new_post` notification (only mentions do) | 1440px |
| QA-NOTIF-015 | member | api | Not a space member | `POST /me/space-notification-prefs` with non-member space_id | 403 Forbidden | API |
| QA-NOTIF-016 | admin | backend | Email Templates tab | Visit Settings → Email Templates; click `bn.new_follower` | Template editor pane loads with Subject, Preview text, Body HTML fields; available tokens shown as clickable chips; HTML / Plain / Preview tab strip present | 1440px, 390px |
| QA-NOTIF-017 | admin | backend | Email template loaded | Edit subject; click "Save template" | `admin_post_buddynext_email_save` fires; row upserted in `bn_email_templates`; success notice "Template saved." appears; reload shows new subject | 1440px |
| QA-NOTIF-018 | admin | backend | Email template loaded | Click "Send test" → confirm modal → "Send test" | Test email dispatched to `admin_email`; all tokens replaced with placeholder values; "Test email sent." notice appears | 1440px |
| QA-NOTIF-019 | admin | backend | Template saved with custom body | Click "Reset to default" → confirm | Row deleted from `bn_email_templates`; template reverts to catalogue defaults; "Template reset to defaults." notice | 1440px |
| QA-NOTIF-020 | admin | backend | Email template enabled | Toggle enabled off; Save | `bn_email_templates.enabled=0`; subsequent `bn.new_follower` events skip `send_now()` (template disabled); future emails suppressed | 1440px |
| QA-NOTIF-021 | member | frontend | Notification email with unsubscribe link | Click unsubscribe URL in email | HMAC verified; `email_freq='off'` set for user+type; redirect to `?bn_unsub_status=success` | — |
| QA-NOTIF-022 | anonymous | frontend | Tampered unsubscribe URL (sig invalid) | Visit `/?bn_unsub=1&uid=1&type=bn.new_follower&sig=bad` | HMAC fails; redirect to `?bn_unsub_status=invalid`; no DB change | — |
| QA-NOTIF-023 | admin | backend | Notifications tab | Uncheck `buddynext_notif_default_follow`; Save | **EXPECTED TO FAIL (toggle bug)**: `buddynext_notif_default_follow` remains `1` in DB (`wp option get buddynext_notif_default_follow --path="/Users/varundubey/Local Sites/buddynext/app/public"`) | 1440px |
| QA-NOTIF-024 | admin | backend | Email tab | Set From Name `BN Test`; From Address `test@example.com`; Save | Values persist; outgoing transactional emails use these values (verify via `buddynext_email_payload` filter or wp_mail log) | 1440px |
| QA-NOTIF-025 | member | frontend | Space with 250+ members; post created | Another member posts in space | Fan-out creates `bn.space_new_post` notifications in batches of 200; Action Scheduler jobs visible in `WP Admin → Tools → Scheduled Actions` | 1440px |

---

## 4. Site-owner expectations & suggestions

- **Toggle-OFF is broken for all 6 default notification booleans.** Any admin who unchecks a notification default toggle will find the setting silently reverts to ON. This affects `buddynext_notif_default_follow`, `_connection`, `_reaction`, `_comment`, `_mention`, `_space_join`. Root cause: `render_toggle_row()` emits no preceding `<input type="hidden" value="0">`. Priority: critical.

- **`buddynext_digest_frequency` default discrepancy.** `SETTINGS_MAP` line 83 in `Settings.php` declares default `'weekly'`; `11-admin-hub-settings.md` documents it as `'daily'`. The render fallback uses `'weekly'`. The default used when the option has never been saved should be verified against the SETTINGS_MAP declaration, as end users may get no digest if the option is absent from the DB and the fallback is `'weekly'` but the documentation says `'daily'`. Priority: medium.

- **No `{{unsubscribe_url}}` token in Email footer field.** The `buddynext_email_footer_text` option is described as a plain-text footer appended to all emails. Unsubscribe links are only inserted in individual template body HTML. A footer-level unsubscribe is the industry standard (CAN-SPAM, GDPR Article 21). The admin UI has no prompt for this. Add a `{{unsubscribe_url}}` token to the footer renderer and document it. Priority: high.

- **Email test always sends to `admin_email`, not the configured From address.** `EmailEditor::send_test()` calls `get_option('admin_email')` as the recipient. When From address is a custom no-reply address, QA of template rendering is done to a different inbox than what real members receive from. Add an optional test-recipient field in the Send-Test modal. Priority: medium.

- **Digest queue has no admin visibility.** Items queued in usermeta `buddynext_digest_queue_{freq}` are invisible to the admin. There is no report of "X members have Y items in their digest queue" and no manual flush tool. Site owners with a stale queue after a cron failure have no recovery path. Priority: medium.

- **No admin UI for the `bn_email_log` table.** Every sent email is logged to `bn_email_log` (user_id, type, sent_at) but there is no admin page to view it. Community owners cannot audit delivery issues, see which members received which notifications, or diagnose wp_mail failures. An Email Log page under BuddyNext admin is expected. Priority: medium.

- **`bn.strike_warning` email is sent from `ModerationListener::on_user_warned()` using type `bn.strike_warning`, but the catalogue template slug is `bn.strike_issued`.** When a moderator issues a formal warn (not a strike), the email dispatched is `bn.strike_warning`. The `EmailEditor` catalogue only has `bn.strike_issued`. If a site owner disables or customizes `bn.strike_warning` — a template that has no catalogue entry — the change is invisible in the editor. Priority: high.

- **`bn.connection_declined` notification type is in the catalogue (34 types) but has no template in the EmailEditor catalogue.** Members who decline a connection request will not receive the standard email confirmation path. Verify if this is intentional (declined party is not emailed) or a catalogue gap. Priority: low.

- **No per-notification-type email preview in notification settings.** Members on the prefs page can set email_freq per type but never see what the email looks like. Linking to a frontend preview (or showing the template preview text) would reduce unsubscribes caused by surprise at content format. Priority: low.
