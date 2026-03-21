# BuddyNext — Moderation

**Status:** Locked
**Last updated:** 2026-03-19

---

## What It Does

Full-platform moderation system covering all content types, all user actions, and all addon contributions. Site admins, space moderators, and automated safeguards work together to keep the community healthy.

---

## Who Moderates What

| Role | Scope |
|------|-------|
| Site Admin | Everything — all content, all users, all spaces, all addon content |
| Space Moderator | Posts, comments, and members within their space only. Cannot issue site-wide strikes or suspend accounts. Can remove posts, pin posts, ban users from their space. |

---

## Reportable Content (All Types)

Any member can report any of the following:

**BuddyNext content**
- Feed posts (text, link, poll, activity)
- Comments
- User profiles
- Spaces
- Direct messages

**WPMediaVerse content (bridge)**
- Media items (photos, videos)
- Media comments

**Jetonomy content (bridge)**
- Discussions
- Discussion replies

**Career Board content (bridge)**
- Job listings
- Employer profiles

Report reasons: spam, harassment, misinformation, inappropriate content, fake/misleading, impersonation, other (free text).

---

## Moderation Queue

Central admin panel for all flagged content regardless of source:

- Unified queue — all content types, all plugins, one place
- Filters: by content type, by source plugin, by report reason, by status, by date
- Sort by: report count (default), newest, oldest
- Each item shows: content preview, reporter, report reason, report count, previous decisions on this user

**Queue actions per item:**
- Approve — clear flag, content stays visible, reporter notified (resolved)
- Remove — soft delete content, poster notified (content removed), reporter notified (resolved)
- Escalate — flag for senior moderator review
- Dismiss — false report, no action taken
- Bulk actions: approve all / remove all matching filter

---

## User Moderation

**Warn** — private message sent to user explaining the violation. Logged. No access impact.

**Strike** — formal violation. Configured thresholds:
- Strike 1 → warning notification
- Strike 2 → 48h post suspension (can read, cannot post/comment/react)
- Strike 3 → full account suspension
Thresholds configurable by admin.

**Suspend** — locked out. Content visibility: admin choice (keep visible or hide all). Suspension email sent with appeal link. Suspension duration: temporary (X days) or permanent.

**Shadow ban** — user can post but content is invisible to all other users. They never know. Admin only.

**Ban from space** — space moderator action. User removed from space, cannot rejoin. Site-wide access unaffected.

**Permanent ban** — full account deletion or permanent suspension. Admin only.

---

## Strikes and WBGamification

When a strike is issued → WBGamificationBridge fires → WBGam deducts configured points from user.
When account is suspended → WBGam points frozen (no earn, no spend) for duration.
Strike reversed by admin → WBGam points restored.

---

## Suspended Users in Platform Surfaces

- **Leaderboards**: suspended users hidden
- **Member directory**: suspended users hidden
- **Search**: suspended users hidden from results
- **Space rosters**: suspended users hidden
- **Notifications**: no outbound notifications from suspended users
- **Profiles**: profile hidden or visible — admin choice at suspension time

---

## Search + Moderation Sync

When content is removed via moderation:
- `bn_search_index` entry removed immediately (sync, not async) — removed content must not resurface in search
- `bn_posts` / `bn_comments` / `bn_messages` soft-deleted (data kept for potential appeal review)

When user is shadow-banned:
- All their content excluded from `bn_search_index` queries at query time (filter by shadow-ban status)

---

## Automated Safeguards

| Safeguard | Notes |
|-----------|-------|
| Banned word list | Admin-defined. Action on trigger: flag for review OR auto-reject post submission. Per space override. |
| Rate limiting | Max posts/comments per user per minute. Configurable. Excess attempts silently queued (not rejected) to avoid frustration. |
| Link domain blocklist | Posts containing blocked domains rejected at submission |
| New member restrictions | First N posts from new account go to approval queue. Configurable days or post count threshold. |
| Pre-moderation per space | Space setting — all posts in that space need moderator approval before visible |
| Spam detection | Multiple identical posts → auto-flag |
| AI content moderation | Pro — image + text toxicity scoring. Score threshold triggers auto-flag or auto-reject. |

---

## Content Warnings (User-Applied)

Users can mark their own post as sensitive before publishing:
- Content warning options: NSFW, spoilers, violence, strong language
- Post shows blurred/collapsed with warning label in feed
- User clicks "Show" to reveal
- Admins can force-add content warning to any post retroactively

---

## Appeal Process

Suspended users receive appeal URL in suspension email.
Appeal creates a moderation thread visible only to admins.
Admin reviews appeal: lift suspension, reduce, or uphold.
User notified of outcome.

---

## Moderation Log

Every action logged: who did what, to what content, when, note field.
Immutable — no deletions. Admin-only access.

---

## Admin Alerts

When moderation queue exceeds X items (configurable) → email alert to admin email.
When a specific user receives Y reports in Z days → alert admin.
New user pending approval (approval-mode registration) → alert admin.

---

## Addon Behavior

### WPMediaVerse
Media items, media comments reportable. Reported items enter unified queue with content preview. WPMediaVerse storage not affected by moderation — content hidden at query level, not deleted from storage (until admin permanently deletes).

### Jetonomy
Discussions and replies reportable. Jetonomy-side moderation (voting flags, community moderation) still active within discussions. Escalated items enter BuddyNext queue. Space mod cannot moderate Jetonomy discussions directly — they request review via escalation.

### WBGamification
Strikes → points deducted via bridge action. Suspended users removed from leaderboards. Badge history preserved (immutable) but badges hidden while suspended.

### Career Board
Job listings and employer profiles reportable. Fake job detection → admin review. Admin can de-list job or suspend employer account.

---

## Data Stored

`bn_reports` — report records (reporter, content type, content id, reason, status)
`bn_mod_log` — immutable moderation action log
`bn_user_strikes` — strike records per user

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Activity Feed | Removed posts soft-deleted from `bn_posts` + search index |
| Search | Removed content immediately de-indexed; shadow-banned users filtered |
| Notifications | Moderation decisions notify poster + reporter; admin alerts on queue thresholds |
| Spaces | Space-scoped queue for moderators; pre-moderation toggle per space |
| Social Graph | Blocks independent of moderation — user can block without reporting |
| Direct Messaging | DM reports: metadata only, not message content (privacy) |
| WBGamification | Strikes → point deduction; suspension → freeze points + hide leaderboard |
| Career Board | Job listings + employer profiles reportable |
| Email | Suspension, appeal, content-removed, content-approved emails in catalog |
| Pro AI | AI scores submitted content; threshold-based auto-flag or auto-reject |

---

## Gaps / Open Questions

- None — fully locked
