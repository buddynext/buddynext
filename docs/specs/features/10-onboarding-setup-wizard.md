# BuddyNext — Onboarding + Setup Wizard

**Status:** Locked
**Last updated:** 2026-03-19

---

## Two Separate Wizards

1. **Admin Setup Wizard** — runs once on plugin activation, configures the site
2. **Member Onboarding Wizard** — runs once per new user after email verification

---

## Admin Setup Wizard

Triggered on first activation (or "Set up BuddyNext" admin notice). Skippable.

**Steps:**
1. Site name + logo + brand color
2. Registration settings (open / invite-only / admin approval, email verification on/off)
3. Default notification preferences (site-wide defaults)
4. Create first space categories
5. Review active addons (shows WPMediaVerse, Jetonomy, WBGamification, Career Board if active)
6. Done — link to dashboard

Stored in `wp_options` (no custom table needed). Wizard state tracked in `buddynext_setup_complete` option.

---

## Member Onboarding Wizard

Shown once after email verification (or after registration if verification is disabled). Skippable — all steps optional.

**Steps:**
1. Display name + avatar + bio
2. Pick interests → maps to space categories → shown on step 3
3. Suggested spaces to join (filtered by interests)
4. Suggested people to follow (mutual-interest members + staff picks)

After wizard (or skip): redirect to home feed.

Wizard completion fires `buddynext_onboarding_complete` → cancels nudge emails, awards WBGam points if active.

---

## Nudge Emails (Trigger-based, Action Scheduler)

If wizard is skipped or abandoned:
- +24h: "Complete your profile" email (cancelled if profile complete)
- +72h: "Join your first space" email (cancelled if space joined)

---

## Admin Bulk Invite

Admin-only tool in the admin panel. Members cannot invite in bulk.

- Upload CSV (columns: email, first_name — optional last_name)
- BuddyNext sends each address a personalised invite email with a unique signup link
- Invite link pre-fills email on registration form + bypasses invite code gate if invite-only mode is on
- Admin sees invite status per row: pending / registered / bounced
- Resend button per row or bulk resend for pending/bounced
- Invite links expire in 7 days (configurable)
- Stored in `bn_invites` table — no external service needed, uses Action Scheduler for batch sending

---

## Registration Form

- Gutenberg block: embeddable on any page
- Fields: username, email, password (+ optional custom fields if admin configured them)
- reCAPTCHA / hCaptcha support (admin toggle)
- Invite-only mode: requires invite code field

---

## Integration Points

| Feature | Connection |
|---------|-----------|
| Notifications + Email | Verification email, welcome email, nudge emails |
| Profiles | Step 1 saves to `bn_profile_values` |
| Spaces | Step 3 joins spaces → `bn_space_members` |
| Social Graph | Step 4 follows people → `bn_follows` |
| WBGamification | Completion awards points + "Welcome" badge |
| Pro | Drip email sequences extend on top of the same Action Scheduler jobs |

---

## Gaps / Open Questions

- None — fully locked
