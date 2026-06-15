# QA — Privacy & Data (free)

**Manifest refs:** tables: `bn_notifications`, `bn_notification_prefs`, `bn_blocks` (for block-exclude SQL), `bn_space_members` (notification_pref column), `bn_search_index` (shadow-ban removal), `bn_posts`, `bn_email_log` · usermeta keys: `bn_channel_prefs`, `bn_privacy_who_can_follow`, `bn_privacy_who_can_connect`, `bn_privacy_profile_visibility`, `bn_shadow_banned`, `buddynext_digest_queue_{freq}` · REST routes: `GET/PUT /me/notification-channels` (see component 06), `GET/PUT /me/notification-prefs` (see component 06) · services: PrivacyService, ProfileService, ProfileController, AuthController, Admin/ToolsTab · capabilities: `manage_options` (Tools tab)
**Cross-ref (no dup):** FLOW-TEST-MATRIX M21 (privacy / visibility controls on edit page) · JOURNEYS none specific (member privacy settings are inline in profile edit, not a dedicated journey) · scope card component 11 (Admin Hub & Settings) for option-level bug notes; component 01 (Social Graph) for block/mute implementation; component 06 (Notifications & Email) for unsubscribe + channel prefs
**Admin location:** BuddyNext → Settings → Privacy & Data tab

---

## 1. Backend settings & options (justify each)

Toggle options (type `boolean`) that use `render_toggle_row()` are flagged **[TOGGLE-BUG]**: unchecked state is never saved because no preceding hidden input is emitted. The `buddynext_google_indexing` option is a **select** (3 values), not a boolean toggle — no toggle bug, but noted below for the default discrepancy between code and component 11 docs.

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_google_indexing` | Privacy & Data | `'public_posts'` | Select: robots meta tag applied to BuddyNext front-end pages; values: `all` (everything public), `public_posts` (public posts only), `none` (noindex all community pages) | Yes | Rendered as `render_select_row()` — no toggle bug; note: component 11 documented this as a toggle `[TOGGLE-BUG]` boolean, but `Settings.php` line 96 and the render method confirm it is a select with string values |
| `buddynext_cookie_consent` | Privacy & Data | `0` (false) | Toggle: show cookie consent banner on first visit **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; banner cannot be dismissed once turned on without DB edit; no GDPR legal basis selector |
| `buddynext_data_retention_days` | Privacy & Data | `365` | Integer: activity log entries older than N days are purged automatically; 0 = retain indefinitely | Yes | Rendered as `render_number_row()` (min 0, max 3650); no toggle bug; what exactly is "activity log" is not defined in the Settings UI hint — `bn_mod_log`? `bn_email_log`? `bn_notifications`? Ambiguous. Priority: medium |
| `buddynext_allow_data_export` | Privacy & Data | `1` (true) | Toggle: adds "Download my data" to member profile settings; generates JSON archive of posts, reactions, profile fields **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; no rate-limit on export requests (member can request export repeatedly); export archive scope not confirmed in free code |
| `buddynext_allow_account_deletion` | Privacy & Data | `1` (true) | Toggle: adds "Delete account" to member profile settings; admins can always delete regardless **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; deletion flow (what gets purged vs. anonymized) depends on `buddynext_anonymize_on_delete` |
| `buddynext_anonymize_on_delete` | Privacy & Data | `1` (true) | Toggle: when ON, posts by deleted member are reassigned to anonymous author instead of hard-deleted; preserves thread continuity **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; "anonymous author" identity (display name, avatar) not configured in Settings; no option to choose between anonymize and hard-delete |

---

## 2. Frontend functions (function-by-function)

### Per-user privacy preferences (PrivacyService)

Stored as usermeta with prefix `bn_privacy_` (e.g. `bn_privacy_who_can_follow`). Three preference keys with fixed valid values:

| Preference key | Usermeta key | Default | Valid values | Enforced by |
|---|---|---|---|---|
| `who_can_follow` | `bn_privacy_who_can_follow` | `'everyone'` | `everyone`, `nobody` | `PrivacyService::can_follow()` |
| `who_can_connect` | `bn_privacy_who_can_connect` | `'everyone'` | `everyone`, `followers`, `nobody` | `PrivacyService::can_connect()` |
| `profile_visibility` | `bn_privacy_profile_visibility` | `'public'` | `public`, `followers`, `connections`, `private` | `PrivacyService::can_view_profile()` |

`PrivacyService::set_preference()` calls `update_user_meta()` and fires `buddynext_privacy_preference_changed($user_id, $key, $value)`.

### Privacy enforcement rules

| Check | Method | Logic |
|---|---|---|
| Can actor follow target? | `PrivacyService::can_follow()` | Deny if target has blocked actor (`BlockService::is_blocked(target, actor)`); deny if `who_can_follow='nobody'`; allow if `'everyone'` |
| Can actor send connection request to target? | `PrivacyService::can_connect()` | Deny if blocked; deny if `'nobody'`; if `'followers'` → target must already follow actor (`FollowService::is_following(target, actor)`); allow if `'everyone'` |
| Can viewer see owner's profile? | `PrivacyService::can_view_profile()` | Self-view always allowed; deny if blocked; `public` → always; `followers` → viewer must follow owner; `connections` → shared accepted connection; `private` → deny all others |
| Can viewer see owner's posts/activity? | `PrivacyService::can_view_activity()` | Self-view always allowed; deny if blocked; if private account (`FollowService::is_private_account(owner)`) → viewer must be an approved follower; otherwise → allowed (per-post privacy applies at PostService layer) |

### Block exclusion SQL (PrivacyService)

`PrivacyService::block_exclude_sql()` — single source of truth for relationship exclusion SQL fragments. Generates `column NOT IN (SELECT blocked_id ... UNION SELECT blocker_id ...)` predicate with configurable `forward_types` and `reverse_types`. Used by:
- Feed: forward `block|mute`, reverse `block` (mute is feed-only soft-hide)
- Directory: forward `block`, reverse `block` (bidirectional hard stop only)
- Search: two calls — all types both directions on item subject, plus forward `restrict` on author column

Degrades gracefully when `bn_blocks` table is absent (fresh install or test harness).

### Data export (member-facing)

Controlled by `buddynext_allow_data_export`. When ON, member profile settings show "Download my data" option. Export generates a JSON archive. The full export implementation is in `ProfileService` / `ProfileController` (not detailed in this component). Key facts:
- Archive includes: posts, reactions, profile fields (stated in Settings UI description)
- Delivery method (download vs. email attachment) not confirmed in free code reviewed

### Account deletion (member-facing)

Controlled by `buddynext_allow_account_deletion`. When ON, member can delete their own account. Behavior on deletion is gated by `buddynext_anonymize_on_delete`:
- **Anonymize ON**: posts reassigned to anonymous author (preserves thread shape)
- **Anonymize OFF**: posts hard-deleted (destructive)
Implementation is in `ProfileService` / `AuthController`. The deletion also removes the user from `bn_search_index` (same path as shadow-ban removal).

### Search engine indexing

`buddynext_google_indexing` controls the `robots` meta tag on BuddyNext front-end pages:
- `all` — no noindex (public posts, profiles, spaces all crawlable)
- `public_posts` — public posts only (default)
- `none` — `noindex` on all BuddyNext community pages
Profile and space pages respect their own visibility settings regardless of this global setting (noted in the Settings UI description).

### Cookie consent

`buddynext_cookie_consent` — when enabled, a consent banner appears on first visit. BuddyNext itself sets only functional cookies (session, auth). No third-party cookie integration in the free plugin.

### Data retention purge

`buddynext_data_retention_days` — activity log entries older than N days are purged. The Settings UI description says "BuddyNext activity log entries" but does not specify which tables are included. The retention purge cron (if it exists) was not found in the free plugin files reviewed — verify in `Core/Plugin.php` or `Core/Installer.php`.

### Settings Export / Import (ToolsTab)

`ToolsTab.php` provides three maintenance utilities relevant to privacy/data under the Advanced → Tools admin tab:

| Tool | Handler | What it does |
|---|---|---|
| Repair counters | `admin_post_bn_tools_recount` | Recompute `space_members`, `follow_counts`, `post_engagement` denormalized counts; no privacy data touched |
| Flush caches | `admin_post_bn_tools_flush_cache` | Clears BuddyNext object-cache group via `CacheService::forget_group()` |
| Export settings | `admin_post_bn_tools_export` | Streams JSON of all wp_options keys matching `buddynext_%` or `bn_%` (excluding `bn_demo_manifest`); includes all privacy option values |
| Import settings | `admin_post_bn_tools_import` | Accepts uploaded JSON; whitelisted to known BN keys only (uses `collect_options()` key intersection); flushes cache after import |

The export includes `buddynext_data_retention_days`, `buddynext_allow_data_export`, `buddynext_allow_account_deletion`, `buddynext_anonymize_on_delete`, `buddynext_google_indexing`, `buddynext_cookie_consent` — all privacy-affecting options are portable between environments.

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-PRIV-001 | member | frontend | M21: Profile edit page | Visit member profile edit page | Privacy / visibility controls present: `who_can_follow`, `who_can_connect`, `profile_visibility` fields visible and saveable | 1440px, 768px, 390px |
| QA-PRIV-002 | member | frontend | Profile privacy = public | Another member (not a follower) visits profile | Profile visible | 1440px |
| QA-PRIV-003 | member | frontend | Profile privacy = followers | Non-follower visits profile | Profile hidden (`can_view_profile()` returns false); 403 or blank profile state | 1440px |
| QA-PRIV-004 | member | frontend | Profile privacy = followers | A follower visits profile | Profile visible (follower granted access) | 1440px |
| QA-PRIV-005 | member | frontend | Profile privacy = connections | A connected member visits | Profile visible | 1440px |
| QA-PRIV-006 | member | frontend | Profile privacy = connections | A follower (not connected) visits | Profile hidden; connections-only gate applies | 1440px |
| QA-PRIV-007 | member | frontend | Profile privacy = private | Any other member visits | Profile hidden; `can_view_profile()` → false for all non-self | 1440px |
| QA-PRIV-008 | member | frontend | `who_can_connect = followers` | Non-follower attempts connection request | `PrivacyService::can_connect()` returns false; connection request rejected or button hidden | 1440px, 390px |
| QA-PRIV-009 | member | frontend | `who_can_follow = nobody` | Another member tries to follow | `PrivacyService::can_follow()` returns false; follow action rejected or button hidden | 1440px, 390px |
| QA-PRIV-010 | member A | frontend | A has blocked B | B visits A's feed | `PrivacyService::can_view_activity()` → false (blocked); A's posts absent from B's view of A's profile; `block_exclude_sql()` used in feed/search queries | 1440px |
| QA-PRIV-011 | member | frontend | `buddynext_allow_data_export = 1` | Visit profile settings | "Download my data" option visible | 1440px, 390px |
| QA-PRIV-012 | admin | backend | `buddynext_allow_data_export = 1` (toggle bug means this can't be turned off) | Uncheck allow_data_export; Save | **EXPECTED TO FAIL (toggle bug)**: option remains `1`; "Download my data" still visible; `wp option get buddynext_allow_data_export --path="/Users/varundubey/Local Sites/buddynext/app/public"` returns `1` | 1440px |
| QA-PRIV-013 | member | frontend | `buddynext_allow_account_deletion = 1` | Visit profile settings | "Delete account" option visible | 1440px, 390px |
| QA-PRIV-014 | member | frontend | `buddynext_anonymize_on_delete = 1`; member has 3 posts | Member deletes account | Posts remain in DB with author reassigned to anonymous identity; threads intact; member row deleted from wp_users | 1440px |
| QA-PRIV-015 | admin | backend | Privacy & Data tab | Set `buddynext_google_indexing = none`; Save | Value persists; BuddyNext front-end pages emit `<meta name="robots" content="noindex">` (verify via page source) | 1440px |
| QA-PRIV-016 | admin | backend | Privacy & Data tab | Set `buddynext_google_indexing = all`; Save | No noindex meta on BuddyNext pages; public posts/profiles/spaces crawlable | 1440px |
| QA-PRIV-017 | admin | backend | `buddynext_cookie_consent = 0` | Uncheck cookie consent; Save | **EXPECTED TO FAIL (toggle bug)**: consent banner still appears on next guest visit | 1440px |
| QA-PRIV-018 | anonymous | frontend | `buddynext_cookie_consent = 1` (DB-forced for test) | Visit any BuddyNext page as guest | Cookie consent banner appears | 1440px, 390px |
| QA-PRIV-019 | admin | backend | Privacy & Data tab | Set `buddynext_data_retention_days = 30`; Save | Value persists as `30`; trigger retention purge cron (if implemented); entries older than 30 days removed from relevant table(s) | 1440px |
| QA-PRIV-020 | admin | backend | Tools tab | Click "Export settings" | Browser downloads `buddynext-settings.json`; JSON contains `buddynext_data_retention_days`, `buddynext_allow_data_export`, `buddynext_anonymize_on_delete`, and all other BN options | 1440px |
| QA-PRIV-021 | admin | backend | Export JSON downloaded in QA-PRIV-020 | Modify `buddynext_data_retention_days` to `90`; upload via "Import settings" | `buddynext_data_retention_days` = 90 in DB; `wp option get buddynext_data_retention_days` confirms; BuddyNext cache flushed | 1440px |
| QA-PRIV-022 | admin | backend | Import with tampered key | Upload JSON with injected key `some_other_plugin_option = "evil"` | Tampered key silently ignored; only BN-whitelisted keys updated; no extraneous options written | API |
| QA-PRIV-023 | admin | backend | Tools tab | Click "Flush BuddyNext cache" | Cache group cleared; success notice "Cache flushed." | 1440px |
| QA-PRIV-024 | admin | backend | Shadow-banned member in search | Check member directory search | Shadow-banned user absent from `bn_search_index`; does not appear in search results or member directory | 1440px |
| QA-PRIV-025 | admin | backend | Shadow-banned member | Remove shadow ban | `buddynext_index_user` action fires; user re-indexed; appears in search results again | 1440px |

---

## 4. Site-owner expectations & suggestions

- **Toggle-OFF is broken for 4 of 5 boolean Privacy options.** `buddynext_cookie_consent`, `buddynext_allow_data_export`, `buddynext_allow_account_deletion`, `buddynext_anonymize_on_delete` all use `render_toggle_row()` without a hidden input. Admins cannot reliably disable these features. Root cause: same as all other boolean settings (see component 11). Priority: critical.

- **`buddynext_google_indexing` is a select, not a boolean toggle.** Component 11 incorrectly documented this as a `[TOGGLE-BUG]` boolean. The actual implementation in `Settings.php` line 96 and `render_tab_privacy()` uses `render_select_row()` with three string values (`all`, `public_posts`, `none`). The select is not affected by the toggle bug. This distinction matters for QA: the value can be changed freely. Priority: low (documentation only).

- **Data retention purge target is ambiguous.** The Settings UI says "BuddyNext activity log entries" but does not specify which tables are purged. Community owners need to know: Does this purge `bn_mod_log`? `bn_email_log`? `bn_notifications`? `bn_reports`? Each has a different legal and operational implication. The setting description and the purge cron implementation (not found in reviewed code) must be aligned. Priority: high.

- **Data retention purge cron not confirmed.** The `buddynext_data_retention_days` setting is configurable and persisted, but no scheduled action or cron handler that reads it and purges data was found in the reviewed files (`Core/Plugin.php`, `Core/Installer.php` not fully audited). If the purge is not implemented, the setting is a no-op and community owners may believe data is being deleted when it is not. Verify and document. Priority: high.

- **No GDPR right-to-access workflow (SAR).** The data export generates a JSON file triggered by the member themselves, but there is no admin-initiated Subject Access Request (SAR) path — an admin cannot export data on behalf of a member who is locked out or submits a legal request. EU GDPR compliance typically requires an admin-side SAR flow. Priority: medium.

- **`buddynext_allow_data_export` has no rate-limit.** A member can trigger data export repeatedly without delay. On communities with large amounts of member data, this could be used to DoS the server or exfiltrate data volume. Add a cooldown (e.g. once per 24h per user). Priority: medium.

- **No confirmation of what is included in the data export.** The Settings UI description says "posts, reactions, and profile fields" but the actual export scope (whether it includes notification history, DM history, space memberships, moderation records) is undocumented. Community owners need a clear data map for GDPR Article 15 compliance. Priority: medium.

- **`buddynext_anonymize_on_delete` offers no configuration of the anonymous identity.** When a member deletes their account and anonymize is ON, posts are reassigned to "anonymous author" — but what display name, avatar, and profile link are used is not configurable. Some communities want "Deleted User", others "Anonymous". A setting for the anonymous placeholder identity would make this compliant and customisable. Priority: low.

- **Privacy preference changes have no confirmation email.** When a member changes `profile_visibility` to `private`, they receive no confirmation that the change took effect. For a security-sensitive setting, a transactional "your privacy settings were updated" email is standard practice. Priority: low.

- **Block exclusion SQL (`PrivacyService::block_exclude_sql()`) uses `UNION` of two `SELECT` subqueries inside `NOT IN (...)`.** On large communities, `NOT IN (SELECT ...)` is not index-efficient. On a `bn_blocks` table with many rows, this can become a full-scan per query. The SQL should be reviewed for indexing on `(blocker_id, type)` and `(blocked_id, type)` composite indexes, and `NOT EXISTS` or LEFT JOIN NULL pattern considered for performance at 2000+ member scale. Priority: medium (big-site readiness).
