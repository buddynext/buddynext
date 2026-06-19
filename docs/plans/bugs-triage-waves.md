# BuddyNext — Possible-Bug Triage & Wave Plan

> Source: Basecamp **Possible Bug** column (`9995234561`), 95 active cards, snapshot 2026-06-19.
> Grouped by **subsystem** (the unit that shares files → cohesive PR per wave), with each card's
> `[Type]` tag. Destination for triaged-valid cards: **Ready for Development** (`9990087450`),
> then claimed into **Team 2** (`10000467831`) to work.
>
> Wave order = severity first: **Security/Data-loss → Functional correctness → Bridges/fatals →
> UI/Theme polish → Onboarding/Auth → Docs cleanup**, with **NEEDS-INFO** as a parallel repro track.

---

## A. Search & Indexing (7)
- 10014121812 — [Search] SearchController::search() instantiates new SearchService() instead of DI container
- 10014121731 — [Search] reindex_all_sync() WP-Cron fallback only indexes users (LIMIT 500); spaces+posts never indexed
- 10014121622 — [Search] SearchService::deindex() unchecked $wpdb->delete() — deleted objects remain in index on failure
- 10014121548 — [Search] SearchService::index() unchecked $wpdb->query() — silent indexing failure
- 10013917855 — [Functional] matching_user_ids() doesn't exclude suspended/shadow-banned — server-rendered search leaks data
- 10013917760 — [Functional] online_now() doesn't exclude suspended/shadow-banned/directory-opt-out users
- 10013868535 — [Free] Search store.js wrong space join/leave endpoints (/spaces/{id}/members vs /join /leave)

## B. Notifications & Email (6)
- 10013951360 — [Functional] buddynext_notification_created fires inconsistent data across merge vs insert paths
- 10013951076 — [Data] $wpdb->insert() return not checked in NotificationService::create()
- 10013950941 — [Functional] Master email channel toggle not respected in email send path
- 10013950863 — [Functional] buddynext_notification_should_send filter bypassed in group merge path
- 10013951215 — [Docs] Journey doc REST paths missing /me/ prefix for notifications
- 10010425778 — [NEEDS-INFO] Notification dropdown blank/empty in dark mode

## C. Profiles / Fields / Repeater (10)
- 10014111630 — [UI] Dynamically added Work Experience / Education entries render unstyled (browser default)
- 10013916235 — Headline field hidden on profile view despite being editable
- 10013918143 — [UI] Profile hover: stats row + tab show underline instead of background-only
- 10014014523 — [Nav] "See all" link in profile views navigates to REST endpoint → JSON 401
- 10013791670 — [Functional] create_field() REST missing show_on_register, is_searchable, visibility, options args
- 10013791527 — [Docs Mismatch] Journey claims POST profile-fields/groups don't exist — they are registered
- 10013791325 — [Data] System groups deletable via delete_group() with no is_system guard — data loss
- 10013791150 — [Data] create_field() auto-group doesn't bust all_groups cache — invisible up to 10 min
- 10013790923 — [Functional] Repeater field search mirrors never written — searchable sub-fields invisible in directory
- 10013790759 — [Functional] save_profile() silently discards WP_Error from sanitization/validation
- 10013917983 — [Docs Mismatch] member-types routes {id} vs controller {slug}

## D. Comments (8)
- 10013745552 — [Functional] CommentController::update() response missing viewer_reaction
- 10013745495 — [UI] Pinned comment orphaned on soft-delete — ghost deleted comment at top
- 10013745417 — [Data] CommentService::delete() double-decrements comment_count on repeat DELETE
- 10013739560 — [Data] Per-user comment rate-limit TOCTOU on transient read-then-increment
- 10013739434 — [Functional] CommentController::pin() returns 403 for both not-found and permission-denied
- 10013739261 — [Functional] CommentController::create() force-stamps 400, flattening 403/429
- 10013688879 — [Functional] CommentService soft-delete orphans reply threads (hidden parent hides children)
- 10013678638 — [UI] Comment emoji trigger icon invisible on Reign — missing color reset

## E. Reactions (4)
- 10013718859 — [Perf] ReactionController::list_reactors() N+1 — 100+ queries for 100 reactors
- 10013718968 — [Docs] reactions.md — decrement SQL + filter claim mismatches
- 10013697659 — [Functional] Open reactors popover blocks clicks on Share/action buttons below
- 10013565266 — [Functional] Reaction summary chips don't update after toggling a reaction

## F. Polls & Shares (4)
- 10013688794 — [Data] PollService counter race — inflated vote counts on concurrent requests
- 10013688722 — [Functional] ShareService TOCTOU — concurrent requests create duplicate shares
- 10012439940 — Member Profile "Share to Feed" creates empty activity feed entry
- 10013716266 — [UI][Low] Poll "ends" date calendar icon invisible in BuddyX/Pro dark mode

## G. Social Graph — Follow / Connection / Block (6)
- 10013663372 — [Perf] FollowService::unfollow() invalidates cache on no-op unfollow during block cleanup
- 10013662982 — [Functional] ConnectionController::withdraw_request() conflates withdraw and disconnect
- 10013662642 — [Perf] FollowService::follow() redundant bidirectional block checks
- 10013662266 — [Security] FollowController::unfollow() missing capability check (follow() has one)
- 10013661932 — [Perf] BlockService::block() redundant unfollow/connection cleanup on duplicate blocks
- 10013661557 — [Functional] Follow suggestions return blocked/blocking users

## H. Messaging / DM (3)
- 10013977576 — [Functional] After both users delete a DM conversation, new messages not delivered to recipient
- 10013970934 — Member Card Popup appears along with Delete Conversation confirmation modal
- 10013840441 — [UI] Reaction picker on outgoing (sent) messages causes horizontal scrollbar
- 10010123239 — [NEEDS-INFO] Messages page UI breaks/glitches on refresh (likely covered by autofocus fix — verify)

## I. Spaces (7)
- 10013774962 — [Functional] SpaceMemberService::unban() fires wrong hook (member_unbanned vs space_user_unbanned)
- 10013774875 — [UI] Space moderation Reports tab action buttons completely inert
- 10013774705 — [Functional] SpaceMemberService::ban() fires wrong hook (member_removed vs space_user_banned)
- 10013774607 — [Data] Space deletion leaves orphaned bn_space_{id}_* options
- 10013932477 — [Functional] Space notification preference "All activity" → "Could not save space preference"
- 10010357621 — [NEEDS-INFO] No limit notice + console error when exceeding max space-creation limit
- 10010498716 — [NEEDS-INFO] Space owner posts incorrectly sent for moderation when approval enabled

## J. Cross-plugin Bridges — Jetonomy / WPMediaVerse / Gamification (8)
- 10013757839 — [Free] JetonomyBridge — 9+ direct SQL into jt_* tables bypass Jetonomy model API
- 10013757308 — [Free] WPMediaVerseBridge — follow-mirror re-entrancy guard not in try/finally → can lock
- 10013757164 — [Free] WPMediaVerseBridge — 39 lines dead code enqueue_lightbox() never hooked
- 10013756987 — [Free] JetonomyBridge — N+1 in @mention parsing (50+ queries)
- 10013756617 — [Free] GamificationBridge — register_actions() against NOOP_HOOK never fired
- 10013749964 — [Free] GamificationBridgeListener — buddynext_service('notifications') no null-check → fatal
- 10013749808 — [Free] JetonomyBridge — CSRF on state-changing GET in maybe_provision_and_redirect()
- 10013749653 — [Free] WPMediaVerseBridge — missing method_exists() guard on is_restricted()/is_muted() → fatal

## K. Hashtags (4)
- 10013703491 — [Free] HashtagService::sync() omits created_at → NULL timestamps
- 10013703291 — [Free] HashtagService::get_trending() uses NOW() instead of UTC_TIMESTAMP()
- 10013569501 — [Functional] PHP warnings on hashtag feed — object notation used on array
- 10013646284 — [Free] Journey hashtags.md — unfollow is DELETE not POST (step 9 always following:true)

## L. Onboarding (3)
- 10013934556 — [UI] Onboarding Spaces step grid gap 12px but v2 specifies 8px
- 10013932750 — [UI] Onboarding Spaces step legacy .bn-card class → CSS token conflict on picker cards
- 10013782328 — [Reign Theme] Unwanted gap between header and onboarding content

## M. Auth / Registration (2)
- 10013840323 — [Auth] [buddynext_auth] shortcode — login/register broken; missing JS/CSS enqueue + Gutenberg publish fails
- 10013787924 — [UI/Functional] Registration page broken: UI/JS conflict + "User registration not allowed"

## N. Theme Integration — Reign / BuddyX (5)
- 10013838106 — [Reign] Bell (notification) icon not showing in Reign header when BuddyNext active
- 10013932484 — Reign Customizer typography font family not applied to BuddyNext (broken token chain, 4 locations)
- 10013626483 — [UI] BuddyX global button selector applies accent background to BuddyNext buttons
- 10013634209 — [Feature] BuddyX/BuddyX Pro: inject BuddyNext user dropdown in header (parity with Reign)
- 10013782549 — [UI] Infinite-scroll "Loading more posts…" spinner renders as bordered card box

## O. Cron / Race-condition reliability — Free (3)
- 10013814166 — [Free] OnboardingListener nudge — wp_next_scheduled race → duplicate nudge emails
- 10013813955 — [Free] OutboundWebhookService::dispatch() — wp_next_scheduled race → duplicate webhook deliveries
- 10013813837 — [Free] RoleService add/spend/deduct_credits — race → credit balance corruption

## P. Pro (6)
- 10013814605 — [Pro] AI ReplyController::bump_usage() — get_user_meta read-modify-write race bypasses daily quota
- 10013785010 — [Pro] LabelService + LabelAssignmentService — no capability checks on CRUD/assignment
- 10013784901 — [Pro] DripService::process_due_enrollments() — no locking → duplicate emails on concurrent cron
- 10013784763 — [Pro] DripService::create_sequence() — steps stored without validate_step() → stored XSS
- 10013784604 — [Pro] BulkModService — no capability check on dismiss/remove/warn/suspend
- 10013673246 — [Pro] Journey scheduled-posts.md — backdate SQL uses NOW(), fails on non-UTC MySQL

## Q. Media (2)
- 10013929948 — Orphaned media uploads — removing preview does not delete from server
- 10004890159 — [NEEDS-INFO] Share Media action does not work on user profile media gallery

## R. Feed / Bookmarks misc (2)
- 10010367439 — [NEEDS-INFO] Save option not working on Feed/Home page
- 10010339386 — [NEEDS-INFO] Saved posts not appearing in Bookmarks

## S. Docs / Journey mismatches (low-risk batch) (4)
- 10013917983 — member-types {id} vs {slug} (also listed in C)
- 10013791527 — profile-fields/groups endpoints exist (also C)
- 10013718968 — reactions.md mismatches (also E)
- 10013646424 — [Free] Journey sidebar.md step 11 checks sidebar-upcoming-events.php which doesn't exist

## T. NEEDS-INFO — parallel repro/triage track (9)
- 10010498716 — Space owner posts sent for moderation (also I)
- 10010425778 — Notification dropdown blank in dark mode (also B)
- 10010367439 — Save option not working Feed/Home (also R)
- 10010357621 — Space creation limit notice (also I)
- 10010339386 — Saved posts not in Bookmarks (also R)
- 10010123239 — Messages page UI breaks on refresh (also H)
- 10004890159 — Share Media profile gallery (also Q)
- 10004784905 — Default avatar not applied after configuration
- 10001372535 — Members grid "hover text not visible" — not reproduced, needs element + env

---

## Proposed wave sequence

**Wave 1 — Security + Data-loss (highest severity).**
Pro caps/XSS/CSRF + destructive/data-corruption races:
P (10013785010, 10013784763, 10013784604, 10013814605, 10013784901), G(10013662266),
J(10013749808 CSRF), C(10013791325 system-group delete), O(all 3 credit/webhook/nudge races),
F(10013688794 poll race), H… → plus the unchecked-DB-write data cards
B(10013951076), I(10013774607), Search(10014121622/10014121548).

**Wave 2 — Functional correctness (server behaviour).**
B notifications (10013951360/10013950941/10013950863), C profile
(10013790759/10013790923/10013791670/10013791150), D comments
(10013739261/10013739434/10013745417/10013688879/10013745552), I spaces hooks
(10013774705/10013774962/10013932477), G social graph
(10013662982/10013661557), Search privacy (10013917855/10013917760),
F(10013688722 share TOCTOU), H(10013977576 DM delivery), E(10013565266/10013697659).

**Wave 3 — Bridges + fatals + perf.**
J(remaining bridge fatals/dead-code/N+1), K hashtags
(10013703491/10013703291/10013569501), E(10013718859 N+1),
G perf (10013663372/10013662642/10013661932), C(10013868535 endpoints).

**Wave 4 — UI / UX polish + theme integration.**
C(10014111630/10013918143/10014014523/10013916235), D(10013745495/10013678638),
H(10013840441/10013970934), N (all theme), L onboarding,
F(10013716266), R/Q media+feed, 10013782549 spinner.

**Wave 5 — Auth / Registration.** M (10013840323, 10013787924) — likely interrelated; verify together.

**Wave 6 — Docs / Journey cleanup (low-risk, batchable anytime).** S + the [Docs] cards in B/E/K/P.

**Parallel track — NEEDS-INFO (T).** Reproduce each against current code; close as by-design/already-fixed
with evidence, or promote to a wave above. (e.g. 10010123239 messages-refresh glitch is likely already
fixed by the autofocus + native.php work — verify and close.)
