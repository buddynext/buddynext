# Email Verification

Email verification asks every new member to confirm their email address before they get full access to your community. They click a link in a message sent to that address, which proves the inbox is real and belongs to them. It is a simple step that keeps your member list genuine and your community emails landing in real inboxes.

![New member on the BuddyNext email verification step after sign-up](../images/onboarding.webp)

![Members - Registration & Login admin tab where email verification is turned on or off](../images/admin-registration.webp)

## Why use it

- **Confirm real addresses.** A member who verifies has a working inbox, so your welcome emails, notifications, and digests actually reach them.
- **Cut spam and throwaway sign-ups.** Bots and disposable accounts rarely complete the verify step, so requiring it keeps your member directory cleaner.
- **Protect community trust.** When you know addresses are real, password resets and account-recovery emails go to the right person.

Verification is optional. When it is turned off, every account is treated as verified the moment it is created.


## How it works for members

When verification is required, here is what a new member experiences.

1. **Sign up as usual.** The account is created and BuddyNext asks the member to confirm their address.
2. **Open the email.** BuddyNext sends a message with a confirmation link to the address used at sign-up. The email matches the rest of your community's branded emails.
3. **Click the link.** The link opens a confirmation page. Once it loads, the address is verified and the member can use the community normally.
4. **Resend if needed.** If the email did not arrive, the member can use the **Resend** button to send a fresh link. They should also check spam or promotions folders first.

### What members can do before verifying

This depends entirely on **how strictly** you choose to enforce verification, and the two settings feel very different to a new member. You pick the level (see below).

**Restricted (the default).** The member is signed in and can look around - read the feed, browse spaces, see who is in the community - but cannot **post or comment** until they confirm. If they try to post anyway, BuddyNext blocks the post and shows a **Resend verification email** action right there, so they can request a fresh link without losing what they were doing.

**Full.** The member cannot use the community at all. They land on a verification screen instead of the feed and stay there until they confirm.

> **Note:** The confirmation link is single-use. Once it has been clicked successfully, clicking it again does nothing - the account is already verified, and that state stays set.

## Setting it up (for owners)

Email verification is controlled in three places in the admin: a master feature switch, a toggle that requires it for new sign-ups, and a setting for how strictly to enforce it.

### Step 1 - Turn on the feature

Go to **BuddyNext > Platform > Features** and enable **Email Verification**. This makes the verification system available. Until this is on, the settings below are hidden, because they would have no effect.

### Step 2 - Require it, and choose how strict to be

Go to **BuddyNext > Members > Registration & Login**. With the feature enabled, you will see both settings.

| Setting | What it does | Default |
|---|---|---|
| Require email verification | Asks new members to confirm their email address. | Off |
| How strictly to enforce verification | **Restricted** (recommended): members can look around but cannot post or comment until they confirm. **Full**: they cannot use the community at all until they confirm. | Restricted |

### Which level should you pick?

**Restricted** is the recommendation, and it is the default for a reason. A new member who has just signed up and is stuck on a blank "check your email" screen has nothing to look at and no reason to wait. If the email is slow, or lands in spam, or they simply mistyped their address, you have lost them. Letting them read the community while the email arrives means they are getting value from your site in the exact minutes they are deciding whether to stay - and they still cannot post spam, which is the thing verification is actually protecting you from.

**Full** is the stricter choice. Pick it when your community must not be readable by an unconfirmed address at all - a private, paid, or professional community where the content itself is the thing being protected, not just the ability to post into it. Be aware of what you are trading: some genuine new members will not make it past the screen.

> **Tip:** Whichever you pick, make sure your site can actually send email before you switch verification on. Verification is the one feature that turns a broken mail setup into a locked front door - every new member gets stuck, and none of them can tell you, because telling you would require an account.


### Admin Approval mode

Setting **Registration Mode** to **Admin Approval** is a separate gate from email verification. In approval mode, a new account is created but held until an administrator approves it. The new member sees a message that their account is awaiting administrator approval, and they cannot sign in until you approve them.

You can use approval mode and email verification together: the member confirms their email, and you still review and approve the account before they get in.

> **Note:** Approval mode and verification answer different questions. Verification proves the email address is real. Approval lets you personally review who joins. Turn on whichever (or both) match how tightly you want to control sign-ups.

### The verification email

The message that carries the confirmation link is a standard BuddyNext email, sent with your community's branding, sender identity, and footer - the same shell as every other email the platform sends. There is nothing extra to configure to make it match your other emails.

## Good to know

- **Unverified state.** A new account stays unverified until the link is clicked. While unverified, the member is steered to the verification screen rather than the full community.
- **Link expiry.** A confirmation link is valid for a limited time after it is sent. If a member clicks an old link, they are told it has expired and asked to request a new one - which they do with the **Resend** button.
- **Resending replaces the old link.** Each resend issues a fresh link and clears the previous pending one, so only the newest link works. Always tell members to use the most recent email.
- **Already verified.** If a member who is already verified tries to resend, BuddyNext tells them their address is already confirmed and does not send another email.
- **Verification off.** If you never turn the feature on, or leave the require toggle off, every account counts as verified automatically and members go straight into the community after sign-up.
