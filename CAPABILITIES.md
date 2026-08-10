# BuddyNext - Capabilities

What BuddyNext free can and cannot do, in buyer language. One row per capability,
each verified against the code at the reference given.

**Last verified against code:** 2026-08-10, branch `1.1.3`, commit `b006dcf8`.
**Source of truth order:** `audit/manifest.summary.json` > this file > the code.
Regenerate both with `/wp-plugin-onboard --refresh`.

Status key: **YES** shipped and code-verified - **PARTIAL** works with a named
limit - **PRO** delivered by BuddyNext Pro, not free - **NO** absent.

---

## Running a community

| Can it... | Status | How |
|---|---|---|
| Run an activity feed members post to? | YES | `Feed\PostService`, 9 `/posts` + 9 `/feed` routes, `bn_posts` |
| Post text, links, images, video and polls? | YES | `PostService::ALLOWED_TYPES`; `bn_poll_options` / `bn_poll_votes` |
| Show a preview card for a pasted link? | YES | `PostController::link_preview` + `PostService::og_meta`; the scrape runs off the member's request (`buddynext_async_fetch_link_meta`), so a slow or dead link never blocks the post |
| Open a post permalink with its replies visible? | YES | `templates/feed/single-post.php` seeds `commentsOpen` for `context === 'single'`; paging is the same control as the feed |
| Let members react, comment and reply? | YES | `bn_reactions`, `bn_comments`; 4 `/reactions` + 3 `/comments` routes |
| Bookmark and reshare posts? | YES | `bn_bookmarks`, `bn_shares` |
| Follow people, and connect mutually? | YES | `bn_follows` (follow) and `bn_connections` (request/accept) are separate graphs |
| Block another member? | YES | `bn_blocks`; enforced on feed, comments and DM |
| Group content into spaces? | YES | 32 `/spaces` routes, `bn_spaces` + `bn_space_members` + `bn_space_meta` |
| Make a space private or secret? | YES | `Spaces\SpaceVisibility`; secret spaces stay out of search and 404 to non-members |
| Give a space its own photo albums? | YES | 1.1.1. `Media\Galleries` + `SpaceAlbumListener`; needs the space Media tab on |
| Categorise spaces? | YES | `bn_space_categories`, 2 `/space-categories` routes |
| Ban a member from one space without site-wide action? | YES | `bn_space_bans` |
| Hashtag and follow topics? | YES | `bn_hashtags`, `bn_post_hashtags`, `bn_hashtag_follows`; 7 `/hashtags` routes |
| Post announcements? | YES | `announcements` feature group (default on) |
| Search members, spaces and posts? | YES | `bn_search_index`; 3 `/search` routes; visibility-scoped |

## Members and profiles

| Can it... | Status | How |
|---|---|---|
| Give members a profile with custom fields? | YES | `bn_profile_groups` / `bn_profile_fields` / `bn_profile_values`; admin at `buddynext-members` |
| Show a member's cover photo on their directory card? | YES | `MemberDirectoryController::shape_item()` sends `cover_url` via `buddynext_user_cover_url()`; falls back to a tone gradient when the member has none |
| Segment members into types? | YES | `bn_member_types` + assignments; 4 `/member-types` routes |
| Show who is online / last active? | YES | `bn_presence` |
| Let a member keep a private profile? | YES | per-field and profile-level visibility; a private profile does not leak post counts |
| Show a member's forum, job, listing or course activity on their profile? | YES | bridge panels - only for the partner plugins actually installed |
| List published articles on a profile? | YES | 1.1.1. `Bridges\MemberBlogBridge`; requires WB Member Blog |
| Onboard a new member with a wizard? | YES | `onboarding` group, 4 `/me/onboarding` routes, admin page `buddynext-setup` |

## Accounts and access

| Can it... | Status | How |
|---|---|---|
| Register and log in without wp-login? | YES | 18 `/auth` routes; mapped `/login/` page |
| Sign in with Google, Facebook, GitHub, Discord or Apple? | YES | `Auth\SocialLogin`; Apple added 1.1.1 |
| Require two-factor? | YES | TOTP with a scannable QR, plus an email-code fallback (`POST /auth/2fa/email-code`) |
| Verify email before posting? | YES | `bn_verify_tokens`, `Auth\VerificationService` |
| Invite people to the community? | YES | `bn_invites` |
| Connect a native mobile app to the same account? | YES | 1.1.1. `POST /auth/app-connect` mints an Application Password behind a one-time bridge token |
| Serve a mobile app its config and translations? | YES | `/app/config` and `POST /app/strings` |
| Install as a PWA? | YES | `PWA\PwaService`, 3 `/pwa` routes. Needs HTTPS - it cannot be tested over plain HTTP |

## Moderation and safety

| Can it... | Status | How |
|---|---|---|
| Let members report content? | YES | `bn_reports`, 6 `/reports` routes |
| Warn, suspend or shadow-ban? | YES | `bn_user_strikes`, `bn_user_suspensions`, `bn_mod_log` |
| Let a suspended member appeal? | YES | `bn_appeals`, 4 `/appeals` routes; the appeal page stays reachable while suspended |
| Rate-limit abuse? | PARTIAL | `bn_rate_limits` table backs it on every site; the fast object-cache path needs Redis or Memcached |
| Filter banned words / safeguards? | YES | `Moderation\SafeguardService` |
| Review a queue of reports in wp-admin? | YES | moderation screens under the `buddynext` hub |
| Auto-moderate with rules or AI? | PRO | `bn_mod_rules`, AI moderation - see BuddyNext Pro |

## Owner administration

| Can it... | Status | How |
|---|---|---|
| Administer everything from one menu? | YES | `Admin\AdminHub`, top-level `buddynext` menu; sections register through `self::sections()` |
| Work on a phone? | YES | 1.1.1 rebuilt every admin listing to card-stack below 782px; pinned by `tests/e2e/admin/table-layout-contract.spec.ts` |
| Edit the emails it sends? | YES | `bn_email_templates` + `bn_email_log` |
| Re-theme without touching CSS? | YES | one `--bn-hue` drives the OKLCH accent ramp via `Theme\TokenService` |
| Override templates in a theme? | YES | `{theme}/buddynext/` |
| Choose its page slugs? | YES | `buddynext_slug_*` / `buddynext_page_*` option pairs |
| Control which plugins load on community routes? | YES | `Core\PluginIsolation` with an owner-managed `buddynext_isolation_keep` allow-list |

## Platform and integration

| Can it... | Status | How |
|---|---|---|
| Offer a REST API? | YES | 222 routes under `buddynext/v1`; catalogued in `docs/api/openapi.json` (208 paths) |
| Send outbound webhooks? | YES | opt-in. `bn_outbound_webhooks` + log; 4 `/webhooks` routes |
| Be extended by other plugins? | YES | 1,292 documented hooks; `NavRegistry`, `buddynext_companions`, `buddynext_integrations` |
| Integrate with Wbcom plugins? | YES | bridges for WPMediaVerse (DM), Jetonomy (forums), Gamification, Career Board, Learnomy, Listora, Eventonomy, WB Member Blog - each self-guards and is individually toggleable |
| Run background work at scale? | YES | 36 scheduled jobs: 24 on Action Scheduler, 12 on WP-Cron |
| Manage it from WP-CLI? | YES | 6 commands under `wp buddynext` (`demo`, `cert`, `handles`, `qa-fixtures`, `repair-space-owners`, `repair-discussion-visibility`) |
| Stay fast at 100k members? | YES | list surfaces paginate and count via `COUNT(*)`; the leaderboard N+1 was removed in 1.1.1 |

## Deliberately not in free

| Can it... | Status | How |
|---|---|---|
| Charge for membership? | PRO | tiers, Stripe, PayPal, coupons, tax, invoices |
| Send broadcast email or drip sequences? | PRO | |
| Show community analytics? | PRO | |
| Send push notifications? | PRO | |
| Push live updates over websockets? | PRO | free uses REST polling - 5s active, adaptive |
| White-label the plugin? | PRO | |
| Run direct messaging on its own? | NO | BuddyNext is the UI; the conversation store belongs to WPMediaVerse |
| Replace or disable WordPress core behaviour? | NO | by design. `/feed/`, `wp/v2` and `wp-login.php` stay core's |
