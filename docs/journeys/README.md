# BuddyNext Free — Functional Journey Runbooks

## What is a journey?

A journey is a step-by-step manual test of one Free feature. Each runbook is self-contained and executable by a human tester or an AI agent against a live WordPress site. Journeys are not unit tests — they walk the full feature end-to-end: REST API, DB state, and service layer. No frontend assertions are made (design is not complete); all verification happens at the REST + DB + service layers.

## How to run a journey

1. Start the LocalWP dev site (`http://buddynext-dev.local/`) via the LocalWP GUI.

2. Open the site shell from LocalWP (Right-click site -> "Open Site Shell"). All `wp` commands in the runbooks are run inside this shell — no `npx wp-env` prefix needed.

3. Open a journey file and follow the steps in order.

4. After each step verify the expected outcome before proceeding.

5. Run the verification SQL queries at the end of each journey to confirm DB state.

6. Run the cleanup SQL to leave the environment tidy after each journey (unless running on a disposable instance).

## Test credentials

| Role  | Username | Password |
|-------|----------|----------|
| Admin | `admin`  | `password` |
| Member (seed user 1) | `member1` | `password` |
| Member (seed user 2) | `member2` | `password` |

Base URL: `http://buddynext-dev.local/`

Admin dashboard: `http://buddynext-dev.local/wp-admin/`

Admin autologin shortcut: append `?autologin=1` to any admin URL. The mu-plugin at `mu-plugins/00-autologin.php` handles this.

## Free DB tables (reference)

| Table | Feature area |
|-------|-------------|
| `wp_bn_follows` | Social Graph — follow graph |
| `wp_bn_connections` | Social Graph — bilateral connections |
| `wp_bn_blocks` | Social Graph — blocks and mutes |
| `wp_bn_posts` | Activity Feed — core post content |
| `wp_bn_poll_options` | Activity Feed — poll answer options |
| `wp_bn_poll_votes` | Activity Feed — poll votes |
| `wp_bn_reactions` | Activity Feed — emoji reactions |
| `wp_bn_comments` | Activity Feed — comments |
| `wp_bn_shares` | Activity Feed — shares |
| `wp_bn_bookmarks` | Activity Feed — bookmarks |
| `wp_bn_feed_items` | Activity Feed — denormalised feed cache |
| `wp_bn_spaces` | Spaces — space definitions |
| `wp_bn_space_members` | Spaces — membership rows |
| `wp_bn_space_categories` | Spaces — taxonomy categories |
| `wp_bn_space_bans` | Spaces — banned users per space |
| `wp_bn_profile_groups` | Profile Fields — field group definitions |
| `wp_bn_profile_fields` | Profile Fields — field definitions |
| `wp_bn_profile_values` | Profile Fields — user-filled values |
| `wp_bn_member_types` | Member Directory — type definitions |
| `wp_bn_member_type_assignments` | Member Directory — user-type assignments |
| `wp_bn_notifications` | Notifications — in-app notification queue |
| `wp_bn_notification_prefs` | Notifications — per-user delivery preferences |
| `wp_bn_email_templates` | Notifications — email template definitions |
| `wp_bn_email_log` | Notifications — sent email history |
| `wp_bn_hashtags` | Hashtags — hashtag definitions |
| `wp_bn_post_hashtags` | Hashtags — post-to-hashtag join table |
| `wp_bn_hashtag_follows` | Hashtags — user-to-hashtag follows |
| `wp_bn_search_index` | Search — full-text search index |
| `wp_bn_reports` | Moderation — content reports |
| `wp_bn_user_strikes` | Moderation — moderation strikes |
| `wp_bn_user_suspensions` | Moderation — user suspensions |
| `wp_bn_appeals` | Moderation — moderation appeals |
| `wp_bn_mod_log` | Moderation — immutable audit log |
| `wp_bn_verify_tokens` | Auth — email verification tokens |
| `wp_bn_user_abilities` | Permissions — explicit ability grants |
| `wp_bn_user_credits` | Gamification — credit balances |
| `wp_bn_outbound_webhooks` | Outbound — registered webhook endpoints |
| `wp_bn_outbound_webhook_log` | Outbound — webhook delivery log |
| `wp_bn_announcement_dismissals` | Feed — announcement dismissal tracking |
| `wp_bn_invites` | Onboarding — community invite tokens |
| `wp_bn_activity_log` | Audit — community activity event log |

## Running all journeys for a dogfooding session

Run these journeys in the recommended order. Each journey includes cleanup SQL to leave the environment in a known-good state for the next one.

| Order | File | Feature | Estimated time |
|-------|------|---------|---------------|
| 1 | [social-graph.md](social-graph.md) | Follow / unfollow / connect / block | 10 min |
| 2 | [activity-feed.md](activity-feed.md) | Create posts, poll, reactions, comments, shares, bookmarks | 12 min |
| 3 | [spaces.md](spaces.md) | Create open / private / secret space; join; ban; leave | 12 min |
| 4 | [profile-fields.md](profile-fields.md) | Admin creates fields; member fills profile; visibility filter | 10 min |
| 5 | [member-directory.md](member-directory.md) | Directory listing; member type filter; search; block exclusions | 8 min |
| 6 | [notifications-email.md](notifications-email.md) | Follow action triggers notification row + email; mark read | 10 min |
| 7 | [hashtags.md](hashtags.md) | Post with #tag; auto-index; follow hashtag; trending list | 8 min |
| 8 | [search.md](search.md) | Full-text search; reindex; viewer-aware block + shadow-ban exclusion | 10 min |
| 9 | [moderation-report.md](moderation-report.md) | Report post; admin reviews queue; strike; suspend; appeal | 12 min |
| 10 | [auth-verification.md](auth-verification.md) | Register user; create token; verify; resend; idempotency | 8 min |

Estimate: 100 minutes total for all 10 journeys including cleanup.

## Runbook contract

Every runbook in this directory follows the same structure:

1. **Header metadata** — feature namespace, hooks exercised, DB tables touched, estimated time.
2. **Preconditions** — what must exist before the journey starts (users, spaces, data).
3. **Happy-path steps** — numbered, sequential, each with an exact URL or WP-CLI command and an expected outcome.
4. **Edge cases to also verify** — at least 2 non-happy-path scenarios per feature.
5. **What this validates** — explicit list of service methods, hook fires, and DB writes confirmed.
6. **Verification queries** — copy-paste SQL to run against the LocalWP MySQL instance.
7. **REST surface walked** — each endpoint exercised with expected status and response shape.
8. **Cleanup** — SQL (and WP-CLI where needed) to remove test artifacts.
9. **Known limitations** — documented gaps, pending hooks, or TODO items.
10. **Automation notes** — guidance for converting the journey to a scripted or Playwright test.

## WP-CLI usage in these runbooks

All `wp` commands assume you are in LocalWP's site shell. Open it by right-clicking the site in the LocalWP GUI and selecting "Open Site Shell". Commands appear as:

```bash
wp option get siteurl
wp user get member1 --field=ID
wp db query "SELECT * FROM wp_bn_follows LIMIT 5;"
wp eval "echo buddynext_service('follows')->count(1);"
```

Do not prefix with `npx wp-env run cli` — that is the wp-env Docker pattern used in the Pro bundle workspace. This Free repo uses LocalWP.

## MySQL prefix

All tables use the `wp_` prefix (LocalWP default). If your LocalWP site uses a different prefix, adjust the SQL queries accordingly.

```bash
wp db query "SELECT option_value FROM wp_options WHERE option_name = 'table_prefix';"
```
