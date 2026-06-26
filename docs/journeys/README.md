# BuddyNext Free — Functional Journey Runbooks

## What is a journey?

A journey is a step-by-step manual test of one Free feature. Each runbook is self-contained and executable by a human tester or an AI agent against a live WordPress site. Journeys are not unit tests — they walk the full feature end-to-end: REST API, DB state, service layer, **the frontend control that triggers it, and the admin setting that gates it**.

Earlier journeys verified only the REST + DB + service layers ("no frontend assertions, design not complete"). That left two blind spots where customers — not journeys — find the bugs: (1) the **button/wiring layer** (a template control that points at the wrong route/method/nonce passes every REST-layer journey while being dead in the browser), and (2) the **admin-config → member-effect** layer (a setting saved but never applied). Both are now mandatory — see the Runbook contract (items 11–12) and **Verification discipline** below.

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
| Admin | `varundubey` | (site admin pw) |
| Member (subscriber) | `alice` | see note |
| Member (subscriber) | `bob` | see note |
| Member (subscriber) | `carol` | see note |

> **Verified against the running site 2026-06-20.** The real accounts are `alice`/`bob`/`carol`/`david`/`eve` (subscribers), `author1`, `contributor1`, admin `varundubey`. Older runbooks say `member1`/`member2` — **those do not exist here**; substitute `alice`/`bob`. Where a runbook hardcodes `member1`/`member2`, read them as `alice`/`bob`.
>
> **Passwords for REST basic-auth:** the curl examples assume the member's password is `password`. If a login returns 401, set a known password first (dev site only): `wp user update alice --user_pass=password`. For browser steps, prefer autologin — no password needed: `?autologin=alice`, `?autologin=bob`, `?autologin=1` (admin).
>
> **Login REST field is `user`** (not `username`/`user_login`): `POST /auth/login {"user":"alice","password":"password"}`. Missing it returns `rest_missing_callback_param`.

Base URL: `http://buddynext.local/`  *(not `buddynext-dev.local` — that host does not resolve on this machine)*

Admin dashboard: `http://buddynext.local/wp-admin/`

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
| `wp_bn_presence` | Realtime — indexed online-presence timestamps |
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
| `wp_bn_outbound_webhooks` | Outbound — registered webhook endpoints |
| `wp_bn_outbound_webhook_log` | Outbound — webhook delivery log |
| `wp_bn_invites` | Onboarding — community invite tokens |
| `wp_bn_activity_log` | Audit — community activity event log |

## Feature coverage — 100%

Every feature in the `FeatureRegistry` catalogue (20 features across core / community / bridges / integrations) has a dedicated journey runbook. Each runbook opens with a **Site-owner expectation** block — what a community owner expects the feature to do out-of-the-box and what they configure — so the journey can be audited as "does the feature meet the owner's expectation?" Bridge journeys require their partner plugin active and degrade to no-ops (no fatals) when it is not.

**Current status is in [`../../audit/journeys.json`](../../audit/journeys.json)** (machine-readable: feature → expectation → status → gaps). Authoring these runbooks surfaced six expectation-vs-reality gaps, all **fixed on 2026-06-09** (see each feature's `fixed_2026_06_09` note in journeys.json): sidebar suggested-follows cache-bust, `buddynext_reactors_limit` filter, WPMediaVerse comment-sync arg order, webhooks opt-in runtime enforcement, announcement expiry, and registration-mode enforcement (invite redemption + approval login gate). Where a runbook's *Known limitations* still lists one of these, journeys.json is the source of truth.

### Core (mandatory)

| File | Feature | Est. time |
|------|---------|-----------|
| [social-graph.md](social-graph.md) | Follow / unfollow / connect / block | 10 min |
| [activity-feed.md](activity-feed.md) | Posts, poll, shares, bookmarks (reactions + comments have their own runbooks) | 12 min |
| [reactions.md](reactions.md) | Emoji reactions: toggle, counts, reactor list | 6 min |
| [comments.md](comments.md) | Threaded comments: create, edit, delete, pin, before-save gate | 8 min |
| [spaces.md](spaces.md) | Open / private / secret space; join; ban; leave; custom types | 12 min |
| [profile-fields.md](profile-fields.md) | Admin creates fields; member fills profile; visibility filter | 10 min |
| [member-directory.md](member-directory.md) | Directory listing; member-type filter; search; block exclusions | 8 min |
| [notifications-email.md](notifications-email.md) | Action triggers notification row + email; mark read | 10 min |
| [search.md](search.md) | Full-text search; reindex; viewer-aware block + shadow-ban exclusion | 10 min |
| [moderation-report.md](moderation-report.md) | Report post; review queue; strike; suspend; appeal | 12 min |
| [auth-verification.md](auth-verification.md) | Register user; create token; verify; resend; idempotency | 8 min |

### Community (default-on)

| File | Feature | Est. time |
|------|---------|-----------|
| [hashtags.md](hashtags.md) | Post with #tag; auto-index; follow hashtag; trending list | 8 min |
| [onboarding.md](onboarding.md) | Reg mode; signup onboarding wizard; community invites; setup wizard | 10 min |
| [announcements.md](announcements.md) | Admin pins a site-wide announcement; per-user dismiss | 6 min |
| [sidebar.md](sidebar.md) | Trending / suggested-follows / joined-spaces widgets; feature toggle | 6 min |

### Bridges & integrations (opt-in — require partner plugin)

| File | Feature | Partner | Est. time |
|------|---------|---------|-----------|
| [gamification.md](gamification.md) | Badge feed cards + badge/level notifications + Achievements profile tab (consumer-only) | wb-gamification | 10 min |
| [jetonomy.md](jetonomy.md) | Forums / discussions unified into the community | jetonomy | 12 min |
| [wpmediaverse.md](wpmediaverse.md) | Direct messages + media (BN blocks enforced) | wpmediaverse | 12 min |
| [media-albums.md](media-albums.md) | Profile media uploads + albums (create / cover / reorder / privacy) | wpmediaverse | 14 min |
| [career-board.md](career-board.md) | Job posts surface in community search | career board | 8 min |
| [webhooks.md](webhooks.md) | Outbound webhooks fire on community events | (none) | 8 min |

Estimate: ~200 minutes for the full suite including cleanup. Run core first, then community, then bridges (each runbook includes cleanup SQL to leave a known-good state).

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
11. **Frontend action wiring** — for every member-facing control the feature exposes (button, form, toggle, menu item), a row mapping: `template file:line` → JS store action (`assets/js/<feature>/store.js`) → REST `METHOD /path` → nonce/context key. The journey must confirm the JS path+method matches a **live** registered route and that the template emits the correct context. This is the layer that catches "button looks fine, does nothing."
12. **Admin-config → member-effect** — for every site-owner setting that gates or changes this feature, a step that flips the setting in the **real admin form**, then re-checks the member-facing effect (route now 403s, nav item disappears, rail hides, email shows the new sender, etc.). Verifies the *set → stored → read → applied* contract end-to-end, not just that the option saves.

### Verification discipline — static analysis is NOT proof

A journey step is only "verified" when it was exercised against the **running site** — the live REST route index, a real rendered HTML response, or a real DB row. Grepping the source for a `get_option()` call or a `register_rest_route()` string is a *hint*, not a verdict. Indirection (helper functions, route-registration loops, bridge guards) routinely defeats grep.

On 2026-06-20 a static (grep-only) audit produced **five** "customer-facing broken" findings. Every one was a **false positive** when checked against the running site:

| Static claim | Why grep was wrong | How to verify correctly |
|---|---|---|
| Appeals approve/deny button 404s | route is `POST /appeals/{id}/resolve` (`ModerationController.php:401`); grep matched only the legacy `/approve`,`/deny` | `curl /wp-json/buddynext/v1` and search the live route index |
| Messages tab dead without WPMediaVerse | `/messages/` is guarded and bounces (`PageRouter.php:267`) | load the page logged-in; observe the redirect |
| "Show desktop rail" toggle does nothing | read via helper `buddynext_community_rail_enabled()` (`hub-shell.php:89`), not a literal `get_option` | `wp option update` it off, then `curl` the page and grep the markup |
| "Show mobile nav" toggle does nothing | read via helper `buddynext_community_mobile_nav_enabled()` (`hub-shell.php:140`) | same: flip option, fetch HTML, count `.bn-mobile-nav` nodes |
| Un-restrict button 404s | `DELETE /users/{id}/restrict` is registered and live; grep of one controller missed it | live route index shows `block`/`mute`/`restrict` all present |

The lesson encoded into items 11–12: **verify the live route index and the rendered HTML, every run.** A worked record of this pass is in [`../qa/FLOW-VERIFICATION-2026-06-20.md`](../qa/FLOW-VERIFICATION-2026-06-20.md).

### Reusable verification snippets (run these every pass)

```bash
# Site (this machine): http://buddynext.local/  — NOT buddynext-dev.local (stale in some older runbooks)
BASE=http://buddynext.local

# 1) Live REST route index — ground truth for "does this endpoint exist?"
curl -s "$BASE/wp-json/buddynext/v1" | python3 -c "import sys,json;[print(r) for r in sorted(json.load(sys.stdin)['routes'])]"

# 2) Methods on one route (confirm JS METHOD matches)
curl -s "$BASE/wp-json/buddynext/v1" | python3 -c "import sys,json;d=json.load(sys.stdin);[print(r,sorted({m for ep in i.get('endpoints',[]) for m in ep['methods']})) for r,i in d['routes'].items() if 'restrict' in r]"

# 3) Logged-in HTML fetch (admin-config -> member-effect)
curl -s -c /tmp/bn.txt -o /dev/null -L "$BASE/?autologin=1"          # member1: ?autologin=member1
curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-app__rail'  # 1 = rendered, 0 = hidden

# 4) Flip a gating option, re-fetch, then RESTORE (delete = back to default)
wp option update buddynext_enable_community_rail 0 && curl -s -b /tmp/bn.txt -L "$BASE/activity/" | grep -c 'bn-app__rail'
wp option delete buddynext_enable_community_rail   # restore pristine default
```

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
