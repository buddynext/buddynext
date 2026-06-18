# Admin Screen Coverage Matrix (Definition of Done per screen)

> Ground truth for the backend-appearance audit, compiled 2026-06-18 from the real
> Free + Pro admin code (Settings.php, every Admin/* class, Pro admin classes).
> Every screen's redesign MUST carry all of these — presentation-only, drop nothing.
> Format: `option_key / column / action — Label — type — default`. Pro marked (PRO).

## Settings
### General (settings:general)
- buddynext_site_name — Community Name — text — site name
- buddynext_brand_color — Brand color — color — #0073aa
- buddynext_description — Community Description — textarea — ''
- buddynext_public_explore — Public explore feed — toggle — on
- buddynext_enable_dm — Enable direct messaging — toggle (disabled if WPMediaVerse absent) — on
- buddynext_default_dm_access — Who can DM me (default) — select everyone|members|connections|nobody — everyone
- buddynext_member_dir_columns — Member directory columns — select auto|2|3|4 — 3
- buddynext_spaces_dir_columns — Space directory columns — select auto|2|3|4 — 3
- buddynext_enable_community_nav — Auto-place community menu in theme — toggle — on

### Appearance (settings:appearance) — separate form
- buddynext_logo_url — Logo — file (PNG/JPEG/WebP/SVG ≤2MB) — ''
- buddynext_default_theme — Default theme — select auto|light|dark — auto
- buddynext_custom_css — Custom CSS — textarea — ''

### Navigation (settings:navigation) — NavManager, 3-panel
- 4 sortable scopes: Main nav, Profile tabs, Space tabs, Mobile bottom nav
- Per nav item: page assignment (wp_dropdown_pages), label override (text), visibility (toggle), capability gate (select ability), login-required (toggle), guest label (text), conflict validation

### Features (settings:features) — FeatureRegistry, grouped
- Core (always on, locked toggles): Activity Feed, Social Graph, Spaces, Profiles, Notifications…
- Community: Reactions, Comments, Hashtags, Polls, Bookmarks, Shares, Link Preview, Emoji Picker (toggles)
- Integration bridges (disabled if partner absent): WPMediaVerse, Jetonomy, WBGamification, Career Board (toggles)
- Power-user: Custom Reactions, Advanced Fields, Moderation Rules, Scheduled Posts, AI Moderation, Realtime (toggles)
- Each row: label, description, tier badge, dependencies, required-plugin warning

## Platform
### Integrations (settings:integrations)
- Companion catalog: WPMediaVerse, Jetonomy, WBGamification, Career Board — status badge (Active/Inactive/Not installed) + 1-click Install/Activate/Get Pro
- buddynext_jetonomy_feed_sync — Surface Jetonomy discussions in feed — toggle — on

### Tools (settings:tools) — separate form
- Background tasks — health status display (healthy / N waiting / WP-Cron-off warning + cron command)
- Actions: Recount space members, Recount follow counts, Recount post reactions&comments, Flush cache, Export settings, Import settings (file)
- Demo data — seed/remove (DemoAdmin)

### Webhooks (settings:webhooks)
- buddynext_webhook_secret — Shared Secret — password (show/copy/generate-rotate) — ''
- Endpoints table: URL, Events, Status, Created, Actions (Send test, View log, Remove)
- Add endpoint: URL (text), Events — 14 checkboxes (member.registered, member.verified, user.suspended, user.unsuspended, member.ability_granted, member.ability_revoked, post.created, post.deleted, comment.created, reaction.added, user.followed, connection.accepted, space.joined, space.left)

## Members
### Directory (members:directory) — list
- KPIs: Total / Active / New this week / Suspended
- Filters: status chips (All/Active/Suspended), search (name/email/username), role dropdown
- Columns: Member (name+@handle+avatar), Email, Role, Status, Joined, Last Active, Last Login, Actions (View/Edit/Suspend-Unsuspend)
- Pagination (shared pager, 20/page)

### Member Labels (members:labels) (PRO) — list
- Table: Label (color swatch), Icon, Members, Actions (Edit/Delete)
- Add: name (text), color (color), icon (select), description (textarea)

### Registration (settings:registration)
- buddynext_reg_mode — Registration Mode — select open|invite|approval — open
- buddynext_email_verify — Require email verification — toggle — off
- buddynext_auth_panel_show — Show branding panel — toggle — on
- buddynext_auth_panel_heading — Panel heading — text — site name
- buddynext_auth_panel_tagline — Panel tagline — textarea — site tagline
- buddynext_auth_panel_quote — Featured quote — textarea — ''
- buddynext_auth_panel_image — Panel banner image URL — text — ''
- buddynext_reg_spam_protection — Protect the sign-up form — toggle — on
- buddynext_reg_challenge — Human-verification question — toggle — on
- buddynext_reg_rate_limit — Sign-ups per hour per IP — number 0–100 — 5
- buddynext_allowed_domains — Allowed email domains — textarea — ''
- Social login (per provider Google/GitHub/Apple/Discord/Microsoft): enabled (toggle), client_id (text), client_secret (password), redirect URL (readonly copy), setup steps (details)

### Roles & capabilities (settings:roles) — matrix (each = select member|moderator|admin|'')
- Posts: create-post, schedule-post, pin-post, delete-any-post
- Spaces: create, join, post, moderate
- Connections: follow, connect
- Profiles: edit-any
- Moderation: report, review-queue, issue-strike, suspend-user
- Actions: Save permissions, Reset to defaults

### Privacy (settings:privacy)
- buddynext_google_indexing — Allow search engines to index — select all|public_posts|none — public_posts
- buddynext_cookie_consent — Show cookie consent notice — toggle — off
- buddynext_data_retention_days — Activity log retention (days) — number 0–3650 — 365
- buddynext_allow_data_export — Allow members to export data — toggle — on
- buddynext_allow_account_deletion — Allow members to delete account — toggle — on
- buddynext_anonymize_on_delete — Anonymise posts on deletion — toggle — on

### Member sub-managers (Members admin tabs)
- Profile Fields: field groups table; field form (label, type [+PRO types: date_extended/location/file/multi_select_advanced/number_advanced/conditional], required, visibility, help text, per-type options)
- Member Types: table (label/icon/count/actions); add (label, icon, description)
- Avatar settings: allow custom avatars (toggle), avatar style (initials/gravatar/upload), initials color scheme
- Invitations (invite mode): pending table (email/invited-by/sent/expires/status/actions resend-revoke); create (emails textarea)
- Approval (approval mode): pending requests table (user/email/applied/approve-reject)

## Spaces
### Directory (spaces:directory) — list
- KPIs: Total / Open / Private / Secret
- Filters: search (name), type dropdown (All/Open/Private/Secret)
- Columns: Space (name+icon), Type, Members, Posts, Owner, Created, Actions (Edit/Delete)
- Pagination
- Categories sub-tab: category CRUD (add/edit/delete/reorder)

### Space settings (settings:spaces)
- buddynext_space_creation_role — Who can create spaces — select member|admin — member
- buddynext_space_max_per_member — Max spaces per member — number ≥0 — 0 (unlimited)
- buddynext_space_allow_sub — Allow sub-spaces — toggle — on
- buddynext_space_max_sub_spaces — Max sub-spaces per space — number ≥0 — 0
- buddynext_space_default_type — Default visibility for new spaces — select (SpaceTypeRegistry) — open
- buddynext_space_default_category — Default category for new spaces — select (0=None + categories) — 0
- (PRO) Per-space Brand tab: logo URL, hue, font, custom CSS (inherit site if blank)

## Engagement
### Insights (settings/growth:insights) — dashboard
- At-a-glance cards: Members, Active(30d), Posts, Spaces, Comments, Reactions, Connections, Follows
- New members · last 14 days — spark histogram
- (PRO) Analytics injected: time-window filter (Today/7d/30d/custom); stat grid (DAU, New members, Posts published, Engagement rate); Daily activity chart; Top content; Top members; Export CSV; sub-views Cohorts (retention table), Funnel (drop-off), Profile Views (list + opt-out)

### Social graph (settings:social)
- buddynext_default_post_privacy — Default post visibility — select public|followers|connections|private — public
- buddynext_allow_polls — Allow polls — toggle — on
- buddynext_allow_shares — Allow re-shares — toggle — on
- buddynext_allow_bookmarks — Allow bookmarks — toggle — on
- buddynext_enable_link_preview — Enable link previews — toggle — on
- buddynext_enable_emoji_picker — Enable emoji picker — toggle — on
- buddynext_post_edit_window — Post edit window (minutes) — number ≥0 — 60
- buddynext_enabled_reactions — Reactions palette — multi-checkbox (like/love/haha/wow/sad/angry, min 1) — all 6

### Reactions (settings:reactions) (PRO custom)
- Default reactions — read-only palette + link to Social
- Custom reactions table: emoji, label, slug, Remove
- Add: label (text ≤20), slug (text kebab), emoji (picker) — cap 20 total

## Notifications
### Notifications (settings:notifications)
- buddynext_notif_default_follow / _connection / _reaction / _comment / _mention / _space_join — default prefs — toggles — on
- buddynext_digest_frequency — Digest frequency — select never|daily|weekly — weekly
- buddynext_admin_alert_email — Admin alert email — text(email) — admin_email

### Email (settings:email)
- buddynext_email_from_name — From name — text — site name
- buddynext_email_from_address — From address — text(email) — admin email
- buddynext_email_reply_to — Reply-To address — text(email) — ''
- buddynext_email_footer_text — Footer text — textarea ({{site_name}}/{{site_url}}/{{current_year}}) — ''

### Email Templates (settings:templates) — split-pane editor
- Left rail: catalogue (8 categories: Social/Spaces/Moderation/Gamification/Jetonomy/Auth/Digests/Onboarding), each row name + trigger + enabled badge
- Editor: subject (text), preview_text (text), body_html (textarea), enabled (toggle), tokens (clickable insert list)
- Tabs: HTML / Plain (read-only) / Preview (iframe)
- Actions: Reset to default, Send test (recipient input), Save

## Realtime & Push (PRO)
### Realtime (settings:realtime)
- buddynextpro_soketi_enabled — Enable realtime — toggle — off
- buddynextpro_soketi_host — Host — text(url) — ''
- buddynextpro_soketi_app_id — App ID — text — ''
- buddynextpro_soketi_key — Key — text — ''
- buddynextpro_soketi_secret — Secret — password — ''
- Test connection (button); setup guide (read-only)

### Push (settings:push)
- buddynextpro_push_enabled — Enable push delivery — toggle — off
- buddynextpro_fcm_project_id — Firebase project ID — text — ''
- buddynextpro_fcm_web_api_key — Web API key — text — ''
- buddynextpro_fcm_sender_id — Sender ID — text — ''
- buddynextpro_fcm_web_app_id — Web app ID — text — ''
- buddynextpro_fcm_vapid_key — VAPID key — text — ''
- buddynextpro_fcm_service_account_json — Service account JSON — textarea (masked, autoload no) — ''
- Test push (button)

### Push defaults (settings:push-prefs)
- Default push categories (mentions/new followers/digest…) — toggles

## Campaigns (PRO)
### Broadcasts (growth:broadcasts) — list + editor
- List: Campaign, Status (pending/sending/sent), Created, Recipients, Actions (Edit/View recipients/Send test/Send now/Cancel)
- Editor: name, subject, body HTML (tokens), segment (all/by-space/by-tag/by-activity/by-join-date/by-member-label), schedule (now/later + datetime), Send test, Draft/Send
- Recipients view: pending/sent/bounced/unsubscribed counts

### Drip (growth:drip) — list + editor
- List: Sequence, Trigger (registration/onboarding/manual), Enabled (toggle), Steps, Actions (Edit/Delete)
- Editor: name, trigger (select), steps builder (per step: template select, delay minutes, move up/down, delete; add step); live preview iframe

### Scheduled (growth:scheduled) — list
- Columns: Author, Excerpt, Scheduled for, Actions (Preview/Cancel/Publish now)
- Header action: Publish overdue posts now

### AI Feed (growth:ai-feed)
- buddynextpro_ai_feed_enabled — Enable AI feed ranking — toggle — off
- buddynextpro_ai_decay_days — Affinity decay window (days) — number 1–365 — 14

## Moderation
### Moderation settings (settings:moderation)
- buddynext_premod_mode — Hold posts for approval — select off|new_members|links|all — off
- buddynext_premod_new_member_count — New-member posts to review — number ≥1 — 1
- buddynext_auto_hide_threshold — Auto-hide after N reports — number ≥1 — 5
- buddynext_mod_queue_alert_threshold — Queue alert threshold — number ≥0 — 20
- buddynext_strike_warn_threshold — Strikes before warning — number ≥1 — 2
- buddynext_strike_suspend_threshold — Strikes before suspension — number ≥1 — 5
- buddynext_strike_perma_ban_threshold — Strikes before permanent ban — number ≥0 — 0
- buddynext_banned_words — Banned words — textarea — ''
- buddynext_banned_hashtags — Banned hashtags — textarea — ''
- buddynext_blocked_domains — Blocked link domains — textarea — ''
- buddynext_blocked_ips — Blocked IP addresses — textarea — ''
- buddynext_post_rate_limit — Post rate limit (per minute) — number ≥0 — 10
- buddynext_duplicate_post_window — Duplicate post window (minutes) — number ≥0 — 0
- buddynext_new_member_post_threshold — New member review threshold — number ≥0 — 0

### Queues (moderation:pending/reports/suspensions/appeals/log/bulk)
- Pending: Post, Author, Space, Held, Actions (Approve/Reject)
- Reports: Reported Content, Reason, Reporter ("N users"), When, Actions (Dismiss/Resolve/Remove/Escalate/Strike author/Suspend author)
- Suspensions: Member, Reason, Expires, Actions (Lift)
- Appeals: Member, Appeal, When, Decision (Approve/Deny)
- Moderation Log: When, Moderator, Action, Target, Object, Note (paginated)
- (PRO) Bulk: checkbox column + bulk Dismiss/Remove (reports), bulk Warn/Suspend (users, duration+reason)

### Auto-Moderation (PRO)
- Rules (moderation:rules): list (Name/Type/Priority/Enabled/Actions); add/edit (name, rule_type keyword_block|link_block|rate_limit|threshold_remove, priority, type-specific config: keywords+action / domains+action / max_posts+window+action / report_threshold+auto_remove)
- AI moderation (moderation:ai): buddynextpro_ai_classifier_provider (select openai|anthropic|local|disabled), _key (password), _model (text), _threshold (range 0–1 step .05, default .8); test bench (text, url, Classify → verdict table)

## Monetization (PRO)
### Plans / tiers (monetization:tiers) — list + editor
- List: Tier Name, Price, Status (toggle), Subscribers, Actions (Edit/Delete/View subscribers)
- Editor: name, description, slug, status (toggle), price (number), currency (select), billing_cycle (monthly/yearly/one_time), trial_days (number), max_members (number 0=unlimited), entitlements (multi-toggle grid)

### Subscriptions (monetization:subscriptions) — list
- Columns: Subscriber, Tier, Status (active/trialing/past_due/cancelled), Started, Expires, Actions (View/Revoke/Extend)
- Details: avatar+name, tier, status, period start/end, payment method, next billing, Revoke

### Stripe (monetization:stripe)
- buddynextpro_stripe_mode — Mode — readonly (derived test/live)
- buddynextpro_stripe_publishable_key — Publishable key — text(masked)
- buddynextpro_stripe_secret_key — Secret key — password(masked)
- buddynextpro_stripe_webhook_secret — Webhook secret — password(masked)
- Webhook endpoint URL — readonly copy; Test connection (button)
- (Paywall, if present): buddynextpro_paywall_enabled (toggle), _message (textarea), _cta_text (text), _cta_link (text)

### License (settings:license)
- buddynext-pro_license_key — License key — password
- buddynext-pro_license_key_allow_tracking — Allow tracking — toggle
- Activate/Deactivate (buttons); status badge (Active/Inactive/Expired); activation count, expiry, upgrade link
