# QA — Admin Hub & Settings (free)

**Manifest refs:** tables: `bn_email_templates` · REST routes: none (Settings API, admin-post) · services: AdminHub, Settings, NavManager, EmailEditor, IntegrationHub, Members, Spaces · capabilities: `manage_options`
**Cross-ref (no dup):** JOURNEYS J-58 (admin settings + feature toggle) · J-60 (email editor list + preview) · FLOW-TEST-MATRIX O1 (settings tab render) · O5 (navigation admin) · O6 (email templates)
**Admin location:** BuddyNext → top-level menu (slug `buddynext`); sub-sections: Settings, Members, Spaces; within Settings: 11 tabs + NavManager tab + EmailEditor tab + IntegrationHub tab

---

## 1. Backend settings & options (justify each)

Options are drawn from three sources:
- **SETTINGS_MAP** — 53 options registered via `register_setting()` in `Settings.php` (lines 32-105)
- **Extra** — 3 array options registered in `Settings.php` outside SETTINGS_MAP
- **NavManager** — 17 options stored by `NavManager.php` via direct `update_option()`, never in SETTINGS_MAP
- **AdminHub** — 1 option consumed by `AdminHub::build_menu()`, not in SETTINGS_MAP

Toggle options (type `boolean`) that use `render_toggle_row()` are flagged **[TOGGLE-BUG]**: unchecked state is never saved because no preceding hidden input is emitted (see `AdminPageBase.php` lines 161-186).

### General tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_site_name` | General | `''` | Community display name shown in emails and UI | Yes | No character limit enforced; very long values could break email headers |
| `buddynext_brand_color` | General | `'#6366f1'` | Primary brand hex color applied as CSS custom property | Yes | No dark-mode contrast check; owner can set an unreadable color |
| `buddynext_description` | General | `''` | Short tagline used in SEO meta and onboarding screens | Yes | Not wired to `wp_head` meta tag; setting exists but has no frontend output verified |
| `buddynext_public_explore` | General | `1` | Toggle: allow guests to view Explore page without login **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_enable_dm` | General | `1` | Toggle: enable Direct Messaging for members **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_default_dm_access` | General | `'followers'` | Who can DM a member: everyone / followers / connections / nobody | Yes | No per-member override surfaced in profile settings |
| `buddynext_show_onboarding` | General | `1` | Toggle: show onboarding wizard to new members **[TOGGLE-BUG]** | Yes | No option to re-trigger onboarding for existing members |

### Features tab (outside SETTINGS_MAP)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_features` | Features | `[]` (array) | Array of enabled feature slugs (polls, bookmarks, shares, reactions, hashtags, link-preview, emoji-picker, etc.) | Yes | No granular per-role control; all or nothing per feature |

### Registration tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_reg_mode` | Registration | `'open'` | Registration mode: open / invite-only / approval | Yes | — |
| `buddynext_email_verify` | Registration | `1` | Toggle: require email verification before first login **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_reg_spam_protection` | Registration | `'none'` | Spam protection method: none / recaptcha / honeypot | Yes | reCAPTCHA secret stored in `buddynext_social_login` array — no dedicated option |
| `buddynext_reg_challenge` | Registration | `''` | Custom text challenge question shown at signup | Questionable | No answer field validation; any string passes |
| `buddynext_reg_rate_limit` | Registration | `5` | Max registration attempts per IP per hour | Yes | No UI feedback shown to throttled users |
| `buddynext_allowed_domains` | Registration | `''` | Newline-separated email domain allowlist | Yes | No DNS MX validation; typos silently block all registrations |
| `buddynext_social_login` | Registration | `[]` (array) | Google and Facebook OAuth credentials: `{enabled, client_id, client_secret}` | Yes | Secret field is masked (`type="password"`, `Settings.php:857`) and sanitized; at-rest storage in `wp_options` is standard for OAuth secrets — optional hardening only (see §4) |

### Social tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_default_post_privacy` | Social | `'public'` | Default privacy for new posts: public / followers / connections / private | Yes | — |
| `buddynext_allow_polls` | Social | `1` | Toggle: enable poll creation in composer **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_allow_shares` | Social | `1` | Toggle: enable share/repost button **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_allow_bookmarks` | Social | `1` | Toggle: enable bookmark/save button **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_enable_link_preview` | Social | `1` | Toggle: fetch OpenGraph preview when URL pasted in composer **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_enable_emoji_picker` | Social | `1` | Toggle: show emoji picker in composer **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_post_edit_window` | Social | `15` | Minutes after posting during which author can edit | Yes | No indicator shown to member of remaining edit time |
| `buddynext_enabled_reactions` | Social | `[]` (array) | Array of reaction emoji slugs enabled for posts | Yes | Checkboxes use `name="buddynext_enabled_reactions[]"` — not affected by toggle bug, but no reorder control |

### Spaces tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_enable_spaces` | Spaces | `1` | Toggle: globally enable Spaces feature **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_space_creation_role` | Spaces | `'subscriber'` | Minimum role required to create a Space | Yes | — |
| `buddynext_space_max_sub_spaces` | Spaces | `5` | Max sub-spaces per parent Space | Questionable | No max=0 to disable sub-spaces entirely; owner must set an arbitrarily large number |

### Moderation tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_auto_hide_threshold` | Moderation | `5` | Report count at which a post is auto-hidden pending review | Yes | — |
| `buddynext_strike_warn_threshold` | Moderation | `2` | Strikes before warning email is sent | Yes | — |
| `buddynext_strike_suspend_threshold` | Moderation | `5` | Strikes before account is suspended | Yes | — |
| `buddynext_strike_perma_ban_threshold` | Moderation | `10` | Strikes before permanent ban | Yes | No grace period or appeal window configurable here |
| `buddynext_mod_queue_alert_threshold` | Moderation | `20` | Queued items count that triggers admin email alert | **No** | BUG (filed to Basecamp Bugs): `ModerationListener.php:411` reads the wrong key `bn_moderation_queue_alert_threshold`, so this setting is ignored and the alert always uses the default 20 |
| `buddynext_banned_words` | Moderation | `''` | Newline-separated list of banned words/phrases | Yes | No regex support; exact-match only |
| `buddynext_blocked_domains` | Moderation | `''` | Newline-separated list of blocked link domains | Yes | No wildcard subdomain matching |
| `buddynext_banned_hashtags` | Moderation | `''` | Newline-separated list of banned hashtags | Yes | — |
| `buddynext_post_rate_limit` | Moderation | `10` | Max posts a member can create per hour | Yes | Rate limit not applied to admin/editor roles |
| `buddynext_new_member_post_threshold` | Moderation | `3` | Max posts allowed in first 24h for new members | Yes | No UI signal to new member explaining the limit |

### Notifications tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_notif_default_follow` | Notifications | `1` | Toggle: new-follower notification ON by default for members **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_connection` | Notifications | `1` | Toggle: connection-request notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_reaction` | Notifications | `1` | Toggle: reaction notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_comment` | Notifications | `1` | Toggle: comment notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_mention` | Notifications | `1` | Toggle: @mention notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_notif_default_space_join` | Notifications | `1` | Toggle: space-join notification ON by default **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_digest_frequency` | Notifications | `'weekly'` | Email digest cadence: realtime / daily / weekly / never | Yes | — |
| `buddynext_admin_alert_email` | Notifications | `''` | Email address for admin system alerts | Yes | Falls back to `admin_email` when blank, but not documented in UI |

### Email tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_email_from_name` | Email | `''` | Sender display name in outbound emails | Yes | — |
| `buddynext_email_from_address` | Email | `''` | Sender FROM address | Yes | No SPF/DKIM validation hint; owners often set an address their server can't send from |
| `buddynext_email_reply_to` | Email | `''` | Reply-To header address | Questionable | Left blank by default, meaning Reply-To = From; no UI explains the difference |
| `buddynext_email_footer_text` | Email | `''` | Footer text appended to all transactional emails | Yes | No `{unsubscribe_url}` token documented or auto-inserted |

### Integrations tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_jetonomy_feed_sync` | Integrations | `0` | Toggle: sync Jetonomy forum activity into BuddyNext feed **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug; also inconsistency: tab uses `class_exists()` to detect Jetonomy while IntegrationHub.php uses `is_plugin_active()` |

### Privacy tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_google_indexing` | Privacy | `'public_posts'` | Select (string): search-engine indexing scope — `all` / `public_posts` / `none` | Yes | NOT a toggle (sanitize_key string) — the render_toggle_row OFF-persistence bug does not apply |
| `buddynext_cookie_consent` | Privacy | `0` | Toggle: show cookie consent banner **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_data_retention_days` | Privacy | `365` | Days to keep deleted member's activity data before purge | Yes | No dry-run or preview of records that would be purged |
| `buddynext_allow_data_export` | Privacy | `1` | Toggle: members can request personal data export **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_allow_account_deletion` | Privacy | `1` | Toggle: members can delete their own account **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |
| `buddynext_anonymize_on_delete` | Privacy | `1` | Toggle: anonymize content (replace names/avatars) when member deletes account **[TOGGLE-BUG]** | Yes | Cannot be turned OFF reliably due to toggle bug |

### Webhooks tab

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_webhook_secret` | Webhooks | `''` | HMAC secret used to sign outbound webhook payloads | Yes | Endpoint list/add/delete is JS + REST only, not stored in wp_options; no UI to rotate secret without clearing and retyping |

### NavManager options (stored by NavManager.php, not in SETTINGS_MAP)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_nav_overrides` | Settings > Nav Manager (main scope) | `[]` | Hub order + visibility + custom tabs for main nav | Yes | Visibility row checkboxes share the same toggle bug |
| `buddynext_nav_overrides_profile` | Settings > Nav Manager (profile scope) | `[]` | Hub order + visibility for profile nav | Yes | Same toggle bug |
| `buddynext_nav_overrides_space` | Settings > Nav Manager (space scope) | `[]` | Hub order + visibility for space nav | Yes | Same toggle bug |
| `buddynext_nav_overrides_mobile` | Settings > Nav Manager (mobile scope) | `[]` | Hub order + visibility for mobile bottom bar | Yes | Same toggle bug |
| `buddynext_page_activity` | Settings > Nav Manager | WP page ID | WP page assigned as the Activity/Feed hub | Yes | Confirmed live value: `5` |
| `buddynext_page_auth` | Settings > Nav Manager | WP page ID | WP page assigned as the Auth hub | Yes | Confirmed live value: `10` |
| `buddynext_page_feed` | Settings > Nav Manager | WP page ID | WP page for feed (alias of activity) | Questionable | Separate from `buddynext_page_activity`; potential conflict if they point to different pages |
| `buddynext_page_members` | Settings > Nav Manager | WP page ID | WP page for member directory | Yes | Confirmed live value: `12` |
| `buddynext_page_messages` | Settings > Nav Manager | WP page ID | WP page for DM hub | Yes | Confirmed live value: `8` |
| `buddynext_page_notifications` | Settings > Nav Manager | WP page ID | WP page for notifications hub | Yes | Confirmed live value: `9` |
| `buddynext_page_people` | Settings > Nav Manager | WP page ID | WP page for People/Members hub | Yes | Confirmed live value: `6` |
| `buddynext_page_profile` | Settings > Nav Manager | WP page ID | WP page for member profile | Yes | Confirmed live value: `13` |
| `buddynext_page_spaces` | Settings > Nav Manager | WP page ID | WP page for Spaces hub | Yes | Confirmed live value: `7` |
| `buddynext_slug_activity` | Settings > Nav Manager | `'activity'` | URL slug for activity hub | Yes | Confirmed live: `activity` |
| `buddynext_slug_auth` | Settings > Nav Manager | `'login'` | URL slug for auth hub | Yes | Confirmed live: `login` |
| `buddynext_slug_messages` | Settings > Nav Manager | `'messages'` | URL slug for DM hub | Yes | Confirmed live: `messages` |
| `buddynext_slug_notifications` | Settings > Nav Manager | `'notifications'` | URL slug for notifications hub | Yes | Confirmed live: `notifications` |
| `buddynext_slug_people` | Settings > Nav Manager | `'members'` | URL slug for People hub | Yes | Confirmed live: `members` |
| `buddynext_slug_spaces` | Settings > Nav Manager | `'spaces'` | URL slug for Spaces hub | Yes | Confirmed live: `spaces` |

### AdminHub option (consumed but not registered in SETTINGS_MAP)

| Option key | Tab / location | Default | What it controls | Justified? | Gap / missing must-have |
|---|---|---|---|---|---|
| `buddynext_white_label` | Settings → Appearance (`AppearanceTab.php`) | `''` | Renames the "BuddyNext" top-level admin menu label | Yes | Has a UI (corrected on re-verification): `AppearanceTab.php` reads it (line 58) and writes it (line 137); `AdminHub::build_menu()` consumes it |

---

## 2. Frontend functions — admin pages

This is a pure-admin component. No public frontend functions. Admin pages below:

| Admin page | Slug / URL | What the site owner does here |
|---|---|---|
| Settings — General | `admin.php?page=buddynext` | Set site name, brand color, tagline, guest explore toggle, DM baseline, onboarding toggle |
| Settings — Features | `admin.php?page=buddynext&tab=features` | Enable / disable individual feature modules (polls, bookmarks, shares, reactions, hashtags, etc.) |
| Settings — Registration | `admin.php?page=buddynext&tab=registration` | Choose open / invite / approval mode; configure email verify, spam protection, rate limiting, allowed domains, social login OAuth credentials |
| Settings — Social | `admin.php?page=buddynext&tab=social` | Default post privacy, toggle composer features, set post-edit window, configure reaction emoji palette |
| Settings — Spaces | `admin.php?page=buddynext&tab=spaces` | Enable spaces globally, set creation role, cap sub-space depth |
| Settings — Moderation | `admin.php?page=buddynext&tab=moderation` | Configure auto-hide threshold, strike escalation thresholds, banned words / domains / hashtags, rate limits |
| Settings — Notifications | `admin.php?page=buddynext&tab=notifications` | Default notification preferences for new members, digest frequency, admin alert email |
| Settings — Email | `admin.php?page=buddynext&tab=email` | FROM name / address / reply-to, email footer text |
| Settings — Privacy | `admin.php?page=buddynext&tab=privacy` | Search engine indexing, cookie consent, data retention period, member export / deletion / anonymization toggles |
| Settings — Integrations | `admin.php?page=buddynext&tab=integrations` | Toggle integrations with detected plugins (Jetonomy feed sync, etc.) |
| Settings — Webhooks | `admin.php?page=buddynext&tab=webhooks` | Set HMAC webhook secret; add / remove / list outbound webhook endpoints (JS-driven via REST) |
| Settings — Nav Manager | `admin.php?page=buddynext&tab=nav_manager` | Drag-and-drop reorder nav hubs per scope (main / profile / space / mobile), toggle hub visibility, add custom tabs, assign WP pages to hubs, set slugs |
| Settings — Email Templates | `admin.php?page=buddynext&tab=email_editor` | Select from 22 email templates, edit subject / preview text / body HTML, send test email to admin, reset to default |
| Settings — Addons | `admin.php?page=buddynext&tab=integrations_hub` | View active / inactive addon cards (WPMediaVerse, Jetonomy, WBGamification, Career Board); configure link navigates to BuddyNext root settings |
| Members — Directory | `admin.php?page=buddynext-members` | List members with search + role + status filter, paginated (20/page), suspend / unsuspend, export CSV |
| Spaces — Directory | `admin.php?page=buddynext-spaces` | List spaces with search + type filter, paginated (20/page), view space on frontend, delete space; manage space categories inline |

---

## 3. QA cases

| ID | Role | Layer | Pre-state | Steps | Expected | Viewports |
|---|---|---|---|---|---|---|
| QA-ADMIN-001 | admin | backend | Fresh install, default options | Navigate to `admin.php?page=buddynext?autologin=1`; verify all 11 tabs render without PHP errors or JS console errors | All tabs load; active tab highlighted; no blank panels | 1440px, 768px, 390px |
| QA-ADMIN-002 | admin | backend | General tab open | Change Site Name to `TestCommunity`; click Save | Success notice appears; reload page; `buddynext_site_name` = `TestCommunity` (`wp option get buddynext_site_name --path=...`) | 1440px, 390px |
| QA-ADMIN-003 | admin | backend | Features tab open | Disable the `polls` feature by unchecking it; Save | **EXPECTED TO FAIL due to toggle bug**: option reverts to previous enabled state on reload; `wp option get buddynext_features` still includes `polls` | 1440px |
| QA-ADMIN-004 | admin | backend | Notifications tab, all defaults ON | Uncheck `buddynext_notif_default_follow`; Save | **EXPECTED TO FAIL**: checkbox absent from POST is not submitted; `wp option get buddynext_notif_default_follow` returns `1` after reload | 1440px |
| QA-ADMIN-005 | admin | backend | Privacy tab | Set `buddynext_google_indexing` select to `none`; Save; reload | Select (not a toggle) persists; `wp option get buddynext_google_indexing` → `none` (default is `public_posts`) | 1440px |
| QA-ADMIN-006 | admin | backend | Registration tab | Set Mode to `approval`; Save; reload | `buddynext_reg_mode` = `approval`; Members admin shows "Pending" sub-tab | 1440px, 390px |
| QA-ADMIN-007 | admin | backend | Moderation tab | Set auto-hide threshold to `1`; banned word `spam`; Save; reload | `buddynext_auto_hide_threshold` = `1`; `buddynext_banned_words` = `spam` | 1440px |
| QA-ADMIN-008 | admin | backend | Email tab | Set From Name `BuddyNext Test`; From Address `noreply@example.com`; Save; reload | Values persist; `wp option get buddynext_email_from_name` = `BuddyNext Test` | 1440px |
| QA-ADMIN-009 | admin | backend | Nav Manager tab open | Drag second hub to first position in main-nav scope; Save | `buddynext_nav_overrides` updates; reload Nav Manager; new order displayed; frontend nav reflects new order | 1440px, 768px |
| QA-ADMIN-010 | admin | backend | Nav Manager, main scope | Uncheck visibility for a hub row; Save | **EXPECTED TO FAIL**: toggle bug applies to nav visibility checkboxes; hub remains visible; `buddynext_nav_overrides` row `visible` stays `1` | 1440px |
| QA-ADMIN-011 | admin | backend | Nav Manager, slug section | Change `buddynext_slug_people` to `community`; Save | `buddynext_slug_people` = `community`; People hub URL resolves at `/community/`; old `/members/` URL 404s | 1440px |
| QA-ADMIN-012 | admin | backend | Nav Manager, two hubs assigned same WP page | Assign same WP page ID to two different hub page dropdowns; Save | Save blocked; redirect to Nav Manager with `?bn_notice=page_conflict` query param; conflict notice visible | 1440px |
| QA-ADMIN-013 | admin | backend | Email Templates tab | Open template `bn.new_follower`; change Subject line; Save via `buddynext_email_save` action | Row in `bn_email_templates` updated; reload template; new subject persists | 1440px, 390px |
| QA-ADMIN-014 | admin | backend | Email Templates tab, template edited | Click "Send Test"; observe where test email lands | Test delivered to `admin_email` (the documented recipient — UI states "Send a test email to <admin_email>", `EmailEditor.php:972`); placeholders used in body. By design (recipient ≠ From), not a defect | 1440px |
| QA-ADMIN-015 | admin | backend | Email Templates tab, template edited | Click "Reset to Default" | Row deleted from `bn_email_templates`; template reverts to catalogue default; reload shows original subject / body | 1440px |
| QA-ADMIN-016 | admin | backend | IntegrationHub tab, Jetonomy inactive | View Addons tab with Jetonomy plugin deactivated | Jetonomy card shows "Inactive" badge; no Configure CTA; no errors | 1440px, 390px |
| QA-ADMIN-017 | admin | backend | IntegrationHub tab, Jetonomy active | Activate Jetonomy plugin; visit Addons tab | Jetonomy card shows "Active" badge and Configure CTA; CTA links to BuddyNext root admin | 1440px |
| QA-ADMIN-018 | non-admin (subscriber) | backend | Logged in as subscriber | Navigate directly to `admin.php?page=buddynext` | `wp_die()` fires or redirect to `wp-login.php`; subscriber sees no settings content | 1440px, 390px |
| QA-ADMIN-019 | non-admin (editor) | backend | Logged in as editor | Navigate directly to `admin.php?page=buddynext` | Same as QA-ADMIN-018; `manage_options` cap required; editor blocked | 1440px |
| QA-ADMIN-020 | admin | backend | Members admin | Visit `admin.php?page=buddynext-members`; search for a username; filter by Suspended status | Members list filters correctly; paginated (20/page); CSV export downloads with ID, Login, Email, Registered columns | 1440px, 390px |
| QA-ADMIN-021 | admin | backend | Spaces admin | Visit `admin.php?page=buddynext-spaces`; filter by type `private`; delete a space | Space removed from `bn_spaces`; `bn_space_members` rows for that space deleted; `buddynext_space_deleted` hook fires | 1440px |
| QA-ADMIN-022 | admin | backend | Spaces admin, Categories subtab | Visit `?page=buddynext-spaces&subtab=categories`; create category `Gaming`; delete it | Create persists; delete nullifies `category_id` on affected spaces; no orphan rows | 1440px |
| QA-ADMIN-023 | admin | backend | Webhooks tab | Enter HMAC secret; Save; add an endpoint URL via JS form; reload | `buddynext_webhook_secret` persists; endpoint appears in list; endpoint stored via REST (not wp_options); reload shows it | 1440px |
| QA-ADMIN-024 | admin | backend | Social tab | Set `buddynext_post_edit_window` to `0`; Save | Value persists as `0`; members no longer see Edit button on posts after save | 1440px |
| QA-ADMIN-025 | admin | backend | Registration tab, Social Login | Enter Google `client_id` and `client_secret`; enable Google OAuth toggle; Save | Values saved to `buddynext_social_login` array; reload; values present; secret field is masked (`type="password"`). At-rest storage is standard (optional hardening only) | 1440px |

---

## 4. Site-owner expectations & suggestions

- **Toggle-OFF is broken for all boolean settings.** Any admin who unchecks a feature toggle expecting it to disable that feature will find the setting silently reverts to ON. This affects 20+ options across all tabs. Root cause: `render_toggle_row()` in `AdminPageBase.php` emits no preceding `<input type="hidden" name="option" value="0">`. Fix required before any toggle-based setting is considered functional. Priority: critical.

- **~~`buddynext_white_label` has no UI~~ — CORRECTED on re-verification: it does.** `AppearanceTab.php` provides the Settings → Appearance UI that reads (line 58) and writes (line 137) the option; `AdminHub::build_menu()` consumes it. No action needed. (Original audit missed the Appearance tab — it is not in the `Settings.php` 11-tab array.)

- **Social login `client_secret` at-rest (minor, not a defect).** The field is masked in the UI (`type="password"`, `Settings.php:857`) and sanitized; OAuth secrets in `wp_options` is standard practice across WP social-login plugins (core has no secret vault). Optional hardening only: encrypt at rest via an `AUTH_KEY`-derived key. Priority: low.

- **Email test recipient is the admin — by design, not a defect (CORRECTED).** `EmailEditor::send_test()` sends the test to `admin_email` as the *recipient*, and the UI explicitly states "Send a test email to <admin_email>" (`EmailEditor.php:972`). Recipient ≠ From. Optional nicety: allow a custom test recipient. Priority: low.

- **Members CSV export is too thin.** Export covers only ID, Login, Email, Registered. Community owners routinely need: display name, last-active date, role, suspension status, post count, and space memberships. Priority: medium.

- **Spaces admin has no Edit action.** The space directory has View (opens frontend) and Delete. Owners cannot rename a space, change its type (open/private/secret), or reassign its category without going to the frontend as that space's admin. An inline Edit panel or modal is expected. Priority: medium.

- **No moderator-role delegation.** All admin pages require `manage_options` (admin cap only). There is no way to grant a trusted community manager access to Moderation, Members, or Spaces admin without making them a full WP administrator. A `buddynext_moderator` capability or role should be supported. Priority: medium.

- **No integration-specific config links in IntegrationHub.** Active addon cards link to `admin.php?page=buddynext` (the BuddyNext root), not to the addon's own settings page. A Jetonomy admin expects the Configure CTA to open Jetonomy settings. Priority: low.

- **`buddynext_page_feed` and `buddynext_page_activity` are separate options with no documented relationship.** If both are set to different pages, behavior is undefined. These should be unified or one deprecated with a clear UI note. Priority: low.

- **No empty-state guidance in the Email Templates list.** If `bn_email_templates` table is empty or a category has zero rows, the UI shows a blank panel with no message. Add "No templates in this category" messaging. Priority: low.
