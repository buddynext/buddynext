# What's New in 1.2.4

This release widens the app API, makes pages faster, clears up notifications and fixes a long list of smaller problems. BuddyNext Pro 1.2.4 rebuilds paid membership around one plan card and one checkout.

> **Note:** BuddyNext free and BuddyNext Pro are released together. Update both at the same time and test them together.

## New for members

- **Photos on a published post.** Members can add and remove photos on a post they already published.
- **Notification preview.** On desktop and tablet, the header bell opens a preview of recent notifications.
- **Sharing.** Public posts on a public site can be shared to social networks by members and guests.
- **Leaderboard.** The leaderboard can be browsed in full, page by page, and each space has its own Leaderboard tab.
- **Restore files.** Removed files can be restored from the Trash, not only folders.

## Better for members

- **Uploads.** Uploads show how much of each file has been sent, several videos can be uploaded together, and Post waits until media is ready.
- **Logging in.** Logging in returns visitors to the page they came from, from every Log in link and from wp-login.php. Finishing onboarding lands a new member on the activity feed.
- **Paging.** Lists use WordPress's `/page/N/` addresses, a page past the end answers Page not found with a way back, and profile tabs are paged.
- **Long posts.** See more expands a long post in place on the feed.
- **Notifications.** A member is notified once per person per post for reactions, and reactions are bell-only. The bell number counts what is new since the bell was last opened and still unread.
- **New posts pill.** Clicking the New posts pill puts the posts it counted at the top of the feed instead of reloading the page.
- **Phones.** Toasts show one at a time, never repeat a counter, and sit above the Save bar.
- **Sub-spaces.** A sub-space names its parent on its page and in the Spaces sidebar.

## New for site owners

- **Page width.** **Appearance > Layout** sets community pages to full width, the theme's width or a custom width.
- **Tools.** Tools can check and repair the database tables and restore the default email wording.
- **Email Log.** The Email Log can be searched by recipient.
- **Webhooks.** A new webhook endpoint shows its signing secret once, with a Copy button.
- **Roles.** The Roles screen lists every ability an owner can grant, including reacting, sharing, bookmarking and voting.
- **Suspensions.** New sites suspend a member at 5 strikes and never ban automatically; saved values on existing sites are kept. Every suspension carries a reason the member is told.
- **Free licence.** The free licence is activated in the background, stops when the store refuses, and tells the owner why with a Retry button.
- **Permalinks.** On Plain permalinks the owner is told BuddyNext needs pretty links, with a one-click fix.
- **Site search.** Site search stays with WordPress; `?s=` is no longer redirected to community search.
- **Speed.** Feed pages, the Members directory and every request run fewer database queries.
- **Admin wording.** Admin screens use one save wording, sentence case and "members" throughout.

## Fixes

- Nobody could register on a new site because consent was required with no checkbox shown. This is fixed.
- Signup refusals that are not about a single field are shown to the visitor, and registration redirects saved in settings are followed, including an off-site address.
- Choosing Admin approval in the setup wizard holds new members for approval, and Go to dashboard no longer adds sample content.
- Every declared transactional email is delivered, not only verification and welcome.
- Deleting posts lowers their hashtags' counts, and old counts are repaired once.
- Guests see a member's public albums instead of No albums yet.
- Invite-only spaces no longer offer a join request, My Spaces filters by category, and approving a join request runs the same checks as joining.
- A missing post answers 404 on edit, delete, pin and unpin.
- Photo alt text comes from WPMediaVerse, not the upload title, and deleting a comment in the media lightbox removes its copy on the post.
- A reaction clicked in the photo lightbox right after it opens is saved on the post and stays highlighted.
- The app's own files are cached correctly when their paths contain spaces, and a version update is never answered with the old copy.
- Hiding Jetonomy from navigation also hides the Discussions link in the rail.
- The cookie notice button uses the site's accent colour on theme pages.
- Deleting a post from its own page returns to the activity feed instead of leaving an empty page.
- Opening the bell also clears the notification number on the side rail and the phone navigation.
- Phone layouts of the directories line up, and the Members toolbar no longer leaves a lone view switch at tablet width.

## Security

- Members-only posts no longer appear on Explore, in link previews or with their link preview over the API.
- Posts in private and secret spaces stay out of Explore and profiles, and every route of a secret space answers a stranger like a missing space.
- Profiles no longer reveal the secret spaces a member belongs to.
- Albums list only the photos the viewer may see and never show a hidden cover.
- React, share, bookmark and poll vote follow the member's ability on the server, not only in the page.
- Unlisted membership plans stay out of the public API.

## For developers

- **App API.** The app API covers the Explore deck, featured spaces, space header, tabs, team and roster, profile tabs, onboarding steps, notification badges, privacy settings, standing and sign-in accounts.
- **Filters.** New filters `buddynext_rest_space_item`, `buddynext_member_hold` and `buddynext_reaction_choices`. Partner notifications can opt into BuddyNext email.
- **Integration cards.** Integration cards can be rewritten in place with `IntegrationActivity::rewrite`, and bridges name the features that need a newer partner version.
- **REST reference.** The REST API reference is regenerated for 1.2.4.

## BuddyNext Pro 1.2.4

Paid membership is rebuilt around one plan card, one order summary and a pricing page made of blocks.

- **Pricing page.** The membership pricing page is built from blocks: header, plan cards, community proof, comparison and FAQ, with one pattern that restores the whole layout. Blocks can be selected and configured in the editor.
- **One checkout.** One plan card and one order summary everywhere, showing coupon, tax, points and what renews. The owner's default gateway is listed first at checkout.
- **Who can join.** Choose Anyone, or Paying members only.
- **Renewals.** An optional monthly renewal day, and admins can change a member's renewal date; the charge moves with it.
- **Finding a member.** Admins can find a member from an email, invoice number or payment reference.
- **Billing history.** Members read their billing history in the app, and the app gets plan cards and the order summary.
- **Plan changes.** Moving to a higher plan starts now, moving down starts when the current plan ends, and monthly to yearly keeps the unused time. Settings > Membership shows a queued plan change with Cancel. A member whose plan is renewed by Stripe or PayPal changes plan on the pricing page.
- **Cancelling.** A member who cancels keeps the time they paid for, and a cancel is confirmed with the provider. A cancelled plan cannot be bought again before it ends, so nobody is charged twice.
- **Refunds and deletion.** Refunds stop the provider billing, refund the order's own payment and send one receipt. A refunded or ended paid plan leaves the member on the Free plan. Deleting a paying member cancels their billing and keeps the records.
- **Payments.** Stripe renewals and failed payments work on current Stripe API versions, and the first payment of a subscription is recorded once, as one order, with its tax. Update payment method opens Stripe's billing portal for members who paid with a different email. The Payment Gateways screen names every Stripe and PayPal webhook event and flags the ones a site's webhook is missing.
- **Exports.** The Orders export includes subtotal, discount, tax, refunded amount, payment reference and points.
- **Engagement.** Custom reactions follow the one on/off switch on Engagement > Social. Disabling a drip sequence pauses enrolled members, and enabling it resumes with the next step only. With Hide my profile views on, the visit is not recorded at all.
- **Security.** Unlisted plans are hidden from the public API and from Settings > Membership.
