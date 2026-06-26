# What's New in 1.0.3

BuddyNext 1.0.3 brings the most-requested member feature - a real photo and video gallery on every profile - together with a deep round of performance work that keeps large communities fast. BuddyNext Pro 1.0.3 ships in lockstep, building on the new payments and membership additions that landed just before it. This page is a plain-language tour of what changed and why it matters.

![The community activity feed, where member photos and videos now appear the moment they are uploaded](../images/community-activity-feed.webp)

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## The headline: profile media and albums

Every member profile now has a **Media** tab where members upload their own photos and videos, choose who can see each one, and group them into albums. It turns a profile from a single header image into a personal gallery - a place to show work, trips, a portfolio, or whatever a member wants the community to see.

What members can do:

- **Upload photos and videos** straight from their profile. Each upload appears in their gallery right away, newest first, and shows up in the activity feed too.
- **Choose the audience for each upload** - Public, Followers, Connections, or Only me. The choice is honored everywhere; anything private is genuinely hidden from everyone else, not just greyed out.
- **Build albums** - create a named album, add and remove media, pick a cover image, drag items into the order they want, rename it, change who can see it, and delete it. Deleting an album never deletes the photos inside; they stay in the gallery.

For a site owner, member media is some of the most engaging content a community can have - it gives people a reason to come back, and because every upload carries its own privacy setting, members stay in control without the owner having to police a free-for-all.

Profile media relies on the **WPMediaVerse** companion plugin being active; once it is, the Media tab appears automatically with nothing else to switch on. For the full walkthrough, see the Profile Media and Albums page in the Members section.

## Faster and steadier at scale

A big part of 1.0.3 is invisible: it makes BuddyNext hold up as your community grows into the thousands and beyond. You will not see new buttons for this work - you will feel it as a community that stays quick when it is busy.

- **Snappier media everywhere.** Photo and video previews now generate a fast, downscaled thumbnail, so uploads on the Media tab, the feed composer, and direct messages feel instant instead of waiting on the full-size file.
- **Up-to-date member directory.** Block or unblock someone and the directory refreshes immediately, instead of briefly showing a stale list.
- **Accurate, lighter background work.** Behind-the-scenes jobs were tuned so the community does the right amount of work at the right time, keeping pages responsive even under load.
- **Steadier feeds and announcements.** Ending or dismissing an announcement updates the home feed straight away, so what members see always matches the current state.

On the Pro side, the same theme continues under the hood: membership and analytics dashboards load faster on large sites, sending a broadcast to a big audience no longer tries to load every recipient at once, and real-time and push notifications no longer make members wait on the delivery service before their action completes.

## Fixes members will notice

1.0.3 also clears a set of rough edges:

- Changing your **display name** now sticks - it no longer reverts to your login name when you click away.
- A **video without a poster image** shows a generated thumbnail instead of a black tile.
- Trying to edit or delete a **comment you do not own** returns a clear message instead of a confusing error.
- **Search** for members, spaces, and posts returns results whether you search by the singular or plural name of the type.
- Moderation **appeal decisions and member warnings** are recorded correctly in the audit log.

## Recent Pro additions: payments and membership

If you run BuddyNext Pro, the releases just before 1.0.3 expanded how members pay for and manage their membership. These are worth knowing about as part of the current Pro picture:

- **More ways to pay.** Alongside Stripe, members can now pay with **PayPal** hosted checkout, or **redeem a membership tier with gamification points** using a per-tier points price you set.
- **One place to manage gateways.** A single **Payments** screen lists every gateway - Stripe, PayPal, points, and a sandbox option - each with its own enable toggle, its own settings, and a default-gateway pick.
- **A gateway picker at checkout.** When more than one gateway is active, members choose how they want to pay.
- **Self-service cancellation.** Members can **cancel their own membership** from their My Membership page, and cancelling stops billing at the provider.
- **Monthly or yearly pricing.** When your plans span both, the pricing table shows a **Monthly / Yearly** toggle so members pick the billing interval that suits them.

Payment handling was also hardened across the board - Stripe moved to hosted checkout so payment is reliably collected before access is granted, the subscription lifecycle was tightened so renewals and cancellations stay in sync with the provider, and stored secret keys are kept masked in the admin.

## Where to go next

- New to BuddyNext? Start with the Introduction and the Admin Setup Wizard.
- For the full member walkthrough of the new gallery, see Profile Media and Albums in the Members section.
- For the payments and membership features, see the Membership and payments pages in the BuddyNext Pro section.
