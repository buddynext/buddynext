# Members and Login Issues

Problems around email verification, two-factor authentication, profile handles, and avatars.

## A new member never receives the verification email

**Symptom:** A member signed up, but the confirmation email never arrives, or they're stuck on the verification screen.

**Likely causes and fixes:**

- **The site can't send mail reliably.** Before turning on email verification, confirm the site can actually send email - test it from **Notifications > Email Templates**. A site that can't send mail locks every new member out at signup with no way in, since Full-strictness verification blocks them entirely.
- **The email landed in spam.** Ask the member to check spam or promotions folders first, then use the **Resend** button on their verification screen for a fresh link.
- **The link expired.** A confirmation link is valid only for a limited time. An expired link tells the member so and offers **Resend**, which issues a fresh link and invalidates the old one - always use the newest email.
- **The member is stuck with no way to tell you.** If your setup is **Restricted** (the default), they can still browse and reach you while unverified. If it's set to **Full**, they cannot use the community at all until verified, and can't message you to say so - if this keeps happening, consider switching to Restricted, or verify them by hand as an admin (see below).

See [Email Verification](../accounts-access/04-email-verification.md).

## I need to manually confirm a member's email because the message never arrived

**Fix:** Open the member in **BuddyNext > Members**, and next to View Profile you'll see a **Mark email verified** button (only shown when email verification is on and the member isn't verified yet). Clicking it runs the same verification the member would trigger themselves - but be aware it bypasses proof of ownership, asserting the address belongs to that member on your say-so. Use it when you have another reason to trust the address, not as a way to clear a backlog.

See [Email Verification](../accounts-access/04-email-verification.md).

## A member lost access to their authenticator app and can't sign in

**Symptom:** 2FA is on for the member's account, and they no longer have the device with the authenticator app.

**Fix:** At the sign-in challenge, they can enter one of their saved **backup codes** instead of the app code, or choose **email a code** to have BuddyNext send a one-time code to their account's email address. Once back in, they should go to **Settings > Account** to regenerate backup codes or re-enroll a new device. If they have no backup codes left and can't receive the email either, an administrator cannot bypass 2FA for them directly through the plugin - direct database-level 2FA reset is outside the documented member-facing flow.

See [Two-Factor Authentication](../accounts-access/05-two-factor-authentication.md).

## Every member is suddenly forced into a 2FA setup screen

**Symptom:** Members report being stuck on an account-setup screen they can't get past.

**Likely cause:** **Require two-factor authentication** (Members > Registration & Login) was set to **Everyone** (or a role that includes them), and they had not set up 2FA yet.

**Fix:** This is working as designed - a member in a required role who hasn't set up 2FA is held on **Settings > Account** until they finish setup; their account itself is not locked, just held at that screen. If this wasn't intended for regular members, change the setting back to **Administrators** or **Nobody**, and give members advance notice before requiring it again - an unannounced mandatory setup screen reads as the site being broken rather than being careful.

See [Two-Factor Authentication](../accounts-access/05-two-factor-authentication.md).

## A member's profile handle/URL isn't what they expected, or says "taken"

**Symptom:** A member tries to set a custom handle and it's rejected, or their profile link looks auto-generated.

**Likely causes and fixes:**

- **It's genuinely taken**, or it's a reserved system address belonging to a different member. Handles are unique across the whole community - try a different one.
- **They haven't set one yet.** By default, BuddyNext assigns a system address; a member claims a custom handle from their profile settings, with live availability checking as they type.
- **The handle contains characters that don't survive conversion.** Handles are converted to a clean, URL-safe form automatically (lowercase, spaces and punctuation turned into hyphens) - what gets saved may look different from what was typed, which is expected.

See [Member Profiles](../members/01-member-profiles.md).

## A member's avatar isn't showing, or shows the wrong fallback image

**Symptom:** A member with no uploaded avatar sees an unexpected image, or an admin-removed avatar doesn't reset properly.

**Likely cause:** The site's **Default avatar style** setting (Members > Avatar and Cover) controls what shows for members with no uploaded avatar - Initials, a Default image, or Gravatar - and that's what's rendering, not a bug.

**Fix:**
1. Check **BuddyNext > Members > Avatar and Cover** to see which default style is active.
2. If the style is **Default image** and no image is set (or the wrong one is), upload or replace it there.
3. A member's own uploaded avatar always overrides the default - if they've uploaded one and still see the fallback, ask them to re-upload; an admin can also remove and let them re-upload from **BuddyNext > Members** (Edit Member).

See [Member Profiles](../members/01-member-profiles.md).

## Social login says my email is already registered

**Symptom:** A member tries "Continue with Google" (or another provider) and is told to sign in with a password instead.

**Explanation, not a bug:** This is a deliberate safeguard against account takeover. If the provider's email is **not** confirmed as verified by the provider and it matches an existing account, BuddyNext asks the member to sign in with their password first and link the provider from profile settings, rather than silently merging the accounts.

**Fix:** Sign in with the original password, then go to profile settings to link the social provider for next time.

See [Social Login](../accounts-access/03-social-login.md).

## A member can't unlink their only sign-in method

**Symptom:** Trying to unlink a social provider fails with a message about setting a password first.

**Explanation, not a bug:** If a member signed up with a provider and never set a password, that provider is their only way in - BuddyNext refuses the unlink until they set a password (which they can do without entering a "current" password, since they never had one). This prevents someone from accidentally locking themselves out of their own account.

See [Social Login](../accounts-access/03-social-login.md).

## Related

- [Registration](../accounts-access/01-registration.md)
- [Email Verification](../accounts-access/04-email-verification.md)
- [Two-Factor Authentication](../accounts-access/05-two-factor-authentication.md)
- [Social Login](../accounts-access/03-social-login.md)
- [Member Profiles](../members/01-member-profiles.md)
