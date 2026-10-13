# What's New in 1.2.5

This release lets Make, Zapier or n8n sell plans, tightens blocks and space bans, makes colours readable on every theme and fixes a long list of smaller problems. BuddyNext Pro 1.2.5 makes checkout clearer for PayPal and coupons and holds plan limits.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## New for members

- **Notifications.** Select several notifications and delete them together.
- **Profile images.** **Edit cover** and **Edit avatar** are one click away on your own profile.
- **Signing up.** Signing up returns the visitor to the page they came from.

## Better for members

- **Spaces.** Joining a space turns on notifications for its new posts by default.
- **Search.** The Date and Sort filters work, results link where they should, and filters show only when applied.
- **Trending Topics.** Trending Topics counts the posts in its window, and editing a post no longer counts its old hashtags as new.
- **Digests.** Digests reach members on default preferences, follow each type's frequency and stop when switched off.
- **Blocking.** A blocked conversation turns read-only for both members, and the member who blocked can unblock from it or from the other member's Message link.
- **Readable colours.** Badges, state labels, letter avatars and small text are readable on every theme, light and dark.
- **Profile views.** Only a real page visit counts as a profile view.

## New for site owners

- **Access webhook.** The access webhook takes `grant_plan`, `extend_plan` and `cancel_plan`, so Make, Zapier or n8n can sell plans taken elsewhere (plans need BuddyNext Pro).
- **Accounts for buyers.** The access webhook can create the account for a buyer who has none yet. It is off by default, and the account is always a plain member.
- **Webhooks screen.** The Webhooks screen shows the endpoint, a request to copy and the last ten inbound requests.
- **Forums.** Linking an existing forum to a space brings its members across, once.
- **Moderation.** Moderation holds the daily work and Automation holds what is set once. Warnings and strikes carry a reason, and administrators can resolve appeals.
- **Media files.** A replaced avatar or cover gets a new file name, the old file is removed after a day, and deleting a member or space removes its images.

## Fixes

- Editing a Multi-select (advanced) field saved in 1.2.4 no longer empties or wipes its choices, and choice labels survive an edit.
- A member banned from a space can no longer comment, react, share, vote or bookmark there.
- People to follow keeps saying Request sent after a reload on sites with an object cache.
- Website and other address fields refuse a bare word on every screen.
- Picking a space category no longer leaves All Spaces highlighted.
- A document on a post opens for whoever may read the post.
- Learnomy's private academy setting works again while BuddyNext is active.
- Old profile addresses keep working, and handles accept hyphens and underscores.
- Notification counters follow deletes, and bulk Hide and Restore count real changes.
- The spaces table can be created on MyISAM and older InnoDB hosts.
- The schedule tool shows only to members whose plan includes scheduling.
- A profile field that fails to save is announced to screen readers with its error.
- The Explore header says 1 space, 1 member and 1 post instead of a plural.

## Security

- Followers-only and connections-only media no longer open to every member, and a privacy sync never widens a file.
- A pending follow is not a follow, and a private account stays out of discovery.
- A member's moderation standing is shown to site staff only.
- The access webhook refuses a replayed or concurrent copy of a signed request, and role changes through it are off unless the owner allows them.
- Reports refuse a message or media id that does not exist.

## For developers

- **Filters.** New filters `buddynext_access_webhook_cancel_plan`, `buddynext_feed_image_variant`, `buddynext_integration_notification_payload` and `buddynext_trending_topics_hours`, and the action `buddynext_access_webhook_completed`.
- **REST reference.** The REST API reference is regenerated for 1.2.5.

## BuddyNext Pro 1.2.5

Plans sold through Make, Zapier or n8n, clearer checkout for PayPal and coupons, and plan limits that hold.

- **Plans from automation tools.** Make, Zapier or n8n can grant, extend and cancel a member's plan, and the order reference, amount and currency they send are shown on Subscriptions. Unknown plans are refused, and a revoke stops gateway billing.
- **PayPal and coupons.** Checkout marks PayPal unavailable as soon as a coupon or added tax means it cannot charge a renewing plan, with the reason, and the Coupons screen says so too.
- **Checkout default.** The checkout default lists only the gateways a buyer can be sent to, and marks a saved default that is no longer available.
- **Plan limits.** A plan limit with a ceiling, such as Pinned Posts, cannot be saved above it. The plan's group message size is enforced, also on older WPMediaVerse.
- **Email series.** Steps are sent in the order shown, on their day after joining.
- **Auto-moderation.** Add rule shows the fields of the chosen rule type.
- **Readable labels.** Member label colours and their text can be read on every theme.
- **Integrations.** Eventonomy attendee cards follow a rescheduled event, and Learnomy sends one bell row per member per course for a certificate.
- **Fixes.** Access lasts on one-time plans, Revoke ends every row that gives access, and reinstating an ended plan clears the reason it ended. Events come back into search when event search is switched on again. Paying members are kept out of the never-verified account cleanup. The analytics funnel counts approved follows only. The subscriptions and push-token tables can be created on MyISAM hosts.
- **Security.** Realtime public channels carry only posts a visitor may read, and a conversation channel is for its active participants only.
