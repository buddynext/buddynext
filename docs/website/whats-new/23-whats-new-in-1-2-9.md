# What's New in 1.2.9

This release makes communities in Korean, Arabic and other non-Latin languages work end to end, closes a removed post to everyone, and makes media privacy and reporting follow the owner's settings. BuddyNext Pro 1.2.9 matches it with one invoice trail for every membership and renewals that keep members' currency, kept prices and coupons.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## Better for site owners

- **Integration Settings** is one table: a row per integration and a column per surface (navigation, feed, search), so you can see and switch everything at a glance.
- **Main Navigation** lists every rail item, marks items added by other plugins as **Add-on**, and manages Media, Discussions, Events, Leaderboard and the **My spaces** switcher. My spaces honours its label and access settings.
- **Setup wizard.** The community name becomes the site title, the feed is the home page by default, a **Community** menu is added, and the Done step says what sample content was created.
- **Media moderation.** Community moderators can moderate media and remove reported photos, and report queues offer **Remove content** on media reports.
- **Stories.** The composer shows the story length you set and follows the **Post stories** permission, and Stories has its own row in settings.
- **Demo content** sends no mail to demo addresses, keeps space albums out of Explore, dates comments after their post and removes only its own hashtags.

## Better for members

- **Profile URL fields** show the full path, not just the site.
- **Messages** tab counts show in full on desktop.

## Fixes

- A removed post is closed to its author and to every reaction, comment and share.
- Spaces with Korean or other non-Latin names open, can be deleted by typing their name, and their categories filter the spaces directory. See [Space Categories](../spaces/08-space-categories.md).
- Hashtags in Korean, Japanese and accented letters open and can be followed.
- The streak strip shows a whole, translated weekday letter in every language, one per day.
- Turning off **Member Reporting** in MediaVerse removes the Report button from the media viewer. See [WPMediaVerse](../integrations/02-wpmediaverse.md).
- A profile with only social links keeps its About tab.
- The rail highlights **Profile** on your own profile, not Members.
- The **More** menu on tab bars opens its links on iOS Safari.
- Ending a story in its first two minutes leaves no broken post, and a photo a post also uses is kept.
- A queued email whose sender's account was deleted is not sent.
- A settings switch reached by keyboard near the bottom of a long settings tab is no longer hidden behind the Save bar.

## Security

- A photo made private within two minutes of upload stays private. Before, its delayed feed post could make it public again.
- Space invites no longer reveal which usernames exist, and login attempts are limited per account.
- Choosing a space category requires permission to create spaces.

## For developers

- **Space slugs** are stored the way WordPress stores them. `GET /spaces/slug/{slug}` accepts the slug encoded or decoded.
- **Deleting a space** over REST checks the `X-BN-Confirm-Space-Name` header. Send a non-Latin name URL-encoded, since header values must be ASCII.
- **Media reporting** belongs to MediaVerse: BuddyNext no longer filters `mvs_reports_enabled`, and the lightbox's `canReport` reads MediaVerse's own setting.
- **Inbound access webhooks** turn each grant with an `amount` into an invoice. Send `reference` or `idempotency_key` so a repeated delivery stays one invoice. See [REST Webhooks](../developer-guide/23-rest-webhooks.md).

## BuddyNext Pro 1.2.9

- **One invoice trail.** Every invoice names its subscription and whether it was a new purchase, a renewal or a plan change. Existing invoices are linked once, on update.
- **Renewals** keep the site currency, are judged against the subscription's own rate so kept prices and coupon discounts keep renewing, and a PayPal renewal sent twice records one invoice.
- **My Membership** shows the amount the subscription actually bills, and hides the monthly or yearly switch when a member kept an earlier price. The Membership page shows one plans button after a plan change.
- **Default plan.** The setting previews what a plan holds back before you save, and a free unlisted plan can be the default.
- **Grace period.** A renewal charged just after the period ends no longer tells a paying member their plan ended.
- **Subscriptions tab.** Revoke records the reason, a revoke that fails no longer removes access, and the list stays fast on large sites.
- **Migrated members.** Cancelling a member moved from another membership plugin is applied to the right Stripe plan.
- **Plan limits** keep the profile fields a member already filled in, even when their plan no longer allows editing them.
- **Space Businesses** pages its showcase and review queue.
