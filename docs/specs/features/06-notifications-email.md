# BuddyNext — Notifications + Email System

**Status:** Locked
**Last updated:** 2026-03-19 (audit: removed payment-specific email types, no payments in stack)

---

## What It Does

Unified notification center. One bell, one email system — WPMediaVerse, Jetonomy, WBGamification, and Career Board all feed into it via bridges.

---

## On-site Notification Bell

- Unread count badge (cached 30s, invalidated on new notification)
- Dropdown: recent notifications, mark read, mark all read
- Full notifications page at `/notifications/`
- Built with WordPress Interactivity API
- Real-time polling (free) → WebSocket (Pro)

---

## Notification Types

### BuddyNext Core
Follow, connection request, connection accepted, @mention, post reaction, post comment, post share, space join request, space request approved, space invite, new post in space

### WPMediaVerse Bridge
Media reaction, media comment, media favorite, media mention

### Jetonomy Bridge
Discussion reply, discussion mention, discussion reaction

### WBGamification Bridge
Points awarded, badge earned, level up

### Career Board Bridge
Application received (employer), application status changed (candidate)

### Moderation / Admin
Content reported (moderator), content approved (poster), content removed (poster), account suspended, account approved, new user pending approval (admin)

---

## Notification Grouping

High-frequency actions group into one notification:
- Same type + same object within 24h → update existing, increment count
- "Maria, José and 12 others reacted to your post"
- After 24h window — new group starts

---

## User Notification Preferences

Per type, per channel (on-site / email):
- On-site: on / off
- Email: immediate / daily digest / weekly digest / off

Per-space override: all / mentions / none (gates space post notifications).

Admin sets site-wide defaults and can lock certain types.

---

## Email Catalog

**Account/Auth**: Welcome, Email Verification, Password Reset, Account Approved, Account Suspended

**Social**: New Follower, Connection Request, Connection Accepted, Mentioned, Post Reaction, Post Comment, Post Shared

**Spaces**: Space Invite, Join Request Received (moderator), Join Request Approved (member)

**Moderation/Admin**: Content Reported, Content Approved, Content Removed, New User Pending Approval

**Digests**: Daily Digest, Weekly Digest

**WPMediaVerse Bridge**: Media Reaction, Media Comment, Media Favorite

**Jetonomy Bridge**: Discussion Reply, Discussion Mention

**WBGamification Bridge**: Badge Earned, Level Up

**Career Board Bridge**: Application Received, Application Status Changed

**Pro/Membership**: Membership Welcome, Membership Activated, Membership Cancelled

**Pro/Broadcast**: Broadcast Campaign (admin-authored), Space Digest (auto-generated)

**Pro/Drip**: Drip Step (configurable per sequence step — day 0 welcome, day 3 suggestions, day 7 digest, day 14 nudge, day 30 milestone)

---

## Admin Email Editor

- List all emails with enabled/disabled toggle
- Edit subject, preheader, body (WordPress editor + token picker)
- Token picker — inserts `{token}` at cursor
- Preview with dummy data, send test email
- Reset to default per email
- Global settings: from name, from email, footer text, logo

---

## Global Tokens

`{site_name}`, `{site_url}`, `{user_name}`, `{user_email}`, `{user_avatar}`, `{unsubscribe_url}`, `{current_year}`

---

## Registration → Verification → Onboarding Flow

```
Register
    ↓
Email verification sent → [link clicked]
    ↓                           ↓
Pending state            Account activated
                               ↓
                       Welcome email sent
                               ↓
                   Profile setup wizard
                   (name, avatar, bio → interests → spaces → people)
                               ↓
                          Home feed
```

- Admin approval mode: pending until admin approves
- Verification token expires 48h
- If verification disabled in settings: skip directly to welcome + wizard
- Resend link shown on login if still unverified

---

## Email Infrastructure

**Action Scheduler at free tier** — all emails go through it, no exceptions.

- Priorities: high (verification, password reset) / normal (social notifications) / low (digests, nudges)
- Digest deduplication via `bn_email_log` — one digest per user per day, checked before sending
- Delivery: `wp_mail()` default, swappable to SendGrid / Mailgun / SES via filter
- Template fallback: DB override → theme file override → built-in default

---

## Trigger-Based Automated Emails (Free)

After registration:
- +24h: "Complete your profile" nudge → cancelled if profile complete
- +72h: "Join a space" nudge → cancelled if space joined

After joining a space: "Welcome to {space}" immediate email.

---

## Pro Email Automation

### Drip Welcome Sequences
Multi-step automated email series triggered on member registration. Admin builds sequences in the email editor — same template editor as broadcast, same token system.

**Default sequence (editable):**
- Day 0: Welcome + tour of the community
- Day 3: "Spaces you might like" (pulls from member's interest selection at onboarding)
- Day 7: "Here's what happened this week" (top posts digest)
- Day 14: Re-engagement nudge if member hasn't posted
- Day 30: Milestone email ("You've been a member for a month")

**Mechanics:**
- Each step is a scheduled Action Scheduler job, enqueued at enroll time
- Steps cancel automatically if the condition is already met (e.g. day 14 nudge cancels if member has posted)
- Admin can create multiple sequences (e.g. one for paid tier, one for free tier)
- Members assigned to one sequence at a time; re-enroll resets the clock
- Unsubscribe from sequence without unsubscribing from transactional emails
- DB: `bn_drip_sequences` (sequence definitions + steps), `bn_drip_enrollments` (member → sequence, current step, enrolled_at)

### Space Digest Emails
Auto-curated email digest of top posts per space, sent to all space members on a schedule.

- Space admins enable digest per space (off by default) and set frequency: weekly or monthly
- Digest pulls top N posts by reaction + comment count within the period
- Each digest email: space name + avatar, top posts with author name + excerpt + link, "View all in space" CTA
- Members can opt out per space via notification prefs
- Sent via Action Scheduler cron (Monday 08:00 UTC for weekly, 1st of month for monthly)
- Uses same template editor as broadcast — space admins can customise subject + intro text
- DB: reuses `bn_email_campaigns` with `type = 'space_digest'`; generation job writes recipient list to `bn_campaign_recipients`

---

## Unsubscribe

Every email includes `{unsubscribe_url}` — one-click, no login. Signed token (HMAC). Updates notification prefs for that email type. "Unsubscribe from all" secondary option.

---

## Addon Behavior

### WPMediaVerse
- Standalone: own bell + notification store + email templates
- BuddyNext mode: own notification system off, bridge normalizes `mvs_*` actions → `bn_notifications`

### Jetonomy
- Standalone: own notification UI + store
- BuddyNext mode: own system off, bridge normalizes Jetonomy actions → `bn_notifications`

### WBGamification
Bridge normalizes badge/points events → `bn_notifications`. Active in all modes.

### Career Board
Bridge normalizes application events → `bn_notifications`. Active in all modes.

---

## Data Stored

`bn_notifications` — notification rows with group_key for grouping
`bn_notification_prefs` — per-user, per-type, per-channel preferences
`bn_email_templates` — editable email templates (transactional + broadcast + drip)
`bn_email_log` — dedup log (user, type, digest_date, sent_at)
`bn_verify_tokens` — email verification tokens with expiry

**Pro only:**
`bn_email_campaigns` — broadcast campaigns + auto space digests (`type`: `broadcast` | `space_digest`)
`bn_campaign_recipients` — per-recipient delivery + open/click tracking
`bn_drip_sequences` — sequence definitions with ordered steps (template_id, delay_days, cancel_condition)
`bn_drip_enrollments` — member enrollment state (sequence_id, user_id, current_step, enrolled_at, completed_at)

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Social Graph | `bn_blocks` — never notify from blocked user |
| WPMediaVerse | Bridge normalizes `mvs_*` → `bn_notifications` |
| Jetonomy | Bridge normalizes Jetonomy actions → `bn_notifications` |
| WBGamification | Badge/points events → bell + email |
| Career Board | Application events → bell + email |
| Spaces | Space-level notification pref gates `space_new_post` |
| Pro Membership | Membership emails in catalog |
| Action Scheduler | All async delivery + digest dedup |

---

## Gaps / Open Questions

- None — fully locked
