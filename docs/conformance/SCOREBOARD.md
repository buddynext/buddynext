# BuddyNext — Journey Usability Scoreboard

_Generated 2026-05-31 · 50 units (6 contracts + 44 features) · static journey-conformance pass against locked specs._

**Verdicts:** ✅ usable-leave-as-is · 🟢 usable-minor-polish · 🟡 partial-needs-wiring · 🔴 broken-journey · ⚪ unverifiable.
**Confidence:** `confirmed-in-code` = proven · `needs-live-verification` = confirm in browser before trusting.

**Cooked status:** 22 leave-as-is + 10 minor-polish = **32 already usable**. 15 need wiring, 3 broken.

**Gaps:** 136 total — critical 19, high 36, medium 25, low 56. Confidence: confirmed 125 / live-verify 11.

## Work queue — first break per incomplete journey

| Verdict | Feature | Repo | First break | Steps |
|---|---|---|---|---|
| 🟢 | REST/App contract | contract | REST-INVENTORY.md is stale/incomplete — it claims to be the full surface but omits 5+ live Free routes and the entire ~46-route buddynext-pro/v1 name… | 2 |
| 🟢 | Scale contract | contract | includes/Search/SearchController.php:56 — /search `page` arg is unbounded (absint, no maximum) and SearchService.php:371 paginates with LIMIT %d OFFS… | 3 |
| 🟢 | Design system & dark mode | contract | includes/Admin/NavManager.php:585 — a '⚙' gear emoji is emitted inside an echoed (translatable) admin help string, violating the no-emoji-in-markup r… | 2 |
| 🟢 | Notifications | free | none — journey complete for the core follow happy path. The earliest broken link in the broader feature is the space-invite inline Accept/Decline but… | 1 |
| 🟢 | Email System | free | none — journey complete. The seeded bn.new_follower template uses only tokens the runtime resolver supports, so the immediate-email and digest journe… | 2 |
| 🟢 | Outbound Webhooks | free | includes/Admin/Settings.php:1293 — the event-subscription checkbox catalogue exposes only 5 events (one of them phantom) of the 13 the spec contracts… | 3 |
| 🟢 | PWA | free | includes/PWA/PwaService.php:120-131 — manifest advertises assets/images/icon-192.png and icon-512.png but assets/images/ does not exist in the plugin… | 2 |
| 🟢 | Privacy Framework | free | templates/profile/edit.php:512 — the "Show me in the member directory" toggle saves bn_privacy_show_in_directory but MemberDirectoryService (includes… | 3 |
| 🟢 | Bulk Moderation | pro | none — journey complete (full UI->JS->admin-post->BulkModService->Free ModerationService->DB chain wired on canonical Moderation > Bulk tab URL; REST… | 1 |
| 🟢 | White Label | pro | none — journey complete | 2 |
| 🟡 | Visibility & privacy resolver | contract | includes/Feed/FeedService.php:91-101 — excluded_users_where() (the only author-exclusion in home_feed_uncached and explore_feed) filters suspended + … | 4 |
| 🟡 | Moderation | free | Journey Step 14 — suspended users are not blocked from posting. No suspension gate exists on the post-create path: includes/Feed/PostService.php:70 r… | 5 |
| 🟡 | Realtime Updates | free | Online presence heartbeat is missing: includes/SocialGraph/BlockService.php:335 and includes/Profile/MemberDirectoryService.php:133 read bn_last_acti… | 4 |
| 🟡 | Private Messaging | free | WPMediaVerse is not installed/active on the live site, so /messages renders the "Direct messaging requires WPMediaVerse" dependency notice and the jo… | 4 |
| 🟡 | AI Engine | pro | templates/feed/home.php:138-152 — the default for-you view of /activity renders inline chronological SQL and never resolves buddynext_service('feed')… | 4 |
| 🟡 | Drip / Welcome Sequences | pro | buddynext-pro/includes/Email/DripService.php:317 -> EmailSender::send_now('bn.drip_step') early-returns at buddynext/includes/Notifications/EmailSend… | 2 |
| 🟡 | Membership Tiers & Gated Spaces | pro | Step: Web UI never renders the paywall on a gated denial. PaywallRenderer (the spec's blurred-preview + 'Become a Member' CTA, P1-stripe-membership.m… | 4 |
| 🟡 | Stripe Payments | pro | buddynext/assets/js/spaces/store.js:163-169 — a gated-join denial silently resets the Join button; no paywall, no CTA, no checkout path reaches the m… | 4 |
| 🟡 | Push Notifications | pro | Web front-end token registration: no service worker / Firebase web SDK / Notification.requestPermission / POST /me/push-tokens caller exists, so a we… | 4 |
| 🟡 | Auto / Rule-based Moderation | pro | Journey Part 3 (severity=flag): RulesService::evaluate_keyword_block returns a 202 WP_Error (RulesService.php:441), and PostService::create treats ev… | 6 |
| 🟡 | Member Labels | pro | Profile-page / profile-REST label display: the buddynext_profile_extra_data filter has a single Free consumer (profile-stats-strip.php:154) that expe… | 4 |
| 🟡 | Advanced Profile Field Types | pro | Admin field-config save: buddynext/includes/Admin/Members/ProfileFieldsManager.php:421-430 (add) and 713-722 (edit) route options only through choice… | 3 |
| 🟡 | Custom Reactions | pro | buddynext/templates/parts/post-actions.php:115-133 — web reaction picker hardcodes the six built-in reactions and never consumes the buddynext_reacti… | 3 |
| 🟡 | Advanced Search Filters | pro | UI layer: no advanced-filter or saved-search controls exist on the web /search or member-directory surfaces (templates/search/results.php:472, member… | 5 |
| 🟡 | Multiple pinned posts | pro | buddynext/templates/partials/post-card.php:322-348 omits isPinned from data-wp-context; consequently buddynext/assets/js/feed/store.js:904 reads ctx.… | 3 |
| 🔴 | Notification / email / push template model | contract | buddynext-pro/includes/Email/BroadcastService.php:450-457 — send_pending() calls EmailSender::send_now() with a fixed 'bn.broadcast' type and no subj… | 5 |
| 🔴 | Gamification seam | contract | Bridge emits do_action('wb_gamification_event', ...) (GamificationBridge.php:149) into a hook wb-gamification never listens to — every BN social acti… | 5 |
| 🔴 | Email Broadcasts | pro | Step 7 (delivery): BroadcastService::send_pending() calls EmailSender::send_now($user_id,'bn.broadcast',['campaign_id','type']) but (a) no bn.broadca… | 4 |

## 🔴 Broken journeys (top priority)

### Notification / email / push template model (contract)
- **First break:** buddynext-pro/includes/Email/BroadcastService.php:450-457 — send_pending() calls EmailSender::send_now() with a fixed 'bn.broadcast' type and no subject/body, so the campaign's authored copy is discarded; combined with no seeded bn.broadcast template, EmailSender.php:105-108 returns before wp_mail() and the broadcast sends nothing while still marking recipients sent.
- **[critical/confirmed-in-code]** Broadcast sender ignores per-campaign subject/body; routes through a single fixed type instead of the admin-authored copy _(buddynext-pro/includes/Email/BroadcastService.php:450-457 vs create_campaign storing subj…)_
- **[critical/confirmed-in-code]** bn.broadcast template not seeded -> EmailSender::send_now early-returns before wp_mail; broadcast emits zero mail yet marks recipients+campaign 'sent' _(includes/Notifications/EmailSender.php:105-108; BroadcastService.php:459-477; includes/Co…)_
- **[high/confirmed-in-code]** Drip sender passes step subject/body_html in $data but send_now ignores those keys; renders the bn.drip_step template only, so every step would share identical copy _(buddynext-pro/includes/Email/DripService.php:317-328; EmailSender.php:119-120)_
- **[high/confirmed-in-code]** bn.drip_step template not seeded -> drip emits zero mail yet still advances/completes enrollments _(buddynext-pro/includes/Email/DripService.php:317-348; includes/Core/Installer.php:69-210)_
- **[medium/confirmed-in-code]** PushDispatcher::build_snippet switches on legacy bare slugs (follower/reaction/comment/mention) not the bn.* catalogue types the create path emits; most types fall to generic 'You have a new notification.' _(buddynext-pro/includes/Push/PushDispatcher.php:159-199 vs slugs in docs/specs/NOTIFICATIO…)_
- **Fix:** Add optional per-call subject/body override to EmailSender::send_now(): when $data['subject']/$data… → BroadcastService::send_pending(): load the campaign once per batch and pass subject => $campaign->s… → DripService::process_due_enrollments(): verify step subject/body_html render once the send_now over… → Decide template-less policy for bn.broadcast / bn.drip_step: either seed sentinel rows or document … → Align PushDispatcher::build_snippet() switch to canonical bn.* catalogue slugs, or route push copy …

### Gamification seam (contract)
- **First break:** Bridge emits do_action('wb_gamification_event', ...) (GamificationBridge.php:149) into a hook wb-gamification never listens to — every BN social action is a no-op, no points/badges/levels ever awarded.
- **[critical/confirmed-in-code]** Emit hook is dead: bridge fires wb_gamification_event but WBGam has no add_action for it; correct seam is wb_gamification_register_action() / wb_gam_submit_event(). No points are ever awarded from BN activity. _(includes/Bridges/GamificationBridge.php:149 vs grep of wb-gamification: zero listeners fo…)_
- **[critical/confirmed-in-code]** Wrong class guard: bridge/listener/template/settings gate on class_exists('WBGamification\\Plugin'), but plugin bootstrap is global 'WB_Gamification' (ns WBGam\*). The whole seam self-disables even when the plugin is active. _(GamificationBridge.php:35; GamificationBridgeListener.php:28; templates/gamification/lead…)_
- **[critical/confirmed-in-code]** Leaderboard uses direct $wpdb queries (contract requires plugin API) AND targets non-existent wbg_* tables; real tables are wb_gam_*. Leaderboard is empty/errors even with WBGam active and seeded. _(templates/gamification/leaderboard.php:64-66,95-178; real schema wb_gam_points/wb_gam_use…)_
- **[high/confirmed-in-code]** Badge-awarded callback signature mismatch: listener expects (int user_id, int badge_id) but WBGam fires (user_id, array def, string badge_id) — TypeError under strict_types once reachable. _(GamificationBridgeListener.php:44 vs src/Engine/BadgeEngine.php:244)_
- **[low/confirmed-in-code]** Notification type slugs diverge from spec: listener emits bn.badge_awarded / bn.level_up; spec names wb.badge_earned / wb.level_up. Functional but off-spec. _(GamificationBridgeListener.php:53,79 vs spec UI Integration section)_
- **Fix:** Replace the class_exists('WBGamification\\Plugin') guard with class_exists('WB_Gamification') (or f… → Rework GamificationBridge to use the real intake: either register bn_* actions on the wb_gamificati… → Fix GamificationBridgeListener::on_badge_awarded to the 3-arg signature (int $user_id, array $def, … → Rewrite templates/gamification/leaderboard.php to call wb_gam_get_leaderboard() / wb_gam_get_user_p… → Optional: align notification slugs to the spec (wb.badge_earned / wb.level_up) or amend the spec to…

### Email Broadcasts (pro)
- **First break:** Step 7 (delivery): BroadcastService::send_pending() calls EmailSender::send_now($user_id,'bn.broadcast',['campaign_id','type']) but (a) no bn.broadcast template is seeded so send_now() returns early at EmailSender.php:106-108, and (b) the campaign subject/body_html is never passed. No email is sent, yet recipients are marked 'sent'. The buddynext_email_payload seam exists (EmailSender.php:129-148) but Pro only mentions it in a comment (BroadcastService.php:448) and never hooks it.
- **[critical/confirmed-in-code]** Dispatched broadcast delivers no email to any recipient; campaign content never reaches the send path and no matching bn.broadcast template is seeded, so send_now() returns before wp_mail() _(buddynext-pro/includes/Email/BroadcastService.php:450-457; buddynext/includes/Notificatio…)_
- **[high/confirmed-in-code]** Recipients unconditionally marked status='sent' despite zero delivery; admin Recipients view reports false success _(buddynext-pro/includes/Email/BroadcastService.php:459-470; includes/Admin/BroadcastAdmin.…)_
- **[low/confirmed-in-code]** Journey doc's documented REST unsubscribe route GET /buddynext-pro/v1/email/unsubscribe does not exist; real one-click unsubscribe is the init handler ?bn_unsub_campaign=&bid=&uid= (works, but doc is wrong) _(buddynext-pro/docs/journeys/broadcast-email.md:108-114,164 vs includes/Email/BroadcastUns…)_
- **Fix:** In BroadcastService::register(), add_filter('buddynext_email_payload', handler): when context type … → Seed a minimal enabled sentinel bn.broadcast row (and bn.drip_step if Drip shares the path) in the … → Mark a recipient 'sent' only when delivery actually occurred (template resolved and payload not sup… → Align the journey doc unsubscribe section with the real ?bn_unsub_campaign URL (or add the document…

## ✅ Already usable — leave as-is (do not touch)

Activity Feed, Analytics, Authentication & Verification, Blocking & Muting, Bookmarks, Comments, Connections, Engagement / Leaderboard, Follows, Hashtags, Member Directory, Member Profiles, Member Types, Onboarding, Polls, Post Composer & Posts, Reactions, Scheduled Posts, Search, Shares / Reposts, Spaces, Unlimited Webhooks

## Dossiers

- ✅ `conformance/activity-feed.md` — Activity Feed
- ✅ `conformance/post-composer.md` — Post Composer & Posts
- ✅ `conformance/comments.md` — Comments
- ✅ `conformance/reactions.md` — Reactions
- ✅ `conformance/polls.md` — Polls
- ✅ `conformance/shares.md` — Shares / Reposts
- ✅ `conformance/bookmarks.md` — Bookmarks
- ✅ `conformance/hashtags.md` — Hashtags
- ✅ `conformance/member-profiles.md` — Member Profiles
- ✅ `conformance/member-directory.md` — Member Directory
- ✅ `conformance/member-types.md` — Member Types
- ✅ `conformance/follows.md` — Follows
- ✅ `conformance/connections.md` — Connections
- ✅ `conformance/blocking-muting.md` — Blocking & Muting
- ✅ `conformance/spaces.md` — Spaces
- ✅ `conformance/auth-verification.md` — Authentication & Verification
- ✅ `conformance/onboarding.md` — Onboarding
- ✅ `conformance/search.md` — Search
- ✅ `conformance/engagement-leaderboard.md` — Engagement / Leaderboard
- ✅ `conformance/pro-analytics.md` — Analytics
- ✅ `conformance/pro-scheduled-posts.md` — Scheduled Posts
- ✅ `conformance/pro-unlimited-webhooks.md` — Unlimited Webhooks
- 🟢 `conformance/contract-rest-envelope.md` — REST/App contract
- 🟢 `conformance/contract-scale.md` — Scale contract
- 🟢 `conformance/contract-design-system.md` — Design system & dark mode
- 🟢 `conformance/notifications.md` — Notifications
- 🟢 `conformance/email-system.md` — Email System
- 🟢 `conformance/outbound-webhooks.md` — Outbound Webhooks
- 🟢 `conformance/pwa.md` — PWA
- 🟢 `conformance/privacy-framework.md` — Privacy Framework
- 🟢 `conformance/pro-bulk-mod.md` — Bulk Moderation
- 🟢 `conformance/pro-white-label.md` — White Label
- 🟡 `conformance/contract-visibility.md` — Visibility & privacy resolver
- 🟡 `conformance/moderation.md` — Moderation
- 🟡 `conformance/realtime.md` — Realtime Updates
- 🟡 `conformance/messaging.md` — Private Messaging
- 🟡 `conformance/pro-ai.md` — AI Engine
- 🟡 `conformance/pro-drip.md` — Drip / Welcome Sequences
- 🟡 `conformance/pro-membership.md` — Membership Tiers & Gated Spaces
- 🟡 `conformance/pro-stripe.md` — Stripe Payments
- 🟡 `conformance/pro-push.md` — Push Notifications
- 🟡 `conformance/pro-auto-mod.md` — Auto / Rule-based Moderation
- 🟡 `conformance/pro-member-labels.md` — Member Labels
- 🟡 `conformance/pro-advanced-fields.md` — Advanced Profile Field Types
- 🟡 `conformance/pro-custom-reactions.md` — Custom Reactions
- 🟡 `conformance/pro-advanced-search.md` — Advanced Search Filters
- 🟡 `conformance/pro-multi-pin.md` — Multiple pinned posts
- 🔴 `conformance/contract-notifications.md` — Notification / email / push template model
- 🔴 `conformance/contract-gamification-seam.md` — Gamification seam
- 🔴 `conformance/pro-broadcasts.md` — Email Broadcasts