# QA — Moderation (free)

**Manifest refs:** tables: `bn_reports`, `bn_mod_log`, `bn_user_strikes`, `bn_user_suspensions`, `bn_appeals`, `bn_posts` (status/content-warning columns) · REST routes: `POST /reports`, `GET /reports`, `GET /reports/queue`, `POST /reports/{id}/dismiss`, `PUT /reports/{id}/escalate`, `PUT /reports/{id}/resolve`, `POST /reports/{id}/remove`, `GET /users/{id}/strikes`, `POST /users/{id}/strikes`, `POST /users/{id}/strikes/{sid}/reverse`, `GET /posts/{id}/content-warning`, `PUT /posts/{id}/content-warning`, `POST /users/{id}/warn`, `POST /users/{id}/shadow-ban`, `DELETE /users/{id}/shadow-ban`, `POST /users/{id}/suspend`, `DELETE /users/{id}/suspend`, `GET /users/{id}/suspension`, `POST /appeals`, `GET /me/appeals`, `POST /me/appeals`, `GET /appeals`, `PUT /appeals/{id}/approve`, `PUT /appeals/{id}/deny`, `GET /spaces/{id}/bans`, `POST /spaces/{id}/bans`, `DELETE /spaces/{id}/bans/{user_id}`, `GET /users/{id}/warnings`, `GET /users/{id}/shadow-ban`, `GET /users/{id}/suspensions` · services: ModerationService, ModerationController, ModerationListener, ModerationLogService, SafeguardService · capabilities: `manage_options` (admin routes); `require_queue_access` (space owners/mods for their spaces)
**Cross-ref (no dup):** JOURNEYS J-54 (space mod review content), J-55 (admin mod queue), J-56 (admin suspend user), J-57 (admin restore user) · FLOW-TEST-MATRIX O7 (moderation review queue / reports / strikes / suspend) · scope card component 11 (Admin Hub & Settings) for option-level bug notes
**Admin location:** BuddyNext → Settings → Moderation tab

---

## 1. Backend settings & options (justify each)

All Moderation tab options are numeric or textarea — none use `render_toggle_row()` — so the **TOGGLE-BUG does not apply to this tab**. They are registered in `SETTINGS_MAP` in `Settings.php` (lines 65-74).

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_auto_hide_threshold` | Moderation | `5` | Number of reports on a single piece of content before it is auto-hidden (status set to hidden pending review) | Yes | Default in SETTINGS_MAP (`Settings.php` line 65) is `5`; component 11 documents `3` — the SETTINGS_MAP value is authoritative |
| `buddynext_strike_warn_threshold` | Moderation | `2` | Number of active (non-reversed) strikes before a warning email is sent to the member | Yes | Checked in `ModerationListener::on_strike_issued()` immediately after creating the strike notification |
| `buddynext_strike_suspend_threshold` | Moderation | `5` | Active strikes before account is suspended automatically | Yes | Checked in same listener; calls `admin_members->suspend_member($user_id)` — no explicit `duration_days` passed (defaults to permanent) |
| `buddynext_strike_perma_ban_threshold` | Moderation | `0` | Active strikes before permanent ban (0 = disabled) | Yes | Value is read from options but no automatic permanent-ban enforcement code was found in ModerationListener; may be informational / Pro-gated |
| `buddynext_mod_queue_alert_threshold` | Moderation | `20` | Report queue size that triggers a daily admin alert email | Yes | `ModerationListener::on_daily_queue_check()` reads `bn_moderation_queue_alert_threshold` (note: different key from the registered option `buddynext_mod_queue_alert_threshold`); **key mismatch** — daily check may never fire the alert |
| `buddynext_banned_words` | Moderation | `''` | Newline-separated list; `SafeguardService::check_banned_words()` case-insensitive substring match | Yes | No regex support; no per-space override; match is substring ("can" matches "cancel") |
| `buddynext_blocked_domains` | Moderation | `''` | Newline-separated hostnames; `SafeguardService::check_blocked_domain()` matches exact hostname only | Yes | No wildcard subdomain matching; `spam.example.com` does not block `evil.spam.example.com` |
| `buddynext_banned_hashtags` | Moderation | `''` | Newline-separated hashtags; enforcement not visible in SafeguardService (may be enforced at hashtag extraction layer) | Questionable | SafeguardService has no `check_banned_hashtags()` method; option exists but enforcement code not confirmed in free plugin |
| `buddynext_post_rate_limit` | Moderation | `10` | Max posts per user per minute (SafeguardService counts bn_posts rows in last 60 seconds) | Yes | Not applied to admin/editor roles — no role exclusion code found in SafeguardService; verify if `manage_options` users are excluded at the controller level |
| `buddynext_new_member_post_threshold` | Moderation | `0` | Total published post count below which new member posts are queued as `status='pending'` (0 = disabled) | Yes | Returns WP_Error code `pending_review` with HTTP 202; callers must save as pending not reject; no UI feedback to member explaining review |

---

## 2. Frontend functions (function-by-function)

### Content reporting (member-facing)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Submit report | Report button on post/comment | `POST buddynext/v1/reports` | Auth required; args: `object_type` (post/comment/user), `object_id`, `reason` (spam/harassment/misinformation/inappropriate/fake/impersonation/other), `space_id`, `notes`; unique constraint — one report per user/object; fires `buddynext_report_created` |
| Report reasons | Client-side list | — | 7 canonical reasons; filterable via `buddynext_report_reasons` (Pro rules engine may add more) |

### Moderation queue (admin + space owner/mod)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| List queue | Mod queue admin page | `GET buddynext/v1/reports/queue` | Site admins: unrestricted; space owners/mods: only their spaces (computed from `bn_space_members WHERE role IN ('owner','moderator') AND status='active'`); empty `space_ids` for a space mod returns no reports; supports filters: `per_page`, `page`, `object_type`, `reason`, `space_ids`; returns `{items, total}` |
| Dismiss report | Admin action | `POST buddynext/v1/reports/{id}/dismiss` | Requires `manage_options`; sets status to dismissed; writes `bn_mod_log` entry |
| Escalate report | Admin action | `PUT buddynext/v1/reports/{id}/escalate` | Requires `manage_options`; status → escalated |
| Resolve report | Admin action | `PUT buddynext/v1/reports/{id}/resolve` | Requires `manage_options`; status → resolved; fires `buddynext_report_resolved` |
| Remove content | Admin action | `POST buddynext/v1/reports/{id}/remove` | Requires `manage_options`; fires `buddynext_content_removed($object_type, $object_id, $actor_id)`; for `post` type → `bn_posts.status='deleted'` + cache cleared; for `comment` type → `bn_comments.is_deleted=1` |
| Content warning | Post detail | `GET buddynext/v1/posts/{id}/content-warning` | Public (no auth); reads `content_warning` + `content_warning_type` from `bn_posts` |
| Set content warning | Admin action | `PUT buddynext/v1/posts/{id}/content-warning` | Requires `manage_options`; valid types: nsfw / spoilers / violence / language |

### Strike system (admin-only)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Issue strike | Member moderation panel | `POST buddynext/v1/users/{id}/strikes` | Requires `manage_options`; inserts `bn_user_strikes` row; fires `buddynext_strike_issued($strike_id, $user_id, $actor_id)`; `ModerationListener::on_strike_issued()` creates `bn.strike_issued` notification; checks `warn_threshold` and `suspend_threshold` automatically |
| List strikes | Member moderation panel | `GET buddynext/v1/users/{id}/strikes` | Requires `manage_options`; returns non-reversed strikes WHERE `is_reversed=0` ORDER BY `created_at DESC` |
| Reverse strike | Member moderation panel | `POST buddynext/v1/users/{id}/strikes/{sid}/reverse` | Requires `manage_options`; sets `is_reversed=1`, `reversed_by`, `reversed_at` |
| Strike → warn auto-trigger | Background (listener) | — | When active strike count >= `buddynext_strike_warn_threshold` but < `buddynext_strike_suspend_threshold`: creates `bn.strike_warning` notification and calls `EmailSender::send($user_id, 'bn.strike_warning', ...)` |
| Strike → suspend auto-trigger | Background (listener) | — | When active strike count >= `buddynext_strike_suspend_threshold`: calls `admin_members->suspend_member($user_id)` (permanent, no duration specified in listener) |

### Warnings (admin-only)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Issue warning | Member moderation panel | `POST buddynext/v1/users/{id}/warn` | Requires `manage_options`; writes `bn_mod_log` row (action='warn'); fires `buddynext_user_warned($user_id, $actor_id, $reason)`; `ModerationListener::on_user_warned()` creates `bn.user_warned` notification and sends `bn.strike_warning` email |
| List warnings | Member moderation panel | `GET buddynext/v1/users/{id}/warnings` | Requires `manage_options`; reads `bn_mod_log WHERE action IN ('warn','warned')` |

### Shadow ban (admin-only)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Shadow-ban user | Member moderation panel | `POST buddynext/v1/users/{id}/shadow-ban` | Requires `manage_options`; sets usermeta `bn_shadow_banned='1'`; fires `buddynext_user_shadow_banned($user_id)`; `ModerationListener::on_user_shadow_banned()` deletes the user's row from `bn_search_index` (user disappears from directory/search) |
| Remove shadow ban | Member moderation panel | `DELETE buddynext/v1/users/{id}/shadow-ban` | Requires `manage_options`; deletes usermeta `bn_shadow_banned`; fires `buddynext_user_shadow_ban_removed($user_id)` → `buddynext_index_user($user_id)` re-index action |
| Check shadow ban | Member moderation panel | `GET buddynext/v1/users/{id}/shadow-ban` | Requires `manage_options`; returns `{is_shadow_banned: bool}` |

### Suspension (admin-only)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Suspend user | Member moderation panel | `POST buddynext/v1/users/{id}/suspend` | Requires `manage_options`; args: `reason` (string), `duration_days` (int, 0 = permanent), `hide_posts` (bool); inserts `bn_user_suspensions` row; fires `buddynext_user_suspended($user_id, $actor_id, $reason, $expires_at)` and legacy `buddynext_member_suspended($user_id, $actor_id)` |
| Unsuspend user | Member moderation panel | `DELETE buddynext/v1/users/{id}/suspend` | Requires `manage_options`; DELETE from `bn_user_suspensions WHERE user_id=%d AND (expires_at IS NULL OR expires_at > NOW())`; fires `buddynext_user_unsuspended($user_id)` |
| Get active suspension | Member moderation panel | `GET buddynext/v1/users/{id}/suspension` | Requires `manage_options`; returns most recent active `bn_user_suspensions` row or null |
| List user suspensions | Member panel history | `GET buddynext/v1/users/{id}/suspensions` | Requires `manage_options`; all rows for user |
| Suspension notification | Background (listener) | — | `ModerationListener::on_user_suspended()` creates `bn.member_suspended` notification and sends `bn.member_suspended` email with `{reason, expires_at}` |
| Unsuspension notification | Background (listener) | — | `ModerationListener::on_user_unsuspended()` creates `bn.user_unsuspended` notification; sends `bn.unsuspension_confirmation` email only if that template exists and is enabled in `bn_email_templates`; otherwise logs a PHP error and skips email |

### Appeals (member + admin)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| Submit appeal (free-form) | Member profile settings | `POST buddynext/v1/me/appeals` | Auth required; no `suspension_id` needed; `ModerationService::create_appeal()` resolves active suspension automatically — returns 422 `not_suspended` if user has no active suspension |
| Submit appeal (with suspension_id) | API | `POST buddynext/v1/appeals` | Auth required; explicit `suspension_id` + `message` |
| List own appeals | Member appeal status page | `GET buddynext/v1/me/appeals` | Auth required; returns all user's appeals across all statuses, newest first |
| List all appeals | Admin queue | `GET buddynext/v1/appeals` | Requires `manage_options`; paginated (`limit`, `offset`); ORDER BY id ASC (FIFO); returns `{items, total}` from `get_pending_appeals()` |
| Approve appeal | Admin panel | `PUT buddynext/v1/appeals/{id}/approve` | Requires `manage_options`; sets status='approved'; lifts the referenced suspension via `lift_suspension_by_id()` (sets `lifted_at`/`lifted_by` on exact suspension row, fires unsuspend hooks); fires `buddynext_appeal_resolved($appeal_id, $user_id, 'approved')` |
| Deny appeal | Admin panel | `PUT buddynext/v1/appeals/{id}/deny` | Requires `manage_options`; sets status='denied'; fires `buddynext_appeal_resolved($appeal_id, $user_id, 'denied')` |
| Appeal resolution notification | Background (listener) | — | `ModerationListener::on_appeal_resolved()` creates `bn.appeal_resolved` notification and sends `bn.appeal_resolved` email with `{status: decision}` |
| Appeal submitted notification | Background (listener) | — | `ModerationListener::on_appeal_submitted()` creates `bn.appeal_submitted` in-app notification for ALL site administrators (no email sent) |

### Space bans (space owner/mod + admin)

| Function | Surface / route | REST endpoint | Expected behaviour |
|---|---|---|---|
| List space bans | Space settings | `GET buddynext/v1/spaces/{id}/bans` | Requires space owner/mod or `manage_options` |
| Ban from space | Space settings | `POST buddynext/v1/spaces/{id}/bans` | Same caps |
| Remove space ban | Space settings | `DELETE buddynext/v1/spaces/{id}/bans/{user_id}` | Same caps |

### Pre-submit content safeguards (SafeguardService)

`SafeguardService::check()` is called at post-create time. Checks run in order:
1. `check_banned_words()` — reads `buddynext_banned_words`; case-insensitive substring; WP_Error `banned_word` (HTTP 422) on match
2. `check_blocked_domain()` — reads `buddynext_blocked_domains`; exact hostname match on `link_url`; WP_Error `blocked_domain` (HTTP 422) on match
3. `check_rate_limit()` — reads `buddynext_post_rate_limit`; COUNT bn_posts WHERE user_id=%d AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE); WP_Error `rate_limited` (HTTP 429) on breach
4. `check_new_member_gate()` — reads `buddynext_new_member_post_threshold`; COUNT all user's bn_posts; WP_Error `pending_review` (HTTP 202) if below threshold — **non-fatal**: callers should save with `status='pending'` not discard
5. `buddynext_safeguard_check` filter — Pro ML/keyword scoring hooks stack here

### Moderation log (ModerationLogService)

Append-only write to `bn_mod_log`. Schema: `(id, actor_id, action, object_type, object_id, target_user_id, note, created_at)`. No update or delete methods. Read methods: `get_log_for_user($user_id)` and `get_log_for_object($object_type, $object_id)`.

### Daily queue alert cron (ModerationListener)

`buddynext_daily_queue_check` WP-Cron event scheduled daily. `on_daily_queue_check()` counts rows from `bn_reports WHERE status IN ('pending','escalated')`. If count >= `bn_moderation_queue_alert_threshold` (note: this is a **different key** than the registered `buddynext_mod_queue_alert_threshold`), sends plain-text `wp_mail()` to `bn_moderation_alert_email` (another non-SETTINGS-MAP option; falls back to `admin_email`).

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-MOD-001 | member | frontend | Logged in; a post exists | Click report button on post; select reason "spam"; Submit | Report row created in `bn_reports`; `buddynext_report_created` fires; reporter sees success feedback; cannot report same object again (unique constraint) | 1440px, 390px |
| QA-MOD-002 | member | api | Existing report for same user/object | `POST /reports` with same reporter_id + object twice | Second call returns 422 or 409 (unique constraint violation) | API |
| QA-MOD-003 | admin | backend | 1 pending report | Visit `GET /reports/queue` | Queue returns item with status='pending'; total=1 | 1440px |
| QA-MOD-004 | admin | backend | Pending report | `POST /reports/{id}/dismiss` | `bn_reports.status='dismissed'`; item leaves queue | 1440px |
| QA-MOD-005 | admin | backend | Pending report on a post | `POST /reports/{id}/remove` | `bn_posts.status='deleted'`; post cache `buddynext_posts` key cleared; post absent from feed | 1440px |
| QA-MOD-006 | space moderator | api | Space mod of space A | `GET /reports/queue` without space_ids | Returns only reports for space A's posts (mod's space IDs resolved from `bn_space_members`) | API |
| QA-MOD-007 | member (not mod) | api | No mod role | `GET /reports/queue` | 403 Forbidden | API |
| QA-MOD-008 | admin | backend | Post exists | `PUT /posts/{id}/content-warning` with `{has_warning: true, warning_type: "nsfw"}` | `bn_posts.content_warning=1`, `content_warning_type='nsfw'`; `GET /posts/{id}/content-warning` returns `{has_warning: true, type: "nsfw"}` | 1440px |
| QA-MOD-009 | admin | backend | Member has 0 strikes | `POST /users/{id}/strikes` with `{reason: "spam"}` | Strike row in `bn_user_strikes`; `bn.strike_issued` notification created; active count = 1; no warn email yet (< warn_threshold=2) | 1440px |
| QA-MOD-010 | admin | backend | Member has 1 active strike | `POST /users/{id}/strikes` again | Active count = 2 >= warn_threshold; `bn.strike_warning` notification + email sent to member | 1440px |
| QA-MOD-011 | admin | backend | Member has a strike | `POST /users/{id}/strikes/{sid}/reverse` | `is_reversed=1`, `reversed_by`, `reversed_at` set; `GET /users/{id}/strikes` no longer returns this row in active strikes | 1440px |
| QA-MOD-012 | admin | backend | Member with no suspend | `POST /users/{id}/suspend` with `{reason: "repeated violations", duration_days: 7}` | Row in `bn_user_suspensions` with `expires_at` = now + 7 days; `bn.member_suspended` notification; suspension email with reason and expiry sent to member | 1440px |
| QA-MOD-013 | admin | backend | Member suspended | `DELETE /users/{id}/suspend` | Suspension row deleted from `bn_user_suspensions`; `bn.user_unsuspended` notification; unsuspension email attempt (skips if `bn.unsuspension_confirmation` template absent or disabled) | 1440px |
| QA-MOD-014 | admin | backend | Member suspended permanently | `POST /users/{id}/shadow-ban` | `bn_shadow_banned` usermeta = '1'; user row deleted from `bn_search_index`; user absent from member directory and search | 1440px |
| QA-MOD-015 | admin | backend | Shadow-banned member | `DELETE /users/{id}/shadow-ban` | `bn_shadow_banned` usermeta deleted; `buddynext_index_user` action fires; user re-appears in search | 1440px |
| QA-MOD-016 | admin | backend | Member is suspended | `POST /me/appeals` (as suspended member) | Appeal row in `bn_appeals` with suspension_id auto-resolved; status='pending'; `buddynext_appeal_submitted` fires; all admins receive `bn.appeal_submitted` in-app notification | 1440px, 390px |
| QA-MOD-017 | member (not suspended) | api | No active suspension | `POST /me/appeals` | 422 WP_Error `not_suspended` | API |
| QA-MOD-018 | admin | backend | Appeal pending | `PUT /appeals/{id}/approve` | `bn_appeals.status='approved'`; suspension row for that appeal's `suspension_id` gets `lifted_at` set; `buddynext_appeal_resolved($id, $user_id, 'approved')` fires; `bn.appeal_resolved` notification and email to member | 1440px |
| QA-MOD-019 | admin | backend | Appeal pending | `PUT /appeals/{id}/deny` | `bn_appeals.status='denied'`; suspension NOT lifted; `bn.appeal_resolved` notification and email with decision='denied' | 1440px |
| QA-MOD-020 | admin | backend | Post with banned word in `buddynext_banned_words` (e.g. "badword") | Member submits post with content "this is badword" | `SafeguardService::check_banned_words()` returns WP_Error `banned_word` HTTP 422; post NOT saved | 1440px |
| QA-MOD-021 | admin | backend | Blocked domain "evil.com" in `buddynext_blocked_domains` | Member submits post with `link_url="https://evil.com/page"` | WP_Error `blocked_domain` HTTP 422; post rejected | 1440px |
| QA-MOD-022 | member | api | Rate limit = 2 (buddynext_post_rate_limit = 2) | Submit 3 posts within 1 minute | Third post returns HTTP 429 WP_Error `rate_limited` | API |
| QA-MOD-023 | new member | api | `buddynext_new_member_post_threshold = 3`; member has 2 published posts | Submit new post | Returns HTTP 202 WP_Error `pending_review`; post saved with `status='pending'` not rejected | API |
| QA-MOD-024 | admin | backend | Moderation tab | Set `buddynext_auto_hide_threshold = 1`; Save; member reports a post | After 1 report, post status set to hidden (verify `bn_posts.status` in DB); absent from feed | 1440px |
| QA-MOD-025 | admin | backend | `buddynext_mod_queue_alert_threshold = 1` and 1 pending report | Wait for / manually trigger `buddynext_daily_queue_check` cron | **EXPECTED TO FAIL (option key mismatch)**: `on_daily_queue_check()` reads `bn_moderation_queue_alert_threshold` but the registered option is `buddynext_mod_queue_alert_threshold`; alert email never sends at any threshold | 1440px |
| QA-MOD-026 | admin | backend | J-55: admin mod queue | Visit `/wp-admin/admin.php?page=buddynext-moderation` | Queue table renders without errors; pending reports visible | 1440px, 768px, 390px |
| QA-MOD-027 | admin | backend | J-56: suspend user from queue | From moderation queue, suspend a user | Confirmation visible; user row marked suspended; suspension row in `bn_user_suspensions` | 1440px, 768px, 390px |
| QA-MOD-028 | admin | backend | J-57: restore user | Restore the suspended user | Row reverts to active; `bn_user_suspensions` row deleted | 1440px, 768px, 390px |
| QA-MOD-029 | space moderator | frontend | J-54: space mod review content | Visit space moderation tab; review a reported post | Decision controls visible (approve / remove); action confirms moderation response | 1440px, 768px, 390px |

---

## 4. Site-owner expectations & suggestions

- **Option key mismatch in daily queue alert cron.** `ModerationListener::on_daily_queue_check()` reads `get_option('bn_moderation_queue_alert_threshold', 20)` but the registered setting is `buddynext_mod_queue_alert_threshold`. The cron will always use the fallback value `20` since the real key is never found. The alert email address fallback reads `bn_moderation_alert_email` (not exposed in Settings UI). Net result: the queue alert feature is completely non-functional in the free plugin. Fix: align option key in the listener to `buddynext_mod_queue_alert_threshold`. Priority: critical.

- **`bn.unsuspension_confirmation` email template has no catalogue entry.** `ModerationListener::on_user_unsuspended()` checks for this template in `bn_email_templates` and sends the email only if the row exists and is enabled. The `EmailEditor` catalogue (`EmailEditor::get_catalogue()`) has no `bn.unsuspension_confirmation` entry, so the template can never be created via the UI. Suspended members who are reinstated never receive a confirmation email. Add the template to the catalogue or fall back to a hardcoded wp_mail. Priority: high.

- **Permanent ban threshold (`buddynext_strike_perma_ban_threshold`) has no enforcement code in the free plugin.** The option is configurable in the Moderation tab but `ModerationListener::on_strike_issued()` only checks `warn_threshold` and `suspend_threshold`. Permanent ban from strikes is either Pro-gated or not yet implemented. The Setting UI should clarify this (e.g. "Managed by Pro"). Priority: medium.

- **`buddynext_banned_hashtags` option has no SafeguardService enforcement.** The option appears in the Moderation tab (textarea for banned hashtags) but `SafeguardService` has no `check_banned_hashtags()` method. Hashtag enforcement, if any, would happen at the hashtag extraction layer. This gap is not documented. Community owners who enter banned hashtags expecting content to be blocked will be silently disappointed. Priority: high.

- **Auto-hide threshold (`buddynext_auto_hide_threshold`) enforcement not confirmed in free code.** The option is registered and shown in the Moderation tab, but the auto-hide logic that reads this threshold and changes `bn_posts.status` was not found in the free plugin files reviewed. It may be in `ModerationService` or the report creation path (lines not fully audited). Verify which service reads this threshold and produces the auto-hide. Priority: high.

- **No moderator role / capability delegation.** All moderation REST routes require `manage_options` (full site administrator). Space owners/mods have partial access to the queue for their own spaces only, but cannot issue strikes, suspensions, or access the global appeal queue. Community owners managing large communities need a `buddynext_moderator` capability that grants moderation access without site-admin rights. Priority: medium.

- **No pagination on `get_pending_appeals()`.** `ModerationService::get_pending_appeals()` returns FIFO (`ORDER BY id ASC`) with a `$limit`/`$offset` from the controller, but the admin page listing appeals does not expose a filter or "next page" UI if FIFO ordering puts an unresolvable appeal first. Priority: low.

- **`ModerationLogService` has no REST or admin page.** The `bn_mod_log` table is written but never surfaced to admins. Community owners have no way to audit moderation history (who issued what strike, which report was dismissed by whom) from the UI. An audit log view in the moderation admin is expected. Priority: medium.

- **`hide_posts` suspension flag has no enforcement code found.** `POST /users/{id}/suspend` accepts `hide_posts: true` and saves it to `bn_user_suspensions.hide_posts`, but no code was found that actually hides the suspended user's posts (sets `bn_posts.status='hidden'`). The `on_content_removed` listener only handles explicit removal action, not suspension flag. Verify or implement. Priority: high.
