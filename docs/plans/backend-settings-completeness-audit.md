# BuddyNext Backend Settings — Completeness Audit

**Date:** 2026-06-17
**Scope:** Every BuddyNext (Free + Pro) backend settings / admin screen.
**Method:** Read-only code + DB analysis. Per-area auditors scored each control against the completeness rubric (A option-wiring, B actions-wired, C lists-at-scale, D states, E three-entry-points, F stubs/placeholders, G enforcement-parity).
**Purpose:** Find "half-cooked" settings — screens that look present in the admin UI but are not wired end-to-end.

---

## 1. Executive Summary

### Status counts (17 audited areas)

| Status | Count | Areas |
|---|---|---|
| **half-cooked** | 7 | navigation, email-and-templates, roles-registration-privacy, features-registry, pro-analytics, pro-campaigns, pro-labels, pro-monetization |
| **minor-gaps** | 9 | settings-options-wiring, members, spaces, moderation-free, insights, platform-integrations-tools-webhooks, pro-reactions, pro-realtime-push, pro-automod |
| **fully-wired** | 0 | — |

> Note: 8 areas carry a `half-cooked` headline above (pro-monetization, pro-campaigns, pro-analytics, pro-labels, roles-registration-privacy, features-registry, navigation, email-and-templates). The "minor-gaps" bucket still ships at least one critical or high issue in several cases (e.g. the Spaces save-wipe is critical).

### Severity totals across all areas

| Severity | Count |
|---|---|
| Critical | 9 |
| High | 17 |
| Medium | 14 |
| Low | 25 |

### Worst offenders (tackle first)

1. **pro-monetization** — the entire tier/paywall/gating feature has **no admin or REST way to set a space's `required_ability`**; gating, checkout, and paywall can never be activated through the UI. No manual (non-Stripe) subscription path. Stripe plumbing is fully wired but unreachable.
2. **pro-campaigns** — broadcast send cron interval is **never registered at runtime**, so "Send Now" reports success while nothing is ever delivered; segment selector silently sends to **zero recipients for 4 of 6 options**.
3. **pro-analytics** — `space_health()` / `engagement_rate()` are **permanently 0** due to an event `target_type` contract mismatch; two CSV export branches are dead code; overview chart is a 360-query N+1 at 1-year window.
4. **features-registry** — 4 bridge toggles (gamification, jetonomy, wpmediaverse, career_board) are **dead switches**: saved but never read; bridges load on plugin-presence only.
5. **roles-registration-privacy** — the Roles & Capabilities matrix is largely a **dead-toggle screen**: only ~5 of ~17 capabilities are actually enforced at runtime.
6. **email-and-templates** — **9 actively-sent templates have no editor entry**; `preview_text` is saved but never sent; advertised token vocabulary does not match the renderer.
7. **navigation** — visibility / capability / login-required / guest-label per-tab options are **saved but never applied**; mobile scope is mis-mapped to a fixed bar.
8. **settings-options-wiring (Spaces tab)** — option-group mismatch **silently wipes 4 fields on save** (confirmed absent in DB).
9. **pro-labels** — label **assignment is REST-only**: no admin or frontend way to grant a label to a member, stranding 3 downstream consumers (broadcast segment, search filter, profile chips).

---

## 2. Prioritized Issue Table

### CRITICAL

| Area | Rubric | Issue | Evidence | Suggested fix |
|---|---|---|---|---|
| settings-options-wiring (Spaces tab) | A | 4 of 7 Spaces-tab fields are not in `TAB_OPTIONS['spaces']`, so options.php whitelists the wrong group and silently rejects them on save. Affected: `buddynext_space_max_per_member`, `buddynext_space_allow_sub`, `buddynext_space_default_type`, `buddynext_space_default_category`. Confirmed absent in DB despite the tab rendering them. Read side is fine (SpaceService consumes all 4) — pure save break = dead form. | `includes/Admin/Settings.php:651-655` vs render at `:1528-1610`; fallback `:711-719` | Add the 4 option names to the `'spaces'` array in `TAB_OPTIONS`. |
| roles-registration-privacy | A | Roles & Capabilities matrix is mostly a dead-toggle screen. Wiring (`bn_role_map_overrides` → PermissionService) is real, but a repo-wide grep finds only 5 capability slugs actually checked at runtime (moderation review-queue/issue-strike/suspend-user, spaces moderate/manage-settings). Every other dropdown changes the option with zero consumers. | `RolesTab.php:43-73` CATALOG; grep of `buddynext_can(` yields only those 5 | Either gate the controllers on these caps (PostController, FollowController, SpaceController, etc.) or remove the unenforced rows from the catalog. Reconcile catalog ↔ enforcement. |
| roles-registration-privacy | A | 11 specific dead toggles saved to `bn_role_map_overrides` but never read: Create/Schedule/Pin/Delete-any post, Create/Join/Post-in spaces, Follow members, Send connection requests, Report content, Edit-any profile. Controllers gate on `is_user_logged_in()` only. | `PostController.php:112`; `SpaceController.php:611`; grep `buddynext-connections/follow\|connect` → 0 consumers | Same as above — wire or remove. The author already did this correctly for manage-settings/delete (`RolesTab.php:50-53`). |
| features-registry | A | 4 dead bridge toggles: gamification, jetonomy, wpmediaverse, career_board rendered as live switches but no code calls `is_enabled()`/`buddynext_feature_enabled()` for them. Bridges load unconditionally on `buddynext_load_bridges`, guarded only by `class_exists`/`function_exists` of the external plugin. Toggling does nothing either way. | `FeatureRegistry.php:89-92`; `Plugin.php:393-407`; Bridges `init()` methods; option `buddynext_features` | Gate each bridge's `init()` on `service('features')->is_enabled($slug)` OR remove the toggles and render them as read-only status (present/absent). |
| email-and-templates | F | Catalogue/installer slug drift: Installer seeds 26 templates but EmailEditor catalogue exposes only 18. 9 actively-sent templates have NO editor entry (uneditable, undisableable): appeal_resolved, daily_digest, weekly_digest, member_suspended, new_report, strike_warning, unsuspension_confirmation, email_change_confirm, moderation digest. Proven sent via CronService/ModerationListener/VerificationListener. | `EmailEditor.php:96-257` vs `Installer.php:194-351`; `CronService.php:61`; `ModerationListener.php:107` | Add the 9 missing template entries to `get_catalogue()` with correct token lists, or remove the seeds if a template is unused. |
| pro-analytics | G | `space_health()` and `engagement_rate()` are broken by an event-type/`target_type` contract mismatch. Service counts `post.created`/`reaction.added`/`comment.created` WHERE `target_type='space'`, but the Collector records those with `target_type='post'` (DB confirms 0 rows with `target_type='space'`). Space posts always 0, engagement always ~0. Shipped via REST `/analytics/space-health`. | `AnalyticsService.php:310-320,386-408` vs `AnalyticsCollector.php:156-162,232-258` | Either record a `space_id`/space-scoped event on post/reaction/comment, or rewrite the queries to JOIN events to their object's owning space instead of filtering `target_type='space'`. |
| pro-campaigns | B | Broadcast send cron is dead at runtime. The `buddynextpro_five_minutes` interval is registered only inside `activate_cron()` (activation request); the normal boot `register()` never re-adds the `cron_schedules` filter. `wp_next_scheduled('buddynextpro_broadcast_send_pending')` returns false. `send_pending()` never runs — no broadcast email is ever delivered. | `BroadcastService.php:88` vs `:620`; `wp_next_scheduled(...)=false` | Move the `cron_schedules` filter registration into `register()` (boot path), not only `activate_cron()`. Add a self-heal that re-schedules on boot if missing. |
| pro-monetization | G | Entire tier/paywall/gating feature has NO admin or REST UI to set a space's `required_ability` — the field that activates gating. It is only ever read, never written by any code path. The product's own journey doc tells owners to run raw SQL. Owners configuring via admin can never produce a gated space; checkout/paywall/gating never fire. | `GatedSpacesIntegration.php:66`; `required_ability` never written anywhere | Add a tier-gating control to the Free space-settings template (or a Pro SpaceBrandAdmin-style injection) + a REST field so `required_ability` is settable. Wire all three entry points. |

### HIGH

| Area | Rubric | Issue | Evidence | Suggested fix |
|---|---|---|---|---|
| members | G | Suspension data-model split: admin `suspend_member()` writes BOTH `bn_suspended` usermeta AND the `bn_user_suspensions` table; moderation-queue `ModerationService::suspend_user()` writes only the table. Canonical reads use the table, but two front-end templates gate on the orphan usermeta. A queue-suspended member is mishandled by usermeta-keyed paths. | `Members.php:284,288` vs `ModerationService.php:793`; reads `FeedService.php:94`, `MemberDirectoryService.php:188` vs `templates/feed/bookmarks.php:143`, `single-post.php:95` | Pick the table as the single source of truth. Drop the usermeta write and switch the two templates to `ModerationService::is_suspended()`. |
| members | F | Profile-field type `file` is registered/selectable but unwired in admin Edit Member: `render_field()` has no `file` branch (renders text input), and `handle_save_member_profile()` reads only `$_POST`, never `$_FILES`. A File field is a text box that cannot accept a file. | `ProfileFieldsManager.php:134`; `MemberEditForm.php:528-545`; `Members.php:662-673` | Add a `file` branch (enctype + `$_FILES` handling via `media_handle_upload`) or remove `file` from the field-type matrix until supported. |
| spaces | A | Dead toggle: per-category "Show in the spaces directory" (`show_in_dir`) is fully saved + rendered as a column, but NO runtime path reads it. Directory query selects every category unconditionally. Toggling off does nothing. | `templates/spaces/directory.php:58-60`; `SpaceService.php:117-126,138`; toggle at `Admin/Spaces.php:494-499` | Add `WHERE show_in_dir = 1` to the directory categories query and to `get_categories()`/`get_categories_full()`. |
| spaces | G | Write-only data: a category's `icon_svg` is sanitized + saved + previewed in admin, but the front-end directory renders icons from a hardcoded slug→Lucide map (`bn_space_category_icon()`, 11 fixed slugs, `home` fallback). Admin-entered SVG never shown to members. | `templates/spaces/directory.php:225-241,266`; saved at `Admin/Spaces.php:534` | Render `$cat->icon_svg` when present, falling back to the Lucide map only when empty. |
| spaces | E | Three-entry-point gap: the REST category controller omits `icon_svg` entirely (not in create/update args, not in payload, not in PUT whitelist). Category icon is reachable only via the admin form. | `SpaceCategoryController.php:45-75,88-121,184,246-259` | Add `icon_svg` to REST create/update args, the PUT field whitelist, and `payload()`. |
| moderation-free | C | Reports tab does not paginate: `render_reports()` calls `get_queue(['per_page'=>50])` with no page arg and renders no prev/next, while `get_queue()` fully supports page/offset + COUNT(*). Queue permanently truncated at 50 groups. | `ModerationQueue.php:76,82`; `ModerationService.php:641-643` | Pass `page` from `$_GET`, read `$queue['total']`, render prev/next nav. |
| moderation-free | C | Reports tab has no filter or sort despite `get_queue()` natively supporting object_type/reason/space_ids filters. A 2000-row queue is unusable without filter-by-reason/type. | `ModerationService.php:644-684` unused by `ModerationQueue.php:73-108` | Surface reason + type filter dropdowns (REASON_LABELS already exists) and wire them to `get_queue()` args. |
| navigation | A | Per-tab `guest_label` is saved + rendered as an input but never read on the front end (zero consumers). Write-only dead option. | `NavManager.php:1280,1312,1099-1112`; no reader in `NavOverrides.php` | Consume `guest_label` in `NavOverrides` when the viewer is a guest, or remove the field. |
| navigation | G | Per-tab `login_required` toggle ("Redirect guests to login") saved + shown but no front-end path reads it; `NavOverrides` applies only hidden/label/order. | `NavManager.php:1279`; empty grep in `NavOverrides.php` | Add a guest-redirect guard in the rail/profile/space render paths keyed on `login_required`, or remove the toggle. |
| navigation | G | Per-tab Visibility (all/logged_in/admins/cap) + Required Capability saved + rendered, but `NavOverrides` honors capability only for CUSTOM tabs; CORE tabs ignore visibility/capability. "Admins only" hub still shows to all. | `NavOverrides.php:131-135` | Apply visibility/capability checks to core tabs in `apply_rail`/`apply_profile_tabs`/`apply_space_tabs`/`apply_mobile_items`. |
| navigation | F | Mobile scope mis-planned. Admin lists 5 slugs (feed, explore, spaces, notifications, messages) but the rendered bottom bar is a fixed strip (feed, spaces, create, notifications, profile). `explore`/`messages` rows can never appear; `create`/`profile` are uncontrollable. | `NavManager.php:496-539` vs `templates/partials/nav.php:131-176` | Reconcile the admin mobile-scope slug list with the actual rendered keys (drive the bar from the override set). |
| email-and-templates | A | `preview_text` is write-only/dead at send: edited, saved, seeded, re-displayed — but EmailSender never reads it. The "shown in inbox before opening" promise is not delivered. | `EmailEditor.php:859-871` vs `EmailSender.php:153-166,299-360` | Emit a preheader `<span>` from `preview_text` in `wrap_email_html()`, or remove the field. |
| email-and-templates | G | Token-vocabulary mismatch: editor advertises tokens like `{{follower_name}}`, `{{reactor_name}}`, `{{profile_url}}`, etc., but `render()` only guarantees a 7-token set; others resolve only if the listener passed that exact key. Admin who uses an advertised token ships literal `{{follower_name}}` text. | `EmailEditor.php:102-254` vs `EmailSender.php:377-393` | Make the per-template token list authoritative — have each listener populate every advertised token, or trim the advertised list to what `render()` guarantees. |
| platform-integrations-tools-webhooks | B | Auto-deactivated webhooks have no re-enable path. After 3 failures `is_active=0`; the row shows "Disabled" but exposes only Send-test/View-log/Remove. No PUT/PATCH route, no `is_active=1` write anywhere except the initial INSERT. Owner must delete + re-create (losing secret + log). | `OutboundWebhookService.php:481-510`; `Settings.php:2160-2178` | Add an Enable/Re-activate action (admin-post + REST PATCH) that sets `is_active=1` and resets the failure counter. |
| platform-integrations-tools-webhooks | G | Webhook "Shared Secret" field copy claims it signs outgoing webhooks, but the global `buddynext_webhook_secret` is consumed only by inbound `AccessWebhookController`. Outbound deliveries sign with the per-endpoint secret. Field does not sign any outbound webhook. | `Settings.php:2051` vs `OutboundWebhookService.php:418`, `AccessWebhookController.php:297` | Fix the copy to "verify inbound access requests" only; surface the per-endpoint secret separately (see medium below). |
| roles-registration-privacy | G | Space capabilities ("Post in spaces", "Join spaces") exposed as global min-role dropdowns, but posting is enforced by SpacePostGuard (per-space `who_can_post`) and joining by SpaceMemberService (`buddynext_can_join_space` + join_method) — neither consults the RolesTab caps. | `SpacePostGuard.php:67`; `SpaceMemberService.php:86,197`; `RolesTab.php:50-59` | Remove create/post/join from the catalog (as already done for manage-settings/delete) or route the guards through the cap check. |
| features-registry | G | Misleading UI: `render_tab_features()` prints "changes apply immediately on save" and renders the 4 dead bridge toggles identically to the 9 working ones. Owner gets no indication the switch is inert. Registration tab already proves the codebase knows how to hide inert sub-toggles. | `Settings.php:1069-1071,1118-1132` vs `:1169-1192` | Make the bridge toggles functional (see critical) or render them disabled with a "requires plugin X" status. |
| pro-analytics | B | CSV export `content` and `members` branches are dead code: the only Export button hardcodes `view=overview` and signs the overview nonce; the other nonces never validate. No template/JS posts `view=content`/`view=members`. | `AnalyticsAdmin.php:154-161,917-953` | Render per-view export buttons that post the matching `view` + nonce, or remove the dead switch branches. |
| pro-analytics | B | Export button is context-blind: rendered once for the whole section, always exports the overview member_growth(90) set. On Cohorts/Funnel/Profile-Views tabs it silently downloads registration counts, not the visible data. | `AnalyticsAdmin.php:933-953,156` | Make the export action read the current view and export the corresponding dataset. |
| pro-analytics | C | Overview daily-activity chart is an N+1 bomb: `build_daily_dau_series()` calls `dau()` once per (point × bucket) — up to 360 separate aggregate queries at window=365. No grouped GROUP BY, no cache. | `AnalyticsAdmin.php:797-817` | Replace with a single `GROUP BY DATE(...)` query (or cached series). |
| pro-campaigns | G | Broadcast segment selector non-functional for 4 of 6 options. Form posts only `segment_type`; `by_space`/`by_tag`/`by_member_label` need space_ids/tags/label_ids the form never collects → 0 recipients, no error. `by_join_date` has no date inputs. Only `all_users` + `by_activity_level` work. | `BroadcastAdmin.php:147-148,371-382`; `SegmentService.php:96-98,131-133,244-246` | Add the missing segment inputs to the form and pass them through, or hide the segment types the UI can't configure. |
| pro-labels | E | Label ASSIGNMENT has no admin and no frontend entry point — REST-only. The Member Labels screen does label CRUD only; nowhere (Users list, profile, bulk action) attaches a label to a user. Feature is unusable without hand-written REST calls. | `MemberLabelsAdmin.php` (CRUD only); `LabelsController.php:155-170`; no `manage_users`/`user_row_actions` in Pro | Add a per-user assign UI via `show_user_profile`/`edit_user_profile` (pattern already used in `Email/BroadcastUnsubscribe.php:49-52`) + a Users-list column/bulk action. |
| pro-labels | B | Admin "Members" column per label is a dead-end metric — not a link, no companion screen to view/manage members of a label, and always 0 with no assignment UI. | `MemberLabelsAdmin.php:403,418`; DB assignments COUNT=0 | Link the count to a filtered member view once assignment UI exists. |
| pro-labels | G | Three downstream consumers depend on assignment data no UI can create: broadcast `by_member_label` segment, advanced-search `member_label` filter, profile/byline chips + REST `profile.labels`. Render seams are correctly fired; only the assignment half is missing. | `SegmentService.php:54`; `AdvancedSearchFilters.php:217`; `ProfileLabelInjector.php:76-80` | Resolved by adding assignment UI (above). |
| pro-monetization | B | No manual / non-Stripe subscription path. Subscriptions admin only revokes; `create_subscription()` reachable only from the Stripe webhook / sync. REST is read-only. A manual/free community has zero UI to put a member into a tier. | `MembershipAdmin.php:338`; `SubscriptionsController.php` (GET-only) | Add an admin "Grant tier to member" action + a REST create route calling `SubscriptionService::create_subscription(source='manual')`. |
| pro-monetization | A | Saved-but-never-read: `buddynextpro_tier_stripe_product_id_{slug}` is written and only re-read to repopulate its own field; never consumed by Checkout/Webhook/Paywall (checkout uses price ID only). Gives a false sense the product link matters. | `MembershipAdmin.php:53,291,846` | Remove the field, or mark it read-only "bookkeeping" so it doesn't imply behavior. |
| pro-realtime-push | A | Orphan option `buddynextpro_soketi_cluster` is read-but-never-saved: shipped to the pusher-js client but RealtimeAdmin's SETTINGS_MAP has no `cluster` field and no save handler. Permanently `mt1`; any non-mt1 Pusher cluster can never be set in-product. | `RealtimeAssets.php:157` vs `RealtimeAdmin.php:46-52` | Add a `cluster` field to SETTINGS_MAP + save handler. |
| pro-automod | A | Dead toggle: threshold_remove rule's "Suspend" action is never enforced. Pro emits an `action=suspend` descriptor but Free's auto-action switch has only `remove`/`warn` — no `case 'suspend'`. Suspend rule = zero behavior. | `ModerationService.php:198-223`; `RulesService.php:335-348`; `ModRulesAdmin.php:567` | Add `case 'suspend'` to Free's switch (calling `suspend()` with the descriptor user_id/duration), or remove Suspend from the threshold_remove form. |

### MEDIUM

| Area | Rubric | Issue | Evidence | Suggested fix |
|---|---|---|---|---|
| members | C | Members directory has no sort control. `list_members()` accepts orderby/order but `render_members_tab()` exposes only search + role + status. | `Members.php:144-152` vs `:920-947` | Add a sort-by select (name/recency/last-active/last-login). |
| members | C | Unbounded pagination UI: members table prints one link per page (`for $i=1..$pages`), no windowing. 100 links at 2k members, 2500 at 50k. Same anti-pattern in Spaces.php. | `Members.php:1085`; `Spaces.php:354` | Add first/last/ellipsis windowing (shared helper). |
| spaces | G | Custom space types (via `buddynext_space_types` filter) are second-class in admin Directory: stat grid + visibility filter hardcoded to Open/Private/Secret; `get_type_counts()` only buckets those 3; per-row badge prints `ucfirst($type_key)` not the registry label. | `Admin/Spaces.php:185-202,232-236,824-849,305` | Drive the stat tiles/filter/badge from `SpaceTypeRegistry`. |
| insights | A | Posts metric counts every `bn_posts` row with no status filter (ENUM includes draft/pending/scheduled/deleted). Pro writes scheduled posts, so total + "+N this week" over-report vs what members see. | `Insights.php:149-150`; `Installer.php:676` | Add `WHERE status='published'`. |
| insights | A | Comments metric is COUNT(*) with no `is_deleted` filter; delete() is soft. Diverges from every visible count once anyone deletes a comment. | `Insights.php:152`; `CommentService.php:362` | Add `WHERE is_deleted=0`. |
| insights | A | Spaces metric has no `is_archived` filter, while SpaceService and member surfaces filter `is_archived=0`. Archived spaces over-report. | `Insights.php:151`; `SpaceService.php:177,488` | Add `WHERE is_archived=0`. |
| email-and-templates | F | Send-test renders 11 advertised tokens literally (map omits badge_name/new_level/discussion_title/etc.) and bypasses EmailSender entirely (own wp_mail, no From/Reply-To/footer/brand shell), so the test looks nothing like a real send. | `EmailEditor.php:403-446` | Route send-test through `EmailSender` and complete the placeholder map. |
| email-and-templates | E | No REST entry point for email templates (backend + runtime only). For a 26-row editable feature this is the missing third entry point with no documented exception. | manifest (no route); `EmailEditor.php` admin-post only | Add a REST controller for template read/write, or document the exception in the manifest. |
| platform-integrations-tools-webhooks | F | Per-endpoint signing secret is generated + returned in the 201 body but the JS add-handler discards `res.body.secret` and reloads. Receiving server can never verify `X-BuddyNext-Signature` for UI-created endpoints. | `OutboundWebhookController.php:193-199` vs `settings.js:117-122` | Surface the secret once on creation (copy-to-clipboard modal) + a "reveal/rotate secret" action. |
| pro-analytics | C | Cohort retention uses an unbounded `IN()` list — imploding every user id per (cohort × offset) cell; thousands-element IN lists, no cap/JOIN. | `CohortService.php:222-247` | Replace IN(csv) with a JOIN against a temp/derived set; cap cohort size. |
| pro-analytics | D | Cohorts/Funnel/Profile-Views have no time-window/parameter UI (hardcoded ranges); `render_window_filter` only drawn for overview + profile-views. Read-but-never-settable params. | `AnalyticsAdmin.php:273-275,325-328` | Draw the window filter on those views and pass params through. |
| pro-analytics | D | Overview/cohort/funnel views have no error state if a `$wpdb` query returns false (only empty-row states). | `AnalyticsAdmin.php:751-758,421-427` | Add an error branch for failed queries. |
| pro-analytics | G | Funnel does not model a real funnel — each step counts an independent population, so conversion/drop-off shown to the owner is misleading rather than a cohorted activation funnel. | `FunnelService.php:77-103,171-209` | Re-implement as a cohorted funnel (signups → those who then posted → etc.) or relabel as "activity by stage". |
| pro-campaigns | C | Campaign/sequence/scheduled-post lists have no pagination UI or COUNT(*): campaigns capped at newest 20 with no nav, sequences + scheduled posts run unbounded `SELECT *`. | `BroadcastAdmin.php:269`; `DripService.php:243`; `ScheduledPostsAdmin.php:291-298` | Add pagination + count + filter/sort to all three. |
| pro-campaigns | A | Drip step reorder/delete bypass the service layer with raw `$wpdb->update` inside the admin handler — architecture violation, and REST/other entry points cannot reorder/delete steps. | `DripAdmin.php:266-277,310-324` | Add `update_step()`/`delete_step()`/`reorder_steps()` to DripService and call them from both admin + REST. |
| pro-campaigns | B | Broadcast "Send Now" has no live-delivery path and no cron fallback (relies on the dead cron). Success notice shown while nothing sends; no manual "process queue now" button. | `BroadcastAdmin.php:182-211`; `BroadcastService.php:342-395` | After fixing the cron, add a manual "process queue now" action (like ScheduledPostsAdmin) as a recovery path. |
| pro-monetization | C | Subscriptions admin list hardcodes `LIMIT 200` with no OFFSET, no pagination UI, no COUNT(*). 2000+ subs → only newest 200 visible. | `MembershipAdmin.php:593,603` | Add pagination + COUNT(*). |
| pro-monetization | E | Three-entry-point parity broken: subscriptions have no write entry point except the Stripe webhook; tier-to-space gating has 0/3 entry points (raw SQL only). | `required_ability` 0/3; subscription create webhook-only | Add admin + REST write paths (ties to the two criticals above). |
| pro-realtime-push | G | PushPrefsAdmin (19-toggle grid under site Settings, `manage_options`) writes ONLY the current admin's usermeta — a near-duplicate of the member-facing WebPush panel. Title/placement imply a global/member control. | `PushPrefsAdmin.php:14-16,211-222`; `WebPushAssets.php:301-388` | Either make it set site defaults / manage members, or relabel + move it to a personal-prefs context. |

### LOW (condensed)

| Area | Rubric | Issue | Evidence |
|---|---|---|---|
| settings-options-wiring | G | Notifications-defaults override only the `on_site` channel; `email_freq` untouched, but the label reads as a blanket default. | `NotificationPrefService.php:80-92`; `Settings.php:1745-1785` |
| settings-options-wiring | G | `buddynext_notif_default_space_join` maps to `bn.space_join_requested` (join *request*), not an actual member-joined event; open spaces unaffected. Label drift. | `NotificationPrefService.php:50`; `Settings.php:1781-1784` |
| members | F | Dead duplicate `Members::export_members_csv()` (unbounded `get_users`) — real export is the streamed `MemberExport` class; only callers are tests. | `Members.php:376-401`; `MemberExport.php:25,64` |
| members | F | Field-type vocabulary drift: registry declares boolean/color/multiselect/file; renderer has checkbox/toggle/rating/social branches; boolean/color/file fall through to text. | `ProfileFieldsManager.php:105-140` vs `MemberEditForm.php:485-507` |
| members | G | Suspension is content-visibility only, not a login gate (only `bn_pending_approval` blocks auth). Likely by design; "Suspended" badge may over-imply. | `Plugin.php:263`; `Members.php:1060` |
| spaces | C | Admin Spaces list has no sort/category-filter UI; `bn_spaces.type` has no index; type GROUP BY full-scans each page load. | `Admin/Spaces.php:655-735,824-849`; Installer KEYs |
| spaces | D | Category save error flash is generic — distinct WP_Error codes (409/422/500) collapsed to one message. | `Admin/Spaces.php:540-552`; codes at `SpaceCategoryService.php:123/143/159/257/263` |
| moderation-free | C | Suspensions/Appeals tabs hard-capped (100/500) with no pagination; `get_pending_appeals()` offset param unused. | `ModerationQueue.php:202,248`; `ModerationService.php:1016,1060` |
| moderation-free | C | N+1 in report row loop (get_userdata + object_author + object_view_url per row, ~150 queries/50 rows). | `ModerationQueue.php:121,552-565,577-596` |
| moderation-free | G | Banned-words/blocked-domain/rate-limit enforced on POST creation but NOT on COMMENT creation. | `CommentService.php:68` vs `PostService.php:134` |
| moderation-free | D | Admin moderation actions always redirect `bn_done=1`; WP_Error returns ignored → "Done." on failed/no-op actions; no concurrency feedback. | `ModerationQueue.php:308-323,527-532` |
| moderation-free | A | Reason filter dropdown copy exists but no UI to filter by it; discriminator is render-only. | `ModerationQueue.php:41-49,149-155` |
| insights | G | Members/active-30d count all wp_users, no suspended/shadow-banned exclusion (rest of plugin excludes them). Defensible as "total accounts" but inconsistent. | `Insights.php:140-147` |
| insights | D | No explicit empty/zero state on Insights tab/widget; new install shows a wall of 0s + flat strip. Cosmetic. | `Insights.php:230-243,212-222` |
| insights | E | No REST entry for Insights bundle — acceptable documented exception (deep analytics is Pro). | `Insights.php:1-16` |
| navigation | G | Mobile reorder silently dropped: order saved but `apply_mobile_items` deliberately doesn't apply it, while the admin note says "Drag to reorder." | `NavOverrides.php:274-301`; `NavManager.php:1141` |
| navigation | A | Main-nav `auth` tab override orphaned — rail has no `auth` key so `apply_rail` never matches. | `templates/shell/rail.php:66-111` vs `NavManager.php:1493` |
| navigation | A | Rail `explore` item has no admin row (intentional) — owner can't hide/relabel/reorder a visible nav item. | `rail.php:74` vs `NavManager.php:1446` |
| navigation | A | `people` slug round-trip — confirmed consistent (PageRouter reads it); flagged only to verify. | `PageRouter.php:1345,1733` |
| email-and-templates | A | `{{liker_name}}` test token unused by any body (real templates use `{{reactor_name}}`) — leftover/renamed token. | `EmailEditor.php:408` vs `:134` |
| email-and-templates | C | Editor rail calls `get_saved()` per template in the render loop — N+1 of ~26 single-row SELECTs. Fine at fixed size today. | `EmailEditor.php:690-695` |
| platform-integrations-tools-webhooks | C | Tools "Repair counters" load unbounded result sets + per-row recounts (N+1), single long admin-post request, no batching/Action-Scheduler. | `ToolsTab.php:185-199,190` |
| platform-integrations-tools-webhooks | A | Export/Import sweeps `buddynext_%`/`bn_%` options including the webhook secret + EDD license key into a plaintext JSON export (only `bn_demo_manifest` skipped). | `ToolsTab.php:302-322` |
| platform-integrations-tools-webhooks | E | IntegrationHub "Available" cards ship `url=''` for built-in addons → shows raw plugin_file path instead of a CTA; redundant/weaker duplicate of the CompanionRegistry list. | `IntegrationHub.php:360-373,445-488` |
| pro-reactions | A | Declared 20-total cap has NO write-time enforcement — extra reactions persist but are silently dropped from the picker via `array_slice` at display, with no admin feedback. | `CustomReactionsService.php:120-128,189,199` vs `CustomReactionsAdmin.php:306-309` |
| pro-reactions | F | Dead code: `validate_slug()`/`validate_color()` never called; vestigial color field/swatch from pre-Fluent design. | `CustomReactionsService.php:261,312` |
| pro-reactions | E | No REST endpoint to manage the custom-reaction set (admin-post only); undocumented exception. | `buddynext-pro/audit/manifest.json:475,479` |
| pro-reactions | G | Admin copy hardcoded "6 defaults / 20 max" regardless of the owner's enabled-reaction subset. | `CustomReactionsAdmin.php:306-309` vs `ReactionService.php:175-192` |
| pro-realtime-push | C | `list_tokens()` + push fan-out use unbounded `SELECT` per user (realistically <10 rows); no per-user token cap at registration. | `PushController.php:170-179,244-281` |
| pro-realtime-push | F | WPMediaVerse fires `mvs_message_sent` with `message_id=0` on conversation creation; dispatcher forwards a non-existent message id (a valid second event follows). | `RealtimeDispatcher.php:204-221` |
| pro-automod | G | Free's auto-action docblock advertises remove/warn/suspend but implements only remove+warn; Pro never emits warn → contract drift. | `ModerationService.php:180-182` vs `:198-223` |
| pro-automod | C | Moderation Rules list unbounded `SELECT *` + no pagination UI (small counts expected). | `RulesService.php:209-232`; `ModRulesAdmin.php:306-343` |
| pro-automod | D | BulkModAdmin collapses per-ID failure reasons into a single "N failed" count; owner can't tell which IDs failed. | `BulkModAdmin.php:386-389,450-460` |
| pro-labels | C | Admin label list unbounded read + per-row N+1 member count (also in REST list). Bounded in practice. | `LabelService.php:210-226`; `MemberLabelsAdmin.php:418` |
| pro-labels | A | `assigned_by` column written but never displayed anywhere. Minor write-only column. | `LabelAssignmentService.php:69-74` |
| pro-monetization | D | Gated-space paywall CTA relies on unspecified store JS; no loading/error state, can fall back to an empty href (dead "Become a Member" button). | `PaywallIntegration.php:197-208` |
| features-registry | G | FeatureRegistry docblock claims "source of truth / is_enabled() before binding"; in reality only sidebar + webhooks gate at bind time, Pro never references the Free registry. Document the exception or unify. | `FeatureRegistry.php:11-14`; Pro `Plugin.php:530,553` |
| features-registry | F | Catalog grouping: `spaces` (default_on) sits inside the "MANDATORY — always on" comment block; reads as mandatory to a future dev. | `FeatureRegistry.php:69-78` |
| pro-analytics | E | space_health not surfaced on any frontend space screen; cohorts/funnel admin-only with no REST — acceptable but undocumented exception. | `AnalyticsController.php:95-178` |
| pro-analytics | A | No options in the analytics area — zero admin configurability (retention/funnel steps only via PHP filter). N/A for orphan checks, noted for completeness. | grep get_option/update_option = 0 |
| pro-campaigns | D | Broadcast "Send Test" always redirects `test_sent=1` regardless of wp_mail result; recipients-view has no "not dispatched yet" empty state. | `BroadcastAdmin.php:218-253,407-461` |
| pro-campaigns | E | Drip enrollments have no admin/frontend visibility — owner can't audit who's enrolled or stuck mid-sequence. | `DripEnrollmentService.php:100-140`; `DripAdmin.php` |

---

## 3. Truly Half-Cooked Screens — Owner Shortlist

These look finished in the admin but are not wired end-to-end. Fix these first; each is a screen an owner can configure and reasonably expect to work, but it does nothing (or the wrong thing).

1. **Monetization → Paywall / Tier Gating** — you can build tiers + connect Stripe, but there is **no UI anywhere to gate a space** (`required_ability` is never written). Gating/checkout/paywall can never turn on without raw SQL. Also no manual subscription path for non-Stripe sites.
2. **Campaigns → Broadcast** — **"Send Now" never delivers**: the 5-minute send cron interval isn't registered at runtime. And **4 of 6 segments send to zero recipients** because the form never collects their parameters. The owner sees "queued for sending" and nothing happens.
3. **Settings → Features (bridge toggles)** — gamification / jetonomy / wpmediaverse / career_board switches are **inert**; bridges load on plugin presence regardless of the toggle. Turning them off does nothing.
4. **Settings → Roles & Capabilities** — a full permissions matrix where **only ~5 of ~17 capabilities are actually enforced**. Setting "Create posts = Moderators" still lets everyone post.
5. **Settings → Email Templates** — **9 of the 26 sent emails have no editor row** (uneditable/undisableable), `preview_text` is saved but never sent, and the advertised merge tokens don't match what the renderer resolves.
6. **Settings → Navigation** — per-tab **Visibility, Required Capability, Login-required, and Guest-label are saved but never applied** to core tabs; mobile reorder is dropped; the mobile scope lists tabs the bar can't render.
7. **Settings → Spaces tab** — the **New-space-defaults + per-member/sub-space-limit section silently fails to save** (option-group mismatch; 4 fields confirmed absent in DB).
8. **Pro → Member Labels** — you can define labels but there is **no admin or frontend way to assign one to a member** (REST-only), so the label-count column, broadcast label segment, search filter, and profile chips are all permanently empty.
9. **Pro → Analytics (Space Health / Engagement)** — these views **always read 0** due to an event `target_type` mismatch, and the per-tab CSV "Export" silently exports the wrong (overview) dataset.

---

## 4. De-duplication & Dropped Items

**Overlapping/related findings consolidated:**
- The **unbounded pagination-UI / no-sort / no-filter** pattern recurs across members, spaces, moderation, campaigns, monetization, labels, automod. Treat as one shared fix: a windowed pagination helper + a standard list-controls (sort/filter) partial reused by every admin list. Each instance is still listed for traceability but they share a root cause.
- **pro-labels** issues B + G both reduce to the single critical E (no assignment UI). Fixing assignment resolves the dead member-count column and all three downstream consumers.
- **pro-monetization** E (entry-point parity) restates criticals G + B; kept as a separate row only to flag the REST gap explicitly.
- **pro-campaigns** B ("Send Now" no delivery) is a consequence of the critical cron finding; kept for the recovery-path recommendation (manual "process queue now").
- **roles-registration-privacy**: Registration + Privacy are fully wired end-to-end — only the Roles matrix is in scope as defective. Confirmed fine, not flagged.

**Confirmed fine / dropped (not defects):**
- Insights metrics are real queries with cache + invalidation (no placeholder data) — only the missing soft-delete/archive/status filters are flagged.
- Moderation engine options: all 12 are saved AND read AND enforced — only the queue UI (scale) is flagged.
- Email identity options (From/Reply-To/footer) are fully wired.
- Realtime/Push are wired end-to-end except the one orphan `soketi_cluster` option and the duplicate admin push-prefs screen.
- pro-reactions save → filter → Fluent SVG picker is wired; only the write-time cap + dead validators are flagged.
- pro-automod AI + safeguard/keyword/link/rate rules are wired end-to-end; only the threshold "Suspend" action is dead.
