# What's New in 1.1.7

This is a fix release. We ran a full audit of everything 1.1.6 introduced and closed the gaps it left: the media lightbox now acts on the post a photo belongs to, members billed through another plugin see the amount they were actually charged, and several admin screens stop misreporting what they are showing.

> **Note:** BuddyNext free and BuddyNext Pro are released together. If you run both, update them at the same time so they stay in step.

## The lightbox acts on the post, not a hidden copy

When you opened a photo in the media lightbox in 1.1.6, saving it and reacting to it quietly went somewhere other than the post the photo belongs to.

- **Save** now bookmarks the post the photo belongs to, so it lands in the same saved list as Save on the feed card. It previously saved to a separate WPMediaVerse Pro collection, and removed itself from the screen whenever that was unavailable.
- **Reactions** left in the lightbox now apply to the post, so they appear on the feed card and are counted once. They previously went to a separate media store and were visible only inside the lightbox.
- **Photos added to a space album** appear in the activity post again, instead of the post rendering as text only.

## Members billed by another plugin see the real price (Pro)

When a membership is billed through Paid Memberships Pro or WooCommerce, the two systems do not keep their prices in step - so BuddyNext was showing its own plan price, not what the member was actually charged.

- **A member billed through another plugin is now shown the amount actually charged.** Where no recorded payment exists, the page names the billing provider instead of printing a figure it cannot vouch for.
- **Those sales now reach the Orders list**, in the billing plugin's own amount and currency, dated when the payment was taken. Renewals and refunds are recorded too. Historical orders from before this release are not backfilled.

## The coupon field is back on the pricing page (Pro)

A coupon field was only being shown when tax was enabled, which left the Coupons screen unreachable for members on a site with no tax. The field now appears whenever the site has a coupon that can be redeemed.

## A profile section's visibility is a ceiling, not a switch

The visibility control on a profile field group now states plainly what it does: it sets a ceiling. A field inside a group can be more private than the group, never more public, and setting a section to Public does not publish the fields inside it. Conditional profile fields also respond to multiselect and radio triggers now - the rule previously never ran for those field types, so a conditional field stayed visible whatever the member picked.

## Admin screens tell the truth

- A space owner or moderator clicking a reported-content notification now reaches that space's moderation panel instead of a 404. The emailed link is corrected too.
- Deleting a profile field or group opens a confirmation dialog instead of expanding the table row, which had made the "this permanently deletes stored member values" warning almost unreadable.
- The invite list on **Members > Invites** uses the full width of the screen and scrolls sideways when it needs to, instead of being squeezed into a narrow form column.
- The Realtime and Push settings section is hidden when neither feature is on. Webhooks moved to **Platform**, alongside Integrations and Tools.
- The Explore sidebar's Browse card and the Insights daily-active tile render their icons instead of blank space.
- The settings save bar no longer sits flush against the section below it.
- Builder-generated CSS is kept on BuddyNext hub routes.

## For developers

- New filter `buddynext_can_view_explore` gates the Explore deck before it is built.
- The icon gate reads array-key icon slugs and scans BuddyNext Pro, so a missing icon fails the build rather than rendering as nothing.
